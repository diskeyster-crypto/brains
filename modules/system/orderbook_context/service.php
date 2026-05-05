<?php

declare(strict_types=1);

/**
 * OrderBookContextService
 *
 * Fetches Bybit public orderbook snapshots, detects significant size "walls"
 * (large bid/ask clusters), tracks their persistence and eaten/broken state,
 * and returns normalized wall context for Profit Manager and Dynamic Strategies.
 *
 * Usage:
 *   $obc = new OrderBookContextService(__DIR__);
 *   $ctx = $obc->getWallContext('BTCUSDT', 65000.0);
 *
 * Returned context keys:
 *   symbol, fetched_at, current_price,
 *   nearest_bid_wall, nearest_ask_wall,
 *   bid_wall_score, ask_wall_score, wall_imbalance,
 *   bid_wall_status, ask_wall_status,
 *   fetch_ok, errors[]
 *
 * Wall status values: new | persistent | eaten | broken | disappeared | none
 *
 * SAFETY:
 *   - Walls are NEVER a sole entry/exit signal.
 *   - Stop Manager is NOT affected.
 *   - Only fetches for symbols explicitly requested.
 *   - Cached per symbol for config[cache_ttl_seconds] to limit API load.
 *   - Public market endpoint — no private keys required.
 */
class OrderBookContextService
{
    private const BYBIT_ORDERBOOK_URL = 'https://api.bybit.com/v5/market/orderbook';

    /** @var array<string,mixed> */
    private array $config;

    private string $storageDir;

    /** @var array<string,array{context:array,ts:int}> in-memory cache keyed by symbol */
    private array $memoryCache = [];

    /** @var array<string,mixed>|null wall state loaded from storage */
    private ?array $wallState = null;

    /** @var bool whether wall state has been modified and needs saving */
    private bool $wallStateDirty = false;

    // ── Diagnostic counters (reset per tick / instantiation) ─────────────────
    private int $symbolsCheckedTotal       = 0;
    private int $fetchSuccessTotal         = 0;
    private int $fetchFailedTotal          = 0;
    private int $askWallsDetectedTotal     = 0;
    private int $bidWallsDetectedTotal     = 0;
    private int $persistentWallsTotal      = 0;
    private int $eatenWallsTotal           = 0;
    private int $brokenWallsTotal          = 0;
    private int $cacheHitsTotal            = 0;

