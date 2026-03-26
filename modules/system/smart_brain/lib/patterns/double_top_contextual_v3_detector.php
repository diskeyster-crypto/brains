<?php
declare(strict_types=1);

require_once __DIR__ . '/pattern_detector_interface.php';

/**
 * Double Top Contextual V3 Detector — Smart Brain Analyzer V3
 *
 * SHORT-side mirror of the Double Bottom Contextual V3 Detector (LONG-side).
 *
 * Evolution of V2 contextual detector with richer Parser2 context integration,
 * buyer-weakening scoring, configurable breakdown trigger modes, optional retest
 * requirement, and full observability via context summaries and reject reasons.
 *
 * Three phases:
 *   1. Context Gates (5 gates) — validate market environment via Parser2 canonical context
 *   2. Setup Stage             — find two nearby highs with pullback and buyer weakening
 *   3. Confirmation            — price breaks below trigger level, holds, and optionally retests
 *
 * Will not fire without valid context set via setContext().
 *
 * Output: trend_bias = down, pattern_algorithm = double_top_contextual_v3
 */
final class DoubleTopContextualV3Detector implements PatternDetectorInterface
{
    // ── Context Parameters ──

    private float  $minUptrendStrength;
    private float  $maxNoiseScore;
    private int    $minTrendDurationBars;
    private float  $minTrendDepthPct;
    private float  $minExhaustionScore;
    private float  $minTrendMaturityScore;

    // ── Setup Parameters ──

    private float  $highSimilarityTolerancePct;
    private float  $minPullbackBetweenHighsPct;
    private int    $minSpacingBarsBetweenHighs;
    private int    $maxSpacingBarsBetweenHighs;
    private float  $maxSecondHighOvershootPct;

    // ── Confirmation Parameters ──

    private string $reclaimTriggerMode;
    private float  $reclaimTriggerFraction;
    private float  $minReclaimStrengthPct;
    private int    $minHoldBars;
    private float  $minHoldQualityScore;
    private bool   $retestRequired;
    private float  $retestTolerancePct;
    private float  $reclaimInvalidationTolerancePct;
    private float  $newHighAfterSetupTolerancePct;
    private float  $newHighLargeBreakThresholdPct;
    private int    $newHighSustainedBarsMin;

    // ── Confidence Parameters ──

    private float  $minFinalConfidence;
    private float  $contextWeight;
    private float  $setupWeight;
    private float  $confirmationWeight;

    // ── Observability Parameters ──

    private bool   $debugContextSummaryEnabled;
    private bool   $emitRejectReasonEnabled;

    // ── Parser2 Canonical Context ──

    /** @var array<string,mixed>|null */
    private ?array $context = null;

    // ── Stage Counters ──

    private int $stageContextRejected = 0;
    private int $stageSetupCandidates = 0;
    private int $stageConfirmed       = 0;
    private int $stageConfirmRejected = 0;
    private int $stageContextPassed   = 0;

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
        // V3 context gates
        $this->minUptrendStrength        = (float) ($params['min_uptrend_strength']            ?? 0.20);
        $this->maxNoiseScore             = (float) ($params['max_noise_score']                  ?? 0.70);
        $this->minTrendDurationBars      = (int)   ($params['min_trend_duration_bars']          ?? 12);
        $this->minTrendDepthPct          = (float) ($params['min_trend_depth_pct']               ?? 0.008);
        $this->minExhaustionScore        = (float) ($params['min_exhaustion_score']              ?? 0.15);
        $this->minTrendMaturityScore     = (float) ($params['min_trend_maturity_score']          ?? 0.15);

        // Setup
        $this->highSimilarityTolerancePct = (float) ($params['high_similarity_tolerance_pct']    ?? 0.015);
        $this->minPullbackBetweenHighsPct = (float) ($params['min_pullback_between_highs_pct']   ?? 0.005);
        $this->minSpacingBarsBetweenHighs = (int)   ($params['min_spacing_bars_between_highs']   ?? 5);
        $this->maxSpacingBarsBetweenHighs = (int)   ($params['max_spacing_bars_between_highs']   ?? 100);
        $this->maxSecondHighOvershootPct  = (float) ($params['max_second_high_overshoot_pct']    ?? 0.005);

        // Confirmation
        $this->reclaimTriggerMode        = (string)($params['reclaim_trigger_mode']              ?? 'midpoint_weighted');
        $this->reclaimTriggerFraction    = (float) ($params['reclaim_trigger_fraction']          ?? 0.5);
        $this->minReclaimStrengthPct     = (float) ($params['min_reclaim_strength_pct']          ?? 0.005);
        $this->minHoldBars               = (int)   ($params['min_hold_bars']                    ?? 3);
        $this->minHoldQualityScore       = (float) ($params['min_hold_quality_score']            ?? 0.0);
        $this->retestRequired            = (bool)  ($params['retest_required']                   ?? false);
        $this->retestTolerancePct        = (float) ($params['retest_tolerance_pct']              ?? 0.003);
        $this->reclaimInvalidationTolerancePct = (float) ($params['reclaim_invalidation_tolerance_pct'] ?? 0.003);
        $this->newHighAfterSetupTolerancePct   = (float) ($params['new_high_after_setup_tolerance_pct'] ?? 0.005);
        $this->newHighLargeBreakThresholdPct   = (float) ($params['new_high_large_break_threshold_pct'] ?? 0.035);
        $this->newHighSustainedBarsMin         = (int)   ($params['new_high_sustained_bars_min']        ?? 2);

        // Confidence
        $this->minFinalConfidence        = (float) ($params['min_final_confidence']              ?? 0.30);
        $this->contextWeight             = (float) ($params['context_weight']                    ?? 0.15);
        $this->setupWeight               = (float) ($params['setup_weight']                      ?? 0.25);
        $this->confirmationWeight        = (float) ($params['confirmation_weight']               ?? 0.60);

