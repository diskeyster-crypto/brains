<?php
declare(strict_types=1);

/**
 * Symbol Intelligence Layer V2 — Smart Brain
 *
 * Analyzes closed simulator trades per symbol and classifies symbols into:
 *   whitelist       — good performing symbols (hard whitelist)
 *   soft_whitelist  — promising symbols (not yet meeting hard whitelist thresholds)
 *   blacklist       — bad performing symbols (enough evidence)
 *   watchlist       — uncertain / not enough data
 *   unknown         — no history at all
 *
 * Classification order:
 *   1. Check if symbol has zero trades → unknown
 *   2. Check blacklist conditions (enough trades + low winrate or high early failure)
 *   3. Check hard whitelist conditions (enough trades + good winrate + positive ROI)
 *   4. Check soft whitelist conditions (fewer trades + promising winrate + positive ROI)
 *   5. Everything else → watchlist
 *
 * Symbol score formula:
 *   score = (winrate * 0.35) + (clamp(avg_roi, -0.1, 0.1) / 0.1 * 0.25)
 *         + (recent_winrate * 0.20) + (clamp(recent_avg_roi, -0.1, 0.1) / 0.1 * 0.10)
 *         - (early_failure_ratio * 0.10)
 *
 * Writes:
 *   storage/symbol_stats.json
 *   storage/whitelist.json
 *   storage/soft_whitelist.json
 *   storage/blacklist.json
 *   storage/watchlist.json
 */
final class SymbolIntelligence
{
    private StateManager $state;

    // Classification thresholds — user-configurable, with conservative defaults
    private int   $whitelistMinTrades;
    private float $whitelistMinWinrate;
    private float $whitelistMinAvgRoi;

    private int   $blacklistMinTrades;
    private float $blacklistMaxWinrate;
    private float $blacklistMaxEarlyFailureRatio;

    private bool  $softWhitelistEnabled;
    private int   $softWhitelistMinTrades;
    private float $softWhitelistMinWinrate;
    private float $softWhitelistMinAvgRoi;

    private int   $recentWindow;

    /**
     * @param StateManager         $state
     * @param array<string,mixed>  $thresholds  User-configurable thresholds from user config
     */
    public function __construct(StateManager $state, array $thresholds = [])
    {
        $this->state = $state;

        // Hard whitelist thresholds
        $this->whitelistMinTrades  = max(1, (int)($thresholds['whitelist_min_trades'] ?? 5));
        $this->whitelistMinWinrate = (float)($thresholds['whitelist_min_winrate'] ?? 0.50);
        $this->whitelistMinAvgRoi  = (float)($thresholds['whitelist_min_avg_roi'] ?? 0.0);

        // Blacklist thresholds
        $this->blacklistMinTrades            = max(1, (int)($thresholds['blacklist_min_trades'] ?? 3));
        $this->blacklistMaxWinrate           = (float)($thresholds['blacklist_max_winrate'] ?? 0.30);
        $this->blacklistMaxEarlyFailureRatio = (float)($thresholds['blacklist_max_early_failure_ratio'] ?? 0.60);

        // Soft whitelist thresholds
        $this->softWhitelistEnabled   = (bool)($thresholds['soft_whitelist_enabled'] ?? true);
        $this->softWhitelistMinTrades = max(1, (int)($thresholds['soft_whitelist_min_trades'] ?? 1));
        $this->softWhitelistMinWinrate = (float)($thresholds['soft_whitelist_min_winrate'] ?? 0.50);
        $this->softWhitelistMinAvgRoi  = (float)($thresholds['soft_whitelist_min_avg_roi'] ?? 0.005);

        // Recent performance window
        $this->recentWindow = max(1, (int)($thresholds['symbol_recent_window'] ?? 5));
    }

