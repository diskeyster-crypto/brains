<?php

declare(strict_types=1);

namespace Modules\ProfManager\Profiles\Long;

use Modules\ProfManager\Lib\RiskMath;
use Modules\ProfManager\Lib\ProfitLockPlanner;
use Modules\ProfManager\Lib\CandleReader;

/**
 * LongProfile
 *
 * Baseline long-position profit management — legacy_safe_long logic.
 *
 * Lifecycle per position:
 *   ROI < init_roi                         → skip (below_init_roi)
 *   init_roi <= ROI < activation_roi       → track peak, observe only (below_activation_roi)
 *   ROI >= activation_roi (via peak_roi)   → run step-trailing lock planner
 *
 * Hybrid Long overlay (when hybrid_enabled = true):
 *   After legacy lock logic, applies pattern-based exit confirmation layer:
 *     idle               → on pattern detected: waiting_confirmation + guard stop
 *     waiting_confirmation → confirm or reject; on reject: breathing trailing recovery
 *   Pattern: long_structure_weak_high — detected from parser2 price series (score >= 3).
 *   Falls back to legacy-only when no candle data is available.
 *
 * Reads and writes own state to profiles/long/storage/.
 * Demo only — no exchange actions.
 */
class LongProfile
{
    private string $storageDir;
    private array  $config;
    private RiskMath $riskMath;
    private ProfitLockPlanner $planner;
    private CandleReader $candleReader;
    private string $parser2StorageDir;

    public function __construct(string $profileDir, array $configOverrides = [])
    {
        $this->storageDir = rtrim($profileDir, '/') . '/storage';
        $this->config     = $this->loadConfig($profileDir, $configOverrides);
        $this->riskMath   = new RiskMath();
        $this->planner    = new ProfitLockPlanner($this->riskMath);
        $this->candleReader = new CandleReader();
        $this->parser2StorageDir = $this->resolveParser2StorageDir($profileDir);
        $this->ensureStorage();
    }