        // Observability
        $this->debugContextSummaryEnabled = (bool) ($params['debug_context_summary_enabled']     ?? true);
        $this->emitRejectReasonEnabled    = (bool) ($params['emit_reject_reason_enabled']        ?? true);
    }

    public function getName(): string
    {
        return 'double_top_contextual_v3';
    }

    /**
     * Set Parser2 canonical market context for the next detect() call.
     *
     * Expected canonical keys:
     *   regime_direction       : 'up'|'bullish'|'weak_up'|'down'|'flat'
     *   regime_strength        : float 0..1
     *   regime_duration_bars   : int
     *   regime_depth_pct       : float (fractional, e.g. 0.15 = 15%)
     *   noise_score            : float 0..1
     *   noise_class            : string ('low'|'medium'|'high')
     *   volatility_state       : string ('low'|'normal'|'high'|'extreme')
     *   exhaustion_score       : float 0..1
     *   trend_maturity_score   : float 0..1
     *   stretch_score          : float 0..1
     *   parser2_context_available : bool
     *   parser2_context_ts     : int (unix timestamp)
     *   parser2_context_version: string
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
     * @return array{context_rejected:int,setup_candidates:int,confirmed:int,confirm_rejected:int,context_passed:int}
     */
    public function getStageCounters(): array
    {
        return [
            'context_rejected' => $this->stageContextRejected,
            'setup_candidates' => $this->stageSetupCandidates,
            'confirmed'        => $this->stageConfirmed,
            'confirm_rejected' => $this->stageConfirmRejected,
            'context_passed'   => $this->stageContextPassed,
        ];
    }

    /** Reset stage counters (call before a new analyzer run). */
    public function resetStageCounters(): void
    {
        $this->stageContextRejected    = 0;
        $this->stageSetupCandidates    = 0;
        $this->stageConfirmed          = 0;
        $this->stageConfirmRejected    = 0;
        $this->stageContextPassed      = 0;
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

        // ── Context Requirement ──

        if ($this->context === null || $this->context === []) {
            $this->addRejectReason('reject_no_context');
            $this->stageContextRejected++;
            return null;
        }

        $n = count($history);
        if ($n < 30) {
            return null;
        }

        $prices = array_column($history, 'price');

        // ── Phase 1: Context Gates (5 gates) ──

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

        $bestResult            = null;
        $setupFoundThisCall    = false;
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

            // ── Check for fresh breakup after setup ──

            $breakupResult = $this->evaluatePostSetupBreakup(
                $segment, $segLen, $high2Idx,
                $setup['high_1_price'], $setup['high_2_price'], $setup['avg_high'],
                $setup['trigger_level']
            );
            if ($breakupResult['rejected']) {
                $this->addRejectReason('reject_new_high_after_setup');
                $this->trackConfirmRejectReason('reject_new_high_after_setup', $breakupResult['diagnostics']);
                continue;
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
                $this->addRejectReason('reject_context_deteriorated');
                $this->trackConfirmRejectReason('reject_context_deteriorated', [
                    'detail' => 'context_reversed_during_confirmation',
                    'stage_at_failure' => 'confirmation',
                    'side' => 'short',
                    'pattern_algorithm' => 'double_top_contextual_v3',
                    'high1' => round($setup['high_1_price'], 6),
                    'high2' => round($setup['high_2_price'], 6),
                    'defended_zone_high' => round(max($setup['high_1_price'], $setup['high_2_price']), 6),
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
                $confirmation['confirmation_score']
            );

            if ($confidence < $this->minFinalConfidence) {
                $this->addRejectReason('reject_low_confidence');
                $this->trackConfirmRejectReason('reject_low_confidence', [
                    'detail' => 'composite_confidence_below_min',
                    'stage_at_failure' => 'confirmation',
                    'side' => 'short',
                    'pattern_algorithm' => 'double_top_contextual_v3',
                    'confidence' => round($confidence, 4),
                    'min_final_confidence' => $this->minFinalConfidence,
                    'confirmation_score' => round($confirmation['confirmation_score'], 4),
                    'setup_score' => round($setup['setup_score'], 4),
                    'context_score' => round($contextResult['context_score'], 4),
                    'high1' => round($setup['high_1_price'], 6),
                    'high2' => round($setup['high_2_price'], 6),
                    'defended_zone_high' => round(max($setup['high_1_price'], $setup['high_2_price']), 6),
                    'trigger_level' => round($setup['trigger_level'], 6),
                    'avg_high' => round($setup['avg_high'], 6),
                    'neckline' => round($setup['neckline'], 6),
                ]);
                continue;
            }

            if ($bestResult === null || $confidence > $bestResult['final_confidence']) {
                $contextSummary = $this->debugContextSummaryEnabled
                    ? $this->buildContextSummary($contextResult)
                    : [];

                // Compute confirmation tier from the composite confirmation score
                $confirmationTier = $this->computeConfirmationTier($confirmation['confirmation_score']);

                $bestResult = [
                    'detected'               => true,
                    'confidence'             => round($confidence, 2),
                    'trend_bias'             => 'down',
                    'pattern_algorithm'      => 'double_top_contextual_v3',
                    'signal_mode'            => 'contextual_confirmed_reversal',
                    'setup_found'            => true,
                    'confirmation_passed'    => true,
                    'context_score'          => round($contextResult['context_score'], 4),
                    'setup_score'            => round($setup['setup_score'], 4),
                    'confirmation_score'     => round($confirmation['confirmation_score'], 4),
                    'confirmation_tier'      => $confirmationTier,
                    'final_confidence'       => round($confidence, 4),
                    'setup_high_1_price'     => round($setup['high_1_price'], 6),
                    'setup_high_2_price'     => round($setup['high_2_price'], 6),
                    'setup_neckline_price'   => round($setup['neckline'], 6),
                    'trigger_level'          => round($setup['trigger_level'], 6),
                    'reclaim_strength_score'       => $confirmation['reclaim_strength_score'],
                    'hold_quality_score'           => $confirmation['hold_quality_score'],
                    'post_reclaim_stability_score'  => $confirmation['post_reclaim_stability_score'],
                    'zone_defense_score'           => $confirmation['zone_defense_score'],
                    'context_summary'        => $contextSummary,
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
    //  Context Gates (5 gates)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Evaluate Parser2 canonical context gates.
     *
     * Gate 1: Regime Direction — must be 'up', 'weak_up', or 'bullish'
     * Gate 2: Regime Strength — regime_strength >= min_uptrend_strength
     * Gate 3: Noise — noise_score <= max_noise_score
     * Gate 4: Trend Maturity — regime_duration_bars >= min AND/OR regime_depth_pct >= min
     * Gate 5: Exhaustion — exhaustion_score >= min
     *
     * @param float[] $prices Full price array (used for computed scores)
     * @return array{context_score:float,direction:string,regime_strength:float,noise_score:float,trend_duration:int,regime_depth_pct:float,exhaustion_score:float,trend_maturity_score:float}|null
     */
    private function evaluateContextGates(array $prices): ?array
    {
        $ctx = $this->context;

        // Gate 1: Regime Direction — must be uptrend
        $direction = (string) ($ctx['regime_direction'] ?? '');
        if ($direction !== 'up' && $direction !== 'weak_up' && $direction !== 'bullish') {
            $this->addRejectReason('reject_context_not_uptrend');
            $this->trackRejectReason('reject_context_not_uptrend', $ctx);
            return null;
        }

        // Gate 2: Regime Strength — direct gate
        $regimeStrength = (float) ($ctx['regime_strength'] ?? 0.0);
        if ($regimeStrength < $this->minUptrendStrength) {
            $this->addRejectReason('reject_context_trend_too_weak');
            $this->trackRejectReason('reject_context_trend_too_weak', $ctx);
            return null;
        }

        // Gate 3: Noise — market must not be too noisy
        $noiseScore = $this->resolveNoiseScore($prices);
        if ($noiseScore > $this->maxNoiseScore) {
            $this->addRejectReason('reject_noise_too_high');
            $this->trackRejectReason('reject_noise_too_high', $ctx);
            return null;
        }

        // Gate 4: Trend Maturity — duration AND/OR depth must meet minimums
        $trendDuration  = (int)   ($ctx['regime_duration_bars'] ?? 0);
        $regimeDepthPct = (float) ($ctx['regime_depth_pct']     ?? 0.0);
        $trendMaturity  = (float) ($ctx['trend_maturity_score'] ?? 0.0);

        $durationMet = $trendDuration >= $this->minTrendDurationBars;
        $depthMet    = $regimeDepthPct >= $this->minTrendDepthPct;

        if (!$durationMet && !$depthMet) {
            if ($trendDuration < $this->minTrendDurationBars && $regimeDepthPct < $this->minTrendDepthPct) {
                $this->addRejectReason('reject_context_duration_too_short');
                $this->trackRejectReason('reject_context_duration_too_short', $ctx);
            }
            return null;
        }

        if ($trendMaturity < $this->minTrendMaturityScore) {
            $this->addRejectReason('reject_context_not_mature');
            $this->trackRejectReason('reject_context_not_mature', $ctx);
            return null;
        }

        // Gate 5: Exhaustion — upside should be weakening (V3 requires explicit exhaustion)
        $exhaustionScore = $this->resolveExhaustionScore($prices);
        if ($exhaustionScore < $this->minExhaustionScore) {
            $this->addRejectReason('reject_no_exhaustion');
            $this->trackRejectReason('reject_no_exhaustion', $ctx);
            return null;
        }

        // ── Context passed — track passage ──
        $this->stageContextPassed++;

        // ── Context score: composite quality of context conditions ──
        // Use adapter-supplied context_quality_score if available, else compute locally
        $adapterQuality = (float) ($ctx['context_quality_score'] ?? 0.0);

        $strengthScore   = min(1.0, max(0.0, $regimeStrength));
        $noiseQuality    = max(0.0, 1.0 - ($noiseScore / max(0.01, $this->maxNoiseScore)));
        $maturityScore   = min(1.0, (float) $trendDuration / ($this->minTrendDurationBars * 2.0));
        $depthScore      = min(1.0, $regimeDepthPct / max(0.001, $this->minTrendDepthPct * 3.0));
        $exhaustNorm     = min(1.0, $exhaustionScore);

        $contextScore = ($strengthScore * 0.20)
            + ($noiseQuality * 0.20)
            + ($maturityScore * 0.20)
            + ($depthScore * 0.20)
            + ($exhaustNorm * 0.20);

        return [
            'context_score'       => $contextScore,
            'context_quality_score' => $adapterQuality > 0.0 ? $adapterQuality : $contextScore,
            'direction'           => $direction,
            'regime_strength'     => $regimeStrength,
            'noise_score'         => $noiseScore,
            'trend_duration'      => $trendDuration,
            'regime_depth_pct'    => $regimeDepthPct,
            'exhaustion_score'    => $exhaustionScore,
            'trend_maturity_score'=> $trendMaturity,
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

        $directionalMove = abs($slice[count($slice) - 1] - $slice[0]);
        $totalPath       = 0.0;
        for ($i = 1, $len = count($slice); $i < $len; $i++) {
            $totalPath += abs($slice[$i] - $slice[$i - 1]);
        }

        if ($totalPath <= 0.0) {
            return 0.0;
        }

        $efficiency = $directionalMove / $totalPath;

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

        $slope1 = ($firstHalf[count($firstHalf) - 1] - $firstHalf[0]) / count($firstHalf);
        $slope2 = ($secondHalf[count($secondHalf) - 1] - $secondHalf[0]) / count($secondHalf);

        $refPrice = $firstHalf[0] > 0.0 ? $firstHalf[0] : 1.0;
        $normSlope1 = $slope1 / $refPrice;
        $normSlope2 = $slope2 / $refPrice;

        // For uptrend exhaustion: first half slope must be positive (rising)
        if ($normSlope1 <= 0.0) {
            return 0.0;
        }

        $deceleration = 1.0 - ($normSlope2 / $normSlope1);

        return max(0.0, min(1.0, $deceleration));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Setup Stage
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Evaluate whether a valid double-top setup exists with buyer
     * weakening scoring and configurable weight distribution.
     *
     * @return array{setup_score:float,high_1_price:float,high_2_price:float,avg_high:float,neckline:float,trigger_level:float,high_distance_pct:float,pullback_pct:float,spacing_bars:int,buyer_weakening_score:float}|null
     */
    private function evaluateSetup(
        array $segment,
        int $segLen,
        int $high1Idx,
        int $high2Idx,
        array $contextResult
    ): ?array {
        $high1Price  = $segment[$high1Idx];
        $high2Price  = $segment[$high2Idx];
        $spacingBars = $high2Idx - $high1Idx;

        // Spacing constraints
        if ($spacingBars < $this->minSpacingBarsBetweenHighs || $spacingBars > $this->maxSpacingBarsBetweenHighs) {
            $this->addRejectReason('reject_spacing_invalid');
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
            $this->addRejectReason('reject_second_high_too_far');
            return null;
        }

        // Second high must not break too far above first high (breakout guard)
        if ($high2Price > $high1Price) {
            $overshootPct = ($high2Price - $high1Price) / $high1Price;
            if ($overshootPct > $this->maxSecondHighOvershootPct) {
                $this->addRejectReason('reject_second_high_breakout');
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
            $this->addRejectReason('reject_no_valid_pullback');
            return null;
        }

        // Trigger level: depends on reclaim_trigger_mode
        $triggerLevel = $this->computeTriggerLevel($avgHigh, $neckline);

        // ── Buyer Weakening Score (V3 improvement) ──
        $buyerWeakeningScore = $this->computeBuyerWeakeningScore(
            $segment,
            $high1Idx,
            $high2Idx
        );

        // ── Setup score with configurable weights ──
        $closenessScore = max(0.0, 1.0 - ($highDistancePct / max(0.001, $this->highSimilarityTolerancePct)));
        $pullbackScore  = min(1.0, $pullbackPct / 0.03);
        $spacingScore   = 1.0 - abs($spacingBars - 20.0) / 80.0;
        $spacingScore   = max(0.0, min(1.0, $spacingScore));

        $setupScore = ($closenessScore * 0.30)
            + ($pullbackScore * 0.25)
            + ($spacingScore * 0.20)
            + ($buyerWeakeningScore * 0.25);

        return [
            'setup_score'             => $setupScore,
            'high_1_price'            => $high1Price,
            'high_2_price'            => $high2Price,
            'avg_high'                => $avgHigh,
            'neckline'                => $neckline,
            'trigger_level'           => $triggerLevel,
            'high_distance_pct'       => $highDistancePct,
            'pullback_pct'            => $pullbackPct,
            'spacing_bars'            => $spacingBars,
            'buyer_weakening_score'   => $buyerWeakeningScore,
        ];
    }

    /**
     * Compute trigger level based on configured reclaim_trigger_mode.
     *
     * 'neckline'          — trigger is the neckline itself
     * 'midpoint_weighted' — fraction between neckline and avg_high (default)
     */
    private function computeTriggerLevel(float $avgHigh, float $neckline): float
    {
        if ($this->reclaimTriggerMode === 'neckline') {
            return $neckline;
        }

        // midpoint_weighted (default): fraction from avg_high toward neckline
        return $avgHigh - ($avgHigh - $neckline) * $this->reclaimTriggerFraction;
    }

    /**
     * Compute buyer weakening score by comparing buying pressure
     * around first high vs second high. If buyers are weaker at the
     * second high, the score is higher (bearish reversal signal).
     */
    private function computeBuyerWeakeningScore(
        array $segment,
        int $high1Idx,
        int $high2Idx
    ): float {
        $windowSize = 3;

        // Buying pressure around high1
        $start1 = max(0, $high1Idx - $windowSize);
        $end1   = min(count($segment) - 1, $high1Idx + $windowSize);
        $pressure1 = $this->measureBuyingPressure($segment, $start1, $end1, $high1Idx);

        // Buying pressure around high2
        $start2 = max(0, $high2Idx - $windowSize);
        $end2   = min(count($segment) - 1, $high2Idx + $windowSize);
        $pressure2 = $this->measureBuyingPressure($segment, $start2, $end2, $high2Idx);

        if ($pressure1 <= 0.0) {
            return 0.5;
        }

        // Ratio: if pressure2 < pressure1, buyers are weakening
        $ratio = $pressure2 / $pressure1;
        $weakening = max(0.0, min(1.0, 1.0 - $ratio));

        return $weakening;
    }

    /**
     * Measure buying pressure around a high as the average magnitude
     * of price increases in the window relative to the high price.
     */
    private function measureBuyingPressure(
        array $segment,
        int $start,
        int $end,
        int $highIdx
    ): float {
        $highPrice = $segment[$highIdx];
        if ($highPrice <= 0.0) {
            return 0.0;
        }

        $totalIncrease = 0.0;
        $count         = 0;

        for ($i = $start; $i < $end; $i++) {
            $change = $segment[$i + 1] - $segment[$i];
            if ($change > 0.0) {
                $totalIncrease += $change / $highPrice;
                $count++;
            }
        }

        return $count > 0 ? $totalIncrease / $count : 0.0;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Confirmation Stage
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Evaluate confirmation after the second high.
     *
     * Price must break below trigger level, hold for min_hold_bars
     * with sufficient quality, optionally retest, and not make a new
     * high above the setup high.
     *
     * @return array{confirmation_score:float,hold_score:float,reclaim_strength:float,hold_bars:int,hold_quality:float,retest_passed:bool,reclaim_strength_score:float,hold_quality_score:float,post_reclaim_stability_score:float,zone_defense_score:float}|null
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
            'pattern_algorithm' => 'double_top_contextual_v3',
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

        // No new high above invalidation level (avg high) — with tolerance for micro-overshoots
        $confirmMax = (float) max($confirmBars);
        $invalidationBuffer = $avgHigh * $this->reclaimInvalidationTolerancePct;
        $bufferedInvalidation = $avgHigh + $invalidationBuffer;
        $overshootPct = $avgHigh > 0.0 ? ($confirmMax - $avgHigh) / $avgHigh : 0.0;

        if ($confirmMax > $bufferedInvalidation) {
            // Meaningful break: price rose well above avg_high beyond tolerance
            $this->addRejectReason('reject_reclaim_failed');
            $this->trackConfirmRejectReason('reject_reclaim_failed', array_merge($shortStructure, [
                'detail' => 'new_high_above_avg_large',
                'subtype' => 'reclaim_meaningful_break',
                'confirm_max' => round($confirmMax, 6),
                'buffered_invalidation' => round($bufferedInvalidation, 6),
                'tolerance_pct' => $this->reclaimInvalidationTolerancePct,
                'overshoot_pct' => round($overshootPct, 6),
                'post_max_price' => round($confirmMax, 6),
                'breach_above_defended_pct' => max($high1Price, $high2Price) > 0.0
                    ? round(($confirmMax - max($high1Price, $high2Price)) / max($high1Price, $high2Price), 6) : 0,
                'breach_above_buffered_pct' => $bufferedInvalidation > 0.0
                    ? round(($confirmMax - $bufferedInvalidation) / $bufferedInvalidation, 6) : 0,
            ]));
            return null;
        }

        // Price must break below trigger level
        $confirmMin = (float) min($confirmBars);
        if ($confirmMin > $triggerLevel) {
            $this->addRejectReason('reject_no_reclaim');
            $this->trackConfirmRejectReason('reject_no_reclaim', array_merge($shortStructure, [
                'detail' => 'never_reached_trigger',
                'confirm_min' => round($confirmMin, 6),
                'distance_pct' => $triggerLevel > 0 ? round(($confirmMin - $triggerLevel) / $triggerLevel, 4) : 0,
            ]));
            return null;
        }

        // Breakdown strength: how far below trigger the price reached
        $reclaimStrength = $triggerLevel > 0.0
            ? ($triggerLevel - $confirmMin) / $triggerLevel
            : 0.0;

        if ($reclaimStrength < $this->minReclaimStrengthPct) {
            $this->addRejectReason('reject_reclaim_failed');
            $this->trackConfirmRejectReason('reject_reclaim_weak', array_merge($shortStructure, [
                'detail' => 'reclaim_strength_below_min',
                'subtype' => 'reclaim_not_sustained',
                'reclaim_strength' => round($reclaimStrength, 6),
                'min_required' => $this->minReclaimStrengthPct,
            ]));
            return null;
        }

        // ── Component 1: Reclaim Strength Score ──
        // How convincingly price broke below the trigger/neckline
        // Maps breakdown strength relative to setup range
        $fullRange = $avgHigh - $neckline;
        $reclaimMagnitude = $triggerLevel - $confirmMin;
        $reclaimRatioScore = ($fullRange > 0.0)
            ? min(1.0, $reclaimMagnitude / ($fullRange * 0.5))
            : min(1.0, $reclaimStrength / 0.02);
        $reclaimPctScore = min(1.0, $reclaimStrength / 0.02);
        $reclaimStrengthScore = ($reclaimPctScore * 0.6) + ($reclaimRatioScore * 0.4);

        // Hold quality: fraction of bars below trigger level
        $barsBelowTrigger = 0;
        foreach ($confirmBars as $p) {
            if ($p <= $triggerLevel) {
                $barsBelowTrigger++;
            }
        }
        $holdQuality = $confirmLen > 0 ? (float) $barsBelowTrigger / $confirmLen : 0.0;

        // Minimum hold bars below trigger
        if ($barsBelowTrigger < $this->minHoldBars) {
            $this->addRejectReason('reject_hold_failed');
            $this->trackConfirmRejectReason('reject_hold_bars_insufficient', array_merge($shortStructure, [
                'detail' => 'bars_below_trigger_below_min',
                'bars_below_trigger' => $barsBelowTrigger,
                'min_hold_bars' => $this->minHoldBars,
                'hold_quality' => round($holdQuality, 4),
                'hold_started' => $barsBelowTrigger > 0 ? 'yes' : 'no',
            ]));
            return null;
        }

        // Hold quality score gate (soft — only rejects extreme failures when threshold > 0)
        if ($this->minHoldQualityScore > 0.0 && $holdQuality < $this->minHoldQualityScore) {
            $this->addRejectReason('reject_hold_failed');
            $this->trackConfirmRejectReason('reject_hold_quality_low', array_merge($shortStructure, [
                'detail' => 'hold_quality_below_threshold',
                'hold_quality' => round($holdQuality, 4),
                'min_hold_quality_score' => $this->minHoldQualityScore,
                'bars_below_trigger' => $barsBelowTrigger,
                'confirm_len' => $confirmLen,
            ]));
            return null;
        }

        // ── Component 2: Hold Quality Score ──
        // How well price held after breakdown (fraction + duration factor)
        $holdDurationFactor = min(1.0, $barsBelowTrigger / max(1, $this->minHoldBars * 3));
        $holdQualityScore = ($holdQuality * 0.6) + ($holdDurationFactor * 0.4);

        // Hold score: fraction of bars that held below midpoint of trigger → avg_high
        $holdMid   = ($avgHigh + $triggerLevel) / 2.0;
        $barsBelow = 0;
        foreach ($confirmBars as $p) {
            if ($p <= $holdMid) {
                $barsBelow++;
            }
        }
        $holdScore = $confirmLen > 0 ? (float) $barsBelow / $confirmLen : 0.0;

        // ── Component 3: Post-Reclaim Stability Score ──
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
        $postReclaimStabilityScore = max(0.0, min(1.0, 1.0 - ($cv / 0.03)));

        // ── Component 4: Zone Defense Score ──
        // How well price defends the top zone from below: minimum distance below trigger as fraction of range
        $minBelowTrigger = PHP_FLOAT_MAX;
        $barsDefending = 0;
        foreach ($confirmBars as $p) {
            $dist = $triggerLevel - $p;
            if ($dist >= 0.0 && $dist < $minBelowTrigger) {
                $minBelowTrigger = $dist;
            }
            // Count bars in the neckline-to-trigger zone (defending territory)
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

        // ── Optional Retest Requirement (V3 improvement) ──
        $retestPassed = true;
        if ($this->retestRequired) {
            $retestPassed = $this->evaluateRetest($confirmBars, $triggerLevel, $avgHigh);
            if (!$retestPassed) {
                $this->addRejectReason('reject_retest_failed');
                $this->trackConfirmRejectReason('reject_retest_failed', array_merge($shortStructure, [
                    'detail' => 'retest_not_found',
                    'confirm_len' => $confirmLen,
                ]));
                return null;
            }
        }

        // ── Composite confirmation_score ──
        // Weighted combination of 4 components for meaningful differentiation
        $confirmationScore = ($reclaimStrengthScore * 0.30)
            + ($holdQualityScore * 0.30)
            + ($postReclaimStabilityScore * 0.20)
            + ($zoneDefenseScore * 0.20);

        // Require at least minimal confirmation quality
        if ($confirmationScore < 0.05 && $holdScore < 0.3) {
            $this->addRejectReason('reject_low_confirmation_score');
            $this->trackConfirmRejectReason('reject_low_confirmation_score', array_merge($shortStructure, [
                'detail' => 'confirmation_score_and_hold_too_low',
                'confirmation_score' => round($confirmationScore, 4),
                'hold_score' => round($holdScore, 4),
                'reclaim_strength_score' => round($reclaimStrengthScore, 4),
                'hold_quality_score' => round($holdQualityScore, 4),
                'post_reclaim_stability_score' => round($postReclaimStabilityScore, 4),
                'zone_defense_score' => round($zoneDefenseScore, 4),
            ]));
            return null;
        }

        return [
            'confirmation_score'          => $confirmationScore,
            'hold_score'                  => $holdScore,
            'reclaim_strength'            => $reclaimStrength,
            'hold_bars'                   => $barsBelowTrigger,
            'hold_quality'                => $holdQuality,
            'retest_passed'               => $retestPassed,
            'reclaim_strength_score'      => round($reclaimStrengthScore, 4),
            'hold_quality_score'          => round($holdQualityScore, 4),
            'post_reclaim_stability_score' => round($postReclaimStabilityScore, 4),
            'zone_defense_score'          => round($zoneDefenseScore, 4),
        ];
    }

    /**
     * Evaluate whether the price retested near the trigger level after
     * an initial breakdown. A valid retest rises close to the trigger
     * without breaking above avg_high, then drops back down.
     */
    private function evaluateRetest(
        array $confirmBars,
        float $triggerLevel,
        float $avgHigh
    ): bool {
        if ($triggerLevel <= 0.0) {
            return false;
        }

        $len = count($confirmBars);
        if ($len < 4) {
            return false;
        }

        $brokenOnce  = false;
        $retestFound = false;

        for ($i = 0; $i < $len; $i++) {
            $p = $confirmBars[$i];

            if (!$brokenOnce && $p <= $triggerLevel) {
                $brokenOnce = true;
                continue;
            }

            if ($brokenOnce && !$retestFound) {
                // Look for price rising close to trigger level
                $distancePct = ($triggerLevel - $p) / $triggerLevel;
                $closeToTrigger = abs($distancePct) <= $this->retestTolerancePct
                    || ($p > $triggerLevel && $p <= $avgHigh);

                if ($closeToTrigger) {
                    // Verify it drops back below trigger
                    for ($j = $i + 1; $j < $len; $j++) {
                        if ($confirmBars[$j] <= $triggerLevel) {
                            $retestFound = true;
                            break;
                        }
                    }
                }
            }

            if ($retestFound) {
                break;
            }
        }

        return $retestFound;
    }

    /**
     * Evaluate whether price has made a fresh breakup after the second
     * top that invalidates the pattern.
     *
     * Uses max(high1, high2) as the defended-zone reference (the actual structural
     * ceiling) instead of avg_high, which can sit below high2 when high2 > high1.
     * Applies newHighAfterSetupTolerancePct as buffer.
     *
     * Stage-aware logic (mirrored from March 2026 FIX-TZ):
     *   - Checks whether a breakdown toward trigger has started before the spike
     *   - Pre-breakdown spikes are treated more leniently (moderate breaks allowed)
     *   - Post-breakdown breaks are treated strictly
     *   - Large break requires sustained bars above threshold, not just a single tick
     *
     * Returns ['rejected' => bool, 'diagnostics' => [...]] with subtypes:
     *   - new_high_after_setup_micro_sweep               (within tolerance buffer)
     *   - new_high_after_setup_moderate_break             (moderate, pre-breakdown, allowed)
     *   - new_high_after_setup_meaningful_break           (moderate but sustained or post-breakdown)
     *   - new_high_after_setup_large_break                (structural failure)
     *   - new_high_after_setup_post_reclaim_break         (break after breakdown started)
     *   - new_high_after_setup_pre_reclaim_retest_failed  (deep pre-breakdown retest, no recovery)
     *
     * @return array{rejected:bool,diagnostics:array<string,mixed>}
     */
    private function evaluatePostSetupBreakup(
        array $segment,
        int $segLen,
        int $high2Idx,
        float $high1Price,
        float $high2Price,
        float $avgHigh,
        float $triggerLevel
    ): array {
        // Use defended zone ceiling: the actual highest of the two setup highs
        $defendedZoneHigh = max($high1Price, $high2Price);

        // Buffered invalidation: only reject if price breaks meaningfully above
        $toleranceBuffer = $defendedZoneHigh * $this->newHighAfterSetupTolerancePct;
        $bufferedInvalidation = $defendedZoneHigh + $toleranceBuffer;

        // Large break threshold (configurable, default 3.5%)
        $largeBreakThreshold = $this->newHighLargeBreakThresholdPct;
        $sustainedBarsMin    = $this->newHighSustainedBarsMin;

        // ── Scan post-setup bars for stage-aware analysis ──
        $postMaxPrice = null;
        $postMaxIdx   = null;
        $reclaimStarted = false;
        $reclaimStartIdx = null;
        $holdStarted = false;
        $barsBelowTrigger = 0;
        $barsAboveDefended = 0;
        $barsAboveBuffered = 0;
        $consecutiveAboveDefended = 0;
        $maxConsecutiveAboveDefended = 0;
        $maxPriceBeforeReclaim = null;
        $maxPriceAfterReclaim  = null;
        $recoveredAfterSpike   = false;

        for ($i = $high2Idx + 1; $i < $segLen; $i++) {
            $p = $segment[$i];

            // Track breakdown start: first time price drops to trigger level
            if (!$reclaimStarted && $p <= $triggerLevel) {
                $reclaimStarted = true;
                $reclaimStartIdx = $i;
            }

            // Track hold: sustained bars below trigger
            if ($p <= $triggerLevel) {
                $barsBelowTrigger++;
                if ($barsBelowTrigger >= 2) {
                    $holdStarted = true;
                }
            }

            // Track bars above defended zone
            if ($p > $defendedZoneHigh) {
                $barsAboveDefended++;
                $consecutiveAboveDefended++;
                if ($consecutiveAboveDefended > $maxConsecutiveAboveDefended) {
                    $maxConsecutiveAboveDefended = $consecutiveAboveDefended;
                }
            } else {
                $consecutiveAboveDefended = 0;
            }

            if ($p > $bufferedInvalidation) {
                $barsAboveBuffered++;
            }

            // Track overall max
            if ($postMaxPrice === null || $p > $postMaxPrice) {
                $postMaxPrice = $p;
                $postMaxIdx = $i;
            }

            // Track max before and after breakdown
            if (!$reclaimStarted) {
                if ($maxPriceBeforeReclaim === null || $p > $maxPriceBeforeReclaim) {
                    $maxPriceBeforeReclaim = $p;
                }
            } else {
                if ($maxPriceAfterReclaim === null || $p > $maxPriceAfterReclaim) {
                    $maxPriceAfterReclaim = $p;
                }
            }
        }

        // Check if price recovered below defended zone after the spike
        if ($postMaxIdx !== null && $postMaxIdx < $segLen - 1) {
            for ($i = $postMaxIdx + 1; $i < $segLen; $i++) {
                if ($segment[$i] <= $defendedZoneHigh) {
                    $recoveredAfterSpike = true;
                    break;
                }
            }
        }

        // No bars after setup → no breakup
        if ($postMaxPrice === null) {
            return ['rejected' => false, 'diagnostics' => []];
        }

        $breachAboveDefended = $defendedZoneHigh > 0.0
            ? ($postMaxPrice - $defendedZoneHigh) / $defendedZoneHigh
            : 0.0;

        // Determine stage at which the highest point occurred
        $spikeBeforeReclaim = ($postMaxIdx !== null && $reclaimStartIdx !== null)
            ? ($postMaxIdx < $reclaimStartIdx)
            : true; // if no breakdown, spike is pre-breakdown by definition
        $stageAtFailure = $holdStarted ? 'post_hold' : ($reclaimStarted ? 'post_reclaim' : 'pre_reclaim');

        $baseDiagnostics = [
            'side' => 'short',
            'pattern_algorithm' => 'double_top_contextual_v3',
            'high1' => round($high1Price, 6),
            'high2' => round($high2Price, 6),
            'defended_zone_high' => round($defendedZoneHigh, 6),
            'buffered_invalidation' => round($bufferedInvalidation, 6),
            'post_max_price' => round($postMaxPrice, 6),
            'avg_high' => round($avgHigh, 6),
            'trigger_level' => round($triggerLevel, 6),
            'tolerance_pct' => $this->newHighAfterSetupTolerancePct,
            'large_break_threshold_pct' => $largeBreakThreshold,
            'breach_above_defended_pct' => round($breachAboveDefended, 6),
            'breach_above_buffered_pct' => $bufferedInvalidation > 0.0
                ? round(($postMaxPrice - $bufferedInvalidation) / $bufferedInvalidation, 6) : 0,
            'reclaim_started' => $reclaimStarted,
            'hold_started' => $holdStarted,
            'stage_at_failure' => $stageAtFailure,
            'spike_before_reclaim' => $spikeBeforeReclaim,
            'bars_above_defended' => $barsAboveDefended,
            'bars_above_buffered' => $barsAboveBuffered,
            'max_consecutive_above_defended' => $maxConsecutiveAboveDefended,
            'recovered_after_spike' => $recoveredAfterSpike,
        ];

        // Price stayed below the defended zone → no breakup at all
        if ($postMaxPrice <= $defendedZoneHigh) {
            return ['rejected' => false, 'diagnostics' => $baseDiagnostics];
        }

        // Price spiked above defended zone but stayed within tolerance buffer → micro sweep (allowed)
        if ($postMaxPrice <= $bufferedInvalidation) {
            return [
                'rejected' => false,
                'diagnostics' => array_merge($baseDiagnostics, [
                    'detail' => 'micro_sweep_within_tolerance',
                    'subtype' => 'new_high_after_setup_micro_sweep',
                ]),
            ];
        }

        // ── Price broke above buffered invalidation → stage-aware severity ──

        // Case 1: Post-breakdown break (price broke below trigger, then spiked above → serious)
        if ($reclaimStarted && $maxPriceAfterReclaim !== null && $maxPriceAfterReclaim > $bufferedInvalidation) {
            return [
                'rejected' => true,
                'diagnostics' => array_merge($baseDiagnostics, [
                    'detail' => 'price_broke_above_defended_zone_after_reclaim',
                    'subtype' => 'new_high_after_setup_post_reclaim_break',
                ]),
            ];
        }

        // Case 2: Large structural break (high breach % AND sustained bars above)
        if ($breachAboveDefended > $largeBreakThreshold
            && $maxConsecutiveAboveDefended >= $sustainedBarsMin) {
            return [
                'rejected' => true,
                'diagnostics' => array_merge($baseDiagnostics, [
                    'detail' => 'price_broke_well_above_defended_zone_sustained',
                    'subtype' => 'new_high_after_setup_large_break',
                ]),
            ];
        }

        // Case 3: Pre-breakdown moderate break that recovered → allowed
        // If spike happened before any breakdown started and price recovered, treat leniently
        if ($spikeBeforeReclaim && $recoveredAfterSpike && $breachAboveDefended <= $largeBreakThreshold) {
            return [
                'rejected' => false,
                'diagnostics' => array_merge($baseDiagnostics, [
                    'detail' => 'pre_reclaim_spike_recovered',
                    'subtype' => 'new_high_after_setup_moderate_break',
                ]),
            ];
        }

        // Case 4: Pre-breakdown deep retest that did NOT recover → reject
        if ($spikeBeforeReclaim && !$recoveredAfterSpike) {
            return [
                'rejected' => true,
                'diagnostics' => array_merge($baseDiagnostics, [
                    'detail' => 'pre_reclaim_deep_retest_no_recovery',
                    'subtype' => 'new_high_after_setup_pre_reclaim_retest_failed',
                ]),
            ];
        }

        // Case 5: Moderate break — between micro_sweep and large_break
        // Single tick / brief spike without sustained breakout → meaningful but not fatal
        if ($breachAboveDefended <= $largeBreakThreshold
            && $maxConsecutiveAboveDefended < $sustainedBarsMin) {
            return [
                'rejected' => false,
                'diagnostics' => array_merge($baseDiagnostics, [
                    'detail' => 'moderate_breach_not_sustained',
                    'subtype' => 'new_high_after_setup_moderate_break',
                ]),
            ];
        }

        // Default: meaningful break (sustained moderate breach beyond buffer)
        return [
            'rejected' => true,
            'diagnostics' => array_merge($baseDiagnostics, [
                'detail' => 'price_broke_above_buffered_invalidation',
                'subtype' => 'new_high_after_setup_meaningful_break',
            ]),
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

        $direction = (string) ($this->context['regime_direction'] ?? '');
        if ($direction === 'down' || $direction === 'bearish') {
            return true;
        }

        return false;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Confidence Computation
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Map V3 confirmation_score into a tier label.
     *
     * V3 thresholds:
     *   weak:        confirmation_score < 0.50
     *   medium:      0.50 <= confirmation_score < 0.75
     *   strong:      0.75 <= confirmation_score < 0.90
     *   very_strong: confirmation_score >= 0.90
     */
    private function computeConfirmationTier(float $confirmationScore): string
    {
        if ($confirmationScore >= 0.90) {
            return 'very_strong';
        }
        if ($confirmationScore >= 0.75) {
            return 'strong';
        }
        if ($confirmationScore >= 0.50) {
            return 'medium';
        }
        return 'weak';
    }

    /**
     * Compute composite confidence from context, setup, and confirmation
     * using configurable weights.
     */
    private function computeConfidence(
        float $contextScore,
        float $setupScore,
        float $confirmationScore
    ): float {
        $confidence = ($contextScore * $this->contextWeight)
            + ($setupScore * $this->setupWeight)
            + ($confirmationScore * $this->confirmationWeight);

        return max(0.10, min(0.98, $confidence));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Context Summary
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Build a human-readable context summary for observability output.
     *
     * @return array<string,mixed>
     */
    private function buildContextSummary(array $contextResult): array
    {
        $ctx = $this->context ?? [];

        return [
            'regime_direction'      => $contextResult['direction'],
            'regime_strength'       => round($contextResult['regime_strength'], 4),
            'regime_duration_bars'  => $contextResult['trend_duration'],
            'regime_depth_pct'      => round($contextResult['regime_depth_pct'], 4),
            'noise_score'           => round($contextResult['noise_score'], 4),
            'noise_class'           => (string) ($ctx['noise_class'] ?? 'unknown'),
            'volatility_state'      => (string) ($ctx['volatility_state'] ?? 'unknown'),
            'volatility_score'      => round((float) ($ctx['volatility_score'] ?? 0.0), 4),
            'exhaustion_score'      => round($contextResult['exhaustion_score'], 4),
            'trend_maturity_score'  => round($contextResult['trend_maturity_score'], 4),
            'stretch_score'         => round((float) ($ctx['stretch_score'] ?? 0.0), 4),
            'local_structure_score' => round((float) ($ctx['local_structure_score'] ?? 0.0), 4),
            'context_quality_score' => round((float) ($contextResult['context_quality_score'] ?? 0.0), 4),
            'context_version'       => (string) ($ctx['source_version'] ?? ($ctx['parser2_context_version'] ?? 'unknown')),
        ];
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

    /**
     * Add a reject reason if emit_reject_reason_enabled is true.
     */
    private function addRejectReason(string $reason): void
    {
        if ($this->emitRejectReasonEnabled) {
            $this->lastRejectReasons[] = $reason;
        }
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
                'reject_reason'       => $reason,
                'regime_direction'    => (string) ($ctx['regime_direction'] ?? ''),
                'regime_strength'     => round((float) ($ctx['regime_strength'] ?? 0.0), 4),
                'regime_depth_pct'    => round((float) ($ctx['regime_depth_pct'] ?? 0.0), 4),
                'regime_duration_bars'=> (int) ($ctx['regime_duration_bars'] ?? 0),
                'trend_maturity_score'=> round((float) ($ctx['trend_maturity_score'] ?? 0.0), 4),
                'noise_score'         => round((float) ($ctx['noise_score'] ?? 0.0), 4),
                'exhaustion_score'    => round((float) ($ctx['exhaustion_score'] ?? 0.0), 4),
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
}