    public function __construct(string $moduleDir, array $configOverrides = [])
    {
        $base = [];
        $cfgFile = rtrim($moduleDir, '/') . '/config/base.php';
        if (is_file($cfgFile)) {
            $loaded = @include $cfgFile;
            if (is_array($loaded)) {
                $base = $loaded;
            }
        }
        $activeFile = rtrim($moduleDir, '/') . '/config/active.php';
        if (is_file($activeFile)) {
            $active = @include $activeFile;
            if (is_array($active)) {
                $base = array_merge($base, $active);
            }
        }
        $this->config     = array_merge($base, $configOverrides);
        $this->storageDir = rtrim($moduleDir, '/') . '/storage';
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Get wall context for a symbol at the given current price.
     *
     * Returns from in-memory cache if fresh enough (cache_ttl_seconds).
     * Otherwise fetches a new orderbook snapshot from Bybit public API,
     * detects walls, updates persistence state, and returns the context.
     *
     * Fails gracefully — never throws. On error, returns context with
     * fetch_ok=false and populated errors[].
     *
     * @param string $symbol       Trading symbol, e.g. 'BTCUSDT'
     * @param float  $currentPrice Current mark/last price of the symbol
     * @return array<string,mixed>
     */
    public function getWallContext(string $symbol, float $currentPrice): array
    {
        if (!(bool)($this->config['orderbook_wall_enabled'] ?? true)) {
            return $this->emptyContext($symbol, $currentPrice, ['orderbook_wall_disabled']);
        }

        $symbol = strtoupper(trim($symbol));
        if ($symbol === '' || $currentPrice <= 0.0) {
            return $this->emptyContext($symbol, $currentPrice, ['invalid_symbol_or_price']);
        }

        $this->symbolsCheckedTotal++;

        // ── In-memory cache check ─────────────────────────────────────────────
        $ttl = max(1, (int)($this->config['orderbook_wall_cache_ttl_seconds'] ?? 5));
        if (isset($this->memoryCache[$symbol])) {
            $cached = $this->memoryCache[$symbol];
            if (time() - $cached['ts'] < $ttl) {
                $this->cacheHitsTotal++;
                return $cached['context'];
            }
        }

        // ── Fetch orderbook ───────────────────────────────────────────────────
        $limit     = max(50, min(500, (int)($this->config['orderbook_wall_limit'] ?? 200)));
        $fetchResult = $this->fetchOrderbook($symbol, $limit);

        if (!$fetchResult['ok']) {
            $this->fetchFailedTotal++;
            $ctx = $this->emptyContext($symbol, $currentPrice, $fetchResult['errors']);
            $this->memoryCache[$symbol] = ['context' => $ctx, 'ts' => time()];
            return $ctx;
        }

        $this->fetchSuccessTotal++;

        $bids = $fetchResult['bids'];  // [[price, size], ...]
        $asks = $fetchResult['asks'];  // [[price, size], ...]

        // ── Detect walls ──────────────────────────────────────────────────────
        $detected = $this->detectWalls($bids, $asks, $currentPrice);

        if ($detected['nearest_ask_wall'] !== null) {
            $this->askWallsDetectedTotal++;
        }
        if ($detected['nearest_bid_wall'] !== null) {
            $this->bidWallsDetectedTotal++;
        }

        // ── Update wall persistence state ─────────────────────────────────────
        $nowTs = time();
        $this->ensureWallStateLoaded();

        $askStatus = $this->updateWallEntry(
            $symbol,
            'ask',
            $detected['nearest_ask_wall'],
            $currentPrice,
            $nowTs
        );
        $bidStatus = $this->updateWallEntry(
            $symbol,
            'bid',
            $detected['nearest_bid_wall'],
            $currentPrice,
            $nowTs
        );

        if ($askStatus === 'persistent') { $this->persistentWallsTotal++; }
        if ($bidStatus === 'persistent') { $this->persistentWallsTotal++; }
        if ($askStatus === 'eaten')      { $this->eatenWallsTotal++; }
        if ($bidStatus === 'eaten')      { $this->eatenWallsTotal++; }
        if ($askStatus === 'broken')     { $this->brokenWallsTotal++; }
        if ($bidStatus === 'broken')     { $this->brokenWallsTotal++; }

        if ($this->wallStateDirty) {
            $this->saveWallState();
        }

        // ── Build context ─────────────────────────────────────────────────────
        $ctx = [
            'symbol'           => $symbol,
            'fetched_at'       => date('c', $nowTs),
            'current_price'    => $currentPrice,
            'nearest_bid_wall' => $detected['nearest_bid_wall'],
            'nearest_ask_wall' => $detected['nearest_ask_wall'],
            'bid_wall_score'   => $detected['bid_wall_score'],
            'ask_wall_score'   => $detected['ask_wall_score'],
            'wall_imbalance'   => $detected['wall_imbalance'],
            'bid_wall_status'  => $bidStatus,
            'ask_wall_status'  => $askStatus,
            'fetch_ok'         => true,
            'errors'           => [],
        ];

        $this->memoryCache[$symbol] = ['context' => $ctx, 'ts' => $nowTs];
        return $ctx;
    }

    /**
     * Return diagnostic stats for last_run output.
     *
     * @return array<string,int>
     */
    public function getStats(): array
    {
        return [
            'symbols_checked_total'       => $this->symbolsCheckedTotal,
            'orderbook_fetch_success_total'=> $this->fetchSuccessTotal,
            'orderbook_fetch_failed_total' => $this->fetchFailedTotal,
            'orderbook_cache_hits_total'   => $this->cacheHitsTotal,
            'ask_walls_detected_total'     => $this->askWallsDetectedTotal,
            'bid_walls_detected_total'     => $this->bidWallsDetectedTotal,
            'persistent_walls_total'       => $this->persistentWallsTotal,
            'eaten_walls_total'            => $this->eatenWallsTotal,
            'broken_walls_total'           => $this->brokenWallsTotal,
        ];
    }

    /**
     * Return the loaded config (for diagnostic/dashboard display).
     *
     * @return array<string,mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    // =========================================================================
    // Orderbook fetch
    // =========================================================================

    /**
     * Fetch public Bybit orderbook snapshot.
     *
     * @param string $symbol
     * @param int    $limit  Number of levels per side (50–500)
     * @return array{ok:bool, bids:array, asks:array, errors:array}
     */
    private function fetchOrderbook(string $symbol, int $limit): array
    {
        $url    = self::BYBIT_ORDERBOOK_URL
                . '?category=linear&symbol=' . urlencode($symbol)
                . '&limit=' . $limit;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'User-Agent: tredercopis-obc/1.0',
            ],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw      = curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $raw === '') {
            return ['ok' => false, 'bids' => [], 'asks' => [], 'errors' => ['curl_error: ' . $curlErr]];
        }

