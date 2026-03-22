<?php
declare(strict_types=1);

require_once __DIR__ . '/pattern_detector_interface.php';

/**
 * Double Top Confirm V2 Detector — Smart Brain Analyzer V2
 *
 * Two-stage confirmed reversal pattern: requires setup (two nearby highs
 * after an upward movement with mid pullback) AND confirmation (price
 * breaks below a trigger level and holds without making a new high).
 *
 * Unlike V1 which fires on detecting two highs + optional post-decline,
 * V2 only signals when both setup and confirmation stages pass.
 *
 * Output: trend_bias = down, pattern_algorithm = double_top_confirm_v2
 */
final class DoubleTopConfirmV2Detector implements PatternDetectorInterface
{
    /** Maximum allowed difference between two highs (as fraction of price) */
    private float $highTolerance;

    /** Minimum bars after second high required for confirmation window */
    private int $confirmationMinBars;

    /**
     * V2 stage counters — accumulated across detect() calls.
     *
     * setup_candidates: stage 1 passed (valid double-top setup found)
     * confirmed:        stage 2 passed (confirmation after setup)
     * confirm_rejected: stage 1 passed but stage 2 failed
     */
    private int $stageSetupCandidates = 0;
    private int $stageConfirmed = 0;
    private int $stageConfirmRejected = 0;

    public function __construct(
        float $highTolerance = 0.015,
        int $confirmationMinBars = 3
    ) {
        $this->highTolerance = $highTolerance;
        $this->confirmationMinBars = $confirmationMinBars;
    }

    /**
     * Return accumulated V2 stage counters since last reset.
     *
     * @return array{setup_candidates:int,confirmed:int,confirm_rejected:int}
     */
    public function getStageCounters(): array
    {
        return [
            'setup_candidates'  => $this->stageSetupCandidates,
            'confirmed'         => $this->stageConfirmed,
            'confirm_rejected'  => $this->stageConfirmRejected,
        ];
    }

    /** Reset stage counters (call before a new analyzer run). */
    public function resetStageCounters(): void
    {
        $this->stageSetupCandidates = 0;
        $this->stageConfirmed = 0;
        $this->stageConfirmRejected = 0;
    }

    public function getName(): string
    {
        return 'double_top_confirm_v2';
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

        // Find local maxima (highs) in the segment
        $highs = $this->findLocalMaxima($segment);

        if (count($highs) < 2) {
            return null;
        }

        // Try pairs of highs (latest first) — look for best confirmed setup
        $bestResult = null;
        $setupFoundThisCall = false;
        $confirmPassedThisCall = false;

        for ($i = count($highs) - 1; $i >= 1; $i--) {
            $high2Idx = $highs[$i];
            $high1Idx = $highs[$i - 1];

            // ── Stage 1: Setup Detection ──

            $setup = $this->evaluateSetup($segment, $segLen, $high1Idx, $high2Idx);
            if ($setup === null) {
                continue;
            }

            // Stage 1 passed — count as setup candidate (once per detect call)
            if (!$setupFoundThisCall) {
                $this->stageSetupCandidates++;
                $setupFoundThisCall = true;
            }

            // ── Stage 2: Confirmation ──

            $confirmation = $this->evaluateConfirmation(
                $segment,
                $segLen,
                $high2Idx,
                $setup['avg_high'],
                $setup['trigger_level']
            );
            if ($confirmation === null) {
                continue;
            }

            // Stage 2 passed
            $confirmPassedThisCall = true;

            // ── Composite Confidence ──

            $confidence = $this->computeConfidence($setup, $confirmation);

            if ($bestResult === null || $confidence > $bestResult['confidence']) {
                $bestResult = [
                    'detected'              => true,
                    'confidence'            => round($confidence, 2),
                    'trend_bias'            => 'down',
                    'setup_found'           => true,
                    'confirmation_passed'   => true,
                    'trigger_level'         => round($setup['trigger_level'], 6),
                    'setup_high_price'      => round($setup['avg_high'], 6),
                    'neckline_price'        => round($setup['neckline'], 6),
                ];
            }
        }

        // Track confirmation outcome for this detect() call
        if ($setupFoundThisCall && $confirmPassedThisCall) {
            $this->stageConfirmed++;
        } elseif ($setupFoundThisCall && !$confirmPassedThisCall) {
            $this->stageConfirmRejected++;
        }

        return $bestResult;
    }

