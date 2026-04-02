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
     * @param array<string,array<string,mixed>> $profiles          Scenario profiles from config
     * @param string                            $passportDir       Path to coin_passport passports/
     * @param bool                              $liveOutputEnabled Master live-output gate (default false)
     */
    public function __construct(array $profiles, string $passportDir, bool $liveOutputEnabled = false)
    {
        $this->profiles          = $profiles;
        $this->passportDir       = $passportDir;
        $this->liveOutputEnabled = $liveOutputEnabled;
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
            ]),
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
            'allow_demo'    => [false, true,  true,  true,  $reason],
            'allow_shadow'  => [false, false, true,  true,  $reason],
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
