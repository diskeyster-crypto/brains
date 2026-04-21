<?php

declare(strict_types=1);

/**
 * Pattern Strategy — Trend Engine
 *
 * Determines H4 trend direction for a single symbol from its recent candles.
 *
 * Rule (deterministic):
 *   1. Compute a short EMA (fast) and long EMA (slow) from close prices.
 *   2. Compare the last fast vs. last slow value.
 *   3. Also compare first-half average close vs. second-half average close for
 *      a simple price trajectory confirmation.
 *   4. Both must agree for a directional output; otherwise → flat | unknown.
 *
 * Outputs one of: bullish | bearish | flat | unknown
 */

namespace Modules\Strategy\Pattern\Logic;

final class PatternTrend
{
    private const FAST_PERIOD = 8;
    private const SLOW_PERIOD = 21;

    /**
     * Analyse trend from H4 candle data.
     *
     * @param  array  $candles   [ ['open'=>, 'high'=>, 'low'=>, 'close'=>, 'ts'=>], ... ]
     *                            Ordered oldest → newest.
     * @return array  { trend_direction, fast_ema, slow_ema, slope_pass }
     */
    public function analyse(array $candles): array
    {
        $closes = array_map(fn($c) => (float)($c['close'] ?? 0.0), $candles);
        $n      = count($closes);

        if ($n < self::SLOW_PERIOD) {
            return [
                'trend_direction' => 'unknown',
                'fast_ema'        => null,
                'slow_ema'        => null,
                'slope_pass'      => false,
            ];
        }

        $fastEma = $this->ema($closes, self::FAST_PERIOD);
        $slowEma = $this->ema($closes, self::SLOW_PERIOD);

        $lastFast = end($fastEma);
        $lastSlow = end($slowEma);

        // EMA signal
        if ($lastFast > $lastSlow) {
            $emaDir = 'bullish';
        } elseif ($lastFast < $lastSlow) {
            $emaDir = 'bearish';
        } else {
            $emaDir = 'flat';
        }

        // Slope confirmation: compare first-half vs second-half close average
        $half      = (int)floor($n / 2);
        $firstHalf = array_slice($closes, 0, $half);
        $secondHalf = array_slice($closes, $half);
        $avgFirst  = array_sum($firstHalf) / max(1, count($firstHalf));
        $avgSecond = array_sum($secondHalf) / max(1, count($secondHalf));

        if ($avgSecond > $avgFirst) {
            $slopeDir = 'bullish';
        } elseif ($avgSecond < $avgFirst) {
            $slopeDir = 'bearish';
        } else {
            $slopeDir = 'flat';
        }

        $slopePass = ($emaDir === $slopeDir);

        // Both must agree
        if ($emaDir === 'flat' || !$slopePass) {
            $direction = 'flat';
        } else {
            $direction = $emaDir;
        }

        return [
            'trend_direction' => $direction,
            'fast_ema'        => round($lastFast, 6),
            'slow_ema'        => round($lastSlow, 6),
            'slope_pass'      => $slopePass,
        ];
    }

    /**
     * Check whether the trend passes the gate for a given side.
     *
     * @param  string $trendDirection
     * @param  string $side  'long' | 'short'
     * @return array  { pass: bool, reason: string }
     */
    public function gate(string $trendDirection, string $side): array
    {
        if ($side === 'long' && $trendDirection === 'bullish') {
            return ['pass' => true,  'reason' => 'trend_bullish_long_ok'];
        }
        if ($side === 'short' && $trendDirection === 'bearish') {
            return ['pass' => true,  'reason' => 'trend_bearish_short_ok'];
        }
        return ['pass' => false, 'reason' => "trend_{$trendDirection}_side_{$side}_mismatch"];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Compute EMA values array for given close prices and period.
     * Uses standard Wilder-style smoothing: k = 2 / (period + 1).
     */
    private function ema(array $closes, int $period): array
    {
        $k    = 2.0 / ($period + 1);
        $emas = [];
        $prev = null;

        foreach ($closes as $i => $close) {
            if ($i < $period - 1) {
                $emas[] = null;
                continue;
            }
            if ($prev === null) {
                // Seed: simple average of first $period values
                $seed  = array_sum(array_slice($closes, 0, $period)) / $period;
                $prev  = $seed;
                $emas[] = $seed;
                continue;
            }
            $ema   = $close * $k + $prev * (1 - $k);
            $prev  = $ema;
            $emas[] = $ema;
        }

        return $emas;
    }
}
