<?php
declare(strict_types=1);

require_once __DIR__ . '/pattern_detector_interface.php';

/**
 * Double Top Contextual V2 Detector — Smart Brain Analyzer V2
 *
 * Context-aware two-stage confirmed reversal pattern that requires Parser2
 * market context before evaluating setup and confirmation stages.
 *
 * Three phases:
 *   1. Context Gates  — validate market environment via Parser2 context
 *   2. Setup Stage    — find two nearby highs with pullback (stricter than V2)
 *   3. Confirmation   — price breaks below trigger level and holds
 *
 * Will not fire without valid context set via setContext().
 *
 * Output: trend_bias = down, pattern_algorithm = double_top_contextual_v2
 */
final class DoubleTopContextualV2Detector implements PatternDetectorInterface
{
    // ── Configurable Parameters ──

    private float $minUptrendStrength;
    private float $maxNoiseScore;
    private int   $minTrendDurationBars;
    private float $minExhaustionScore;
    private float $highSimilarityTolerancePct;
    private float $minPullbackBetweenHighsPct;
    private int   $minSpacingBarsBetweenHighs;
    private int   $maxSpacingBarsBetweenHighs;
    private float $maxSecondHighOvershootPct;
    private float $breakdownTriggerFraction;
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
    private int $stageContextPassed   = 0;
    private int $stageSymbolsChecked  = 0;

    // ── Reject Tracking ──

    /** @var string[] */
    private array $lastRejectReasons = [];

    /** @var array<string,int> Accumulated reject reason distribution across all calls in a run */
    private array $rejectReasonDistribution = [];

    /** @var array<int,array<string,mixed>> Debug preview of first N rejected context windows */
    private array $contextRejectPreview = [];
    private int   $contextRejectPreviewLimit = 5;

    /** @var array<string,int> Accumulated confirmation reject reason distribution */
    private array $confirmRejectReasonDistribution = [];

    /** @var array<int,array<string,mixed>> Debug preview of first N rejected confirmations */
    private array $confirmRejectPreview = [];
    private int   $confirmRejectPreviewLimit = 5;

    public function __construct(array $params = [])
    {
        // V2 context gates: LOOSER than V3 — V2 is the "alive contextual pattern"
        $this->minUptrendStrength         = (float) ($params['min_uptrend_strength']           ?? 0.15);
        $this->maxNoiseScore              = (float) ($params['max_noise_score']                 ?? 0.80);
        $this->minTrendDurationBars       = (int)   ($params['min_trend_duration_bars']         ?? 8);
        $this->minExhaustionScore         = (float) ($params['min_exhaustion_score']             ?? 0.05);
        // Softened: real double tops often have 2-3% difference between the two peaks
        $this->highSimilarityTolerancePct = (float) ($params['high_similarity_tolerance_pct']    ?? 0.025);
        $this->minPullbackBetweenHighsPct = (float) ($params['min_pullback_between_highs_pct']   ?? 0.005);
        $this->minSpacingBarsBetweenHighs = (int)   ($params['min_spacing_bars_between_highs']   ?? 5);
        $this->maxSpacingBarsBetweenHighs = (int)   ($params['max_spacing_bars_between_highs']   ?? 100);
        // Softened: second top can slightly exceed first by up to 1% (was 0.5%)
        $this->maxSecondHighOvershootPct  = (float) ($params['max_second_high_overshoot_pct']    ?? 0.010);
        $this->breakdownTriggerFraction   = (float) ($params['breakdown_trigger_fraction']       ?? 0.5);
        $this->minHoldBars                = (int)   ($params['min_hold_bars']                    ?? 3);
        $this->minFinalConfidence         = (float) ($params['min_final_confidence']              ?? 0.30);
    }

    public function getName(): string
    {
        return 'double_top_contextual_v2';
    }