    /**
     * Rebuild all symbol intelligence data from closed trades.
     *
     * @return array{symbol_stats:array<string,mixed>,whitelist:list<string>,soft_whitelist:list<string>,blacklist:list<string>,watchlist:list<string>}
     */
    public function rebuild(): array
    {
        $closed = $this->state->readJson('storage/simulator/closed.json', []);

        // Aggregate per-symbol raw data + collect per-symbol trades for recent window
        $raw = [];
        /** @var array<string,list<array<string,mixed>>> $perSymbolTrades */
        $perSymbolTrades = [];

        foreach ($closed as $trade) {
            $symbol = (string)($trade['symbol'] ?? '');
            if ($symbol === '') {
                continue;
            }

            if (!isset($raw[$symbol])) {
                $raw[$symbol] = [
                    'trades_total' => 0,
                    'wins' => 0,
                    'losses' => 0,
                    'roi_sum' => 0.0,
                    'mae_sum' => 0.0,
                    'mfe_sum' => 0.0,
                    'duration_sum' => 0.0,
                    'long_count' => 0,
                    'short_count' => 0,
                    'stop_loss_count' => 0,
                    'early_failure_count' => 0,
                    'trailing_stop_count' => 0,
                    'break_even_stop_count' => 0,
                    'take_profit_count' => 0,
                    'patterns' => [],
                    'last_trade_at' => '',
                    'last_roi' => 0.0,
                ];
                $perSymbolTrades[$symbol] = [];
            }

            $r = &$raw[$symbol];
            $tradeRoi = (float)($trade['roi'] ?? 0.0);

            $r['trades_total']++;
            if ($tradeRoi >= 0) {
                $r['wins']++;
            } else {
                $r['losses']++;
            }

            $r['roi_sum'] += $tradeRoi;
            $r['mae_sum'] += (float)($trade['mae'] ?? 0.0);
            $r['mfe_sum'] += (float)($trade['mfe'] ?? 0.0);
            $r['duration_sum'] += (float)($trade['duration'] ?? 0.0);

            $side = (string)($trade['side'] ?? '');
            if ($side === 'long') {
                $r['long_count']++;
            }
            if ($side === 'short') {
                $r['short_count']++;
            }

            $reason = (string)($trade['reason'] ?? '');
            match ($reason) {
                'stop_loss' => $r['stop_loss_count']++,
                'early_failure' => $r['early_failure_count']++,
                'trailing_stop' => $r['trailing_stop_count']++,
                'break_even_stop' => $r['break_even_stop_count']++,
                'take_profit' => $r['take_profit_count']++,
                default => null,
            };

            // Pattern distribution
            $pattern = (string)($trade['pattern_algorithm'] ?? 'none');
            $r['patterns'][$pattern] = ($r['patterns'][$pattern] ?? 0) + 1;

            // Last trade tracking
            $closedAt = (string)($trade['closed_at'] ?? '');
            if ($closedAt !== '' && ($r['last_trade_at'] === '' || $closedAt > $r['last_trade_at'])) {
                $r['last_trade_at'] = $closedAt;
                $r['last_roi'] = $tradeRoi;
            }

            // Collect trade for recent window calculation
            $perSymbolTrades[$symbol][] = $trade;

            unset($r);
        }

        // Compute final stats and classify
        $symbolStats = [];
        $whitelist = [];
        $softWhitelist = [];
        $blacklist = [];
        $watchlist = [];

        foreach ($raw as $symbol => $r) {
            $t = $r['trades_total'];
            $winrate = $t > 0 ? round($r['wins'] / $t, 4) : 0.0;
            $avgRoi = $t > 0 ? round($r['roi_sum'] / $t, 6) : 0.0;
            $avgMae = $t > 0 ? round($r['mae_sum'] / $t, 6) : 0.0;
            $avgMfe = $t > 0 ? round($r['mfe_sum'] / $t, 6) : 0.0;
            $avgDuration = $t > 0 ? round($r['duration_sum'] / $t, 1) : 0.0;
            $earlyFailureRatio = $t > 0 ? round($r['early_failure_count'] / $t, 4) : 0.0;

            // Recent performance (last N trades per symbol)
            $recentData = $this->computeRecentMetrics($perSymbolTrades[$symbol] ?? []);

            $status = $this->classifySymbol($t, $winrate, $avgRoi, $earlyFailureRatio);

            // Symbol score
            $score = $this->computeSymbolScore($winrate, $avgRoi, $recentData['recent_winrate'], $recentData['recent_avg_roi'], $earlyFailureRatio);

            $symbolStats[$symbol] = [
                'trades_total' => $t,
                'wins' => $r['wins'],
                'losses' => $r['losses'],
                'winrate' => $winrate,
                'average_roi' => $avgRoi,
                'average_mae' => $avgMae,
                'average_mfe' => $avgMfe,
                'average_duration' => $avgDuration,
                'long_count' => $r['long_count'],
                'short_count' => $r['short_count'],
                'stop_loss_count' => $r['stop_loss_count'],
                'early_failure_count' => $r['early_failure_count'],
                'trailing_stop_count' => $r['trailing_stop_count'],
                'break_even_stop_count' => $r['break_even_stop_count'],
                'take_profit_count' => $r['take_profit_count'],
                'pattern_distribution' => $r['patterns'],
                'last_trade_at' => $r['last_trade_at'],
                'last_roi' => $r['last_roi'],
                'status' => $status,
                'symbol_score' => $score,
                // Recent performance metrics
                'recent_trades_total' => $recentData['recent_trades_total'],
                'recent_wins' => $recentData['recent_wins'],
                'recent_losses' => $recentData['recent_losses'],
                'recent_winrate' => $recentData['recent_winrate'],
                'recent_avg_roi' => $recentData['recent_avg_roi'],
            ];

            match ($status) {
                'whitelist' => $whitelist[] = $symbol,
                'soft_whitelist' => $softWhitelist[] = $symbol,
                'blacklist' => $blacklist[] = $symbol,
                'watchlist' => $watchlist[] = $symbol,
                default => null,
            };
        }

        // Sort lists alphabetically
        sort($whitelist);
        sort($softWhitelist);
        sort($blacklist);
        sort($watchlist);

        // Write storage files
        $this->state->writeJson('storage/symbol_stats.json', [
            'symbols' => $symbolStats,
            'whitelist_count' => count($whitelist),
            'soft_whitelist_count' => count($softWhitelist),
            'blacklist_count' => count($blacklist),
            'watchlist_count' => count($watchlist),
            'total_symbols' => count($symbolStats),
            'updated_at' => date('c'),
        ]);
        $this->state->writeJson('storage/whitelist.json', $whitelist);
        $this->state->writeJson('storage/soft_whitelist.json', $softWhitelist);
        $this->state->writeJson('storage/blacklist.json', $blacklist);
        $this->state->writeJson('storage/watchlist.json', $watchlist);

        return [
            'symbol_stats' => $symbolStats,
            'whitelist' => $whitelist,
            'soft_whitelist' => $softWhitelist,
            'blacklist' => $blacklist,
            'watchlist' => $watchlist,
        ];
    }