    /**
     * Process a single long position.
     *
     * @param array $position Normalized position (side = 'long')
     * @param int   $nowTs    Current unix timestamp
     * @return array {action, skip_reason, roi, peak_roi, lock_price, lock_active, profile_used, notes,
     *               hybrid_state, hybrid_pattern_detected, hybrid_pattern_type,
     *               hybrid_confirmation_ticks, hybrid_confirmation_result,
     *               hybrid_guard_stop, hybrid_guard_active,
     *               hybrid_breathing_stop, hybrid_breathing_active,
     *               hybrid_simulation_enabled,
     *               hybrid_detection_score, hybrid_support_level, hybrid_detection_evidence}
     */
    public function process(array $position, int $nowTs): array
    {
        $symbol = (string) ($position['symbol'] ?? '');
        $key    = $this->positionKey($symbol, 'long');

        $positionsState = $this->readState();
        $locks          = $this->readLocks();

        $positionState = $positionsState[$key] ?? [];
        $lockState     = $locks[$key]          ?? [];

        // ── STEP 1: run legacy_safe_long lifecycle ────────────────────────────
        $runResult = $this->runLifecycle($position, $lockState, $positionState, $this->config, $nowTs);

        $positionState = $runResult['position_state'];
        $lockState     = $runResult['lock_state'];
        $plan          = $runResult['plan'];

        // Update lock entry for legacy lock actions
        if (in_array($plan['action'], ['would_set_profit_lock', 'would_move_profit_lock'], true)) {
            $locks[$key] = [
                'symbol'        => $symbol,
                'side'          => 'long',
                'lock_price'    => $plan['proposed_lock'],
                'lock_roi'      => $plan['proposed_roi'],
                'action'        => $plan['action'],
                'updated_at'    => date('c', $nowTs),
                'updated_at_ts' => $nowTs,
            ];
        } elseif (!empty($lockState)) {
            $locks[$key] = $lockState;
        }

        // ── STEP 2–4: hybrid overlay ──────────────────────────────────────────
        $simEnabled = !empty($this->config['hybrid_simulation_enabled']);
        $hybridMeta = [
            'hybrid_state'               => 'idle',
            'hybrid_pattern_detected'    => false,
            'hybrid_pattern_type'        => null,
            'hybrid_confirmation_ticks'  => 0,
            'hybrid_confirmation_result' => null,
            'hybrid_guard_stop'          => null,
            'hybrid_guard_active'        => false,
            'hybrid_breathing_stop'      => null,
            'hybrid_breathing_active'    => false,
            'hybrid_simulation_enabled'  => $simEnabled,
            'hybrid_detection_score'     => null,
            'hybrid_support_level'       => null,
            'hybrid_detection_evidence'  => null,
            'hybrid_detection_reason'    => null,
        ];

        if (!empty($this->config['hybrid_enabled'])) {
            [$plan, $positionState, $hybridMeta] = $this->applyHybridOverlay(
                $position,
                $positionState,
                $locks[$key] ?? [],
                $plan,
                $nowTs
            );
        }

        // Persist updated state (includes hybrid fields)
        $positionsState[$key] = $positionState;
        $this->writeState($positionsState);
        $this->writeLocks($locks);

        $lockRecord = $locks[$key] ?? [];
        $lockPrice  = isset($lockRecord['lock_price']) ? (float) $lockRecord['lock_price'] : null;

        return [
            'action'                    => $plan['action']               ?? 'skip',
            'skip_reason'               => $plan['skip_reason']          ?? null,
            'roi'                       => $plan['current_roi']          ?? null,
            'peak_roi'                  => $plan['peak_roi']             ?? null,
            'lock_price'                => ($lockPrice !== null && $lockPrice > 0.0) ? $lockPrice : null,
            'lock_active'               => ($lockPrice !== null && $lockPrice > 0.0),
            'profile_used'              => 'legacy_safe_long',
            'notes'                     => !empty($plan['note']) ? [$plan['note']] : [],
            'init_roi'                  => (float) ($this->config['init_roi']       ?? 2.0),
            'activation_roi'            => (float) ($this->config['activation_roi'] ?? 10.0),
            'distance_pct'              => $plan['distance_pct']               ?? null,
            'min_required_distance_pct' => $plan['min_required_distance_pct']  ?? null,
            'hybrid_state'               => $hybridMeta['hybrid_state'],
            'hybrid_pattern_detected'    => $hybridMeta['hybrid_pattern_detected'],
            'hybrid_pattern_type'        => $hybridMeta['hybrid_pattern_type'],
            'hybrid_confirmation_ticks'  => $hybridMeta['hybrid_confirmation_ticks'],
            'hybrid_confirmation_result' => $hybridMeta['hybrid_confirmation_result'],
            'hybrid_guard_stop'          => $hybridMeta['hybrid_guard_stop'],
            'hybrid_guard_active'        => $hybridMeta['hybrid_guard_active'],
            'hybrid_breathing_stop'      => $hybridMeta['hybrid_breathing_stop'],
            'hybrid_breathing_active'    => $hybridMeta['hybrid_breathing_active'],
            'hybrid_simulation_enabled'  => $hybridMeta['hybrid_simulation_enabled'],
            'hybrid_detection_score'     => $hybridMeta['hybrid_detection_score'],
            'hybrid_support_level'       => $hybridMeta['hybrid_support_level'],
            'hybrid_detection_evidence'  => $hybridMeta['hybrid_detection_evidence'],
            'hybrid_detection_reason'    => $hybridMeta['hybrid_detection_reason'],
        ];
    }

    /**
     * Return the count of active lock entries in this profile's storage.
     */
    public function getLockCount(): int
    {
        $locks = $this->readLocks();
        return count($locks);
    }

    /**
     * Remove state and lock entries for symbols no longer in the active position list.
     *
     * Called by the service router after routing all positions in a tick.
     * Only cleans profiles/long/storage/{state,locks}.json — historical logs are untouched.
     *
     * @param list<string> $activeKeys  Position keys (symbol_long) currently active this tick
     * @return array{long_state_cleaned: int, long_locks_cleaned: int}
     */
    public function cleanStale(array $activeKeys): array
    {
        $statesCleaned = 0;
        $locksCleaned  = 0;

        $state = $this->readState();
        foreach (array_keys($state) as $key) {
            if (!in_array($key, $activeKeys, true)) {
                unset($state[$key]);
                $statesCleaned++;
            }
        }

        $locks = $this->readLocks();
        foreach (array_keys($locks) as $key) {
            if (!in_array($key, $activeKeys, true)) {
                unset($locks[$key]);
                $locksCleaned++;
            }
        }

        if ($statesCleaned > 0) {
            $this->writeState($state);
        }
        if ($locksCleaned > 0) {
            $this->writeLocks($locks);
        }

        return [
            'long_state_cleaned' => $statesCleaned,
            'long_locks_cleaned' => $locksCleaned,
        ];
    }

    // =========================================================================
    // Lifecycle logic (migrated from ProfileLegacySafe)
    // =========================================================================

