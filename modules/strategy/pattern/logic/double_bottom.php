<?php

declare(strict_types=1);

/**
 * Pattern Strategy — Double Bottom Detector
 *
 * Identifies a double-bottom pattern candidate (long bias) from H4 candles.
 *
 * Rules:
 *   1. Find the two most recent swing lows.
 *   2. The two lows must be within a tolerance of each other (similar price level).
 *   3. There must be an intervening swing high (the "neckline") between the two lows.
 *   4. Current price must be above or near the neckline (approaching breakout).
 *
 * Outputs:
 *   candidate_found      bool
 *   candidate_side       'long'
 *   candidate_trigger    float  (neckline price — breakout level)
 *   candidate_score      float  0.0–1.0
 *   neckline             float
 *   low1_price           float
 *   low2_price           float
 *   reject_reason        string | null
 */

namespace Modules\Strategy\Pattern\Logic;

final class PatternDoubleBottom
{
    private const PIVOT_WINDOW   = 3;    // bars each side for pivot detection
    private const LOW_TOLERANCE  = 0.015; // 1.5% price similarity between the two lows
    private const MIN_PIVOT_GAP  = 4;    // minimum bars between the two lows

    public function detect(array $candles): array
    {
        $n = count($candles);
        if ($n < self::PIVOT_WINDOW * 2 + self::MIN_PIVOT_GAP + 2) {
            return $this->noCandidate('insufficient_candles');
        }

        $pivots = $this->findPivots($candles);
        $lows   = array_values(array_filter($pivots, fn($p) => $p['type'] === 'low'));
        $highs  = array_values(array_filter($pivots, fn($p) => $p['type'] === 'high'));

        if (count($lows) < 2) {
            return $this->noCandidate('insufficient_swing_lows');
        }

        // Take last two lows
        $low2 = $lows[count($lows) - 1];  // more recent
        $low1 = $lows[count($lows) - 2];  // older

        // Gap check
        if (($low2['idx'] - $low1['idx']) < self::MIN_PIVOT_GAP) {
            return $this->noCandidate('lows_too_close');
        }

        // Similarity check
        $avgLow    = ($low1['price'] + $low2['price']) / 2.0;
        $deviation = abs($low1['price'] - $low2['price']) / max(1e-8, $avgLow);
        if ($deviation > self::LOW_TOLERANCE) {
            return $this->noCandidate('lows_not_similar');
        }

        // Find neckline: highest swing high between the two lows
        $necklineHigh = null;
        foreach ($highs as $h) {
            if ($h['idx'] > $low1['idx'] && $h['idx'] < $low2['idx']) {
                if ($necklineHigh === null || $h['price'] > $necklineHigh['price']) {
                    $necklineHigh = $h;
                }
            }
        }
        if ($necklineHigh === null) {
            return $this->noCandidate('no_neckline_between_lows');
        }

        $neckline     = (float)$necklineHigh['price'];
        $currentClose = (float)($candles[$n - 1]['close'] ?? 0.0);

        // Price must be at or below neckline (still completing the pattern or just breaking)
        if ($currentClose > $neckline * 1.01) {
            return $this->noCandidate('price_too_far_above_neckline');
        }

        // Score: symmetry + depth
        $depth = abs($neckline - $avgLow) / max(1e-8, $neckline);  // how deep the "W"
        $symmetry = 1.0 - ($deviation / self::LOW_TOLERANCE);       // 1 = perfect symmetry
        $score    = round(min(1.0, ($depth * 10 + $symmetry) / 2.0), 4);

        return [
            'candidate_found'   => true,
            'candidate_side'    => 'long',
            'candidate_trigger' => round($neckline, 6),
            'candidate_score'   => $score,
            'neckline'          => round($neckline, 6),
            'low1_price'        => round((float)$low1['price'], 6),
            'low2_price'        => round((float)$low2['price'], 6),
            'reject_reason'     => null,
        ];
    }

    // -------------------------------------------------------------------------

    private function noCandidate(string $reason): array
    {
        return [
            'candidate_found'   => false,
            'candidate_side'    => 'long',
            'candidate_trigger' => 0.0,
            'candidate_score'   => 0.0,
            'neckline'          => 0.0,
            'low1_price'        => 0.0,
            'low2_price'        => 0.0,
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
