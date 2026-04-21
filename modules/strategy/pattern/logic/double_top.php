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
    // Reduced from 3 → 2: requires only 2 bars on each side to confirm a swing high.
    // A window of 3 misses many valid double-top peaks in crypto H4 data (higher
    // volatility, shorter consolidations). Window of 2 finds more pairs while the
    // neckline floor and quality gate filter low-quality results downstream.
    private const PIVOT_WINDOW    = 2;
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
        // Use side-specific neckline distance tolerance when present; fall back to shared key.
        // Default 4% for double_top: crypto H4 candles can close 3-4% below the neckline on
        // the first breakdown bar, so the shared 2% default would reject valid short setups.
        $necklineDistPct   = (float)($config['double_top_neckline_distance_tolerance_pct']
            ?? $config['neckline_distance_tolerance_pct']
            ?? 0.04);

        $n = count($candles);
        if ($n < self::PIVOT_WINDOW * 2 + self::MIN_PIVOT_GAP + 2) {
            return $this->noCandidate('insufficient_candles', 0.0);
        }

        $pivots = $this->findPivots($candles);
        $highs  = array_values(array_filter($pivots, fn($p) => $p['type'] === 'high'));

        if (count($highs) < 2) {
            return $this->noCandidate('insufficient_swing_highs', 0.0);
        }

        // Search all pairs from most-recent outward to find the first valid double-top.
        // Taking only the last two pivot highs fails in bearish contexts because the two
        // most-recent pivot highs are typically lower-highs in a downtrend (naturally
        // dissimilar). The actual double-top peaks occur earlier in the lookback window.
        $count      = count($highs);
        $lastReason = 'highs_not_similar';
        $bestSimDelta = null;   // smallest deviation seen across all pairs (for diagnostics)

        $high1 = null;
        $high2 = null;
        $chosenDeviation = null;
        $necklineLow = null;
        $avgHigh = 0.0;

        $currentClose = (float)($candles[$n - 1]['close'] ?? 0.0);

        $found = false;
        for ($a = $count - 1; $a >= 1 && !$found; $a--) {
            for ($b = $a - 1; $b >= 0 && !$found; $b--) {
                $h2 = $highs[$a];
                $h1 = $highs[$b];

                if (($h2['idx'] - $h1['idx']) < self::MIN_PIVOT_GAP) {
                    continue;
                }

                $avg = ($h1['price'] + $h2['price']) / 2.0;
                $dev = abs($h1['price'] - $h2['price']) / max(1e-8, $avg);

                // Track best (smallest) deviation seen for diagnostics even on rejection
                if ($bestSimDelta === null || $dev < $bestSimDelta) {
                    $bestSimDelta = $dev;
                }

                if ($dev > $highTolerance) {
                    continue;
                }

                // Similar pair found — check neckline (lowest low between the two highs)
                $neck = null;
                for ($k = $h1['idx'] + 1; $k < $h2['idx']; $k++) {
                    $l = (float)($candles[$k]['low'] ?? 0.0);
                    if ($neck === null || $l < $neck) {
                        $neck = $l;
                    }
                }
                if ($neck === null) {
                    $lastReason = 'no_neckline_between_highs';
                    continue;
                }
                if ($neck > $avg * (1.0 - $minNecklineBounce)) {
                    $lastReason = 'neckline_too_close';
                    continue;
                }

                // Price must be at or above neckline (still completing pattern or just
                // breaking down).  Checked inside the loop so that a stale pair (where
                // price has already moved far below the neckline) is skipped and the
                // search continues for a more-recent active setup.
                if ($currentClose < $neck * (1.0 - $necklineDistPct)) {
                    $lastReason = 'price_too_far_below_neckline';
                    continue;
                }

                $high1 = $h1;
                $high2 = $h2;
                $chosenDeviation = $dev;
                $avgHigh = $avg;
                $necklineLow = $neck;
                $found = true;
            }
        }

        if (!$found) {
            return $this->noCandidate($lastReason, $bestSimDelta ?? 0.0);
        }

        $neckline = $necklineLow;

        $height   = abs($avgHigh - $neckline) / max(1e-8, $avgHigh);
        $symmetry = 1.0 - ($chosenDeviation / max(1e-8, $highTolerance));
        $score    = round(min(1.0, ($height * 10 + $symmetry) / 2.0), 4);

        return [
            'candidate_found'      => true,
            'candidate_side'       => 'short',
            'candidate_trigger'    => round($neckline, 6),
            'candidate_score'      => $score,
            'neckline'             => round($neckline, 6),
            'high1_price'          => round((float)$high1['price'], 6),
            'high2_price'          => round((float)$high2['price'], 6),
            'window_size'          => $high2['idx'] - $high1['idx'],
            'similarity_delta_pct' => round($chosenDeviation, 6),
            'reject_reason'        => null,
        ];
    }

    // -------------------------------------------------------------------------

    private function noCandidate(string $reason, float $simDeltaPct = 0.0): array
    {
        return [
            'candidate_found'      => false,
            'candidate_side'       => 'short',
            'candidate_trigger'    => 0.0,
            'candidate_score'      => 0.0,
            'neckline'             => 0.0,
            'high1_price'          => 0.0,
            'high2_price'          => 0.0,
            'window_size'          => 0,
            'similarity_delta_pct' => $simDeltaPct,
            'reject_reason'        => $reason,
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
                if ($j !== $i && (float)($candles[$j]['high'] ?? 0.0) > $high) {
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
                if ($j !== $i && (float)($candles[$j]['low'] ?? 0.0) < $low) {
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
