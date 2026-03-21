<?php
declare(strict_types=1);

/**
 * Coin Passport Engine — Smart Brain Phase 5
 *
 * Maintains long-term per-symbol statistics in storage/passports/{SYMBOL}.json.
 *
 * Data sources:
 *   - monitors.json  (corridor_width for averaging)
 *   - simulator/closed.json  (trade statistics)
 *
 * Computes: trades_total, winrate, avg_mae, avg_mfe, avg_duration,
 *           corridor_width_avg, volatility_avg, speed_class, reliability_score
 */
final class CoinPassportEngine
{
    private StateManager $state;

    public function __construct(StateManager $state)
    {
        $this->state = $state;
    }

    /**
     * Update passports for every symbol present in monitors.
     *
     * @param array<int,array<string,mixed>> $monitors
     */
    public function update(array $monitors): void
    {
        $closed = $this->state->readJson('storage/simulator/closed.json', []);

        // Group closed trades by symbol
        /** @var array<string,array<int,array<string,mixed>>> $closedBySymbol */
        $closedBySymbol = [];
        foreach ($closed as $trade) {
            $sym = (string)($trade['symbol'] ?? '');
            if ($sym !== '') {
                $closedBySymbol[$sym][] = $trade;
            }
        }

        foreach ($monitors as $monitor) {
            $symbol = (string)($monitor['symbol'] ?? '');
            if ($symbol === '') {
                continue;
            }

            $existing = $this->state->readJson('storage/passports/' . $symbol . '.json', []);
            $trades = $closedBySymbol[$symbol] ?? [];

            $passport = $this->buildPassport($symbol, $monitor, $existing, $trades);
            $this->state->writeJson('storage/passports/' . $symbol . '.json', $passport);
        }
    }

    /**
     * @param array<string,mixed> $monitor
     * @param array<string,mixed> $existing
     * @param array<int,array<string,mixed>> $trades
     * @return array<string,mixed>
     */
    private function buildPassport(string $symbol, array $monitor, array $existing, array $trades): array
    {
        $corridorWidth = (float)($monitor['corridor_width'] ?? 0.0);

        // Running average for corridor_width_avg (EMA alpha = 0.2)
        $oldCorridorAvg = (float)($existing['corridor_width_avg'] ?? 0.0);
        $corridorWidthAvg = ($oldCorridorAvg > 0.0)
            ? $oldCorridorAvg * 0.8 + $corridorWidth * 0.2
            : $corridorWidth;

        // Volatility avg — use existing EMA or 0 when unavailable from monitors
        $oldVolatilityAvg = (float)($existing['volatility_avg'] ?? 0.0);
        $volatilityAvg = $oldVolatilityAvg; // preserved from previous updates

        // Trade statistics — recomputed from ALL closed trades
        $tradesTotal = count($trades);
        $tradesWon = 0;
        $tradesLost = 0;
        $maeSum = 0.0;
        $mfeSum = 0.0;
        $durationSum = 0.0;

        foreach ($trades as $trade) {
            $roi = (float)($trade['roi'] ?? 0.0);
            if ($roi >= 0) {
                $tradesWon++;
            } else {
                $tradesLost++;
            }
            $maeSum += (float)($trade['mae'] ?? 0.0);
            $mfeSum += (float)($trade['mfe'] ?? 0.0);
            $durationSum += (float)($trade['duration'] ?? 0.0);
        }

        $winrate = ($tradesTotal > 0) ? round($tradesWon / $tradesTotal, 4) : 0.0;
        $avgMae = ($tradesTotal > 0) ? round($maeSum / $tradesTotal, 6) : 0.0;
        $avgMfe = ($tradesTotal > 0) ? round($mfeSum / $tradesTotal, 6) : 0.0;
        $avgDuration = ($tradesTotal > 0) ? round($durationSum / $tradesTotal, 2) : 0.0;

        // Speed class
        $speedClass = $this->computeSpeedClass($avgDuration);

        // Reliability score = winrate × log10(trades_total + 1) × corridor_width_avg
        $reliabilityScore = $this->computeReliability($winrate, $tradesTotal, $corridorWidthAvg);

        return [
            'symbol' => $symbol,
            'corridor_width_avg' => round($corridorWidthAvg, 6),
            'volatility_avg' => round($volatilityAvg, 6),
            'avg_mae' => $avgMae,
            'avg_mfe' => $avgMfe,
            'avg_duration' => $avgDuration,
            'winrate' => $winrate,
            'trades_total' => $tradesTotal,
            'trades_won' => $tradesWon,
            'trades_lost' => $tradesLost,
            'speed_class' => $speedClass,
            'reliability_score' => $reliabilityScore,
            'updated_at' => date('c'),
        ];
    }