    private function runLifecycle(
        array $position,
        array $lockState,
        array $positionState,
        array $profileConfig,
        int   $nowTs
    ): array {
        $symbol = $position['symbol'] ?? '';
        $side   = strtolower(trim($position['side'] ?? ''));

        // ── ROI gate ──────────────────────────────────────────────────────────
        $currentRoi = $this->riskMath->calculateRoiPct($position);
        $initRoi    = (float) ($profileConfig['init_roi'] ?? 2.0);

        if ($currentRoi === null || $currentRoi < $initRoi) {
            return [
                'plan' => [
                    'action'       => 'skip',
                    'symbol'       => $symbol,
                    'side'         => $side,
                    'skip_reason'  => $currentRoi === null ? 'cannot_calculate_roi' : 'below_init_roi',
                    'current_roi'  => $currentRoi,
                    'peak_roi'     => null,
                    'proposed_lock'=> null,
                    'proposed_roi' => null,
                    'note'         => null,
                ],
                'position_state' => $positionState,
                'lock_state'     => $lockState,
            ];
        }

        // ── Peak ROI tracking ─────────────────────────────────────────────────
        $peakRoi = (float) ($positionState['peak_roi'] ?? $currentRoi);
        if ($currentRoi > $peakRoi) {
            $peakRoi = $currentRoi;
        }

        $entryPrice    = (float) ($position['entry_price'] ?? $position['avg_price'] ?? 0.0);
        $positionState = array_merge($positionState, [
            'symbol'        => $symbol,
            'side'          => $side,
            'entry_price'   => $entryPrice,
            'leverage'      => (float) ($position['leverage'] ?? 0.0),
            'current_roi'   => $currentRoi,
            'peak_roi'      => $peakRoi,
            'updated_at'    => date('c', $nowTs),
            'updated_at_ts' => $nowTs,
        ]);

        if (!isset($positionState['tracked_since'])) {
            $positionState['tracked_since']    = date('c', $nowTs);
            $positionState['tracked_since_ts'] = $nowTs;
        }

        // ── Observe only below activation ─────────────────────────────────────
        $activationRoi = (float) ($profileConfig['activation_roi'] ?? 10.0);
        if ($peakRoi < $activationRoi) {
            return [
                'plan' => [
                    'action'       => 'skip',
                    'symbol'       => $symbol,
                    'side'         => $side,
                    'skip_reason'  => 'below_activation_roi',
                    'current_roi'  => $currentRoi,
                    'peak_roi'     => $peakRoi,
                    'proposed_lock'=> null,
                    'proposed_roi' => null,
                    'note'         => null,
                ],
                'position_state' => $positionState,
                'lock_state'     => $lockState,
            ];
        }

        // ── Plan profit lock via ProfitLockPlanner ────────────────────────────
        $plan = $this->planner->plan($position, $lockState, $positionState, $profileConfig, $nowTs);

        if (in_array($plan['action'], ['would_set_profit_lock', 'would_move_profit_lock'], true)) {
            $lockState = [
                'symbol'        => $symbol,
                'side'          => $side,
                'lock_price'    => $plan['proposed_lock'],
                'lock_roi'      => $plan['proposed_roi'],
                'action'        => $plan['action'],
                'updated_at'    => date('c', $nowTs),
                'updated_at_ts' => $nowTs,
            ];
        }

        return [
            'plan'           => $plan,
            'position_state' => $positionState,
            'lock_state'     => $lockState,
        ];
    }

    // =========================================================================
    // Hybrid Long overlay
    // =========================================================================

