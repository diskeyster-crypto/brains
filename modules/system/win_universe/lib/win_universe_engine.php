<?php
declare(strict_types=1);

/**
 * WinUniverseEngine
 *
 * Core computation engine for the Win Universe module.
 *
 * Responsibilities:
 *   1. Load closed trade records from Trading Bot and Smart Brain simulator sources.
 *   2. Filter trades to the configured lookback window.
 *   3. Compute per-symbol statistics (closed_trades_count, wins_count, losses_count,
 *      recent_avg_roi, best_roi, recent_winrate, last_trade_time).
 *   4. Run qualification logic and produce per-symbol qualification records.
 *
 * Hard constraints (MUST NOT violate):
 *   - Read-only: never writes to Smart Brain, Trading Bot, or Profit Manager storage.
 *   - No exchange calls, no gateway access.
 *   - No AI logic.
 */
final class WinUniverseEngine
{
    /** @var string Absolute path to Trading Bot module root (sibling of this module) */
    private string $botModuleBase;

    /** @var string Absolute path to Smart Brain module root (sibling of this module) */
    private string $brainModuleBase;

    public function __construct(string $moduleBase)
    {
        $this->botModuleBase   = dirname($moduleBase) . '/trading_bot';
        $this->brainModuleBase = dirname($moduleBase) . '/smart_brain';
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Compute per-symbol statistics, qualification results, and win-pool lifecycle.
     *
     * @param array<string,mixed> $config      Merged module config (win_universe block)
     * @param array<string,mixed> $prevPool    Previously persisted win pool state (from win_universe_pool.json)
     * @return array{
     *   symbols: array<string,array<string,mixed>>,
     *   qualified: string[],
     *   near_qualified: string[],
     *   rejected: string[],
     *   win_pool: array<string,array<string,mixed>>,
     *   promotions: array<int,array<string,mixed>>,
     *   demotions: array<int,array<string,mixed>>,
     *   config_used: array<string,mixed>,
     *   sources_used: string[],
     *   computed_at: string,
     *   trade_count_total: int,
     * }
     */
    public function compute(array $config, array $prevPool = []): array
    {
        $ts = date('c');

        $minRoi           = (float)($config['min_roi_threshold']      ?? 1.5);
        $minAvgRoi        = (float)($config['min_avg_roi']            ?? 0.0);
        $lookbackDays     = max(1, (int)($config['lookback_days']     ?? 30));
        $minTrades        = max(1, (int)($config['min_closed_trades'] ?? 3));
        $minWinrate       = (float)($config['min_winrate']            ?? 0.0);
        $expiryDays       = (int)($config['qualification_expiry_days'] ?? 90);
        $demotionStreak   = (int)($config['demotion_loss_streak']     ?? 0);

        $cutoff = time() - ($lookbackDays * 86400);

        // Load raw closed trades from all sources
        [$rawTrades, $sourcesUsed] = $this->loadAllClosedTrades();

        // Compute per-symbol stats from trades within lookback window
        $symbolStats = $this->aggregateBySymbol($rawTrades, $cutoff, $minRoi);

        // Run qualification engine on each symbol
        $results       = [];
        $qualified     = [];
        $nearQualified = [];
        $rejected      = [];

        foreach ($symbolStats as $symbol => $stats) {
            $rec = $this->qualify($symbol, $stats, $minRoi, $minAvgRoi, $minTrades, $minWinrate, $lookbackDays);
            $results[$symbol] = $rec;
            if ($rec['qualified']) {
                $qualified[] = $symbol;
            } elseif ($rec['near_qualified']) {
                $nearQualified[] = $symbol;
            } else {
                $rejected[] = $symbol;
            }
        }

        // Sort qualified list by recent_avg_roi descending
        usort($qualified, static function (string $a, string $b) use ($results): int {
            return ($results[$b]['recent_avg_roi'] ?? 0.0) <=> ($results[$a]['recent_avg_roi'] ?? 0.0);
        });
        usort($nearQualified, static function (string $a, string $b) use ($results): int {
            return ($results[$b]['recent_avg_roi'] ?? 0.0) <=> ($results[$a]['recent_avg_roi'] ?? 0.0);
        });
        sort($rejected);

        $tradeCountTotal = array_sum(array_column($symbolStats, 'closed_trades_count_window'));

        // Threshold sensitivity: count how many symbols fail by each criterion
        $sensitivity = $this->buildSensitivityBreakdown($results, $minTrades, $minRoi, $minAvgRoi, $minWinrate);

        // Two-pool lifecycle: compute promotions, demotions, new win pool state
        [$newPool, $promotions, $demotions] = $this->computeLifecycle(
            $results,
            $prevPool,
            $ts,
            $expiryDays,
            $demotionStreak,
            $minRoi,
            $rawTrades,
            $cutoff
        );

        // Build demotion metadata map from this run's events (symbol → event record)
        $demotionMap = [];
        foreach ($demotions as $ev) {
            $demotionMap[(string)($ev['symbol'] ?? '')] = $ev;
        }

        // Annotate each symbol with pool membership, eligibility, and lifecycle metadata
        foreach ($results as $sym => &$rec) {
            $inPool                    = isset($newPool[$sym]);
            $rec['in_win_pool']        = $inPool;
            $rec['promotion_eligible'] = !$inPool && $rec['qualified'];
            // demotion_eligible = true when the symbol currently sits in the win pool
            // (it is by definition eligible to be demoted if it fails maintenance rules)
            $rec['demotion_eligible']  = $inPool;
            $rec['win_pool_entry']     = $inPool ? $newPool[$sym] : null;

            // Promotion metadata comes from the pool entry (written on promotion)
            if ($inPool) {
                $rec['promotion_reason']    = $newPool[$sym]['promotion_reason'] ?? null;
                $rec['last_promotion_time'] = $newPool[$sym]['promoted_at'] ?? null;
            }

            // Demotion metadata for symbols demoted in this run
            if (isset($demotionMap[$sym])) {
                $rec['demotion_reason']    = $demotionMap[$sym]['reason'];
                $rec['last_demotion_time'] = $demotionMap[$sym]['timestamp'];
            }
        }
        unset($rec);

        return [
            'symbols'               => $results,
            'qualified'             => $qualified,
            'near_qualified'        => $nearQualified,
            'rejected'              => $rejected,
            'win_pool'              => $newPool,
            'promotions'            => $promotions,
            'demotions'             => $demotions,
            'config_used'           => [
                'min_roi_threshold'         => $minRoi,
                'min_avg_roi'               => $minAvgRoi,
                'lookback_days'             => $lookbackDays,
                'min_closed_trades'         => $minTrades,
                'min_winrate'               => $minWinrate,
                'qualification_expiry_days' => $expiryDays,
                'demotion_loss_streak'      => $demotionStreak,
                'win_universe_mode'         => (string)($config['win_universe_mode'] ?? 'shadow'),
                'priority_bonus_enabled'    => (bool)($config['priority_bonus_enabled'] ?? false),
                'priority_bonus_strength'   => (float)($config['priority_bonus_strength'] ?? 0.1),
            ],
            'sources_used'          => $sourcesUsed,
            'computed_at'           => $ts,
            'trade_count_total'     => (int)$tradeCountTotal,
            'threshold_sensitivity' => $sensitivity,
        ];
    }

    // =========================================================================
    // Two-pool lifecycle
    // =========================================================================

    /**
     * Compute promotion/demotion events and update the win pool state.
     *
     * Promotion rules (general → win):
     *   - Symbol is currently qualified AND is NOT already in the win pool.
     *
     * Demotion rules (win → general, any one triggers):
     *   1. Symbol is no longer qualified in the current window.
     *   2. qualification_expiry_days > 0 AND the promotion timestamp is older
     *      than expiry days AND symbol is not currently qualified.
     *   3. demotion_loss_streak > 0 AND the symbol's last N trades within the
     *      lookback window are all losses (roi < min_roi_threshold).
     *
     * @param array<string,array<string,mixed>> $results      Current qualification results
     * @param array<string,array<string,mixed>> $prevPool     Previous win pool state
     * @param string  $ts            ISO timestamp for this run
     * @param int     $expiryDays   Days before expiry demotion triggers (0 = off)
     * @param int     $demotionStreak Consecutive losses to trigger demotion (0 = off)
     * @param float   $minRoi       ROI threshold (defines a "loss")
     * @param array<int,array<string,mixed>> $rawTrades All raw trades (for streak check)
     * @param int     $cutoff       Unix timestamp for lookback window start
     * @return array{0: array<string,array<string,mixed>>, 1: list<array<string,mixed>>, 2: list<array<string,mixed>>}
     */
    private function computeLifecycle(
        array $results,
        array $prevPool,
        string $ts,
        int $expiryDays,
        int $demotionStreak,
        float $minRoi,
        array $rawTrades,
        int $cutoff
    ): array {
        $newPool    = $prevPool;
        $promotions = [];
        $demotions  = [];
        $nowTs      = time();

        // ── Promotions ─────────────────────────────────────────────────────────
        foreach ($results as $sym => $rec) {
            if (!$rec['qualified']) {
                continue;
            }
            if (isset($newPool[$sym])) {
                // Already in pool — update last_revalidated_at
                $newPool[$sym]['last_revalidated_at'] = $ts;
                $newPool[$sym]['last_avg_roi']         = $rec['recent_avg_roi'];
                continue;
            }
            // Promote
            $reason = sprintf(
                'квалифицирован: %d сделок в окне, avg ROI %.2f%%, winrate %.1f%%',
                (int)($rec['closed_trades_window'] ?? 0),
                (float)($rec['recent_avg_roi'] ?? 0),
                (float)($rec['recent_winrate'] ?? 0) * 100
            );
            $newPool[$sym] = [
                'symbol'              => $sym,
                'promoted_at'         => $ts,
                'last_revalidated_at' => $ts,
                'promotion_reason'    => $reason,
                'last_avg_roi'        => $rec['recent_avg_roi'],
            ];
            $promotions[] = [
                'symbol'    => $sym,
                'event'     => 'promoted',
                'timestamp' => $ts,
                'reason'    => $reason,
                'avg_roi'   => $rec['recent_avg_roi'],
            ];
        }

        // ── Demotions ──────────────────────────────────────────────────────────
        foreach (array_keys($newPool) as $sym) {
            $rec         = $results[$sym] ?? null;
            $poolEntry   = $newPool[$sym];
            $demoteWhy   = null;

            // Rule 1: no longer qualified
            if ($rec === null || !$rec['qualified']) {
                $demoteWhy = $rec !== null
                    ? ('не квалифицирован: ' . ($rec['qualification_reason'] ?? 'критерии не выполнены'))
                    : 'символ не найден в текущем расчёте';
            }

            // Rule 2: expiry
            if ($demoteWhy === null && $expiryDays > 0) {
                $promotedTs = strtotime((string)($poolEntry['promoted_at'] ?? ''));
                if ($promotedTs !== false && $promotedTs > 0) {
                    $ageSeconds = $nowTs - $promotedTs;
                    if ($ageSeconds > ($expiryDays * 86400)) {
                        $demoteWhy = sprintf(
                            'истёк срок квалификации: в пуле %d дн. (лимит %d дн.)',
                            (int)($ageSeconds / 86400),
                            $expiryDays
                        );
                    }
                }
            }

            // Rule 3: loss streak
            if ($demoteWhy === null && $demotionStreak > 0 && $rec !== null) {
                $streak = $this->computeLossStreak($sym, $rawTrades, $cutoff, $minRoi);
                if ($streak >= $demotionStreak) {
                    $demoteWhy = sprintf(
                        '%d убыточных сделок подряд (порог: %d)',
                        $streak,
                        $demotionStreak
                    );
                }
            }

            if ($demoteWhy !== null) {
                $demotions[] = [
                    'symbol'             => $sym,
                    'event'              => 'demoted',
                    'timestamp'          => $ts,
                    'reason'             => $demoteWhy,
                    'was_in_pool_since'  => $poolEntry['promoted_at'] ?? null,
                    'last_avg_roi'       => $poolEntry['last_avg_roi'] ?? null,
                ];
                unset($newPool[$sym]);
            }
        }

        return [$newPool, $promotions, $demotions];
    }

    /**
     * Compute the trailing loss streak for a symbol within the lookback window.
     * Trades are sorted most-recent-first; streak ends when a winning trade is found.
     */
    private function computeLossStreak(string $sym, array $rawTrades, int $cutoff, float $minRoi): int
    {
        $windowTrades = [];
        foreach ($rawTrades as $trade) {
            if (strtoupper((string)($trade['symbol'] ?? '')) !== $sym) {
                continue;
            }
            $closedAt = (int)($trade['closed_at'] ?? 0);
            if ($closedAt < $cutoff) {
                continue;
            }
            $roi = is_numeric($trade['roi'] ?? null) ? (float)$trade['roi'] : null;
            $windowTrades[] = ['closed_at' => $closedAt, 'roi' => $roi];
        }

        if (empty($windowTrades)) {
            return 0;
        }

        // Sort most-recent-first
        usort($windowTrades, static function (array $a, array $b): int {
            return $b['closed_at'] <=> $a['closed_at'];
        });

        $streak = 0;
        foreach ($windowTrades as $t) {
            $roi = $t['roi'];
            if ($roi === null || $roi < $minRoi) {
                $streak++;
            } else {
                break;
            }
        }

        return $streak;
    }

    // =========================================================================
    // Data loading
    // =========================================================================

    /**
     * Load all closed trades from available sources.
     *
     * Sources tried (in order):
     *   1. Trading Bot trades/closed_trades.json (aggregated snapshot)
     *   2. Trading Bot trades/closed/*.json (individual per-trade files)
     *   3. Smart Brain simulator/closed.json
     *
     * @return array{0: array<int,array<string,mixed>>, 1: string[]}
     */
    private function loadAllClosedTrades(): array
    {
        $trades      = [];
        $sourcesUsed = [];

        // ── Source 1 & 2: Trading Bot closed trades ───────────────────────────
        try {
            $botStorageDir = $this->resolveBotStorageDir();
            if ($botStorageDir !== null) {
                $raw = [];

                // Primary: aggregated snapshot
                $aggregatedPath = $botStorageDir . '/trades/closed_trades.json';
                if (is_file($aggregatedPath)) {
                    $content = @file_get_contents($aggregatedPath);
                    if ($content !== false && $content !== '') {
                        $decoded = json_decode($content, true);
                        if (is_array($decoded) && !empty($decoded)) {
                            $raw           = $decoded;
                            $sourcesUsed[] = 'bot_closed_trades_aggregated';
                        }
                    }
                }

                // Secondary: individual per-trade files
                if (empty($raw)) {
                    $closedDir = $botStorageDir . '/trades/closed';
                    if (is_dir($closedDir)) {
                        foreach (glob($closedDir . '/*.json') ?: [] as $path) {
                            $content = @file_get_contents($path);
                            if ($content === false || $content === '') {
                                continue;
                            }
                            $trade = json_decode($content, true);
                            if (is_array($trade) && !empty($trade)) {
                                $raw[] = $trade;
                            }
                        }
                        if (!empty($raw)) {
                            $sourcesUsed[] = 'bot_closed_trades_individual';
                        }
                    }
                }

                foreach ($raw as $trade) {
                    $normalised = $this->normaliseBotTrade($trade, 'bot_closed_trades');
                    if ($normalised !== null) {
                        $trades[] = $normalised;
                    }
                }
            }
        } catch (\Throwable $e) {
            // non-fatal
        }

        // ── Source 3: Smart Brain simulator closed trades ─────────────────────
        try {
            $simPath = $this->brainModuleBase . '/storage/simulator/closed.json';
            if (is_file($simPath)) {
                $content = @file_get_contents($simPath);
                if ($content !== false && $content !== '') {
                    $decoded = json_decode($content, true);
                    if (is_array($decoded) && !empty($decoded)) {
                        $added = 0;
                        foreach ($decoded as $trade) {
                            $normalised = $this->normaliseSimulatorTrade($trade);
                            if ($normalised !== null) {
                                $trades[] = $normalised;
                                $added++;
                            }
                        }
                        if ($added > 0) {
                            $sourcesUsed[] = 'simulator_closed';
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // non-fatal
        }

        return [$trades, $sourcesUsed];
    }

    /**
     * Normalise a Trading Bot closed trade record.
     *
     * @param array<string,mixed> $trade
     * @return array<string,mixed>|null  null if record is unusable
     */
    private function normaliseBotTrade(array $trade, string $source): ?array
    {
        $sym = (string)($trade['symbol'] ?? '');
        if ($sym === '') {
            return null;
        }

        // Require a closed timestamp
        if (!isset($trade['closed_at']) && !isset($trade['closed_ts'])) {
            return null;
        }

        if (isset($trade['closed_ts']) && is_numeric($trade['closed_ts'])) {
            $closedAt = (int)$trade['closed_ts'];
        } elseif (isset($trade['closed_at'])) {
            $closedAt = is_numeric($trade['closed_at'])
                ? (int)$trade['closed_at']
                : (int)strtotime((string)$trade['closed_at']);
        } else {
            $closedAt = 0;
        }

        if ($closedAt <= 0) {
            return null;
        }

        $roi = isset($trade['roi']) && is_numeric($trade['roi']) ? (float)$trade['roi'] : null;

        return [
            'symbol'    => strtoupper($sym),
            'roi'       => $roi,
            'closed_at' => $closedAt,
            'source'    => $source,
        ];
    }

    /**
     * Normalise a Smart Brain simulator closed trade record.
     *
     * @param array<string,mixed> $trade
     * @return array<string,mixed>|null
     */
    private function normaliseSimulatorTrade(array $trade): ?array
    {
        if (!is_array($trade)) {
            return null;
        }
        if (($trade['status'] ?? '') !== 'closed') {
            return null;
        }
        $sym = (string)($trade['symbol'] ?? '');
        if ($sym === '') {
            return null;
        }

        $closedAtRaw = $trade['closed_at'] ?? null;
        if ($closedAtRaw === null) {
            return null;
        }
        $closedAt = is_numeric($closedAtRaw)
            ? (int)$closedAtRaw
            : (int)strtotime((string)$closedAtRaw);
        if ($closedAt <= 0) {
            return null;
        }

        $roi = isset($trade['roi']) && is_numeric($trade['roi']) ? (float)$trade['roi'] : null;

        return [
            'symbol'    => strtoupper($sym),
            'roi'       => $roi,
            'closed_at' => $closedAt,
            'source'    => 'simulator',
        ];
    }

    // =========================================================================
    // Statistics aggregation
    // =========================================================================

    /**
     * Aggregate closed trades into per-symbol statistics within the lookback window.
     *
     * @param array<int,array<string,mixed>> $trades
     * @param int   $cutoff  Unix timestamp; trades older than this are excluded
     * @param float $minRoi  ROI threshold used to count "wins above threshold"
     * @return array<string,array<string,mixed>>
     */
    private function aggregateBySymbol(array $trades, int $cutoff, float $minRoi): array
    {
        $bySymbol = [];

        foreach ($trades as $trade) {
            $sym      = (string)($trade['symbol'] ?? '');
            $closedAt = (int)($trade['closed_at'] ?? 0);
            $roi      = $trade['roi'];  // float|null

            if ($sym === '' || $closedAt <= 0) {
                continue;
            }

            // Count all-time trades (before cutoff filter)
            if (!isset($bySymbol[$sym])) {
                $bySymbol[$sym] = [
                    'closed_trades_count'        => 0,
                    'closed_trades_count_window' => 0,
                    'wins_count'                 => 0,
                    'losses_count'               => 0,
                    'wins_above_threshold'        => 0,
                    'roi_sum'                    => 0.0,
                    'roi_values'                 => [],
                    'best_roi'                   => null,
                    'last_trade_time'            => 0,
                ];
            }

            $bySymbol[$sym]['closed_trades_count']++;

            // Only include in window stats if within lookback
            if ($closedAt < $cutoff) {
                continue;
            }

            $bySymbol[$sym]['closed_trades_count_window']++;

            if ($closedAt > $bySymbol[$sym]['last_trade_time']) {
                $bySymbol[$sym]['last_trade_time'] = $closedAt;
            }

            if ($roi !== null) {
                $bySymbol[$sym]['roi_sum']   += $roi;
                $bySymbol[$sym]['roi_values'][] = $roi;

                if ($bySymbol[$sym]['best_roi'] === null || $roi > $bySymbol[$sym]['best_roi']) {
                    $bySymbol[$sym]['best_roi'] = $roi;
                }

                if ($roi >= $minRoi) {
                    $bySymbol[$sym]['wins_count']++;
                    $bySymbol[$sym]['wins_above_threshold']++;
                } else {
                    $bySymbol[$sym]['losses_count']++;
                }
            }
        }

        // Compute derived fields
        $result = [];
        foreach ($bySymbol as $sym => $raw) {
            $windowCount = $raw['closed_trades_count_window'];
            $roiValues   = $raw['roi_values'];
            $recentAvgRoi = $windowCount > 0 && !empty($roiValues)
                ? round($raw['roi_sum'] / count($roiValues), 4)
                : null;

            $recentWinrate = $windowCount > 0
                ? round($raw['wins_count'] / $windowCount, 4)
                : null;

            $lastTradeTime = $raw['last_trade_time'] > 0
                ? date('c', $raw['last_trade_time'])
                : null;

            $result[$sym] = [
                'symbol'                     => $sym,
                'closed_trades_count'        => $raw['closed_trades_count'],
                'closed_trades_count_window' => $windowCount,
                'wins_count'                 => $raw['wins_count'],
                'losses_count'               => $raw['losses_count'],
                'wins_above_threshold'       => $raw['wins_above_threshold'],
                'recent_avg_roi'             => $recentAvgRoi,
                'best_roi'                   => $raw['best_roi'],
                'recent_winrate'             => $recentWinrate,
                'last_trade_time'            => $lastTradeTime,
            ];
        }

        return $result;
    }

    // =========================================================================
    // Qualification engine
    // =========================================================================

    /**
     * Determine whether a symbol qualifies for the Win Universe.
     *
     * Qualification criteria (all must pass):
     *   1. min_closed_trades : symbol must have >= min_closed_trades in the window
     *   2. min_roi_threshold : recent_avg_roi must be >= min_roi_threshold
     *   3. min_avg_roi       : recent_avg_roi must be >= min_avg_roi (0 = gate off)
     *   4. min_winrate       : recent_winrate must be >= min_winrate  (0 = gate off)
     *
     * Near-qualified: meets trade count but fails exactly one of the soft criteria.
     *
     * @param array<string,mixed> $stats        Aggregated stats for the symbol
     * @param float               $minRoi       Per-trade win threshold (defines what counts as a win)
     * @param float               $minAvgRoi    Minimum required average ROI (0 = off)
     * @param int                 $minTrades    Min closed trades in window
     * @param float               $minWinrate   Min win-rate (0 = disabled)
     * @param int                 $lookbackDays
     * @return array<string,mixed>
     */
    private function qualify(
        string $symbol,
        array $stats,
        float $minRoi,
        float $minAvgRoi,
        int $minTrades,
        float $minWinrate,
        int $lookbackDays
    ): array {
        $windowCount   = (int)($stats['closed_trades_count_window'] ?? 0);
        $recentAvgRoi  = $stats['recent_avg_roi'];
        $recentWinrate = $stats['recent_winrate'];
        $winsAbove     = (int)($stats['wins_above_threshold'] ?? 0);

        // Gate 1: sufficient trade count (hard gate — must pass to be near-qualified)
        $meetsTradeCount = $windowCount >= $minTrades;

        // Gate 2: average ROI >= per-trade win threshold (main quality gate)
        $meetsRoi = ($recentAvgRoi !== null) && ($recentAvgRoi >= $minRoi);

        // Gate 3: average ROI >= separate min_avg_roi threshold (0 = gate off)
        $avgRoiGateEnabled = $minAvgRoi > 0.0;
        $meetsAvgRoi       = !$avgRoiGateEnabled || (($recentAvgRoi !== null) && ($recentAvgRoi >= $minAvgRoi));

        // Gate 4: win-rate (optional gate, only active if minWinrate > 0)
        $winrateGateEnabled = $minWinrate > 0.0;
        $meetsWinrate       = !$winrateGateEnabled || (($recentWinrate !== null) && ($recentWinrate >= $minWinrate));

        $qualified = $meetsTradeCount && $meetsRoi && $meetsAvgRoi && $meetsWinrate;

        // Near-qualified: meets trade count but fails exactly one soft criterion
        $softFailCount = 0;
        if (!$meetsRoi) {
            $softFailCount++;
        }
        if ($avgRoiGateEnabled && !$meetsAvgRoi) {
            $softFailCount++;
        }
        if ($winrateGateEnabled && !$meetsWinrate) {
            $softFailCount++;
        }
        $nearQualified = !$qualified && $meetsTradeCount && $softFailCount === 1;

        // Build missing_requirements list
        $missing = [];
        if (!$meetsTradeCount) {
            $missing[] = sprintf(
                'min_closed_trades: нужно %d, есть %d в окне %dд.',
                $minTrades,
                $windowCount,
                $lookbackDays
            );
        }
        if (!$meetsRoi) {
            $roiDisplay = $recentAvgRoi !== null ? number_format($recentAvgRoi, 2) . '%' : 'н/д';
            $missing[] = sprintf(
                'min_roi_threshold: нужно >= %.2f%%, есть %s',
                $minRoi,
                $roiDisplay
            );
        }
        if ($avgRoiGateEnabled && !$meetsAvgRoi) {
            $roiDisplay = $recentAvgRoi !== null ? number_format($recentAvgRoi, 2) . '%' : 'н/д';
            $missing[] = sprintf(
                'min_avg_roi: нужно >= %.2f%%, есть %s',
                $minAvgRoi,
                $roiDisplay
            );
        }
        if ($winrateGateEnabled && !$meetsWinrate) {
            $wrDisplay = $recentWinrate !== null ? number_format($recentWinrate * 100, 1) . '%' : 'н/д';
            $missing[] = sprintf(
                'min_winrate: нужно >= %.1f%%, есть %s',
                $minWinrate * 100,
                $wrDisplay
            );
        }

        // Build reason string (Russian)
        if ($qualified) {
            $reason = 'квалифицирован: все критерии выполнены';
            $status = 'qualified';
        } elseif ($nearQualified) {
            $reason = 'почти квалифицирован: ' . implode('; ', $missing);
            $status = 'near_qualified';
        } elseif (!$meetsTradeCount) {
            $reason = sprintf(
                'недостаточно сделок: %d из %d в окне %dд.',
                $windowCount,
                $minTrades,
                $lookbackDays
            );
            $status = 'rejected';
        } else {
            $reason = 'отклонён: ' . implode('; ', $missing);
            $status = 'rejected';
        }

        return [
            'symbol'                  => $symbol,
            'qualification_status'    => $status,
            'qualified'               => $qualified,
            'near_qualified'          => $nearQualified,
            'qualification_reason'    => $reason,
            'missing_requirements'    => $missing,
            'wins_above_threshold'    => $winsAbove,
            'recent_trade_count'      => $windowCount,
            'closed_trades_count'     => (int)($stats['closed_trades_count'] ?? 0),
            'closed_trades_window'    => $windowCount,
            'recent_avg_roi'          => $recentAvgRoi,
            'best_roi'                => $stats['best_roi'],
            'recent_winrate'          => $recentWinrate,
            'last_trade_time'         => $stats['last_trade_time'],
            'lookback_window_used'    => $lookbackDays,
            'thresholds_used'         => [
                'min_roi_threshold'   => $minRoi,
                'min_avg_roi'         => $minAvgRoi,
                'min_winrate'         => $minWinrate,
                'min_closed_trades'   => $minTrades,
            ],
            // legacy flat fields (kept for backwards compatibility)
            'threshold_used'          => $minRoi,
            'winrate_threshold_used'  => $minWinrate,
            'min_trades_required'     => $minTrades,
        ];
    }

    // =========================================================================
    // Threshold sensitivity breakdown
    // =========================================================================

    /**
     * Build a summary of how many symbols fail by each individual criterion.
     *
     * Useful for diagnosing why qualified_count may be zero.
     *
     * @param array<string,array<string,mixed>> $results
     */
    private function buildSensitivityBreakdown(
        array $results,
        int $minTrades,
        float $minRoi,
        float $minAvgRoi,
        float $minWinrate
    ): array {
        $failByTradeCount = 0;
        $failByRoi        = 0;
        $failByAvgRoi     = 0;
        $failByWinrate    = 0;
        $failByTradeCountOnly = 0;
        $failByRoiOnly        = 0;
        $failByAvgRoiOnly     = 0;
        $failByWinrateOnly    = 0;
        $totalRejected    = 0;
        $totalNear        = 0;

        foreach ($results as $rec) {
            if ($rec['qualified']) {
                continue;
            }

            $windowCount   = (int)($rec['recent_trade_count'] ?? 0);
            $recentAvgRoi  = $rec['recent_avg_roi'];
            $recentWinrate = $rec['recent_winrate'];

            $failTC  = $windowCount < $minTrades;
            $failRoi = !($recentAvgRoi !== null && $recentAvgRoi >= $minRoi);
            $failAvg = $minAvgRoi > 0.0 && !($recentAvgRoi !== null && $recentAvgRoi >= $minAvgRoi);
            $failWr  = $minWinrate > 0.0 && !($recentWinrate !== null && $recentWinrate >= $minWinrate);

            if ($failTC) {
                $failByTradeCount++;
            }
            if ($failRoi) {
                $failByRoi++;
            }
            if ($failAvg) {
                $failByAvgRoi++;
            }
            if ($failWr) {
                $failByWinrate++;
            }

            // "only" counts: fails exactly that criterion (and meets all others)
            if ($failTC && !$failRoi && !$failAvg && !$failWr) {
                $failByTradeCountOnly++;
            }
            if (!$failTC && $failRoi && !$failAvg && !$failWr) {
                $failByRoiOnly++;
            }
            if (!$failTC && !$failRoi && $failAvg && !$failWr) {
                $failByAvgRoiOnly++;
            }
            if (!$failTC && !$failRoi && !$failAvg && $failWr) {
                $failByWinrateOnly++;
            }

            if ($rec['near_qualified']) {
                $totalNear++;
            } else {
                $totalRejected++;
            }
        }

        return [
            'fail_by_trade_count'      => $failByTradeCount,
            'fail_by_roi_threshold'    => $failByRoi,
            'fail_by_avg_roi'          => $failByAvgRoi,
            'fail_by_winrate'          => $failByWinrate,
            'fail_by_trade_count_only' => $failByTradeCountOnly,
            'fail_by_roi_only'         => $failByRoiOnly,
            'fail_by_avg_roi_only'     => $failByAvgRoiOnly,
            'fail_by_winrate_only'     => $failByWinrateOnly,
            'near_qualified_total'     => $totalNear,
            'rejected_total'           => $totalRejected,
        ];
    }

    // =========================================================================
    // Bot storage resolution (read-only, mirrors profit_manager approach)
    // =========================================================================

    /**
     * Resolve the Trading Bot's mode-specific storage directory.
     *
     * Tries storage_live, storage_demo, storage_paper, then legacy storage.
     * Reads bot.json to determine the preferred mode.
     *
     * @return string|null
     */
    private function resolveBotStorageDir(): ?string
    {
        $base = $this->botModuleBase;
        if (!is_dir($base)) {
            return null;
        }

        $botMode = $this->readBotMode($base);
        $ordered = $this->botStorageSuffixOrder($botMode);

        foreach ($ordered as $suffix) {
            $sd = $base . '/' . $suffix;
            if (is_dir($sd . '/trades/active')) {
                return $sd;
            }
        }
        foreach ($ordered as $suffix) {
            $sd = $base . '/' . $suffix;
            if (is_dir($sd)) {
                return $sd;
            }
        }

        return null;
    }

    /**
     * Read bot mode from bot.json (defaults to 'demo').
     */
    private function readBotMode(string $botBase): string
    {
        try {
            $path = $botBase . '/config/bot.json';
            if (!is_file($path)) {
                return 'demo';
            }
            $json = @file_get_contents($path);
            if ($json === false || $json === '') {
                return 'demo';
            }
            $data = json_decode($json, true);
            if (!is_array($data)) {
                return 'demo';
            }
            $mode = (string)($data['module']['mode'] ?? $data['mode'] ?? 'demo');
            return $mode !== '' ? $mode : 'demo';
        } catch (\Throwable $e) {
            return 'demo';
        }
    }

    /**
     * Return ordered list of storage directory suffixes to try.
     *
     * @return string[]
     */
    private function botStorageSuffixOrder(string $botMode): array
    {
        $modeMap   = ['live' => 'storage_live', 'demo' => 'storage_demo', 'paper' => 'storage_paper'];
        $preferred = $modeMap[$botMode] ?? 'storage_demo';
        $all       = [$preferred];
        foreach ($modeMap as $suffix) {
            if ($suffix !== $preferred) {
                $all[] = $suffix;
            }
        }
        $all[] = 'storage';
        return $all;
    }
}
