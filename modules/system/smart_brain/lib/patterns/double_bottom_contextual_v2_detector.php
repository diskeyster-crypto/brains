<?php
declare(strict_types=1);

require_once __DIR__ . '/pattern_detector_interface.php';

/**
 * Double Bottom Contextual V2 Detector — Smart Brain Analyzer V2
 *
 * Context-aware two-stage confirmed reversal pattern that requires Parser2
 * market context before evaluating setup and confirmation stages.
 *
 * Three phases:
 *   1. Context Gates  — validate market environment via Parser2 context
 *   2. Setup Stage    — find two nearby lows with rebound (stricter than V2)
 *   3. Confirmation   — price reclaims trigger level and holds
 *
 * Will not fire without valid context set via setContext().
 *
 * Output: trend_bias = up, pattern_algorithm = double_bottom_contextual_v2
 */
final class DoubleBottomContextualV2Detector implements PatternDetectorInterface
{
    // ── Configurable Parameters ──

    private float $minDowntrendStrength;
    private float $maxNoiseScore;
    private int   $minTrendDurationBars;
    private float $minExhaustionScore;
    private float $lowSimilarityTolerancePct;
    private float $minReboundBetweenLowsPct;
    private int   $minSpacingBarsBetweenLows;
    private int   $maxSpacingBarsBetweenLows;
    private float $maxSecondLowUndercutPct;
    private float $reclaimTriggerFraction;
    private int   $minHoldBars;
    private float $minFinalConfidence;

    // ── Parser2 Context ──

    /** @var array<string,mixed>|null */
    private ?array $context = null;

    // ── Stage Counters ──

    private int $stageSetupCandidates = 0;
    private int $stageConfirmed       = 0;
    private int $stageConfirmRejected = 0;
    private int $stageContextRejected = 0;

    // ── Reject Tracking ──

    /** @var string[] */
    private array $lastRejectReasons = [];

    public function __construct(array $params = [])
    {
        $this->minDowntrendStrength      = (float) ($params['min_downtrend_strength']        ?? 0.3);
        $this->maxNoiseScore             = (float) ($params['max_noise_score']                ?? 0.7);
        $this->minTrendDurationBars      = (int)   ($params['min_trend_duration_bars']        ?? 15);
        $this->minExhaustionScore        = (float) ($params['min_exhaustion_score']            ?? 0.2);
        $this->lowSimilarityTolerancePct = (float) ($params['low_similarity_tolerance_pct']    ?? 0.015);
        $this->minReboundBetweenLowsPct  = (float) ($params['min_rebound_between_lows_pct']   ?? 0.005);
        $this->minSpacingBarsBetweenLows = (int)   ($params['min_spacing_bars_between_lows']   ?? 5);
        $this->maxSpacingBarsBetweenLows = (int)   ($params['max_spacing_bars_between_lows']   ?? 100);
        $this->maxSecondLowUndercutPct   = (float) ($params['max_second_low_undercut_pct']     ?? 0.005);
        $this->reclaimTriggerFraction    = (float) ($params['reclaim_trigger_fraction']         ?? 0.5);
        $this->minHoldBars               = (int)   ($params['min_hold_bars']                   ?? 3);
        $this->minFinalConfidence        = (float) ($params['min_final_confidence']             ?? 0.30);
    }

    public function getName(): string
    {
        return 'double_bottom_contextual_v2';
    }

    /**
     * Set Parser2 market context for the next detect() call.
     *
     * Expected keys (accepts both V2 and canonical field names):
     *   trend_direction / regime_direction : 'down'|'weak_down'|'up'|'flat'
     *   trend_strength / regime_strength   : float 0..1
     *   noise_score                        : float 0..1
     *   trend_duration_bars / regime_duration_bars : int
     *   exhaustion_score                   : float 0..1
     *   volatility                         : float
     *
     * @param array<string,mixed> $context
     */
    public function setContext(array $context): void
    {
        $this->context = $context;
    }

    /**
     * Return accumulated stage counters since last reset.
     *
     * @return array{setup_candidates:int,confirmed:int,confirm_rejected:int,context_rejected:int}
     */
    public function getStageCounters(): array
    {
        return [
            'setup_candidates' => $this->stageSetupCandidates,
            'confirmed'        => $this->stageConfirmed,
            'confirm_rejected' => $this->stageConfirmRejected,
            'context_rejected' => $this->stageContextRejected,
        ];
    }