    /**
     * Pattern detection — long_structure_weak_high.
     *
     * Reads recent price series from parser2 NDJSON storage and scores a set of
     * structural weakness conditions. Returns detected=true when score >= min_score.
     *
     * Simulation override takes priority:
     *   hybrid_simulation_force_detect=true → detected=true, pattern_type='simulated_exit_pattern'
     *
     * @param array $position      Normalized position data
     * @param array $positionState Current per-symbol state
     * @return array{detected:bool, pattern_type:string|null, confidence:float, reason:string|null, evidence:array}
     */
    private function detectExitPattern(array $position, array $positionState): array
    {
        $noDetect = static fn(string $reason, array $ev = []): array => [
            'detected'     => false,
            'pattern_type' => null,
            'confidence'   => 0.0,
            'reason'       => $reason,
            'evidence'     => $ev,
        ];

        // ── Simulation override (priority) ────────────────────────────────────
        $simEnabled = !empty($this->config['hybrid_simulation_enabled']);
        if ($simEnabled && !empty($this->config['hybrid_simulation_force_detect'])) {
            $simSymbol = strtoupper(trim((string) ($this->config['hybrid_simulation_pattern_symbol'] ?? '')));
            $posSymbol = strtoupper(trim((string) ($position['symbol'] ?? '')));
            if ($simSymbol === '' || $simSymbol === $posSymbol) {
                // [SIMULATION] forced detection — demo/dev only
                return [
                    'detected'     => true,
                    'pattern_type' => 'simulated_exit_pattern',
                    'confidence'   => 1.0,
                    'reason'       => 'simulation_forced',
                    'evidence'     => [],
                ];
            }
        }

        // ── ROI gate: position must be in profit ──────────────────────────────
        $currentRoi = (float) ($positionState['current_roi'] ?? 0.0);
        if ($currentRoi <= 0.0) {
            return $noDetect('not_in_profit');
        }

        // ── Read price series from parser2 NDJSON storage ────────────────────
        $symbol      = strtoupper(trim((string) ($position['symbol'] ?? '')));
        $minPoints   = (int)   ($this->config['detection_min_price_points'] ?? 15);
        $maxPoints   = (int)   ($this->config['detection_max_price_points'] ?? 60);
        $lookbackSec = (int)   ($this->config['detection_lookback_sec']     ?? 3600);

        $pricePoints = $this->candleReader->readRecentPrices(
            $symbol,
            $this->parser2StorageDir,
            $maxPoints,
            $lookbackSec
        );

        $n = count($pricePoints);
        if ($n < $minPoints) {
            return $noDetect('no_candle_data', ['points_available' => $n, 'min_required' => $minPoints]);
        }

        $prices = array_column($pricePoints, 'price');

        // ── Window split: early 40% | middle 20% | recent 40% ────────────────
        $earlyEnd  = max(1, (int) round($n * 0.40));
        $middleEnd = max($earlyEnd + 1, (int) round($n * 0.60));

        $earlyPrices  = array_slice($prices, 0, $earlyEnd);
        $middlePrices = array_slice($prices, $earlyEnd, $middleEnd - $earlyEnd);
        $recentPrices = array_slice($prices, $middleEnd);

        if (empty($earlyPrices) || empty($middlePrices) || empty($recentPrices)) {
            return $noDetect('insufficient_window_data', ['n' => $n]);
        }

        $previousHigh  = max($earlyPrices);
        $earlyLow      = min($earlyPrices);
        $currentHigh   = max($recentPrices);
        $recentLow     = min($recentPrices);
        $lastHigherLow = min($middlePrices);
        $supportLevel  = $lastHigherLow;

        $currentPrice = (float) ($position['current_price'] ?? $position['mark_price']
            ?? ($prices !== [] ? end($prices) : 0.0));

        $marginFactor = (float) ($this->config['detection_weak_high_margin_pct'] ?? 0.10) / 100.0;
        $minScore     = (int)   ($this->config['detection_min_score'] ?? 3);
        $initRoi      = (float) ($this->config['init_roi'] ?? 2.0);

        $score         = 0;
        $evidenceFlags = [];

        // Score 1: Failed higher high — current high did not exceed previous high
        $failedHigherHigh = ($currentHigh <= $previousHigh * (1.0 + $marginFactor));
        if ($failedHigherHigh) {
            $score++;
            $evidenceFlags[] = 'failed_higher_high';
        }

        // Score 2: Lower high — current high is actually below previous high
        $lowerHigh = ($currentHigh < $previousHigh * (1.0 - $marginFactor));
        if ($lowerHigh) {
            $score++;
            $evidenceFlags[] = 'lower_high';
        }

        // Score 3: Current price at or below local support
        $supportBreakCandidate = ($currentPrice < $supportLevel * (1.0 + $marginFactor));
        if ($supportBreakCandidate) {
            $score++;
            $evidenceFlags[] = 'support_break_candidate';
        }

        // Score 4: Shrinking impulse — recent range < early range * threshold
        $earlyRange       = max(1e-9, $previousHigh - $earlyLow);
        $recentRange      = max(0.0, $currentHigh - $recentLow);
        $shrinkingImpulse = ($recentRange < $earlyRange * 0.85);
        if ($shrinkingImpulse) {
            $score++;
            $evidenceFlags[] = 'shrinking_impulse';
        }

        // Score 5: In solid profit zone (roi >= init_roi)
        $inProfitZone = ($currentRoi >= $initRoi);
        if ($inProfitZone) {
            $score++;
            $evidenceFlags[] = 'in_profit_zone';
        }

        $detected   = ($score >= $minScore);
        $confidence = min(1.0, $score / 5.0);

        $evidence = [
            'previous_high'            => round($previousHigh,  6),
            'current_high'             => round($currentHigh,   6),
            'last_higher_low'          => round($lastHigherLow, 6),
            'support_level'            => round($supportLevel,  6),
            'current_price'            => round($currentPrice,  6),
            'failed_higher_high'       => $failedHigherHigh,
            'lower_high'               => $lowerHigh,
            'support_break_candidate'  => $supportBreakCandidate,
            'shrinking_impulse'        => $shrinkingImpulse,
            'in_profit_zone'           => $inProfitZone,
            'score'                    => $score,
            'points_used'              => $n,
        ];

        return [
            'detected'     => $detected,
            'pattern_type' => $detected ? 'long_structure_weak_high' : null,
            'confidence'   => $confidence,
            'reason'       => 'score_' . $score . '_of_5',
            'evidence'     => $evidence,
        ];
    }

