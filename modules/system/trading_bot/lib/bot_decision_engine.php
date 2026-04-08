<?php
declare(strict_types=1);

namespace Modules\System\TradingBot\Lib;

/**
 * BotDecisionEngine
 *
 * Generates a canonical decision packet for every signal/intent.
 * Encapsulates routing logic: enter_live / enter_demo / skip.
 *
 * RULES (from roadmap):
 *  - Rule-engine first, no AI.
 *  - Decision based on coin passport + signal quality.
 *  - Auto mode: system routes based on confidence. Manual mode: user params respected, packet still recorded.
 *  - Low-confidence cases → demo (not dropped).
 *  - No silent drops.
 *
 * Required decision packet fields (roadmap Phase 1):
 *  decision_id, signal_id, symbol, side, pattern_algorithm, confidence_score,
 *  confidence_band, decision, execution_mode, reason_codes, passport_snapshot,
 *  corridor_snapshot, proposed_budget, proposed_leverage,
 *  proposed_stop_profile, proposed_trailing_profile
 *
 * Allowed decisions:  enter_live | enter_demo | skip
 * Confidence bands:   green | yellow | red | gray
 */
final class BotDecisionEngine
{
    private string $storageDir;
    private string $passportsDir;

    public function __construct(string $storageDir, string $passportsDir)
    {
        $this->storageDir   = rtrim($storageDir, '/');
        $this->passportsDir = rtrim($passportsDir, '/');
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Generate a canonical decision packet for one intent.
     *
     * @param array  $intent   The signal/intent to evaluate
     * @param string $botMode  Current bot mode: live | demo | paper | dry
     * @param array  $config   Bot runtime config (used for auto_mode flag)
     * @return array           Complete decision packet
     */
    public function makeDecision(array $intent, string $botMode, array $config, array $context = []): array
    {
        $autoMode = (bool)($config['execution']['auto_mode'] ?? false);

        $symbol           = strtoupper((string)($intent['symbol'] ?? ''));
        $side             = strtolower((string)($intent['side'] ?? ''));
        $signalId         = (string)($intent['signal_id'] ?? $intent['id'] ?? '');
        $intentId         = (string)($intent['intent_id'] ?? '');
        $patternAlgorithm = (string)($intent['pattern_algorithm'] ?? $intent['pattern_type'] ?? '');
        $confidenceScore  = (float)($intent['confidence_score']
            ?? $intent['quality_score']
            ?? $intent['signal_strength']
            ?? 0.0);

        $liveRoutingPolicy = (string)($config['execution']['live_routing_policy'] ?? 'green_only');

        $passport       = $this->loadPassport($symbol);
        $signalStrength = (float)($intent['signal_strength'] ?? 0.0);
        $qualityScore   = (float)($intent['quality_score'] ?? 0.0);
        $confidenceBand = $this->computeConfidenceBand($confidenceScore, $passport, $signalStrength, $qualityScore);

        // Compute route_state with live routing policy awareness (yellow promotion).
        // route_state is the single source of truth for final routing.
        $yellowCaps  = null;
        $routeState  = $this->computeRouteStateWithPolicy(
            $confidenceBand, $liveRoutingPolicy, $passport, $intent, $config, $context, $yellowCaps
        );

        // Decision and execution_mode are derived ONLY from route_state — never from a
        // parallel confidence-band path. This ensures route_state = demo_learn cannot
        // produce decision = enter_live regardless of autoMode or botMode.
        $decision      = $this->computeDecisionFromRouteState($routeState, $botMode);
        $executionMode = $this->resolveExecutionMode($decision, $botMode);
        $reasonCodes   = $this->computeReasonCodes($confidenceBand, $passport, $botMode, $autoMode, $intent);

        // Phase 2: flag parallel demo suggestion when live mode + non-red confidence
        $parallelDemoSuggested = ($botMode === 'live')
            && in_array($confidenceBand, ['green', 'yellow', 'gray'], true);

        $decisionId = $this->generateDecisionId($symbol, $signalId);

        return [
            // Identity
            'decision_id'               => $decisionId,
            'signal_id'                 => $signalId,
            'intent_id'                 => $intentId,

            // Signal context
            'symbol'                    => $symbol,
            'side'                      => $side,
            'side_original'             => (string)($intent['side_original'] ?? $side),
            'source'                    => (string)($intent['source'] ?? ''),
            'pattern_algorithm'         => $patternAlgorithm,
            'pattern_version'           => (string)($intent['pattern_version'] ?? ''),
            'signal_strength'           => (float)($intent['signal_strength'] ?? 0),
            'quality_score'             => (float)($intent['quality_score'] ?? 0),
            'scenario_id'               => (string)($intent['scenario_id'] ?? ''),
            'scenario_score'            => (float)($intent['scenario_score'] ?? 0),

            // Confidence
            'confidence_score'          => round($confidenceScore, 4),
            'confidence_band'           => $confidenceBand,

            // Routing
            'route_state'                    => $routeState,
            'decision'                       => $decision,
            'execution_mode'                 => $executionMode,
            'auto_mode'                      => $autoMode,
            'bot_mode'                       => $botMode,
            'live_routing_policy'            => $liveRoutingPolicy,
            'reason_codes'                   => $reasonCodes,

            // Yellow live admission observability (only set when confidence_band=yellow)
            'yellow_live_eligible'           => $yellowCaps !== null ? (bool)$yellowCaps['eligible'] : null,
            'yellow_live_block_reason'       => $yellowCaps !== null ? $yellowCaps['block_reason'] : null,
            'yellow_live_current_positions'  => $yellowCaps !== null ? (int)($yellowCaps['open_count'] ?? 0) : null,
            'yellow_live_current_samples'    => $yellowCaps !== null ? (int)($yellowCaps['healthy_samples'] ?? 0) : null,
            'yellow_live_budget_multiplier'  => (float)($config['execution']['yellow_live_budget_multiplier'] ?? 0.30),
            'yellow_live_max_positions'      => (int)($config['execution']['yellow_live_max_positions'] ?? 1),
            'yellow_live_max_leverage'       => (int)($config['execution']['yellow_live_max_leverage'] ?? 2),
            'yellow_live_min_samples_required' => (int)($config['execution']['yellow_live_require_min_healthy_samples'] ?? 3),

            // Phase 2: parallel demo tracking
            'parallel_demo_suggested'   => $parallelDemoSuggested,

            // Evidence snapshots
            'passport_snapshot'         => $this->buildPassportSnapshot($passport),
            'corridor_snapshot'         => $this->buildCorridorSnapshot($passport),

            // Proposed risk params (from intent + config)
            'proposed_budget'           => $this->extractBudget($intent, $config),
            'proposed_leverage'         => $this->extractLeverage($intent),
            'proposed_stop_profile'     => $this->extractStopProfile($intent),
            'proposed_trailing_profile' => $this->extractTrailingProfile($intent),

            // Metadata
            'created_at'                => date('c'),
            'ts'                        => time(),
        ];
    }

    /**
     * Persist a decision packet to storage/{storageDir}/runtime/decisions/{decision_id}.json
     */
    public function saveDecisionPacket(array $packet): void
    {
        $dir = $this->storageDir . '/runtime/decisions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $id   = $packet['decision_id'] ?? ('dec_unknown_' . time());
        $path = $dir . '/' . $id . '.json';
        @file_put_contents(
            $path,
            json_encode($packet, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    /**
     * Load a decision packet by its decision_id.
     */
    public function loadDecisionPacket(string $decisionId): ?array
    {
        if ($decisionId === '') {
            return null;
        }
        $path = $this->storageDir . '/runtime/decisions/' . $decisionId . '.json';
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    // =========================================================================
    // Confidence computation
    // =========================================================================

    /**
     * Compute confidence band from signal quality + passport.
     *
     * green  – strong signal + live-eligible passport with solid evidence
     * yellow – medium confidence; live or demo, some evidence
     * red    – poor confidence (bad passport performance + weak signal)
     * gray   – insufficient data to classify; send to demo to learn
     */
    private function computeConfidenceBand(
        float  $confidenceScore,
        ?array $passport,
        float  $signalStrength = 0.0,
        float  $qualityScore   = 0.0
    ): string {
        // Use best available signal quality indicator
        $bestSignal = max($confidenceScore, $signalStrength, $qualityScore);

        if ($passport === null) {
            // No passport yet — very strong signal can still be green (live-worthy),
            // moderate signal gets yellow (demo-learn), weak signal stays gray.
            if ($bestSignal >= 0.85) return 'green';
            if ($bestSignal >= 0.75) return 'yellow';
            return 'gray';
        }

        // Use precomputed trust_state if present (populated by passport rebuild)
        $trustState = (string)($passport['trust_state'] ?? '');
        if ($trustState !== '') {
            switch ($trustState) {
                case 'green':
                    // Green passport: decent signal → green confidence; weak signal → yellow
                    return $bestSignal >= 0.40 ? 'green' : 'yellow';
                case 'yellow':
                    // Yellow passport: strong signal → green (live-worthy); moderate → yellow;
                    // weak signal → red (clearly bad evidence → skip-eligible); else gray to keep learning
                    if ($bestSignal >= 0.55) return 'green';
                    if ($bestSignal >= 0.45) return 'yellow';
                    if ($bestSignal < 0.38) return 'red';
                    return 'gray';
                case 'red':
                    // Red passport: only strong signal can yield yellow; otherwise red → skip
                    return $bestSignal >= 0.75 ? 'yellow' : 'red';
                case 'insufficient_data':
                    // Insufficient data — very strong signal overrides to green (live-worthy);
                    // strong signal yields yellow; very weak → red; else gray to keep learning.
                    if ($bestSignal >= 0.85) return 'green';
                    if ($bestSignal >= 0.70) return 'yellow';
                    if ($bestSignal < 0.35) return 'red';
                    return 'gray';
            }
        }

        // Fallback: derive from raw passport metrics
        $liveEligibility = (string)($passport['recommended_live_eligibility'] ?? 'sim_only');
        $dataConfidence  = (string)($passport['data_confidence'] ?? 'none');
        $noiseScore      = (float)($passport['noise_score'] ?? 1.0);

        if ($dataConfidence === 'none' || ($passport['insufficient_data_flag'] ?? false)) {
            return 'gray';
        }

        if ($liveEligibility === 'live_eligible'
            && in_array($dataConfidence, ['medium', 'high'], true)
            && $noiseScore <= 0.55
            && $bestSignal >= 0.50) {
            return 'green';
        }

        if ($liveEligibility === 'live_eligible' && $bestSignal >= 0.40) {
            return 'yellow';
        }

        if ($dataConfidence === 'low' && $liveEligibility === 'sim_only') {
            return 'gray';
        }

        if ($noiseScore > 0.70 || $bestSignal < 0.30) {
            return 'red';
        }

        return 'yellow';
    }

    // =========================================================================
    // Routing
    // =========================================================================

    /**
     * Compute route_state with live routing policy awareness.
     *
     * route_state is the SINGLE SOURCE OF TRUTH for final routing.
     * decision and execution_mode are derived ONLY from route_state.
     *
     * Under green_only (default):
     *   green  → green_live_worthy
     *   red    → skip
     *   yellow/gray → demo_learn
     *
     * Under green_plus_yellow_capped:
     *   green  → green_live_worthy
     *   red    → skip
     *   yellow → green_live_worthy IF all caps pass; otherwise demo_learn
     *   gray   → demo_learn (always)
     *
     * @param  string $confidenceBand
     * @param  string $liveRoutingPolicy  'green_only' | 'green_plus_yellow_capped'
     * @param  ?array $passport
     * @param  array  $intent
     * @param  array  $config
     * @param  array  $context           e.g. ['open_yellow_live_count' => N]
     * @param  array|null &$yellowCaps   OUT — cap check result when band=yellow; null otherwise
     * @return string  route_state value
     */
    private function computeRouteStateWithPolicy(
        string  $confidenceBand,
        string  $liveRoutingPolicy,
        ?array  $passport,
        array   $intent,
        array   $config,
        array   $context,
        ?array  &$yellowCaps
    ): string {
        $yellowCaps = null;

        switch ($confidenceBand) {
            case 'green':
                return 'green_live_worthy';
            case 'red':
                return 'skip';
            case 'yellow':
                if ($liveRoutingPolicy === 'green_plus_yellow_capped') {
                    $yellowCaps = $this->checkYellowLiveCaps($passport, $intent, $config, $context);
                    if ($yellowCaps['eligible']) {
                        return 'green_live_worthy'; // yellow promoted under capped policy
                    }
                }
                return 'demo_learn';
            default:
                // gray or unknown → demo_learn
                return 'demo_learn';
        }
    }

    /**
     * Check whether a yellow-confidence intent passes all yellow live caps.
     *
     * Returns ['eligible' => bool, 'block_reason' => string|null, ...diagnostics...]
     */
    private function checkYellowLiveCaps(
        ?array $passport,
        array  $intent,
        array  $config,
        array  $context
    ): array {
        $maxPositions = (int)($config['execution']['yellow_live_max_positions'] ?? 1);
        $maxLeverage  = (int)($config['execution']['yellow_live_max_leverage'] ?? 2);
        $budgetMult   = (float)($config['execution']['yellow_live_budget_multiplier'] ?? 0.30);
        $minSamples   = (int)($config['execution']['yellow_live_require_min_healthy_samples'] ?? 3);

        $openCount      = (int)($context['open_yellow_live_count'] ?? 0);
        $leverage       = (float)($intent['risk']['leverage'] ?? $intent['leverage'] ?? $config['exchange']['leverage'] ?? 5);
        $proposedBudget = (float)($intent['risk']['budget'] ?? $intent['budget'] ?? 0.0);
        $baseBudget     = (float)($config['execution']['budget_per_order']
                            ?? $config['demo_sources']['demo_risk_defaults']['budget_usdt_per_trade']
                            ?? 0.0);
        $healthySamples = (int)($passport['healthy_closed_samples'] ?? 0);

        $blockReasons = [];
        if ($openCount >= $maxPositions) {
            $blockReasons[] = 'max_yellow_positions_reached:' . $openCount . '>=' . $maxPositions;
        }
        if ($leverage > $maxLeverage) {
            $blockReasons[] = 'leverage_exceeds_cap:' . $leverage . '>' . $maxLeverage;
        }
        if ($proposedBudget > 0 && $baseBudget > 0 && $proposedBudget > $baseBudget * $budgetMult) {
            $blockReasons[] = 'budget_exceeds_cap:' . round($proposedBudget, 2) . '>' . round($baseBudget * $budgetMult, 2);
        }
        if ($healthySamples < $minSamples) {
            $blockReasons[] = 'insufficient_healthy_samples:' . $healthySamples . '<' . $minSamples;
        }

        return [
            'eligible'        => empty($blockReasons),
            'block_reason'    => empty($blockReasons) ? null : implode('; ', $blockReasons),
            'open_count'      => $openCount,
            'leverage'        => $leverage,
            'proposed_budget' => $proposedBudget,
            'healthy_samples' => $healthySamples,
        ];
    }

    /**
     * Compute the routing decision from route_state.
     *
     * route_state is the single authoritative source. This is the ONLY place
     * where decision is derived — there is no parallel path.
     *
     *   green_live_worthy → enter_live (live bot) or enter_demo (demo/paper bot)
     *   demo_learn        → enter_demo (ALWAYS, regardless of bot mode)
     *   skip              → skip
     */
    private function computeDecisionFromRouteState(string $routeState, string $botMode): string
    {
        switch ($routeState) {
            case 'green_live_worthy':
                return $botMode === 'live' ? 'enter_live' : 'enter_demo';
            case 'demo_learn':
                return 'enter_demo';
            case 'skip':
                return 'skip';
            default:
                return 'enter_demo';
        }
    }

    /**
     * @deprecated Use computeRouteStateWithPolicy() and computeDecisionFromRouteState() instead.
     * Kept only for reference; no longer called from makeDecision().
     */
    private function computeRouteState(string $confidenceBand): string
    {
        switch ($confidenceBand) {
            case 'green':
                return 'green_live_worthy';
            case 'red':
                return 'skip';
            default:
                return 'demo_learn';
        }
    }

    /**
     * @deprecated Replaced by computeDecisionFromRouteState(). No longer called from makeDecision().
     * Kept for reference only.
     */
    private function computeDecision(string $confidenceBand, string $botMode, bool $autoMode): string
    {
        if (!$autoMode) {
            return in_array($botMode, ['live'], true) ? 'enter_live' : 'enter_demo';
        }
        switch ($confidenceBand) {
            case 'green':
                return $botMode === 'live' ? 'enter_live' : 'enter_demo';
            case 'yellow':
                return 'enter_demo';
            case 'gray':
                return 'enter_demo';
            case 'red':
                return 'skip';
            default:
                return 'enter_demo';
        }
    }

    /**
     * Resolve execution_mode string from decision + botMode.
     */
    private function resolveExecutionMode(string $decision, string $botMode): string
    {
        switch ($decision) {
            case 'enter_live':  return 'live';
            case 'enter_demo':  return 'demo';
            case 'skip':        return 'skip';
            default:            return $botMode;
        }
    }

    // =========================================================================
    // Reason codes
    // =========================================================================

    private function computeReasonCodes(
        string $confidenceBand,
        ?array $passport,
        string $botMode,
        bool $autoMode,
        array $intent
    ): array {
        $codes = [];

        $codes[] = $autoMode ? 'auto_mode_routing' : 'manual_mode_user_decision';
        $codes[] = 'confidence_band_' . $confidenceBand;

        if ($passport === null) {
            $codes[] = 'no_passport_data';
        } else {
            $eli = (string)($passport['recommended_live_eligibility'] ?? '');
            if ($eli !== '') {
                $codes[] = 'passport_eligibility_' . $eli;
            }
            $dc = (string)($passport['data_confidence'] ?? '');
            if ($dc !== '') {
                $codes[] = 'passport_data_confidence_' . $dc;
            }
            if ($passport['insufficient_data_flag'] ?? false) {
                $codes[] = 'passport_insufficient_data';
            }
            $ts = (string)($passport['trust_state'] ?? '');
            if ($ts !== '') {
                $codes[] = 'passport_trust_state_' . $ts;
            }
        }

        if (!empty($intent['is_orphan_adopted']) || !empty($intent['adopted_from_exchange_orphan'])) {
            $codes[] = 'orphan_adopted_intent';
        }

        if (!empty($intent['brain_controlled'])) {
            $codes[] = 'brain_controlled_intent';
        }

        return $codes;
    }

    // =========================================================================
    // Passport / corridor snapshots
    // =========================================================================

    private function buildPassportSnapshot(?array $passport): array
    {
        if ($passport === null) {
            return ['available' => false];
        }
        return [
            'available'                    => true,
            'symbol'                       => $passport['symbol'] ?? null,
            'trust_state'                  => $passport['trust_state'] ?? null,
            'recommended_live_eligibility' => $passport['recommended_live_eligibility'] ?? null,
            'data_confidence'              => $passport['data_confidence'] ?? null,
            'sample_size_total'            => $passport['sample_size_total'] ?? null,
            'noise_score'                  => $passport['noise_score'] ?? null,
            'runner_probability'           => $passport['runner_probability'] ?? null,
            'sl_survival_score'            => $passport['sl_survival_score'] ?? null,
            'updated_at'                   => $passport['updated_at'] ?? null,
        ];
    }

    private function buildCorridorSnapshot(?array $passport): array
    {
        if ($passport === null) {
            return ['available' => false];
        }
        return [
            'available'    => true,
            'corridor_p50' => $passport['corridor_roi_p50'] ?? null,
            'corridor_p75' => $passport['corridor_roi_p75'] ?? null,
            'corridor_p90' => $passport['corridor_roi_p90'] ?? null,
            'corridor_min' => $passport['corridor_roi_min'] ?? null,
        ];
    }

    // =========================================================================
    // Proposed risk extraction
    // =========================================================================

    private function extractBudget(array $intent, array $config): ?float
    {
        $b = $intent['risk']['budget'] ?? $intent['budget'] ?? $config['execution']['budget_per_order'] ?? null;
        return $b !== null ? (float)$b : null;
    }

    private function extractLeverage(array $intent): ?int
    {
        $l = $intent['risk']['leverage'] ?? $intent['leverage'] ?? null;
        return $l !== null ? (int)$l : null;
    }

    private function extractStopProfile(array $intent): array
    {
        $risk = is_array($intent['risk'] ?? null) ? $intent['risk'] : [];
        return [
            'stop_loss_pct'   => $risk['stop_loss_pct'] ?? null,
            'take_profit_pct' => $risk['take_profit_pct'] ?? null,
        ];
    }

    private function extractTrailingProfile(array $intent): array
    {
        $trailing = is_array($intent['risk']['trailing'] ?? null) ? $intent['risk']['trailing'] : [];
        return [
            'trailing_enabled'        => $trailing['enabled'] ?? null,
            'trailing_activation_roi' => $trailing['activation_roi'] ?? $trailing['activation_roi_pct'] ?? null,
            'drawdown_factor'         => $trailing['drawdown_factor'] ?? null,
        ];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Load passport JSON for a symbol. Returns null if not found or not valid.
     * If the stored passport exists but does not contain trust_state, triggers
     * an inline rebuild via CoinPassportEngine so future reads include trust_state.
     */
    private function loadPassport(string $symbol): ?array
    {
        if ($symbol === '' || $this->passportsDir === '') {
            return null;
        }
        $path = $this->passportsDir . '/' . $symbol . '.json';
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        // Determine if an inline rebuild is needed:
        // 1. trust_state absent: always rebuild (existing migration path).
        // 2. healthy_closed_samples absent: old format passport needs one-time migration.
        // 3. trust_state = 'insufficient_data' with accumulated healthy closes: rebuild immediately so
        //    passports stuck under old stricter thresholds are re-evaluated.
        // 4. trust_state = 'insufficient_data' and passport is stale (>2 h) but no healthy closes yet:
        //    periodic refresh to pick up any new evidence.
        $needsRebuild = !array_key_exists('trust_state', $data);
        if (!$needsRebuild && !array_key_exists('healthy_closed_samples', $data)) {
            $needsRebuild = true;
        }
        if (!$needsRebuild && ($data['trust_state'] ?? '') === 'insufficient_data') {
            // Force rebuild immediately if healthy closes have accumulated since last evaluation —
            // these passports may have been evaluated under stricter thresholds and are now stuck.
            if ((int)($data['healthy_closed_samples'] ?? 0) > 0) {
                $needsRebuild = true;
            } elseif (isset($data['updated_at'])
                && (time() - (int)strtotime($data['updated_at'])) > 7200) {
                $needsRebuild = true;
            }
        }
        if ($needsRebuild) {
            $data = $this->rebuildPassportInline($symbol, $path, $data);
        }

        return $data;
    }

    /**
     * Rebuild a single passport file inline using CoinPassportEngine.
     * Returns the refreshed passport (with trust_state) on success, or the original
     * array as a fallback if the engine cannot be loaded.
     *
     * @param array<string,mixed> $existing Existing passport data (used as fallback)
     * @return array<string,mixed>
     */
    private function rebuildPassportInline(string $symbol, string $path, array $existing): array
    {
        // Resolve CoinPassportEngine path: it lives alongside this module under modules/system/
        $passportEngineFile = dirname(__DIR__, 2) . '/coin_passport/lib/passport_engine.php';
        if (!is_file($passportEngineFile)) {
            return $existing;
        }
        if (!class_exists('CoinPassportEngine', false)) {
            require_once $passportEngineFile;
        }
        if (!class_exists('CoinPassportEngine', false)) {
            return $existing;
        }
        try {
            // CoinPassportEngine constructor: passportsDir, tradingBotStorage, aiShadowStorage
            $moduleBase        = dirname(__DIR__);
            $tradingBotStorage = $moduleBase . '/storage';
            $aiShadowStorage   = dirname($moduleBase) . '/ai_shadow/storage';
            $engine = new \CoinPassportEngine($this->passportsDir, $tradingBotStorage, $aiShadowStorage);
            $rebuilt = $engine->rebuildSymbol($symbol);
            if (is_array($rebuilt) && array_key_exists('trust_state', $rebuilt)) {
                return $rebuilt;
            }
        } catch (\Throwable $e) {
            // Non-fatal: fall back to existing data
        }
        return $existing;
    }

    /**
     * Generate a unique decision_id.
     *
     * Format: dec_{symbol_8}_{signal_hash_8}_{unixts}
     */
    private function generateDecisionId(string $symbol, string $signalId): string
    {
        $symPart  = substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($symbol)), 0, 8);
        $sigPart  = substr(md5($signalId . microtime()), 0, 8);
        return 'dec_' . $symPart . '_' . $sigPart . '_' . time();
    }
}
