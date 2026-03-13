<?php
declare(strict_types=1);

/**
 * Parser 5: Signal Monitor Engine — Main Service
 *
 * Monitors candidates from Parser4, tracks price movement,
 * publishes entry signals when conditions are met.
 *
 * Pipeline: Parser0 → Parser1 → Parser2 → Parser3 → Parser4 → Parser5 → Executor
 *
 * NO regex, NO symbol validation, NO hardcoded paths.
 * All paths obtained via SystemPaths::instance()->get()
 *
 * @package Modules\Parser5SignalMonitor
 */

use Core\System\SystemPaths;

final class Parser5SignalMonitorService
{
    private array $config;
    private string $storageDir;
    private string $logsDir;
    /** @var resource|null */
    private $logHandle = null;

    public function __construct()
    {
        // Paths via SystemPaths — architectural law of Tredercopis
        $paths = SystemPaths::instance();

        $this->storageDir = $paths->get('parser.parser5_signal_monitor.storage');
        $this->logsDir = $paths->get('parser.parser5_signal_monitor.logs');

        $this->config = $this->loadConfig();
    }

    public function __destruct()
    {
        if ($this->logHandle !== null) {
            fclose($this->logHandle);
        }
    }

    /**
     * Main entry point (called by cron/runner)
     */
    public function execute(): array
    {
        $startTime = microtime(true);
        $startTs = date('c');

        $this->log('INFO', '========== Parser5 Signal Monitor START ==========');

        $result = [
            'ok' => true,
            'ts' => $startTs,
            'ts_unix' => time(),
            'monitored_count' => 0,
            'signals_published' => 0,
            'instant_signals' => 0,
            'expired_count' => 0,
            'new_candidates' => 0,
            'confirmed_count' => 0,
            'candidates_loaded' => 0,
            'errors' => [],
            'duration_ms' => 0,
        ];

        try {
            // 1. Load current monitor state
            // IMPORTANT: storage/monitor.json is stored as an object wrapper:
            // { updated_at, count, entries: {SYMBOL: {...}} }
            // We must load ONLY the entries array, otherwise count() becomes 3 and the monitor never updates.
            $monitor = $this->loadMonitorEntries();
            $this->log('INFO', 'Loaded monitor entries: ' . count($monitor));

            // 2. Load candidates from Parser4
            $candidates = $this->loadCandidates();
            $result['candidates_loaded'] = count($candidates);
            $this->log('INFO', 'Loaded ' . count($candidates) . ' candidates from Parser4');

            // 3. Process candidates: HOT → instant signal, COLD → monitor
            $instantSignals = [];
            $coldCandidates = [];
            $instantCount = 0;

            foreach ($candidates as $candidate) {
                $type = $this->classifyCandidate($candidate);
                if ($type === 'HOT') {
                    // Instant signal (must confirm impulse is STILL present on the latest history)
                    $signal = $this->processInstantSignal($candidate);
                    if ($signal !== null) {
                        $instantSignals[] = $signal;
                        $instantCount++;
                        $this->log('INFO', "INSTANT SIGNAL: {$signal['symbol']} {$signal['side']} abs_return=" . round($candidate['abs_return_best'] ?? $candidate['abs_return'] ?? 0, 4));
                    }
                } else {
                    // Cold → monitor
                    $coldCandidates[] = $candidate;
                }
            }

            $result['instant_signals'] = $instantCount;
            $this->log('INFO', "Classified: {$instantCount} HOT (instant), " . count($coldCandidates) . " COLD (monitor)");

            // 4. Sync COLD candidates to monitor
            $newCount = $this->syncCandidates($monitor, $coldCandidates);
            $result['new_candidates'] = $newCount;
            $this->log('INFO', 'Synced ' . $newCount . ' cold candidates to monitor');

            // 5. Expire old entries
            $expiredCount = $this->expireOldEntries($monitor);
            $result['expired_count'] = $expiredCount;
            if ($expiredCount > 0) {
                $this->log('INFO', 'Expired ' . $expiredCount . ' old monitor entries');
            }

            // 6. Check entry conditions for each monitored candidate
            $monitorSignals = [];
            $confirmedCount = 0;
            
            foreach ($monitor as $symbol => &$entry) {
                $checkResult = $this->checkEntryConditions($symbol, $entry);
                
                if ($checkResult['ready']) {
                    // Generate signal from monitor
                    $signal = $this->generateSignal($symbol, $entry, $checkResult);
                    if ($signal !== null) {
                        $signal['mode'] = 'monitor_confirmed';
                        $monitorSignals[] = $signal;
                        $entry['signal_published'] = true;
                        $entry['signal_ts'] = time();
                        $this->log('INFO', "MONITOR SIGNAL: {$symbol} {$entry['side']} entry={$signal['entry_price']} tp={$signal['take_profit']} sl={$signal['stop_loss']}");
                    }
                } elseif ($checkResult['confirmed']) {
                    $confirmedCount++;
                    $entry['confirmations'] = ($entry['confirmations'] ?? 0) + 1;
                }
            }
            unset($entry);

            // Combine instant and monitor signals
            $allSignals = array_merge($instantSignals, $monitorSignals);

            $result['confirmed_count'] = $confirmedCount;
            $result['signals_published'] = count($allSignals);
            $result['monitored_count'] = count($monitor);

            // 7. Save updated monitor state
            $this->saveMonitor($monitor);

            // 8. Merge and save signals
            $this->saveSignals($allSignals);

            // 9. Archive to daily history
            $this->archiveSignals($allSignals);

        } catch (Throwable $e) {
            $result['ok'] = false;
            $result['errors'][] = $e->getMessage();
            $this->log('ERROR', 'Exception: ' . $e->getMessage());
        }

        $result['duration_ms'] = (int)((microtime(true) - $startTime) * 1000);

        // Save last run
        $this->saveLastRun($result);

        $this->log('INFO', sprintf(
            'COMPLETE: monitored=%d signals=%d expired=%d new=%d duration=%dms',
            $result['monitored_count'],
            $result['signals_published'],
            $result['expired_count'],
            $result['new_candidates'],
            $result['duration_ms']
        ));

        return $result;
    }