    /**
     * Stage 1 — Evaluate whether a valid double-top setup exists.
     *
     * @return array{closeness_score:float,pullback_score:float,avg_high:float,neckline:float,trigger_level:float}|null
     */
    private function evaluateSetup(array $segment, int $segLen, int $high1Idx, int $high2Idx): ?array
    {
        $high1Price = $segment[$high1Idx];
        $high2Price = $segment[$high2Idx];

        // Highs must be separated by at least 3 bars
        if ($high2Idx - $high1Idx < 3) {
            return null;
        }

        // Need enough room after second high for confirmation
        if ($high2Idx >= $segLen - $this->confirmationMinBars) {
            return null;
        }

        // Check tolerance: highs should be close
        $avgHigh = ($high1Price + $high2Price) / 2.0;
        if ($avgHigh <= 0.0) {
            return null;
        }
        $diff = abs($high1Price - $high2Price) / $avgHigh;
        if ($diff > $this->highTolerance) {
            return null;
        }

        // Prior upward movement (prices before high1 should be lower)
        $priorStart = max(0, $high1Idx - 5);
        $priorPrices = array_slice($segment, $priorStart, $high1Idx - $priorStart);
        if (count($priorPrices) < 2) {
            return null;
        }
        $priorAvg = array_sum($priorPrices) / count($priorPrices);
        if ($priorAvg >= $high1Price) {
            return null;
        }

        // Mid pullback between highs (neckline dip)
        $midSlice = array_slice($segment, $high1Idx, $high2Idx - $high1Idx + 1);
        $neckline = min($midSlice);
        $pullbackStrength = ($avgHigh - $neckline) / $avgHigh;

        if ($pullbackStrength < 0.002) {
            return null;
        }

        // Trigger level: midpoint between neckline and avg high
        $triggerLevel = ($neckline + $avgHigh) / 2.0;

        $closenessScore = max(0.0, 1.0 - ($diff / $this->highTolerance));
        $pullbackScore = min(1.0, $pullbackStrength / 0.03);

        return [
            'closeness_score' => $closenessScore,
            'pullback_score'  => $pullbackScore,
            'avg_high'        => $avgHigh,
            'neckline'        => $neckline,
            'trigger_level'   => $triggerLevel,
        ];
    }

    /**
     * Stage 2 — Evaluate confirmation after the second high.
     *
     * Price must break below trigger level and hold without making a new
     * high above the setup high.
     *
     * @return array{confirmation_score:float,hold_score:float}|null
     */
    private function evaluateConfirmation(
        array $segment,
        int $segLen,
        int $high2Idx,
        float $avgHigh,
        float $triggerLevel
    ): ?array {
        $confirmBars = array_slice($segment, $high2Idx + 1);
        $confirmLen = count($confirmBars);

        if ($confirmLen < $this->confirmationMinBars) {
            return null;
        }

        // Check that no bar makes a new high above the setup high
        $confirmMax = max($confirmBars);
        if ($confirmMax > $avgHigh) {
            return null;
        }

        // Price must break below the trigger level at some point
        $confirmMin = min($confirmBars);
        if ($confirmMin > $triggerLevel) {
            return null;
        }

        // Confirmation strength: how far below trigger the price reached
        $breakRange = $triggerLevel > 0.0
            ? ($triggerLevel - $confirmMin) / $triggerLevel
            : 0.0;
        $confirmationScore = min(1.0, $breakRange / 0.02);

        // Hold quality: how well price stayed below midpoint between trigger and avgHigh
        // Lower-high structure = more bars staying below the midpoint
        $holdMid = ($triggerLevel + $avgHigh) / 2.0;
        $barsBelow = 0;
        foreach ($confirmBars as $p) {
            if ($p <= $holdMid) {
                $barsBelow++;
            }
        }
        $holdScore = $confirmLen > 0 ? (float)$barsBelow / $confirmLen : 0.0;

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
            + ($setup['pullback_score'] * 0.25)
            + ($confirmation['confirmation_score'] * 0.30)
            + ($confirmation['hold_score'] * 0.20);

        return max(0.10, min(0.98, $confidence));
    }

    /**
     * Find indices of local maxima in a price series.
     *
     * @param array<int,float> $prices
     * @return array<int,int>
     */
    private function findLocalMaxima(array $prices): array
    {
        $n = count($prices);
        if ($n < 3) {
            return [];
        }

        $maxima = [];
        $window = max(2, (int)($n / 10));

        for ($i = $window; $i < $n - $window; $i++) {
            $isMax = true;
            for ($j = $i - $window; $j <= $i + $window; $j++) {
                if ($j !== $i && $prices[$j] > $prices[$i]) {
                    $isMax = false;
                    break;
                }
            }
            if ($isMax) {
                $maxima[] = $i;
            }
        }

        return $maxima;
    }
}
