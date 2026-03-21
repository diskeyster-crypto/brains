<?php
declare(strict_types=1);

require_once __DIR__ . '/pattern_detector_interface.php';

/**
 * Double Bottom Confirm V2 Detector — Smart Brain Analyzer V2
 *
 * Two-stage confirmed reversal pattern: requires setup (two nearby lows
 * after a downward movement with mid rebound) AND confirmation (price
 * reclaims above a trigger level and holds without making a new low).
 *
 * Unlike V1 which fires on detecting two lows + optional post-recovery,
 * V2 only signals when both setup and confirmation stages pass.
 *
 * Output: trend_bias = up, pattern_algorithm = double_bottom_confirm_v2
 */
final class DoubleBottomConfirmV2Detector implements PatternDetectorInterface
{
    /** Maximum allowed difference between two lows (as fraction of price) */
    private float $lowTolerance;

    /** Minimum bars after second low required for confirmation window */
    private int $confirmationMinBars;

    public function __construct(
        float $lowTolerance = 0.015,
        int $confirmationMinBars = 3
    ) {
        $this->lowTolerance = $lowTolerance;
        $this->confirmationMinBars = $confirmationMinBars;
    }

    public function getName(): string
    {
        return 'double_bottom_confirm_v2';
    }

    /**
     * @param array<int,array{ts_unix:int,price:float}> $history
     * @return array{detected:bool,confidence:float,trend_bias:string}|null
     */
    public function detect(array $history): ?array
    {
        $n = count($history);
        if ($n < 30) {
            return null;
        }

        $prices = array_column($history, 'price');

        // Look at the last 60% of data for the pattern
        $lookback = max(30, (int)($n * 0.6));
        $segment = array_slice($prices, $n - $lookback);
        $segLen = count($segment);

        // Find local minima (lows) in the segment
        $lows = $this->findLocalMinima($segment);

        if (count($lows) < 2) {
            return null;
        }

        // Try pairs of lows (latest first) — look for best confirmed setup
        $bestResult = null;

        for ($i = count($lows) - 1; $i >= 1; $i--) {
            $low2Idx = $lows[$i];
            $low1Idx = $lows[$i - 1];

            // ── Stage 1: Setup Detection ──

            $setup = $this->evaluateSetup($segment, $segLen, $low1Idx, $low2Idx);
            if ($setup === null) {
                continue;
            }

            // ── Stage 2: Confirmation ──

            $confirmation = $this->evaluateConfirmation(
                $segment,
                $segLen,
                $low2Idx,
                $setup['avg_low'],
                $setup['trigger_level']
            );
            if ($confirmation === null) {
                continue;
            }

            // ── Composite Confidence ──

            $confidence = $this->computeConfidence($setup, $confirmation);

            if ($bestResult === null || $confidence > $bestResult['confidence']) {
                $bestResult = [
                    'detected'              => true,
                    'confidence'            => round($confidence, 2),
                    'trend_bias'            => 'up',
                    'setup_found'           => true,
                    'confirmation_passed'   => true,
                    'trigger_level'         => round($setup['trigger_level'], 6),
                    'setup_low_price'       => round($setup['avg_low'], 6),
                    'neckline_price'        => round($setup['neckline'], 6),
                ];
            }
        }

        return $bestResult;
    }