    // =========================================================================
    // DATA LOADING
    // =========================================================================

    private function loadConfig(): array
    {
        $configFile = SystemPaths::instance()->get('parser.parser5_signal_monitor.config');
        if (!file_exists($configFile)) {
            throw new RuntimeException('Config file not found: ' . $configFile);
        }
        return require $configFile;
    }

    /**
     * Load monitor entries.
     * The stored file format is:
     * { "updated_at": "...", "count": N, "entries": { "BTCUSDT": {...}, ... } }
     * We must return ONLY the "entries" array.
     *
     * @return array<string,array<string,mixed>>
     */
    private function loadMonitorEntries(): array
    {
        $monitorFile = $this->resolvePath($this->config['output']['monitor'] ?? 'storage/monitor.json');

        if (!is_file($monitorFile)) {
            return [];
        }

        $content = file_get_contents($monitorFile);
        if ($content === false || $content === '') {
            return [];
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return [];
        }

        // Wrapper format
        if (isset($data['entries']) && is_array($data['entries'])) {
            return $data['entries'];
        }

        // Legacy format: direct map {SYMBOL: entry}
        return $data;
    }

    private function loadCandidates(): array
    {
        $candidatesStorageKey = (string)($this->config['sources']['candidates_storage_key'] ?? '');
        $candidatesFilename = (string)($this->config['sources']['candidates_filename'] ?? 'candidates.json');

        $candidatesDir = $this->resolvePath($candidatesStorageKey);
        $candidatesFile = rtrim($candidatesDir, '/') . '/' . ltrim($candidatesFilename, '/');

        if (!file_exists($candidatesFile)) {
            $this->log('WARNING', 'Candidates file not found: ' . $candidatesFile);
            return [];
        }

        $content = file_get_contents($candidatesFile);
        if ($content === false || $content === '') {
            return [];
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return [];
        }

        // Candidates can be directly an array or inside 'candidates' key
        if (isset($data['candidates']) && is_array($data['candidates'])) {
            return $data['candidates'];
        }

        return $data;
    }

    /**
     * Load latest price from Parser2 history in a memory-safe way.
     * Reads the latest NDJSON file and extracts the most recent valid row.
     */
    private function loadCurrentPrice(string $symbol): ?float
    {
        $latestRow = $this->loadLatestHistoryRow($symbol);
        if ($latestRow === null) {
            return null;
        }

        return $this->extractPrice($latestRow);
    }

    /**
     * Compute signed return for the latest window (minutes) using recent NDJSON tail.
     * This is used to ensure Instant Mode reacts to CURRENT impulse, not only Parser4 historic metrics.
     *
     * @return array{ok:bool,signed_return:float,abs_return:float,first_price:float,last_price:float,first_ts:int,last_ts:int}
     */
    private function computeWindowReturn(string $symbol, int $windowMinutes): array
    {
        $windowSeconds = max(1, $windowMinutes) * 60;

        // Read recent lines from the latest file (tail) to cover the needed window.
        $tail = $this->loadHistoryTailRows($symbol, 5000);
        if (empty($tail)) {
            return [
                'ok' => false,
                'signed_return' => 0.0,
                'abs_return' => 0.0,
                'first_price' => 0.0,
                'last_price' => 0.0,
                'first_ts' => 0,
                'last_ts' => 0,
            ];
        }

        // Tail rows are ordered from newest to oldest.
        $lastRow = $tail[0];
        $lastTs = $this->extractTsUnix($lastRow);
        $lastPrice = $this->extractPrice($lastRow);

        if ($lastTs <= 0 || $lastPrice === null || $lastPrice <= 0) {
            return [
                'ok' => false,
                'signed_return' => 0.0,
                'abs_return' => 0.0,
                'first_price' => 0.0,
                'last_price' => 0.0,
                'first_ts' => 0,
                'last_ts' => 0,
            ];
        }

        $startTs = $lastTs - $windowSeconds;

        $firstPrice = null;
        $firstTs = 0;

        // Walk older rows until we reach the beginning of window.
        foreach ($tail as $row) {
            $ts = $this->extractTsUnix($row);
            if ($ts <= 0) {
                continue;
            }
            if ($ts < $startTs) {
                // We crossed outside the window.
                break;
            }

            $p = $this->extractPrice($row);
            if ($p === null || $p <= 0) {
                continue;
            }

            // Because we iterate newest->oldest, the last assignment becomes the oldest price within window.
            $firstPrice = $p;
            $firstTs = $ts;
        }

        if ($firstPrice === null || $firstPrice <= 0) {
            // Not enough data for the window.
            return [
                'ok' => false,
                'signed_return' => 0.0,
                'abs_return' => 0.0,
                'first_price' => 0.0,
                'last_price' => $lastPrice,
                'first_ts' => 0,
                'last_ts' => $lastTs,
            ];
        }

        $signed = ($lastPrice / $firstPrice) - 1.0;
        $abs = abs($signed);

        return [
            'ok' => true,
            'signed_return' => $signed,
            'abs_return' => $abs,
            'first_price' => $firstPrice,
            'last_price' => $lastPrice,
            'first_ts' => $firstTs,
            'last_ts' => $lastTs,
        ];
    }

