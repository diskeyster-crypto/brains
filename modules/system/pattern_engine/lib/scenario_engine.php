<?php
declare(strict_types=1);

/**
 * ScenarioEngine
 *
 * Applies scenario profiles on top of normalized signals.
 *
 * A scenario = pattern signal + Coin Passport guidance + market regime + scenario profile.
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

    /** @var array<string,array<string,mixed>> */
    private array $passportCache = [];

    private string $passportDir;

    /**
     * @param array<string,array<string,mixed>> $profiles  Scenario profiles from config
     * @param string                            $passportDir  Path to coin_passport passports/
     */
    public function __construct(array $profiles, string $passportDir)
    {
        $this->profiles    = $profiles;
        $this->passportDir = $passportDir;
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
        $passport = $this->loadPassport((string)($signal['symbol'] ?? ''));
        $profiles = $this->matchingProfiles($signal);

        if (empty($profiles)) {
            return $this->buildDecision($signal, null, null, 'reject', 'no_matching_profile', $passport);
        }

        // Try each profile, return the most permissive allowed result
        $best = null;
        foreach ($profiles as $profileName => $profile) {
            $decision = $this->applyProfile($signal, $profile, $profileName, $passport);
            if ($best === null || $this->statusRank($decision['scenario_status']) > $this->statusRank($best['scenario_status'])) {
                $best = $decision;
            }
        }

        return $best ?? $this->buildDecision($signal, null, null, 'reject', 'evaluation_failed', $passport);
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
        ?array $passport
    ): array {
        $signalStrength  = (float)($signal['signal_strength'] ?? 0);
        $qualityScore    = (float)($signal['quality_score'] ?? 0);
        $minStrength     = (float)($profile['min_signal_strength'] ?? 0.3);
        $minQuality      = (float)($profile['min_quality_score'] ?? 0.3);

        // --- signal quality gate ---
        if ($signalStrength < $minStrength) {
            return $this->buildDecision($signal, $profile, $profileName, 'reject', 'signal_strength_below_threshold', $passport);
        }
        if ($qualityScore < $minQuality) {
            return $this->buildDecision($signal, $profile, $profileName, 'reject', 'quality_score_below_threshold', $passport);
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
                ]
            );
        }

        $executionModeHint = (string)($profile['execution_mode_hint'] ?? 'allow_demo');
        $reason            = 'profile_passed:' . $profileName;

        return $this->buildDecision($signal, $profile, $profileName, $executionModeHint, $reason, $passport, [
            'passport_ok'    => true,
            'market_ok'      => true,
            'scenario_score' => $scenarioScore,
        ]);
    }

    // -------------------------------------------------------------------------
    // Private: passport checks
    // -------------------------------------------------------------------------

    /** @return array{bool, string|null} */
    private function checkPassport(array $passport, array $profile, array $signal): array
    {
        $algorithm = (string)($signal['pattern_algorithm'] ?? '');

        // Corridor P75
        $minCorridorP75 = (float)($profile['min_corridor_p75_roi'] ?? 0.0);
        if ($minCorridorP75 > 0) {
            // Try pattern-specific, then generic
            $cp75 = (float)(
                $passport['pattern_stats'][$algorithm]['corridor_p75'] ??
                $passport['corridor_p75'] ??
                0
            );
            if ($cp75 < $minCorridorP75) {
                return [false, 'corridor_p75_below_threshold'];
            }
        }

        // Runner probability
        $minRunnerProb = (float)($profile['min_runner_probability'] ?? 0.0);
        if ($minRunnerProb > 0) {
            $rp = (float)($passport['runner_probability'] ?? $passport['pattern_stats'][$algorithm]['runner_rate'] ?? 0);
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

        // Confidence
        $minConfidence = (string)($profile['min_confidence'] ?? '');
        if ($minConfidence !== '') {
            $confidenceMap  = ['none' => 0, 'low' => 1, 'medium' => 2, 'high' => 3];
            $passportConf   = strtolower((string)($passport['confidence'] ?? 'none'));
            $confLevel      = $confidenceMap[$passportConf] ?? 0;
            $requiredLevel  = $confidenceMap[$minConfidence] ?? 0;
            if ($confLevel < $requiredLevel) {
                return [false, 'confidence_below_threshold'];
            }
        }

        // Live eligibility from passport
        $eligibility = (string)($passport['recommended_live_eligibility'] ?? '');
        if (in_array($eligibility, ['reject'], true)) {
            return [false, 'passport_live_eligibility_reject'];
        }

        return [true, null];
    }

    /** @return array{bool, string|null} */
    private function checkMarketRegime(array $passport, array $profile): array
    {
        $minRegimeHealth = (float)($profile['min_regime_health'] ?? 0.0);
        if ($minRegimeHealth > 0) {
            $regimeHealth = (float)($passport['market_regime_health_score'] ?? $passport['regime_health'] ?? 0);
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
        $conf       = strtolower((string)($passport['confidence'] ?? 'none'));
        $confScore  = match ($conf) {
            'high'   => 1.0,
            'medium' => 0.7,
            'low'    => 0.4,
            default  => 0.1,
        };
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
        array $extra = []
    ): array {
        $passportOk  = (bool)($extra['passport_ok'] ?? ($passport !== null));
        $marketOk    = (bool)($extra['market_ok'] ?? true);
        $scenScore   = (float)($extra['scenario_score'] ?? 0.0);

        [$allowLive, $allowDemo, $allowShadow, $allowSim, $liveBlockReason] =
            $this->statusFlags($status, $reason);

        $maxHold = (int)($profile['max_hold_minutes'] ?? 0);

        return [
            'scenario_id'               => 'sc_' . substr(hash('sha256', ($signal['signal_id'] ?? '') . $status . microtime(true) . random_int(0, PHP_INT_MAX)), 0, 12),
            'signal_id'                 => $signal['signal_id'] ?? '',
            'symbol'                    => $signal['symbol'] ?? '',
            'side'                      => $signal['side'] ?? '',
            'pattern_algorithm'         => $signal['pattern_algorithm'] ?? '',
            'scenario_status'           => $status,
            'scenario_score'            => round($scenScore, 4),
            'scenario_reason'           => $reason,
            'execution_mode_hint'       => $this->executionModeHint($status),
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
            'diagnostics'               => [
                'status'          => $status,
                'reason'          => $reason,
                'passport_reason' => $extra['passport_reason'] ?? null,
                'market_reason'   => $extra['market_reason'] ?? null,
                'signal_strength' => $signal['signal_strength'] ?? null,
                'quality_score'   => $signal['quality_score'] ?? null,
                'profile'         => $profileName,
            ],
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