    /**
     * Exit pattern confirmation.
     *
     * Returns whether the currently-detected pattern has been confirmed or rejected.
     * Minimum tick count is NOT sufficient on its own — this method must return
     * confirmed=true for the position to be closed.
     *
     * Real confirmation conditions (any one triggers):
     *   - current price breaks below stored support_level (support_break)
     *   - two or more recent closes below support_level (two_closes_below_support)
     *   - strong drop from the detected high (strong_drop)
     *
     * Real rejection conditions:
     *   - price makes new higher high above previous_high (new_higher_high)
     *
     * Simulation overrides (demo/dev only, checked first):
     *   force_confirm → confirmed=true
     *   force_reject  → rejected=true
     *   (force_confirm takes precedence if both are set)
     *
     * Default: confirmed=false, rejected=false (keep waiting).
     *
     * @param array $position      Normalized position data
     * @param array $positionState Current per-symbol state (has stored detection evidence)
     * @return array{confirmed:bool, rejected:bool, reason:string|null, evidence:array}
     */
    private function confirmExitPattern(array $position, array $positionState): array
    {
        $pending = static fn(?string $reason = null, array $ev = []): array => [
            'confirmed' => false,
            'rejected'  => false,
            'reason'    => $reason,
            'evidence'  => $ev,
        ];

        // ── Simulation overrides (priority) ───────────────────────────────────
        $simEnabled = !empty($this->config['hybrid_simulation_enabled']);
        if ($simEnabled) {
            $simSymbol = strtoupper(trim((string) ($this->config['hybrid_simulation_pattern_symbol'] ?? '')));
            $posSymbol = strtoupper(trim((string) ($position['symbol'] ?? '')));
            $symbolMatch = ($simSymbol === '' || $simSymbol === $posSymbol);

            if ($symbolMatch && !empty($this->config['hybrid_simulation_force_confirm'])) {
                // [SIMULATION] forced confirmation — demo/dev only
                return ['confirmed' => true,  'rejected' => false, 'reason' => 'simulated_confirm', 'evidence' => []];
            }
            if ($symbolMatch && !empty($this->config['hybrid_simulation_force_reject'])) {
                // [SIMULATION] forced rejection — demo/dev only
                return ['confirmed' => false, 'rejected' => true,  'reason' => 'simulated_reject',  'evidence' => []];
            }
        }

        // ── Load stored detection evidence ────────────────────────────────────
        $supportLevel  = isset($positionState['support_level'])  ? (float) $positionState['support_level']  : 0.0;
        $previousHigh  = isset($positionState['previous_high'])  ? (float) $positionState['previous_high']  : 0.0;
        $detectedHigh  = isset($positionState['detected_high'])  ? (float) $positionState['detected_high']  : 0.0;
        $currentPrice  = (float) ($position['current_price'] ?? $position['mark_price'] ?? 0.0);
        $symbol        = strtoupper(trim((string) ($position['symbol'] ?? '')));

        // Cannot decide without stored evidence
        if ($supportLevel <= 0.0 || $previousHigh <= 0.0) {
            return $pending();
        }

        $confirmBreakPct  = (float) ($this->config['confirm_support_break_pct']   ?? 0.15) / 100.0;
        $newHighMarginPct = (float) ($this->config['confirm_new_high_margin_pct'] ?? 0.20) / 100.0;
        $strongDropPct    = (float) ($this->config['confirm_strong_drop_pct']     ?? 2.0)  / 100.0;

        $ev = [
            'support_level' => round($supportLevel, 6),
            'previous_high' => round($previousHigh, 6),
            'detected_high' => round($detectedHigh, 6),
            'current_price' => round($currentPrice, 6),
        ];

        // ── Rejection: price made a new higher high ───────────────────────────
        if ($currentPrice > $previousHigh * (1.0 + $newHighMarginPct)) {
            $ev['rejection_type'] = 'new_higher_high';
            return ['confirmed' => false, 'rejected' => true, 'reason' => 'new_higher_high', 'evidence' => $ev];
        }

        // ── Confirmation: clean break below support level ─────────────────────
        if ($currentPrice < $supportLevel * (1.0 - $confirmBreakPct)) {
            $ev['confirmation_type'] = 'support_break';
            return ['confirmed' => true, 'rejected' => false, 'reason' => 'support_break', 'evidence' => $ev];
        }

        // ── Confirmation: strong drop from detected high ──────────────────────
        if ($detectedHigh > 0.0 && $currentPrice < $detectedHigh * (1.0 - $strongDropPct)) {
            $ev['confirmation_type'] = 'strong_drop';
            return ['confirmed' => true, 'rejected' => false, 'reason' => 'strong_drop', 'evidence' => $ev];
        }

        // ── Confirmation: two consecutive closes below support ────────────────
        $maxPoints   = (int) ($this->config['detection_max_price_points'] ?? 60);
        $lookbackSec = (int) ($this->config['detection_lookback_sec']     ?? 3600);
        $pricePoints = $this->candleReader->readRecentPrices($symbol, $this->parser2StorageDir, $maxPoints, $lookbackSec);

        if (count($pricePoints) >= 2) {
            $recentSlice = array_slice(array_column($pricePoints, 'price'), -3);
            $closesBelow = 0;
            foreach ($recentSlice as $p) {
                if ($p < $supportLevel) {
                    $closesBelow++;
                }
            }
            if ($closesBelow >= 2) {
                $ev['confirmation_type'] = 'two_closes_below_support';
                $ev['closes_below']      = $closesBelow;
                return ['confirmed' => true, 'rejected' => false, 'reason' => 'two_closes_below_support', 'evidence' => $ev];
            }
        }

        return $pending(null, $ev);
    }