    private function extractPrice(array $row): ?float
    {
        // Direct price fields
        $priceKeys = ['price', 'lastPrice', 'markPrice', 'close', 'c'];
        
        foreach ($priceKeys as $key) {
            if (isset($row[$key]) && is_numeric($row[$key])) {
                return (float)$row[$key];
            }
        }

        // Nested in data
        if (isset($row['data']) && is_array($row['data'])) {
            foreach ($priceKeys as $key) {
                if (isset($row['data'][$key]) && is_numeric($row['data'][$key])) {
                    return (float)$row['data'][$key];
                }
            }
        }

        return null;
    }

    // =========================================================================
    // CANDIDATE CLASSIFICATION: HOT vs COLD
    // =========================================================================

    /**
     * Classify candidate as HOT (instant signal) or COLD (monitor)
     */
    private function classifyCandidate(array $candidate): string
    {
        $instantEnabled = $this->config['modes']['instant_signal']['enabled'] ?? true;
        if (!$instantEnabled) {
            return 'COLD';
        }

        $absThreshold = $this->config['modes']['instant_signal']['abs_return_threshold'] ?? 0.02;
        $minScore = $this->config['modes']['instant_signal']['min_score'] ?? 0.3;

        // Fast pre-filter (cheap): use Parser4 score/abs_return
        $absReturn = (float)($candidate['abs_return_best'] ?? $candidate['abs_return'] ?? 0);
        $score = (float)($candidate['score'] ?? 0);

        if ($absReturn < $absThreshold || $score < $minScore) {
            return 'COLD';
        }

        // Expensive check (accurate): validate CURRENT impulse on the latest window.
        $fallbackWindow = (int)($this->config['modes']['instant_signal']['window_minutes_fallback'] ?? 5);
        $windowMinutes = (int)($candidate['best_window_min'] ?? 0);
        if ($windowMinutes <= 0) {
            $windowMinutes = $fallbackWindow;
        }

        $symbol = (string)($candidate['symbol'] ?? '');
        if ($symbol === '') {
            return 'COLD';
        }

        $windowRet = $this->computeWindowReturn($symbol, $windowMinutes);
        if (($windowRet['ok'] ?? false) !== true) {
            return 'COLD';
        }

        $currentAbs = (float)($windowRet['abs_return'] ?? 0.0);
        if ($currentAbs < $absThreshold) {
            return 'COLD';
        }

        $candidateSide = (string)($candidate['side'] ?? 'long');
        $currentSigned = (float)($windowRet['signed_return'] ?? 0.0);
        $currentSide = $currentSigned >= 0 ? 'long' : 'short';
        if ($candidateSide !== $currentSide) {
            return 'COLD';
        }

        return 'HOT';
    }