    /**
     * Compute recent performance metrics from the last N trades for a symbol.
     *
     * @param list<array<string,mixed>> $trades  All trades for this symbol (in order from closed.json)
     * @return array{recent_trades_total:int,recent_wins:int,recent_losses:int,recent_winrate:float,recent_avg_roi:float}
     */
    private function computeRecentMetrics(array $trades): array
    {
        $recentTrades = array_slice($trades, -$this->recentWindow);
        $count = count($recentTrades);

        if ($count === 0) {
            return [
                'recent_trades_total' => 0,
                'recent_wins' => 0,
                'recent_losses' => 0,
                'recent_winrate' => 0.0,
                'recent_avg_roi' => 0.0,
            ];
        }

        $wins = 0;
        $roiSum = 0.0;
        foreach ($recentTrades as $t) {
            $roi = (float)($t['roi'] ?? 0.0);
            $roiSum += $roi;
            if ($roi >= 0) {
                $wins++;
            }
        }

        return [
            'recent_trades_total' => $count,
            'recent_wins' => $wins,
            'recent_losses' => $count - $wins,
            'recent_winrate' => round($wins / $count, 4),
            'recent_avg_roi' => round($roiSum / $count, 6),
        ];
    }

    /**
     * Compute a simple ranking score for a symbol.
     *
     * Formula:
     *   score = (winrate * 0.35)
     *         + (clamp(avg_roi, -0.1, 0.1) / 0.1 * 0.25)
     *         + (recent_winrate * 0.20)
     *         + (clamp(recent_avg_roi, -0.1, 0.1) / 0.1 * 0.10)
     *         - (early_failure_ratio * 0.10)
     *
     * Range: approximately -0.45 to +0.90
     *
     * @return float
     */
    private function computeSymbolScore(float $winrate, float $avgRoi, float $recentWinrate, float $recentAvgRoi, float $earlyFailureRatio): float
    {
        $clampRoi = max(-0.1, min(0.1, $avgRoi));
        $clampRecentRoi = max(-0.1, min(0.1, $recentAvgRoi));

        $score = ($winrate * 0.35)
            + (($clampRoi / 0.1) * 0.25)
            + ($recentWinrate * 0.20)
            + (($clampRecentRoi / 0.1) * 0.10)
            - ($earlyFailureRatio * 0.10);

        return round($score, 4);
    }

