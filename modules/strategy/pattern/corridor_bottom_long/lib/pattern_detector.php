<?php

declare(strict_types=1);

/**
 * Corridor Bottom Long — Pattern Detector
 *
 * Checks a fixed set of bullish reversal patterns against a candle window.
 * All checks are ONLY meaningful when the current price is already inside
 * the 24 h corridor low zone (the caller is responsible for that gate).
 *
 * Supported patterns (driven by config 'enabled_patterns'):
 *   double_bottom       — two local lows near the corridor floor, second ≥ first
 *   reclaim_low         — price pierced the low zone then reclaimed above it
 *   higher_low          — latest swing low is higher than previous swing low
 *   engulfing_reversal  — bullish engulfing candle near the corridor low
 *
 * Each pattern returns:
 *   [
 *     'detected'     => bool,
 *     'pattern_type' => string,
 *     'score'        => int,    // 0 or 1
 *     'evidence'     => string, // short human-readable description
 *   ]
 *
 * Usage:
 *   $detector = new PatternDetector();
 *   $result   = $detector->detect($candles, $corridorLow, $config);
 *   // $result['best'] is the highest-scoring pattern found
 */

namespace Modules\Strategy\CorridorBottomLong\Lib;

final class PatternDetector
{
    // ── Public entry point ────────────────────────────────────────────────────

    /**
     * Run all enabled pattern checks on the provided candle window.
     *
     * @param  array  $candles     OHLCV records, oldest → newest.
     *                             Each record: ['ts','open','high','low','close','volume']
     * @param  float  $corridorLow 24 h corridor low price.
     * @param  array  $config      Effective strategy config.
     * @return array  {
     *   best:     array  — highest-scoring result (or null pattern if none detected),
     *   all:      array  — list of all pattern results,
     *   detected: bool,  — true if at least one pattern fired
     * }
     */
    public function detect(array $candles, float $corridorLow, array $config): array
    {
        $enabled = (array)($config['enabled_patterns'] ?? [
            'double_bottom',
            'reclaim_low',
            'higher_low',
            'engulfing_reversal',
        ]);

        $results = [];

        foreach ($enabled as $pattern) {
            $r = match ($pattern) {
                'double_bottom'      => $this->checkDoubleBottom($candles, $corridorLow),
                'reclaim_low'        => $this->checkReclaimLow($candles, $corridorLow),
                'higher_low'         => $this->checkHigherLow($candles),
                'engulfing_reversal' => $this->checkEngulfingReversal($candles, $corridorLow),
                default              => null,
            };
            if ($r !== null) {
                $results[] = $r;
            }
        }

        // Pick the best (first detected, or first in list)
        $detected = array_values(array_filter($results, fn($r) => $r['detected']));
        $best     = !empty($detected) ? $detected[0] : $this->nullPattern();

        return [
            'best'     => $best,
            'all'      => $results,
            'detected' => !empty($detected),
        ];
    }

    // ── Pattern checks ────────────────────────────────────────────────────────

    /**
     * DOUBLE BOTTOM
     *
     * Criteria:
     *   - Find two local lows in the candle window, both within 3% above corridorLow
     *   - Second low does NOT break first low by more than 0.3%
     *   - There is a rebound (a candle whose close > both lows) between them
     */
    private function checkDoubleBottom(array $candles, float $corridorLow): array
    {
        $n = count($candles);
        if ($n < 6) {
            return $this->pattern('double_bottom', false, 0, 'not_enough_candles');
        }

        // Proximity threshold: lows must be within 3% above corridorLow
        $proximityPct = 3.0;
        $threshold    = $corridorLow * (1 + $proximityPct / 100);

        // Find local lows (candle[i].low < candle[i-1].low && candle[i].low < candle[i+1].low)
        $localLows = [];
        for ($i = 1; $i < $n - 1; $i++) {
            $low = (float)($candles[$i]['low'] ?? 0.0);
            $prevLow = (float)($candles[$i - 1]['low'] ?? 0.0);
            $nextLow = (float)($candles[$i + 1]['low'] ?? 0.0);
            if ($low <= $threshold && $low < $prevLow && $low < $nextLow) {
                $localLows[] = ['idx' => $i, 'low' => $low];
            }
        }

        if (count($localLows) < 2) {
            return $this->pattern('double_bottom', false, 0, 'less_than_two_local_lows_near_floor');
        }

        // Take the last two qualifying lows
        $low1 = $localLows[count($localLows) - 2];
        $low2 = $localLows[count($localLows) - 1];

        // Second low must not go significantly below first low
        $breakPct = $low1['low'] > 0 ? (($low1['low'] - $low2['low']) / $low1['low']) * 100 : 0.0;
        if ($breakPct > 0.3) {
            return $this->pattern('double_bottom', false, 0, 'second_low_breaks_first_low');
        }

        // Check for a rebound candle between the two lows
        $rebound = false;
        $reboundMin = max($low1['low'], $low2['low']) * 1.002; // at least 0.2% above the lows
        for ($i = $low1['idx'] + 1; $i < $low2['idx']; $i++) {
            if ((float)($candles[$i]['close'] ?? 0.0) > $reboundMin) {
                $rebound = true;
                break;
            }
        }

        if (!$rebound) {
            return $this->pattern('double_bottom', false, 0, 'no_rebound_between_lows');
        }

        return $this->pattern(
            'double_bottom', true, 1,
            sprintf('lows at idx %d (%.6f) and %d (%.6f)', $low1['idx'], $low1['low'], $low2['idx'], $low2['low'])
        );
    }