    /**
     * Set Parser2 canonical market context for the next detect() call.
     *
     * Expected canonical keys:
     *   regime_direction       : 'up'|'weak_up'|'down'|'flat'
     *   regime_strength        : float 0..1
     *   regime_duration_bars   : int
     *   regime_depth_pct       : float (fractional, e.g. 0.15 = 15%)
     *   noise_score            : float 0..1
     *   exhaustion_score       : float 0..1
     *   trend_maturity_score   : float 0..1
     *   volatility_score       : float 0..1
     *   stretch_score          : float 0..1
     *   context_quality_score  : float 0..1
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
     * @return array{setup_candidates:int,confirmed:int,confirm_rejected:int,context_rejected:int,context_passed:int}
     */
    public function getStageCounters(): array
    {
        return [
            'setup_candidates' => $this->stageSetupCandidates,
            'confirmed'        => $this->stageConfirmed,
            'confirm_rejected' => $this->stageConfirmRejected,
            'context_rejected' => $this->stageContextRejected,
            'context_passed'   => $this->stageContextPassed,
            'symbols_checked'  => $this->stageSymbolsChecked,
        ];
    }

    /** Reset stage counters (call before a new analyzer run). */
    public function resetStageCounters(): void
    {
        $this->stageSetupCandidates    = 0;
        $this->stageConfirmed          = 0;
        $this->stageConfirmRejected    = 0;
        $this->stageContextRejected    = 0;
        $this->stageContextPassed      = 0;
        $this->stageSymbolsChecked     = 0;
        $this->rejectReasonDistribution = [];
        $this->contextRejectPreview    = [];
        $this->confirmRejectReasonDistribution = [];
        $this->confirmRejectPreview    = [];
    }

    /**
     * Return accumulated reject reason distribution across all calls in this run.
     *
     * @return array<string,int>
     */
    public function getRejectReasonDistribution(): array
    {
        return $this->rejectReasonDistribution;
    }

    /**
     * Return debug preview of first N rejected context windows.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getContextRejectPreview(): array
    {
        return $this->contextRejectPreview;
    }

    /**
     * Return accumulated confirmation reject reason distribution.
     *
     * @return array<string,int>
     */
    public function getConfirmRejectReasonDistribution(): array
    {
        return $this->confirmRejectReasonDistribution;
    }

