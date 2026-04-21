<?php

declare(strict_types=1);

/**
 * Pattern Strategy — Double Top Detector
 *
 * Identifies a double-top pattern candidate (short bias) from H4 candles.
 *
 * Rules:
 *   1. Find the two most recent swing highs.
 *   2. The two highs must be within tolerance of each other (similar price level).
 *   3. There must be an intervening swing low (the "neckline") between the two highs.
 *   4. Current price must be below or near the neckline (approaching breakdown).
 *
 * Outputs:
 *   candidate_found      bool
 *   candidate_side       'short'
 *   candidate_trigger    float  (neckline price — breakdown level)
 *   candidate_score      float  0.0–1.0
 *   neckline             float
 *   high1_price          float
 *   high2_price          float
 *   reject_reason        string | null
 */

namespace Modules\Strategy\Pattern\Logic;

final class PatternDoubleTop
{
    private const PIVOT_WINDOW    = 3;
    private const HIGH_TOLERANCE  = 0.015; // 1.5% similarity between the two highs
    private const MIN_PIVOT_GAP   = 4;

    public function detect(array $candles): array
    {
        $n = count($candles);
        if ($n < self::PIVOT_WINDOW * 2 + self::MIN_PIVOT_GAP + 2) {
            return $this->noCandidate('insufficient_candles');
        }

        $pivots = $this->findPivots($candles);
        $highs  = array_values(array_filter($pivots, fn($p) => $p['type'] === 'high'));
        $lows   = array_values(array_filter($pivots, fn($p) => $p['type'] === 'low'));

        if (count($highs) < 2) {
            return $this->noCandidate('insufficient_swing_highs');
        }

        $high2 = $highs[count($highs) - 1];
        $high1 = $highs[count($highs) - 2];

        if (($high2['idx'] - $high1['idx']) < self::MIN_PIVOT_GAP) {
            return $this->noCandidate('highs_too_close');
        }

        $avgHigh   = ($high1['price'] + $high2['price']) / 2.0;
        $deviation = abs($high1['price'] - $high2['price']) / max(1e-8, $avgHigh);
        if ($deviation > self::HIGH_TOLERANCE) {
            return $this->noCandidate('highs_not_similar');
        }

        // Neckline: lowest swing low between the two highs
        $necklineLow = null;
        foreach ($lows as $l) {
            if ($l['idx'] > $high1['idx'] && $l['idx'] < $high2['idx']) {
                if ($necklineLow === null || $l['price'] < $necklineLow['price']) {
                    $necklineLow = $l;
                }
            }
        }
        if ($necklineLow === null) {
            return $this->noCandidate('no_neckline_between_highs');
        }

        $neckline     = (float)$necklineLow['price'];
        $currentClose = (float)($candles[$n - 1]['close'] ?? 0.0);

        // Price must be at or above neckline (still completing pattern or just breaking)
        if ($currentClose < $neckline * 0.99) {
            return $this->noCandidate('price_too_far_below_neckline');
        }

        $height   = abs($avgHigh - $neckline) / max(1e-8, $avgHigh);
        $symmetry = 1.0 - ($deviation / self::HIGH_TOLERANCE);
        $score    = round(min(1.0, ($height * 10 + $symmetry) / 2.0), 4);

        return [
            'candidate_found'   => true,
            'candidate_side'    => 'short',
            'candidate_trigger' => round($neckline, 6),
            'candidate_score'   => $score,
            'neckline'          => round($neckline, 6),
            'high1_price'       => round((float)$high1['price'], 6),
            'high2_price'       => round((float)$high2['price'], 6),
            'reject_reason'     => null,
        ];
    }

    // -------------------------------------------------------------------------

    private function noCandidate(string $reason): array
    {
        return [
            'candidate_found'   => false,
            'candidate_side'    => 'short',
            'candidate_trigger' => 0.0,
            'candidate_score'   => 0.0,
            'neckline'          => 0.0,
            'high1_price'       => 0.0,
            'high2_price'       => 0.0,
            'reject_reason'     => $reason,
        ];
    }

    private function findPivots(array $candles): array
    {
        $n      = count($candles);
        $w      = self::PIVOT_WINDOW;
        $pivots = [];

        for ($i = $w; $i < $n - $w; $i++) {
            $high = (float)($candles[$i]['high'] ?? 0.0);
            $low  = (float)($candles[$i]['low']  ?? 0.0);

            $isHigh = true;
            for ($j = $i - $w; $j <= $i + $w; $j++) {
                if ($j !== $i && (float)($candles[$j]['high'] ?? 0.0) >= $high) {
                    $isHigh = false;
                    break;
                }
            }
            if ($isHigh) {
                $pivots[] = ['type' => 'high', 'price' => $high, 'idx' => $i];
                continue;
            }

            $isLow = true;
            for ($j = $i - $w; $j <= $i + $w; $j++) {
                if ($j !== $i && (float)($candles[$j]['low'] ?? 0.0) <= $low) {
                    $isLow = false;
                    break;
                }
            }
            if ($isLow) {
                $pivots[] = ['type' => 'low', 'price' => $low, 'idx' => $i];
            }
        }

        return $pivots;
    }
}