    /**
     * Stage 1 — Evaluate whether a valid double-bottom setup exists.
     *
     * @return array{closeness_score:float,rebound_score:float,avg_low:float,neckline:float,trigger_level:float}|null
     */
    private function evaluateSetup(array $segment, int $segLen, int $low1Idx, int $low2Idx): ?array
    {
        $low1Price = $segment[$low1Idx];
        $low2Price = $segment[$low2Idx];

        // Lows must be separated by at least 3 bars
        if ($low2Idx - $low1Idx < 3) {
            return null;
        }

        // Need enough room after second low for confirmation
        if ($low2Idx >= $segLen - $this->confirmationMinBars) {
            return null;
        }

        // Check tolerance: lows should be close
        $avgLow = ($low1Price + $low2Price) / 2.0;
        if ($avgLow <= 0.0) {
            return null;
        }
        $diff = abs($low1Price - $low2Price) / $avgLow;
        if ($diff > $this->lowTolerance) {
            return null;
        }

        // Prior downward movement (prices before low1 should be higher)
        $priorStart = max(0, $low1Idx - 5);
        $priorPrices = array_slice($segment, $priorStart, $low1Idx - $priorStart);
        if (count($priorPrices) < 2) {
            return null;
        }
        $priorAvg = array_sum($priorPrices) / count($priorPrices);
        if ($priorAvg <= $low1Price) {
            return null;
        }

        // Mid rebound between lows (neckline bounce)
        $midSlice = array_slice($segment, $low1Idx, $low2Idx - $low1Idx + 1);
        $neckline = max($midSlice);
        $reboundStrength = ($neckline - $avgLow) / $avgLow;

        if ($reboundStrength < 0.002) {
            return null;
        }

        // Trigger level: midpoint between avg low and neckline
        $triggerLevel = ($avgLow + $neckline) / 2.0;

        $closenessScore = max(0.0, 1.0 - ($diff / $this->lowTolerance));
        $reboundScore = min(1.0, $reboundStrength / 0.03);

        return [
            'closeness_score' => $closenessScore,
            'rebound_score'   => $reboundScore,
            'avg_low'         => $avgLow,
            'neckline'        => $neckline,
            'trigger_level'   => $triggerLevel,
        ];
    }

    /**
     * Stage 2 — Evaluate confirmation after the second low.
     *
     * Price must reclaim above trigger level and hold without making a new
     * low below the setup low.
     *
     * @return array{confirmation_score:float,hold_score:float}|null
     */
    private function evaluateConfirmation(
        array $segment,
        int $segLen,
        int $low2Idx,
        float $avgLow,
        float $triggerLevel
    ): ?array {
        $confirmBars = array_slice($segment, $low2Idx + 1);
        $confirmLen = count($confirmBars);

        if ($confirmLen < $this->confirmationMinBars) {
            return null;
        }

        // Check that no bar makes a new low below the setup low
        $confirmMin = min($confirmBars);
        if ($confirmMin < $avgLow) {
            return null;
        }

        // Price must reclaim above the trigger level at some point
        $confirmMax = max($confirmBars);
        if ($confirmMax < $triggerLevel) {
            return null;
        }

        // Confirmation strength: how far above trigger the price reached
        $reclaimRange = $triggerLevel > 0.0
            ? ($confirmMax - $triggerLevel) / $triggerLevel
            : 0.0;
        $confirmationScore = min(1.0, $reclaimRange / 0.02);

        // Hold quality: how well price stayed above the setup low
        // Measured as fraction of bars that closed above midpoint between avgLow and trigger
        $holdMid = ($avgLow + $triggerLevel) / 2.0;
        $barsAbove = 0;
        foreach ($confirmBars as $p) {
            if ($p >= $holdMid) {
                $barsAbove++;
            }
        }
        $holdScore = $confirmLen > 0 ? (float)$barsAbove / $confirmLen : 0.0;

        // Require at least minimal confirmation quality
        if ($confirmationScore < 0.05 && $holdScore < 0.3) {
            return null;
        }

        return [
            'confirmation_score' => $confirmationScore,
            'hold_score'         => $holdScore,
        ];
    }

    /**
     * Compute composite confidence from setup and confirmation scores.
     */
    private function computeConfidence(array $setup, array $confirmation): float
    {
        $confidence =
            ($setup['closeness_score'] * 0.25)
            + ($setup['rebound_score'] * 0.25)
            + ($confirmation['confirmation_score'] * 0.30)
            + ($confirmation['hold_score'] * 0.20);

        return max(0.10, min(0.98, $confidence));
    }

    /**
     * Find indices of local minima in a price series.
     *
     * @param array<int,float> $prices
     * @return array<int,int>
     */
    private function findLocalMinima(array $prices): array
    {
        $n = count($prices);
        if ($n < 3) {
            return [];
        }

        $minima = [];
        $window = max(2, (int)($n / 10));

        for ($i = $window; $i < $n - $window; $i++) {
            $isMin = true;
            for ($j = $i - $window; $j <= $i + $window; $j++) {
                if ($j !== $i && $prices[$j] < $prices[$i]) {
                    $isMin = false;
                    break;
                }
            }
            if ($isMin) {
                $minima[] = $i;
            }
        }

        return $minima;
    }
}
