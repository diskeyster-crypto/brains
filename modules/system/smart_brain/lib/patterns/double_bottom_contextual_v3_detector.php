<?php
declare(strict_types=1);

require_once __DIR__ . '/pattern_detector_interface.php';

/**
 * Double Bottom Contextual V3 Detector — Smart Brain Analyzer V3
 *
 * Evolution of V2 contextual detector with richer Parser2 context integration,
 * seller-weakening scoring, configurable reclaim trigger modes, optional retest
 * requirement, and full observability via context summaries and reject reasons.
 *
 * Three phases:
 *   1. Context Gates (5 gates) — validate market environment via Parser2 canonical context
 *   2. Setup Stage             — find two nearby lows with rebound and seller weakening
 *   3. Confirmation            — price reclaims trigger level, holds, and optionally retests
 *
 * Will not fire without valid context set via setContext().
 *
 * Output: trend_bias = up, pattern_algorithm = double_bottom_contextual_v3
 */
final class DoubleBottomContextualV3Detector implements PatternDetectorInterface
{
    // ── Context Parameters ──

    private float  $minDowntrendStrength;
    private float  $maxNoiseScore;
    private int    $minTrendDurationBars;
    private float  $minTrendDepthPct;
    private float  $minExhaustionScore;
    private float  $minTrendMaturityScore;

    // ── Setup Parameters ──

    private float  $lowSimilarityTolerancePct;
    private float  $minReboundBetweenLowsPct;
    private int    $minSpacingBarsBetweenLows;
    private int    $maxSpacingBarsBetweenLows;
    private float  $maxSecondLowUndercutPct;

    // ── Confirmation Parameters ──

    private string $reclaimTriggerMode;
    private float  $reclaimTriggerFraction;
    private float  $minReclaimStrengthPct;
    private int    $minHoldBars;
    private float  $minHoldQualityScore;
    private bool   $retestRequired;
    private float  $retestTolerancePct;

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

    public function __construct(array $params = [])
    {
        // V3 context gates: STRICTER than V2 — V3 is the "regime-confirmed" pattern
        $this->minDowntrendStrength      = (float) ($params['min_downtrend_strength']        ?? 0.25);
        $this->maxNoiseScore             = (float) ($params['max_noise_score']                ?? 0.70);
        $this->minTrendDurationBars      = (int)   ($params['min_trend_duration_bars']        ?? 12);
        $this->minTrendDepthPct          = (float) ($params['min_trend_depth_pct']             ?? 0.008);
        $this->minExhaustionScore        = (float) ($params['min_exhaustion_score']            ?? 0.15);
        $this->minTrendMaturityScore     = (float) ($params['min_trend_maturity_score']        ?? 0.15);

        // Setup
        $this->lowSimilarityTolerancePct = (float) ($params['low_similarity_tolerance_pct']    ?? 0.015);
        $this->minReboundBetweenLowsPct  = (float) ($params['min_rebound_between_lows_pct']   ?? 0.005);
        $this->minSpacingBarsBetweenLows = (int)   ($params['min_spacing_bars_between_lows']   ?? 5);
        $this->maxSpacingBarsBetweenLows = (int)   ($params['max_spacing_bars_between_lows']   ?? 100);
        $this->maxSecondLowUndercutPct   = (float) ($params['max_second_low_undercut_pct']     ?? 0.005);

        // Confirmation
        $this->reclaimTriggerMode        = (string)($params['reclaim_trigger_mode']             ?? 'midpoint_weighted');
        $this->reclaimTriggerFraction    = (float) ($params['reclaim_trigger_fraction']         ?? 0.5);
        $this->minReclaimStrengthPct     = (float) ($params['min_reclaim_strength_pct']         ?? 0.005);
        $this->minHoldBars               = (int)   ($params['min_hold_bars']                   ?? 3);
        $this->minHoldQualityScore       = (float) ($params['min_hold_quality_score']           ?? 0.3);
        $this->retestRequired            = (bool)  ($params['retest_required']                  ?? false);
        $this->retestTolerancePct        = (float) ($params['retest_tolerance_pct']             ?? 0.003);

        // Confidence
        $this->minFinalConfidence        = (float) ($params['min_final_confidence']             ?? 0.30);
        $this->contextWeight             = (float) ($params['context_weight']                   ?? 0.25);
        $this->setupWeight               = (float) ($params['setup_weight']                     ?? 0.35);
        $this->confirmationWeight        = (float) ($params['confirmation_weight']              ?? 0.40);

        // Observability
        $this->debugContextSummaryEnabled = (bool) ($params['debug_context_summary_enabled']    ?? true);
        $this->emitRejectReasonEnabled    = (bool) ($params['emit_reject_reason_enabled']       ?? true);
    }

