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
}
