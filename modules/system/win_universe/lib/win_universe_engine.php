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
     * Compute per-symbol statistics and qualification results.
     *
     * @param array<string,mixed> $config  Merged module config (win_universe block)
     * @return array{
     *   symbols: array<string,array<string,mixed>>,
     *   qualified: string[],
     *   near_qualified: string[],
     *   rejected: string[],
     *   config_used: array<string,mixed>,
     *   sources_used: string[],
     *   computed_at: string,
     *   trade_count_total: int,
     * }
     */
    public function compute(array $config): array
    {
        $ts = date('c');

        $minRoi        = (float)($config['min_roi_threshold'] ?? 1.5);
        $lookbackDays  = max(1, (int)($config['lookback_days']    ?? 30));
        $minTrades     = max(1, (int)($config['min_closed_trades'] ?? 3));
        $minWinrate    = (float)($config['min_winrate'] ?? 0.0);

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
            $rec = $this->qualify($symbol, $stats, $minRoi, $minTrades, $minWinrate, $lookbackDays);
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

        return [
            'symbols'           => $results,
            'qualified'         => $qualified,
            'near_qualified'    => $nearQualified,
            'rejected'          => $rejected,
            'config_used'       => [
                'min_roi_threshold'  => $minRoi,
                'lookback_days'      => $lookbackDays,
                'min_closed_trades'  => $minTrades,
                'min_winrate'        => $minWinrate,
            ],
            'sources_used'      => $sourcesUsed,
            'computed_at'       => $ts,
            'trade_count_total' => (int)$tradeCountTotal,
        ];
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
     *   1. min_closed_trades: symbol must have >= min_closed_trades in the window
     *   2. min_roi_threshold: recent_avg_roi must be >= min_roi_threshold
     *   3. min_winrate (if > 0): recent_winrate must be >= min_winrate
     *
     * Near-qualified: fails one soft criterion (roi or winrate) but meets trade count.
     *
     * @param array<string,mixed> $stats       Aggregated stats for the symbol
     * @param float               $minRoi      Min average ROI threshold
     * @param int                 $minTrades   Min closed trades in window
     * @param float               $minWinrate  Min win-rate (0 = disabled)
     * @param int                 $lookbackDays
     * @return array<string,mixed>
     */
    private function qualify(
        string $symbol,
        array $stats,
        float $minRoi,
        int $minTrades,
        float $minWinrate,
        int $lookbackDays
    ): array {
        $windowCount   = (int)($stats['closed_trades_count_window'] ?? 0);
        $recentAvgRoi  = $stats['recent_avg_roi'];
        $recentWinrate = $stats['recent_winrate'];
        $winsAbove     = (int)($stats['wins_above_threshold'] ?? 0);

        // Gate 1: sufficient trade count
        $meetsTradeCount = $windowCount >= $minTrades;

        // Gate 2: average ROI
        $meetsRoi = ($recentAvgRoi !== null) && ($recentAvgRoi >= $minRoi);

        // Gate 3: win-rate (optional gate, only active if minWinrate > 0)
        $winrateGateEnabled = $minWinrate > 0.0;
        $meetsWinrate       = !$winrateGateEnabled || (($recentWinrate !== null) && ($recentWinrate >= $minWinrate));

        $qualified = $meetsTradeCount && $meetsRoi && $meetsWinrate;

        // Near-qualified: meets trade count but fails at most one of roi/winrate
        $failCount = 0;
        if (!$meetsRoi) {
            $failCount++;
        }
        if ($winrateGateEnabled && !$meetsWinrate) {
            $failCount++;
        }
        $nearQualified = !$qualified && $meetsTradeCount && $failCount === 1;

        // Build reason string
        if ($qualified) {
            $reason = 'qualified';
        } elseif (!$meetsTradeCount) {
            $reason = sprintf(
                'insufficient_trades: %d/%d в окне %d дн.',
                $windowCount,
                $minTrades,
                $lookbackDays
            );
        } elseif (!$meetsRoi) {
            $roiDisplay = $recentAvgRoi !== null ? number_format($recentAvgRoi, 2) . '%' : 'n/a';
            $reason = sprintf(
                'avg_roi_below_threshold: %s < %.2f%%',
                $roiDisplay,
                $minRoi
            );
        } elseif (!$meetsWinrate) {
            $wrDisplay = $recentWinrate !== null ? number_format($recentWinrate * 100, 1) . '%' : 'n/a';
            $reason = sprintf(
                'winrate_below_threshold: %s < %.1f%%',
                $wrDisplay,
                $minWinrate * 100
            );
        } else {
            $reason = 'not_qualified';
        }

        return [
            'symbol'              => $symbol,
            'qualified'           => $qualified,
            'near_qualified'      => $nearQualified,
            'qualification_reason'=> $reason,
            'wins_above_threshold'=> $winsAbove,
            'closed_trades_count' => (int)($stats['closed_trades_count'] ?? 0),
            'closed_trades_window'=> $windowCount,
            'recent_avg_roi'      => $recentAvgRoi,
            'best_roi'            => $stats['best_roi'],
            'recent_winrate'      => $recentWinrate,
            'last_trade_time'     => $stats['last_trade_time'],
            'lookback_window_used'=> $lookbackDays,
            'threshold_used'      => $minRoi,
            'winrate_threshold_used' => $minWinrate,
            'min_trades_required' => $minTrades,
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