    /**
     * Apply hybrid overlay on top of the legacy plan.
     *
     * STEP 2: On fresh pattern detection (hybrid_state=idle) → activate guard stop.
     *         Stores detection evidence (support_level, previous_high, detected_high,
     *         detection_score) into positionState for use by confirmExitPattern().
     * STEP 3: In waiting_confirmation → increment tick counter; resolve via confirmExitPattern().
     *         NOTE: confirmation_ticks is a minimum observation gate only.
     *         Actual confirmation requires confirmExitPattern().confirmed = true.
     * STEP 4: On rejection or window expiry → restore breathing trailing (legacy lock is floor).
     *
     * @param array $position      Normalized position
     * @param array $positionState Per-symbol mutable state (will be updated with hybrid fields)
     * @param array $lockState     Current lock record for this symbol
     * @param array $plan          Legacy plan (may have action overridden)
     * @param int   $nowTs         Current unix timestamp
     * @return array{0: array, 1: array, 2: array} [$plan, $positionState, $hybridMeta]
     */
    private function applyHybridOverlay(
        array $position,
        array $positionState,
        array $lockState,
        array $plan,
        int   $nowTs
    ): array {
        $currentPrice = (float) ($position['current_price'] ?? $position['mark_price'] ?? 0.0);
        $leverage     = (float) ($position['leverage'] ?? 0.0);
        $simEnabled   = !empty($this->config['hybrid_simulation_enabled']);

        $hybridState        = (string) ($positionState['hybrid_state']         ?? 'idle');
        $patternDetectedAt  = isset($positionState['pattern_detected_at'])
            ? (int) $positionState['pattern_detected_at']
            : null;
        $confirmationTicks  = (int)   ($positionState['confirmation_ticks']    ?? 0);
        $guardStopPrice     = isset($positionState['guard_stop_price'])
            ? (float) $positionState['guard_stop_price']
            : null;
        $lastBreathingStop  = isset($positionState['last_breathing_stop'])
            ? (float) $positionState['last_breathing_stop']
            : null;
        $lastPatternType    = isset($positionState['pattern_type'])
            ? (string) $positionState['pattern_type']
            : null;

        // Read persisted detection evidence for hybridMeta display
        $detectionScore    = isset($positionState['detection_score'])    ? (int)    $positionState['detection_score']    : null;
        $supportLevelState = isset($positionState['support_level'])      ? (float)  $positionState['support_level']      : null;
        $detectionEvidence = isset($positionState['detection_evidence']) ? (string) $positionState['detection_evidence'] : null;
        $detectionReason   = isset($positionState['detection_reason'])   ? (string) $positionState['detection_reason']   : null;

        $patternResult   = $this->detectExitPattern($position, $positionState);
        $patternDetected = (bool) ($patternResult['detected']     ?? false);
        $patternType     = $patternResult['pattern_type'] ?? null;
        // Always track the most recent detection reason (e.g. no_candle_data, insufficient_window_data, score_X_of_5)
        $detectionReason = $patternResult['reason'] ?? $detectionReason;

        $hybridAction       = null;
        $guardActive        = false;
        $breathingActive    = false;
        $newBreathingStop   = null;
        $confirmationResult = null;

        // ── STEP 2: fresh pattern detection while idle ────────────────────────
        if ($patternDetected && $hybridState === 'idle') {
            $hybridState       = 'waiting_confirmation';
            $patternDetectedAt = $nowTs;
            $confirmationTicks = 0;
            $lastPatternType   = $patternType;

            $guardOffset = ($currentPrice > 0.0 && $leverage > 0.0)
                ? $this->riskMath->roiDistanceToPriceOffset(
                    $currentPrice,
                    (float) ($this->config['guard_roi_distance'] ?? 3.0),
                    $leverage
                )
                : null;

            $guardStopPrice = ($guardOffset !== null) ? $currentPrice - $guardOffset : null;
            $hybridAction   = 'hybrid_guard_activated';

            // Store detection evidence into positionState for confirmation use
            $ev = $patternResult['evidence'] ?? [];
            $positionState['support_level']       = $ev['support_level']   ?? null;
            $positionState['previous_high']       = $ev['previous_high']   ?? null;
            $positionState['detected_high']       = $ev['current_high']    ?? null;
            $positionState['last_higher_low']     = $ev['last_higher_low'] ?? null;
            $positionState['detection_score']     = $ev['score']           ?? null;
            $positionState['detection_evidence']  = $this->summarizeEvidence($ev);

            $detectionScore    = $positionState['detection_score'];
            $supportLevelState = $positionState['support_level'];
            $detectionEvidence = $positionState['detection_evidence'];
        }

        // ── STEP 3: confirmation loop ─────────────────────────────────────────
        if ($hybridState === 'waiting_confirmation') {
            $confirmationTicks++;
            $guardActive = ($guardStopPrice !== null && $guardStopPrice > 0.0);

            $minTicks      = (int)  ($this->config['pattern_confirmation_min_ticks']  ?? 2);
            $windowSec     = (int)  ($this->config['pattern_confirmation_window_sec'] ?? 300);
            $windowExpired = ($patternDetectedAt !== null)
                && (($nowTs - $patternDetectedAt) > $windowSec);

            if ($confirmationTicks < $minTicks && !$windowExpired) {
                // Still in minimum observation window — do nothing yet
                $hybridAction = 'waiting_confirmation';
            } elseif ($windowExpired) {
                // Window expired without confirmation
                $confirmationResult = 'window_expired';
                $hybridAction       = 'hybrid_rejected';
                $hybridState        = 'idle';
                $guardStopPrice     = null;
            } else {
                // Min ticks met — ask confirmExitPattern() for actual decision
                $confirmResult = $this->confirmExitPattern($position, $positionState);

                if ($confirmResult['confirmed']) {
                    $confirmationResult = $confirmResult['reason'] ?? 'confirmed';
                    $hybridAction       = 'hybrid_close_confirmed';
                    $hybridState        = 'idle';
                    $confirmationTicks  = 0;
                    $guardStopPrice     = null;
                } elseif ($confirmResult['rejected']) {
                    $confirmationResult = $confirmResult['reason'] ?? 'rejected';
                    $hybridAction       = 'hybrid_rejected';
                    $hybridState        = 'idle';
                    $guardStopPrice     = null;
                } else {
                    // Still pending — keep waiting
                    $hybridAction = 'waiting_confirmation';
                }
            }

            // ── STEP 4: rejection recovery ────────────────────────────────────
            if ($hybridAction === 'hybrid_rejected') {
                $breathingRoiDist = (float) ($this->config['breathing_roi_distance_min'] ?? 5.0);
                $breathingOffset  = ($currentPrice > 0.0 && $leverage > 0.0)
                    ? $this->riskMath->roiDistanceToPriceOffset($currentPrice, $breathingRoiDist, $leverage)
                    : null;
                $rawBreathingStop = ($breathingOffset !== null) ? $currentPrice - $breathingOffset : null;

                // Respect legacy lock — final stop = max(legacy_lock, breathing_stop)
                $legacyLockPrice = isset($lockState['lock_price']) ? (float) $lockState['lock_price'] : 0.0;
                if ($rawBreathingStop !== null) {
                    $newBreathingStop = ($legacyLockPrice > 0.0)
                        ? max($legacyLockPrice, $rawBreathingStop)
                        : $rawBreathingStop;
                    $breathingActive   = ($newBreathingStop > 0.0);
                    $lastBreathingStop = $newBreathingStop;
                }
            }
        }

        // ── Persist hybrid fields back into positionState ─────────────────────
        $positionState['hybrid_state']        = $hybridState;
        $positionState['pattern_detected_at'] = $patternDetectedAt;
        $positionState['pattern_type']        = $lastPatternType;
        $positionState['confirmation_ticks']  = $confirmationTicks;
        $positionState['guard_stop_price']    = $guardStopPrice;
        $positionState['last_breathing_stop'] = $lastBreathingStop;

        // ── Override plan action if hybrid produced one ───────────────────────
        if ($hybridAction !== null) {
            $plan['action']      = $hybridAction;
            $plan['skip_reason'] = null;
        }

        $hybridMeta = [
            'hybrid_state'               => $hybridState,
            'hybrid_pattern_detected'    => $patternDetected,
            'hybrid_pattern_type'        => $lastPatternType ?? $patternType,
            'hybrid_confirmation_ticks'  => $confirmationTicks,
            'hybrid_confirmation_result' => $confirmationResult,
            'hybrid_guard_stop'          => ($guardStopPrice !== null && $guardStopPrice > 0.0) ? $guardStopPrice : null,
            'hybrid_guard_active'        => $guardActive,
            'hybrid_breathing_stop'      => ($newBreathingStop !== null && $newBreathingStop > 0.0) ? $newBreathingStop : null,
            'hybrid_breathing_active'    => $breathingActive,
            'hybrid_simulation_enabled'  => $simEnabled,
            'hybrid_detection_score'     => $detectionScore,
            'hybrid_support_level'       => ($supportLevelState !== null && $supportLevelState > 0.0) ? $supportLevelState : null,
            'hybrid_detection_evidence'  => $detectionEvidence,
            'hybrid_detection_reason'    => $detectionReason,
        ];

        return [$plan, $positionState, $hybridMeta];
    }

