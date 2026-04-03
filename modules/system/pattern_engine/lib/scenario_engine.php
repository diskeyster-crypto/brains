<?php
declare(strict_types=1);

namespace PatternEngine;

if (defined('PATTERN_ENGINE_SCENARIO_ENGINE_LOADED')) {
    return;
}
define('PATTERN_ENGINE_SCENARIO_ENGINE_LOADED', true);

/**
 * ScenarioEngine
 *
 * Applies scenario profiles on top of normalized signals.
 *
 * A scenario = pattern signal + Coin Passport guidance + market regime + scenario profile.
 *
 * Coin Passport schema fields consumed (real schema — do NOT use legacy names):
 *   data_confidence             string  none|low|medium|high
 *   confidence_score_numeric    float   0-1
 *   corridor_p50_roi            float
 *   corridor_p75_roi            float
 *   corridor_p90_roi            float
 *   runner_probability          float   0-1
 *   noise_score                 float   0-1  (lower = better)
 *   short_suitability_score     float   0-1
 *   market_regime_health_score  float   0-1
 *   recommended_live_eligibility string allow_live|sim_only|shadow_only|reject
 *   live_block_reason           string|null
 *   pattern_behavior            array   keyed by algorithm name
 *   insufficient_data_flag      bool
 *   insufficient_data_reason    string|null
 *
 * Output (ScenarioDecision):
 * {
 *   scenario_id:                string
 *   signal_id:                  string
 *   scenario_status:            string   allow_live|allow_demo|allow_shadow|allow_sim|shadow_only|sim_only|reject
 *   scenario_score:             float    0-1
 *   scenario_reason:            string
 *   execution_mode_hint:        string
 *   passport_requirements_met:  bool
 *   market_requirements_met:    bool
 *   allowed_for_demo:           bool
 *   allowed_for_shadow:         bool
 *   allowed_for_sim:            bool
 *   allowed_for_live:           bool
 *   live_block_reason:          string|null
 *   profile_used:               string
 *   decided_at:                 string   ISO 8601
 *   diagnostics:                array
 * }
 */
final class ScenarioEngine
{
    /** @var array<string,array<string,mixed>> */
    private array $profiles;

    /** @var array<string,array<string,mixed>|null> */
    private array $passportCache = [];

    private string $passportDir;

    /**
     * When false (default), any scenario that would emit allow_live is
     * downgraded to allow_demo.  Set to true only after the new pattern
     * stack is proven through demo/shadow/sim.
     */
    private bool $liveOutputEnabled;

    /**
     * Downstream graduation policy — controls final bucket (allow_demo / allow_sim / shadow_only).
     * When non-empty this policy is the authoritative gate for demo/sim graduation,
     * independent of (and able to override) per-profile degraded_execution_mode.
     *
     * @var array<string,mixed>
     */
    private array $downstreamPolicy;

    /**
     * Paper pre-classification policy — applied after downstream graduation.
     * Controls which downstream-demo signals are promoted to paper_strong_candidate
     * (and thus eligible for actual demo export) vs paper_candidate (sim) vs paper_reject (shadow).
     *
     * @var array<string,mixed>
     */
    private array $paperPolicy;

    /**
     * Per-run counter for signals graduated via the low-confidence demo policy.
     * Enforces the max_demo_low_confidence_signals_per_run cap.
     */
    private int $demoLowConfidenceGranted = 0;

    /**
     * Per-run counter for paper_strong_candidate grants.
     * Enforces the paper_max_strong_per_run cap.
     */
    private int $paperStrongGranted = 0;

    /**
     * Per-run counter for paper_candidate grants.
     * Enforces the paper_max_candidates_per_run cap.
     */
    private int $paperCandidateGranted = 0;

    /**
     * @param array<string,array<string,mixed>> $profiles          Scenario profiles from config
     * @param string                            $passportDir       Path to coin_passport passports/
     * @param bool                              $liveOutputEnabled Master live-output gate (default false)
     * @param array<string,mixed>               $downstreamPolicy  Downstream graduation policy (default [])
     * @param array<string,mixed>               $paperPolicy       Paper pre-classification policy (default [])
     */
    public function __construct(array $profiles, string $passportDir, bool $liveOutputEnabled = false, array $downstreamPolicy = [], array $paperPolicy = [])
    {
        $this->profiles                  = $profiles;
        $this->passportDir               = $passportDir;
        $this->liveOutputEnabled         = $liveOutputEnabled;
        $this->downstreamPolicy          = $downstreamPolicy;
        $this->paperPolicy               = $paperPolicy;
        $this->demoLowConfidenceGranted  = 0;
        $this->paperStrongGranted        = 0;
        $this->paperCandidateGranted     = 0;
    }

    /**
     * Evaluate a normalized signal against all matching profiles.
     * Returns the most permissive scenario decision.
     *
     * @param  array<string,mixed>  $signal    Normalized signal from UniversalSignalAdapter
     * @return array<string,mixed>             Scenario decision
     */
    public function evaluate(array $signal): array
    {
        $passportResult = $this->loadPassportForSignal($signal);
        $passport       = $passportResult['passport'];
        $profiles       = $this->matchingProfiles($signal);

        if (empty($profiles)) {
            return $this->buildDecision($signal, null, null, 'reject', 'no_matching_profile', $passport, [], $passportResult);
        }

        // Try each profile, return the most permissive allowed result
        $best = null;
        foreach ($profiles as $profileName => $profile) {
            $decision = $this->applyProfile($signal, $profile, $profileName, $passport, $passportResult);
            if ($best === null || $this->statusRank($decision['scenario_status']) > $this->statusRank($best['scenario_status'])) {
                $best = $decision;
            }
        }

        return $best ?? $this->buildDecision($signal, null, null, 'reject', 'evaluation_failed', $passport, [], $passportResult);
    }

    /**
     * Batch evaluate a list of normalized signals.
     *
     * @param  list<array<string,mixed>>   $signals
     * @return list<array<string,mixed>>
     */
    public function evaluateAll(array $signals): array
    {
        $results = [];
        foreach ($signals as $signal) {
            $results[] = $this->evaluate($signal);
        }
        return $results;
    }

    // -------------------------------------------------------------------------
    // Private: profile matching
    // -------------------------------------------------------------------------

    /**
     * Return profiles that match this signal's algorithm and side.
     *
     * @return array<string,array<string,mixed>>
     */
    private function matchingProfiles(array $signal): array
    {
        $algorithm = (string)($signal['pattern_algorithm'] ?? '');
        $side      = (string)($signal['side'] ?? '');
        $matched   = [];

        foreach ($this->profiles as $name => $profile) {
            $allowedAlgos = (array)($profile['allowed_algorithms'] ?? []);
            $allowedSides = (array)($profile['allowed_sides'] ?? ['long', 'short']);

            if (!empty($allowedAlgos) && !in_array($algorithm, $allowedAlgos, true)) {
                continue;
            }
            if (!in_array($side, $allowedSides, true)) {
                continue;
            }
            $matched[$name] = $profile;
        }

        return $matched;
    }