    /**
     * Return debug preview of first N rejected confirmations.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getConfirmRejectPreview(): array
    {
        return $this->confirmRejectPreview;
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
        $this->stageSymbolsChecked++;

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

        $highs = $this->findLocalMaxima($segment);
        if (count($highs) < 2) {
            return null;
        }

        $bestResult          = null;
        $setupFoundThisCall  = false;
        $confirmPassedThisCall = false;

        for ($i = count($highs) - 1; $i >= 1; $i--) {
            $high2Idx = $highs[$i];
            $high1Idx = $highs[$i - 1];

            // ── Stage 1: Setup Detection ──

            $setup = $this->evaluateSetup($segment, $segLen, $high1Idx, $high2Idx, $contextResult);
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
                $high2Idx,
                $setup['avg_high'],
                $setup['trigger_level'],
                $setup['neckline'],
                $setup['high_1_price'],
                $setup['high_2_price']
            );
            if ($confirmation === null) {
                continue;
            }

            // ── Context still valid check ──

            if ($this->hasContextDeteriorated()) {
                $this->lastRejectReasons[] = 'reject_context_deteriorated';
                $this->trackConfirmRejectReason('reject_context_deteriorated', [
                    'detail' => 'context_reversed_during_confirmation',
                    'stage_at_failure' => 'confirmation',
                    'side' => 'short',
                    'pattern_algorithm' => 'double_top_contextual_v2',
                    'high1' => round($setup['high_1_price'], 6),
                    'high2' => round($setup['high_2_price'], 6),
                    'trigger_level' => round($setup['trigger_level'], 6),
                    'avg_high' => round($setup['avg_high'], 6),
                    'neckline' => round($setup['neckline'], 6),
                ]);
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
                $this->trackConfirmRejectReason('reject_low_confidence', [
                    'detail' => 'composite_confidence_below_min',
                    'stage_at_failure' => 'confirmation',
                    'side' => 'short',
                    'pattern_algorithm' => 'double_top_contextual_v2',
                    'confidence' => round($confidence, 4),
                    'min_final_confidence' => $this->minFinalConfidence,
                    'confirmation_score' => round($confirmation['confirmation_score'], 4),
                    'setup_score' => round($setup['setup_score'], 4),
                    'context_score' => round($contextResult['context_score'], 4),
                    'high1' => round($setup['high_1_price'], 6),
                    'high2' => round($setup['high_2_price'], 6),
                    'trigger_level' => round($setup['trigger_level'], 6),
                    'avg_high' => round($setup['avg_high'], 6),
                    'neckline' => round($setup['neckline'], 6),
                ]);
                continue;
            }

            if ($bestResult === null || $confidence > $bestResult['final_confidence']) {
                $bestResult = [
                    'detected'               => true,
                    'confidence'             => round($confidence, 2),
                    'trend_bias'             => 'down',
                    'pattern_algorithm'      => 'double_top_contextual_v2',
                    'signal_mode'            => 'confirmed_reversal_contextual',
                    'setup_found'            => true,
                    'confirmation_passed'    => true,
                    'setup_score'            => round($setup['setup_score'], 4),
                    'confirmation_score'     => round($confirmation['confirmation_score'], 4),
                    'context_score'          => round($contextResult['context_score'], 4),
                    'final_confidence'       => round($confidence, 4),
                    'setup_high_1_price'     => round($setup['high_1_price'], 6),
                    'setup_high_2_price'     => round($setup['high_2_price'], 6),
                    'setup_neckline_price'   => round($setup['neckline'], 6),
                    'trigger_level'          => round($setup['trigger_level'], 6),
                    'reject_reasons'         => [],
                    // Confirmation score component debug fields
                    'breakdown_strength_score'       => $confirmation['breakdown_strength_score'],
                    'hold_quality_score'             => $confirmation['hold_quality_score'],
                    'post_breakdown_stability_score' => $confirmation['post_breakdown_stability_score'],
                    'zone_defense_score'             => $confirmation['zone_defense_score'],
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
     * Evaluate Parser2 canonical context gates.
     *
     * V2 uses LOOSER gates than V3 — this is intentional.
     * V2 is the "alive contextual pattern" that should pass on moderately bullish regimes.
     *
     * Gate 1: Regime Direction — must be uptrend-compatible
     * Gate 2: Regime Strength — basic minimum (lower than V3)
     * Gate 3: Noise — market must not be too noisy (higher tolerance than V3)
     * Gate 4: Duration — minimum trend duration (lower than V3)
     * Gate 5: Exhaustion — soft gate (much lower than V3)
     *
     * @param float[] $prices  Full price array (used for computed scores)
     * @return array{context_score:float,direction:string,noise_score:float,trend_duration:int,exhaustion_score:float,regime_strength:float}|null
     */
    private function evaluateContextGates(array $prices): ?array
    {
        $ctx = $this->context;

        // Gate 1: Direction — must be bullish-compatible or flat-with-prior-uptrend.
        //
        // A double top (short) requires a PRIOR uptrend, not necessarily a current one.
        // At confirmation time, price may be in a 'flat' transition phase (the regime is
        // shifting from bullish to neutral as the double top plays out). 'flat' is accepted
        // when uptrend_duration_bars confirms a meaningful prior uptrend existed.
        //
        // Explicitly rejected: 'down' / 'weak_down' — these signal a bearish regime that
        // pre-dates the pattern, meaning the double top is not from a valid uptrend top.
        $direction = (string) ($ctx['regime_direction'] ?? '');
        $uptrendDurationBarsGate1 = (int) ($ctx['uptrend_duration_bars'] ?? 0);

        $isUptrend = $direction === 'up' || $direction === 'weak_up' || $direction === 'bullish';
        $isFlatWithPriorUptrend = $direction === 'flat' && $uptrendDurationBarsGate1 >= $this->minTrendDurationBars;

        if (!$isUptrend && !$isFlatWithPriorUptrend) {
            $reason = ($direction === 'flat')
                ? 'reject_context_flat_no_prior_uptrend'
                : 'reject_context_not_uptrend';
            $this->lastRejectReasons[] = $reason;
            $this->trackRejectReason($reason, $ctx);
            return null;
        }

        // Gate 2: Regime Strength — basic minimum (V2 uses lower threshold than V3)
        $regimeStrength = (float) ($ctx['regime_strength'] ?? 0.0);
        if ($regimeStrength < $this->minUptrendStrength) {
            $this->lastRejectReasons[] = 'reject_context_too_weak';
            $this->trackRejectReason('reject_context_too_weak', $ctx);
            return null;
        }

        // Gate 3: Noise — market must not be too noisy (V2 allows higher noise than V3)
        $noiseScore = $this->resolveNoiseScore($prices);
        if ($noiseScore > $this->maxNoiseScore) {
            $this->lastRejectReasons[] = 'reject_noise_too_high';
            $this->trackRejectReason('reject_noise_too_high', $ctx);
            return null;
        }

        // Gate 4: Duration — minimum trend duration (V2 uses lower minimum than V3)
        // Prefer uptrend_duration_bars (uptrend regime) if available; fall back to regime_duration_bars
        // (which measures the current bearish/pullback phase — shorter for a fresh double top).
        $uptrendDuration = (int) ($ctx['uptrend_duration_bars'] ?? 0);
        $trendDuration   = $uptrendDuration > 0 ? $uptrendDuration : (int) ($ctx['regime_duration_bars'] ?? 0);
        if ($trendDuration < $this->minTrendDurationBars) {
            $this->lastRejectReasons[] = 'reject_context_duration_too_short';
            $this->trackRejectReason('reject_context_duration_too_short', $ctx);
            return null;
        }

        // Gate 5: Exhaustion — soft gate for V2 (much lower threshold than V3)
        $exhaustionScore = $this->resolveExhaustionScore($prices);
        if ($exhaustionScore < $this->minExhaustionScore) {
            $this->lastRejectReasons[] = 'reject_no_exhaustion';
            $this->trackRejectReason('reject_no_exhaustion', $ctx);
            return null;
        }

        // ── Context passed — track passage ──
        $this->stageContextPassed++;

        // ── Context score: composite quality of context conditions ──
        $strengthScore = min(1.0, max(0.0, $regimeStrength / 1.0));
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
            'regime_strength'  => $regimeStrength,
            'noise_score'      => $noiseScore,
            'trend_duration'   => $trendDuration,
            'exhaustion_score' => $exhaustionScore,
        ];
    }

    /**
     * Track a context reject reason in the accumulated distribution and debug preview.
     *
     * @param string $reason
     * @param array<string,mixed>|null $ctx
     */
    private function trackRejectReason(string $reason, ?array $ctx = null): void
    {
        $this->rejectReasonDistribution[$reason] = ($this->rejectReasonDistribution[$reason] ?? 0) + 1;

        if ($ctx !== null && count($this->contextRejectPreview) < $this->contextRejectPreviewLimit) {
            $this->contextRejectPreview[] = [
                'reject_reason'         => $reason,
                'regime_direction'      => (string) ($ctx['regime_direction'] ?? ''),
                'regime_strength'       => round((float) ($ctx['regime_strength'] ?? 0.0), 4),
                'regime_depth_pct'      => round((float) ($ctx['regime_depth_pct'] ?? 0.0), 4),
                'regime_duration_bars'  => (int) ($ctx['regime_duration_bars'] ?? 0),
                'uptrend_duration_bars' => (int) ($ctx['uptrend_duration_bars'] ?? 0),
                'trend_maturity_score'  => round((float) ($ctx['trend_maturity_score'] ?? 0.0), 4),
                'noise_score'           => round((float) ($ctx['noise_score'] ?? 0.0), 4),
                'exhaustion_score'      => round((float) ($ctx['exhaustion_score'] ?? 0.0), 4),
                'context_quality_score' => round((float) ($ctx['context_quality_score'] ?? 0.0), 4),
            ];
        }
    }

    /**
     * Track a confirmation-stage reject reason in accumulated distribution and debug preview.
     *
     * @param string $reason
     * @param array<string,mixed> $diagnostics  Confirmation-stage diagnostic fields
     */
    private function trackConfirmRejectReason(string $reason, array $diagnostics = []): void
    {
        $this->confirmRejectReasonDistribution[$reason] = ($this->confirmRejectReasonDistribution[$reason] ?? 0) + 1;

        if (count($this->confirmRejectPreview) < $this->confirmRejectPreviewLimit) {
            $this->confirmRejectPreview[] = array_merge(
                ['reject_reason' => $reason],
                $diagnostics
            );
        }
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
     * uptrend to second half. Weaker second half = higher exhaustion.
     */
    private function resolveExhaustionScore(array $prices): float
    {
        if (isset($this->context['exhaustion_score'])) {
            return (float) $this->context['exhaustion_score'];
        }

        $trendDuration = (int) ($this->context['regime_duration_bars'] ?? 0);
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

        // Slope: (last - first) / count — positive for uptrend
        $slope1 = ($firstHalf[count($firstHalf) - 1] - $firstHalf[0]) / count($firstHalf);
        $slope2 = ($secondHalf[count($secondHalf) - 1] - $secondHalf[0]) / count($secondHalf);

        // Normalize by first-half price to make it scale-independent
        $refPrice = $firstHalf[0] > 0.0 ? $firstHalf[0] : 1.0;
        $normSlope1 = $slope1 / $refPrice;
        $normSlope2 = $slope2 / $refPrice;

        // Both should be positive in an uptrend; exhaustion means second is less positive
        if ($normSlope1 <= 0.0) {
            // Not a clear uptrend in first half
            return 0.0;
        }

        // Ratio: how much weaker is the second half advance?
        // If slope2 is closer to 0 (less positive) than slope1, exhaustion is high
        $deceleration = 1.0 - ($normSlope2 / $normSlope1);

        return max(0.0, min(1.0, $deceleration));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Setup Stage
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Stage 1 — Evaluate whether a valid double-top setup exists
     * with stricter requirements than base V2.
     *
     * @return array{setup_score:float,high_1_price:float,high_2_price:float,avg_high:float,neckline:float,trigger_level:float,high_distance_pct:float,pullback_pct:float,spacing_bars:int}|null
     */
    private function evaluateSetup(
        array $segment,
        int $segLen,
        int $high1Idx,
        int $high2Idx,
        array $contextResult
    ): ?array {
        $high1Price = $segment[$high1Idx];
        $high2Price = $segment[$high2Idx];
        $spacingBars = $high2Idx - $high1Idx;

        // Spacing constraints
        if ($spacingBars < $this->minSpacingBarsBetweenHighs) {
            return null;
        }
        if ($spacingBars > $this->maxSpacingBarsBetweenHighs) {
            return null;
        }

        // Need room after second high for confirmation
        if ($high2Idx >= $segLen - $this->minHoldBars) {
            return null;
        }

        $avgHigh = ($high1Price + $high2Price) / 2.0;
        if ($avgHigh <= 0.0) {
            return null;
        }

        // High similarity check
        $highDistancePct = abs($high1Price - $high2Price) / $avgHigh;
        if ($highDistancePct > $this->highSimilarityTolerancePct) {
            $this->lastRejectReasons[] = 'reject_second_high_too_far';
            return null;
        }

        // Second high must not break too far above first high
        if ($high2Price > $high1Price) {
            $overshootPct = ($high2Price - $high1Price) / $high1Price;
            if ($overshootPct > $this->maxSecondHighOvershootPct) {
                $this->lastRejectReasons[] = 'reject_second_high_breakout';
                return null;
            }
        }

        // Prior upward movement (prices before high1 should be lower)
        $priorStart  = max(0, $high1Idx - 5);
        $priorPrices = array_slice($segment, $priorStart, $high1Idx - $priorStart);
        if (count($priorPrices) < 2) {
            return null;
        }
        $priorAvg = array_sum($priorPrices) / count($priorPrices);
        if ($priorAvg >= $high1Price) {
            return null;
        }

        // Neckline: lowest point between the two highs
        $midSlice = array_slice($segment, $high1Idx, $high2Idx - $high1Idx + 1);
        $neckline = (float) min($midSlice);

        // Pullback strength between highs
        $pullbackPct = ($avgHigh - $neckline) / $avgHigh;
        if ($pullbackPct < $this->minPullbackBetweenHighsPct) {
            $this->lastRejectReasons[] = 'reject_no_valid_pullback';
            return null;
        }

        // Trigger level: breakdown_trigger_fraction of range between avg_high and neckline
        $triggerLevel = $avgHigh - ($avgHigh - $neckline) * $this->breakdownTriggerFraction;

        // ── Setup score ──
        $closenessScore = max(0.0, 1.0 - ($highDistancePct / $this->highSimilarityTolerancePct));
        $pullbackScore  = min(1.0, $pullbackPct / 0.03);
        $spacingScore   = 1.0 - abs($spacingBars - 20.0) / 80.0;
        $spacingScore   = max(0.0, min(1.0, $spacingScore));

        $setupScore = ($closenessScore * 0.40) + ($pullbackScore * 0.35) + ($spacingScore * 0.25);

        return [
            'setup_score'        => $setupScore,
            'high_1_price'       => $high1Price,
            'high_2_price'       => $high2Price,
            'avg_high'           => $avgHigh,
            'neckline'           => $neckline,
            'trigger_level'      => $triggerLevel,
            'high_distance_pct'  => $highDistancePct,
            'pullback_pct'       => $pullbackPct,
            'spacing_bars'       => $spacingBars,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Confirmation Stage
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Stage 2 — Evaluate confirmation after the second high.
     *
     * Price must break below trigger level, hold for min_hold_bars,
     * and not make a new high above the setup high.
     *
     * @return array{confirmation_score:float,hold_score:float,breakdown_strength:float,hold_bars:int,hold_quality:float,breakdown_strength_score:float,hold_quality_score:float,post_breakdown_stability_score:float,zone_defense_score:float}|null
     */
    private function evaluateConfirmation(
        array $segment,
        int $segLen,
        int $high2Idx,
        float $avgHigh,
        float $triggerLevel,
        float $neckline,
        float $high1Price = 0.0,
        float $high2Price = 0.0
    ): ?array {
        $confirmBars = array_slice($segment, $high2Idx + 1);
        $confirmLen  = count($confirmBars);

        // Common short-side structural context for all rejection diagnostics
        $shortStructure = [
            'stage_at_failure' => 'confirmation',
            'side' => 'short',
            'pattern_algorithm' => 'double_top_contextual_v2',
            'high1' => round($high1Price, 6),
            'high2' => round($high2Price, 6),
            'defended_zone_high' => round(max($high1Price, $high2Price), 6),
            'avg_high' => round($avgHigh, 6),
            'trigger_level' => round($triggerLevel, 6),
            'neckline' => round($neckline, 6),
        ];

        if ($confirmLen < $this->minHoldBars) {
            $this->trackConfirmRejectReason('reject_insufficient_confirm_bars', array_merge($shortStructure, [
                'detail' => 'not_enough_bars_after_setup',
                'confirm_len' => $confirmLen,
                'min_hold_bars' => $this->minHoldBars,
            ]));
            return null;
        }

        // No new high above invalidation level (avg high)
        $confirmMax = max($confirmBars);
        if ($confirmMax > $avgHigh) {
            $this->lastRejectReasons[] = 'reject_new_high_after_setup';
            $overshootPct = $avgHigh > 0.0 ? round(($confirmMax - $avgHigh) / $avgHigh, 6) : 0;
            $this->trackConfirmRejectReason('reject_new_high_after_setup', array_merge($shortStructure, [
                'detail' => 'new_high_above_avg_high',
                'confirm_max' => round($confirmMax, 6),
                'overshoot_pct' => $overshootPct,
                'post_max_price' => round($confirmMax, 6),
                'breach_above_defended_pct' => max($high1Price, $high2Price) > 0.0
                    ? round(($confirmMax - max($high1Price, $high2Price)) / max($high1Price, $high2Price), 6) : 0,
            ]));
            return null;
        }

        // Price must break below trigger level
        $confirmMin = min($confirmBars);
        if ($confirmMin > $triggerLevel) {
            $this->lastRejectReasons[] = 'reject_no_breakdown';
            $this->trackConfirmRejectReason('reject_no_breakdown', array_merge($shortStructure, [
                'detail' => 'price_never_broke_below_trigger',
                'confirm_min' => round($confirmMin, 6),
                'distance_pct' => $triggerLevel > 0 ? round(($confirmMin - $triggerLevel) / $triggerLevel, 4) : 0,
            ]));
            return null;
        }

        // ── Component 1: Breakdown Strength Score ──
        // How far below trigger the price reached, normalized to neckline range
        $breakdownStrength = $triggerLevel > 0.0
            ? ($triggerLevel - $confirmMin) / $triggerLevel
            : 0.0;
        // Use neckline-relative normalization: full range = avgHigh - neckline
        $fullRange = $avgHigh - $neckline;
        $breakdownBelowTrigger = $triggerLevel - $confirmMin;
        if ($fullRange > 0.0) {
            // Normalize breakdown against the pattern's own range for meaningful spread
            $breakdownStrengthScore = min(1.0, $breakdownBelowTrigger / $fullRange);
        } else {
            $breakdownStrengthScore = min(1.0, $breakdownStrength / 0.02);
        }

        // ── Component 2: Hold Quality Score ──
        // How consistently price stays below trigger
        $barsBelowTrigger = 0;
        foreach ($confirmBars as $p) {
            if ($p <= $triggerLevel) {
                $barsBelowTrigger++;
            }
        }
        $holdQuality = $confirmLen > 0 ? (float) $barsBelowTrigger / $confirmLen : 0.0;
        // Incorporate hold duration: more bars holding → stronger signal
        $holdDurationFactor = min(1.0, $barsBelowTrigger / max(1, $this->minHoldBars * 3));
        $holdQualityScore = ($holdQuality * 0.6) + ($holdDurationFactor * 0.4);

        // Minimum hold bars below trigger
        if ($barsBelowTrigger < $this->minHoldBars) {
            $this->lastRejectReasons[] = 'reject_hold_failed';
            $this->trackConfirmRejectReason('reject_hold_failed', array_merge($shortStructure, [
                'detail' => 'bars_below_trigger_below_min',
                'bars_below_trigger' => $barsBelowTrigger,
                'min_hold_bars' => $this->minHoldBars,
                'hold_quality' => round($holdQuality, 4),
                'confirm_min' => round($confirmMin, 6),
                'hold_started' => $barsBelowTrigger > 0 ? 'yes' : 'no',
            ]));
            return null;
        }

        // ── Component 3: Post-Breakdown Stability Score ──
        // Low variance after breakdown → stable confirmation
        $confirmMean = array_sum($confirmBars) / $confirmLen;
        $variance = 0.0;
        foreach ($confirmBars as $p) {
            $variance += ($p - $confirmMean) ** 2;
        }
        $variance /= $confirmLen;
        $stdDev = sqrt($variance);
        // Coefficient of variation: lower is more stable
        $cv = $confirmMean > 0.0 ? ($stdDev / $confirmMean) : 0.0;
        // Map: CV=0 → 1.0 (perfect stability), CV≥0.03 → 0.0 (very unstable)
        $postBreakdownStabilityScore = max(0.0, min(1.0, 1.0 - ($cv / 0.03)));

        // ── Component 4: Zone Defense Score ──
        // How well price defends trigger from below: minimum distance below trigger as fraction of range
        $minBelowTrigger = PHP_FLOAT_MAX;
        $barsDefending = 0;
        foreach ($confirmBars as $p) {
            $dist = $triggerLevel - $p;
            if ($dist >= 0.0 && $dist < $minBelowTrigger) {
                $minBelowTrigger = $dist;
            }
            // Count bars in the neckline-to-trigger zone (defending territory from below)
            if ($p <= $triggerLevel && $p >= $neckline * 0.98) {
                $barsDefending++;
            }
        }
        if ($minBelowTrigger === PHP_FLOAT_MAX) {
            $minBelowTrigger = 0.0;
        }
        // Defense margin: how close was the closest test of trigger from below
        $defenseMarginScore = ($fullRange > 0.0)
            ? min(1.0, ($minBelowTrigger / $fullRange) * 2.0)
            : ($triggerLevel > 0.0 ? min(1.0, $minBelowTrigger / ($triggerLevel * 0.01)) : 0.0);
        // Defense consistency: fraction of bars in the defended zone
        $defenseConsistency = $confirmLen > 0 ? (float) $barsDefending / $confirmLen : 0.0;
        $zoneDefenseScore = ($defenseMarginScore * 0.5) + ($defenseConsistency * 0.5);

        // Hold score: fraction of bars that held below midpoint of trigger → avg_high
        $holdMid   = ($avgHigh + $triggerLevel) / 2.0;
        $barsBelow = 0;
        foreach ($confirmBars as $p) {
            if ($p <= $holdMid) {
                $barsBelow++;
            }
        }
        $holdScore = $confirmLen > 0 ? (float) $barsBelow / $confirmLen : 0.0;

        // ── Composite confirmation_score ──
        // Weighted combination of 4 components for meaningful differentiation
        $confirmationScore = ($breakdownStrengthScore * 0.30)
            + ($holdQualityScore * 0.30)
            + ($postBreakdownStabilityScore * 0.20)
            + ($zoneDefenseScore * 0.20);

        // Require at least minimal confirmation quality
        if ($confirmationScore < 0.05 && $holdScore < 0.3) {
            $this->lastRejectReasons[] = 'reject_breakdown_not_sustained';
            $this->trackConfirmRejectReason('reject_breakdown_not_sustained', array_merge($shortStructure, [
                'detail' => 'confirmation_score_and_hold_too_low',
                'confirmation_score' => round($confirmationScore, 4),
                'hold_score' => round($holdScore, 4),
                'breakdown_strength_score' => round($breakdownStrengthScore, 4),
                'hold_quality_score' => round($holdQualityScore, 4),
                'post_breakdown_stability_score' => round($postBreakdownStabilityScore, 4),
                'zone_defense_score' => round($zoneDefenseScore, 4),
            ]));
            return null;
        }

        return [
            'confirmation_score'            => $confirmationScore,
            'hold_score'                    => $holdScore,
            'breakdown_strength'            => $breakdownStrength,
            'hold_bars'                     => $barsBelowTrigger,
            'hold_quality'                  => $holdQuality,
            'breakdown_strength_score'      => round($breakdownStrengthScore, 4),
            'hold_quality_score'            => round($holdQualityScore, 4),
            'post_breakdown_stability_score' => round($postBreakdownStabilityScore, 4),
            'zone_defense_score'            => round($zoneDefenseScore, 4),
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

        // For short (double top) patterns: a declining regime is CONFIRMATION, not deterioration.
        // Deterioration means the uptrend has STRONGLY RESUMED (pattern structurally invalidated).
        // The structural guard (price above setup highs) is already caught by reject_new_high_after_setup.
        // Only flag deterioration when the regime returned to a strong bullish state.
        $direction = (string) ($this->context['regime_direction'] ?? '');
        $strength  = (float) ($this->context['regime_strength'] ?? 0.0);

        return ($direction === 'up' || $direction === 'bullish') && $strength > 0.60;
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
     * Find indices of local maxima in a price series.
     *
     * @param float[] $prices
     * @return int[]
     */
    private function findLocalMaxima(array $prices): array
    {
        $n = count($prices);
        if ($n < 3) {
            return [];
        }

        $maxima = [];
        $window = max(2, (int) ($n / 10));

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