    public function getName(): string
    {
        return 'double_bottom_contextual_v3';
    }

    /**
     * Set Parser2 canonical market context for the next detect() call.
     *
     * Expected canonical keys:
     *   regime_direction       : 'down'|'bearish'|'up'|'bullish'|'flat'
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

        $lows = $this->findLocalMinima($segment);
        if (count($lows) < 2) {
            return null;
        }

        $bestResult            = null;
        $setupFoundThisCall    = false;
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

            // ── Check for fresh breakdown after setup ──

            if ($this->hasFreshBreakdownAfterSetup($segment, $segLen, $low2Idx, $setup['avg_low'])) {
                $this->addRejectReason('reject_new_low_after_setup');
                continue;
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
                $this->addRejectReason('reject_context_deteriorated');
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
                continue;
            }

            if ($bestResult === null || $confidence > $bestResult['final_confidence']) {
                $contextSummary = $this->debugContextSummaryEnabled
                    ? $this->buildContextSummary($contextResult)
                    : [];

                $bestResult = [
                    'detected'               => true,
                    'confidence'             => round($confidence, 2),
                    'trend_bias'             => 'up',
                    'pattern_algorithm'      => 'double_bottom_contextual_v3',
                    'signal_mode'            => 'contextual_confirmed_reversal',
                    'setup_found'            => true,
                    'confirmation_passed'    => true,
                    'context_score'          => round($contextResult['context_score'], 4),
                    'setup_score'            => round($setup['setup_score'], 4),
                    'confirmation_score'     => round($confirmation['confirmation_score'], 4),
                    'final_confidence'       => round($confidence, 4),
                    'setup_low_1_price'      => round($setup['low_1_price'], 6),
                    'setup_low_2_price'      => round($setup['low_2_price'], 6),
                    'setup_neckline_price'   => round($setup['neckline'], 6),
                    'trigger_level'          => round($setup['trigger_level'], 6),
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
     * Gate 1: Regime Direction — must be 'down' or 'bearish'
     * Gate 2: Regime Strength — regime_strength >= min_downtrend_strength
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

        // Gate 1: Regime Direction — must be downtrend
        $direction = (string) ($ctx['regime_direction'] ?? '');
        if ($direction !== 'down' && $direction !== 'weak_down' && $direction !== 'bearish') {
            $this->addRejectReason('reject_context_not_downtrend');
            $this->trackRejectReason('reject_context_not_downtrend', $ctx);
            return null;
        }

        // Gate 2: Regime Strength — direct gate (V3 requires stronger than V2)
        $regimeStrength = (float) ($ctx['regime_strength'] ?? 0.0);
        if ($regimeStrength < $this->minDowntrendStrength) {
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

        // Gate 5: Exhaustion — downside should be weakening (V3 requires explicit exhaustion)
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
     * downtrend to second half. Weaker second half = higher exhaustion.
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

        if ($normSlope1 >= 0.0) {
            return 0.0;
        }

        $deceleration = 1.0 - ($normSlope2 / $normSlope1);

        return max(0.0, min(1.0, $deceleration));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Setup Stage
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Evaluate whether a valid double-bottom setup exists with seller
     * weakening scoring and configurable weight distribution.
     *
     * @return array{setup_score:float,low_1_price:float,low_2_price:float,avg_low:float,neckline:float,trigger_level:float,low_distance_pct:float,rebound_pct:float,spacing_bars:int,seller_weakening_score:float}|null
     */
    private function evaluateSetup(
        array $segment,
        int $segLen,
        int $low1Idx,
        int $low2Idx,
        array $contextResult
    ): ?array {
        $low1Price   = $segment[$low1Idx];
        $low2Price   = $segment[$low2Idx];
        $spacingBars = $low2Idx - $low1Idx;

        // Spacing constraints
        if ($spacingBars < $this->minSpacingBarsBetweenLows || $spacingBars > $this->maxSpacingBarsBetweenLows) {
            $this->addRejectReason('reject_spacing_invalid');
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
            $this->addRejectReason('reject_second_low_too_far');
            return null;
        }

        // Second low must not break too far below first low (breakdown guard)
        if ($low2Price < $low1Price) {
            $undercutPct = ($low1Price - $low2Price) / $low1Price;
            if ($undercutPct > $this->maxSecondLowUndercutPct) {
                $this->addRejectReason('reject_second_low_breakdown');
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
            $this->addRejectReason('reject_no_valid_rebound');
            return null;
        }

        // Trigger level: depends on reclaim_trigger_mode
        $triggerLevel = $this->computeTriggerLevel($avgLow, $neckline);

        // ── Seller Weakening Score (V3 improvement) ──
        $sellerWeakeningScore = $this->computeSellerWeakeningScore(
            $segment,
            $low1Idx,
            $low2Idx
        );

        // ── Setup score with configurable weights ──
        $closenessScore = max(0.0, 1.0 - ($lowDistancePct / max(0.001, $this->lowSimilarityTolerancePct)));
        $reboundScore   = min(1.0, $reboundPct / 0.03);
        $spacingScore   = 1.0 - abs($spacingBars - 20.0) / 80.0;
        $spacingScore   = max(0.0, min(1.0, $spacingScore));

        $setupScore = ($closenessScore * 0.30)
            + ($reboundScore * 0.25)
            + ($spacingScore * 0.20)
            + ($sellerWeakeningScore * 0.25);

        return [
            'setup_score'             => $setupScore,
            'low_1_price'             => $low1Price,
            'low_2_price'             => $low2Price,
            'avg_low'                 => $avgLow,
            'neckline'                => $neckline,
            'trigger_level'           => $triggerLevel,
            'low_distance_pct'        => $lowDistancePct,
            'rebound_pct'             => $reboundPct,
            'spacing_bars'            => $spacingBars,
            'seller_weakening_score'  => $sellerWeakeningScore,
        ];
    }

    /**
     * Compute trigger level based on configured reclaim_trigger_mode.
     *
     * 'neckline'          — trigger is the neckline itself
     * 'midpoint_weighted' — fraction between avg_low and neckline (default)
     */
    private function computeTriggerLevel(float $avgLow, float $neckline): float
    {
        if ($this->reclaimTriggerMode === 'neckline') {
            return $neckline;
        }

        // midpoint_weighted (default)
        return $avgLow + ($neckline - $avgLow) * $this->reclaimTriggerFraction;
    }

    /**
     * Compute seller weakening score by comparing selling pressure
     * around first low vs second low. If sellers are weaker at the
     * second low, the score is higher (bullish reversal signal).
     */
    private function computeSellerWeakeningScore(
        array $segment,
        int $low1Idx,
        int $low2Idx
    ): float {
        $windowSize = 3;

        // Selling pressure around low1
        $start1 = max(0, $low1Idx - $windowSize);
        $end1   = min(count($segment) - 1, $low1Idx + $windowSize);
        $pressure1 = $this->measureSellingPressure($segment, $start1, $end1, $low1Idx);

        // Selling pressure around low2
        $start2 = max(0, $low2Idx - $windowSize);
        $end2   = min(count($segment) - 1, $low2Idx + $windowSize);
        $pressure2 = $this->measureSellingPressure($segment, $start2, $end2, $low2Idx);

        if ($pressure1 <= 0.0) {
            return 0.5;
        }

        // Ratio: if pressure2 < pressure1, sellers are weakening
        $ratio = $pressure2 / $pressure1;
        $weakening = max(0.0, min(1.0, 1.0 - $ratio));

        return $weakening;
    }

    /**
     * Measure selling pressure around a low as the average magnitude
     * of price declines in the window relative to the low price.
     */
    private function measureSellingPressure(
        array $segment,
        int $start,
        int $end,
        int $lowIdx
    ): float {
        $lowPrice = $segment[$lowIdx];
        if ($lowPrice <= 0.0) {
            return 0.0;
        }

        $totalDecline = 0.0;
        $count        = 0;

        for ($i = $start; $i < $end; $i++) {
            $change = $segment[$i] - $segment[$i + 1];
            if ($change > 0.0) {
                $totalDecline += $change / $lowPrice;
                $count++;
            }
        }

        return $count > 0 ? $totalDecline / $count : 0.0;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Confirmation Stage
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Evaluate confirmation after the second low.
     *
     * Price must reclaim above trigger level, hold for min_hold_bars
     * with sufficient quality, optionally retest, and not make a new
     * low below the setup low.
     *
     * @return array{confirmation_score:float,hold_score:float,reclaim_strength:float,hold_bars:int,hold_quality:float,retest_passed:bool}|null
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
        $confirmMin = (float) min($confirmBars);
        if ($confirmMin < $avgLow) {
            $this->addRejectReason('reject_reclaim_failed');
            return null;
        }

        // Price must reclaim above trigger level
        $confirmMax = (float) max($confirmBars);
        if ($confirmMax < $triggerLevel) {
            $this->addRejectReason('reject_no_reclaim');
            return null;
        }

        // Reclaim strength: how far above trigger the price reached
        $reclaimStrength = $triggerLevel > 0.0
            ? ($confirmMax - $triggerLevel) / $triggerLevel
            : 0.0;

        if ($reclaimStrength < $this->minReclaimStrengthPct) {
            $this->addRejectReason('reject_reclaim_failed');
            return null;
        }

        // Hold quality: fraction of bars above trigger level
        $barsAboveTrigger = 0;
        foreach ($confirmBars as $p) {
            if ($p >= $triggerLevel) {
                $barsAboveTrigger++;
            }
        }
        $holdQuality = $confirmLen > 0 ? (float) $barsAboveTrigger / $confirmLen : 0.0;

        // Minimum hold bars above trigger
        if ($barsAboveTrigger < $this->minHoldBars) {
            $this->addRejectReason('reject_hold_failed');
            return null;
        }

        // Hold quality score gate (V3 improvement)
        if ($holdQuality < $this->minHoldQualityScore) {
            $this->addRejectReason('reject_hold_failed');
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

        // ── Optional Retest Requirement (V3 improvement) ──
        $retestPassed = true;
        if ($this->retestRequired) {
            $retestPassed = $this->evaluateRetest($confirmBars, $triggerLevel, $avgLow);
            if (!$retestPassed) {
                $this->addRejectReason('reject_retest_failed');
                return null;
            }
        }

        // ── Confirmation score ──
        $reclaimScore      = min(1.0, $reclaimStrength / 0.02);
        $holdQualityScore  = min(1.0, $holdQuality);
        $holdAboveScore    = min(1.0, $holdScore);

        $confirmationScore = ($reclaimScore * 0.40)
            + ($holdQualityScore * 0.35)
            + ($holdAboveScore * 0.25);

        return [
            'confirmation_score' => $confirmationScore,
            'hold_score'         => $holdScore,
            'reclaim_strength'   => $reclaimStrength,
            'hold_bars'          => $barsAboveTrigger,
            'hold_quality'       => $holdQuality,
            'retest_passed'      => $retestPassed,
        ];
    }

    /**
     * Evaluate whether the price retested near the trigger level after
     * an initial reclaim. A valid retest dips close to the trigger
     * without breaking below avg_low, then bounces back up.
     */
    private function evaluateRetest(
        array $confirmBars,
        float $triggerLevel,
        float $avgLow
    ): bool {
        if ($triggerLevel <= 0.0) {
            return false;
        }

        $len = count($confirmBars);
        if ($len < 4) {
            return false;
        }

        $reclaimedOnce = false;
        $retestFound   = false;

        for ($i = 0; $i < $len; $i++) {
            $p = $confirmBars[$i];

            if (!$reclaimedOnce && $p >= $triggerLevel) {
                $reclaimedOnce = true;
                continue;
            }

            if ($reclaimedOnce && !$retestFound) {
                // Look for price dipping close to trigger level
                $distancePct = ($p - $triggerLevel) / $triggerLevel;
                $closeToTrigger = abs($distancePct) <= $this->retestTolerancePct
                    || ($p < $triggerLevel && $p >= $avgLow);

                if ($closeToTrigger) {
                    // Verify it bounces back above trigger
                    for ($j = $i + 1; $j < $len; $j++) {
                        if ($confirmBars[$j] >= $triggerLevel) {
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
     * Check whether price has made a fresh low below the setup low
     * after the second bottom, invalidating the pattern.
     */
    private function hasFreshBreakdownAfterSetup(
        array $segment,
        int $segLen,
        int $low2Idx,
        float $avgLow
    ): bool {
        for ($i = $low2Idx + 1; $i < $segLen; $i++) {
            if ($segment[$i] < $avgLow * (1.0 - $this->maxSecondLowUndercutPct)) {
                return true;
            }
        }
        return false;
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
        if ($direction === 'up' || $direction === 'bullish') {
            return true;
        }

        return false;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Confidence Computation
    // ═══════════════════════════════════════════════════════════════════

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
}