        if ($httpCode !== 200) {
            return ['ok' => false, 'bids' => [], 'asks' => [], 'errors' => ['http_code: ' . $httpCode]];
        }

        $json = json_decode((string)$raw, true);
        if (!is_array($json)) {
            return ['ok' => false, 'bids' => [], 'asks' => [], 'errors' => ['json_parse_failed']];
        }

        $retCode = (int)($json['retCode'] ?? -1);
        if ($retCode !== 0) {
            return ['ok' => false, 'bids' => [], 'asks' => [], 'errors' => ['bybit_ret_code: ' . $retCode . ' ' . ($json['retMsg'] ?? '')]];
        }

        $result = $json['result'] ?? [];
        if (!is_array($result)) {
            return ['ok' => false, 'bids' => [], 'asks' => [], 'errors' => ['missing_result_key']];
        }

        // Bybit returns arrays of [price_str, size_str]
        $bids = $this->parseOrderbookSide((array)($result['b'] ?? []));
        $asks = $this->parseOrderbookSide((array)($result['a'] ?? []));

        return ['ok' => true, 'bids' => $bids, 'asks' => $asks, 'errors' => []];
    }

    /**
     * Parse one side of the Bybit orderbook response.
     *
     * Bybit returns: [ [price_str, size_str], ... ]
     * Output: [ [price:float, size:float, notional:float], ... ]
     * Filtered to positive price and size only.
     *
     * @param array $raw Raw side array from Bybit response
     * @return array<int,array{price:float,size:float,notional:float}>
     */
    private function parseOrderbookSide(array $raw): array
    {
        $out = [];
        foreach ($raw as $level) {
            if (!is_array($level) || count($level) < 2) {
                continue;
            }
            $price = (float)$level[0];
            $size  = (float)$level[1];
            if ($price <= 0.0 || $size <= 0.0) {
                continue;
            }
            $out[] = [
                'price'    => $price,
                'size'     => $size,
                'notional' => $price * $size,
            ];
        }
        return $out;
    }

    // =========================================================================
    // Wall detection
    // =========================================================================

    /**
     * Detect nearest ask wall and nearest bid wall from the parsed orderbook.
     *
     * Wall detection algorithm:
     *   1. Filter levels within [min_distance_pct, max_distance_pct] of price.
     *   2. Compute average notional across all levels in that window.
     *   3. Flag levels where notional >= avg * size_multiple OR >= min_notional_usdt.
     *   4. Cluster nearby flags within cluster_pct radius and sum their notionals.
     *   5. Return the nearest (closest to price) clustered wall for each side.
     *
     * @param array $bids         Parsed bid levels [{price, size, notional}]
     * @param array $asks         Parsed ask levels [{price, size, notional}]
     * @param float $currentPrice Current market price
     * @return array{nearest_bid_wall:array|null, nearest_ask_wall:array|null,
     *               bid_wall_score:float|null, ask_wall_score:float|null, wall_imbalance:float|null}
     */
    private function detectWalls(array $bids, array $asks, float $currentPrice): array
    {
        $minDist      = (float)($this->config['orderbook_wall_min_distance_pct']  ?? 0.05);
        $maxDist      = (float)($this->config['orderbook_wall_max_distance_pct']  ?? 1.5);
        $sizeMultiple = (float)($this->config['orderbook_wall_size_multiple']      ?? 4.0);
        $minNotional  = (float)($this->config['orderbook_wall_min_notional_usdt']  ?? 5000.0);
        $clusterPct   = (float)($this->config['orderbook_wall_cluster_pct']        ?? 0.10);

        $askWall      = $this->findNearestWall($asks,  $currentPrice, 'ask', $minDist, $maxDist, $sizeMultiple, $minNotional, $clusterPct);
        $bidWall      = $this->findNearestWall($bids,  $currentPrice, 'bid', $minDist, $maxDist, $sizeMultiple, $minNotional, $clusterPct);

        $askScore = $askWall !== null ? ($askWall['wall_score'] ?? null) : null;
        $bidScore = $bidWall !== null ? ($bidWall['wall_score'] ?? null) : null;

        $imbalance = null;
        if ($askScore !== null && $bidScore !== null) {
            $maxS = max($askScore, $bidScore);
            if ($maxS > 0.0) {
                $imbalance = round(($askScore - $bidScore) / $maxS, 4);
            }
        }

        return [
            'nearest_bid_wall' => $bidWall,
            'nearest_ask_wall' => $askWall,
            'bid_wall_score'   => $bidScore,
            'ask_wall_score'   => $askScore,
            'wall_imbalance'   => $imbalance,
        ];
    }

    /**
     * Find the nearest wall on one side (ask or bid) within the detection window.
     *
     * @param array  $levels      Parsed levels for the side
     * @param float  $currentPrice
     * @param string $side        'ask' or 'bid'
     * @param float  $minDistPct  Minimum distance (%) from price
     * @param float  $maxDistPct  Maximum distance (%) from price
     * @param float  $sizeMultiple Wall threshold multiplier vs average
     * @param float  $minNotional  Absolute notional threshold (USDT)
     * @param float  $clusterPct  Cluster radius in % of price
     * @return array|null Nearest wall or null if none detected
     */
    private function findNearestWall(
        array  $levels,
        float  $currentPrice,
        string $side,
        float  $minDistPct,
        float  $maxDistPct,
        float  $sizeMultiple,
        float  $minNotional,
        float  $clusterPct
    ): ?array {
        if (empty($levels) || $currentPrice <= 0.0) {
            return null;
        }

        // ── Filter to window ───────────────────────────────────────────────────
        $windowLevels = [];
        foreach ($levels as $lvl) {
            $price = $lvl['price'];
            if ($side === 'ask') {
                if ($price <= $currentPrice) {
                    continue;
                }
                $distPct = ($price - $currentPrice) / $currentPrice * 100.0;
            } else {
                if ($price >= $currentPrice) {
                    continue;
                }
                $distPct = ($currentPrice - $price) / $currentPrice * 100.0;
            }

            if ($distPct < $minDistPct || $distPct > $maxDistPct) {
                continue;
            }

            $windowLevels[] = array_merge($lvl, ['distance_pct' => round($distPct, 6)]);
        }

        if (empty($windowLevels)) {
            return null;
        }

        // ── Average notional across window ────────────────────────────────────
        $totalNotional = 0.0;
        foreach ($windowLevels as $lvl) {
            $totalNotional += $lvl['notional'];
        }
        $avgNotional = $totalNotional / count($windowLevels);
        if ($avgNotional <= 0.0) {
            return null;
        }

        // ── Mark wall candidates ───────────────────────────────────────────────
        $wallCandidates = [];
        foreach ($windowLevels as $lvl) {
            $isWall = $lvl['notional'] >= ($avgNotional * $sizeMultiple)
                   || $lvl['notional'] >= $minNotional;
            if ($isWall) {
                $wallCandidates[] = $lvl;
            }
        }

        if (empty($wallCandidates)) {
            return null;
        }

        // ── Cluster nearby candidates within cluster_pct radius ───────────────
        // Sort by price (ascending for ask, descending for bid so nearest-first)
        if ($side === 'ask') {
            usort($wallCandidates, static fn($a, $b): int => $a['price'] <=> $b['price']);
        } else {
            usort($wallCandidates, static fn($a, $b): int => $b['price'] <=> $a['price']);
        }

        $clusterRadiusAbs = $currentPrice * $clusterPct / 100.0;
        $clusters         = [];
        $current          = null;

        foreach ($wallCandidates as $lvl) {
            if ($current === null) {
                $current = [
                    'price_anchor' => $lvl['price'],
                    'total_notional' => $lvl['notional'],
                    'total_size'     => $lvl['size'],
                    'level_count'    => 1,
                    'distance_pct'   => $lvl['distance_pct'],
                ];
            } else {
                if (abs($lvl['price'] - $current['price_anchor']) <= $clusterRadiusAbs) {
                    // Merge into current cluster
                    $current['total_notional'] += $lvl['notional'];
                    $current['total_size']     += $lvl['size'];
                    $current['level_count']++;
                    // Keep nearest (lowest ask or highest bid) anchor
                    if ($side === 'ask' && $lvl['price'] < $current['price_anchor']) {
                        $current['price_anchor']  = $lvl['price'];
                        $current['distance_pct']  = $lvl['distance_pct'];
                    } elseif ($side === 'bid' && $lvl['price'] > $current['price_anchor']) {
                        $current['price_anchor']  = $lvl['price'];
                        $current['distance_pct']  = $lvl['distance_pct'];
                    }
                } else {
                    $clusters[] = $current;
                    $current    = [
                        'price_anchor'   => $lvl['price'],
                        'total_notional' => $lvl['notional'],
                        'total_size'     => $lvl['size'],
                        'level_count'    => 1,
                        'distance_pct'   => $lvl['distance_pct'],
                    ];
                }
            }
        }
        if ($current !== null) {
            $clusters[] = $current;
        }

        if (empty($clusters)) {
            return null;
        }

        // Nearest cluster is first (sorted nearest-first above)
        $nearest = $clusters[0];

        $wallScore = $avgNotional > 0.0
            ? round($nearest['total_notional'] / $avgNotional, 2)
            : 0.0;

        return [
            'price'          => round($nearest['price_anchor'], 8),
            'distance_pct'   => round($nearest['distance_pct'], 4),
            'notional'       => round($nearest['total_notional'], 2),
            'size'           => round($nearest['total_size'], 6),
            'level_count'    => $nearest['level_count'],
            'wall_score'     => $wallScore,
            'avg_notional'   => round($avgNotional, 2),
        ];
    }

    // =========================================================================
    // Wall persistence state
    // =========================================================================

    /**
     * Update persistence state for a detected wall on one side.
     *
     * Wall status transitions:
     *   - First time seen → 'new'
     *   - Seen again within min_seconds / min_seen_count → 'persistent'
     *   - Notional drops >= eaten_notional_drop_pct% → 'eaten'
     *   - Price crosses through by break_price_through_pct% → 'broken'
     *   - No wall detected this tick but state exists → 'disappeared'
     *   - No wall and no state → 'none'
     *
     * @param string     $symbol       Symbol key
     * @param string     $side         'ask' or 'bid'
     * @param array|null $wallData     Detected wall data or null (not detected)
     * @param float      $currentPrice Current price
     * @param int        $nowTs        Current unix timestamp
     * @return string Wall status: new|persistent|eaten|broken|disappeared|none
     */
    private function updateWallEntry(
        string  $symbol,
        string  $side,
        ?array  $wallData,
        float   $currentPrice,
        int     $nowTs
    ): string {
        $stateKey = $symbol . '_' . $side;

        $minSeenCount = max(1, (int)($this->config['wall_persistence_min_seen_count']  ?? 2));
        $minSeconds   = max(0, (int)($this->config['wall_persistence_min_seconds']     ?? 10));
        $eatenDropPct = (float)($this->config['wall_eaten_notional_drop_pct']          ?? 60.0);
        $breakPct     = (float)($this->config['wall_break_price_through_pct']          ?? 0.05);

        $existing = $this->wallState[$stateKey] ?? null;

        if ($wallData === null) {
            // Wall not detected this tick
            if ($existing !== null) {
                // Mark as disappeared (keep state for 1 more tick, then could clean)
                $this->wallState[$stateKey] = array_merge($existing, [
                    'last_seen_at' => $existing['last_seen_at'],
                    'status'       => 'disappeared',
                ]);
                $this->wallStateDirty = true;
                return 'disappeared';
            }
            return 'none';
        }

        // Wall detected — check for broken (price crossed through)
        $wallPrice = $wallData['price'];
        $isBroken  = false;
        if ($side === 'ask') {
            // Broken if current price is above wall by break threshold
            $breakThreshold = $wallPrice * (1.0 + $breakPct / 100.0);
            $isBroken = $currentPrice >= $breakThreshold;
        } else {
            // Broken if current price is below wall by break threshold
            $breakThreshold = $wallPrice * (1.0 - $breakPct / 100.0);
            $isBroken = $currentPrice <= $breakThreshold;
        }

        if ($isBroken) {
            $this->wallState[$stateKey] = array_merge($existing ?? [], [
                'symbol'         => $symbol,
                'side'           => $side,
                'wall_price'     => $wallPrice,
                'last_notional'  => $wallData['notional'],
                'broken_at'      => date('c', $nowTs),
                'broken_at_ts'   => $nowTs,
                'status'         => 'broken',
            ]);
            $this->wallStateDirty = true;
            return 'broken';
        }

        // Check for eaten (notional dropped sharply vs previous max)
        $isEaten = false;
        if ($existing !== null && isset($existing['max_notional']) && $existing['max_notional'] > 0.0) {
            $notionalDrop = ($existing['max_notional'] - $wallData['notional']) / $existing['max_notional'] * 100.0;
            if ($notionalDrop >= $eatenDropPct) {
                $isEaten = true;
            }
        }

        if ($isEaten) {
            $this->wallState[$stateKey] = array_merge($existing, [
                'last_seen_at'   => date('c', $nowTs),
                'last_seen_at_ts'=> $nowTs,
                'last_notional'  => $wallData['notional'],
                'eaten_count'    => (int)($existing['eaten_count'] ?? 0) + 1,
                'status'         => 'eaten',
            ]);
            $this->wallStateDirty = true;
            return 'eaten';
        }

        // New or update
        if ($existing === null) {
            // First time seeing this wall
            $this->wallState[$stateKey] = [
                'symbol'          => $symbol,
                'side'            => $side,
                'wall_price'      => $wallPrice,
                'first_seen_at'   => date('c', $nowTs),
                'first_seen_at_ts'=> $nowTs,
                'last_seen_at'    => date('c', $nowTs),
                'last_seen_at_ts' => $nowTs,
                'seen_count'      => 1,
                'last_notional'   => $wallData['notional'],
                'max_notional'    => $wallData['notional'],
                'eaten_count'     => 0,
                'broken_at'       => null,
                'status'          => 'new',
            ];
            $this->wallStateDirty = true;
            return 'new';
        }

        // Update existing entry
        $seenCount   = (int)($existing['seen_count'] ?? 1) + 1;
        $maxNotional = max((float)($existing['max_notional'] ?? 0.0), $wallData['notional']);
        $firstTs     = (int)($existing['first_seen_at_ts'] ?? $nowTs);
        $spanSeconds = $nowTs - $firstTs;

        $isPersistent = $seenCount >= $minSeenCount && $spanSeconds >= $minSeconds;

        $this->wallState[$stateKey] = array_merge($existing, [
            'wall_price'      => $wallPrice,
            'last_seen_at'    => date('c', $nowTs),
            'last_seen_at_ts' => $nowTs,
            'seen_count'      => $seenCount,
            'last_notional'   => $wallData['notional'],
            'max_notional'    => $maxNotional,
            'status'          => $isPersistent ? 'persistent' : 'new',
        ]);
        $this->wallStateDirty = true;

        return $isPersistent ? 'persistent' : 'new';
    }

    // =========================================================================
    // Wall state persistence
    // =========================================================================

    private function ensureWallStateLoaded(): void
    {
        if ($this->wallState !== null) {
            return;
        }
        $path = $this->storageDir . '/wall_state.json';
        if (is_file($path)) {
            $raw     = @file_get_contents($path);
            $decoded = $raw !== false ? @json_decode((string)$raw, true) : null;
            $this->wallState = is_array($decoded) ? $decoded : [];
        } else {
            $this->wallState = [];
        }
    }

    private function saveWallState(): void
    {
        if ($this->wallState === null || !$this->wallStateDirty) {
            return;
        }
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0755, true);
        }
        $path = $this->storageDir . '/wall_state.json';
        @file_put_contents(
            $path,
            json_encode($this->wallState, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
        $this->wallStateDirty = false;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Return an empty (no-data) wall context, e.g. when feature is disabled or fetch fails.
     *
     * @param string   $symbol
     * @param float    $currentPrice
     * @param string[] $errors
     * @return array<string,mixed>
     */
    private function emptyContext(string $symbol, float $currentPrice, array $errors): array
    {
        return [
            'symbol'           => $symbol,
            'fetched_at'       => date('c'),
            'current_price'    => $currentPrice,
            'nearest_bid_wall' => null,
            'nearest_ask_wall' => null,
            'bid_wall_score'   => null,
            'ask_wall_score'   => null,
            'wall_imbalance'   => null,
            'bid_wall_status'  => 'none',
            'ask_wall_status'  => 'none',
            'fetch_ok'         => false,
            'errors'           => $errors,
        ];
    }
}