    /**
     * Build a compact human-readable evidence summary string.
     *
     * Example: "score:4|fhh,lh,si,ipz"
     *
     * @param array<string,mixed> $evidence
     */
    private function summarizeEvidence(array $evidence): string
    {
        $flags = [];
        if (!empty($evidence['failed_higher_high']))      $flags[] = 'fhh';
        if (!empty($evidence['lower_high']))              $flags[] = 'lh';
        if (!empty($evidence['support_break_candidate'])) $flags[] = 'sbc';
        if (!empty($evidence['shrinking_impulse']))       $flags[] = 'si';
        if (!empty($evidence['in_profit_zone']))          $flags[] = 'ipz';

        $score = isset($evidence['score']) ? (int) $evidence['score'] : 0;
        return 'score:' . $score . ($flags !== [] ? '|' . implode(',', $flags) : '');
    }

    /**
     * Resolve the absolute path to parser2_history_accumulator/storage.
     *
     * Auto-detects by navigating up from profileDir:
     *   .../modules/prof_manager/profiles/long → .../modules/prof_manager/profiles → .../modules
     *   then appends /parser/parser2_history_accumulator/storage
     *
     * Config key 'parser2_storage_path' (non-empty) overrides auto-detection.
     *
     * @param string $profileDir Absolute path to this profile directory
     */
    private function resolveParser2StorageDir(string $profileDir): string
    {
        $override = trim((string) ($this->config['parser2_storage_path'] ?? ''));
        if ($override !== '') {
            return rtrim($override, '/');
        }

        // profileDir = .../modules/prof_manager/profiles/long
        // dirname x3 = .../modules
        $modulesDir = dirname(dirname(dirname($profileDir)));
        return $modulesDir . '/parser/parser2_history_accumulator/storage';
    }

