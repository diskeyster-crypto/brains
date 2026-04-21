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
    private const DEFAULT_HIGH_TOLERANCE      = 0.05; // 5% default (crypto double-tops often differ 3–7%)
    private const DEFAULT_MIN_NECKLINE_BOUNCE = 0.005; // neckline must be at least 0.5% below the avg high
    private const MIN_PIVOT_GAP   = 4;

    /**
     * @param  array $candles  H4 candles oldest → newest
     * @param  array $config   Strategy config; reads `pattern_similarity_tolerance`
     */
    public function detect(array $candles, array $config = []): array
    {
        $highTolerance     = (float)($config['double_top_similarity_tolerance_pct']
            ?? $config['pattern_similarity_tolerance']
            ?? self::DEFAULT_HIGH_TOLERANCE);
        $minNecklineBounce = (float)($config['double_top_min_neckline_bounce_pct']
            ?? self::DEFAULT_MIN_NECKLINE_BOUNCE);

        $n = count($candles);
        if ($n < self::PIVOT_WINDOW * 2 + self::MIN_PIVOT_GAP + 2) {
            return $this->noCandidate('insufficient_candles');
        }

        $pivots = $this->findPivots($candles);
        $highs  = array_values(array_filter($pivots, fn($p) => $p['type'] === 'high'));

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
        if ($deviation > $highTolerance) {
            return $this->noCandidate('highs_not_similar');
        }

        // Neckline: lowest low directly between the two highs (no formal pivot required)
        $necklineLow = null;
        for ($k = $high1['idx'] + 1; $k < $high2['idx']; $k++) {
            $l = (float)($candles[$k]['low'] ?? 0.0);
            if ($necklineLow === null || $l < $necklineLow) {
                $necklineLow = $l;
            }
        }
        if ($necklineLow === null) {
            return $this->noCandidate('no_neckline_between_highs');
        }

        // Neckline must be meaningfully below the average high
        if ($necklineLow > $avgHigh * (1.0 - $minNecklineBounce)) {
            return $this->noCandidate('neckline_too_close');
        }

        $neckline     = $necklineLow;
        $currentClose = (float)($candles[$n - 1]['close'] ?? 0.0);

        // Price must be at or above neckline (still completing pattern or just breaking)
        if ($currentClose < $neckline * 0.99) {
            return $this->noCandidate('price_too_far_below_neckline');
        }

        $height   = abs($avgHigh - $neckline) / max(1e-8, $avgHigh);
        $symmetry = 1.0 - ($deviation / max(1e-8, $highTolerance));
        $score    = round(min(1.0, ($height * 10 + $symmetry) / 2.0), 4);

        return [
            'candidate_found'   => true,
            'candidate_side'    => 'short',
            'candidate_trigger' => round($neckline, 6),
            'candidate_score'   => $score,
            'neckline'          => round($neckline, 6),
            'high1_price'       => round((float)$high1['price'], 6),
            'high2_price'       => round((float)$high2['price'], 6),
            'window_size'       => $high2['idx'] - $high1['idx'],
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
            'window_size'       => 0,
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