    /**
     * reliability_score = winrate × log10(trades_total + 1) × corridor_width_avg
     * Clamped to [0, 1].
     */
    private function computeReliability(float $winrate, int $tradesTotal, float $corridorWidthAvg): float
    {
        $score = $winrate * log10($tradesTotal + 1) * $corridorWidthAvg;
        return round(min(1.0, max(0.0, $score)), 4);
    }

    /**
     * avg_duration < 10  → fast
     * avg_duration < 30  → medium
     * else               → slow
     */
    private function computeSpeedClass(float $avgDuration): string
    {
        if ($avgDuration < 10.0) {
            return 'fast';
        }
        if ($avgDuration < 30.0) {
            return 'medium';
        }
        return 'slow';
    }

    /**
     * Enrich passports with execution profiles from live trading bot stats.
     *
     * Merges per-symbol exit behavior (stop/trailing/BE stats) into each
     * passport's execution_profile section. Only writes when sample size
     * meets the minimum threshold.
     *
     * @param array<string,array<string,mixed>> $symbolExitStats Keyed by symbol
     * @param int $minSampleSize Minimum trades to write a meaningful profile
     */
    public function enrichWithExecutionProfile(array $symbolExitStats, int $minSampleSize = 10): void
    {
        if (empty($symbolExitStats)) {
            return;
        }

        foreach ($symbolExitStats as $symbol => $stats) {
            $count = (int)($stats['trades_count'] ?? 0);
            if ($count < 3) {
                continue; // Not enough trades to store anything
            }

            $passportPath = 'storage/passports/' . $symbol . '.json';
            $passport = $this->state->readJson($passportPath, []);
            if (empty($passport)) {
                continue; // No passport exists yet
            }

            $profile = [
                'sample_size' => $count,
                'actionable' => $count >= $minSampleSize,
                'stop_behavior' => [
                    'stop_hit_count' => (int)($stats['stop_hit_count'] ?? 0),
                    'stop_hit_rate' => $count > 0
                        ? round((int)($stats['stop_hit_count'] ?? 0) / $count, 4) : 0,
                ],
                'trailing_behavior' => [
                    'trailing_enabled_count' => (int)($stats['trailing_enabled_count'] ?? 0),
                    'trailing_active_count' => (int)($stats['trailing_active_count'] ?? 0),
                    'trailing_activation_rate' => (float)($stats['trailing_activation_rate'] ?? 0),
                    'trailing_close_count' => (int)($stats['trailing_close_count'] ?? 0),
                ],
                'break_even_behavior' => [
                    'break_even_enabled_count' => (int)($stats['break_even_enabled_count'] ?? 0),
                    'break_even_applied_count' => (int)($stats['break_even_applied_count'] ?? 0),
                    'break_even_apply_rate' => (float)($stats['break_even_apply_rate'] ?? 0),
                ],
                'exit_reason_distribution' => $stats['close_reason_distribution'] ?? [],
                'expectancy' => (float)($stats['expectancy'] ?? 0),
                'winrate' => (float)($stats['winrate'] ?? 0),
                'avg_roi' => (float)($stats['avg_roi'] ?? 0),
                'roi_stats' => $stats['roi_stats'] ?? [],
                'by_side' => [],
                'updated_at' => date('c'),
            ];

            // Add side breakdown if available
            foreach (['long', 'short'] as $side) {
                $sd = $stats['by_side'][$side] ?? null;
                if ($sd && ($sd['trades'] ?? 0) > 0) {
                    $profile['by_side'][$side] = [
                        'trades' => (int)$sd['trades'],
                        'winrate' => (float)($sd['winrate'] ?? 0),
                        'avg_roi' => (float)($sd['avg_roi'] ?? 0),
                    ];
                }
            }

            // Derived scores (only meaningful with enough data)
            if ($count >= $minSampleSize) {
                $trailRate = (float)($stats['trailing_activation_rate'] ?? 0);
                $beRate = (float)($stats['break_even_apply_rate'] ?? 0);
                $stopRate = $profile['stop_behavior']['stop_hit_rate'];

                // trailing_friendliness: high trail activation + low stop hit = trailing-friendly
                $profile['trailing_friendliness_score'] = round(
                    min(1.0, max(0.0, $trailRate * 0.6 + (1 - $stopRate) * 0.4)),
                    4
                );
                // break_even_friendliness: high BE apply rate
                $profile['break_even_friendliness_score'] = round(
                    min(1.0, max(0.0, $beRate)),
                    4
                );
                // stop_sensitivity: high stop hit rate = high sensitivity (noisy symbol)
                $profile['stop_sensitivity_score'] = round(
                    min(1.0, max(0.0, $stopRate)),
                    4
                );
            }

            // Stop distance stats
            if (!empty($stats['stop_distance_stats'])) {
                $profile['stop_distance_stats'] = $stats['stop_distance_stats'];
            }

            // MAE-based adaptive stop profile (per symbol overall)
            $maeStopFloor = 0.03;
            $maeStopCap = 0.08;
            $maeMinWinners = 5;
            if (!empty($stats['mae_winners_stats'])) {
                $mws = $stats['mae_winners_stats'];
                $mwCount = (int)($mws['count'] ?? 0);
                $mwP75 = (float)($mws['p75'] ?? 0);
                $profile['mae_stop_profile'] = [
                    'mae_winners_count' => $mwCount,
                    'mae_winners_median' => (float)($mws['median'] ?? 0),
                    'mae_winners_p75' => $mwP75,
                    'mae_winners_p80' => (float)($mws['p80'] ?? 0),
                    'mae_winners_avg' => (float)($mws['avg'] ?? 0),
                    // Persist computed suggestion so passport is self-explanatory
                    'suggested_logical_stop_roi' => ($mwCount >= $maeMinWinners && $mwP75 > 0)
                        ? round(max($maeStopFloor, min($maeStopCap, $mwP75)), 4) : null,
                    'fallback_used' => ($mwCount < $maeMinWinners || $mwP75 <= 0),
                    'last_updated_ts' => time(),
                ];
            }

            // MAE-based stop profile per side
            foreach (['long', 'short'] as $side) {
                $sd = $stats['by_side'][$side] ?? null;
                if ($sd && !empty($sd['mae_winners_stats'])) {
                    $sideMae = $sd['mae_winners_stats'];
                    $sideCount = (int)($sideMae['count'] ?? 0);
                    $sideP75 = (float)($sideMae['p75'] ?? 0);
                    // Side-specific total trades and winning trades for strict side-aware gating
                    $sideTotalTrades = (int)($sd['trades'] ?? 0);
                    $sideWins = (int)($sd['wins'] ?? 0);
                    $profile['by_side'][$side]['mae_stop_profile'] = [
                        'sample_size' => $sideTotalTrades,
                        'winning_sample_size' => $sideWins,
                        'mae_winners_count' => $sideCount,
                        'mae_winners_median' => (float)($sideMae['median'] ?? 0),
                        'mae_winners_p75' => $sideP75,
                        'mae_winners_p80' => (float)($sideMae['p80'] ?? 0),
                        // Persist per-side computed suggestion
                        'suggested_logical_stop_roi' => ($sideCount >= $maeMinWinners && $sideP75 > 0)
                            ? round(max($maeStopFloor, min($maeStopCap, $sideP75)), 4) : null,
                        'fallback_used' => ($sideCount < $maeMinWinners || $sideP75 <= 0),
                        'last_updated_ts' => time(),
                    ];
                }
            }

            $passport['execution_profile'] = $profile;
            $this->state->writeJson($passportPath, $passport);
        }
    }
}
