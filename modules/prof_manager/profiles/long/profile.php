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
    public function process(array $position, int $nowTs, array $wallContext = [], array $ctx = []): array
    {
        $allowStateWrite = (bool)($ctx['allow_state_write'] ?? true);
        $symbol = (string) ($position['symbol'] ?? '');
        $key    = self::buildPositionIdentityKey($position, 'long');

        // ── Append current price to PM-owned price history ────────────────────
        $posPrice = (float) ($position['current_price'] ?? $position['mark_price'] ?? 0.0);
        if ($posPrice > 0.0 && !empty($this->config['price_history_enabled'])) {
            $this->appendPricePoint(
                $symbol,
                $posPrice,
                $nowTs,
                (string) ($position['price_source'] ?? 'bybit_gateway')
            );
        }

        $positionsState = $this->readState();
        $locks          = $this->readLocks();

        $positionState = $positionsState[$key] ?? [];
        $lockState     = $locks[$key]          ?? [];

        // ── Position identity validation ───────────────────────────────────────
        // Detect stale lock/position state that belongs to a previous position
        // on the same key slot.  If identity fields mismatch (or are absent on
        // a legacy record), discard the stored state so the new position starts
        // fresh and does not inherit old peak_roi/current_roi/lock state.
        $identityMismatch  = false;
        $legacyKeyIgnored  = false;
        $curSignalId   = (string)($position['signal_id']       ?? '');
        $curOpenedAt   = (string)($position['opened_at']       ?? $position['bot_submitted_at'] ?? $position['created_at'] ?? '');
        $curEntryPrice = (float)($position['entry_price']      ?? $position['avg_price'] ?? 0.0);

        // Helper: checks whether stored identity fields mismatch current position.
        $identityFieldsMismatch = function (array $stored) use ($curSignalId, $curOpenedAt, $curEntryPrice): bool {
            $storedSigId      = (string)($stored['position_signal_id']  ?? '');
            $storedOpenedAt   = (string)($stored['position_opened_at']  ?? '');
            $storedEntryPrice = (float)($stored['position_entry_price'] ?? 0.0);
            if ($storedSigId !== '' && $curSignalId !== '' && $storedSigId !== $curSignalId) {
                return true;
            }
            if ($storedOpenedAt !== '' && $curOpenedAt !== '' && $storedOpenedAt !== $curOpenedAt) {
                return true;
            }
            if ($storedEntryPrice > 0.0 && $curEntryPrice > 0.0) {
                $tol = 0.001 * max($storedEntryPrice, $curEntryPrice);
                if (abs($storedEntryPrice - $curEntryPrice) > $tol) {
                    return true;
                }
            }
            return false;
        };

        if (!empty($lockState)) {
            $hasIdentity = array_key_exists('position_signal_id',   $lockState)
                        || array_key_exists('position_opened_at',    $lockState)
                        || array_key_exists('position_entry_price',  $lockState);

            if (!$hasIdentity) {
                // Legacy record — written before identity fields were introduced.
                $legacyKeyIgnored = true;
                $lockState        = [];
                $positionState    = [];
            } elseif ($identityFieldsMismatch($lockState)) {
                $identityMismatch = true;
                $lockState        = [];
                $positionState    = [];
            }
        }

        // Guard positionState even when lockState is empty: if the stored
        // positionState carries identity fields from a previous position,
        // discard it so peak_roi/current_roi are not inherited.
        if (!$identityMismatch && !$legacyKeyIgnored && !empty($positionState)) {
            $psHasIdentity = array_key_exists('position_signal_id',   $positionState)
                          || array_key_exists('position_opened_at',    $positionState)
                          || array_key_exists('position_entry_price',  $positionState);
            if ($psHasIdentity && $identityFieldsMismatch([
                'position_signal_id'   => $positionState['position_signal_id']   ?? '',
                'position_opened_at'   => $positionState['position_opened_at']   ?? '',
                'position_entry_price' => $positionState['position_entry_price'] ?? 0.0,
            ])) {
                $identityMismatch = true;
                $positionState    = [];
            }
        }

        // ── Pre-compute ROI and effective lock buffer for impulse hold ─────────
        $currentPrice = (float) ($position['current_price'] ?? $position['mark_price'] ?? 0.0);
        $earlyRoi     = $this->riskMath->calculateRoiPct($position);
        // Save stored peak BEFORE earlyPeakRoi is raised so last_peak_at comparison is accurate.
        $storedPeakRoiBefore = (float)($positionState['peak_roi'] ?? 0.0);
        $earlyPeakRoi = (float) ($positionState['peak_roi'] ?? ($earlyRoi ?? 0.0));
        if ($earlyRoi !== null && $earlyRoi > $earlyPeakRoi) {
            $earlyPeakRoi = $earlyRoi;
        }

        $impulseCtx         = null;
        $impulseChecked     = false;
        $effectiveConfig    = $this->config;

        $impulseHoldEnabled = !empty($this->config['impulse_hold_enabled']);
        $impulseHoldMinRoi  = (float) ($this->config['impulse_hold_min_roi'] ?? 8.0);

        if ($impulseHoldEnabled && $earlyRoi !== null && $earlyRoi >= $impulseHoldMinRoi) {
            $impulseCtx     = $this->computeImpulseContext($position, $currentPrice, $earlyRoi, $earlyPeakRoi, $nowTs);
            $impulseChecked = true;

            $impulseClass = $impulseCtx['impulse_class'] ?? 'weak';
            if ($impulseClass === 'strong' || $impulseClass === 'very_strong') {
                // Widen the effective lock buffer to create more breathing room
                $normalBuf    = (float) ($this->config['lock_buffer_roi'] ?? 2.0);
                $widenBuf     = (float) ($this->config['impulse_hold_widen_lock_buffer_roi'] ?? 5.0);
                $multiplier   = (float) ($this->config['impulse_hold_lock_buffer_multiplier'] ?? 2.0);
                $effectiveBuf = max($normalBuf, $widenBuf, $normalBuf * $multiplier);
                // Do not mutate $this->config — create a local copy only
                $effectiveConfig = array_merge($this->config, ['lock_buffer_roi' => $effectiveBuf]);
            }
        }

        // ── Trend birth pre-context: compute before staircase for extra buffers ──
        // Uses roi_samples from stored state (previous ticks) to detect early trend
        // birth and widen effective lock/staircase buffers before planning.
        $trendBirthHoldEnabled        = !empty($this->config['trend_birth_hold_enabled']);
        $trendBirthPreCtx             = null;
        $trendBirthExtraLockBuffer    = 0.0;
        $trendBirthExtraStairBuffer   = 0.0;
        $trendBirthExtraLockApplied   = false;
        $trendBirthExtraStairApplied  = false;

        if ($trendBirthHoldEnabled && $earlyRoi !== null) {
            $trendBirthPreCtx = $this->computeTrendBirthContext($position, $positionState, $nowTs);
            if (($trendBirthPreCtx['trend_phase'] ?? '') === 'early_trend_birth'
                && !empty($trendBirthPreCtx['structure_intact'])
            ) {
                $trendBirthExtraLockBuffer  = (float) ($this->config['trend_birth_extra_lock_buffer_roi']      ?? 2.0);
                $trendBirthExtraStairBuffer = (float) ($this->config['trend_birth_extra_staircase_buffer_roi'] ?? 3.0);
            }
        }

        // Apply extra lock buffer to effectiveConfig if trend birth is active
        if ($trendBirthExtraLockBuffer > 0.0) {
            $curLockBuf      = (float) ($effectiveConfig['lock_buffer_roi'] ?? 2.0);
            $effectiveConfig = array_merge($effectiveConfig, ['lock_buffer_roi' => $curLockBuf + $trendBirthExtraLockBuffer]);
            $trendBirthExtraLockApplied = true;
        }

        // ── ROI staircase: raise effective lock_floor_roi ─────────────────────
        // Computed before runLifecycle so the planner uses the raised floor.
        $staircaseChecked  = false;
        $staircaseActive   = false;
        $staircaseFloorRoi = null;

        if (!empty($this->config['roi_staircase_enabled'])) {
            $staircaseMinPeak = (float) ($this->config['roi_staircase_min_peak_roi'] ?? 10.0);
            if ($earlyPeakRoi >= $staircaseMinPeak) {
                $staircaseChecked  = true;
                $staircaseFloorRoi = $this->computeStaircaseFloor($earlyPeakRoi, $trendBirthExtraStairBuffer);
                $existingFloor     = (float) ($effectiveConfig['lock_floor_roi'] ?? 5.0);
                if ($staircaseFloorRoi > $existingFloor) {
                    // Do not mutate config — local override only
                    $effectiveConfig = array_merge($effectiveConfig, ['lock_floor_roi' => $staircaseFloorRoi]);
                    $staircaseActive = true;
                    if ($trendBirthExtraStairBuffer > 0.0) {
                        $trendBirthExtraStairApplied = true;
                    }
                }
            }
        }

        // ── STEP 1: run legacy_safe_long lifecycle ────────────────────────────
        $runResult = $this->runLifecycle($position, $lockState, $positionState, $effectiveConfig, $nowTs);

        $positionState = $runResult['position_state'];
        $lockState     = $runResult['lock_state'];
        $plan          = $runResult['plan'];

        // ── Track peak_at timestamp and append ROI sample ─────────────────────
        // Compare against $storedPeakRoiBefore (the stored value before earlyPeakRoi was
        // raised in process()). earlyPeakRoi is already max(stored, current) so comparing
        // runPeakRoi against earlyPeakRoi would never detect a newly raised peak.
        $runPeakRoi = (float) ($plan['peak_roi'] ?? $earlyPeakRoi);
        $statePeakAtUpdated = false;
        if ($runPeakRoi > ($storedPeakRoiBefore + 0.001)) {
            $positionState['last_peak_at']  = $nowTs;
            $positionState['last_peak_roi'] = $runPeakRoi;
            $statePeakAtUpdated = true;
        } elseif (!isset($positionState['last_peak_at'])) {
            $positionState['last_peak_at']  = $nowTs;
            $positionState['last_peak_roi'] = $runPeakRoi;
        }
        $runRoi = $plan['current_roi'] ?? $earlyRoi;
        if ($runRoi !== null && $currentPrice > 0.0) {
            $this->appendRoiSample($positionState, (float) $runRoi, $currentPrice, $nowTs);
        }

        // Update lock entry for legacy lock actions
        if (in_array($plan['action'], ['would_set_profit_lock', 'would_move_profit_lock'], true)) {
            $locks[$key] = [
                'symbol'               => $symbol,
                'side'                 => 'long',
                'lock_price'           => $plan['proposed_lock'],
                'lock_roi'             => $plan['proposed_roi'],
                'action'               => $plan['action'],
                'updated_at'           => date('c', $nowTs),
                'updated_at_ts'        => $nowTs,
                // Position identity — used to detect stale state when the same
                // symbol+side is reused by a new, different position.
                'position_signal_id'   => $curSignalId,
                'position_opened_at'   => $curOpenedAt,
                'position_entry_price' => $curEntryPrice,
            ];
        } elseif (!empty($lockState)) {
            // Preserve existing lock but ensure identity fields are present.
            $lockState['position_signal_id']   = $curSignalId;
            $lockState['position_opened_at']   = $curOpenedAt;
            $lockState['position_entry_price'] = $curEntryPrice;
            $locks[$key] = $lockState;
        }

        // ── STEP 1c: lock-too-close unprotected handling ──────────────────────
        // When the planner skips with lock_price_too_close_to_current and no
        // active lock exists, the position is fully unprotected at high ROI.
        // - Strong/very_strong impulse: allow hold, mark as unprotected.
        // - Otherwise: emit would_close_on_lock_too_close to protect profit.
        $lockTooCloseAdjusted        = in_array('adjusted_lock_to_safe_distance', (array) ($plan['note'] ?? []), true)
                                        || ($plan['note'] ?? '') === 'adjusted_lock_to_safe_distance';
        $lockTooCloseUnprotected     = false;
        $lockTooCloseImpulseOverride = false;
        $lockTooCloseCloseAction     = false;

        if ($plan['action'] === 'skip'
            && ($plan['skip_reason'] ?? '') === 'lock_price_too_close_to_current'
            && ($lockState['lock_price'] ?? 0.0) <= 0.0  // no active lock
        ) {
            $ltcRoi        = $plan['current_roi'] ?? $earlyRoi;
            $ltcActivation = (float) ($this->config['activation_roi']          ?? 10.0);
            $ltcGraceMin   = (float) ($this->config['lock_touch_grace_min_roi'] ??  6.0);
            $ltcMinRoi     = max($ltcActivation, $ltcGraceMin);

            if ($ltcRoi !== null && (float) $ltcRoi >= $ltcMinRoi) {
                $ltcImpulseClass = $impulseCtx !== null ? ($impulseCtx['impulse_class'] ?? 'weak') : 'weak';
                if ($ltcImpulseClass === 'strong' || $ltcImpulseClass === 'very_strong') {
                    // Strong impulse: hold, but record that position is unprotected
                    $lockTooCloseImpulseOverride = true;
                    $lockTooCloseUnprotected     = true;
                    $plan['note'] = 'impulse_hold_lock_too_close_override';
                } else {
                    // No protection possible, no impulse hold — close to protect profit
                    $lockTooCloseCloseAction = true;
                    $plan['action']      = 'would_close_on_lock_too_close';
                    $plan['skip_reason'] = null;
                    $plan['note']        = 'lock_price_too_close_profit_protect';
                }
            }
        }

        // ── STEP 1b: lock-touch grace / impulse-hold override ─────────────────
        $lockTouchOverrideAction = null;
        $lockTouchOverrideReason = null;
        $closeReasonHint         = null;
        $graceChecked            = false;
        $momentumBroken          = false;

        if ($plan['action'] === 'would_close_on_lock_touch') {
            $roi     = $plan['current_roi'] ?? $earlyRoi;
            $peakRoi = $plan['peak_roi']    ?? $earlyPeakRoi;

            // Priority 1: Impulse-hold override
            if ($impulseHoldEnabled && $impulseCtx !== null) {
                $impulseClass = $impulseCtx['impulse_class'] ?? 'weak';
                if ($impulseClass === 'strong' || $impulseClass === 'very_strong') {
                    $overrideResult = $this->checkImpulseHoldOverride(
                        $position, $positionState, $impulseCtx, (float) $roi, (float) $peakRoi, $nowTs
                    );
                    $positionState = array_merge($positionState, $overrideResult['state_updates']);
                    if ($overrideResult['override']) {
                        $plan['action']          = 'hold_override_lock_touch';
                        $plan['skip_reason']     = null;
                        $plan['note']            = $overrideResult['reason'];
                        $lockTouchOverrideAction = 'hold_override_lock_touch';
                        $lockTouchOverrideReason = $overrideResult['reason'];
                    } else {
                        $lockTouchOverrideReason = $overrideResult['reason'];
                        $momentumBroken          = !empty($overrideResult['momentum_broken']);
                        if ($momentumBroken) {
                            $closeReasonHint = 'lock_touch_impulse_broken';
                        }
                    }
                }
            }

            // Priority 2: Lock-touch grace (only if impulse did not already override)
            if ($plan['action'] === 'would_close_on_lock_touch'
                && !empty($this->config['lock_touch_grace_enabled'])
            ) {
                $graceMinRoi = (float) ($this->config['lock_touch_grace_min_roi'] ?? 6.0);
                if ($roi !== null && (float) $roi >= $graceMinRoi) {
                    $graceChecked = true;
                    $graceResult  = $this->checkGraceOverride(
                        $position, $positionState, (float) $roi, (float) $peakRoi, $impulseCtx, $nowTs
                    );
                    $positionState = array_merge($positionState, $graceResult['state_updates']);
                    if ($graceResult['override']) {
                        $plan['action']          = 'hold_override_lock_touch';
                        $plan['skip_reason']     = null;
                        $plan['note']            = $graceResult['reason'];
                        $lockTouchOverrideAction = 'hold_override_lock_touch';
                        $lockTouchOverrideReason = $graceResult['reason'];
                    } else {
                        if ($lockTouchOverrideReason === null) {
                            $lockTouchOverrideReason = $graceResult['reason'];
                        }
                        if (!$momentumBroken && !empty($graceResult['momentum_broken'])) {
                            $momentumBroken = true;
                        }
                    }
                }
            }
        }

        // ── Staircase floor close reason hint ─────────────────────────────────
        // When the plan is a lock_touch close AND the staircase floor was active
        // (i.e. the raised floor caused the close), annotate the reason.
        // Strong impulse is not suppressed here — it was already evaluated above
        // and can produce a hold_override_lock_touch.
        if ($staircaseActive
            && $staircaseFloorRoi !== null
            && $plan['action'] === 'would_close_on_lock_touch'
            && $lockTouchOverrideAction === null
        ) {
            $impulseClass = $impulseCtx !== null ? ($impulseCtx['impulse_class'] ?? 'weak') : 'weak';
            if ($impulseClass !== 'strong' && $impulseClass !== 'very_strong') {
                $closeReasonHint = 'roi_staircase_floor_lost';
            }
        }

        // ── Chop / indecision exit ────────────────────────────────────────────
        $chopChecked    = false;
        $chopDetected   = false;
        $chopContext    = null;
        $chopSkipReason = null;

        $chopEnabled    = !empty($this->config['chop_exit_enabled']);
        $chopMinPeakRoi = (float) ($this->config['chop_exit_min_peak_roi'] ?? 8.0);
        // Do not chop-close if grace/impulse already overrode to a hold
        $alreadyHolding = ($plan['action'] === 'hold_override_lock_touch');

        if ($chopEnabled && !$alreadyHolding) {
            $chopRoi     = (float) ($plan['current_roi'] ?? $earlyRoi ?? 0.0);
            $chopPeakRoi = (float) ($plan['peak_roi']    ?? $earlyPeakRoi ?? 0.0);

            if ($chopPeakRoi >= $chopMinPeakRoi && $chopRoi > 0.0) {
                $chopChecked = true;
                $chopContext = $this->computeChopContext($positionState, $chopRoi, $chopPeakRoi, $nowTs);

                if ($chopContext['chop_detected']) {
                    $impulseClass = $impulseCtx !== null ? ($impulseCtx['impulse_class'] ?? 'weak') : 'weak';
                    if ($impulseClass === 'strong' || $impulseClass === 'very_strong') {
                        $chopSkipReason = 'strong_impulse';
                    } else {
                        $chopDetected        = true;
                        $plan['action']      = 'roi_chop_indecision_exit';
                        $plan['skip_reason'] = null;
                        $plan['note']        = 'chop_exit:swings=' . ($chopContext['swing_count'] ?? 0);
                    }
                } else {
                    $chopSkipReason = $chopContext['reason'] ?? 'not_enough_swings';
                }
            }
        }

        // ── STEP 1e: Wall exit check (long: ask wall above price = resistance) ──
        // Triggered only when:
        //   - wall context provided (not empty)
        //   - wall_exit_enabled = true
        //   - current ROI >= wall_exit_min_roi
        //   - nearest ask wall is persistent (not eaten/broken)
        //   - ask wall distance_pct <= wall_exit_distance_pct
        //   - price has failed to break wall for >= wall_exit_fail_checks ticks
        // Skip if wall is eaten/broken (allow impulse continuation).
        // Skip if current plan is already a hold override (grace/impulse).
        $wallExitChecked    = false;
        $wallExitTriggered  = false;
        $wallExitSkipped    = false;
        $wallExitSkipReason = null;
        $wallExitContext     = null;

        if (!empty($wallContext)) {
            $wallExitEnabled = !empty($this->config['wall_exit_enabled']);
            $wallExitMinRoi  = (float)($this->config['wall_exit_min_roi']      ?? 6.0);
            $wallExitDistPct = (float)($this->config['wall_exit_distance_pct'] ?? 0.6);
            $wallExitFail    = max(1, (int)($this->config['wall_exit_fail_checks'] ?? 2));

            $currentRoiForWall = (float)($plan['current_roi'] ?? $earlyRoi ?? 0.0);

            $askWall   = $wallContext['nearest_ask_wall'] ?? null;
            $askStatus = (string)($wallContext['ask_wall_status']  ?? 'none');

            if ($wallExitEnabled && $currentRoiForWall >= $wallExitMinRoi
                && $askWall !== null && is_array($askWall)
            ) {
                $askDist = (float)($askWall['distance_pct'] ?? 999.0);

                if ($askDist <= $wallExitDistPct) {
                    $wallExitChecked = true;

                    if ($askStatus === 'eaten' || $askStatus === 'broken') {
                        // Wall is being absorbed — allow impulse continuation
                        $wallExitSkipped    = true;
                        $wallExitSkipReason = 'wall_exit_skipped_wall_eaten';
                        $wallExitContext     = $askWall;
                        $positionState['wall_exit_fail_count'] = 0;
                    } elseif ($askStatus === 'persistent') {
                        // Accumulate fail-check counter
                        $failCount = (int)($positionState['wall_exit_fail_count'] ?? 0) + 1;
                        $positionState['wall_exit_fail_count'] = $failCount;

                        if ($failCount >= $wallExitFail) {
                            // Do not override if grace/impulse already produced a hold
                            $alreadyHoldOverride = ($plan['action'] === 'hold_override_lock_touch');
                            if (!$alreadyHoldOverride) {
                                $wallExitTriggered  = true;
                                $wallExitContext     = $askWall;
                                $plan['action']     = 'wall_exit_close';
                                $plan['skip_reason']= null;
                                $plan['note']       = 'ask_wall_rejection_profit_exit';
                                $closeReasonHint    = 'ask_wall_rejection_profit_exit';
                            }
                        }
                    } else {
                        // Wall not yet persistent — reset counter
                        $positionState['wall_exit_fail_count'] = 0;
                    }
                } else {
                    // Wall too far, reset counter
                    $positionState['wall_exit_fail_count'] = 0;
                }
            }
        }

        // Persist chop / staircase state
        $positionState['chop_detected']      = $chopDetected;
        $positionState['staircase_floor_roi'] = $staircaseFloorRoi;
        if ($chopContext !== null) {
            $positionState['last_chop_context'] = $chopContext;
        }
        if ($impulseCtx !== null) {
            $positionState['impulse_score']        = $impulseCtx['impulse_score'] ?? 0;
            $positionState['impulse_class']        = $impulseCtx['impulse_class'] ?? 'weak';
            $positionState['last_impulse_context'] = $impulseCtx;
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
            'hybrid_price_source'        => 'none',
            'hybrid_price_points'        => 0,
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

        // ── STEP 7: Trend birth hold — close veto layer ───────────────────────
        // After all normal PM logic (including hybrid overlay) has decided a close
        // action, apply the trend birth veto if the position is in early_trend_birth
        // with structure intact.  This is an overlay/veto layer — it does NOT replace
        // any upstream logic; it only overrides specific close actions.
        $trendBirthCtx           = null;
        $trendBirthHoldActive    = false;
        $trendBirthStructIntact  = false;
        $trendBirthStructBroken  = false;
        $trendBirthHigherLows    = 0;
        $trendBirthLastHLPrice   = null;
        $trendBirthGivebackRoi   = 0.0;
        $trendBirthAgeMinutes    = 0.0;
        $trendBirthCloseVetoed   = false;
        $trendBirthVetoReason    = null;
        $trendBirthNoVetoReason  = null;
        $trendBirthTrendPhase    = null;

        if ($trendBirthHoldEnabled) {
            // Recompute with the up-to-date positionState (roi_samples now include
            // the current tick's sample appended earlier in process()).
            $trendBirthCtx = $this->computeTrendBirthContext($position, $positionState, $nowTs);

            $trendBirthTrendPhase   = $trendBirthCtx['trend_phase'];
            $trendBirthStructIntact = !empty($trendBirthCtx['structure_intact']);
            $trendBirthStructBroken = !empty($trendBirthCtx['structure_broken']);
            $trendBirthHigherLows   = (int)   ($trendBirthCtx['higher_lows_count']     ?? 0);
            $trendBirthLastHLPrice  = $trendBirthCtx['last_higher_low_price']          ?? null;
            $trendBirthGivebackRoi  = (float) ($trendBirthCtx['giveback_roi']          ?? 0.0);
            $trendBirthAgeMinutes   = (float) ($trendBirthCtx['age_minutes']           ?? 0.0);

            if ($trendBirthTrendPhase === 'early_trend_birth' && $trendBirthStructIntact) {
                $trendBirthHoldActive = true;

                $tbRoi       = (float) ($plan['current_roi'] ?? $earlyRoi ?? 0.0);
                $tbPeakRoi   = (float) ($plan['peak_roi']    ?? $earlyPeakRoi ?? 0.0);
                $hardFloor   = (float) ($this->config['trend_birth_hard_floor_roi']   ?? 4.0);
                $maxGiveback = (float) ($this->config['trend_birth_max_giveback_roi'] ?? 12.0);
                $planAction  = $plan['action'] ?? 'skip';

                // Safety checks that prevent any veto
                if ($tbRoi < $hardFloor) {
                    $trendBirthNoVetoReason = 'below_hard_floor';
                } elseif (($tbPeakRoi - $tbRoi) > $maxGiveback) {
                    $trendBirthNoVetoReason = 'giveback_exceeded';
                } elseif ($planAction === 'wall_exit_close'
                    && !empty($this->config['trend_birth_allow_wall_exit'])
                ) {
                    $trendBirthNoVetoReason = 'wall_exit_not_vetoed';
                } else {
                    // Determine whether this action should be vetoed
                    $vetoLockTouch  = !empty($this->config['trend_birth_veto_lock_touch']);
                    $vetoStaircase  = !empty($this->config['trend_birth_veto_staircase_floor_lost']);
                    $vetoChop       = !empty($this->config['trend_birth_veto_chop_exit']);
                    $vetoHybrid     = !empty($this->config['trend_birth_veto_hybrid_weak_high']);

                    $shouldVeto = false;
                    $vetoReason = null;

                    if (($planAction === 'would_close_on_lock_touch') && $vetoLockTouch) {
                        // Check if this is a staircase-floor-lost close specifically
                        if ($closeReasonHint === 'roi_staircase_floor_lost' && $vetoStaircase) {
                            $shouldVeto = true;
                            $vetoReason = 'staircase_floor_lost_vetoed_trend_birth';
                        } elseif ($closeReasonHint !== 'roi_staircase_floor_lost') {
                            $shouldVeto = true;
                            $vetoReason = 'lock_touch_vetoed_trend_birth';
                        } elseif (!$vetoStaircase && $vetoLockTouch) {
                            // staircase close but veto_staircase_floor_lost is disabled,
                            // still check plain lock_touch veto
                            $shouldVeto = true;
                            $vetoReason = 'lock_touch_vetoed_trend_birth';
                        }
                    } elseif ($planAction === 'roi_chop_indecision_exit' && $vetoChop) {
                        $shouldVeto = true;
                        $vetoReason = 'chop_exit_vetoed_trend_birth';
                    } elseif ($planAction === 'hybrid_close_confirmed' && $vetoHybrid) {
                        // Only veto if confirmation is NOT based on support break
                        $confirmResult = $hybridMeta['hybrid_confirmation_result'] ?? null;
                        $supportBased  = in_array($confirmResult, ['support_break', 'two_closes_below_support'], true);
                        if (!$supportBased) {
                            $shouldVeto = true;
                            $vetoReason = 'hybrid_weak_high_vetoed_trend_birth';
                        } else {
                            $trendBirthNoVetoReason = 'hybrid_support_break_not_vetoed';
                        }
                    }

                    if ($shouldVeto) {
                        $trendBirthCloseVetoed   = true;
                        $trendBirthVetoReason    = $vetoReason;
                        $plan['action']          = 'hold_override_trend_birth';
                        $plan['skip_reason']     = null;
                        $plan['note']            = 'trend_birth_structure_intact_hold';
                        $closeReasonHint         = null;
                    } elseif ($trendBirthNoVetoReason === null) {
                        $trendBirthNoVetoReason = 'action_not_vetoed:' . $planAction;
                    }
                }
            }
        }

        // Persist updated state (includes hybrid fields)
        $positionsState[$key] = $positionState;
        if ($allowStateWrite) {
            $this->writeState($positionsState);
            $this->writeLocks($locks);
        }

        $lockRecord = $locks[$key] ?? [];
        $lockPrice  = isset($lockRecord['lock_price']) ? (float) $lockRecord['lock_price'] : null;

        return [
            'action'                    => $plan['action']               ?? 'skip',
            'allow_state_write_blocked' => !$allowStateWrite,
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
            'safe_lock_price'           => $plan['safe_lock_price']            ?? null,
            'safe_lock_roi'             => $plan['safe_lock_roi']              ?? null,
            'required_floor_roi'        => $plan['required_floor_roi']         ?? null,
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
            'hybrid_price_source'        => $hybridMeta['hybrid_price_source']     ?? 'none',
            'hybrid_price_points'        => $hybridMeta['hybrid_price_points']     ?? 0,
            'hybrid_min_close_roi'       => (float) ($this->config['hybrid_min_close_roi'] ?? 5.0),
            // ── Impulse / grace diagnostics ──────────────────────────────────
            'impulse_checked'                  => $impulseChecked,
            'impulse_score'                    => $impulseCtx['impulse_score']                    ?? null,
            'impulse_class'                    => $impulseCtx['impulse_class']                    ?? null,
            'impulse_hold_active'              => (bool) ($positionState['impulse_hold_active']   ?? false),
            'impulse_context'                  => $impulseCtx,
            'lock_touch_override_action'       => $lockTouchOverrideAction,
            'lock_touch_override_reason'       => $lockTouchOverrideReason,
            'grace_checked'                    => $graceChecked,
            'grace_active'                     => (bool) ($positionState['lock_touch_grace_active'] ?? false),
            'grace_override_count'             => (int)  ($positionState['lock_touch_grace_override_count'] ?? 0),
            'momentum_broken'                  => $momentumBroken,
            'missing_metrics'                  => !empty($impulseCtx['missing_metrics']),
            'close_reason_hint'                => $closeReasonHint,
            // ── Staircase diagnostics ─────────────────────────────────────────
            'staircase_checked'                => $staircaseChecked,
            'staircase_active'                 => $staircaseActive,
            'staircase_floor_roi'              => $staircaseFloorRoi,
            // ── Chop exit diagnostics ─────────────────────────────────────────
            'chop_checked'                     => $chopChecked,
            'chop_detected'                    => $chopDetected,
            'chop_context'                     => $chopContext,
            'chop_skip_reason'                 => $chopSkipReason,
            // ── Lock-too-close diagnostics ────────────────────────────────────
            'lock_too_close_adjusted'          => $lockTooCloseAdjusted,
            'lock_too_close_unprotected'       => $lockTooCloseUnprotected,
            'lock_too_close_impulse_override'  => $lockTooCloseImpulseOverride,
            'lock_too_close_close_action'      => $lockTooCloseCloseAction,
            // ── Position identity diagnostics ─────────────────────────────────
            'pm_state_identity_mismatch'       => $identityMismatch,
            'pm_state_legacy_key_ignored'      => $legacyKeyIgnored,
            // ── Wall exit diagnostics ─────────────────────────────────────────
            'wall_exit_checked'                => $wallExitChecked,
            'wall_exit_triggered'              => $wallExitTriggered,
            'wall_exit_skipped'                => $wallExitSkipped,
            'wall_exit_skip_reason'            => $wallExitSkipReason,
            'wall_exit_context'                => $wallExitContext,
            // ── Trend birth hold diagnostics ──────────────────────────────────
            'trend_phase'                           => $trendBirthTrendPhase,
            'trend_birth_hold_enabled'              => $trendBirthHoldEnabled,
            'trend_birth_hold_active'               => $trendBirthHoldActive,
            'trend_birth_structure_intact'          => $trendBirthStructIntact,
            'trend_birth_structure_broken'          => $trendBirthStructBroken,
            'trend_birth_higher_lows_count'         => $trendBirthHigherLows,
            'trend_birth_last_higher_low_price'     => $trendBirthLastHLPrice,
            'trend_birth_giveback_roi'              => $trendBirthGivebackRoi,
            'trend_birth_age_minutes'               => $trendBirthAgeMinutes,
            'trend_birth_extra_lock_buffer_applied' => $trendBirthExtraLockApplied,
            'trend_birth_extra_staircase_buffer_applied' => $trendBirthExtraStairApplied,
            'trend_birth_close_vetoed'              => $trendBirthCloseVetoed,
            'trend_birth_veto_reason'               => $trendBirthVetoReason,
            'trend_birth_no_veto_reason'            => $trendBirthNoVetoReason,
            'trend_birth_context'                   => $trendBirthCtx,
            // ── State freshness diagnostics ───────────────────────────────────
            'state_current_price_updated'      => true,
            'state_peak_at_updated'            => $statePeakAtUpdated,
            'previous_peak_roi'                => $storedPeakRoiBefore,
            'current_peak_roi'                 => $runPeakRoi,
            'last_peak_at'                     => $positionState['last_peak_at'] ?? null,
        ];
    }

    /**
     * Return the count of active lock entries in this profile's storage.
     */
    /**
     * Return the effective merged config for this profile (base + overrides).
     *
     * @return array<string,mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

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
    // ROI staircase — helpers
    // =========================================================================

    /**
     * Compute the dynamic staircase floor ROI for a given peak ROI.
     *
     * Formula:
     *   step  = floor(peak_roi / step_roi)
     *   floor = max(base_floor_roi, step * step_roi - floor_buffer_roi)
     * Clamp:
     *   floor <= max_floor_roi
     *   floor <  peak_roi  (always leave at least a tiny gap)
     *
     * @param float $peakRoi Current peak ROI observed for the position
     * @return float         Staircase floor ROI (>= base_floor_roi)
     */
    private function computeStaircaseFloor(float $peakRoi, float $extraBuffer = 0.0): float
    {
        $cfg       = $this->config;
        $stepRoi   = (float) ($cfg['roi_staircase_step_roi']         ?? 5.0);
        $baseFloor = (float) ($cfg['roi_staircase_base_floor_roi']   ?? 5.0);
        $buffer    = (float) ($cfg['roi_staircase_floor_buffer_roi'] ?? 3.0) + $extraBuffer;
        $maxFloor  = (float) ($cfg['roi_staircase_max_floor_roi']    ?? 50.0);

        if ($stepRoi <= 0.0) {
            return $baseFloor;
        }

        $step  = floor($peakRoi / $stepRoi);
        $floor = max($baseFloor, $step * $stepRoi - $buffer);
        $floor = min($floor, $maxFloor);
        $floor = min($floor, $peakRoi - 0.1); // must remain below peak

        return round($floor, 4);
    }

    // =========================================================================
    // Trend birth hold — helpers
    // =========================================================================

    /**
     * Detect local price higher lows in a series of price values.
     *
     * A "higher low" is a local minimum (price lower than both neighbours) that is
     * strictly higher than the preceding local minimum, filtered by a small noise
     * tolerance to avoid counting micro-jitter as a real higher low.
     *
     * @param float[] $prices       Price series (chronological order)
     * @param float   $tolerancePct Fraction below previous low that still counts as
     *                              "approximately the same" (i.e. NOT a new higher low).
     *                              Example: 0.0012 means 0.12%.
     * @return float[]  Prices of confirmed higher lows (each strictly above the previous)
     */
    private function detectHigherLows(array $prices, float $tolerancePct): array
    {
        $n = count($prices);
        if ($n < 3) {
            return [];
        }

        // Find local minima: point[i] is a local min if it is <= both neighbours
        $localMins = [];
        for ($i = 1; $i < $n - 1; $i++) {
            if ($prices[$i] <= $prices[$i - 1] && $prices[$i] <= $prices[$i + 1]) {
                $localMins[] = (float) $prices[$i];
            }
        }

        if (count($localMins) < 2) {
            return $localMins;
        }

        // Collect higher lows: each local min must be strictly above the previous one
        // (ignoring tiny noise below tolerance)
        $higherLows = [];
        $prev = $localMins[0];
        for ($i = 1; $i < count($localMins); $i++) {
            $cur = $localMins[$i];
            // Require cur to be meaningfully above prev (above noise threshold)
            if ($cur > $prev * (1.0 + $tolerancePct)) {
                $higherLows[] = $cur;
            }
            // Advance prev only when cur is not a lower low (keep last valid level)
            if ($cur >= $prev * (1.0 - $tolerancePct)) {
                $prev = $cur;
            }
        }

        return $higherLows;
    }

    /**
     * Compute trend birth context for a long position.
     *
     * Uses PM-owned roi_samples (which track both ROI% and price per tick) to
     * detect whether the position is in an early uptrend with rising higher lows.
     *
     * Classification:
     *   early_trend_birth  — all birth conditions met, structure intact
     *   mid_trend          — conditions not fully met but structure not broken
     *   late_or_exhausted  — position too old or peak ROI high enough for trend to be mature
     *   structure_broken   — price broke below last higher low / hard floor / giveback exceeded
     *   insufficient_data  — not enough samples to classify
     *
     * @param array $position      Normalized position
     * @param array $positionState Per-position state (roi_samples, peak_roi, current_roi, tracked_since_ts)
     * @param int   $nowTs         Current unix timestamp
     * @return array{
     *   trend_phase: string,
     *   structure_intact: bool,
     *   structure_broken: bool,
     *   higher_lows_count: int,
     *   last_higher_low_price: float|null,
     *   giveback_roi: float,
     *   age_minutes: float,
     *   reason: string
     * }
     */
    private function computeTrendBirthContext(
        array $position,
        array $positionState,
        int   $nowTs
    ): array {
        $cfg = $this->config;

        $minPeakRoi     = (float) ($cfg['trend_birth_min_peak_roi']               ?? 8.0);
        $minCurrentRoi  = (float) ($cfg['trend_birth_min_current_roi']            ?? 5.0);
        $maxAgeMinutes  = (float) ($cfg['trend_birth_max_age_minutes']            ?? 25.0);
        $minSamples     = (int)   ($cfg['trend_birth_min_samples']                ?? 5);
        $minHigherLows  = (int)   ($cfg['trend_birth_min_higher_lows']            ?? 2);
        $hlTolPct       = (float) ($cfg['trend_birth_higher_low_tolerance_pct']   ?? 0.12) / 100.0;
        $structBreakPct = (float) ($cfg['trend_birth_structure_break_pct']        ?? 0.18) / 100.0;
        $maxGivebackRoi = (float) ($cfg['trend_birth_max_giveback_roi']           ?? 12.0);
        $hardFloorRoi   = (float) ($cfg['trend_birth_hard_floor_roi']             ?? 4.0);

        $currentPrice = (float) ($position['current_price'] ?? $position['mark_price'] ?? 0.0);
        $currentRoi   = (float) ($positionState['current_roi'] ?? 0.0);
        $peakRoi      = (float) ($positionState['peak_roi']    ?? 0.0);
        $trackedSince = isset($positionState['tracked_since_ts'])
            ? (int) $positionState['tracked_since_ts']
            : $nowTs;

        $ageSeconds  = max(0, $nowTs - $trackedSince);
        $ageMinutes  = $ageSeconds / 60.0;
        $givebackRoi = max(0.0, $peakRoi - $currentRoi);

        $base = [
            'higher_lows_count'      => 0,
            'last_higher_low_price'  => null,
            'giveback_roi'           => round($givebackRoi, 4),
            'age_minutes'            => round($ageMinutes, 2),
        ];

        $samples = is_array($positionState['roi_samples'] ?? null) ? $positionState['roi_samples'] : [];

        if (count($samples) < $minSamples) {
            return $base + [
                'trend_phase'    => 'insufficient_data',
                'structure_intact' => false,
                'structure_broken' => false,
                'reason'         => 'insufficient_samples:' . count($samples),
            ];
        }

        $prices = array_values(array_column($samples, 'price'));

        // ── Detect higher lows ────────────────────────────────────────────────
        $higherLows      = $this->detectHigherLows($prices, $hlTolPct);
        $higherLowsCount = count($higherLows);
        $lastHLPrice     = $higherLowsCount > 0 ? (float) end($higherLows) : null;

        $base['higher_lows_count']     = $higherLowsCount;
        $base['last_higher_low_price'] = $lastHLPrice;

        // ── Check structure-broken conditions ─────────────────────────────────
        $structureBroken       = false;
        $structureBrokenReason = null;

        if ($currentRoi < $hardFloorRoi) {
            $structureBroken       = true;
            $structureBrokenReason = 'below_hard_floor';
        } elseif ($givebackRoi > $maxGivebackRoi) {
            $structureBroken       = true;
            $structureBrokenReason = 'giveback_exceeded';
        } elseif ($lastHLPrice !== null && $lastHLPrice > 0.0 && $currentPrice > 0.0) {
            if ($currentPrice < $lastHLPrice * (1.0 - $structBreakPct)) {
                $structureBroken       = true;
                $structureBrokenReason = 'price_broke_higher_low';
            }
        }

        // ── Check for strong negative recent move ─────────────────────────────
        // Simple check: compare last sample price to the price from 3 samples ago
        if (!$structureBroken && count($prices) >= 4) {
            $recentN    = min(count($prices), 4);
            $priceBack  = (float) $prices[count($prices) - $recentN];
            $priceNow   = (float) end($prices);
            if ($priceBack > 0.0 && $priceNow < $priceBack * (1.0 - $structBreakPct * 2.0)) {
                $structureBroken       = true;
                $structureBrokenReason = 'strong_negative_recent_move';
            }
        }

        if ($structureBroken) {
            return $base + [
                'trend_phase'    => 'structure_broken',
                'structure_intact' => false,
                'structure_broken' => true,
                'reason'         => $structureBrokenReason,
            ];
        }

        // ── Classify trend phase ──────────────────────────────────────────────
        $ageOk         = ($ageMinutes <= $maxAgeMinutes);
        $peakOk        = ($peakRoi >= $minPeakRoi);
        $currentOk     = ($currentRoi >= $minCurrentRoi);
        $higherLowsOk  = ($higherLowsCount >= $minHigherLows);
        $givebackOk    = ($givebackRoi <= $maxGivebackRoi);
        $hardFloorOk   = ($currentRoi >= $hardFloorRoi);

        // Check current price is above last higher low (with tolerance)
        $aboveLastHL = true;
        if ($lastHLPrice !== null && $lastHLPrice > 0.0 && $currentPrice > 0.0) {
            $aboveLastHL = ($currentPrice >= $lastHLPrice * (1.0 - $hlTolPct));
        }

        $allBirthConditions = $ageOk && $peakOk && $currentOk && $higherLowsOk
                           && $givebackOk && $hardFloorOk && $aboveLastHL;

        if ($allBirthConditions) {
            return $base + [
                'trend_phase'    => 'early_trend_birth',
                'structure_intact' => true,
                'structure_broken' => false,
                'reason'         => 'all_conditions_met',
            ];
        }

        // Determine why it's not early birth
        if (!$ageOk || $peakRoi >= 30.0) {
            return $base + [
                'trend_phase'    => 'late_or_exhausted',
                'structure_intact' => false,
                'structure_broken' => false,
                'reason'         => !$ageOk ? 'age_exceeded' : 'peak_roi_high',
            ];
        }

        $midReason = [];
        if (!$peakOk)       { $midReason[] = 'peak_roi_low'; }
        if (!$currentOk)    { $midReason[] = 'current_roi_low'; }
        if (!$higherLowsOk) { $midReason[] = 'insufficient_higher_lows:' . $higherLowsCount; }
        if (!$aboveLastHL)  { $midReason[] = 'below_last_higher_low'; }

        return $base + [
            'trend_phase'    => 'mid_trend',
            'structure_intact' => false,
            'structure_broken' => false,
            'reason'         => implode(',', $midReason) ?: 'mid_trend',
        ];
    }

    // =========================================================================
    // ROI sample tracking — helpers
    // =========================================================================

    /**
     * Append one ROI + price sample to the per-position state for chop detection.
     *
     * Keeps the most recent samples within a rolling window.
     * Mutates $positionState in-place.
     *
     * @param array $positionState Mutable position state (roi_samples key)
     * @param float $roi           Current ROI %
     * @param float $price         Current price
     * @param int   $nowTs         Current unix timestamp
     */
    private function appendRoiSample(array &$positionState, float $roi, float $price, int $nowTs): void
    {
        $maxSamples   = 20;
        $maxWindowSec = 1200; // keep at most 20 minutes

        $samples = is_array($positionState['roi_samples'] ?? null) ? $positionState['roi_samples'] : [];

        $samples[] = ['ts' => $nowTs, 'roi' => $roi, 'price' => $price];

        // Prune old samples outside the rolling window
        $cutoff  = $nowTs - $maxWindowSec;
        $samples = array_values(array_filter($samples, static fn(array $s): bool => $s['ts'] >= $cutoff));

        // Cap at max samples (keep newest)
        if (count($samples) > $maxSamples) {
            $samples = array_slice($samples, -$maxSamples);
        }

        $positionState['roi_samples'] = $samples;
    }

    // =========================================================================
    // Chop / indecision exit — helpers
    // =========================================================================

    /**
     * Detect whether a position is chopping in a narrow ROI band without making
     * a new peak — a pattern that often precedes a dump.
     *
     * Returns chop_detected = true only when ALL of:
     *   - peak ROI was reached at least chop_exit_no_new_peak_seconds ago
     *   - within chop_exit_window_seconds the ROI made >= chop_exit_min_swings
     *     alternating moves each between chop_exit_swing_roi and chop_exit_max_swing_roi
     *   - current ROI >= chop_exit_min_close_roi (when require_profit = true)
     *
     * Fails gracefully when roi_samples is empty.
     *
     * @param array $positionState Per-position state (roi_samples, last_peak_at)
     * @param float $roi           Current ROI %
     * @param float $peakRoi       Peak ROI % for this position
     * @param int   $nowTs         Current unix timestamp
     * @return array{
     *   chop_detected: bool,
     *   reason: string,
     *   swing_count: int,
     *   seconds_since_peak: int|null,
     *   roi_swings: list<float>
     * }
     */
    private function computeChopContext(
        array $positionState,
        float $roi,
        float $peakRoi,
        int   $nowTs
    ): array {
        $cfg = $this->config;

        $windowSec     = (int)   ($cfg['chop_exit_window_seconds']     ?? 180);
        $minSwings     = (int)   ($cfg['chop_exit_min_swings']         ?? 3);
        $swingRoi      = (float) ($cfg['chop_exit_swing_roi']          ?? 3.0);
        $maxSwingRoi   = (float) ($cfg['chop_exit_max_swing_roi']      ?? 5.0);
        $noPeakSec     = (int)   ($cfg['chop_exit_no_new_peak_seconds'] ?? 180);
        $requireProfit = !empty($cfg['chop_exit_require_profit']);
        $minCloseRoi   = (float) ($cfg['chop_exit_min_close_roi']      ?? 4.0);

        $noResult = static function(string $reason, int $swings = 0, ?int $secsSincePeak = null): array {
            return ['chop_detected' => false, 'reason' => $reason, 'swing_count' => $swings,
                    'seconds_since_peak' => $secsSincePeak, 'roi_swings' => []];
        };

        // Check that peak was reached long enough ago
        $lastPeakAt       = isset($positionState['last_peak_at']) ? (int) $positionState['last_peak_at'] : null;
        $secondsSincePeak = ($lastPeakAt !== null) ? ($nowTs - $lastPeakAt) : null;

        if ($secondsSincePeak === null || $secondsSincePeak < $noPeakSec) {
            return $noResult('peak_too_recent', 0, $secondsSincePeak);
        }

        // Check minimum close ROI
        if ($requireProfit && $roi < $minCloseRoi) {
            return $noResult('below_min_close_roi', 0, $secondsSincePeak);
        }

        $samples = is_array($positionState['roi_samples'] ?? null) ? $positionState['roi_samples'] : [];

        if (count($samples) < 3) {
            return $noResult('insufficient_samples', 0, $secondsSincePeak);
        }

        // Filter to the observation window
        $cutoff = $nowTs - $windowSec;
        $window = array_values(array_filter($samples, static fn(array $s): bool => $s['ts'] >= $cutoff));

        if (count($window) < 3) {
            return $noResult('insufficient_window_samples', 0, $secondsSincePeak);
        }

        // Detect alternating swings: count direction changes where the move size
        // falls between swingRoi and maxSwingRoi.
        $rois      = array_column($window, 'roi');
        $swings    = [];
        $swingBase = $rois[0];
        $lastDir   = 0;

        for ($i = 1; $i < count($rois); $i++) {
            $delta  = $rois[$i] - $swingBase;
            $absD   = abs($delta);
            $curDir = ($delta >= 0.0) ? 1 : -1;

            if ($absD >= $swingRoi && $absD <= $maxSwingRoi) {
                if ($lastDir === 0 || $curDir !== $lastDir) {
                    // First swing, or a direction reversal — count as a new swing
                    $swings[]  = round($delta, 4);
                    $swingBase = $rois[$i];
                    $lastDir   = $curDir;
                }
            }
        }

        $swingCount = count($swings);

        if ($swingCount < $minSwings) {
            return [
                'chop_detected'      => false,
                'reason'             => 'not_enough_swings',
                'swing_count'        => $swingCount,
                'seconds_since_peak' => $secondsSincePeak,
                'roi_swings'         => $swings,
            ];
        }

        return [
            'chop_detected'      => true,
            'reason'             => 'chop_detected',
            'swing_count'        => $swingCount,
            'seconds_since_peak' => $secondsSincePeak,
            'roi_swings'         => $swings,
        ];
    }

    // =========================================================================
    // Impulse-aware hold mode — helpers
    // =========================================================================

    /**
     * Read recent parser2 ticker records for a symbol.
     *
     * Returns an ascending-timestamp array of records, each containing:
     *   ts_unix, last_price, turnover24h, open_interest_value
     *
     * Fails gracefully — never throws.
     *
     * @param string $symbol        Upper-case trading symbol
     * @param int    $nowTs         Current unix timestamp
     * @param int    $windowSeconds How far back to look (minimum 60 s)
     * @return array<int,array{ts_unix:int,last_price:float,turnover24h:float,open_interest_value:float}>
     */
    private function readParser2TickerPoints(string $symbol, int $nowTs, int $windowSeconds): array
    {
        $storageDir = $this->parser2StorageDir;
        $symbolDir  = rtrim($storageDir, '/') . '/' . strtoupper($symbol);
        if (!is_dir($symbolDir)) {
            return [];
        }

        $windowSeconds = max($windowSeconds, 60);
        $sinceTs       = $nowTs - $windowSeconds;

        // Build candidate date strings covering the window
        $dates  = [];
        $cursor = $sinceTs;
        while ($cursor <= $nowTs) {
            $d = date('Y-m-d', $cursor);
            if (!in_array($d, $dates, true)) {
                $dates[] = $d;
            }
            $cursor += 86400;
        }
        $todayDate = date('Y-m-d', $nowTs);
        if (!in_array($todayDate, $dates, true)) {
            $dates[] = $todayDate;
        }

        $points = [];

        foreach ($dates as $date) {
            $file = $symbolDir . '/' . $date . '.ndjson';
            if (!is_file($file) || !is_readable($file)) {
                continue;
            }
            $handle = @fopen($file, 'r');
            if ($handle === false) {
                continue;
            }
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $rec = json_decode($line, true);
                if (!is_array($rec)) {
                    continue;
                }
                $ts = isset($rec['ts_unix']) ? (int) $rec['ts_unix'] : 0;
                if ($ts < $sinceTs || $ts > ($nowTs + 60)) {
                    continue;
                }

                $lastPrice   = 0.0;
                $turnover24h = 0.0;
                $oiValue     = 0.0;

                if (isset($rec['data']) && is_array($rec['data'])) {
                    // Parser2 full Bybit ticker format
                    foreach (['lastPrice', 'markPrice', 'indexPrice'] as $f) {
                        $v = (float) ($rec['data'][$f] ?? 0.0);
                        if ($v > 0.0) {
                            $lastPrice = $v;
                            break;
                        }
                    }
                    $turnover24h = (float) ($rec['data']['turnover24h']      ?? 0.0);
                    $oiValue     = (float) ($rec['data']['openInterestValue'] ?? 0.0);
                } elseif (isset($rec['last_price'])) {
                    // Parser15 backfill simplified format
                    $lastPrice = (float) ($rec['last_price'] ?? 0.0);
                }

                if ($lastPrice <= 0.0) {
                    continue;
                }

                $points[] = [
                    'ts_unix'             => $ts,
                    'last_price'          => $lastPrice,
                    'turnover24h'         => $turnover24h,
                    'open_interest_value' => $oiValue,
                ];
            }
            fclose($handle);
        }

        if (empty($points)) {
            return [];
        }

        // Sort ascending by timestamp
        usort($points, static fn(array $a, array $b): int => $a['ts_unix'] <=> $b['ts_unix']);

        // Deduplicate by ts_unix (keep last value at each second)
        $deduped = [];
        foreach ($points as $p) {
            $deduped[$p['ts_unix']] = $p;
        }

        return array_values($deduped);
    }

    /**
     * Compute impulse context for a long position.
     *
     * Scores up to 6 components (each +1):
     *   price_momentum_5m, price_momentum_15m, price_momentum_30m,
     *   turnover_growth, open_interest_value_growth, peak_roi_strong
     *
     * Fails gracefully when parser2 data is unavailable — score simply
     * does not include the missing components.
     *
     * @param array $position     Normalized position
     * @param float $currentPrice Current mark price
     * @param float $roi          Current ROI %
     * @param float $peakRoi      Peak ROI % observed for this position
     * @param int   $nowTs        Current unix timestamp
     * @return array{
     *   impulse_score: int,
     *   impulse_class: string,
     *   price_change_1m_pct: float|null,
     *   price_change_3m_pct: float|null,
     *   price_change_5m_pct: float|null,
     *   price_change_15m_pct: float|null,
     *   price_change_30m_pct: float|null,
     *   turnover_growth_pct: float|null,
     *   open_interest_value_growth_pct: float|null,
     *   evidence: list<string>,
     *   missing_metrics: list<string>
     * }
     */
    private function computeImpulseContext(
        array $position,
        float $currentPrice,
        float $roi,
        float $peakRoi,
        int   $nowTs
    ): array {
        $symbol = strtoupper(trim((string) ($position['symbol'] ?? '')));
        $cfg    = $this->config;

        $windowMin     = (int)   ($cfg['impulse_metrics_window_minutes']             ?? 30);
        $windowSec     = $windowMin * 60;
        $min1m         = (float) ($cfg['impulse_min_price_change_1m_pct']            ?? 0.05);
        $min3m         = (float) ($cfg['impulse_min_price_change_3m_pct']            ?? 0.12);
        $min5m         = (float) ($cfg['impulse_min_price_change_5m_pct']            ?? 0.25);
        $min15m        = (float) ($cfg['impulse_min_price_change_15m_pct']           ?? 0.50);
        $min30m        = (float) ($cfg['impulse_min_price_change_30m_pct']           ?? 0.80);
        $minTurnGrowth = (float) ($cfg['impulse_min_turnover_growth_pct']            ?? 1.5);
        $minOiGrowth   = (float) ($cfg['impulse_min_open_interest_value_growth_pct'] ?? 1.5);
        $peakRoiMin    = (float) ($cfg['impulse_peak_roi_min']                       ?? 10.0);
        $useTurnover   = !empty($cfg['impulse_use_turnover24h']);
        $useOi         = !empty($cfg['impulse_use_open_interest']);

        $strongScore     = (int) ($cfg['impulse_hold_strong_score']      ?? 4);
        $veryStrongScore = (int) ($cfg['impulse_hold_very_strong_score'] ?? 5);

        // Read parser2 ticker points for the window
        $points         = $this->readParser2TickerPoints($symbol, $nowTs, $windowSec);
        $missingMetrics = [];

        // Locate price at each lookback target by scanning the sorted points array.
        // We keep the most-recent record at or before each target timestamp.
        $ts1m  = $nowTs - 60;
        $ts3m  = $nowTs - 180;
        $ts5m  = $nowTs - 300;
        $ts15m = $nowTs - 900;
        $ts30m = $nowTs - 1800;

        $price1mAgo  = null;
        $price3mAgo  = null;
        $price5mAgo  = null;
        $price15mAgo = null;
        $price30mAgo = null;

        if (!empty($points)) {
            foreach ($points as $p) {
                $pts = $p['ts_unix'];
                if ($pts <= $ts1m)  { $price1mAgo  = $p['last_price']; }
                if ($pts <= $ts3m)  { $price3mAgo  = $p['last_price']; }
                if ($pts <= $ts5m)  { $price5mAgo  = $p['last_price']; }
                if ($pts <= $ts15m) { $price15mAgo = $p['last_price']; }
                if ($pts <= $ts30m) { $price30mAgo = $p['last_price']; }
            }
        } else {
            $missingMetrics[] = 'no_ticker_data';
        }

        // Price-change calculations
        $change1m  = null;
        $change3m  = null;
        $change5m  = null;
        $change15m = null;
        $change30m = null;

        if ($currentPrice > 0.0) {
            if ($price1mAgo !== null && $price1mAgo > 0.0) {
                $change1m = ($currentPrice - $price1mAgo) / $price1mAgo * 100.0;
            } else {
                $missingMetrics[] = 'price_1m_missing';
            }
            if ($price3mAgo !== null && $price3mAgo > 0.0) {
                $change3m = ($currentPrice - $price3mAgo) / $price3mAgo * 100.0;
            } else {
                $missingMetrics[] = 'price_3m_missing';
            }
            if ($price5mAgo !== null && $price5mAgo > 0.0) {
                $change5m = ($currentPrice - $price5mAgo) / $price5mAgo * 100.0;
            } else {
                $missingMetrics[] = 'price_5m_missing';
            }
            if ($price15mAgo !== null && $price15mAgo > 0.0) {
                $change15m = ($currentPrice - $price15mAgo) / $price15mAgo * 100.0;
            } else {
                $missingMetrics[] = 'price_15m_missing';
            }
            if ($price30mAgo !== null && $price30mAgo > 0.0) {
                $change30m = ($currentPrice - $price30mAgo) / $price30mAgo * 100.0;
            } else {
                $missingMetrics[] = 'price_30m_missing';
            }
        }

        // Turnover growth over window
        $turnoverGrowth = null;
        if ($useTurnover) {
            if (!empty($points)) {
                $t0 = (float) ($points[0]['turnover24h']       ?? 0.0);
                $t1 = (float) (end($points)['turnover24h']     ?? 0.0);
                if ($t0 > 0.0) {
                    $turnoverGrowth = ($t1 - $t0) / $t0 * 100.0;
                } else {
                    $missingMetrics[] = 'turnover_missing';
                }
            } else {
                $missingMetrics[] = 'turnover_missing';
            }
        }

        // Open-interest value growth over window
        $oiGrowth = null;
        if ($useOi) {
            if (!empty($points)) {
                $oi0 = (float) ($points[0]['open_interest_value']       ?? 0.0);
                $oi1 = (float) (end($points)['open_interest_value']     ?? 0.0);
                if ($oi0 > 0.0) {
                    $oiGrowth = ($oi1 - $oi0) / $oi0 * 100.0;
                } else {
                    $missingMetrics[] = 'oi_missing';
                }
            } else {
                $missingMetrics[] = 'oi_missing';
            }
        }

        // ── Score each component ──────────────────────────────────────────────
        $score    = 0;
        $evidence = [];

        // 1. price_momentum_5m
        if ($change5m !== null && $change5m >= $min5m) {
            $score++;
            $evidence[] = 'price_momentum_5m';
        }
        // 2. price_momentum_15m
        if ($change15m !== null && $change15m >= $min15m) {
            $score++;
            $evidence[] = 'price_momentum_15m';
        }
        // 3. price_momentum_30m
        if ($change30m !== null && $change30m >= $min30m) {
            $score++;
            $evidence[] = 'price_momentum_30m';
        }
        // 4. turnover_growth
        if ($turnoverGrowth !== null && $turnoverGrowth >= $minTurnGrowth) {
            $score++;
            $evidence[] = 'turnover_growth';
        }
        // 5. open_interest_value_growth
        if ($oiGrowth !== null && $oiGrowth >= $minOiGrowth) {
            $score++;
            $evidence[] = 'open_interest_value_growth';
        }
        // 6. peak_roi_strong
        if ($peakRoi >= $peakRoiMin) {
            $score++;
            $evidence[] = 'peak_roi_strong';
        }

        // Classification
        if ($score >= $veryStrongScore) {
            $impulseClass = 'very_strong';
        } elseif ($score >= $strongScore) {
            $impulseClass = 'strong';
        } elseif ($score >= 2) {
            $impulseClass = 'normal';
        } else {
            $impulseClass = 'weak';
        }

        return [
            'impulse_score'                  => $score,
            'impulse_class'                  => $impulseClass,
            'price_change_1m_pct'            => $change1m  !== null ? round($change1m,  4) : null,
            'price_change_3m_pct'            => $change3m  !== null ? round($change3m,  4) : null,
            'price_change_5m_pct'            => $change5m  !== null ? round($change5m,  4) : null,
            'price_change_15m_pct'           => $change15m !== null ? round($change15m, 4) : null,
            'price_change_30m_pct'           => $change30m !== null ? round($change30m, 4) : null,
            'turnover_growth_pct'            => $turnoverGrowth !== null ? round($turnoverGrowth, 4) : null,
            'open_interest_value_growth_pct' => $oiGrowth      !== null ? round($oiGrowth,       4) : null,
            'evidence'                       => $evidence,
            'missing_metrics'                => array_values(array_unique($missingMetrics)),
        ];
    }

    /**
     * Determine whether momentum has broken for a position under impulse/grace hold.
     *
     * Momentum is considered broken when any of the following is true:
     *   - any available short-window price change is negative
     *   - turnover24h growth is negative (when available)
     *   - openInterestValue growth is negative (when available)
     *   - roi has fallen below the hard profit floor
     *   - (peak_roi - roi) exceeds the maximum allowed giveback
     *
     * @param array $impulseCtx Impulse context from computeImpulseContext()
     * @param float $roi        Current ROI %
     * @param float $peakRoi    Peak ROI % for this position
     * @param float $hardFloor  Close immediately if roi < hardFloor
     * @param float $maxGiveback Close immediately if (peakRoi - roi) > maxGiveback
     * @return array{momentum_broken: bool, break_reasons: list<string>}
     */
    private function isMomentumBroken(
        array $impulseCtx,
        float $roi,
        float $peakRoi,
        float $hardFloor,
        float $maxGiveback
    ): array {
        $breakReasons = [];

        $change1m   = $impulseCtx['price_change_1m_pct']            ?? null;
        $change3m   = $impulseCtx['price_change_3m_pct']            ?? null;
        $change5m   = $impulseCtx['price_change_5m_pct']            ?? null;
        $change15m  = $impulseCtx['price_change_15m_pct']           ?? null;
        $turnGrowth = $impulseCtx['turnover_growth_pct']            ?? null;
        $oiGrowth   = $impulseCtx['open_interest_value_growth_pct'] ?? null;

        if ($change1m !== null && $change1m < 0.0) {
            $breakReasons[] = 'price_1m_negative';
        }
        if ($change3m !== null && $change3m < 0.0) {
            $breakReasons[] = 'price_3m_negative';
        }
        if ($change5m !== null && $change5m < 0.0) {
            $breakReasons[] = 'price_5m_negative';
        }
        if ($change15m !== null && $change15m < 0.0) {
            $breakReasons[] = 'price_15m_negative';
        }
        if ($turnGrowth !== null && $turnGrowth < 0.0) {
            $breakReasons[] = 'turnover_negative';
        }
        if ($oiGrowth !== null && $oiGrowth < 0.0) {
            $breakReasons[] = 'oi_negative';
        }
        if ($roi < $hardFloor) {
            $breakReasons[] = 'below_hard_floor';
        }
        if (($peakRoi - $roi) > $maxGiveback) {
            $breakReasons[] = 'giveback_exceeded';
        }

        return [
            'momentum_broken' => !empty($breakReasons),
            'break_reasons'   => $breakReasons,
        ];
    }

    /**
     * Check whether a lock-touch grace override should be applied.
     *
     * @param array      $position      Normalized position
     * @param array      $positionState Current per-symbol state
     * @param float      $roi           Current ROI %
     * @param float      $peakRoi       Peak ROI %
     * @param array|null $impulseCtx    Impulse context (may be null)
     * @param int        $nowTs         Current unix timestamp
     * @return array{override:bool, reason:string, momentum_broken:bool, state_updates:array}
     */
    private function checkGraceOverride(
        array  $position,
        array  $positionState,
        float  $roi,
        float  $peakRoi,
        ?array $impulseCtx,
        int    $nowTs
    ): array {
        $cfg = $this->config;

        $graceWindowSec    = (int)   ($cfg['lock_touch_grace_window_seconds']         ?? 180);
        $graceMaxOverrides = (int)   ($cfg['lock_touch_grace_max_overrides']          ?? 2);
        $graceHardFloor    = (float) ($cfg['lock_touch_grace_hard_profit_floor_roi']  ?? 4.0);
        $graceMaxGiveback  = (float) ($cfg['lock_touch_grace_max_giveback_roi']       ?? 8.0);
        $requireNoMomentum = !empty($cfg['lock_touch_grace_require_no_momentum_break']);

        $graceCnt     = (int) ($positionState['lock_touch_grace_override_count'] ?? 0);
        $graceStarted = isset($positionState['lock_touch_grace_started_at'])
            ? (int) $positionState['lock_touch_grace_started_at']
            : null;

        $failReason     = null;
        $momentumBroken = false;

        if ($roi < $graceHardFloor) {
            $failReason = 'below_hard_profit_floor';
        } elseif (($peakRoi - $roi) > $graceMaxGiveback) {
            $failReason = 'giveback_exceeded';
        } elseif ($graceCnt >= $graceMaxOverrides) {
            $failReason = 'max_overrides_reached';
        } elseif ($graceStarted !== null && ($nowTs - $graceStarted) > $graceWindowSec) {
            $failReason = 'grace_window_expired';
        } elseif ($requireNoMomentum && $impulseCtx !== null) {
            $momentumResult = $this->isMomentumBroken(
                $impulseCtx, $roi, $peakRoi, $graceHardFloor, $graceMaxGiveback
            );
            if ($momentumResult['momentum_broken']) {
                $failReason     = 'momentum_broken';
                $momentumBroken = true;
            }
        }

        if ($failReason !== null) {
            $stateUpdates = ['lock_touch_grace_active' => false];
            if ($failReason === 'grace_window_expired') {
                $stateUpdates['lock_touch_grace_started_at'] = null;
            }
            $stateUpdates['last_grace_decision'] = [
                'ts'          => $nowTs,
                'action'      => 'would_close_on_lock_touch',
                'reason'      => 'lock_touch_grace_failed:' . $failReason,
                'fail_reason' => $failReason,
            ];
            return [
                'override'       => false,
                'reason'         => 'lock_touch_grace_failed:' . $failReason,
                'momentum_broken'=> $momentumBroken,
                'state_updates'  => $stateUpdates,
            ];
        }

        // Apply grace override
        $newGraceCnt  = $graceCnt + 1;
        $graceStarted = $graceStarted ?? $nowTs;

        $stateUpdates = [
            'lock_touch_grace_active'         => true,
            'lock_touch_grace_started_at'     => $graceStarted,
            'lock_touch_grace_override_count' => $newGraceCnt,
            'last_grace_decision'             => [
                'ts'     => $nowTs,
                'action' => 'hold_override_lock_touch',
                'reason' => 'lock_touch_grace_override',
            ],
        ];

        return [
            'override'       => true,
            'reason'         => 'lock_touch_grace_override',
            'momentum_broken'=> false,
            'state_updates'  => $stateUpdates,
        ];
    }

    /**
     * Check whether an impulse-hold lock-touch override should be applied.
     *
     * Safety conditions that must all pass for an override to be granted:
     *   - impulse_hold_skip_first_lock_touch = true
     *   - override_count < impulse_hold_max_override_count
     *   - impulse hold age <= impulse_hold_max_minutes
     *   - roi >= impulse_hold_hard_profit_floor_roi
     *   - (peak_roi - roi) <= impulse_hold_max_giveback_roi
     *   - momentum has not broken (when require_momentum_break_to_close = true)
     *
     * @param array $position      Normalized position
     * @param array $positionState Current per-symbol state
     * @param array $impulseCtx    Impulse context from computeImpulseContext()
     * @param float $roi           Current ROI %
     * @param float $peakRoi       Peak ROI %
     * @param int   $nowTs         Current unix timestamp
     * @return array{override:bool, reason:string, momentum_broken:bool, state_updates:array}
     */
    private function checkImpulseHoldOverride(
        array $position,
        array $positionState,
        array $impulseCtx,
        float $roi,
        float $peakRoi,
        int   $nowTs
    ): array {
        $cfg = $this->config;

        $skipFirst     = !empty($cfg['impulse_hold_skip_first_lock_touch']);
        $maxOverrides  = (int)   ($cfg['impulse_hold_max_override_count']       ?? 3);
        $maxMinutes    = (float) ($cfg['impulse_hold_max_minutes']              ?? 45);
        $hardFloor     = (float) ($cfg['impulse_hold_hard_profit_floor_roi']    ?? 6.0);
        $maxGiveback   = (float) ($cfg['impulse_hold_max_giveback_roi']         ?? 12.0);
        $requireMoment = !empty($cfg['impulse_hold_require_momentum_break_to_close']);

        $overrideCnt   = (int) ($positionState['impulse_lock_touch_override_count'] ?? 0);
        $holdStartedAt = isset($positionState['impulse_hold_started_at'])
            ? (int) $positionState['impulse_hold_started_at']
            : null;

        $failReason     = null;
        $momentumBroken = false;

        if (!$skipFirst) {
            $failReason = 'skip_first_disabled';
        } elseif ($overrideCnt >= $maxOverrides) {
            $failReason = 'max_overrides_reached';
        } elseif ($holdStartedAt !== null && ($nowTs - $holdStartedAt) > ((int) ($maxMinutes * 60))) {
            $failReason = 'max_minutes_exceeded';
        } elseif ($roi < $hardFloor) {
            $failReason = 'below_hard_profit_floor';
        } elseif (($peakRoi - $roi) > $maxGiveback) {
            $failReason = 'giveback_exceeded';
        } elseif ($requireMoment) {
            // require_momentum_break_to_close = true means: only allow close when momentum is broken.
            // Equivalently: do NOT override if momentum has broken.
            $momentumResult = $this->isMomentumBroken($impulseCtx, $roi, $peakRoi, $hardFloor, $maxGiveback);
            if ($momentumResult['momentum_broken']) {
                $failReason     = 'momentum_broken';
                $momentumBroken = true;
            }
        }

        if ($failReason !== null) {
            $stateUpdates = [];
            if ($momentumBroken || in_array($failReason, ['below_hard_profit_floor', 'giveback_exceeded'], true)) {
                $stateUpdates['impulse_hold_active'] = false;
            }
            $stateUpdates['last_impulse_decision'] = [
                'ts'          => $nowTs,
                'action'      => 'would_close_on_lock_touch',
                'reason'      => $momentumBroken
                    ? 'lock_touch_impulse_broken'
                    : 'lock_touch_impulse_safety_failed',
                'fail_reason' => $failReason,
            ];
            return [
                'override'       => false,
                'reason'         => $momentumBroken
                    ? 'lock_touch_impulse_broken'
                    : 'lock_touch_impulse_safety_failed',
                'momentum_broken'=> $momentumBroken,
                'state_updates'  => $stateUpdates,
            ];
        }

        // Apply impulse hold override
        $newOverrideCnt = $overrideCnt + 1;
        $holdStartedAt  = $holdStartedAt ?? $nowTs;

        $stateUpdates = [
            'impulse_hold_active'               => true,
            'impulse_score'                     => $impulseCtx['impulse_score'] ?? 0,
            'impulse_class'                     => $impulseCtx['impulse_class'] ?? 'weak',
            'impulse_hold_started_at'           => $holdStartedAt,
            'impulse_lock_touch_override_count' => $newOverrideCnt,
            'last_impulse_context'              => $impulseCtx,
            'last_impulse_decision'             => [
                'ts'     => $nowTs,
                'action' => 'hold_override_lock_touch',
                'reason' => 'impulse_hold_lock_touch_override',
            ],
        ];

        return [
            'override'       => true,
            'reason'         => 'impulse_hold_lock_touch_override',
            'momentum_broken'=> false,
            'state_updates'  => $stateUpdates,
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
            // Update top-level state even below init so consumers always see
            // current values (prevents stale current_roi / current_price fields).
            $currentPriceBelow = (float)($position['current_price'] ?? $position['mark_price'] ?? 0.0);
            $entryPriceBelow   = (float)($position['entry_price']   ?? $position['avg_price']  ?? 0.0);
            $peakRoiBelow      = (float)($positionState['peak_roi'] ?? ($currentRoi ?? 0.0));
            $positionState = array_merge($positionState, [
                'symbol'        => $symbol,
                'side'          => $side,
                'mode'          => (string)($position['execution_mode'] ?? $position['mode'] ?? ''),
                'signal_id'     => (string)($position['signal_id'] ?? ''),
                'opened_at'     => (string)($position['opened_at'] ?? $position['bot_submitted_at'] ?? $position['created_at'] ?? ''),
                'entry_price'   => $entryPriceBelow,
                'current_price' => $currentPriceBelow > 0.0 ? $currentPriceBelow : ($positionState['current_price'] ?? null),
                'current_roi'   => $currentRoi,
                // peak_roi never decreases
                'peak_roi'      => $peakRoiBelow,
                'updated_at'    => date('c', $nowTs),
                'updated_at_ts' => $nowTs,
                // Identity fields in positionState so below-init state is also
                // discarded on a new position even without an existing lock.
                'position_signal_id'   => (string)($position['signal_id']   ?? ''),
                'position_opened_at'   => (string)($position['opened_at']   ?? $position['bot_submitted_at'] ?? $position['created_at'] ?? ''),
                'position_entry_price' => $entryPriceBelow,
            ]);
            if (!isset($positionState['tracked_since'])) {
                $positionState['tracked_since']    = date('c', $nowTs);
                $positionState['tracked_since_ts'] = $nowTs;
            }
            // Append ROI sample even below init if price is valid
            if ($currentRoi !== null && $currentPriceBelow > 0.0) {
                $this->appendRoiSample($positionState, $currentRoi, $currentPriceBelow, $nowTs);
            }
            return [
                'plan' => [
                    'action'       => 'skip',
                    'symbol'       => $symbol,
                    'side'         => $side,
                    'skip_reason'  => $currentRoi === null ? 'cannot_calculate_roi' : 'below_init_roi',
                    'current_roi'  => $currentRoi,
                    'peak_roi'     => $peakRoiBelow,
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
        $currentPriceAbove = (float)($position['current_price'] ?? $position['mark_price'] ?? 0.0);
        $positionState = array_merge($positionState, [
            'symbol'        => $symbol,
            'side'          => $side,
            'mode'          => (string)($position['execution_mode'] ?? $position['mode'] ?? ''),
            'signal_id'     => (string)($position['signal_id'] ?? ''),
            'opened_at'     => (string)($position['opened_at'] ?? $position['bot_submitted_at'] ?? $position['created_at'] ?? ''),
            'entry_price'   => $entryPrice,
            'leverage'      => (float) ($position['leverage'] ?? 0.0),
            'current_price' => $currentPriceAbove > 0.0 ? $currentPriceAbove : ($positionState['current_price'] ?? null),
            'current_roi'   => $currentRoi,
            'peak_roi'      => $peakRoi,
            'updated_at'    => date('c', $nowTs),
            'updated_at_ts' => $nowTs,
            // Identity fields: allow positionState identity validation on next tick
            // even when lockState is empty (e.g. below activation_roi).
            'position_signal_id'   => (string)($position['signal_id']   ?? ''),
            'position_opened_at'   => (string)($position['opened_at']   ?? $position['bot_submitted_at'] ?? $position['created_at'] ?? ''),
            'position_entry_price' => $entryPrice,
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
    private function detectExitPattern(array $position, array $positionState, float $currentRoi): array
    {
        $noDetect = static fn(string $reason, array $ev = [], string $src = 'none', int $pts = 0): array => [
            'detected'     => false,
            'pattern_type' => null,
            'confidence'   => 0.0,
            'reason'       => $reason,
            'evidence'     => $ev,
            'price_source' => $src,
            'price_points' => $pts,
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
                    'price_source' => 'simulation',
                    'price_points' => 0,
                ];
            }
        }

        // ── ROI gate: use current tick ROI, never stale state ─────────────────
        if ($currentRoi <= 0.0) {
            return $noDetect('not_in_profit');
        }

        // ── Resolve price series: PM history first, parser2 fallback ─────────
        $symbol      = strtoupper(trim((string) ($position['symbol'] ?? '')));
        $minPoints   = (int)   ($this->config['detection_min_price_points'] ?? 15);
        $maxPoints   = (int)   ($this->config['detection_max_price_points'] ?? 60);
        $lookbackSec = (int)   ($this->config['detection_lookback_sec']     ?? 3600);
        $pmMinPoints = (int)   ($this->config['price_history_min_points_for_detector'] ?? 10);

        $priceSource = 'none';
        $pricePoints = [];

        // Priority 1: PM-owned price_history.json
        if (!empty($this->config['price_history_enabled'])) {
            $history = $this->readPriceHistory();
            $cutoff  = time() - max($lookbackSec, 60);
            $pmRaw   = array_values(array_filter(
                $history[$symbol] ?? [],
                static fn(array $p): bool => ((int) ($p['ts'] ?? 0)) >= $cutoff
            ));
            if (count($pmRaw) > $maxPoints) {
                $pmRaw = array_slice($pmRaw, -$maxPoints);
            }
            if (count($pmRaw) >= $pmMinPoints) {
                $pricePoints = $pmRaw;
                $priceSource = 'pm_price_history';
            }
        }

        // Priority 2: parser2 CandleReader fallback
        if (empty($pricePoints)) {
            $parser2Raw  = $this->candleReader->readRecentPrices(
                $symbol,
                $this->parser2StorageDir,
                $maxPoints,
                $lookbackSec
            );
            if (!empty($parser2Raw)) {
                // Normalize to same shape as PM history (both have 'price'; use ts_unix as ts)
                $pricePoints = array_map(
                    static fn(array $p): array => ['ts' => $p['ts_unix'], 'price' => $p['price']],
                    $parser2Raw
                );
                $priceSource = 'parser2';
            }
        }

        $n = count($pricePoints);
        if ($n < $minPoints) {
            $reason = ($n === 0) ? 'no_candle_data' : 'insufficient_window_data';
            return $noDetect($reason, ['points_available' => $n, 'min_required' => $minPoints], $priceSource, $n);
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
            'price_source' => $priceSource,
            'price_points' => $n,
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

        // Try PM price history first; fall back to parser2
        $confirmPrices = [];
        if (!empty($this->config['price_history_enabled'])) {
            $history = $this->readPriceHistory();
            $cutoff  = time() - max($lookbackSec, 60);
            $pmRaw   = array_values(array_filter(
                $history[$symbol] ?? [],
                static fn(array $p): bool => ((int) ($p['ts'] ?? 0)) >= $cutoff
            ));
            if (count($pmRaw) > $maxPoints) {
                $pmRaw = array_slice($pmRaw, -$maxPoints);
            }
            if (count($pmRaw) >= 2) {
                $confirmPrices = array_column($pmRaw, 'price');
            }
        }

        if (empty($confirmPrices)) {
            $parser2Raw = $this->candleReader->readRecentPrices($symbol, $this->parser2StorageDir, $maxPoints, $lookbackSec);
            if (count($parser2Raw) >= 2) {
                $confirmPrices = array_column($parser2Raw, 'price');
            }
        }

        if (count($confirmPrices) >= 2) {
            $recentSlice = array_slice($confirmPrices, -3);
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

        // ── Hard ROI gate: use CURRENT TICK ROI from plan, never stale state ──
        $currentRoi = isset($plan['current_roi']) ? (float) $plan['current_roi'] : null;
        $initRoi    = (float) ($this->config['init_roi'] ?? 2.0);

        if ($currentRoi === null || $currentRoi < $initRoi) {
            // Reset any stale hybrid state immediately — no guard, no waiting, no close
            $positionState['hybrid_state']        = 'idle';
            $positionState['pattern_detected_at'] = null;
            $positionState['confirmation_ticks']  = 0;
            $positionState['guard_stop_price']    = null;
            $positionState['detection_reason']    = 'below_init_roi_hybrid_disabled';

            return [
                $plan,
                $positionState,
                [
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
                    'hybrid_detection_reason'    => 'below_init_roi_hybrid_disabled',
                    'hybrid_price_source'        => 'none',
                    'hybrid_price_points'        => 0,
                ],
            ];
        }

        // ── Hybrid minimum close ROI gate ─────────────────────────────────────
        // Hybrid pattern detection, guard activation, confirmation and close are
        // blocked while ROI is below hybrid_min_close_roi.  If a position is
        // already in waiting_confirmation it is reset to idle to prevent a stale
        // close firing the next time this gate passes.
        $hybridMinCloseRoi = (float) ($this->config['hybrid_min_close_roi'] ?? 5.0);

        if ($currentRoi < $hybridMinCloseRoi) {
            $existingHybridState = (string) ($positionState['hybrid_state'] ?? 'idle');

            // Reset stale waiting_confirmation so it cannot fire when ROI later rises
            $positionState['hybrid_state']        = 'idle';
            $positionState['pattern_detected_at'] = null;
            $positionState['confirmation_ticks']  = 0;
            $positionState['guard_stop_price']    = null;
            $positionState['pattern_type']        = null;
            $positionState['detection_reason']    = 'below_hybrid_min_close_roi';

            return [
                $plan,
                $positionState,
                [
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
                    'hybrid_detection_reason'    => 'below_hybrid_min_close_roi',
                    'hybrid_price_source'        => 'none',
                    'hybrid_price_points'        => 0,
                ],
            ];
        }

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

        $patternResult   = $this->detectExitPattern($position, $positionState, $currentRoi);
        $patternDetected = (bool) ($patternResult['detected']     ?? false);
        $patternType     = $patternResult['pattern_type'] ?? null;
        // Always track the most recent detection reason (e.g. no_candle_data, insufficient_window_data, score_X_of_5)
        $detectionReason = $patternResult['reason'] ?? $detectionReason;
        $priceSource     = (string) ($patternResult['price_source'] ?? 'none');
        $pricePts        = (int)    ($patternResult['price_points'] ?? 0);

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
            'hybrid_price_source'        => $priceSource,
            'hybrid_price_points'        => $pricePts,
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
        foreach (['state.json', 'locks.json', 'patterns.json', 'price_history.json'] as $file) {
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

    private function readPriceHistory(): array
    {
        return $this->readJson($this->storageDir . '/price_history.json');
    }

    private function writePriceHistory(array $data): void
    {
        $this->writeJson($this->storageDir . '/price_history.json', $data);
    }

    /**
     * Append one price point for a symbol to the PM-owned price history.
     *
     * Deduplicates by timestamp; trims to the configured max points.
     * Fails silently — never throws.
     */
    private function appendPricePoint(string $symbol, float $price, int $ts, string $source): void
    {
        $maxPoints = (int) ($this->config['price_history_max_points'] ?? 120);
        $history   = $this->readPriceHistory();

        $key     = strtoupper($symbol);
        $entries = $history[$key] ?? [];

        // Deduplicate: skip if same timestamp already recorded
        foreach ($entries as $entry) {
            if ((int) ($entry['ts'] ?? 0) === $ts) {
                return;
            }
        }

        $entries[] = ['ts' => $ts, 'price' => $price, 'source' => $source];

        // Keep only the most-recent N points
        if (count($entries) > $maxPoints) {
            $entries = array_slice($entries, -$maxPoints);
        }

        $history[$key] = $entries;
        $this->writePriceHistory($history);
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

    /**
     * Build a per-position identity key used as the storage key in state.json and locks.json.
     *
     * Format: mode:symbol:side:signal_id:opened_at   (full identity key)
     * Fallback: symbol_side   (legacy — when signal_id or opened_at is unavailable)
     *
     * Using the full key ensures each unique position (by signal_id + opened_at)
     * gets its own slot, preventing a new position from inheriting peak_roi/lock
     * state written by a previous position on the same symbol+side.
     */
    public static function buildPositionIdentityKey(array $position, string $side): string
    {
        $symbol   = strtolower((string)($position['symbol']    ?? ''));
        $signalId = (string)($position['signal_id']            ?? '');
        $openedAt = (string)($position['opened_at']            ?? $position['bot_submitted_at'] ?? $position['created_at'] ?? '');
        $mode     = (string)($position['execution_mode']       ?? $position['mode'] ?? '');

        if ($symbol !== '' && $signalId !== '' && $openedAt !== '') {
            return ($mode !== '' ? $mode : 'unknown')
                . ':' . $symbol
                . ':' . strtolower($side)
                . ':' . $signalId
                . ':' . $openedAt;
        }
        // Fallback: legacy symbol_side key
        return $symbol . '_' . strtolower($side);
    }

    private function positionKey(string $symbol, string $side): string
    {
        return strtolower($symbol) . '_' . strtolower($side);
    }
}