    // -------------------------------------------------------------------------
    // Private: profile application
    // -------------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function applyProfile(
        array $signal,
        array $profile,
        string $profileName,
        ?array $passport,
        array $passportLookup = []
    ): array {
        $signalStrength  = (float)($signal['signal_strength'] ?? 0);
        $qualityScore    = (float)($signal['quality_score'] ?? 0);
        $minStrength     = (float)($profile['min_signal_strength'] ?? 0.3);
        $minQuality      = (float)($profile['min_quality_score'] ?? 0.3);

        // --- signal quality gate ---
        if ($signalStrength < $minStrength) {
            return $this->buildDecision($signal, $profile, $profileName, 'reject', 'signal_strength_below_threshold', $passport, [], $passportLookup);
        }
        if ($qualityScore < $minQuality) {
            return $this->buildDecision($signal, $profile, $profileName, 'reject', 'quality_score_below_threshold', $passport, [], $passportLookup);
        }

        // --- passport gate ---
        $passportOk  = true;
        $passportReason = null;

        if ($passport !== null) {
            [$passportOk, $passportReason] = $this->checkPassport($passport, $profile, $signal);
        } elseif (!empty($profile['require_passport'])) {
            $passportOk     = false;
            $passportReason = 'passport_required_but_missing';
        }

        // --- market/regime gate ---
        $marketOk     = true;
        $marketReason = null;

        if ($passport !== null) {
            [$marketOk, $marketReason] = $this->checkMarketRegime($passport, $profile);
        }

        // --- compute scenario score ---
        $passportScore = $this->passportScore($passport);
        $scenarioScore = round($signalStrength * 0.5 + $qualityScore * 0.3 + $passportScore * 0.2, 4);

        // --- determine scenario status ---
        if (!$passportOk || !$marketOk) {
            $liveBlockReason = $passportReason ?? $marketReason ?? 'passport_or_market_gate_failed';

            // Fallback: allow demo/shadow/sim if profile permits degraded execution
            $degradedMode = (string)($profile['degraded_execution_mode'] ?? 'shadow_only');
            return $this->buildDecision(
                $signal, $profile, $profileName,
                $degradedMode,
                $liveBlockReason,
                $passport,
                [
                    'passport_ok'     => false,
                    'market_ok'       => $marketOk,
                    'passport_reason' => $passportReason,
                    'market_reason'   => $marketReason,
                ],
                $passportLookup
            );
        }

        $executionModeHint = (string)($profile['execution_mode_hint'] ?? 'allow_demo');
        $reason            = 'profile_passed:' . $profileName;

        return $this->buildDecision($signal, $profile, $profileName, $executionModeHint, $reason, $passport, [
            'passport_ok'    => true,
            'market_ok'      => true,
            'scenario_score' => $scenarioScore,
        ], $passportLookup);
    }

    // -------------------------------------------------------------------------
    // Private: passport checks
    // -------------------------------------------------------------------------

    /** @return array{bool, string|null} */
    private function checkPassport(array $passport, array $profile, array $signal): array
    {
        $algorithm = (string)($signal['pattern_algorithm'] ?? '');

        // Bail early if passport has insufficient data and profile requires passport
        if (!empty($passport['insufficient_data_flag']) && !empty($profile['require_passport'])) {
            return [false, 'passport_insufficient_data:' . ($passport['insufficient_data_reason'] ?? 'no_reason')];
        }

        // Corridor P75 ROI — read real field name; fall back to pattern_behavior per-algo entry
        $minCorridorP75 = (float)($profile['min_corridor_p75_roi'] ?? 0.0);
        if ($minCorridorP75 > 0) {
            $cp75 = (float)(
                $passport['corridor_p75_roi'] ??
                $passport['pattern_behavior'][$algorithm]['corridor_p75_roi'] ??
                $passport['pattern_behavior'][$algorithm]['corridor_p75'] ??
                0
            );
            if ($cp75 < $minCorridorP75) {
                return [false, 'corridor_p75_roi_below_threshold'];
            }
        }

        // Runner probability — read real field; fall back to pattern_behavior per-algo runner_rate
        $minRunnerProb = (float)($profile['min_runner_probability'] ?? 0.0);
        if ($minRunnerProb > 0) {
            $rp = (float)(
                $passport['runner_probability'] ??
                $passport['pattern_behavior'][$algorithm]['runner_rate'] ??
                0
            );
            if ($rp < $minRunnerProb) {
                return [false, 'runner_probability_below_threshold'];
            }
        }

        // Noise score (lower is better)
        $maxNoise = (float)($profile['max_noise_score'] ?? 1.0);
        if ($maxNoise < 1.0) {
            $noise = (float)($passport['noise_score'] ?? 0);
            if ($noise > $maxNoise) {
                return [false, 'noise_score_above_threshold'];
            }
        }

        // Short suitability (only checked when profile requires it)
        $minSuitability = (float)($profile['min_suitability_score'] ?? 0.0);
        if ($minSuitability > 0) {
            $suitability = (float)($passport['short_suitability_score'] ?? 0);
            if ($suitability < $minSuitability) {
                return [false, 'short_suitability_score_below_threshold'];
            }
        }

        // Data confidence — real field is data_confidence (string)
        $minConfidence = (string)($profile['min_confidence'] ?? '');
        if ($minConfidence !== '') {
            $confidenceMap = ['none' => 0, 'low' => 1, 'medium' => 2, 'high' => 3];
            $rawConf       = strtolower((string)($passport['data_confidence'] ?? 'none'));
            $confLevel     = $confidenceMap[$rawConf] ?? 0;
            $requiredLevel = $confidenceMap[$minConfidence] ?? 0;
            if ($confLevel < $requiredLevel) {
                return [false, 'data_confidence_below_threshold'];
            }
        }

        // Live eligibility from passport
        $eligibility = (string)($passport['recommended_live_eligibility'] ?? '');
        if (in_array($eligibility, ['reject'], true)) {
            $blockReason = (string)($passport['live_block_reason'] ?? 'passport_live_eligibility_reject');
            return [false, $blockReason];
        }

        return [true, null];
    }

    /** @return array{bool, string|null} */
    private function checkMarketRegime(array $passport, array $profile): array
    {
        $minRegimeHealth = (float)($profile['min_regime_health'] ?? 0.0);
        if ($minRegimeHealth > 0) {
            $regimeHealth = (float)($passport['market_regime_health_score'] ?? 0);
            if ($regimeHealth < $minRegimeHealth) {
                return [false, 'market_regime_health_below_threshold'];
            }
        }
        return [true, null];
    }

    private function passportScore(?array $passport): float
    {
        if ($passport === null) {
            return 0.4; // neutral when no passport
        }

        // Use confidence_score_numeric if available; otherwise derive from data_confidence label
        $numericScore = $passport['confidence_score_numeric'] ?? null;
        if ($numericScore !== null) {
            $confScore = (float)$numericScore;
        } else {
            $conf = strtolower((string)($passport['data_confidence'] ?? 'none'));
            $confScore = match ($conf) {
                'high'   => 1.0,
                'medium' => 0.7,
                'low'    => 0.4,
                default  => 0.1,
            };
        }

        $regimeScore = (float)($passport['market_regime_health_score'] ?? 0.5);
        return round($confScore * 0.5 + $regimeScore * 0.5, 4);
    }

    // -------------------------------------------------------------------------
    // Private: decision builder
    // -------------------------------------------------------------------------

    /**
     * @param  array<string,mixed>|null  $profile
     * @param  array<string,mixed>|null  $passport
     * @param  array<string,mixed>       $extra
     * @return array<string,mixed>
     */
    private function buildDecision(
        array $signal,
        ?array $profile,
        ?string $profileName,
        string $status,
        string $reason,
        ?array $passport,
        array $extra = [],
        array $passportLookup = []
    ): array {
        $passportOk  = (bool)($extra['passport_ok'] ?? ($passport !== null));
        $marketOk    = (bool)($extra['market_ok'] ?? true);
        $scenScore   = (float)($extra['scenario_score'] ?? 0.0);

        // Enforce live-output policy: downgrade allow_live → allow_demo when disabled
        $effectiveStatus = $status;
        $effectiveReason = $reason;
        if ($status === 'allow_live' && !$this->liveOutputEnabled) {
            $effectiveStatus = 'allow_demo';
            $effectiveReason = 'live_output_disabled_by_engine_policy';
        }

        // Apply downstream graduation policy (if configured).
        // This is the authoritative final-bucket gate for non-rejected scenarios.
        $downstreamResult = [];
        if (!empty($this->downstreamPolicy) && $effectiveStatus !== 'reject') {
            $downstreamResult = $this->applyDownstreamPolicy($signal, $passport, $effectiveStatus);
            $effectiveStatus  = $downstreamResult['bucket'];
            $effectiveReason  = $downstreamResult['reason'];
        }

        // Apply paper pre-classification policy (independent of downstream bucket).
        // This annotates every scenario with a paper_bucket so the service layer
        // can restrict demo export to paper_strong_candidate only.
        $paperResult = $this->applyPaperPolicy($signal, $passport, $effectiveStatus);

        [$allowLive, $allowDemo, $allowShadow, $allowSim, $liveBlockReason] =
            $this->statusFlags($effectiveStatus, $effectiveReason);

        $maxHold = (int)($profile['max_hold_minutes'] ?? 0);

        // Build rich passport diagnostics
        $passportDiag = $this->buildPassportDiagnostics($passport, $signal);

        return [
            'scenario_id'               => 'sc_' . substr(hash('sha256', ($signal['signal_id'] ?? '') . $effectiveStatus . microtime(true) . random_int(0, PHP_INT_MAX)), 0, 12),
            'signal_id'                 => $signal['signal_id'] ?? '',
            'symbol'                    => $signal['symbol'] ?? '',
            'side'                      => $signal['side'] ?? '',
            'pattern_algorithm'         => $signal['pattern_algorithm'] ?? '',
            'scenario_status'           => $effectiveStatus,
            'scenario_score'            => round($scenScore, 4),
            'scenario_reason'           => $effectiveReason,
            'execution_mode_hint'       => $this->executionModeHint($effectiveStatus),
            'passport_requirements_met' => $passportOk,
            'market_requirements_met'   => $marketOk,
            'allowed_for_live'          => $allowLive,
            'allowed_for_demo'          => $allowDemo,
            'allowed_for_shadow'        => $allowShadow,
            'allowed_for_sim'           => $allowSim,
            'live_block_reason'         => $liveBlockReason,
            'profile_used'              => $profileName ?? 'default',
            'decided_at'                => date('c'),
            'max_hold_minutes'          => $maxHold > 0 ? $maxHold : null,
            'diagnostics'               => array_merge($passportDiag, [
                'status'                      => $effectiveStatus,
                'original_status'             => $status,
                'reason'                      => $effectiveReason,
                'original_reason'             => $reason,
                'passport_reason'             => $extra['passport_reason'] ?? null,
                'market_reason'               => $extra['market_reason'] ?? null,
                'signal_strength'             => $signal['signal_strength'] ?? null,
                'quality_score'               => $signal['quality_score'] ?? null,
                'profile'                     => $profileName,
                'engine_live_output_enabled'  => $this->liveOutputEnabled,
                'final_scenario_status'       => $effectiveStatus,
                'final_scenario_reason'       => $effectiveReason,
                'passport_lookup_symbol'      => $passportLookup['lookup_symbol'] ?? ($signal['symbol_canonical'] ?? $signal['symbol'] ?? null),
                'passport_lookup_status'      => $passportLookup['lookup_status'] ?? ($passport !== null ? 'found' : 'not_found'),
                'passport_lookup_reason'      => $passportLookup['lookup_reason'] ?? null,
                // Downstream graduation policy fields
                'final_downstream_bucket'     => $effectiveStatus,
                'final_downstream_reason'     => $effectiveReason,
                'downstream_policy_applied'   => !empty($downstreamResult),
                'demo_eligibility_checks'     => $downstreamResult['demo_checks'] ?? [],
                'sim_eligibility_checks'      => $downstreamResult['sim_checks']  ?? [],
                'demo_block_reason'           => $downstreamResult['demo_block_reason'] ?? null,
                'demo_near_miss'              => (bool)($downstreamResult['demo_near_miss'] ?? false),
                'demo_passed_checks'          => $downstreamResult['demo_passed_checks'] ?? [],
                'demo_failed_checks'          => $downstreamResult['demo_failed_checks'] ?? [],
                'demo_thresholds_used'        => $downstreamResult['demo_thresholds_used'] ?? null,
                'demo_actual_values'          => $downstreamResult['demo_actual_values']  ?? null,
                // Low-confidence demo policy diagnostics
                'demo_graduation_mode'                   => $downstreamResult['demo_graduation_mode']                   ?? null,
                'demo_graduation_reason'                 => $downstreamResult['demo_graduation_reason']                 ?? null,
                'demo_low_confidence_policy_used'        => (bool)($downstreamResult['demo_low_confidence_policy_used'] ?? false),
                'demo_low_confidence_checks_passed'      => $downstreamResult['demo_low_confidence_checks_passed']      ?? [],
                'demo_low_confidence_checks_failed'      => $downstreamResult['demo_low_confidence_checks_failed']      ?? [],
                'demo_low_confidence_block_reason'       => $downstreamResult['demo_low_confidence_block_reason']       ?? null,
                'downstream_policy_thresholds'=> !empty($downstreamResult) ? [
                    'demo_require_passport'        => $this->downstreamPolicy['demo_require_passport']        ?? null,
                    'demo_min_signal_strength'     => $this->downstreamPolicy['demo_min_signal_strength']     ?? null,
                    'demo_min_quality_score'       => $this->downstreamPolicy['demo_min_quality_score']       ?? null,
                    'demo_min_corridor_p75_roi'    => $this->downstreamPolicy['demo_min_corridor_p75_roi']    ?? null,
                    'demo_min_runner_probability'  => $this->downstreamPolicy['demo_min_runner_probability']  ?? null,
                    'demo_max_noise_score'         => $this->downstreamPolicy['demo_max_noise_score']         ?? null,
                    'demo_min_confidence'          => $this->downstreamPolicy['demo_min_confidence']          ?? null,
                ] : null,
                // Paper pre-classification (applied to all scenarios; gates demo export)
                'paper_bucket'               => $paperResult['paper_bucket'],
                'paper_reason'               => $paperResult['paper_reason'],
                'paper_score'                => $paperResult['paper_score'],
                'paper_checks_passed'        => $paperResult['paper_checks_passed'],
                'paper_checks_failed'        => $paperResult['paper_checks_failed'],
            ]),
        ];
    }

    // -------------------------------------------------------------------------
    // Private: downstream graduation policy
    // -------------------------------------------------------------------------

    /**
     * Apply the downstream graduation policy to determine the final bucket.
     *
     * This is the authoritative gate for demo/sim graduation, independent of
     * per-profile thresholds.  It can upgrade shadow_only → allow_demo when the
     * signal's passport metrics meet the policy thresholds, and enforces that
     * allow_demo_enabled=false always forces shadow.
     *
     * @param  array<string,mixed>       $signal
     * @param  array<string,mixed>|null  $passport
     * @param  string                    $profileBucket  Result from profile evaluation (pre-policy)
     * @return array{bucket:string, reason:string, demo_checks:list<array<string,mixed>>, sim_checks:list<array<string,mixed>>, demo_block_reason:string|null, demo_near_miss:bool, demo_passed_checks:list<string>, demo_failed_checks:list<string>, demo_thresholds_used:array<string,mixed>, demo_actual_values:array<string,mixed>}
     */
    private function applyDownstreamPolicy(array $signal, ?array $passport, string $profileBucket): array
    {
        $policy = $this->downstreamPolicy;

        $allowDemoEnabled = (bool)($policy['allow_demo_enabled'] ?? true);
        $allowSimEnabled  = (bool)($policy['allow_sim_enabled']  ?? true);

        $signalStrength = (float)($signal['signal_strength'] ?? 0.0);
        $qualityScore   = (float)($signal['quality_score']   ?? 0.0);
        $algo           = (string)($signal['pattern_algorithm'] ?? '');

        $demoChecks      = [];
        $simChecks       = [];
        $demoBlockReason = null;

        // Low-confidence policy state (set if the policy block runs)
        $lcPolicyUsed        = false;
        $lcPassedChecks      = [];
        $lcFailedChecks      = [];
        $lcBlockReason       = null;

        // Snapshot threshold config for transparency
        $demoThresholdsUsed = [
            'require_passport'        => $policy['demo_require_passport']        ?? null,
            'min_signal_strength'     => $policy['demo_min_signal_strength']     ?? null,
            'min_quality_score'       => $policy['demo_min_quality_score']       ?? null,
            'min_corridor_p75_roi'    => $policy['demo_min_corridor_p75_roi']    ?? null,
            'min_runner_probability'  => $policy['demo_min_runner_probability']  ?? null,
            'max_noise_score'         => $policy['demo_max_noise_score']         ?? null,
            'min_confidence'          => $policy['demo_min_confidence']          ?? null,
            'require_eligibility'     => $policy['demo_require_passport_eligibility'] ?? null,
        ];

        // Snapshot actual values for transparency
        $cp75Actual = $passport !== null ? (float)(
            $passport['corridor_p75_roi'] ??
            $passport['pattern_behavior'][$algo]['corridor_p75_roi'] ??
            $passport['pattern_behavior'][$algo]['corridor_p75'] ??
            0.0
        ) : null;
        $rpActual = $passport !== null ? (float)(
            $passport['runner_probability'] ??
            $passport['pattern_behavior'][$algo]['runner_rate'] ??
            0.0
        ) : null;
        $demoActualValues = [
            'signal_strength'   => $signalStrength,
            'quality_score'     => $qualityScore,
            'passport_present'  => $passport !== null,
            'corridor_p75_roi'  => $cp75Actual,
            'runner_probability'=> $rpActual,
            'noise_score'       => $passport !== null ? ($passport['noise_score'] ?? null) : null,
            'data_confidence'   => $passport !== null ? ($passport['data_confidence'] ?? null) : null,
            'eligibility'       => $passport !== null ? ($passport['recommended_live_eligibility'] ?? null) : null,
            'insufficient_data' => $passport !== null ? (!empty($passport['insufficient_data_flag'])) : null,
        ];

        // ---- Demo gate ----
        if ($allowDemoEnabled) {
            $requirePassport = (bool)($policy['demo_require_passport'] ?? false);

            if ($requirePassport && $passport === null) {
                $demoChecks[]    = ['check' => 'passport_available', 'pass' => false, 'reason' => 'passport_required_but_missing'];
                $demoBlockReason = 'demo_blocked_no_passport';
            } else {
                $demoChecks[] = ['check' => 'passport_available', 'pass' => true];

                // signal_strength
                $minStr = (float)($policy['demo_min_signal_strength'] ?? 0.0);
                if ($minStr > 0 && $signalStrength < $minStr) {
                    $demoChecks[]    = ['check' => 'signal_strength', 'pass' => false, 'threshold' => $minStr, 'value' => $signalStrength];
                    $demoBlockReason = $demoBlockReason ?? 'demo_blocked_low_strength';
                } else {
                    $demoChecks[] = ['check' => 'signal_strength', 'pass' => true, 'threshold' => $minStr, 'value' => $signalStrength];
                }

                // quality_score
                $minQual = (float)($policy['demo_min_quality_score'] ?? 0.0);
                if ($minQual > 0 && $qualityScore < $minQual) {
                    $demoChecks[]    = ['check' => 'quality_score', 'pass' => false, 'threshold' => $minQual, 'value' => $qualityScore];
                    $demoBlockReason = $demoBlockReason ?? 'demo_blocked_low_quality';
                } else {
                    $demoChecks[] = ['check' => 'quality_score', 'pass' => true, 'threshold' => $minQual, 'value' => $qualityScore];
                }

                // passport-specific checks (only when passport is present)
                if ($passport !== null) {
                    // insufficient_data_flag
                    if (!empty($passport['insufficient_data_flag'])) {
                        $demoChecks[]    = ['check' => 'insufficient_data', 'pass' => false, 'reason' => $passport['insufficient_data_reason'] ?? 'flagged'];
                        $demoBlockReason = $demoBlockReason ?? 'demo_blocked_insufficient_data';
                    } else {
                        $demoChecks[] = ['check' => 'insufficient_data', 'pass' => true];
                    }

                    // data_confidence
                    $minConf = (string)($policy['demo_min_confidence'] ?? '');
                    if ($minConf !== '') {
                        $confMap  = ['none' => 0, 'low' => 1, 'medium' => 2, 'high' => 3];
                        $rawConf  = strtolower((string)($passport['data_confidence'] ?? 'none'));
                        $confLvl  = $confMap[$rawConf] ?? 0;
                        $reqLvl   = $confMap[$minConf]  ?? 0;
                        $pass     = $confLvl >= $reqLvl;
                        $demoChecks[] = ['check' => 'data_confidence', 'pass' => $pass, 'threshold' => $minConf, 'value' => $rawConf];
                        if (!$pass) {
                            $demoBlockReason = $demoBlockReason ?? 'demo_blocked_low_confidence';
                        }
                    }

                    // corridor_p75_roi
                    $minP75 = (float)($policy['demo_min_corridor_p75_roi'] ?? 0.0);
                    if ($minP75 > 0) {
                        $pass = ($cp75Actual !== null && $cp75Actual >= $minP75);
                        $demoChecks[] = ['check' => 'corridor_p75_roi', 'pass' => $pass, 'threshold' => $minP75, 'value' => $cp75Actual ?? 0.0];
                        if (!$pass) {
                            $demoBlockReason = $demoBlockReason ?? 'demo_blocked_low_corridor';
                        }
                    }

                    // runner_probability
                    $minRunner = (float)($policy['demo_min_runner_probability'] ?? 0.0);
                    if ($minRunner > 0) {
                        $pass = ($rpActual !== null && $rpActual >= $minRunner);
                        $demoChecks[] = ['check' => 'runner_probability', 'pass' => $pass, 'threshold' => $minRunner, 'value' => $rpActual ?? 0.0];
                        if (!$pass) {
                            $demoBlockReason = $demoBlockReason ?? 'demo_blocked_low_runner';
                        }
                    }

                    // noise_score (lower is better)
                    $maxNoise = (float)($policy['demo_max_noise_score'] ?? 1.0);
                    if ($maxNoise < 1.0) {
                        $noise = (float)($passport['noise_score'] ?? 0.0);
                        $pass  = $noise <= $maxNoise;
                        $demoChecks[] = ['check' => 'noise_score', 'pass' => $pass, 'threshold' => $maxNoise, 'value' => $noise];
                        if (!$pass) {
                            $demoBlockReason = $demoBlockReason ?? 'demo_blocked_high_noise';
                        }
                    }

                    // passport eligibility — must be in allowed list
                    $allowedEligibilities = (array)($policy['demo_require_passport_eligibility'] ?? []);
                    if (!empty($allowedEligibilities)) {
                        $eligibility = (string)($passport['recommended_live_eligibility'] ?? '');
                        $pass = in_array($eligibility, $allowedEligibilities, true);
                        $demoChecks[] = ['check' => 'passport_eligibility', 'pass' => $pass, 'threshold' => $allowedEligibilities, 'value' => $eligibility];
                        if (!$pass) {
                            $demoBlockReason = $demoBlockReason ?? 'demo_blocked_passport_eligibility_rejected';
                        }
                    }
                }
            }

            // Derive passed/failed check name lists for transparency
            $demoPassedChecks = array_values(array_map(
                fn($c) => $c['check'],
                array_filter($demoChecks, fn($c) => $c['pass'] === true)
            ));
            $demoFailedChecks = array_values(array_map(
                fn($c) => $c['check'],
                array_filter($demoChecks, fn($c) => $c['pass'] === false)
            ));

            // All demo checks passed — graduate to allow_demo
            if ($demoBlockReason === null) {
                return [
                    'bucket'             => 'allow_demo',
                    'reason'             => 'downstream_policy_demo_gate_passed',
                    'demo_checks'        => $demoChecks,
                    'sim_checks'         => [],
                    'demo_block_reason'  => null,
                    'demo_near_miss'     => false,
                    'demo_passed_checks' => $demoPassedChecks,
                    'demo_failed_checks' => [],
                    'demo_thresholds_used' => $demoThresholdsUsed,
                    'demo_actual_values'   => $demoActualValues,
                ];
            }

            // Near-miss: passport present, blocked by only 1–2 non-passport-absence checks
            $nearMissMax  = (int)($policy['demo_near_miss_max_failed_checks'] ?? 2);
            $isNearMiss   = (
                $passport !== null
                && $demoBlockReason !== 'demo_blocked_no_passport'
                && count($demoFailedChecks) > 0
                && count($demoFailedChecks) <= $nearMissMax
            );

            // ---- Low-confidence demo policy fallback ----
            // Applies when standard demo gate failed but passport is present.
            // Allows a controlled subset of low-confidence / insufficient-data signals
            // to reach demo, subject to tighter quality/noise gates and a per-run cap.
            $lcPolicy = (array)($policy['demo_low_confidence_policy'] ?? []);
            if (
                $demoBlockReason !== null
                && !empty($lcPolicy['enabled'])
                && $passport !== null
                && $demoBlockReason !== 'demo_blocked_no_passport'
            ) {
                $lcChecks = [];

                // cap check
                $lcCap = (int)($lcPolicy['max_demo_low_confidence_signals_per_run'] ?? 5);
                if ($this->demoLowConfidenceGranted >= $lcCap) {
                    $lcBlockReason = 'lc_demo_cap_reached';
                    $lcChecks[] = ['check' => 'lc_cap', 'pass' => false, 'threshold' => $lcCap, 'value' => $this->demoLowConfidenceGranted];
                } else {
                    $lcChecks[] = ['check' => 'lc_cap', 'pass' => true, 'threshold' => $lcCap, 'value' => $this->demoLowConfidenceGranted];
                }

                // confidence or insufficient_data qualifier: signal must actually be low-confidence
                if ($lcBlockReason === null) {
                    $allowWhenConf      = (array)($lcPolicy['allow_when_data_confidence']    ?? ['low', 'none']);
                    $allowIfInsufficient= (bool)($lcPolicy['allow_if_insufficient_data_flag'] ?? true);
                    $rawLcConf          = strtolower((string)($passport['data_confidence'] ?? 'none'));
                    $hasInsufficientFlag= !empty($passport['insufficient_data_flag']);
                    $qualifiesAsLowConf = in_array($rawLcConf, $allowWhenConf, true) || ($allowIfInsufficient && $hasInsufficientFlag);
                    if (!$qualifiesAsLowConf) {
                        $lcBlockReason = 'lc_demo_signal_not_low_confidence';
                        $lcChecks[] = ['check' => 'lc_confidence_qualifier', 'pass' => false, 'value' => $rawLcConf, 'insufficient_flag' => $hasInsufficientFlag];
                    } else {
                        $lcChecks[] = ['check' => 'lc_confidence_qualifier', 'pass' => true, 'value' => $rawLcConf, 'insufficient_flag' => $hasInsufficientFlag];
                    }
                }

                // signal_strength
                $lcMinStr = (float)($lcPolicy['require_signal_strength_min'] ?? 0.0);
                if ($lcBlockReason === null && $lcMinStr > 0 && $signalStrength < $lcMinStr) {
                    $lcBlockReason = 'lc_demo_low_strength';
                    $lcChecks[] = ['check' => 'lc_signal_strength', 'pass' => false, 'threshold' => $lcMinStr, 'value' => $signalStrength];
                } else {
                    $lcChecks[] = ['check' => 'lc_signal_strength', 'pass' => true, 'threshold' => $lcMinStr, 'value' => $signalStrength];
                }

                // quality_score
                $lcMinQual = (float)($lcPolicy['require_quality_score_min'] ?? 0.0);
                if ($lcBlockReason === null && $lcMinQual > 0 && $qualityScore < $lcMinQual) {
                    $lcBlockReason = 'lc_demo_low_quality';
                    $lcChecks[] = ['check' => 'lc_quality_score', 'pass' => false, 'threshold' => $lcMinQual, 'value' => $qualityScore];
                } else {
                    $lcChecks[] = ['check' => 'lc_quality_score', 'pass' => true, 'threshold' => $lcMinQual, 'value' => $qualityScore];
                }

                // noise_score
                $lcMaxNoise = (float)($lcPolicy['require_noise_score_max'] ?? 1.0);
                if ($lcBlockReason === null && $lcMaxNoise < 1.0) {
                    $lcNoise = (float)($passport['noise_score'] ?? 0.0);
                    if ($lcNoise > $lcMaxNoise) {
                        $lcBlockReason = 'lc_demo_high_noise';
                        $lcChecks[] = ['check' => 'lc_noise_score', 'pass' => false, 'threshold' => $lcMaxNoise, 'value' => $lcNoise];
                    } else {
                        $lcChecks[] = ['check' => 'lc_noise_score', 'pass' => true, 'threshold' => $lcMaxNoise, 'value' => $lcNoise];
                    }
                }

                // short_suitability (optional gate — only when > 0)
                $lcMinShort = (float)($lcPolicy['require_short_suitability_min'] ?? 0.0);
                if ($lcBlockReason === null && $lcMinShort > 0) {
                    $lcShort = (float)($passport['short_suitability_score'] ?? 0.0);
                    if ($lcShort < $lcMinShort) {
                        $lcBlockReason = 'lc_demo_low_short_suitability';
                        $lcChecks[] = ['check' => 'lc_short_suitability', 'pass' => false, 'threshold' => $lcMinShort, 'value' => $lcShort];
                    } else {
                        $lcChecks[] = ['check' => 'lc_short_suitability', 'pass' => true, 'threshold' => $lcMinShort, 'value' => $lcShort];
                    }
                }

                $lcPassedChecks = array_values(array_map(fn($c) => $c['check'], array_filter($lcChecks, fn($c) => $c['pass'] === true)));
                $lcFailedChecks = array_values(array_map(fn($c) => $c['check'], array_filter($lcChecks, fn($c) => $c['pass'] === false)));

                if ($lcBlockReason === null) {
                    // Low-confidence policy passed — graduate to allow_demo
                    $this->demoLowConfidenceGranted++;
                    $lcPolicyUsed = true;
                    return [
                        'bucket'                              => 'allow_demo',
                        'reason'                              => 'downstream_policy_lc_demo_gate_passed',
                        'demo_checks'                         => $demoChecks,
                        'sim_checks'                          => [],
                        'demo_block_reason'                   => null,
                        'demo_near_miss'                      => false,
                        'demo_passed_checks'                  => $demoPassedChecks,
                        'demo_failed_checks'                  => [],
                        'demo_thresholds_used'                => $demoThresholdsUsed,
                        'demo_actual_values'                  => $demoActualValues,
                        'demo_graduation_mode'                => 'low_confidence_policy',
                        'demo_graduation_reason'              => 'lc_demo_gate_passed',
                        'demo_low_confidence_policy_used'     => true,
                        'demo_low_confidence_checks_passed'   => $lcPassedChecks,
                        'demo_low_confidence_checks_failed'   => [],
                        'demo_low_confidence_block_reason'    => null,
                    ];
                }
                // Low-confidence policy also failed — outer-scope $lcBlockReason/$lcPassedChecks/$lcFailedChecks are set
            }
        } else {
            $demoBlockReason  = 'allow_demo_disabled_by_policy';
            $demoPassedChecks = [];
            $demoFailedChecks = [];
            $isNearMiss       = false;
        }

        // ---- Sim gate ----
        if ($allowSimEnabled) {
            $requirePassportSim = (bool)($policy['sim_require_passport'] ?? false);
            $minSimStr          = (float)($policy['sim_min_signal_strength'] ?? 0.0);
            $minSimQual         = (float)($policy['sim_min_quality_score']   ?? 0.0);
            $simPasses          = true;

            if ($requirePassportSim && $passport === null) {
                $simChecks[] = ['check' => 'passport_available', 'pass' => false];
                $simPasses   = false;
            } else {
                $simChecks[] = ['check' => 'passport_available', 'pass' => true];
            }

            if ($simPasses && $signalStrength < $minSimStr) {
                $simChecks[] = ['check' => 'signal_strength', 'pass' => false, 'threshold' => $minSimStr, 'value' => $signalStrength];
                $simPasses   = false;
            } elseif ($simPasses) {
                $simChecks[] = ['check' => 'signal_strength', 'pass' => true, 'threshold' => $minSimStr, 'value' => $signalStrength];
            }

            if ($simPasses && $qualityScore < $minSimQual) {
                $simChecks[] = ['check' => 'quality_score', 'pass' => false, 'threshold' => $minSimQual, 'value' => $qualityScore];
                $simPasses   = false;
            } elseif ($simPasses) {
                $simChecks[] = ['check' => 'quality_score', 'pass' => true, 'threshold' => $minSimQual, 'value' => $qualityScore];
            }

            if ($simPasses) {
                return [
                    'bucket'             => 'allow_sim',
                    'reason'             => 'downstream_policy_sim_gate_passed',
                    'demo_checks'        => $demoChecks,
                    'sim_checks'         => $simChecks,
                    'demo_block_reason'  => $demoBlockReason,
                    'demo_near_miss'     => $isNearMiss ?? false,
                    'demo_passed_checks' => $demoPassedChecks ?? [],
                    'demo_failed_checks' => $demoFailedChecks ?? [],
                    'demo_thresholds_used' => $demoThresholdsUsed,
                    'demo_actual_values'   => $demoActualValues,
                    'demo_graduation_mode'                => null,
                    'demo_graduation_reason'              => null,
                    'demo_low_confidence_policy_used'     => $lcPolicyUsed,
                    'demo_low_confidence_checks_passed'   => $lcPassedChecks,
                    'demo_low_confidence_checks_failed'   => $lcFailedChecks,
                    'demo_low_confidence_block_reason'    => $lcBlockReason,
                ];
            }
        }

        // Shadow default
        return [
            'bucket'             => 'shadow_only',
            'reason'             => 'downstream_policy_shadow_default:' . ($demoBlockReason ?? 'sim_gate_failed'),
            'demo_checks'        => $demoChecks,
            'sim_checks'         => $simChecks,
            'demo_block_reason'  => $demoBlockReason,
            'demo_near_miss'     => $isNearMiss ?? false,
            'demo_passed_checks' => $demoPassedChecks ?? [],
            'demo_failed_checks' => $demoFailedChecks ?? [],
            'demo_thresholds_used' => $demoThresholdsUsed,
            'demo_actual_values'   => $demoActualValues,
            'demo_graduation_mode'                => null,
            'demo_graduation_reason'              => null,
            'demo_low_confidence_policy_used'     => $lcPolicyUsed,
            'demo_low_confidence_checks_passed'   => $lcPassedChecks,
            'demo_low_confidence_checks_failed'   => $lcFailedChecks,
            'demo_low_confidence_block_reason'    => $lcBlockReason,
        ];
    }

    // -------------------------------------------------------------------------
    // Private: paper pre-classification policy
    // -------------------------------------------------------------------------

    /**
     * Apply paper pre-classification to a signal.
     *
     * Every scenario gets one paper classification:
     *   paper_strong_candidate — passes all strong thresholds; eligible for demo export
     *   paper_candidate        — passes lower thresholds; eligible for sim export
     *   paper_reject           — fails even candidate thresholds; routed to shadow
     *
     * Uses only internal data: signal_strength, quality_score, corridor_p75_roi,
     * runner_probability, noise_score (from passport when available).
     *
     * @param  array<string,mixed>       $signal
     * @param  array<string,mixed>|null  $passport
     * @param  string                    $finalBucket  The scenario's final downstream bucket
     * @return array{paper_bucket:string, paper_reason:string, paper_score:float, paper_checks_passed:list<string>, paper_checks_failed:list<string>}
     */
    private function applyPaperPolicy(array $signal, ?array $passport, string $finalBucket): array
    {
        $policy = $this->paperPolicy;

        if (empty($policy['enabled'])) {
            return [
                'paper_bucket'        => 'paper_strong_candidate',
                'paper_reason'        => 'paper_policy_disabled',
                'paper_score'         => 1.0,
                'paper_checks_passed' => [],
                'paper_checks_failed' => [],
            ];
        }

        $signalStrength  = (float)($signal['signal_strength'] ?? 0.0);
        $qualityScore    = (float)($signal['quality_score']   ?? 0.0);
        $algo            = (string)($signal['pattern_algorithm'] ?? '');
        $passportPresent = $passport !== null;

        // Extract passport metrics when available
        $cp75Actual  = null;
        $rpActual    = null;
        $noiseActual = null;
        if ($passport !== null) {
            $cp75Actual = (float)(
                $passport['corridor_p75_roi'] ??
                ($passport['pattern_behavior'][$algo]['corridor_p75_roi'] ?? null) ??
                ($passport['pattern_behavior'][$algo]['corridor_p75'] ?? null) ??
                0.0
            );
            $rpActual = (float)(
                $passport['runner_probability'] ??
                ($passport['pattern_behavior'][$algo]['runner_rate'] ?? null) ??
                0.0
            );
            $noiseActual = (float)($passport['noise_score'] ?? 0.0);
        }

        // Composite paper_score (0–1) using only available internal data
        $paperScore = round(
            $signalStrength * 0.35
            + $qualityScore * 0.30
            + ($rpActual !== null ? min(1.0, $rpActual * 5.0) * 0.20 : 0.0)
            + ($cp75Actual !== null ? min(1.0, $cp75Actual / 10.0) * 0.15 : 0.0),
            4
        );

        // ---- Strong candidate check ----
        $strongChecks      = [];
        $strongBlockReason = null;

        $strongMinStr = (float)($policy['paper_strong_min_signal_strength'] ?? 0.50);
        $pass = $signalStrength >= $strongMinStr;
        $strongChecks[] = ['check' => 'signal_strength', 'pass' => $pass, 'threshold' => $strongMinStr, 'value' => $signalStrength];
        if (!$pass) {
            $strongBlockReason = $strongBlockReason ?? 'paper_weak_signal_strength';
        }

        $strongMinQual = (float)($policy['paper_strong_min_quality_score'] ?? 0.45);
        $pass = $qualityScore >= $strongMinQual;
        $strongChecks[] = ['check' => 'quality_score', 'pass' => $pass, 'threshold' => $strongMinQual, 'value' => $qualityScore];
        if (!$pass) {
            $strongBlockReason = $strongBlockReason ?? 'paper_weak_quality_score';
        }

        $strongMinP75 = (float)($policy['paper_strong_min_corridor_p75_roi'] ?? 0.0);
        if ($strongMinP75 > 0 && $cp75Actual !== null) {
            $pass = $cp75Actual >= $strongMinP75;
            $strongChecks[] = ['check' => 'corridor_p75_roi', 'pass' => $pass, 'threshold' => $strongMinP75, 'value' => $cp75Actual];
            if (!$pass) {
                $strongBlockReason = $strongBlockReason ?? 'paper_weak_corridor_p75_roi';
            }
        }

        $strongMinRP = (float)($policy['paper_strong_min_runner_probability'] ?? 0.0);
        if ($strongMinRP > 0 && $rpActual !== null) {
            $pass = $rpActual >= $strongMinRP;
            $strongChecks[] = ['check' => 'runner_probability', 'pass' => $pass, 'threshold' => $strongMinRP, 'value' => $rpActual];
            if (!$pass) {
                $strongBlockReason = $strongBlockReason ?? 'paper_weak_runner_probability';
            }
        }

        $strongMaxNoise = (float)($policy['paper_strong_max_noise_score'] ?? 1.0);
        if ($strongMaxNoise < 1.0 && $noiseActual !== null) {
            $pass = $noiseActual <= $strongMaxNoise;
            $strongChecks[] = ['check' => 'noise_score', 'pass' => $pass, 'threshold' => $strongMaxNoise, 'value' => $noiseActual];
            if (!$pass) {
                $strongBlockReason = $strongBlockReason ?? 'paper_high_noise_score';
            }
        }

        $strongRequirePassport = (bool)($policy['paper_strong_require_passport'] ?? false);
        if ($strongRequirePassport && !$passportPresent) {
            $strongChecks[] = ['check' => 'passport_available', 'pass' => false];
            $strongBlockReason = $strongBlockReason ?? 'paper_strong_requires_passport';
        }

        $maxStrong = (int)($policy['paper_max_strong_per_run'] ?? 20);
        if ($strongBlockReason === null && $this->paperStrongGranted >= $maxStrong) {
            $strongBlockReason = 'paper_strong_cap_reached';
            $strongChecks[] = ['check' => 'cap', 'pass' => false, 'threshold' => $maxStrong, 'value' => $this->paperStrongGranted];
        }

        if ($strongBlockReason === null) {
            $this->paperStrongGranted++;
            $passedChecks = array_values(array_map(
                fn($c) => $c['check'],
                array_filter($strongChecks, fn($c) => $c['pass'] === true)
            ));
            return [
                'paper_bucket'        => 'paper_strong_candidate',
                'paper_reason'        => 'paper_strong_checks_passed',
                'paper_score'         => $paperScore,
                'paper_checks_passed' => $passedChecks,
                'paper_checks_failed' => [],
            ];
        }

        // ---- Candidate check ----
        $candidateChecks      = [];
        $candidateBlockReason = null;

        $candidateMinStr = (float)($policy['paper_candidate_min_signal_strength'] ?? 0.38);
        $pass = $signalStrength >= $candidateMinStr;
        $candidateChecks[] = ['check' => 'signal_strength', 'pass' => $pass, 'threshold' => $candidateMinStr, 'value' => $signalStrength];
        if (!$pass) {
            $candidateBlockReason = $candidateBlockReason ?? 'paper_weak_signal_strength';
        }

        $candidateMinQual = (float)($policy['paper_candidate_min_quality_score'] ?? 0.32);
        $pass = $qualityScore >= $candidateMinQual;
        $candidateChecks[] = ['check' => 'quality_score', 'pass' => $pass, 'threshold' => $candidateMinQual, 'value' => $qualityScore];
        if (!$pass) {
            $candidateBlockReason = $candidateBlockReason ?? 'paper_weak_quality_score';
        }

        $candidateRequirePassport = (bool)($policy['paper_candidate_require_passport'] ?? false);
        if ($candidateRequirePassport && !$passportPresent) {
            $candidateChecks[] = ['check' => 'passport_available', 'pass' => false];
            $candidateBlockReason = $candidateBlockReason ?? 'paper_candidate_requires_passport';
        }

        $maxCandidates = (int)($policy['paper_max_candidates_per_run'] ?? 50);
        if ($candidateBlockReason === null && $this->paperCandidateGranted >= $maxCandidates) {
            $candidateBlockReason = 'paper_candidate_cap_reached';
            $candidateChecks[] = ['check' => 'cap', 'pass' => false, 'threshold' => $maxCandidates, 'value' => $this->paperCandidateGranted];
        }

        if ($candidateBlockReason === null) {
            $this->paperCandidateGranted++;
            $strongFailedChecks = array_values(array_map(
                fn($c) => $c['check'],
                array_filter($strongChecks, fn($c) => $c['pass'] === false)
            ));
            $candidatePassedChecks = array_values(array_map(
                fn($c) => $c['check'],
                array_filter($candidateChecks, fn($c) => $c['pass'] === true)
            ));
            return [
                'paper_bucket'        => 'paper_candidate',
                'paper_reason'        => $strongBlockReason ?? 'paper_strong_checks_failed',
                'paper_score'         => $paperScore,
                'paper_checks_passed' => $candidatePassedChecks,
                'paper_checks_failed' => $strongFailedChecks,
            ];
        }

        // ---- Reject ----
        $allFailedChecks = array_values(array_unique(array_merge(
            array_map(fn($c) => $c['check'], array_filter($strongChecks, fn($c) => $c['pass'] === false)),
            array_map(fn($c) => $c['check'], array_filter($candidateChecks, fn($c) => $c['pass'] === false))
        )));

        return [
            'paper_bucket'        => 'paper_reject',
            'paper_reason'        => $candidateBlockReason ?? $strongBlockReason ?? 'paper_checks_failed',
            'paper_score'         => $paperScore,
            'paper_checks_passed' => [],
            'paper_checks_failed' => $allFailedChecks,
        ];
    }

    /**
     * Build a flat passport diagnostics array for inclusion in every decision.
     * All fields are included even when null, so consumers can inspect gaps.
     *
     * @param  array<string,mixed>|null $passport
     * @param  array<string,mixed>      $signal
     * @return array<string,mixed>
     */
    private function buildPassportDiagnostics(?array $passport, array $signal): array
    {
        if ($passport === null) {
            return [
                'passport_available'              => false,
                'passport_data_confidence'        => null,
                'passport_confidence_score_numeric' => null,
                'passport_corridor_p50_roi'       => null,
                'passport_corridor_p75_roi'       => null,
                'passport_corridor_p90_roi'       => null,
                'passport_runner_probability'     => null,
                'passport_noise_score'            => null,
                'passport_short_suitability_score' => null,
                'passport_market_regime_health_score' => null,
                'passport_live_eligibility'       => null,
                'passport_live_block_reason'      => null,
                'passport_insufficient_data_flag' => null,
                'passport_insufficient_data_reason' => null,
                'passport_pattern_behavior_present' => false,
            ];
        }

        $algo = (string)($signal['pattern_algorithm'] ?? '');
        $patternBehavior = (array)($passport['pattern_behavior'] ?? []);
        $algoData = (array)($patternBehavior[$algo] ?? []);

        return [
            'passport_available'              => true,
            'passport_data_confidence'        => $passport['data_confidence'] ?? null,
            'passport_confidence_score_numeric' => isset($passport['confidence_score_numeric']) ? (float)$passport['confidence_score_numeric'] : null,
            'passport_corridor_p50_roi'       => isset($passport['corridor_p50_roi']) ? (float)$passport['corridor_p50_roi'] : null,
            'passport_corridor_p75_roi'       => isset($passport['corridor_p75_roi']) ? (float)$passport['corridor_p75_roi'] : (isset($algoData['corridor_p75_roi']) ? (float)$algoData['corridor_p75_roi'] : null),
            'passport_corridor_p90_roi'       => isset($passport['corridor_p90_roi']) ? (float)$passport['corridor_p90_roi'] : null,
            'passport_runner_probability'     => isset($passport['runner_probability']) ? (float)$passport['runner_probability'] : (isset($algoData['runner_rate']) ? (float)$algoData['runner_rate'] : null),
            'passport_noise_score'            => isset($passport['noise_score']) ? (float)$passport['noise_score'] : null,
            'passport_short_suitability_score' => isset($passport['short_suitability_score']) ? (float)$passport['short_suitability_score'] : null,
            'passport_market_regime_health_score' => isset($passport['market_regime_health_score']) ? (float)$passport['market_regime_health_score'] : null,
            'passport_live_eligibility'       => $passport['recommended_live_eligibility'] ?? null,
            'passport_live_block_reason'      => $passport['live_block_reason'] ?? null,
            'passport_insufficient_data_flag' => isset($passport['insufficient_data_flag']) ? (bool)$passport['insufficient_data_flag'] : null,
            'passport_insufficient_data_reason' => $passport['insufficient_data_reason'] ?? null,
            'passport_pattern_behavior_present' => !empty($algoData),
            'passport_pattern_success_rate'   => isset($algoData['success_rate']) ? (float)$algoData['success_rate'] : null,
            'passport_pattern_runner_rate'    => isset($algoData['runner_rate']) ? (float)$algoData['runner_rate'] : null,
            'passport_pattern_avg_roi'        => isset($algoData['avg_roi']) ? (float)$algoData['avg_roi'] : null,
            'passport_pattern_stop_rate'      => isset($algoData['stop_rate']) ? (float)$algoData['stop_rate'] : null,
            'passport_pattern_data_confidence' => $algoData['data_confidence'] ?? null,
        ];
    }

    /** @return array{bool,bool,bool,bool,string|null} */
    private function statusFlags(string $status, string $reason): array
    {
        return match ($status) {
            'allow_live'    => [true,  true,  true,  true,  null],
            'allow_demo'    => [false, true,  false, false, $reason],
            'allow_shadow'  => [false, false, true,  false, $reason],
            'shadow_only'   => [false, false, true,  false, $reason],
            'allow_sim'     => [false, false, false, true,  $reason],
            'sim_only'      => [false, false, false, true,  $reason],
            'reject'        => [false, false, false, false, $reason],
            default         => [false, false, false, false, $reason],
        };
    }

    private function executionModeHint(string $status): string
    {
        return match ($status) {
            'allow_live'  => 'live_execution',
            'allow_demo'  => 'demo_execution',
            'allow_shadow', 'shadow_only' => 'shadow_execution',
            'allow_sim', 'sim_only'       => 'simulator',
            default => 'none',
        };
    }

    private function statusRank(string $status): int
    {
        return match ($status) {
            'allow_live'  => 5,
            'allow_demo'  => 4,
            'allow_shadow' => 3,
            'shadow_only' => 2,
            'allow_sim'   => 2,
            'sim_only'    => 1,
            'reject'      => 0,
            default       => 0,
        };
    }

    // -------------------------------------------------------------------------
    // Private: passport loading
    // -------------------------------------------------------------------------

    /**
     * Load the best available passport for a signal, trying canonical symbol first,
     * then normalized, then the raw symbol.
     *
     * Returns a result array with passport and lookup diagnostics.
     *
     * @return array{passport: array<string,mixed>|null, lookup_symbol: string, lookup_status: string, lookup_reason: string}
     */
    private function loadPassportForSignal(array $signal): array
    {
        $canonical  = strtoupper((string)($signal['symbol_canonical']  ?? ''));
        $normalized = strtoupper((string)($signal['symbol_normalized'] ?? ''));
        $original   = strtoupper((string)($signal['symbol']            ?? ''));

        // Try canonical first
        if ($canonical !== '') {
            $p = $this->loadPassport($canonical);
            if ($p !== null) {
                return ['passport' => $p, 'lookup_symbol' => $canonical, 'lookup_status' => 'found', 'lookup_reason' => 'found_by_canonical'];
            }
        }

        // Try normalized if different from canonical
        if ($normalized !== '' && $normalized !== $canonical) {
            $p = $this->loadPassport($normalized);
            if ($p !== null) {
                return ['passport' => $p, 'lookup_symbol' => $normalized, 'lookup_status' => 'found', 'lookup_reason' => 'found_by_normalized'];
            }
        }

        // Try original uppercase if different from both
        if ($original !== '' && $original !== $canonical && $original !== $normalized) {
            $p = $this->loadPassport($original);
            if ($p !== null) {
                return ['passport' => $p, 'lookup_symbol' => $original, 'lookup_status' => 'found', 'lookup_reason' => 'found_by_original'];
            }
        }

        // Not found — collect all tried symbols for the reason string
        $tried = array_unique(array_filter([$canonical, $normalized, $original]));
        return [
            'passport'      => null,
            'lookup_symbol' => $canonical ?: $original,
            'lookup_status' => 'not_found',
            'lookup_reason' => 'no_passport_for:' . implode('|', $tried),
        ];
    }

    /** @return array<string,mixed>|null */
    private function loadPassport(string $symbol): ?array
    {
        if ($symbol === '') {
            return null;
        }
        $upper = strtoupper($symbol);
        if (isset($this->passportCache[$upper])) {
            return $this->passportCache[$upper];
        }
        $path = $this->passportDir . '/' . $upper . '.json';
        if (!file_exists($path)) {
            $this->passportCache[$upper] = null;
            return null;
        }
        $decoded = json_decode((string)file_get_contents($path), true);
        $this->passportCache[$upper] = is_array($decoded) ? $decoded : null;
        return $this->passportCache[$upper];
    }
}