    /**
     * RECLAIM LOW
     *
     * Criteria:
     *   - At least one candle whose low is at or below corridorLow (pierce/visit)
     *   - The closing price of that candle OR a subsequent candle is above corridorLow
     */
    private function checkReclaimLow(array $candles, float $corridorLow): array
    {
        $n = count($candles);
        if ($n < 3) {
            return $this->pattern('reclaim_low', false, 0, 'not_enough_candles');
        }

        // Tolerance: "at or below" means within 0.5% below corridorLow
        $pierceTolerance = $corridorLow * 0.995;

        $pierceIdx = -1;
        for ($i = 0; $i < $n; $i++) {
            if ((float)($candles[$i]['low'] ?? 0.0) <= $corridorLow) {
                $pierceIdx = $i;
                // Keep updating: we want the most recent pierce
            }
        }

        if ($pierceIdx < 0) {
            return $this->pattern('reclaim_low', false, 0, 'no_pierce_of_corridor_low');
        }

        // After the pierce, check that price reclaimed above corridorLow
        $reclaimed = false;
        for ($i = $pierceIdx; $i < $n; $i++) {
            if ((float)($candles[$i]['close'] ?? 0.0) > $corridorLow) {
                $reclaimed = true;
                break;
            }
        }

        if (!$reclaimed) {
            return $this->pattern('reclaim_low', false, 0, 'price_did_not_reclaim_above_low');
        }

        return $this->pattern(
            'reclaim_low', true, 1,
            sprintf('pierced at idx %d, reclaimed above %.6f', $pierceIdx, $corridorLow)
        );
    }

    /**
     * HIGHER LOW
     *
     * Criteria:
     *   - Compare the two most recent swing lows in the window
     *   - Latest swing low > previous swing low
     */
    private function checkHigherLow(array $candles): array
    {
        $n = count($candles);
        if ($n < 5) {
            return $this->pattern('higher_low', false, 0, 'not_enough_candles');
        }

        // Collect swing lows: candle[i].low < both neighbours
        $swingLows = [];
        for ($i = 1; $i < $n - 1; $i++) {
            $low  = (float)($candles[$i]['low']       ?? 0.0);
            $prev = (float)($candles[$i - 1]['low']   ?? 0.0);
            $next = (float)($candles[$i + 1]['low']   ?? 0.0);
            if ($low < $prev && $low < $next) {
                $swingLows[] = ['idx' => $i, 'low' => $low];
            }
        }

        if (count($swingLows) < 2) {
            return $this->pattern('higher_low', false, 0, 'less_than_two_swing_lows');
        }

        $last = $swingLows[count($swingLows) - 1];
        $prev = $swingLows[count($swingLows) - 2];

        if ($last['low'] <= $prev['low']) {
            return $this->pattern('higher_low', false, 0, 'latest_low_not_higher');
        }

        return $this->pattern(
            'higher_low', true, 1,
            sprintf('prev low %.6f at idx %d < latest low %.6f at idx %d',
                $prev['low'], $prev['idx'], $last['low'], $last['idx'])
        );
    }

    /**
     * ENGULFING REVERSAL
     *
     * Criteria:
     *   - Last or second-to-last candle is a bullish engulfing (open < prev.close, close > prev.open)
     *   - The candle that is being engulfed must be bearish (close < open)
     *   - The pattern must be near the corridor low (engulfing candle low ≤ corridorLow × 1.03)
     */
    private function checkEngulfingReversal(array $candles, float $corridorLow): array
    {
        $n = count($candles);
        if ($n < 2) {
            return $this->pattern('engulfing_reversal', false, 0, 'not_enough_candles');
        }

        $proximityThreshold = $corridorLow * 1.03; // within 3% of corridor low

        // Check the last two candle pairs (most recent first)
        for ($i = $n - 1; $i >= 1; $i--) {
            $curr = $candles[$i];
            $prev = $candles[$i - 1];

            $currOpen  = (float)($curr['open']  ?? 0.0);
            $currClose = (float)($curr['close'] ?? 0.0);
            $currLow   = (float)($curr['low']   ?? 0.0);
            $prevOpen  = (float)($prev['open']  ?? 0.0);
            $prevClose = (float)($prev['close'] ?? 0.0);

            // Previous candle must be bearish
            if ($prevClose >= $prevOpen) {
                continue;
            }

            // Current candle must be bullish and engulf previous
            $bullish   = $currClose > $currOpen;
            $engulfs   = $currOpen <= $prevClose && $currClose >= $prevOpen;
            $nearFloor = $currLow <= $proximityThreshold;

            if ($bullish && $engulfs && $nearFloor) {
                return $this->pattern(
                    'engulfing_reversal', true, 1,
                    sprintf('engulfing at idx %d, candle low %.6f vs corridor low %.6f', $i, $currLow, $corridorLow)
                );
            }

            // Only check the last two pairs
            if ($i < $n - 2) {
                break;
            }
        }

        return $this->pattern('engulfing_reversal', false, 0, 'no_engulfing_near_floor');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function pattern(string $type, bool $detected, int $score, string $evidence): array
    {
        return [
            'detected'     => $detected,
            'pattern_type' => $type,
            'score'        => $detected ? $score : 0,
            'evidence'     => $evidence,
        ];
    }

    private function nullPattern(): array
    {
        return [
            'detected'     => false,
            'pattern_type' => null,
            'score'        => 0,
            'evidence'     => 'no_pattern_detected',
        ];
    }
}