    /**
     * Classify a symbol based on its trading metrics.
     *
     * Classification order (first match wins):
     *   1. No trades → unknown
     *   2. Enough trades + low winrate → blacklist
     *   3. Enough trades + high early failure ratio → blacklist
     *   4. Enough trades + good winrate + positive ROI → whitelist (hard)
     *   5. Soft whitelist enabled + enough trades + promising metrics → soft_whitelist
     *   6. Everything else → watchlist
     *
     * @param int   $tradesTotal
     * @param float $winrate
     * @param float $avgRoi
     * @param float $earlyFailureRatio  early_failure_count / trades_total
     * @return string  'whitelist' | 'soft_whitelist' | 'blacklist' | 'watchlist' | 'unknown'
     */
    private function classifySymbol(int $tradesTotal, float $winrate, float $avgRoi, float $earlyFailureRatio): string
    {
        // 1. No trades → unknown
        if ($tradesTotal === 0) {
            return 'unknown';
        }

        // 2. Blacklist: enough trades + very low winrate
        if ($tradesTotal >= $this->blacklistMinTrades && $winrate < $this->blacklistMaxWinrate) {
            return 'blacklist';
        }

        // 3. Blacklist: enough trades + extremely high early failure ratio
        if ($tradesTotal >= $this->blacklistMinTrades && $earlyFailureRatio >= $this->blacklistMaxEarlyFailureRatio) {
            return 'blacklist';
        }

        // 4. Hard whitelist: enough trades + good winrate + positive ROI
        if ($tradesTotal >= $this->whitelistMinTrades
            && $winrate >= $this->whitelistMinWinrate
            && $avgRoi > $this->whitelistMinAvgRoi) {
            return 'whitelist';
        }

        // 5. Soft whitelist: enabled + enough trades + promising metrics
        if ($this->softWhitelistEnabled
            && $tradesTotal >= $this->softWhitelistMinTrades
            && $winrate >= $this->softWhitelistMinWinrate
            && $avgRoi > $this->softWhitelistMinAvgRoi) {
            return 'soft_whitelist';
        }

        // 6. Everything else → watchlist
        return 'watchlist';
    }

    /**
     * Load current symbol stats from storage (no rebuild).
     *
     * @return array<string,mixed>
     */
    public function getSymbolStatsData(): array
    {
        $statsFile = $this->state->readJson('storage/symbol_stats.json', []);
        return [
            'symbol_stats' => (array)($statsFile['symbols'] ?? []),
            'whitelist' => $this->state->readJson('storage/whitelist.json', []),
            'soft_whitelist' => $this->state->readJson('storage/soft_whitelist.json', []),
            'blacklist' => $this->state->readJson('storage/blacklist.json', []),
            'watchlist' => $this->state->readJson('storage/watchlist.json', []),
            'whitelist_count' => (int)($statsFile['whitelist_count'] ?? 0),
            'soft_whitelist_count' => (int)($statsFile['soft_whitelist_count'] ?? 0),
            'blacklist_count' => (int)($statsFile['blacklist_count'] ?? 0),
            'watchlist_count' => (int)($statsFile['watchlist_count'] ?? 0),
            'total_symbols' => (int)($statsFile['total_symbols'] ?? 0),
            'updated_at' => (string)($statsFile['updated_at'] ?? ''),
        ];
    }