    /**
     * Process HOT candidate → instant signal
     */
    private function processInstantSignal(array $candidate): ?array
    {
        $symbol = $candidate['symbol'] ?? null;
        if ($symbol === null || !is_string($symbol) || $symbol === '') {
            return null;
        }

        
        // Normalize symbol for consistent comparisons and stable ID generation
        $symbol = strtoupper(trim($symbol));
// Validate instant impulse on the latest history window.
        $fallbackWindow = (int)($this->config['modes']['instant_signal']['window_minutes_fallback'] ?? 5);
        $windowMinutes = (int)($candidate['best_window_min'] ?? 0);
        if ($windowMinutes <= 0) {
            $windowMinutes = $fallbackWindow;
        }

        $windowRet = $this->computeWindowReturn($symbol, $windowMinutes);
        if (($windowRet['ok'] ?? false) !== true) {
            $this->log('DEBUG', "Instant skip {$symbol}: not enough window data");
            return null;
        }

        $currentAbs = (float)($windowRet['abs_return'] ?? 0.0);
        $currentSigned = (float)($windowRet['signed_return'] ?? 0.0);

        $absThreshold = (float)($this->config['modes']['instant_signal']['abs_return_threshold'] ?? 0.02);
        if ($currentAbs < $absThreshold) {
            $this->log('DEBUG', "Instant downgrade {$symbol}: current_abs={$currentAbs} < {$absThreshold}");
            return null;
        }

        // Determine side from current impulse direction, but keep candidate side if provided.
        $candidateSide = strtolower(trim((string)($candidate['side'] ?? 'long')));
        if ($candidateSide === 'buy') { $candidateSide = 'long'; }
        if ($candidateSide === 'sell') { $candidateSide = 'short'; }
        if ($candidateSide !== 'long' && $candidateSide !== 'short') { $candidateSide = 'long'; }
        $currentSide = $currentSigned >= 0 ? 'long' : 'short';
        if ($candidateSide !== $currentSide) {
            // Impulse direction flipped — do NOT publish instant signal.
            $this->log('DEBUG', "Instant skip {$symbol}: direction flipped candidate={$candidateSide} current={$currentSide}");
            return null;
        }

        $currentPrice = (float)($windowRet['last_price'] ?? 0.0);
        if ($currentPrice <= 0) {
            return null;
        }

        // Check signal limit
        $maxSignals = $this->config['signal']['max_active_signals'] ?? 20;
        $currentSignals = $this->loadCurrentSignals();
        if (count($currentSignals) >= $maxSignals) {
            $this->log('WARNING', "Signal limit reached ({$maxSignals}), skipping instant {$symbol}");
            return null;
        }

        // Check for duplicate
        foreach ($currentSignals as $sig) {
            if (($sig['symbol'] ?? '') === $symbol && ($sig['status'] ?? '') === 'active') {
                $this->log('DEBUG', "{$symbol}: instant signal already exists");
                return null;
            }
        }

        $side = $candidateSide;
        $tpPct = $this->config['signal']['take_profit_pct'] ?? 0.02;
        $slPct = $this->config['signal']['stop_loss_pct'] ?? 0.01;
        $validityMin = $this->config['signal']['validity_minutes'] ?? 30;

        // Calculate TP and SL
        if ($side === 'long') {
            $takeProfit = $currentPrice * (1 + $tpPct);
            $stopLoss = $currentPrice * (1 - $slPct);
        } else {
            $takeProfit = $currentPrice * (1 - $tpPct);
            $stopLoss = $currentPrice * (1 + $slPct);
        }

        $now = time();
        $score = (float)($candidate['score'] ?? 0);
        // Store both historic abs_return (from Parser4) and current abs_return (from latest window)
        $absReturn = (float)($candidate['abs_return_best'] ?? $candidate['abs_return'] ?? 0);
        $absReturnNow = $currentAbs;

        // Pre-compute rounded prices (avoid duplicate round() calls)
        $roundedEntry = round($currentPrice, 8);
        $roundedTp = round($takeProfit, 8);
        $roundedSl = round($stopLoss, 8);

        // P6.1: Generate stable signal ID
        $strategyId = (string)($candidate['strategy_id'] ?? 'parser5');
        $stableId = $this->generateStableSignalId($symbol, $roundedEntry, $side, $strategyId, $now);

        // Full contract format for Brain consumption (ТЗ #3)
        return [
            'id' => $stableId,
            'schema_version' => 'clean_signal_v1',
            'symbol' => $symbol,
            'side' => $side,
            // Legacy flat fields (backward compatibility)
            'entry_price' => $roundedEntry,
            'take_profit' => $roundedTp,
            'stop_loss' => $roundedSl,
            // Structured entry/tp/sl for Brain contract
            'entry' => [
                'type' => 'market',
                'price' => $roundedEntry,
            ],
            'tp' => [
                'price' => $roundedTp,
            ],
            'sl' => [
                'price' => $roundedSl,
            ],
            'validity_minutes' => $validityMin,
            'expires_at' => $now + ($validityMin * 60),
            'created_at' => date('c'),
            'created_ts' => $now,
            'status' => 'active',
            'score' => $score,
            'abs_return' => $absReturn,
            'abs_return_now' => $absReturnNow,
            'confidence' => min(1.0, $absReturn * 20 + $score),
            'confirmations' => (int)($candidate['confirmations'] ?? 0),
            'source' => 'parser5',
            'mode' => 'instant',
            'window_minutes' => $windowMinutes,
        ];
    }

    // =========================================================================
    // MONITOR MANAGEMENT
    // =========================================================================