    // =========================================================================
    // Config
    // =========================================================================

    private function loadConfig(string $profileDir, array $overrides): array
    {
        $configPath = rtrim($profileDir, '/') . '/config.php';
        $base = [];
        if (is_file($configPath)) {
            try {
                $loaded = require $configPath;
                if (is_array($loaded)) {
                    $base = $loaded;
                }
            } catch (\Throwable) {
                // Config file parse errors are non-fatal; profile falls back to defaults
            }
        }
        return array_merge($base, $overrides);
    }

    // =========================================================================
    // Storage
    // =========================================================================

    private function ensureStorage(): void
    {
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0775, true);
        }
        foreach (['state.json', 'locks.json', 'patterns.json'] as $file) {
            $path = $this->storageDir . '/' . $file;
            if (!file_exists($path)) {
                file_put_contents($path, "{}\n", LOCK_EX);
            }
        }
    }

    private function readState(): array
    {
        return $this->readJson($this->storageDir . '/state.json');
    }

    private function writeState(array $data): void
    {
        $this->writeJson($this->storageDir . '/state.json', $data);
    }

    private function readLocks(): array
    {
        return $this->readJson($this->storageDir . '/locks.json');
    }

    private function writeLocks(array $data): void
    {
        $this->writeJson($this->storageDir . '/locks.json', $data);
    }

    private function readJson(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function writeJson(string $path, array $data): void
    {
        file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            LOCK_EX
        );
    }

    private function positionKey(string $symbol, string $side): string
    {
        return strtolower($symbol) . '_' . strtolower($side);
    }
}