    /** Reset stage counters (call before a new analyzer run). */
    public function resetStageCounters(): void
    {
        $this->stageSetupCandidates = 0;
        $this->stageConfirmed       = 0;
        $this->stageConfirmRejected = 0;
        $this->stageContextRejected = 0;
    }

    /**
     * Return reject reasons accumulated during the last detect() call.
     *
     * @return string[]
     */
    public function getLastRejectReasons(): array
    {
        return $this->lastRejectReasons;
    }

    /**
     * @param array<int,array{ts_unix:int,price:float}> $history
     * @return array{detected:bool,confidence:float,trend_bias:string}|null
     */
    public function detect(array $history): ?array
    {
        $this->lastRejectReasons = [];

        // ── Context Requirement ──

        if ($this->context === null || $this->context === []) {
            $this->lastRejectReasons[] = 'reject_no_context';
            return null;
        }

        $n = count($history);
        if ($n < 30) {
            return null;
        }

        $prices = array_column($history, 'price');

        // ── Phase 1: Context Gates ──

        $contextResult = $this->evaluateContextGates($prices);
        if ($contextResult === null) {
            $this->stageContextRejected++;
            return null;
        }

        // ── Phase 2: Setup Stage ──

        $lookback = max(30, (int) ($n * 0.6));
        $segment  = array_slice($prices, $n - $lookback);
        $segLen   = count($segment);

        $lows = $this->findLocalMinima($segment);
        if (count($lows) < 2) {
            return null;
        }

        $bestResult          = null;
        $setupFoundThisCall  = false;
        $confirmPassedThisCall = false;

        for ($i = count($lows) - 1; $i >= 1; $i--) {
            $low2Idx = $lows[$i];
            $low1Idx = $lows[$i - 1];

            // ── Stage 1: Setup Detection ──

            $setup = $this->evaluateSetup($segment, $segLen, $low1Idx, $low2Idx, $contextResult);
            if ($setup === null) {
                continue;
            }

            if (!$setupFoundThisCall) {
                $this->stageSetupCandidates++;
                $setupFoundThisCall = true;
            }

            // ── Stage 2: Confirmation ──

            $confirmation = $this->evaluateConfirmation(
                $segment,
                $segLen,
                $low2Idx,
                $setup['avg_low'],
                $setup['trigger_level'],
                $setup['neckline']
            );
            if ($confirmation === null) {
                continue;
            }

            // ── Context still valid check ──

            if ($this->hasContextDeteriorated()) {
                $this->lastRejectReasons[] = 'reject_context_deteriorated';
                continue;
            }

            $confirmPassedThisCall = true;

            // ── Composite Confidence ──

            $confidence = $this->computeConfidence(
                $contextResult['context_score'],
                $setup['setup_score'],
                $confirmation['confirmation_score'],
                $confirmation['hold_score']
            );

            if ($confidence < $this->minFinalConfidence) {
                continue;
            }

            if ($bestResult === null || $confidence > $bestResult['final_confidence']) {
                $bestResult = [
                    'detected'               => true,
                    'confidence'             => round($confidence, 2),
                    'trend_bias'             => 'up',
                    'pattern_algorithm'      => 'double_bottom_contextual_v2',
                    'signal_mode'            => 'confirmed_reversal_contextual',
                    'setup_found'            => true,
                    'confirmation_passed'    => true,
                    'setup_score'            => round($setup['setup_score'], 4),
                    'confirmation_score'     => round($confirmation['confirmation_score'], 4),
                    'context_score'          => round($contextResult['context_score'], 4),
                    'final_confidence'       => round($confidence, 4),
                    'setup_low_1_price'      => round($setup['low_1_price'], 6),
                    'setup_low_2_price'      => round($setup['low_2_price'], 6),
                    'setup_neckline_price'   => round($setup['neckline'], 6),
                    'trigger_level'          => round($setup['trigger_level'], 6),
                    'reject_reasons'         => [],
                ];
            }
        }

        // ── Track stage outcomes ──

        if ($setupFoundThisCall && $confirmPassedThisCall) {
            $this->stageConfirmed++;
        } elseif ($setupFoundThisCall && !$confirmPassedThisCall) {
            $this->stageConfirmRejected++;
        }

        if ($bestResult !== null) {
            $bestResult['reject_reasons'] = $this->lastRejectReasons;
        }

        return $bestResult;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Context Gates
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Evaluate Parser2 context gates.
     *
     * @param float[] $prices  Full price array (used for computed scores)
     * @return array{context_score:float,direction:string,noise_score:float,trend_duration:int,exhaustion_score:float}|null
     */
    private function evaluateContextGates(array $prices): ?array
    {
        $ctx = $this->context;

        // Gate 1: Direction — must be downtrend (accept both V2 and canonical field names)
        $direction = (string) ($ctx['trend_direction'] ?? ($ctx['regime_direction'] ?? ''));
        if ($direction !== 'down' && $direction !== 'weak_down' && $direction !== 'bearish') {
            $this->lastRejectReasons[] = 'reject_context_not_downtrend';
            return null;
        }

        // Gate 2: Noise — market must not be too noisy
        $noiseScore = $this->resolveNoiseScore($prices);
        if ($noiseScore > $this->maxNoiseScore) {
            $this->lastRejectReasons[] = 'reject_noise_too_high';
            return null;
        }

        // Gate 3: Trend maturity — duration must be sufficient
        $trendDuration = (int) ($ctx['trend_duration_bars'] ?? ($ctx['regime_duration_bars'] ?? 0));
        if ($trendDuration < $this->minTrendDurationBars) {
            $this->lastRejectReasons[] = 'reject_trend_too_immature';
            return null;
        }

        // Gate 4: Exhaustion — downside should be weakening
        $exhaustionScore = $this->resolveExhaustionScore($prices);
        if ($exhaustionScore < $this->minExhaustionScore) {
            $this->lastRejectReasons[] = 'reject_no_exhaustion';
            return null;
        }

        // ── Context score: composite quality of context conditions ──
        $trendStrength = (float) ($ctx['trend_strength'] ?? ($ctx['regime_strength'] ?? $this->minDowntrendStrength));
        $strengthScore = min(1.0, max(0.0, $trendStrength / 1.0));
        $noiseQuality  = max(0.0, 1.0 - ($noiseScore / max(0.01, $this->maxNoiseScore)));
        $maturityScore = min(1.0, (float) $trendDuration / ($this->minTrendDurationBars * 2.0));
        $exhaustScore  = min(1.0, $exhaustionScore / 1.0);

        $contextScore = ($strengthScore * 0.25)
            + ($noiseQuality * 0.25)
            + ($maturityScore * 0.25)
            + ($exhaustScore * 0.25);

        return [
            'context_score'    => $contextScore,
            'direction'        => $direction,
            'noise_score'      => $noiseScore,
            'trend_duration'   => $trendDuration,
            'exhaustion_score' => $exhaustionScore,
        ];
    }

    /**
     * Resolve noise score: use context value if present, otherwise compute
     * from price volatility relative to directional movement.
     */
    private function resolveNoiseScore(array $prices): float
    {
        if (isset($this->context['noise_score'])) {
            return (float) $this->context['noise_score'];
        }

        $n = count($prices);
        if ($n < 3) {
            return 0.0;
        }

        // Compute from recent price data
        $window  = min($n, 50);
        $slice   = array_slice($prices, $n - $window);
        $changes = [];
        for ($i = 1, $len = count($slice); $i < $len; $i++) {
            if ($slice[$i - 1] > 0.0) {
                $changes[] = abs($slice[$i] - $slice[$i - 1]) / $slice[$i - 1];
            }
        }

        if (count($changes) === 0) {
            return 0.0;
        }

        $avgChange      = array_sum($changes) / count($changes);
        $directionalMove = abs($slice[count($slice) - 1] - $slice[0]);
        $totalPath       = 0.0;
        for ($i = 1, $len = count($slice); $i < $len; $i++) {
            $totalPath += abs($slice[$i] - $slice[$i - 1]);
        }

        // Efficiency ratio inverted: high path vs directional = noisy
        if ($totalPath <= 0.0) {
            return 0.0;
        }

        $efficiency = $directionalMove / $totalPath;

        // noise = 1 - efficiency (clamped)
        return max(0.0, min(1.0, 1.0 - $efficiency));
    }

    /**
     * Resolve exhaustion score: use context value if present, otherwise
     * compute from price data by comparing slope of first half of
     * downtrend to second half. Weaker second half = higher exhaustion.
     */
    private function resolveExhaustionScore(array $prices): float
    {
        if (isset($this->context['exhaustion_score'])) {
            return (float) $this->context['exhaustion_score'];
        }

        $trendDuration = (int) ($this->context['trend_duration_bars'] ?? 0);
        $n = count($prices);
        $window = min($n, max(10, $trendDuration));
        if ($window < 6) {
            return 0.0;
        }

        $slice = array_slice($prices, $n - $window);
        $half  = (int) (count($slice) / 2);

        $firstHalf  = array_slice($slice, 0, $half);
        $secondHalf = array_slice($slice, $half);

        if (count($firstHalf) < 2 || count($secondHalf) < 2) {
            return 0.0;
        }

        // Slope: (last - first) / count — negative for downtrend
        $slope1 = ($firstHalf[count($firstHalf) - 1] - $firstHalf[0]) / count($firstHalf);
        $slope2 = ($secondHalf[count($secondHalf) - 1] - $secondHalf[0]) / count($secondHalf);

        // Normalize by first-half price to make it scale-independent
        $refPrice = $firstHalf[0] > 0.0 ? $firstHalf[0] : 1.0;
        $normSlope1 = $slope1 / $refPrice;
        $normSlope2 = $slope2 / $refPrice;

        // Both should be negative in a downtrend; exhaustion means second is less negative
        if ($normSlope1 >= 0.0) {
            // Not a clear downtrend in first half
            return 0.0;
        }

        // Ratio: how much weaker is the second half decline?
        // If slope2 is closer to 0 (less negative) than slope1, exhaustion is high
        $deceleration = 1.0 - ($normSlope2 / $normSlope1);

        return max(0.0, min(1.0, $deceleration));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Setup Stage
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Stage 1 — Evaluate whether a valid double-bottom setup exists
     * with stricter requirements than base V2.
     *
     * @return array{setup_score:float,low_1_price:float,low_2_price:float,avg_low:float,neckline:float,trigger_level:float,low_distance_pct:float,rebound_pct:float,spacing_bars:int}|null
     */
    private function evaluateSetup(
        array $segment,
        int $segLen,
        int $low1Idx,
        int $low2Idx,
        array $contextResult
    ): ?array {
        $low1Price = $segment[$low1Idx];
        $low2Price = $segment[$low2Idx];
        $spacingBars = $low2Idx - $low1Idx;

        // Spacing constraints
        if ($spacingBars < $this->minSpacingBarsBetweenLows) {
            return null;
        }
        if ($spacingBars > $this->maxSpacingBarsBetweenLows) {
            return null;
        }

        // Need room after second low for confirmation
        if ($low2Idx >= $segLen - $this->minHoldBars) {
            return null;
        }

        $avgLow = ($low1Price + $low2Price) / 2.0;
        if ($avgLow <= 0.0) {
            return null;
        }

        // Low similarity check
        $lowDistancePct = abs($low1Price - $low2Price) / $avgLow;
        if ($lowDistancePct > $this->lowSimilarityTolerancePct) {
            $this->lastRejectReasons[] = 'reject_second_low_too_far';
            return null;
        }

        // Second low must not break too far below first low
        if ($low2Price < $low1Price) {
            $undercutPct = ($low1Price - $low2Price) / $low1Price;
            if ($undercutPct > $this->maxSecondLowUndercutPct) {
                $this->lastRejectReasons[] = 'reject_second_low_breakdown';
                return null;
            }
        }

        // Prior downward movement (prices before low1 should be higher)
        $priorStart  = max(0, $low1Idx - 5);
        $priorPrices = array_slice($segment, $priorStart, $low1Idx - $priorStart);
        if (count($priorPrices) < 2) {
            return null;
        }
        $priorAvg = array_sum($priorPrices) / count($priorPrices);
        if ($priorAvg <= $low1Price) {
            return null;
        }

        // Neckline: highest point between the two lows
        $midSlice = array_slice($segment, $low1Idx, $low2Idx - $low1Idx + 1);
        $neckline = (float) max($midSlice);

        // Rebound strength between lows
        $reboundPct = ($neckline - $avgLow) / $avgLow;
        if ($reboundPct < $this->minReboundBetweenLowsPct) {
            $this->lastRejectReasons[] = 'reject_no_valid_rebound';
            return null;
        }

        // Trigger level: reclaim_trigger_fraction of range between avg_low and neckline
        $triggerLevel = $avgLow + ($neckline - $avgLow) * $this->reclaimTriggerFraction;

        // ── Setup score ──
        $closenessScore = max(0.0, 1.0 - ($lowDistancePct / $this->lowSimilarityTolerancePct));
        $reboundScore   = min(1.0, $reboundPct / 0.03);
        $spacingScore   = 1.0 - abs($spacingBars - 20.0) / 80.0;
        $spacingScore   = max(0.0, min(1.0, $spacingScore));

        $setupScore = ($closenessScore * 0.40) + ($reboundScore * 0.35) + ($spacingScore * 0.25);

        return [
            'setup_score'       => $setupScore,
            'low_1_price'       => $low1Price,
            'low_2_price'       => $low2Price,
            'avg_low'           => $avgLow,
            'neckline'          => $neckline,
            'trigger_level'     => $triggerLevel,
            'low_distance_pct'  => $lowDistancePct,
            'rebound_pct'       => $reboundPct,
            'spacing_bars'      => $spacingBars,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Confirmation Stage
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Stage 2 — Evaluate confirmation after the second low.
     *
     * Price must reclaim above trigger level, hold for min_hold_bars,
     * and not make a new low below the setup low.
     *
     * @return array{confirmation_score:float,hold_score:float,reclaim_strength:float,hold_bars:int,hold_quality:float}|null
     */
    private function evaluateConfirmation(
        array $segment,
        int $segLen,
        int $low2Idx,
        float $avgLow,
        float $triggerLevel,
        float $neckline
    ): ?array {
        $confirmBars = array_slice($segment, $low2Idx + 1);
        $confirmLen  = count($confirmBars);

        if ($confirmLen < $this->minHoldBars) {
            return null;
        }

        // No new low below invalidation level (avg low)
        $confirmMin = min($confirmBars);
        if ($confirmMin < $avgLow) {
            $this->lastRejectReasons[] = 'reject_reclaim_failed';
            return null;
        }

        // Price must reclaim above trigger level
        $confirmMax = max($confirmBars);
        if ($confirmMax < $triggerLevel) {
            $this->lastRejectReasons[] = 'reject_no_reclaim';
            return null;
        }

        // Reclaim strength: how far above trigger the price reached
        $reclaimStrength = $triggerLevel > 0.0
            ? ($confirmMax - $triggerLevel) / $triggerLevel
            : 0.0;
        $confirmationScore = min(1.0, $reclaimStrength / 0.02);

        // Hold quality: bars above trigger level
        $barsAboveTrigger = 0;
        foreach ($confirmBars as $p) {
            if ($p >= $triggerLevel) {
                $barsAboveTrigger++;
            }
        }
        $holdQuality = $confirmLen > 0 ? (float) $barsAboveTrigger / $confirmLen : 0.0;

        // Minimum hold bars above trigger
        if ($barsAboveTrigger < $this->minHoldBars) {
            $this->lastRejectReasons[] = 'reject_hold_failed';
            return null;
        }

        // Hold score: fraction of bars that held above midpoint of avg_low → trigger
        $holdMid   = ($avgLow + $triggerLevel) / 2.0;
        $barsAbove = 0;
        foreach ($confirmBars as $p) {
            if ($p >= $holdMid) {
                $barsAbove++;
            }
        }
        $holdScore = $confirmLen > 0 ? (float) $barsAbove / $confirmLen : 0.0;

        // Require at least minimal confirmation quality
        if ($confirmationScore < 0.05 && $holdScore < 0.3) {
            $this->lastRejectReasons[] = 'reject_reclaim_failed';
            return null;
        }

        return [
            'confirmation_score' => $confirmationScore,
            'hold_score'         => $holdScore,
            'reclaim_strength'   => $reclaimStrength,
            'hold_bars'          => $barsAboveTrigger,
            'hold_quality'       => $holdQuality,
        ];
    }

    /**
     * Check whether the context has deteriorated since it was set
     * (e.g., trend reversed before confirmation completed).
     */
    private function hasContextDeteriorated(): bool
    {
        if ($this->context === null) {
            return true;
        }

        $direction = (string) ($this->context['trend_direction'] ?? '');
        if ($direction === 'up') {
            return true;
        }

        return false;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Confidence Computation
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Compute composite confidence from all scoring dimensions.
     *
     * Weights: context 0.20, setup 0.30, confirmation 0.30, hold 0.20
     */
    private function computeConfidence(
        float $contextScore,
        float $setupScore,
        float $confirmationScore,
        float $holdScore
    ): float {
        $confidence = ($contextScore * 0.20)
            + ($setupScore * 0.30)
            + ($confirmationScore * 0.30)
            + ($holdScore * 0.20);

        return max(0.10, min(0.98, $confidence));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Utilities
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Find indices of local minima in a price series.
     *
     * @param float[] $prices
     * @return int[]
     */
    private function findLocalMinima(array $prices): array
    {
        $n = count($prices);
        if ($n < 3) {
            return [];
        }

        $minima = [];
        $window = max(2, (int) ($n / 10));

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