    private function syncCandidates(array &$monitor, array $candidates): int
    {
        $maxMonitored = $this->config['monitor']['max_monitored'] ?? 50;
        $minScore = $this->config['monitor']['min_score'] ?? 0.01;
        $newCount = 0;

        foreach ($candidates as $candidate) {
            $symbol = $candidate['symbol'] ?? null;
            if ($symbol === null || !is_string($symbol) || $symbol === '') {
                continue;
            }

            // Skip if already monitoring
            if (isset($monitor[$symbol])) {
                // Update score if higher
                $newScore = (float)($candidate['score'] ?? 0);
                if ($newScore > ($monitor[$symbol]['score'] ?? 0)) {
                    $monitor[$symbol]['score'] = $newScore;
                }
                continue;
            }

            // Check score threshold
            $score = (float)($candidate['score'] ?? 0);
            if ($score < $minScore) {
                continue;
            }

            // Check limit
            if (count($monitor) >= $maxMonitored) {
                break;
            }

            // Get current price for entry tracking
            $currentPrice = $this->loadCurrentPrice($symbol);
            if ($currentPrice === null) {
                $this->log('DEBUG', "Skip {$symbol}: no price data");
                continue;
            }

            // Add to monitor
            $monitor[$symbol] = [
                'symbol' => $symbol,
                'side' => $candidate['side'] ?? 'long',
                'score' => $score,
                'abs_return' => (float)($candidate['abs_return_best'] ?? $candidate['abs_return'] ?? 0),
                'entry_price_start' => $currentPrice,
                'last_price' => $currentPrice,
                'high_price' => $currentPrice,
                'low_price' => $currentPrice,
                'added_ts' => time(),
                'last_check_ts' => time(),
                'checks' => 0,
                'confirmations' => 0,
                'signal_published' => false,
                'mode' => 'monitor',
            ];

            $newCount++;
            $this->log('INFO', "MONITOR ADD: {$symbol} side={$candidate['side']} score=" . round($score, 4) . " price={$currentPrice}");
        }

        return $newCount;
    }

    private function expireOldEntries(array &$monitor): int
    {
        $maxTime = ($this->config['entry']['max_monitor_time'] ?? 30) * 60; // seconds
        $ttl = ($this->config['monitor']['monitor_ttl_minutes'] ?? 60) * 60;
        $now = time();
        $expiredCount = 0;

        foreach ($monitor as $symbol => $entry) {
            $age = $now - ($entry['added_ts'] ?? 0);

            // Expire if: signal already published, too old, or TTL exceeded
            $shouldExpire = false;
            $reason = '';

            if ($entry['signal_published'] ?? false) {
                $shouldExpire = true;
                $reason = 'signal_published';
            } elseif ($age > $ttl) {
                $shouldExpire = true;
                $reason = 'ttl_exceeded';
            } elseif ($age > $maxTime && ($entry['confirmations'] ?? 0) < 1) {
                $shouldExpire = true;
                $reason = 'no_confirmation';
            }

            if ($shouldExpire) {
                $this->log('INFO', "MONITOR EXPIRE: {$symbol} reason={$reason} age=" . round($age / 60, 1) . "min");
                unset($monitor[$symbol]);
                $expiredCount++;
            }
        }

        return $expiredCount;
    }

    // =========================================================================
    // ENTRY CONDITION CHECKING
    // =========================================================================

    private function checkEntryConditions(string $symbol, array &$entry): array
    {
        $result = [
            'ready' => false,
            'confirmed' => false,
            'price_move' => 0,
            'drawdown' => 0,
            'current_price' => null,
        ];

        // Get current price
        $currentPrice = $this->loadCurrentPrice($symbol);
        if ($currentPrice === null) {
            return $result;
        }

        $result['current_price'] = $currentPrice;

        // Update tracking
        $entry['last_price'] = $currentPrice;
        $entry['last_check_ts'] = time();
        $entry['checks'] = (int)($entry['checks'] ?? 0) + 1;

        if ($currentPrice > ($entry['high_price'] ?? $currentPrice)) {
            $entry['high_price'] = $currentPrice;
        }
        if ($currentPrice < ($entry['low_price'] ?? $currentPrice)) {
            $entry['low_price'] = $currentPrice;
        }

        // Calculate metrics
        $entryPriceStart = $entry['entry_price_start'] ?? $currentPrice;
        $side = $entry['side'] ?? 'long';

        // Price move from start
        $priceMove = ($currentPrice - $entryPriceStart) / $entryPriceStart;
        if ($side === 'short') {
            $priceMove = -$priceMove;
        }
        $result['price_move'] = $priceMove;

        // Drawdown (max adverse movement)
        if ($side === 'long') {
            $drawdown = ($entry['high_price'] - $currentPrice) / $entry['high_price'];
        } else {
            $drawdown = ($currentPrice - $entry['low_price']) / $entry['low_price'];
        }
        $result['drawdown'] = $drawdown;

        // Get thresholds from config
        $minPriceMove = $this->config['entry']['min_price_move_pct'] ?? 0.003;
        $maxDrawdown = $this->config['entry']['max_drawdown_pct'] ?? 0.01;
        $minMonitorTime = ($this->config['entry']['min_monitor_time'] ?? 2) * 60;
        $confirmationRequired = $this->config['entry']['confirmation_count'] ?? 2;
        $minChecks = (int)($this->config['modes']['monitor']['min_checks'] ?? 2);

        // Check minimum time
        $age = time() - ($entry['added_ts'] ?? time());
        if ($age < $minMonitorTime) {
            return $result;
        }

        // Check minimum checks (cron ticks)
        if (((int)($entry['checks'] ?? 0)) < $minChecks) {
            return $result;
        }

        // Check drawdown (invalidate if too high)
        if ($drawdown > $maxDrawdown) {
            $this->log('DEBUG', "{$symbol}: drawdown exceeded ({$drawdown} > {$maxDrawdown})");
            return $result;
        }

        // Check price movement direction
        if ($priceMove >= $minPriceMove) {
            $result['confirmed'] = true;

            // Check if enough confirmations
            $confirmations = ($entry['confirmations'] ?? 0) + 1;
            if ($confirmations >= $confirmationRequired) {
                $result['ready'] = true;
            }
        }

        return $result;
    }

