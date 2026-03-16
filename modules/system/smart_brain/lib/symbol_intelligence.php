<?php
declare(strict_types=1);

/**
 * Symbol Intelligence Layer — Smart Brain
 *
 * Analyzes closed simulator trades per symbol and classifies symbols into:
 *   whitelist  — good performing symbols
 *   blacklist  — bad performing symbols (enough evidence)
 *   watchlist  — uncertain / not enough data
 *   unknown    — no history at all
 *
 * Writes:
 *   storage/symbol_stats.json
 *   storage/whitelist.json
 *   storage/blacklist.json
 *   storage/watchlist.json
 */
final class SymbolIntelligence
{
    private StateManager $state;

    // Classification thresholds (conservative defaults)
    private const MIN_TRADES_FOR_CLASSIFICATION = 5;
    private const WHITELIST_MIN_WINRATE = 0.50;
    private const WHITELIST_MIN_AVG_ROI = 0.0;
    private const BLACKLIST_MAX_WINRATE = 0.30;
    private const BLACKLIST_EARLY_FAILURE_RATIO = 0.60;

    public function __construct(StateManager $state)
    {
        $this->state = $state;
    }

    /**
     * Rebuild all symbol intelligence data from closed trades.
     *
     * @return array{symbol_stats:array<string,mixed>,whitelist:list<string>,blacklist:list<string>,watchlist:list<string>}
     */
    public function rebuild(): array
    {
        $closed = $this->state->readJson('storage/simulator/closed.json', []);

        // Aggregate per-symbol raw data
        $raw = [];
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

            unset($r);
        }

        // Compute final stats and classify
        $symbolStats = [];
        $whitelist = [];
        $blacklist = [];
        $watchlist = [];

        foreach ($raw as $symbol => $r) {
            $t = $r['trades_total'];
            $winrate = $t > 0 ? round($r['wins'] / $t, 4) : 0.0;
            $avgRoi = $t > 0 ? round($r['roi_sum'] / $t, 6) : 0.0;
            $avgMae = $t > 0 ? round($r['mae_sum'] / $t, 6) : 0.0;
            $avgMfe = $t > 0 ? round($r['mfe_sum'] / $t, 6) : 0.0;
            $avgDuration = $t > 0 ? round($r['duration_sum'] / $t, 1) : 0.0;

            $status = $this->classifySymbol($t, $winrate, $avgRoi, $r['early_failure_count']);

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
            ];

            match ($status) {
                'whitelist' => $whitelist[] = $symbol,
                'blacklist' => $blacklist[] = $symbol,
                'watchlist' => $watchlist[] = $symbol,
                default => null,
            };
        }

        // Sort lists alphabetically
        sort($whitelist);
        sort($blacklist);
        sort($watchlist);

        // Write storage files
        $this->state->writeJson('storage/symbol_stats.json', [
            'symbols' => $symbolStats,
            'whitelist_count' => count($whitelist),
            'blacklist_count' => count($blacklist),
            'watchlist_count' => count($watchlist),
            'total_symbols' => count($symbolStats),
            'updated_at' => date('c'),
        ]);
        $this->state->writeJson('storage/whitelist.json', $whitelist);
        $this->state->writeJson('storage/blacklist.json', $blacklist);
        $this->state->writeJson('storage/watchlist.json', $watchlist);

        return [
            'symbol_stats' => $symbolStats,
            'whitelist' => $whitelist,
            'blacklist' => $blacklist,
            'watchlist' => $watchlist,
        ];
    }

    /**
     * Classify a symbol based on its trading metrics.
     *
     * @param int   $tradesTotal
     * @param float $winrate
     * @param float $avgRoi
     * @param int   $earlyFailureCount
     * @return string  'whitelist' | 'blacklist' | 'watchlist' | 'unknown'
     */
    private function classifySymbol(int $tradesTotal, float $winrate, float $avgRoi, int $earlyFailureCount): string
    {
        // Not enough evidence — watchlist or unknown
        if ($tradesTotal < self::MIN_TRADES_FOR_CLASSIFICATION) {
            return $tradesTotal === 0 ? 'unknown' : 'watchlist';
        }

        // Blacklist: very low winrate
        if ($winrate < self::BLACKLIST_MAX_WINRATE) {
            return 'blacklist';
        }

        // Blacklist: extremely high early failure ratio
        if ($tradesTotal > 0 && ($earlyFailureCount / $tradesTotal) >= self::BLACKLIST_EARLY_FAILURE_RATIO) {
            return 'blacklist';
        }

        // Whitelist: good winrate and positive average ROI
        if ($winrate >= self::WHITELIST_MIN_WINRATE && $avgRoi > self::WHITELIST_MIN_AVG_ROI) {
            return 'whitelist';
        }

        // Everything else: watchlist (between whitelist and blacklist)
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
            'blacklist' => $this->state->readJson('storage/blacklist.json', []),
            'watchlist' => $this->state->readJson('storage/watchlist.json', []),
            'whitelist_count' => (int)($statsFile['whitelist_count'] ?? 0),
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
     * @param string $filterMode  'all' | 'whitelist_only' | 'exclude_blacklist' | 'watchlist_only'
     * @return array<int,array<string,mixed>>
     */
    public function filterCandidates(array $candidates, string $filterMode): array
    {
        if ($filterMode === 'all') {
            return $candidates;
        }

        $whitelist = $this->state->readJson('storage/whitelist.json', []);
        $blacklist = $this->state->readJson('storage/blacklist.json', []);
        $watchlist = $this->state->readJson('storage/watchlist.json', []);

        // Convert to lookup sets for performance
        $whitelistSet = array_flip($whitelist);
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
                default => true,
            };

            if ($pass) {
                $filtered[] = $c;
            }
        }

        return $filtered;
    }
}
