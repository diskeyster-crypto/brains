<?php

declare(strict_types=1);

/**
 * Pattern Strategy — Wave Engine
 *
 * Identifies the current swing wave from H4 candles.
 *
 * Algorithm:
 *   1. Find recent swing highs and swing lows using a pivot-window approach.
 *   2. Determine wave_direction from the sequence of the last two swing pivots.
 *   3. Determine wave_state: impulsive (price extending) | corrective (price retracing).
 *   4. Compute wave_freshness: how many bars ago the latest pivot occurred.
 *
 * Outputs:
 *   wave_direction   — 'up' | 'down' | 'unknown'
 *   wave_state       — 'impulsive' | 'corrective' | 'unknown'
 *   wave_freshness   — int bars since last pivot (lower = fresher)
 *   last_pivot_price — float
 *   last_pivot_type  — 'high' | 'low' | 'none'
 */

namespace Modules\Strategy\Pattern\Logic;

final class PatternWave
{
    private const PIVOT_WINDOW = 3;  // bars on each side to confirm a swing pivot

    /**
     * Analyse wave from H4 candles (oldest → newest).
     */
    public function analyse(array $candles): array
    {
        $n = count($candles);
        if ($n < self::PIVOT_WINDOW * 2 + 1) {
            return $this->unknownResult();
        }

        $pivots = $this->findPivots($candles);
        if (count($pivots) < 2) {
            return $this->unknownResult();
        }

        // Take the last two pivots
        $last   = $pivots[count($pivots) - 1];
        $second = $pivots[count($pivots) - 2];

        // Wave direction from pivot sequence
        if ($last['type'] === 'high' && $second['type'] === 'low') {
            $direction = 'up';
        } elseif ($last['type'] === 'low' && $second['type'] === 'high') {
            $direction = 'down';
        } else {
            $direction = 'unknown';
        }

        // Wave state: compare current close to last pivot
        $currentClose = (float)($candles[$n - 1]['close'] ?? 0.0);
        $lastPivotPrice = (float)$last['price'];

        if ($direction === 'up') {
            $state = $currentClose >= $lastPivotPrice ? 'impulsive' : 'corrective';
        } elseif ($direction === 'down') {
            $state = $currentClose <= $lastPivotPrice ? 'impulsive' : 'corrective';
        } else {
            $state = 'unknown';
        }

        $freshness = $n - 1 - $last['idx'];

        return [
            'wave_direction'    => $direction,
            'wave_state'        => $state,
            'wave_freshness'    => $freshness,
            'last_pivot_price'  => round($lastPivotPrice, 6),
            'last_pivot_type'   => $last['type'],
        ];
    }

    /**
     * Gate check.
     *
     * @param  array  $wave  Output of analyse()
     * @param  string $side  'long' | 'short'
     * @return array  { pass: bool, reason: string }
     */
    public function gate(array $wave, string $side): array
    {
        $dir   = $wave['wave_direction'] ?? 'unknown';
        $state = $wave['wave_state']     ?? 'unknown';

        if ($dir === 'unknown' || $state === 'unknown') {
            return ['pass' => false, 'reason' => 'wave_unknown'];
        }

        // Impulsive waves (price extending strongly) are not entry contexts.
        if ($state === 'impulsive') {
            return ['pass' => false, 'reason' => "wave_{$dir}_impulsive_side_{$side}_rejected"];
        }

        // Any corrective wave passes for both sides.
        // Continuation context  → wave_up/corrective for long, wave_down/corrective for short.
        // Reversal context      → wave_down/corrective for long (bottom), wave_up/corrective for short (top).
        // Both are valid setup contexts: only impulsive and unknown remain blocked.
        return ['pass' => true, 'reason' => "wave_{$dir}_corrective_{$side}_ok"];
    }

    // -------------------------------------------------------------------------

    private function findPivots(array $candles): array
    {
        $n      = count($candles);
        $w      = self::PIVOT_WINDOW;
        $pivots = [];

        for ($i = $w; $i < $n - $w; $i++) {
            $high = (float)($candles[$i]['high'] ?? 0.0);
            $low  = (float)($candles[$i]['low']  ?? 0.0);

            // Check swing high
            $isSwingHigh = true;
            for ($j = $i - $w; $j <= $i + $w; $j++) {
                if ($j === $i) {
                    continue;
                }
                if ((float)($candles[$j]['high'] ?? 0.0) >= $high) {
                    $isSwingHigh = false;
                    break;
                }
            }
            if ($isSwingHigh) {
                $pivots[] = ['type' => 'high', 'price' => $high, 'idx' => $i];
                continue;
            }

            // Check swing low
            $isSwingLow = true;
            for ($j = $i - $w; $j <= $i + $w; $j++) {
                if ($j === $i) {
                    continue;
                }
                if ((float)($candles[$j]['low'] ?? 0.0) <= $low) {
                    $isSwingLow = false;
                    break;
                }
            }
            if ($isSwingLow) {
                $pivots[] = ['type' => 'low', 'price' => $low, 'idx' => $i];
            }
        }

        return $pivots;
    }

    private function unknownResult(): array
    {
        return [
            'wave_direction'   => 'unknown',
            'wave_state'       => 'unknown',
            'wave_freshness'   => 9999,
            'last_pivot_price' => 0.0,
            'last_pivot_type'  => 'none',
        ];
    }
}