    // =========================================================================
    // SIGNAL GENERATION
    // =========================================================================

    private function generateSignal(string $symbol, array $entry, array $checkResult): ?array
    {
                // Normalize symbol early for consistent comparisons
        $symbol = strtoupper(trim($symbol));
$maxSignals = $this->config['signal']['max_active_signals'] ?? 20;
        $validityMin = $this->config['signal']['validity_minutes'] ?? 30;
        $tpPct = $this->config['signal']['take_profit_pct'] ?? 0.02;
        $slPct = $this->config['signal']['stop_loss_pct'] ?? 0.01;

        // Load current signals to check limit
        $currentSignals = $this->loadCurrentSignals();
        if (count($currentSignals) >= $maxSignals) {
            $this->log('WARNING', "Signal limit reached ({$maxSignals}), skipping {$symbol}");
            return null;
        }

        // Check for duplicate
        foreach ($currentSignals as $sig) {
            if (($sig['symbol'] ?? '') === $symbol && ($sig['status'] ?? '') === 'active') {
                $this->log('DEBUG', "{$symbol}: signal already exists");
                return null;
            }
        }

        $currentPrice = $checkResult['current_price'] ?? $entry['last_price'] ?? 0;
        $side = strtolower(trim((string)($entry['side'] ?? 'long')));
        if ($side === 'buy') { $side = 'long'; }
        if ($side === 'sell') { $side = 'short'; }
        if ($side !== 'long' && $side !== 'short') { $side = 'long'; }

        // Calculate TP and SL
        if ($side === 'long') {
            $takeProfit = $currentPrice * (1 + $tpPct);
            $stopLoss = $currentPrice * (1 - $slPct);
        } else {
            $takeProfit = $currentPrice * (1 - $tpPct);
            $stopLoss = $currentPrice * (1 + $slPct);
        }

        $now = time();

        // Pre-compute rounded prices (avoid duplicate round() calls)
        $roundedEntry = round($currentPrice, 8);
        $roundedTp = round($takeProfit, 8);
        $roundedSl = round($stopLoss, 8);

        // P6.1: Generate stable signal ID
        $strategyId = (string)($entry['strategy_id'] ?? 'parser5');
        $stableId = $this->generateStableSignalId($symbol, $roundedEntry, $side, $strategyId, $now);

        // Full contract format for Brain consumption (ТЗ #3)
        return [
            'id' => $stableId,
            'schema_version' => 'clean_signal_v1',
            'symbol' => $symbol,
            'side' => $side,
            // Legacy flat fields (backward compatibility)
            'entry_price' => $roundedEntry,
            'take_profit' => $roundedTp,
            'stop_loss' => $roundedSl,
            // Structured entry/tp/sl for Brain contract
            'entry' => [
                'type' => 'market',
                'price' => $roundedEntry,
            ],
            'tp' => [
                'price' => $roundedTp,
            ],
            'sl' => [
                'price' => $roundedSl,
            ],
            'validity_minutes' => $validityMin,
            'expires_at' => $now + ($validityMin * 60),
            'created_at' => date('c'),
            'created_ts' => $now,
            'status' => 'active',
            'score' => (float)($entry['score'] ?? 0),
            'abs_return' => (float)($entry['abs_return'] ?? 0),
            'confirmations' => (int)($entry['confirmations'] ?? 0),
            'source' => 'parser5',
        ];
    }

    private function loadCurrentSignals(): array
    {
        $signalsFile = $this->resolvePath($this->config['output']['signals'] ?? 'storage/signals.json');
        
        if (!file_exists($signalsFile)) {
            return [];
        }

        $content = file_get_contents($signalsFile);
        if ($content === false || $content === '') {
            return [];
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return [];
        }

        // Can be array of signals or object with 'signals' key
        if (isset($data['signals']) && is_array($data['signals'])) {
            return $data['signals'];
        }

        return $data;
    }

    // =========================================================================
    // OUTPUT
    // =========================================================================

    private function saveMonitor(array $monitor): void
    {
        $monitorFile = $this->resolvePath($this->config['output']['monitor'] ?? 'storage/monitor.json');
        $this->ensureDir(dirname($monitorFile));

        $data = [
            'updated_at' => date('c'),
            'count' => count($monitor),
            'entries' => $monitor,
        ];

        $this->writeJsonAtomic($monitorFile, $data);
    }