    /**
     * Filter candidates by symbol intelligence mode.
     *
     * @param array<int,array<string,mixed>> $candidates
     * @param string $filterMode  'all' | 'whitelist_only' | 'exclude_blacklist' | 'watchlist_only' | 'soft_whitelist_only' | 'whitelist_plus_soft'
     * @return array<int,array<string,mixed>>
     */
    public function filterCandidates(array $candidates, string $filterMode): array
    {
        if ($filterMode === 'all') {
            return $candidates;
        }

        $whitelist = $this->state->readJson('storage/whitelist.json', []);
        $softWhitelist = $this->state->readJson('storage/soft_whitelist.json', []);
        $blacklist = $this->state->readJson('storage/blacklist.json', []);
        $watchlist = $this->state->readJson('storage/watchlist.json', []);

        // Convert to lookup sets for performance
        $whitelistSet = array_flip($whitelist);
        $softWhitelistSet = array_flip($softWhitelist);
        $blacklistSet = array_flip($blacklist);
        $watchlistSet = array_flip($watchlist);

        $filtered = [];
        foreach ($candidates as $c) {
            $symbol = (string)($c['symbol'] ?? '');
            if ($symbol === '') {
                continue;
            }

            $pass = match ($filterMode) {
                'whitelist_only' => isset($whitelistSet[$symbol]),
                'exclude_blacklist' => !isset($blacklistSet[$symbol]),
                'watchlist_only' => isset($watchlistSet[$symbol]),
                'soft_whitelist_only' => isset($softWhitelistSet[$symbol]),
                'whitelist_plus_soft' => isset($whitelistSet[$symbol]) || isset($softWhitelistSet[$symbol]),
                default => true,
            };

            if ($pass) {
                $filtered[] = $c;
            }
        }

        return $filtered;
    }

    /**
     * Parse a raw manual symbol list string into a clean array of uppercase symbol names.
     *
     * @param string $rawList  Raw user input (newline/comma separated)
     * @return list<string>
     */
    public static function parseManualSymbolList(string $rawList): array
    {
        // Split by newlines and commas
        $parts = preg_split('/[\r\n,]+/', $rawList);
        if (!is_array($parts)) {
            return [];
        }

        $symbols = [];
        foreach ($parts as $part) {
            $sym = strtoupper(trim($part));
            if ($sym !== '') {
                $symbols[] = $sym;
            }
        }

        return array_values(array_unique($symbols));
    }

    /**
     * Filter candidates by a manual symbol universe.
     *
     * @param array<int,array<string,mixed>> $candidates
     * @param list<string>                   $manualSymbols  Cleaned uppercase symbol names
     * @param string                         $mode           'manual_only' | 'manual_plus_whitelist' | 'manual_plus_soft' | 'manual_exclude_blacklist'
     * @return array<int,array<string,mixed>>
     */
    public function filterByManualUniverse(array $candidates, array $manualSymbols, string $mode): array
    {
        $manualSet = array_flip($manualSymbols);

        // Build the allowed / blocked set based on mode
        $allowedSet = $manualSet;

        if ($mode === 'manual_plus_whitelist') {
            $whitelist = $this->state->readJson('storage/whitelist.json', []);
            foreach ($whitelist as $sym) {
                $allowedSet[$sym] = true;
            }
        } elseif ($mode === 'manual_plus_soft') {
            $softWhitelist = $this->state->readJson('storage/soft_whitelist.json', []);
            foreach ($softWhitelist as $sym) {
                $allowedSet[$sym] = true;
            }
        } elseif ($mode === 'manual_exclude_blacklist') {
            $blacklist = $this->state->readJson('storage/blacklist.json', []);
            $blacklistSet = array_flip($blacklist);
            // Remove blacklisted symbols from manual set
            foreach ($blacklistSet as $sym => $_) {
                unset($allowedSet[$sym]);
            }
        }

        $filtered = [];
        foreach ($candidates as $c) {
            $symbol = strtoupper((string)($c['symbol'] ?? ''));
            if ($symbol === '') {
                continue;
            }
            if (isset($allowedSet[$symbol])) {
                $filtered[] = $c;
            }
        }

        return $filtered;
    }
}