    private function saveSignals(array $newSignals): void
    {
        $signalsFile = $this->resolvePath($this->config['output']['signals'] ?? 'storage/signals.json');
        $this->ensureDir(dirname($signalsFile));

        // Load existing signals
        $existingSignals = $this->loadCurrentSignals();

        // Remove expired signals
        $now = time();
        $activeSignals = [];
        foreach ($existingSignals as $signal) {
            $expiresAt = $signal['expires_at'] ?? 0;
            if ($expiresAt > $now && ($signal['status'] ?? '') === 'active') {
                $activeSignals[] = $signal;
            }
        }

        // Add new signals
        foreach ($newSignals as $signal) {
            $activeSignals[] = $signal;
        }

        // Limit total
        $maxSignals = $this->config['signal']['max_active_signals'] ?? 20;
        if (count($activeSignals) > $maxSignals) {
            // Sort by score desc and take top
            usort($activeSignals, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
            $activeSignals = array_slice($activeSignals, 0, $maxSignals);
        }

        $data = [
            'updated_at' => date('c'),
            'count' => count($activeSignals),
            'signals' => $activeSignals,
        ];

        $this->writeJsonAtomic($signalsFile, $data);
    }

    private function archiveSignals(array $signals): void
    {
        if (empty($signals)) {
            return;
        }

        $historyDir = $this->resolvePath($this->config['output']['history_dir'] ?? 'storage/history');
        $this->ensureDir($historyDir);

        $date = date('Y-m-d');
        $historyFile = $historyDir . '/' . $date . '.ndjson';

        $handle = fopen($historyFile, 'a');
        if ($handle === false) {
            return;
        }

        foreach ($signals as $signal) {
            fwrite($handle, json_encode($signal) . "\n");
        }

        fclose($handle);
    }

    private function saveLastRun(array $result): void
    {
        $lastRunFile = $this->resolvePath($this->config['output']['last_run'] ?? 'storage/last_run.json');
        $this->ensureDir(dirname($lastRunFile));
        $this->writeJsonAtomic($lastRunFile, $result);
    }

    /**
     * Atomic JSON write (temp file + rename).
     *
     * @param string $path
     * @param mixed $data
     */
    private function writeJsonAtomic(string $path, $data): void
    {
        $dir = dirname($path);
        $this->ensureDir($dir);

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        $tmp = $path . '.tmp';
        file_put_contents($tmp, $json, LOCK_EX);
        rename($tmp, $path);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function dirOf(string $path): string
{
    $pos = strrpos($path, '/');
    if ($pos === false) {
        return '';
    }
    return substr($path, 0, $pos);
}

/**
 * Resolve a module path WITHOUT filesystem scanning and WITHOUT hardcoded module routes.
 *
 * Rules:
 * - absolute path "/..." is returned as-is
 * - "storage/..." is resolved to this parser storage directory
 * - "logs/..." is resolved to this parser logs directory
 * - a key-like string "parser.parser4_analyzer.storage" is resolved via SystemPaths
 * - any other relative path is treated as relative to this parser storage directory
 */
private function resolvePath(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }

    // Absolute path is allowed (Core may pass it), but modules should normally use keys.
    if (strpos($path, '/') === 0) {
        return $path;
    }

    // SystemPaths key format (no slashes, has dots)
    if (strpos($path, '/') === false && strpos($path, '.') !== false) {
        try {
            return SystemPaths::instance()->get($path);
        } catch (Throwable $e) {
            // fallback below
        }
    }

    // storage/... -> parser5 storage dir
    if (strpos($path, 'storage/') === 0) {
        return rtrim($this->storageDir, '/') . '/' . substr($path, strlen('storage/'));
    }

    // logs/... -> parser5 logs dir
    if (strpos($path, 'logs/') === 0) {
        return rtrim($this->logsDir, '/') . '/' . substr($path, strlen('logs/'));
    }

    // Default: treat as relative to storage
    return rtrim($this->storageDir, '/') . '/' . ltrim($path, '/');
}

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    private function log(string $level, string $message): void
    {
        $logFile = $this->resolvePath($this->config['output']['log'] ?? 'storage/logs/signal_monitor.log');
        $this->ensureDir($this->dirOf($logFile));

        // Skip debug if not enabled
        if ($level === 'DEBUG' && !($this->config['logging']['debug'] ?? false)) {
            return;
        }

        // Check log rotation
        $maxSize = ($this->config['logging']['max_log_size_mb'] ?? 10) * 1024 * 1024;
        if (file_exists($logFile) && filesize($logFile) > $maxSize) {
            $rotated = $logFile . '.' . date('Y-m-d-His') . '.old';
            rename($logFile, $rotated);
        }

        // Open log handle if needed
        if ($this->logHandle === null) {
            $this->logHandle = fopen($logFile, 'a');
        }

        if ($this->logHandle !== false && $this->logHandle !== null) {
            $timestamp = date('Y-m-d H:i:s');
            fwrite($this->logHandle, "[{$timestamp}] [{$level}] {$message}\n");
        }
    }

    // =========================================================================
    // HISTORY HELPERS (Parser2 NDJSON)
    // =========================================================================

    /**
     * Load the latest NDJSON file row for a symbol.
     * Returns decoded array of the newest valid line.
     *
     * @return array<string,mixed>|null
     */
    private function loadLatestHistoryRow(string $symbol): ?array
    {
        $rows = $this->loadHistoryTailRows($symbol, 200);
        if (empty($rows)) {
            return null;
        }
        // Newest row is first
        return $rows[0];
    }

    /**
     * Load tail rows (newest->oldest) from the latest NDJSON file for a symbol.
     * Memory-safe: reads from the end of file in chunks.
     *
     * @param string $symbol
     * @param int $maxLines
     * @return array<int,array<string,mixed>>
     */
    private function loadHistoryTailRows(string $symbol, int $maxLines): array
    {
        $historyStorageKey = (string)($this->config['sources']['history_storage_key'] ?? '');
        $historyDir = $this->resolvePath($historyStorageKey);
        $symbolDir = $historyDir . '/' . $symbol;

        if (!is_dir($symbolDir)) {
            return [];
        }

        $files = glob($symbolDir . '/*.ndjson');
        if (empty($files)) {
            return [];
        }

        sort($files);
        $latestFile = $files[count($files) - 1];

        if (!is_file($latestFile)) {
            return [];
        }

        $lines = $this->readFileTailLines($latestFile, $maxLines);
        if (empty($lines)) {
            return [];
        }

        // Parse to arrays, keep newest->oldest.
        $rows = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (!is_array($row)) {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Read last N non-empty lines from file, returned newest->oldest.
     *
     * @param string $path
     * @param int $maxLines
     * @return array<int,string>
     */
    private function readFileTailLines(string $path, int $maxLines): array
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return [];
        }

        $lines = [];
        $buffer = '';
        $pos = -1;
        $chunkSize = 8192;

        fseek($fh, 0, SEEK_END);
        $fileSize = ftell($fh);
        if ($fileSize === false) {
            fclose($fh);
            return [];
        }

        while (count($lines) < $maxLines && abs($pos) <= $fileSize) {
            $readSize = $chunkSize;
            if (abs($pos) + $chunkSize > $fileSize) {
                $readSize = $fileSize - abs($pos);
            }
            $pos -= $readSize;
            fseek($fh, $pos, SEEK_END);
            if ($readSize <= 0) {
                break;
            }
            $chunk = fread($fh, $readSize);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buffer = $chunk . $buffer;

            // Split by \n and keep last partial in buffer for next iteration.
            $parts = explode("\n", $buffer);
            $buffer = array_shift($parts);

            // Parts are in chronological order; we need newest->oldest.
            for ($i = count($parts) - 1; $i >= 0; $i--) {
                $line = trim($parts[$i]);
                if ($line === '') {
                    continue;
                }
                $lines[] = $line;
                if (count($lines) >= $maxLines) {
                    break 2;
                }
            }
        }

        // If we still need lines, buffer may contain the earliest part.
        if (count($lines) < $maxLines) {
            $line = trim($buffer);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        fclose($fh);

        return $lines;
    }

    /**
     * Extract timestamp unix from Parser2 row.
     */
    private function extractTsUnix(array $row): int
    {
        if (isset($row['ts_unix'])) {
            return (int)$row['ts_unix'];
        }
        if (isset($row['ts'])) {
            $parsed = strtotime((string)$row['ts']);
            return $parsed !== false ? (int)$parsed : 0;
        }
        return 0;
    }

    private function archiveSignal(array $signal): void
    {
        $date = date('Y-m-d');
        $archiveDir = $this->storageDir . '/signals/archive';
        if (!is_dir($archiveDir)) {
            mkdir($archiveDir, 0755, true);
        }
        $file = $archiveDir . '/' . $date . '.json';
        $data = [];
        if (is_file($file)) {
            $data = json_decode(file_get_contents($file), true) ?: [];
        }
        $data[] = $signal;
        file_put_contents(
            $file,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * P6.1: Generate stable signal ID
     * 
     * ID = sha1(schema_version + symbol + entry_price + direction + source_strategy_id + source_ts_bucket)
     * source_ts_bucket = ts rounded to 60 seconds (to avoid jitter from milliseconds)
     * 
     * @param string $symbol Trading symbol
     * @param float $entryPrice Entry price
     * @param string $side Direction (long/short)
     * @param string $strategyId Source strategy identifier (optional)
     * @param int $ts Unix timestamp for the signal
     * @return string Stable signal ID (sha1 hash)
     */
    private function generateStableSignalId(
        string $symbol,
        float $entryPrice,
        string $side,
        string $strategyId,
        int $ts
    ): string {
                // Normalize inputs to keep IDs stable across sources
        $symbol = strtoupper(trim($symbol));
        $side = strtolower(trim($side));
        if ($side === 'buy') { $side = 'long'; }
        if ($side === 'sell') { $side = 'short'; }
        if ($side !== 'long' && $side !== 'short') { $side = 'long'; }
        $strategyId = trim($strategyId);
$schemaVersion = 'clean_signal_v1';
        // Round ts to 60 seconds to avoid jitter from milliseconds
        $tsBucket = (int)(floor($ts / 60) * 60);
        // Normalize entry price to 8 decimal places for consistency
        $normalizedPrice = number_format($entryPrice, 8, '.', '');
        
        $hashInput = $schemaVersion . $symbol . $normalizedPrice . $side . $strategyId . $tsBucket;
        return sha1($hashInput);
    }

}

/* RULES
- CONFIG FIRST / ZERO-HARDCODE
- All module paths must be resolved via Core\System\SystemPaths keys
- No filesystem root detection (no root-detection, no [local-dir-const] path building for project roots)
- Log errors in release mode; show only if debug enabled (controlled by config)
- LF only
*/
