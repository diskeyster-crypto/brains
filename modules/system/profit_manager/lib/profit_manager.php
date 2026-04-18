<?php
declare(strict_types=1);

namespace Modules\System\ProfitManager\Lib;

/**
 * Profit Manager
 * 
 * Main cycle logic for trailing stop management.
 * Step 1: Load config + locks
 * Step 2: Fetch exchange positions
 * Step 3: Select managed positions
 * Step 4: Apply rules (step trailing, dumb trailing)
 */
class ProfitManager
{
    private array $config;
    private Store $store;
    private RiskMath $riskMath;
    private PositionSelector $selector;
    private StopApplier $applier;
    private Validator $validator;
    private $gateway;
    
    /** @var array Instrument meta cache */
    private array $instrumentCache = [];
    
    public function __construct(
        array $config,
        Store $store,
        RiskMath $riskMath,
        PositionSelector $selector,
        StopApplier $applier,
        Validator $validator,
        $gateway
    ) {
        $this->config = $config;
        $this->store = $store;
        $this->riskMath = $riskMath;
        $this->selector = $selector;
        $this->applier = $applier;
        $this->validator = $validator;
        $this->gateway = $gateway;
    }
    
    /**
     * Active mode execution cycle (trailing_owner = profit_manager).
     * PM is the sole dynamic trailing writer. Enriches each item with full trade
     * context (trade_id, owner_mode, current_roi, peak_roi, trailing_armed, proposed_*)
     * and applied-state observability fields.
     *
     * @param array $botTrades  Active bot trades for context matching (best-effort)
     * @param array $botConfig  Bot config (for activation thresholds)
     * @return array Execution result with PM observability fields
     */
    public function runActive(array $botTrades = [], array $botConfig = []): array
    {
        $items    = [];
        $errors   = [];
        $warnings = [];
        $ts       = date('c');

        $stats = [
            'step_trailing' => ['applied' => 0, 'skipped' => 0, 'failed' => 0],
            'dumb_trailing' => ['applied' => 0, 'skipped' => 0, 'failed' => 0],
        ];

        // PM-9: Per-run stabilization counters (merged into cumulative pm9_counters.json at end)
        $pm9CarriedForward        = 0; // positions that had persisted active state from a prior tick
        $pm9NoopSameLock          = 0; // eligible positions with no computable improvement this tick
        $pm9RegressionPrevented   = 0; // ratchet fired "not_improving" (prevents backward/same stop)
        $pm9DuplicateApplyPrevented = 0; // ratchet prevented re-applying an already-current stop
        $pm9MissingCleanup        = 0; // stale states deleted for positions gone from exchange & bot
        $pm9ClosedCleanup         = 0; // stale states deleted for positions closed by bot
        $handledTradeKeys         = []; // safe-keyed set of trade keys seen this tick (for cleanup)

        // PM-10: Per-run refinement counters (merged into cumulative pm10_counters.json at end)
        $pm10FirstLockProtection   = 0; // positions where first-lock was held (weak continuation)
        $pm10ContinuationExtension = 0; // positions in strong continuation phase (observational)
        $pm10ShallowPullback       = 0; // positions where pullback protection blocked tightening
        $pm10Noop                  = 0; // positions where no refinement branch applied
        $pm10SymbolsAffected       = 0; // positions where any behavioral refinement was applied

        // PM-11: Per-run adaptive refinement counters (merged into cumulative pm11_counters.json)
        $pm11WeakContinuation    = 0; // positions classified as weak_continuation
        $pm11SteadyContinuation  = 0; // positions classified as steady_continuation
        $pm11StrongContinuation  = 0; // positions classified as strong_continuation
        $pm11ShallowPullback     = 0; // positions classified as shallow_pullback
        $pm11FlatCarry           = 0; // positions classified as flat_carry
        $pm11AdjApplied          = 0; // positions where adaptive action was non-noop
        $pm11AdjNoop             = 0; // positions where adaptive action was noop
        $pm11BoundsHit           = 0; // positions where adaptive adjustment was capped by bounds

        // PM-15: Per-run cycle caution counters (merged into cumulative pm15_cycle_caution_counters.json)
        $cycPmCautionTotal       = 0; // positions where cycle model was found (broad observability)
        $cycPmCautionBlockTotal  = 0; // positions where caution blocked a real apply/tighten action path
        $cycPmCautionNoEffectTotal = 0; // positions where cycle model found but no bad state (no block)
        $cycPmCautionUnavailTotal  = 0; // positions where cycle model was unavailable (null)

        // PM-16: Per-run cycle positive support counters (merged into cumulative pm16_cycle_support_counters.json)
        $cycPmSupportTotal         = 0; // positions where cycle model evaluated for support
        $cycPmSupportApplyTotal    = 0; // positions where support tagged a real apply/tighten/extension path
        $cycPmSupportNoEffectTotal = 0; // positions where model found but not favorable / no real action path
        $cycPmSupportUnavailTotal  = 0; // positions where cycle model was unavailable (null)

        // PM-17: Per-run profit capture counters (merged into cumulative pm17_profit_capture_counters.json)
        $pm17CaptureTotal              = 0; // positions with meaningful peak evaluated by profit capture
        $pm17CaptureApplyTotal         = 0; // positions where capture classified as peak_drawdown or lock_strengthen
        $pm17PeakDrawdownTotal         = 0; // positions classified as peak_drawdown (aggressive giveback)
        $pm17ShallowPullbackHoldTotal  = 0; // positions classified as shallow_pullback_hold
        $pm17LockStrengthenTotal       = 0; // positions classified as lock_strengthen (at/near peak)
        $pm17IntermediateTotal         = 0; // positions classified as intermediate_giveback (between thresholds)
        $pm17NoEffectTotal             = 0; // positions where peak was not yet meaningful

        // PM-18 (pm_refine): Per-run peak-drawdown refinement v2 counters
        // Merged into cumulative pm_refine_counters.json at end of run.
        $pmRefTotal         = 0; // positions where peak was meaningful (evaluated actively)
        $pmRefApplyTotal    = 0; // positions where refinement applied an action (any zone)
        $pmRefNoEffectTotal = 0; // positions where peak not yet meaningful (no action possible)
        $pmRefProtectTotal  = 0; // positions classified into protection zone
        $pmRefContinueTotal = 0; // positions classified into growth zone (continuation support)

        // Build bot-trade lookup by canonical "symbol_side" key
        $botTradeByKey = [];
        foreach ($botTrades as $bt) {
            $bSym  = (string)($bt['symbol'] ?? '');
            $bSide = strtolower((string)($bt['side'] ?? ''));
            if ($bSide === 'buy')  { $bSide = 'long'; }
            if ($bSide === 'sell') { $bSide = 'short'; }
            if ($bSym !== '' && in_array($bSide, ['long', 'short'], true)) {
                $botTradeByKey[$bSym . '_' . $bSide] = $bt;
            }
        }

        // Activation ROI threshold for arm-state tracking
        $activationRoiPct = (float)(
            $this->config['step_trailing']['activation_roi_pct_default']
            ?? $botConfig['execution']['trailing_activation_roi']
            ?? 3.5
        );

        $positionsResult = $this->gateway->getPositions();
        if (!($positionsResult['ok'] ?? false)) {
            $errors[] = 'fetch_positions_failed: ' . ($positionsResult['error'] ?? 'unknown');
            return [
                'positions_total'   => 0,
                'positions_managed' => 0,
                'items'             => $items,
                'stats'             => $stats,
                'errors'            => $errors,
                'warnings'          => $warnings,
                'pm8_counters'      => [
                    'active_owner_symbols_seen_total'        => 0,
                    'active_owner_symbols_eligible_total'    => 0,
                    'active_owner_proposals_computed_total'  => 0,
                    'active_owner_apply_attempted_total'     => 0,
                    'active_owner_apply_success_total'       => 0,
                    'active_owner_apply_skipped_total'       => 0,
                    'active_owner_apply_blocked_total'       => 0,
                ],
            ];
        }

        $positions      = $positionsResult['positions'] ?? [];
        $positionsTotal = count($positions);

        $managed          = $this->selector->getManagedSymbols($positions);
        $positionsManaged = count($managed);

        // PM-8: Active-owner runtime proof counters — updated per managed position
        $pm8Seen      = 0; // all positions seen by PM
        $pm8Eligible  = 0; // positions where trailing is armed (above activation ROI)
        $pm8Proposals = 0; // positions where an improving trailing proposal was computed
        $pm8Attempted = 0; // positions where an exchange update was attempted
        $pm8Success   = 0; // positions where the exchange update succeeded
        $pm8Skipped   = 0; // positions where no update was warranted (not armed / not improving)
        $pm8Blocked   = 0; // positions where update was attempted but exchange rejected/failed

        foreach ($managed as $symbol => $ctx) {
            $position = $ctx['position'] ?? [];

            // Derive stable trade key (same logic as shadow mode)
            $side     = $this->validator->normalizeSide($position['side'] ?? '');
            $tradeKey = $symbol . '_' . $side;
            if (!empty($position['orderId'])) {
                $tradeKey = (string)$position['orderId'];
            } elseif (!empty($position['trade_id'])) {
                $tradeKey = (string)$position['trade_id'];
            }

            // Load persisted active state for monotonic peak_roi continuity
            $prevState     = $this->store->loadActiveState($tradeKey);

            // PM-9: Track position continuity (was this position seen in a prior tick?)
            $carriedForwardState = !empty($prevState);
            if ($carriedForwardState) {
                $pm9CarriedForward++;
            }
            $previousLockRoi = $carriedForwardState ? (float)($prevState['last_lock_roi'] ?? 0.0) : null;

            $positionIM    = (float)($position['positionIM'] ?? 0);
            $unrealisedPnl = (float)($position['unrealisedPnl'] ?? 0);
            $currentRoi    = ($positionIM > 0) ? round(($unrealisedPnl / $positionIM) * 100.0, 4) : 0.0;

            // Peak ROI: monotonic — only increases
            $prevPeakRoi   = (float)($prevState['peak_roi'] ?? 0.0);
            $peakRoi       = max($prevPeakRoi, $currentRoi);
            $trailingArmed = (bool)($prevState['trailing_armed'] ?? false);
            if (!$trailingArmed && $activationRoiPct > 0.0 && $peakRoi >= $activationRoiPct) {
                $trailingArmed = true;
            }

            // PM-10: Post-entry policy refinement — computed before any exchange write.
            // Determines which refinement branch applies this tick and whether to hold the update.
            $pm10Fields = $this->computePm10Refinement($prevState, $peakRoi, $currentRoi, $activationRoiPct, $trailingArmed);
            if ($pm10Fields['pm10_hold']) {
                $ctx['pm10_hold']        = true;
                $ctx['pm10_hold_reason'] = $pm10Fields['refinement_policy_stage'];
            } else {
                unset($ctx['pm10_hold'], $ctx['pm10_hold_reason']);
            }
            $pm10Stage = $pm10Fields['refinement_policy_stage'];
            if ($pm10Stage === 'first_lock_protection')          { $pm10FirstLockProtection++;   $pm10SymbolsAffected++; }
            elseif ($pm10Stage === 'shallow_pullback_protection') { $pm10ShallowPullback++;       $pm10SymbolsAffected++; }
            elseif ($pm10Stage === 'continuation_extension')      { $pm10ContinuationExtension++; $pm10SymbolsAffected++; }
            else                                                  { $pm10Noop++; }

            // PM-11: Bounded adaptive refinement — sits on top of PM-10 and may adjust pm10_hold.
            // Uses only immediate runtime facts already present in PM state.
            $pm11Fields = $this->computePm11Adaptive(
                $pm10Fields, $prevState, $peakRoi, $currentRoi, $activationRoiPct, $trailingArmed
            );
            // Apply hold override from adaptive layer (does not bypass safety gates)
            if ($pm11Fields['pm11_hold_override'] === true) {
                $ctx['pm10_hold']        = true;
                $ctx['pm10_hold_reason'] = $pm11Fields['adaptive_refinement_mode'];
            } elseif ($pm11Fields['pm11_hold_override'] === false && isset($ctx['pm10_hold'])) {
                // Strong continuation: release PM-10 hold so step trailing proceeds normally
                unset($ctx['pm10_hold'], $ctx['pm10_hold_reason']);
            }
            // Count adaptive mode
            $aMode = $pm11Fields['adaptive_refinement_mode'];
            if ($aMode === 'weak_continuation')     { $pm11WeakContinuation++; }
            elseif ($aMode === 'steady_continuation') { $pm11SteadyContinuation++; }
            elseif ($aMode === 'strong_continuation') { $pm11StrongContinuation++; }
            elseif ($aMode === 'shallow_pullback')    { $pm11ShallowPullback++; }
            elseif ($aMode === 'flat_carry')          { $pm11FlatCarry++; }
            if ($pm11Fields['adaptive_action_taken'] !== 'noop') { $pm11AdjApplied++; } else { $pm11AdjNoop++; }
            if ($pm11Fields['adaptive_bounds_applied'])           { $pm11BoundsHit++; }

            // PM-17: Profit capture — drawdown from peak ROI tracking and active lock enforcement.
            // Runs AFTER PM-11 so the current pm10_hold state reflects PM-10 + PM-11 decisions.
            // This layer may CLEAR pm10_hold to allow step trailing to fire when drawdown from
            // peak is meaningful. It never loosens stops or reduces existing lock — only tightens.
            $pm17Fields = $this->computePm17ProfitCapture(
                $peakRoi, $currentRoi, $activationRoiPct, $trailingArmed, isset($ctx['pm10_hold'])
            );
            // Apply PM-17 hold override when capture logic determined hold should be released
            if ($pm17Fields['pm17_override_hold'] && isset($ctx['pm10_hold'])) {
                unset($ctx['pm10_hold'], $ctx['pm10_hold_reason']);
            }
            // Count PM-17 modes
            $pm17CaptureMode = $pm17Fields['pm17_capture_mode'];
            if ($pm17CaptureMode === 'no_effect') {
                $pm17NoEffectTotal++;
            } else {
                $pm17CaptureTotal++;
                if ($pm17CaptureMode === 'peak_drawdown') {
                    $pm17PeakDrawdownTotal++;
                    if ($pm17Fields['pm17_applied']) { $pm17CaptureApplyTotal++; }
                } elseif ($pm17CaptureMode === 'shallow_pullback_hold') {
                    $pm17ShallowPullbackHoldTotal++;
                } elseif ($pm17CaptureMode === 'lock_strengthen') {
                    $pm17LockStrengthenTotal++;
                    if ($pm17Fields['pm17_applied']) { $pm17CaptureApplyTotal++; }
                } elseif ($pm17CaptureMode === 'intermediate_giveback') {
                    $pm17IntermediateTotal++;
                }
            }

            // PM-18 (pm_refine): Peak-drawdown refinement v2 — runs AFTER PM-17.
            // Uses absolute drawdown (ROI points) from peak to classify the position into a
            // clear zone and apply a decisive hold override on the real PM action paths.
            // Never loosens stops or widens risk; only holds (shallow pullback) or releases
            // hold (growth zone, protection zone) to let step trailing proceed.
            $pmRefineFields = $this->computePmRefine(
                $peakRoi, $currentRoi, $activationRoiPct, $trailingArmed, isset($ctx['pm10_hold']),
                (bool)($pm17Fields['pm17_applied'] ?? false)
            );
            // Apply pm_refine hold override (true=set hold, false=release hold, null=no change)
            if ($pmRefineFields['pm_refine_override_hold'] === true) {
                $ctx['pm10_hold']        = true;
                $ctx['pm10_hold_reason'] = $pmRefineFields['pm_refine_reason'];
            } elseif ($pmRefineFields['pm_refine_override_hold'] === false) {
                unset($ctx['pm10_hold'], $ctx['pm10_hold_reason']);
            }
            // Count pm_refine zones
            if ($pmRefineFields['pm_refine_used']) {
                $pmRefTotal++;
                if ($pmRefineFields['pm_refine_applied']) {
                    $pmRefApplyTotal++;
                    if ($pmRefineFields['pm_refine_pullback_state'] === 'protection') {
                        $pmRefProtectTotal++;
                    } elseif ($pmRefineFields['pm_refine_pullback_state'] === 'growth') {
                        $pmRefContinueTotal++;
                    }
                }
            } else {
                $pmRefNoEffectTotal++;
            }

            // PM-15: Bounded cycle caution layer — read coin_cycle_decision_model from passport.
            // This is a caution/veto gate only: it may block or defer PM apply/tighten when cycle
            // conditions are clearly weak or bad. It must NOT loosen stops, widen risk, or override
            // monotonic protection. PM ownership and all safety gates remain intact.
            //
            // The model is read here for broad observability (total/no_effect/unavailable counters)
            // and injected into ctx as raw data. The actual block decision is made inside
            // processStepTrailing/processDumbTrailing, AFTER the skip/eligibility check, so caution
            // can only intercept a symbol that is genuinely on the real apply/tighten execute path.
            // block_total and cycle_pm_caution_applied are set after processPosition returns.
            $cycPmCautionModel  = $this->readCycleDecisionModel($symbol);
            $cycPmCautionUsed   = false;
            $cycPmCautionApplied = false;
            $cycPmCautionReason  = null;
            $cycPmModelState     = $cycPmCautionModel['decision_model_state']         ?? 'unavailable';
            $cycPmModelRisk      = $cycPmCautionModel['decision_model_risk_posture']  ?? 'unavailable';
            $cycPmModelAction    = $cycPmCautionModel['decision_model_actionability'] ?? 'non_actionable';

            // Inject raw model into ctx so processStepTrailing/processDumbTrailing can evaluate it.
            $ctx['cycle_pm_caution_model'] = $cycPmCautionModel;

            if ($cycPmCautionModel === null) {
                // Cycle model unavailable — count for broad observability; no caution possible
                $cycPmCautionUnavailTotal++;
            } else {
                $cycPmCautionTotal++;
                $cycPmCautionUsed = true;

                // Evaluate trigger conditions here for the no_effect counter (broad observability).
                // The same conditions are re-evaluated inside processStepTrailing/processDumbTrailing
                // on the real execute path to determine whether to actually block.
                $preCautionReason = null;
                if ($cycPmModelState === 'unavailable' || $cycPmModelState === 'weak') {
                    $preCautionReason = 'cycle_pm_caution_unavailable';
                } elseif ($cycPmModelState === 'cautious') {
                    $preCautionReason = 'cycle_pm_caution_non_actionable';
                } elseif ($cycPmModelAction === 'non_actionable') {
                    $preCautionReason = 'cycle_pm_caution_non_actionable';
                } elseif ($cycPmModelRisk === 'high_risk') {
                    $preCautionReason = 'cycle_pm_caution_high_risk';
                } elseif (!empty($cycPmCautionModel['decision_model_low_confidence_flag'])) {
                    $preCautionReason = 'cycle_pm_caution_low_confidence';
                } elseif (
                    !empty($cycPmCautionModel['decision_model_warning_flag'])
                    && !empty($cycPmCautionModel['decision_model_warning_reason'])
                ) {
                    $preCautionReason = 'cycle_pm_caution_non_actionable';
                }

                if ($preCautionReason === null) {
                    // Cycle conditions acceptable — caution evaluated, no bad state found
                    $cycPmCautionNoEffectTotal++;
                }
                // block_total is updated after processPosition (real action-path intercept only)
            }

            // Execute existing trailing logic (real exchange writes)
            $itemResult = $this->processPosition($symbol, $ctx);

            // Derive PM applied-state observability from step/dumb trailing sub-results
            $appliedAction       = null;
            $appliedStopPrice    = null;
            $appliedLockRoi      = null;
            $exchangeAttempted   = false;
            $exchangeOk          = false;
            $exchangeUpdateError = null;

            // Derive proposed fields from trailing sub-results (informational)
            $proposedAction    = 'hold';
            $proposedStopPrice = null;
            $proposedLockRoi   = null;

            $st = $itemResult['step_trailing'] ?? null;
            $dt = $itemResult['dumb_trailing'] ?? null;

            // Step trailing takes priority for applied fields
            if ($st !== null && ($st['action'] ?? '') === 'step_sl_update') {
                $appliedAction     = 'step_sl_update';
                $appliedStopPrice  = $st['new_sl'] ?? null;
                $appliedLockRoi    = $st['target_lock_roi'] ?? null;
                $proposedAction    = 'step_sl_update';
                $proposedStopPrice = $appliedStopPrice;
                $proposedLockRoi   = $appliedLockRoi;
                $exchangeAttempted = true;
                $exchangeOk        = ($st['ok'] ?? false) === true;
                if (!$exchangeOk) {
                    $exchangeUpdateError = $st['details']['error'] ?? ($st['reason'] ?? 'unknown');
                }
            } elseif ($st !== null && ($st['action'] ?? '') === 'failed') {
                $appliedAction     = 'step_sl_update';
                $proposedAction    = 'step_sl_update';
                $exchangeAttempted = true;
                $exchangeOk        = false;
                $exchangeUpdateError = $st['details']['error'] ?? ($st['reason'] ?? 'unknown');
            } elseif ($dt !== null && ($dt['action'] ?? '') === 'dumb_trailing_set') {
                $appliedAction     = 'dumb_trailing_set';
                $proposedAction    = 'dumb_trailing_set';
                $exchangeAttempted = true;
                $exchangeOk        = ($dt['ok'] ?? false) === true;
                if (!$exchangeOk) {
                    $exchangeUpdateError = $dt['details']['error'] ?? ($dt['reason'] ?? 'unknown');
                }
            } elseif ($dt !== null && ($dt['action'] ?? '') === 'failed') {
                $appliedAction     = 'dumb_trailing_set';
                $proposedAction    = 'dumb_trailing_set';
                $exchangeAttempted = true;
                $exchangeOk        = false;
                $exchangeUpdateError = $dt['details']['error'] ?? ($dt['reason'] ?? 'unknown');
            }

            // PM-15: Detect real caution block on the actual execute path.
            // processStepTrailing and processDumbTrailing set cycle_pm_caution_blocked=true only
            // when caution fired AFTER shouldSkipStepTrailing/shouldSkipDumbTrailing passed,
            // confirming the symbol was genuinely about to apply a trailing update before caution
            // deferred it. This is the only path that increments block_total.
            if (!empty($st['cycle_pm_caution_blocked']) || !empty($dt['cycle_pm_caution_blocked'])) {
                $cycPmCautionApplied = true;
                $cycPmCautionReason  = $st['cycle_pm_caution_reason'] ?? $dt['cycle_pm_caution_reason'] ?? null;
                $cycPmCautionBlockTotal++;
            }

            // PM-15: reason-based mirror — ensure cycle_pm_caution_applied/reason are always set
            // whenever a trailing sub-result returned an explicit caution reason string, even if
            // cycle_pm_caution_blocked was absent from the sub-result for any edge-case reason.
            // This is a propagation fallback only: it does NOT increment block_total (the counter
            // is authoritative and managed solely by the direct detection above).
            if (!$cycPmCautionApplied && $cycPmCautionUsed) {
                static $pm15CautionReasonStrings = [
                    'cycle_pm_caution_high_risk'      => true,
                    'cycle_pm_caution_non_actionable' => true,
                    'cycle_pm_caution_low_confidence' => true,
                    'cycle_pm_caution_unavailable'    => true,
                ];
                foreach ([
                    ($st !== null && ($st['action'] ?? '') === 'skip') ? ($st['reason'] ?? null) : null,
                    ($dt !== null && ($dt['action'] ?? '') === 'skip') ? ($dt['reason'] ?? null) : null,
                ] as $_pm15r) {
                    if ($_pm15r !== null && isset($pm15CautionReasonStrings[$_pm15r])) {
                        $cycPmCautionApplied = true;
                        $cycPmCautionReason  = $_pm15r;
                        break;
                    }
                }
            }

            // PM-16: Bounded cycle positive support layer — uses the same model already read for PM-15.
            // Support is tagging-only: it marks a real PM action path as cycle-favored.
            // It must NOT force new actions, loosen stops, widen risk, or bypass caution blocks.
            // Only fires when PM already has a real apply/proposal path AND caution did not block.

            // Pre-compute proposalAvailable from already-set $appliedAction and $trailingArmed so the
            // gate below does not read a stale $proposalComputed value from a previous loop iteration.
            // ($proposalComputed is the canonical PM-8 variable; it is initialised later at line ~473.)
            $pm16ProposalAvailable = $trailingArmed
                && in_array($appliedAction, ['step_sl_update', 'dumb_trailing_set'], true);

            $cycPmSupportUsed    = false;
            $cycPmSupportApplied = false;
            $cycPmSupportReason  = null;
            if ($cycPmCautionModel === null) {
                $cycPmSupportUnavailTotal++;
            } else {
                $cycPmSupportTotal++;
                $cycPmSupportUsed = true;
                // Favorable condition: state=favorable, actionable, risk not high/unavailable,
                // no low-confidence or warning flags, live bias active.
                $pm16Favorable = (
                    $cycPmModelState  === 'favorable'
                    && $cycPmModelAction === 'actionable'
                    && !in_array($cycPmModelRisk, ['high_risk', 'unavailable'], true)
                    && empty($cycPmCautionModel['decision_model_low_confidence_flag'])
                    && empty($cycPmCautionModel['decision_model_warning_flag'])
                    && ($cycPmCautionModel['decision_model_live_bias'] ?? 'non_live_bias') === 'live_bias'
                );
                // Real action path: PM attempted an exchange update OR computed an improving proposal.
                // Caution-blocked paths are excluded (no real action to support).
                $pm16RealActionPath = !$cycPmCautionApplied && ($exchangeAttempted || $pm16ProposalAvailable);
                if ($pm16Favorable && $pm16RealActionPath) {
                    $cycPmSupportApplied = true;
                    if ($appliedAction === 'step_sl_update') {
                        $cycPmSupportReason = 'cycle_pm_support_extension';
                    } elseif ($appliedAction === 'dumb_trailing_set') {
                        $cycPmSupportReason = 'cycle_pm_support_tighten';
                    } elseif ($pm16ProposalAvailable) {
                        $cycPmSupportReason = 'cycle_pm_support_continue';
                    } else {
                        $cycPmSupportReason = 'cycle_pm_support_hold';
                    }
                    $cycPmSupportApplyTotal++;
                } else {
                    $cycPmSupportNoEffectTotal++;
                }
            }

            // PM-16: Reason-based mirror — ensures applied/reason are always set when a real trailing
            // apply happened, the model was favorable, and caution did not block, even if the direct
            // detection above missed the case (e.g. model state/risk satisfied at a broader level).
            // This is a propagation fallback only: it does NOT increment any counter.
            if (!$cycPmSupportApplied && $cycPmSupportUsed && !$cycPmCautionApplied
                && $cycPmModelState === 'favorable' && $cycPmModelAction === 'actionable'
            ) {
                if ($appliedAction === 'step_sl_update') {
                    $cycPmSupportApplied = true;
                    $cycPmSupportReason  = 'cycle_pm_support_extension';
                } elseif ($appliedAction === 'dumb_trailing_set') {
                    $cycPmSupportApplied = true;
                    $cycPmSupportReason  = 'cycle_pm_support_tighten';
                }
            }

            // Persist updated active state (monotonic peak_roi, arm state, last applied stop)
            $this->store->saveActiveState($tradeKey, [
                'trade_id'                => $tradeKey,
                'symbol'                  => $symbol,
                'side'                    => $side,
                'peak_roi'                => round($peakRoi, 4),
                'trailing_armed'          => $trailingArmed,
                'last_lock_roi'           => $appliedLockRoi ?? (float)($prevState['last_lock_roi'] ?? 0.0),
                'last_applied_stop_price' => ($exchangeOk && $appliedStopPrice !== null)
                                                ? $appliedStopPrice
                                                : (float)($prevState['last_applied_stop_price'] ?? 0.0),
                'updated_at'              => $ts,
            ]);

            // Bot trade context matching (best-effort for observability)
            $botKey   = $symbol . '_' . $side;
            $botTrade = $botTradeByKey[$botKey] ?? null;

            // Attach full trade-context fields to item
            $itemResult['trade_id']       = $tradeKey;
            $itemResult['owner_mode']     = 'profit_manager';
            $itemResult['current_roi']    = $currentRoi;
            $itemResult['peak_roi']       = round($peakRoi, 4);
            $itemResult['trailing_armed'] = $trailingArmed;
            $itemResult['proposed_action']     = $proposedAction;
            $itemResult['proposed_stop_price'] = $proposedStopPrice;
            $itemResult['proposed_lock_roi']   = $proposedLockRoi;

            // Attach PM observability fields
            $itemResult['trailing_runtime_owner']              = 'profit_manager';
            $itemResult['bot_dynamic_trailing_skipped_by_owner'] = true;
            $itemResult['applied_action']                      = $appliedAction;
            $itemResult['applied_stop_price']                  = $appliedStopPrice;
            $itemResult['applied_lock_roi']                    = $appliedLockRoi;
            $itemResult['last_applied_ts']                     = $appliedAction !== null ? $ts : null;
            $itemResult['exchange_update_attempted']           = $exchangeAttempted;
            $itemResult['exchange_update_ok']                  = $exchangeOk;
            $itemResult['exchange_update_error']               = $exchangeUpdateError;

            // PM-8: Active-owner runtime proof fields — per position
            // current_stop_or_lock_reference: stop loss currently set on the exchange position
            $currentStopLoss = (float)($position['stopLoss'] ?? 0.0);

            // lock_improvement_detected: true when step trailing action = step_sl_update
            // (ratchet check already passed inside processStepTrailing) OR dumb trailing set
            $lockImprovementDetected = in_array($appliedAction, ['step_sl_update', 'dumb_trailing_set'], true);

            // PM-8 explicit stage model: build ordered progression for this position.
            // Stages are accumulated as the position advances through PM decision logic.
            // This lets the runtime artifact show exactly which stages were reached.
            $pmStagesReached   = [];
            $eligibleForPm     = $trailingArmed;   // position is above activation ROI
            $proposalComputed  = false;             // PM computed an improving proposal

            if ($eligibleForPm) {
                $pmStagesReached[] = 'eligible_for_pm_management';
                if ($lockImprovementDetected) {
                    // Proposal exists (ratchet would improve the lock)
                    $proposalComputed  = true;
                    $pmStagesReached[] = 'pm_proposal_computed';
                    if ($exchangeAttempted) {
                        $pmStagesReached[] = 'pm_update_apply_attempted';
                        $pmStagesReached[] = $exchangeOk ? 'pm_update_applied' : 'pm_update_blocked';
                    } else {
                        // Proposal computed but not sent (cooldown, distance guard, etc.)
                        $pmStagesReached[] = 'pm_update_skipped';
                    }
                } else {
                    // Armed but no improvement available (trailing not tightening this tick)
                    $pmStagesReached[] = 'pm_update_skipped';
                }
            } else {
                // Position not armed yet (below activation ROI)
                $pmStagesReached[] = 'pm_update_skipped';
            }

            // pm_management_stage: final stage in the progression
            $pmStage = end($pmStagesReached);

            // skip_reason: first available reason when final stage is pm_update_skipped
            $skipReason = null;
            if ($pmStage === 'pm_update_skipped') {
                if (!$eligibleForPm) {
                    $skipReason = 'trailing_not_armed_below_activation_roi';
                } elseif (!$proposalComputed) {
                    // Armed but no improvement this tick
                    if ($st !== null && ($st['action'] ?? '') === 'skip') {
                        $skipReason = $st['reason'] ?? 'no_step_trailing_action';
                    } elseif ($dt !== null && ($dt['action'] ?? '') === 'skip') {
                        $skipReason = $dt['reason'] ?? 'no_dumb_trailing_action';
                    } else {
                        $skipReason = 'no_trailing_action_computed';
                    }
                } else {
                    // Proposal computed but not sent (cooldown, min-distance guard, etc.)
                    $skipReason = 'proposal_computed_but_not_sent';
                }
            }

            // block_reason: exchange-level error when update was attempted but failed
            $blockReason = ($pmStage === 'pm_update_blocked') ? $exchangeUpdateError : null;

            $itemResult['pm_management_stage']             = $pmStage;
            $itemResult['pm_stages_reached']               = $pmStagesReached;
            $itemResult['eligible_for_pm_management']      = $eligibleForPm;
            $itemResult['pm_proposal_computed']            = $proposalComputed;
            $itemResult['lock_improvement_detected']       = $lockImprovementDetected;
            $itemResult['current_stop_or_lock_reference']  = $currentStopLoss > 0.0 ? $currentStopLoss : null;
            $itemResult['proposed_stop_or_lock_reference'] = $proposedStopPrice;
            $itemResult['apply_attempted']                 = $exchangeAttempted;
            $itemResult['apply_applied']                   = $exchangeOk;
            $itemResult['skip_reason']                     = $skipReason;
            $itemResult['block_reason']                    = $blockReason;

            // PM-8 eligibility observability: always surface the activation threshold so the archive
            // can prove why eligible_total is zero when positions are below the threshold.
            $itemResult['activation_roi_threshold']        = $activationRoiPct;
            if (!$eligibleForPm) {
                // Compact explanation: current peak ROI vs. what is needed to arm trailing
                $itemResult['ineligibility_reason']        = 'below_activation_roi';
                $itemResult['roi_gap_to_activation']       = round($activationRoiPct - $peakRoi, 4);
                // Sub-reason: distinguish positions that are currently unprofitable (losing money).
                // This adds diagnostic granularity without changing the eligibility decision.
                if ($currentRoi < 0.0) {
                    $itemResult['ineligibility_sub_reason'] = 'not_profitable';
                }
            }

            // PM-8 counter updates for this position
            $pm8Seen++;
            if ($eligibleForPm) {
                $pm8Eligible++;
            }
            if ($proposalComputed) {
                $pm8Proposals++;
            }
            if ($exchangeAttempted) {
                $pm8Attempted++;
                if ($exchangeOk) {
                    $pm8Success++;
                } else {
                    $pm8Blocked++;
                }
            } else {
                $pm8Skipped++;
            }

            // PM-9: Stabilization observability fields (per position, multi-tick safety)
            $pm9RegressionPrevHere     = false;
            $pm9DuplicateApplyHere     = false;
            $pm9NoopHere               = false;
            $pm9NoChangeReason         = null;

            if ($eligibleForPm && !$proposalComputed) {
                // Armed but no exchange update — classify why
                if ($skipReason === 'not_improving') {
                    // Step trailing ratchet prevented a backward or same-level stop update
                    $pm9RegressionPrevHere = true;
                    $pm9RegressionPrevented++;
                    $pm9NoChangeReason = 'ratchet_not_improving';
                    // Sub-case: ratchet specifically prevented re-applying an already-current stop
                    $lastAppliedStop = (float)($prevState['last_applied_stop_price'] ?? 0.0);
                    if ($lastAppliedStop > 0.0
                        && $currentStopLoss > 0.0
                        && abs($currentStopLoss - $lastAppliedStop) < 0.0001
                    ) {
                        $pm9DuplicateApplyHere = true;
                        $pm9DuplicateApplyPrevented++;
                    }
                } else {
                    // Armed but no improvement computable (distance gate, cooldown, step not yet due)
                    $pm9NoopHere       = true;
                    $pm9NoopSameLock++;
                    $pm9NoChangeReason = $skipReason ?? 'no_trailing_action_computed';
                }
            } elseif (!$eligibleForPm) {
                $pm9NoChangeReason = $skipReason ?? 'below_activation_roi';
            }

            $itemResult['previous_lock_roi']           = $previousLockRoi;
            $itemResult['carried_forward_state']       = $carriedForwardState;
            $itemResult['regression_prevented']        = $pm9RegressionPrevHere;
            $itemResult['duplicate_apply_prevented']   = $pm9DuplicateApplyHere;
            $itemResult['noop_same_lock']              = $pm9NoopHere;
            $itemResult['no_change_reason']            = $pm9NoChangeReason;

            // PM-10: Post-entry refinement observability fields
            $itemResult['refinement_policy_stage']             = $pm10Fields['refinement_policy_stage'];
            $itemResult['refinement_reason']                   = $pm10Fields['refinement_reason'];
            $itemResult['first_lock_protection_active']        = $pm10Fields['first_lock_protection_active'];
            $itemResult['continuation_extension_applied']      = $pm10Fields['continuation_extension_applied'];
            $itemResult['shallow_pullback_protection_active']  = $pm10Fields['shallow_pullback_protection_active'];
            $itemResult['proposed_lock_roi_before_refinement'] = $pm10Fields['proposed_lock_roi_before_refinement'];
            $itemResult['proposed_lock_roi_after_refinement']  = $pm10Fields['proposed_lock_roi_after_refinement'];
            $itemResult['refinement_delta_roi']                = $pm10Fields['refinement_delta_roi'];

            // PM-11: Adaptive refinement observability fields
            $itemResult['adaptive_refinement_mode']   = $pm11Fields['adaptive_refinement_mode'];
            $itemResult['adaptive_refinement_reason'] = $pm11Fields['adaptive_refinement_reason'];
            $itemResult['adaptive_input_regime']      = $pm11Fields['adaptive_input_regime'];
            $itemResult['adaptive_strength_bucket']   = $pm11Fields['adaptive_strength_bucket'];
            $itemResult['adaptive_action_taken']      = $pm11Fields['adaptive_action_taken'];
            $itemResult['adaptive_adjustment_roi']    = $pm11Fields['adaptive_adjustment_roi'];
            $itemResult['adaptive_bounds_applied']    = $pm11Fields['adaptive_bounds_applied'];

            // PM-15: Cycle caution layer observability fields
            $itemResult['cycle_pm_caution_used']        = $cycPmCautionUsed;
            $itemResult['cycle_pm_caution_applied']     = $cycPmCautionApplied;
            $itemResult['cycle_pm_caution_reason']      = $cycPmCautionReason;
            $itemResult['cycle_pm_model_state']         = $cycPmModelState;
            $itemResult['cycle_pm_model_risk']          = $cycPmModelRisk;
            $itemResult['cycle_pm_model_actionability'] = $cycPmModelAction;

            // PM-16: Cycle positive support layer observability fields
            $itemResult['cycle_pm_support_used']              = $cycPmSupportUsed;
            $itemResult['cycle_pm_support_applied']           = $cycPmSupportApplied;
            $itemResult['cycle_pm_support_reason']            = $cycPmSupportReason;
            $itemResult['cycle_pm_support_model_state']       = $cycPmModelState;
            $itemResult['cycle_pm_support_model_risk']        = $cycPmModelRisk;
            $itemResult['cycle_pm_support_model_actionability'] = $cycPmModelAction;

            // PM-17: Profit capture observability fields
            $itemResult['pm17_capture_mode']         = $pm17Fields['pm17_capture_mode'];
            $itemResult['pm17_capture_reason']       = $pm17Fields['pm17_capture_reason'];
            $itemResult['pm17_drawdown']             = $pm17Fields['pm17_drawdown'];
            $itemResult['pm17_drawdown_fraction']    = $pm17Fields['pm17_drawdown_fraction'];
            $itemResult['pm17_peak_meaningful']      = $pm17Fields['pm17_peak_meaningful'];
            $itemResult['pm17_override_hold']        = $pm17Fields['pm17_override_hold'];
            $itemResult['pm17_applied']              = $pm17Fields['pm17_applied'];

            // PM-18 (pm_refine): Peak-drawdown refinement v2 observability fields
            $itemResult['pm_refine_used']                = $pmRefineFields['pm_refine_used'];
            $itemResult['pm_refine_applied']             = $pmRefineFields['pm_refine_applied'];
            $itemResult['pm_refine_reason']              = $pmRefineFields['pm_refine_reason'];
            $itemResult['pm_refine_peak_roi']            = $pmRefineFields['pm_refine_peak_roi'];
            $itemResult['pm_refine_current_roi']         = $pmRefineFields['pm_refine_current_roi'];
            $itemResult['pm_refine_drawdown_from_peak']  = $pmRefineFields['pm_refine_drawdown_from_peak'];
            $itemResult['pm_refine_pullback_state']      = $pmRefineFields['pm_refine_pullback_state'];
            $itemResult['pm_refine_profit_capture_sync'] = $pmRefineFields['pm_refine_profit_capture_sync'];

            // Bot trade context or unavailability reason
            if ($botTrade !== null) {
                $itemResult['bot_context'] = [
                    'bot_trade_id'       => $botTrade['trade_id'] ?? null,
                    'bot_effective_stop' => $botTrade['current_effective_stop_price'] ?? null,
                    'bot_floor_lock_roi' => $botTrade['floor_locked_roi'] ?? ($botTrade['step_lock_roi'] ?? null),
                ];
            } elseif (empty($botTrades)) {
                $itemResult['active_context_unavailable_reason'] = 'no_bot_trades_loaded';
            } else {
                $itemResult['active_context_unavailable_reason'] = 'no_matching_bot_trade';
            }

            // PM-9: Record this trade key as handled this tick (for stale-state cleanup)
            $safeTradeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $tradeKey);
            $handledTradeKeys[$safeTradeKey] = true;

            $items[] = $itemResult;

            // Update stats (same as run())
            if ($st !== null) {
                $action = $st['action'] ?? 'skip';
                if ($action === 'step_sl_update') {
                    $stats['step_trailing']['applied']++;
                } elseif ($action === 'failed') {
                    $stats['step_trailing']['failed']++;
                } else {
                    $stats['step_trailing']['skipped']++;
                }
            }

            if ($dt !== null) {
                $action = $dt['action'] ?? 'skip';
                if ($action === 'dumb_trailing_set') {
                    $stats['dumb_trailing']['applied']++;
                } elseif ($action === 'failed') {
                    $stats['dumb_trailing']['failed']++;
                } else {
                    $stats['dumb_trailing']['skipped']++;
                }
            }

            if (!empty($itemResult['errors'])) {
                $errors = array_merge($errors, $itemResult['errors']);
            }

            $this->updateSymbolStatus($symbol, $ctx, $itemResult);
        }

        // PM-9: Cleanup stale active states for positions that are no longer being managed.
        // Grace period of 300 s prevents spurious cleanup on transient exchange fetch gaps.
        $gracePeriodSec     = 300;
        $allActiveStateKeys = $this->store->loadAllActiveStateKeys();
        foreach ($allActiveStateKeys as $safeKey) {
            if (isset($handledTradeKeys[$safeKey])) {
                continue; // Position was seen this tick — healthy
            }
            // Load the persisted state to check age and identity
            $prevActiveState = $this->store->loadActiveState($safeKey);
            $updatedAt       = $prevActiveState['updated_at'] ?? null;
            $ageSeconds      = $updatedAt ? max(0, time() - (int)strtotime((string)$updatedAt)) : PHP_INT_MAX;
            if ($ageSeconds < $gracePeriodSec) {
                continue; // Too fresh — may be a transient exchange fetch blip
            }
            // Classify: if the position's symbol+side has an active bot trade → position
            // is still alive in bot records but absent from exchange (missing/race condition).
            // If no bot trade record → position is likely cleanly closed.
            $cleanupSymbol = (string)($prevActiveState['symbol'] ?? '');
            $cleanupSide   = (string)($prevActiveState['side'] ?? '');
            $botKey        = $cleanupSymbol . '_' . $cleanupSide;
            $isClosed      = !isset($botTradeByKey[$botKey]);
            $this->store->deleteActiveState($safeKey);
            if ($isClosed) {
                $pm9ClosedCleanup++;
            } else {
                $pm9MissingCleanup++;
            }
        }

        // PM-9: Merge this-run counts into cumulative pm9_counters.json
        $prevPm9    = $this->store->loadPm9Counters();
        $pm9Totals  = [
            'active_owner_cycles_total'                        => ((int)($prevPm9['active_owner_cycles_total']                        ?? 0)) + 1,
            'active_owner_positions_carried_forward_total'     => ((int)($prevPm9['active_owner_positions_carried_forward_total']     ?? 0)) + $pm9CarriedForward,
            'active_owner_noop_same_lock_total'                => ((int)($prevPm9['active_owner_noop_same_lock_total']                ?? 0)) + $pm9NoopSameLock,
            'active_owner_regression_prevented_total'          => ((int)($prevPm9['active_owner_regression_prevented_total']          ?? 0)) + $pm9RegressionPrevented,
            'active_owner_duplicate_apply_prevented_total'     => ((int)($prevPm9['active_owner_duplicate_apply_prevented_total']     ?? 0)) + $pm9DuplicateApplyPrevented,
            'active_owner_position_missing_cleanup_total'      => ((int)($prevPm9['active_owner_position_missing_cleanup_total']      ?? 0)) + $pm9MissingCleanup,
            'active_owner_position_closed_cleanup_total'       => ((int)($prevPm9['active_owner_position_closed_cleanup_total']       ?? 0)) + $pm9ClosedCleanup,
            'updated_at'                                       => $ts,
        ];
        $this->store->savePm9Counters($pm9Totals);

        // PM-10: Merge this-run refinement counts into cumulative pm10_counters.json
        $prevPm10   = $this->store->loadPm10Counters();
        $pm10Totals = [
            'refinement_first_lock_protection_total'       => ((int)($prevPm10['refinement_first_lock_protection_total']       ?? 0)) + $pm10FirstLockProtection,
            'refinement_continuation_extension_total'      => ((int)($prevPm10['refinement_continuation_extension_total']      ?? 0)) + $pm10ContinuationExtension,
            'refinement_shallow_pullback_protection_total' => ((int)($prevPm10['refinement_shallow_pullback_protection_total'] ?? 0)) + $pm10ShallowPullback,
            'refinement_noop_total'                        => ((int)($prevPm10['refinement_noop_total']                        ?? 0)) + $pm10Noop,
            'refinement_symbols_affected_total'            => ((int)($prevPm10['refinement_symbols_affected_total']            ?? 0)) + $pm10SymbolsAffected,
            'updated_at'                                   => $ts,
        ];
        $this->store->savePm10Counters($pm10Totals);

        // PM-11: Merge adaptive refinement counts into cumulative pm11_counters.json
        $prevPm11   = $this->store->loadPm11Counters();
        $pm11Totals = [
            'adaptive_mode_weak_continuation_total'   => ((int)($prevPm11['adaptive_mode_weak_continuation_total']   ?? 0)) + $pm11WeakContinuation,
            'adaptive_mode_steady_continuation_total' => ((int)($prevPm11['adaptive_mode_steady_continuation_total'] ?? 0)) + $pm11SteadyContinuation,
            'adaptive_mode_strong_continuation_total' => ((int)($prevPm11['adaptive_mode_strong_continuation_total'] ?? 0)) + $pm11StrongContinuation,
            'adaptive_mode_shallow_pullback_total'    => ((int)($prevPm11['adaptive_mode_shallow_pullback_total']    ?? 0)) + $pm11ShallowPullback,
            'adaptive_mode_flat_carry_total'          => ((int)($prevPm11['adaptive_mode_flat_carry_total']          ?? 0)) + $pm11FlatCarry,
            'adaptive_adjustment_applied_total'       => ((int)($prevPm11['adaptive_adjustment_applied_total']       ?? 0)) + $pm11AdjApplied,
            'adaptive_adjustment_noop_total'          => ((int)($prevPm11['adaptive_adjustment_noop_total']          ?? 0)) + $pm11AdjNoop,
            'adaptive_bounds_hit_total'               => ((int)($prevPm11['adaptive_bounds_hit_total']               ?? 0)) + $pm11BoundsHit,
            'updated_at'                              => $ts,
        ];
        $this->store->savePm11Counters($pm11Totals);

        // PM-15: Merge cycle caution counts into cumulative pm15_cycle_caution_counters.json
        $prevCyc15   = $this->store->loadCyclePmCautionCounters();
        $cyc15Totals = [
            'cycle_pm_caution_total'           => ((int)($prevCyc15['cycle_pm_caution_total']           ?? 0)) + $cycPmCautionTotal,
            'cycle_pm_caution_block_total'     => ((int)($prevCyc15['cycle_pm_caution_block_total']     ?? 0)) + $cycPmCautionBlockTotal,
            'cycle_pm_caution_no_effect_total' => ((int)($prevCyc15['cycle_pm_caution_no_effect_total'] ?? 0)) + $cycPmCautionNoEffectTotal,
            'cycle_pm_caution_unavailable_total' => ((int)($prevCyc15['cycle_pm_caution_unavailable_total'] ?? 0)) + $cycPmCautionUnavailTotal,
            'updated_at'                       => $ts,
        ];
        $this->store->saveCyclePmCautionCounters($cyc15Totals);

        // PM-16: Merge cycle support counts into cumulative pm16_cycle_support_counters.json
        $prevCyc16   = $this->store->loadCyclePmSupportCounters();
        $cyc16Totals = [
            'cycle_pm_support_total'           => ((int)($prevCyc16['cycle_pm_support_total']           ?? 0)) + $cycPmSupportTotal,
            'cycle_pm_support_apply_total'     => ((int)($prevCyc16['cycle_pm_support_apply_total']     ?? 0)) + $cycPmSupportApplyTotal,
            'cycle_pm_support_no_effect_total' => ((int)($prevCyc16['cycle_pm_support_no_effect_total'] ?? 0)) + $cycPmSupportNoEffectTotal,
            'cycle_pm_support_unavailable_total' => ((int)($prevCyc16['cycle_pm_support_unavailable_total'] ?? 0)) + $cycPmSupportUnavailTotal,
            'updated_at'                       => $ts,
        ];
        $this->store->saveCyclePmSupportCounters($cyc16Totals);

        // PM-17: Merge this-run profit capture counts into cumulative pm17_profit_capture_counters.json
        $prevPm17   = $this->store->loadPm17Counters();
        $pm17Totals = [
            'pm_profit_capture_total'              => ((int)($prevPm17['pm_profit_capture_total']              ?? 0)) + $pm17CaptureTotal,
            'pm_profit_capture_apply_total'        => ((int)($prevPm17['pm_profit_capture_apply_total']        ?? 0)) + $pm17CaptureApplyTotal,
            'pm_profit_capture_peak_drawdown_total'=> ((int)($prevPm17['pm_profit_capture_peak_drawdown_total']?? 0)) + $pm17PeakDrawdownTotal,
            'pm_profit_capture_shallow_pullback_hold_total' => ((int)($prevPm17['pm_profit_capture_shallow_pullback_hold_total'] ?? 0)) + $pm17ShallowPullbackHoldTotal,
            'pm_profit_capture_lock_strengthen_total' => ((int)($prevPm17['pm_profit_capture_lock_strengthen_total'] ?? 0)) + $pm17LockStrengthenTotal,
            'pm_profit_capture_intermediate_total' => ((int)($prevPm17['pm_profit_capture_intermediate_total'] ?? 0)) + $pm17IntermediateTotal,
            'pm_profit_capture_no_effect_total'    => ((int)($prevPm17['pm_profit_capture_no_effect_total']    ?? 0)) + $pm17NoEffectTotal,
            'updated_at'                           => $ts,
        ];
        $this->store->savePm17Counters($pm17Totals);

        // PM-18 (pm_refine): Merge this-run peak-drawdown refinement v2 counts into pm_refine_counters.json
        $prevPmRef   = $this->store->loadPmRefineCounters();
        $pmRefTotals = [
            'pm_refine_total'          => ((int)($prevPmRef['pm_refine_total']          ?? 0)) + $pmRefTotal,
            'pm_refine_apply_total'    => ((int)($prevPmRef['pm_refine_apply_total']    ?? 0)) + $pmRefApplyTotal,
            'pm_refine_no_effect_total'=> ((int)($prevPmRef['pm_refine_no_effect_total']?? 0)) + $pmRefNoEffectTotal,
            'pm_refine_protect_total'  => ((int)($prevPmRef['pm_refine_protect_total']  ?? 0)) + $pmRefProtectTotal,
            'pm_refine_continue_total' => ((int)($prevPmRef['pm_refine_continue_total'] ?? 0)) + $pmRefContinueTotal,
            'updated_at'               => $ts,
        ];
        $this->store->savePmRefineCounters($pmRefTotals);

        return [
            'positions_total'   => $positionsTotal,
            'positions_managed' => $positionsManaged,
            'items'             => $items,
            'stats'             => $stats,
            'errors'            => $errors,
            'warnings'          => $warnings,
            // PM-8: aggregated active-owner proof counters (consumed by service.php executeActive)
            'pm8_counters'      => [
                'active_owner_symbols_seen_total'        => $pm8Seen,
                'active_owner_symbols_eligible_total'    => $pm8Eligible,
                'active_owner_proposals_computed_total'  => $pm8Proposals,
                'active_owner_apply_attempted_total'     => $pm8Attempted,
                'active_owner_apply_success_total'       => $pm8Success,
                'active_owner_apply_skipped_total'       => $pm8Skipped,
                'active_owner_apply_blocked_total'       => $pm8Blocked,
                // Always surface the threshold used for this run (key for archive verification)
                'activation_roi_threshold'               => $activationRoiPct,
            ],
            // PM-9: stabilization counters (both this-run and cumulative totals)
            'pm9_counters'      => [
                // This-run deltas
                'this_run_carried_forward'                     => $pm9CarriedForward,
                'this_run_noop_same_lock'                      => $pm9NoopSameLock,
                'this_run_regression_prevented'                => $pm9RegressionPrevented,
                'this_run_duplicate_apply_prevented'           => $pm9DuplicateApplyPrevented,
                'this_run_missing_cleanup'                     => $pm9MissingCleanup,
                'this_run_closed_cleanup'                      => $pm9ClosedCleanup,
                // Cumulative totals (updated this tick)
                'active_owner_cycles_total'                    => $pm9Totals['active_owner_cycles_total'],
                'active_owner_positions_carried_forward_total' => $pm9Totals['active_owner_positions_carried_forward_total'],
                'active_owner_noop_same_lock_total'            => $pm9Totals['active_owner_noop_same_lock_total'],
                'active_owner_regression_prevented_total'      => $pm9Totals['active_owner_regression_prevented_total'],
                'active_owner_duplicate_apply_prevented_total' => $pm9Totals['active_owner_duplicate_apply_prevented_total'],
                'active_owner_position_missing_cleanup_total'  => $pm9Totals['active_owner_position_missing_cleanup_total'],
                'active_owner_position_closed_cleanup_total'   => $pm9Totals['active_owner_position_closed_cleanup_total'],
            ],
            // PM-10: refinement counters (both this-run and cumulative totals)
            'pm10_counters'     => [
                // This-run deltas
                'this_run_first_lock_protection'               => $pm10FirstLockProtection,
                'this_run_continuation_extension'              => $pm10ContinuationExtension,
                'this_run_shallow_pullback_protection'         => $pm10ShallowPullback,
                'this_run_noop'                                => $pm10Noop,
                'this_run_symbols_affected'                    => $pm10SymbolsAffected,
                // Cumulative totals
                'refinement_first_lock_protection_total'       => $pm10Totals['refinement_first_lock_protection_total'],
                'refinement_continuation_extension_total'      => $pm10Totals['refinement_continuation_extension_total'],
                'refinement_shallow_pullback_protection_total' => $pm10Totals['refinement_shallow_pullback_protection_total'],
                'refinement_noop_total'                        => $pm10Totals['refinement_noop_total'],
                'refinement_symbols_affected_total'            => $pm10Totals['refinement_symbols_affected_total'],
            ],
            // PM-11: adaptive counters (both this-run and cumulative totals)
            'pm11_counters'     => [
                // This-run deltas
                'this_run_weak_continuation'    => $pm11WeakContinuation,
                'this_run_steady_continuation'  => $pm11SteadyContinuation,
                'this_run_strong_continuation'  => $pm11StrongContinuation,
                'this_run_shallow_pullback'     => $pm11ShallowPullback,
                'this_run_flat_carry'           => $pm11FlatCarry,
                'this_run_adj_applied'          => $pm11AdjApplied,
                'this_run_adj_noop'             => $pm11AdjNoop,
                'this_run_bounds_hit'           => $pm11BoundsHit,
                // Cumulative totals
                'adaptive_mode_weak_continuation_total'   => $pm11Totals['adaptive_mode_weak_continuation_total'],
                'adaptive_mode_steady_continuation_total' => $pm11Totals['adaptive_mode_steady_continuation_total'],
                'adaptive_mode_strong_continuation_total' => $pm11Totals['adaptive_mode_strong_continuation_total'],
                'adaptive_mode_shallow_pullback_total'    => $pm11Totals['adaptive_mode_shallow_pullback_total'],
                'adaptive_mode_flat_carry_total'          => $pm11Totals['adaptive_mode_flat_carry_total'],
                'adaptive_adjustment_applied_total'       => $pm11Totals['adaptive_adjustment_applied_total'],
                'adaptive_adjustment_noop_total'          => $pm11Totals['adaptive_adjustment_noop_total'],
                'adaptive_bounds_hit_total'               => $pm11Totals['adaptive_bounds_hit_total'],
            ],
            // PM-15: cycle caution counters (both this-run and cumulative totals)
            'pm15_counters'     => [
                // This-run deltas
                'this_run_caution_total'           => $cycPmCautionTotal,
                'this_run_caution_block_total'     => $cycPmCautionBlockTotal,
                'this_run_caution_no_effect_total' => $cycPmCautionNoEffectTotal,
                'this_run_caution_unavailable_total' => $cycPmCautionUnavailTotal,
                // Cumulative totals
                'cycle_pm_caution_total'           => $cyc15Totals['cycle_pm_caution_total'],
                'cycle_pm_caution_block_total'     => $cyc15Totals['cycle_pm_caution_block_total'],
                'cycle_pm_caution_no_effect_total' => $cyc15Totals['cycle_pm_caution_no_effect_total'],
                'cycle_pm_caution_unavailable_total' => $cyc15Totals['cycle_pm_caution_unavailable_total'],
            ],
            // PM-16: cycle positive support counters (both this-run and cumulative totals)
            'pm16_counters'     => [
                // This-run deltas
                'this_run_support_total'           => $cycPmSupportTotal,
                'this_run_support_apply_total'     => $cycPmSupportApplyTotal,
                'this_run_support_no_effect_total' => $cycPmSupportNoEffectTotal,
                'this_run_support_unavailable_total' => $cycPmSupportUnavailTotal,
                // Cumulative totals
                'cycle_pm_support_total'           => $cyc16Totals['cycle_pm_support_total'],
                'cycle_pm_support_apply_total'     => $cyc16Totals['cycle_pm_support_apply_total'],
                'cycle_pm_support_no_effect_total' => $cyc16Totals['cycle_pm_support_no_effect_total'],
                'cycle_pm_support_unavailable_total' => $cyc16Totals['cycle_pm_support_unavailable_total'],
            ],
            // PM-17: profit capture counters (both this-run and cumulative totals)
            'pm17_counters'     => [
                // This-run deltas
                'this_run_capture_total'              => $pm17CaptureTotal,
                'this_run_capture_apply_total'        => $pm17CaptureApplyTotal,
                'this_run_peak_drawdown_total'        => $pm17PeakDrawdownTotal,
                'this_run_shallow_pullback_hold_total'=> $pm17ShallowPullbackHoldTotal,
                'this_run_lock_strengthen_total'      => $pm17LockStrengthenTotal,
                'this_run_intermediate_total'         => $pm17IntermediateTotal,
                'this_run_no_effect_total'            => $pm17NoEffectTotal,
                // Cumulative totals
                'pm_profit_capture_total'              => $pm17Totals['pm_profit_capture_total'],
                'pm_profit_capture_apply_total'        => $pm17Totals['pm_profit_capture_apply_total'],
                'pm_profit_capture_peak_drawdown_total'=> $pm17Totals['pm_profit_capture_peak_drawdown_total'],
                'pm_profit_capture_shallow_pullback_hold_total' => $pm17Totals['pm_profit_capture_shallow_pullback_hold_total'],
                'pm_profit_capture_lock_strengthen_total' => $pm17Totals['pm_profit_capture_lock_strengthen_total'],
                'pm_profit_capture_intermediate_total' => $pm17Totals['pm_profit_capture_intermediate_total'],
                'pm_profit_capture_no_effect_total'    => $pm17Totals['pm_profit_capture_no_effect_total'],
            ],
            // PM-18 (pm_refine): peak-drawdown refinement v2 counters (both this-run and cumulative)
            'pm_refine_counters' => [
                // This-run deltas
                'this_run_total'          => $pmRefTotal,
                'this_run_apply_total'    => $pmRefApplyTotal,
                'this_run_no_effect_total'=> $pmRefNoEffectTotal,
                'this_run_protect_total'  => $pmRefProtectTotal,
                'this_run_continue_total' => $pmRefContinueTotal,
                // Cumulative totals
                'pm_refine_total'          => $pmRefTotals['pm_refine_total'],
                'pm_refine_apply_total'    => $pmRefTotals['pm_refine_apply_total'],
                'pm_refine_no_effect_total'=> $pmRefTotals['pm_refine_no_effect_total'],
                'pm_refine_protect_total'  => $pmRefTotals['pm_refine_protect_total'],
                'pm_refine_continue_total' => $pmRefTotals['pm_refine_continue_total'],
            ],
        ];
    }

    /**
     * Main execution cycle
     * 
     * @return array Execution result
     */
    public function run(): array
    {
        $items = [];
        $errors = [];
        $warnings = [];
        
        $stats = [
            'step_trailing' => ['applied' => 0, 'skipped' => 0, 'failed' => 0],
            'dumb_trailing' => ['applied' => 0, 'skipped' => 0, 'failed' => 0],
        ];
        
        // Step 2: Fetch exchange positions
        $positionsResult = $this->gateway->getPositions();
        if (!($positionsResult['ok'] ?? false)) {
            $errors[] = 'fetch_positions_failed: ' . ($positionsResult['error'] ?? 'unknown');
            return [
                'positions_total' => 0,
                'positions_managed' => 0,
                'items' => $items,
                'stats' => $stats,
                'errors' => $errors,
                'warnings' => $warnings,
            ];
        }
        
        $positions = $positionsResult['positions'] ?? [];
        $positionsTotal = count($positions);
        
        // Step 3: Select managed positions
        $managed = $this->selector->getManagedSymbols($positions);
        $positionsManaged = count($managed);
        
        // Step 4: Apply rules for each managed position
        foreach ($managed as $symbol => $ctx) {
            $itemResult = $this->processPosition($symbol, $ctx);
            $items[] = $itemResult;
            
            // Update stats
            if (isset($itemResult['step_trailing'])) {
                $action = $itemResult['step_trailing']['action'] ?? 'skip';
                if ($action === 'step_sl_update') {
                    $stats['step_trailing']['applied']++;
                } elseif ($action === 'failed') {
                    $stats['step_trailing']['failed']++;
                } else {
                    $stats['step_trailing']['skipped']++;
                }
            }
            
            if (isset($itemResult['dumb_trailing'])) {
                $action = $itemResult['dumb_trailing']['action'] ?? 'skip';
                if ($action === 'dumb_trailing_set') {
                    $stats['dumb_trailing']['applied']++;
                } elseif ($action === 'failed') {
                    $stats['dumb_trailing']['failed']++;
                } else {
                    $stats['dumb_trailing']['skipped']++;
                }
            }
            
            // Collect errors
            if (!empty($itemResult['errors'])) {
                $errors = array_merge($errors, $itemResult['errors']);
            }
            
            // Update symbol status
            $this->updateSymbolStatus($symbol, $ctx, $itemResult);
        }
        
        return [
            'positions_total' => $positionsTotal,
            'positions_managed' => $positionsManaged,
            'items' => $items,
            'stats' => $stats,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
    
    /**
     * Process a single position
     * 
     * @param string $symbol Symbol
     * @param array $ctx Managed context
     * @return array Position result
     */
    private function processPosition(string $symbol, array $ctx): array
    {
        $position = $ctx['position'] ?? [];
        $result = [
            'symbol' => $symbol,
            'side' => $this->validator->normalizeSide($position['side'] ?? ''),
            'roi_pct' => null,
            'step_trailing' => null,
            'dumb_trailing' => null,
            'errors' => [],
        ];
        
        // Validate context
        $validation = $this->validator->validateManagedContext($ctx);
        if (!$validation['ok']) {
            $result['errors'] = $validation['errors'];
            return $result;
        }
        
        // Calculate ROI
        $positionIM = (float) ($position['positionIM'] ?? 0);
        $unrealisedPnl = (float) ($position['unrealisedPnl'] ?? 0);
        $roi = $this->riskMath->calculateRoiPct($unrealisedPnl, $positionIM);
        $result['roi_pct'] = $roi;
        
        // Get instrument meta for tick size
        $tickSize = $this->getTickSize($symbol);
        
        // Apply Step Trailing (priority 1)
        $stepResult = $this->processStepTrailing($symbol, $position, $ctx, $tickSize);
        $result['step_trailing'] = $stepResult;
        
        // Apply Dumb Trailing (priority 2)
        $dumbResult = $this->processDumbTrailing($symbol, $position, $ctx, $tickSize);
        $result['dumb_trailing'] = $dumbResult;
        
        return $result;
    }
    
    /**
     * Process step trailing for position
     */
    private function processStepTrailing(string $symbol, array $position, array $ctx, float $tickSize): array
    {
        // PM-10: Active-owner refinement hold — if the refinement layer decided to hold this tick, skip.
        if (!empty($ctx['pm10_hold'])) {
            return [
                'action' => 'skip',
                'reason' => $ctx['pm10_hold_reason'] ?? 'pm10_refinement_hold',
            ];
        }

        // Check if should skip
        $skipReason = $this->validator->shouldSkipStepTrailing($position, $ctx, $this->riskMath);
        if ($skipReason !== null) {
            return [
                'action' => 'skip',
                'reason' => $skipReason,
            ];
        }

        // PM-15: Cycle caution — evaluated AFTER skip/eligibility check so it can only intercept
        // a symbol that is genuinely on the execute path (would apply a step trailing update).
        // Trigger conditions match the pre-check in runActive(); the return includes
        // cycle_pm_caution_blocked=true so runActive() can update block_total and applied.
        $cycPmModel = $ctx['cycle_pm_caution_model'] ?? null;
        if ($cycPmModel !== null) {
            $cycPmModelState  = $cycPmModel['decision_model_state']         ?? 'unavailable';
            $cycPmModelRisk   = $cycPmModel['decision_model_risk_posture']  ?? 'unavailable';
            $cycPmModelAction = $cycPmModel['decision_model_actionability'] ?? 'non_actionable';
            $cycPmCautionReason = null;
            if ($cycPmModelState === 'unavailable' || $cycPmModelState === 'weak') {
                $cycPmCautionReason = 'cycle_pm_caution_unavailable';
            } elseif ($cycPmModelState === 'cautious') {
                $cycPmCautionReason = 'cycle_pm_caution_non_actionable';
            } elseif ($cycPmModelAction === 'non_actionable') {
                $cycPmCautionReason = 'cycle_pm_caution_non_actionable';
            } elseif ($cycPmModelRisk === 'high_risk') {
                $cycPmCautionReason = 'cycle_pm_caution_high_risk';
            } elseif (!empty($cycPmModel['decision_model_low_confidence_flag'])) {
                $cycPmCautionReason = 'cycle_pm_caution_low_confidence';
            } elseif (
                !empty($cycPmModel['decision_model_warning_flag'])
                && !empty($cycPmModel['decision_model_warning_reason'])
            ) {
                $cycPmCautionReason = 'cycle_pm_caution_non_actionable';
            }
            if ($cycPmCautionReason !== null) {
                return [
                    'action'                   => 'skip',
                    'reason'                   => $cycPmCautionReason,
                    'cycle_pm_caution_blocked' => true,
                    'cycle_pm_caution_reason'  => $cycPmCautionReason,
                ];
            }
        }
        
        // Calculate ROI and target lock
        $positionIM = (float) ($position['positionIM'] ?? 0);
        $unrealisedPnl = (float) ($position['unrealisedPnl'] ?? 0);
        $roi = $this->riskMath->calculateRoiPct($unrealisedPnl, $positionIM);
        
        $activationRoi = (float) ($ctx['activation_roi_pct'] ?? $this->config['step_trailing']['activation_roi_pct_default'] ?? 0);
        $stepRoi = (float) ($this->config['step_trailing']['step_roi_pct'] ?? 0);
        $lockBuffer = (float) ($this->config['step_trailing']['lock_buffer_roi_pct'] ?? 0);
        $lockFloor = (float) ($this->config['step_trailing']['lock_floor_roi_pct'] ?? 0);
        
        $targetLockRoi = $this->riskMath->calculateStepTrailingLockRoi(
            $roi,
            $activationRoi,
            $stepRoi,
            $lockBuffer,
            $lockFloor
        );
        
        if ($targetLockRoi === null) {
            return [
                'action' => 'skip',
                'reason' => 'no_valid_lock_roi',
            ];
        }
        
        // Calculate new SL price
        $side = $this->validator->normalizeSide($position['side'] ?? '');
        $entryPrice = (float) ($position['avgPrice'] ?? 0);
        $leverage = (float) ($ctx['leverage'] ?? 0);
        
        $candidateSL = $this->riskMath->calculateStepTrailingSL($side, $entryPrice, $targetLockRoi, $leverage);
        if ($candidateSL === null) {
            return [
                'action' => 'skip',
                'reason' => 'cannot_calculate_sl',
            ];
        }
        
        // Validate SL is on profitable side
        if (!$this->riskMath->isSLOnProfitableSide($side, $candidateSL, $entryPrice)) {
            return [
                'action' => 'skip',
                'reason' => 'sl_not_on_profitable_side',
            ];
        }
        
        // Validate SL is safe distance from current price
        $markPrice = (float) ($position['markPrice'] ?? 0);
        $lastPrice = (float) ($position['lastPrice'] ?? $markPrice);
        $refPrice = $this->riskMath->getReferencePrice($side, $markPrice, $lastPrice);
        $minDistancePctBase = (float) ($this->config['step_trailing']['min_distance_to_price_pct'] ?? 0.05);
        $minDistancePctEffective = $minDistancePctBase;
        if ($leverage > 0.0) {
            // Step trailing uses ROI (margin %) which already includes leverage. To avoid a hard block on high leverage,
            // scale min-distance (price %) by leverage.
            $minDistancePctEffective = $minDistancePctBase / max(1.0, $leverage);
        }

        if (!$this->riskMath->isSLSafeDistance($side, $candidateSL, $refPrice, $minDistancePctEffective)) {
            return [
                'action' => 'skip',
                'reason' => 'too_close_to_price',
                'debug' => [
                    'candidate_sl' => $candidateSL,
                    'ref_price' => $refPrice,
                    'min_distance_pct_base' => $minDistancePctBase,
                    'min_distance_pct_effective' => $minDistancePctEffective,
                    'leverage' => $leverage,
                ],
            ];
        }
        
        // Normalize by tick size
        $newSL = $this->riskMath->normalizeSLPrice($side, $candidateSL, $tickSize);
        
        // Validate ratchet (new SL must improve over old)
        $oldSL = (float) ($position['stopLoss'] ?? 0);
        if (!$this->riskMath->isSLImproving($side, $newSL, $oldSL)) {
            return [
                'action' => 'skip',
                'reason' => 'not_improving',
                'debug' => [
                    'old_sl' => $oldSL,
                    'new_sl' => $newSL,
                ],
            ];
        }
        
        // Apply the SL update
        $applyResult = $this->applier->applyStopLoss($symbol, $side, $newSL, $oldSL, $tickSize, [
            'roi' => $roi,
            'target_lock_roi' => $targetLockRoi,
            'leverage' => $leverage,
            'entry' => $entryPrice,
            'ref_price' => $refPrice,
        ]);
        
        return array_merge($applyResult, [
            'old_sl' => $oldSL,
            'new_sl' => $newSL,
            'roi' => $roi,
            'target_lock_roi' => $targetLockRoi,
        ]);
    }
    
    /**
     * Process dumb trailing for position
     */
    private function processDumbTrailing(string $symbol, array $position, array $ctx, float $tickSize): array
    {
        // Check if should skip
        $skipReason = $this->validator->shouldSkipDumbTrailing($position, $ctx, $this->riskMath);
        if ($skipReason !== null) {
            return [
                'action' => 'skip',
                'reason' => $skipReason,
            ];
        }

        // PM-15: Cycle caution — evaluated AFTER skip/eligibility check so it can only intercept
        // a symbol that is genuinely on the execute path (would apply a dumb trailing update).
        $cycPmModel = $ctx['cycle_pm_caution_model'] ?? null;
        if ($cycPmModel !== null) {
            $cycPmModelState  = $cycPmModel['decision_model_state']         ?? 'unavailable';
            $cycPmModelRisk   = $cycPmModel['decision_model_risk_posture']  ?? 'unavailable';
            $cycPmModelAction = $cycPmModel['decision_model_actionability'] ?? 'non_actionable';
            $cycPmCautionReason = null;
            if ($cycPmModelState === 'unavailable' || $cycPmModelState === 'weak') {
                $cycPmCautionReason = 'cycle_pm_caution_unavailable';
            } elseif ($cycPmModelState === 'cautious') {
                $cycPmCautionReason = 'cycle_pm_caution_non_actionable';
            } elseif ($cycPmModelAction === 'non_actionable') {
                $cycPmCautionReason = 'cycle_pm_caution_non_actionable';
            } elseif ($cycPmModelRisk === 'high_risk') {
                $cycPmCautionReason = 'cycle_pm_caution_high_risk';
            } elseif (!empty($cycPmModel['decision_model_low_confidence_flag'])) {
                $cycPmCautionReason = 'cycle_pm_caution_low_confidence';
            } elseif (
                !empty($cycPmModel['decision_model_warning_flag'])
                && !empty($cycPmModel['decision_model_warning_reason'])
            ) {
                $cycPmCautionReason = 'cycle_pm_caution_non_actionable';
            }
            if ($cycPmCautionReason !== null) {
                return [
                    'action'                   => 'skip',
                    'reason'                   => $cycPmCautionReason,
                    'cycle_pm_caution_blocked' => true,
                    'cycle_pm_caution_reason'  => $cycPmCautionReason,
                ];
            }
        }

        // Check if already has trailing stop
        $existingTrailing = (float) ($position['trailingStop'] ?? 0);
        $existingActive = (float) ($position['activePrice'] ?? 0);
        
        if ($existingTrailing > 0) {
            // Check if needs re-arm
            $side = $this->validator->normalizeSide($position['side'] ?? '');
            $markPrice = (float) ($position['markPrice'] ?? 0);
            $lastPrice = (float) ($position['lastPrice'] ?? $markPrice);
            $refPrice = $this->riskMath->getReferencePrice($side, $markPrice, $lastPrice);
            
            $needsRearm = $this->needsTrailingRearm($side, $existingActive, $refPrice);
            if (!$needsRearm) {
                return [
                    'action' => 'skip',
                    'reason' => 'trailing_already_armed',
                ];
            }
        }
        
        // Calculate trailing parameters
        $side = $this->validator->normalizeSide($position['side'] ?? '');
        $leverage = (float) ($ctx['leverage'] ?? 0);
        $activationRoi = (float) ($ctx['activation_roi_pct'] ?? $this->config['dumb_trailing']['activation_roi_pct_default'] ?? $this->config['step_trailing']['activation_roi_pct_default'] ?? 0);
        $markPrice = (float) ($position['markPrice'] ?? 0);
        $lastPrice = (float) ($position['lastPrice'] ?? $markPrice);
        $refPrice = $this->riskMath->getReferencePrice($side, $markPrice, $lastPrice);
        
        $minDistancePct = (float) ($this->config['dumb_trailing']['min_distance_pct'] ?? 0);
        $drawdownFactor = (float) ($this->config['dumb_trailing']['drawdown_factor_default'] ?? 0);
        $epsilonPct = (float) ($this->config['dumb_trailing']['epsilon_pct'] ?? 0);
        
        $trailingStop = $this->riskMath->calculateDumbTrailingDistance(
            $refPrice,
            $activationRoi,
            $leverage,
            $minDistancePct,
            $drawdownFactor
        );
        
        $activePrice = $this->riskMath->calculateDumbTrailingActivePrice(
            $side,
            $refPrice,
            $epsilonPct,
            $tickSize
        );
        
        // Apply trailing
        $applyResult = $this->applier->applyDumbTrailing($symbol, $side, $trailingStop, $activePrice, [
            'leverage' => $leverage,
            'activation_roi' => $activationRoi,
            'ref_price' => $refPrice,
        ]);
        
        return array_merge($applyResult, [
            'trailing_stop' => $trailingStop,
            'active_price' => $activePrice,
        ]);
    }
    
    /**
     * Check if trailing needs re-arm
     */
    private function needsTrailingRearm(string $side, float $activePrice, float $refPrice): bool
    {
        if ($activePrice <= 0) {
            return true;
        }
        
        // LONG: activePrice should be <= refPrice to be armed
        // SHORT: activePrice should be >= refPrice to be armed
        if ($side === 'long') {
            return $activePrice > $refPrice;
        } else {
            return $activePrice < $refPrice;
        }
    }
    
    /**
     * Get tick size for symbol
     */
    private function getTickSize(string $symbol): float
    {
        // Check cache
        if (isset($this->instrumentCache[$symbol]['tickSize'])) {
            return $this->instrumentCache[$symbol]['tickSize'];
        }
        
        // Fetch from gateway
        $meta = $this->gateway->getInstrumentMeta($symbol);
        if ($meta !== null && isset($meta['tickSize'])) {
            $this->instrumentCache[$symbol] = $meta;
            return (float) $meta['tickSize'];
        }
        
        // Fallback from config
        return (float) ($this->config['exchange']['tick_size_fallback'] ?? 0.0001);
    }
    
    /**
     * Update symbol status in store
     */
    private function updateSymbolStatus(string $symbol, array $ctx, array $result): void
    {
        $position = $ctx['position'] ?? [];

        $status = [
            'last_seen_ts' => time(),
            'roi_pct' => $result['roi_pct'],
            'stop_loss' => (float) ($position['stopLoss'] ?? 0),
            'trailing_stop' => (float) ($position['trailingStop'] ?? 0),
            'active_price' => (float) ($position['activePrice'] ?? 0),

            // Summary (for UI compatibility)
            'last_action' => null,
            'last_action_ts' => null,
            'last_reason' => null,

            // Detailed (for debugging)
            'step_action' => null,
            'step_action_ts' => null,
            'step_reason' => null,
            'dumb_action' => null,
            'dumb_action_ts' => null,
            'dumb_reason' => null,
        ];

        // Record step trailing action
        if (isset($result['step_trailing'])) {
            $st = $result['step_trailing'];
            $status['step_action'] = $st['action'] ?? null;

            if (($st['action'] ?? '') === 'step_sl_update') {
                $status['step_action_ts'] = time();

                // Step trailing has priority in summary
                $status['last_action'] = 'step_sl_update';
                $status['last_action_ts'] = $status['step_action_ts'];

                $status['stop_loss'] = $st['new_sl'] ?? $status['stop_loss'];
            } else {
                $status['step_reason'] = $st['reason'] ?? null;

                // Only set summary reason if empty (do not override later)
                if ($status['last_reason'] === null) {
                    $status['last_reason'] = $status['step_reason'];
                }
            }
        }

        // Record dumb trailing action
        if (isset($result['dumb_trailing'])) {
            $dt = $result['dumb_trailing'];
            $status['dumb_action'] = $dt['action'] ?? null;

            if (($dt['action'] ?? '') === 'dumb_trailing_set') {
                $status['dumb_action_ts'] = time();
                $status['trailing_stop'] = $dt['trailing_stop'] ?? $status['trailing_stop'];
                $status['active_price'] = $dt['active_price'] ?? $status['active_price'];

                // Set summary only if step trailing did not act
                if ($status['last_action'] === null) {
                    $status['last_action'] = 'dumb_trailing_set';
                    $status['last_action_ts'] = $status['dumb_action_ts'];
                }
            } else {
                $status['dumb_reason'] = $dt['reason'] ?? null;

                // Do not overwrite step reason
                if ($status['last_action'] === null && $status['last_reason'] === null) {
                    $status['last_reason'] = $status['dumb_reason'];
                }
            }
        }

        $this->store->updateSymbolStatus($symbol, $status);

        // PM-9: Add stabilization evidence to per-symbol status (active mode only; fields are
        // absent in bot/shadow mode so all reads use ?? to stay safe).
        if (array_key_exists('carried_forward_state', $result)
            || array_key_exists('previous_lock_roi', $result)
        ) {
            $pm9Status = [
                'previous_lock_roi'          => $result['previous_lock_roi']          ?? null,
                'proposed_lock_roi'          => $result['proposed_lock_roi_after_refinement'] ?? ($result['proposed_lock_roi'] ?? null),
                'applied_lock_roi'           => $result['applied_lock_roi']           ?? null,
                'carried_forward_state'      => $result['carried_forward_state']      ?? false,
                'regression_prevented'       => $result['regression_prevented']       ?? false,
                'duplicate_apply_prevented'  => $result['duplicate_apply_prevented']  ?? false,
                'noop_same_lock'             => $result['noop_same_lock']             ?? false,
                'no_change_reason'           => $result['no_change_reason']           ?? null,
                'cleaned_up_missing_position' => false,
                'cleaned_up_closed_position'  => false,
                // PM-10: Refinement evidence
                'refinement_policy_stage'              => $result['refinement_policy_stage']              ?? null,
                'refinement_reason'                    => $result['refinement_reason']                    ?? null,
                'first_lock_protection_active'         => $result['first_lock_protection_active']         ?? false,
                'continuation_extension_applied'       => $result['continuation_extension_applied']       ?? false,
                'shallow_pullback_protection_active'   => $result['shallow_pullback_protection_active']   ?? false,
                'proposed_lock_roi_before_refinement'  => $result['proposed_lock_roi_before_refinement']  ?? null,
                'proposed_lock_roi_after_refinement'   => $result['proposed_lock_roi_after_refinement']   ?? null,
                'refinement_delta_roi'                 => $result['refinement_delta_roi']                 ?? null,
                // PM-11: Adaptive refinement evidence
                'adaptive_refinement_mode'             => $result['adaptive_refinement_mode']             ?? null,
                'adaptive_refinement_reason'           => $result['adaptive_refinement_reason']           ?? null,
                'adaptive_input_regime'                => $result['adaptive_input_regime']                ?? null,
                'adaptive_strength_bucket'             => $result['adaptive_strength_bucket']             ?? null,
                'adaptive_action_taken'                => $result['adaptive_action_taken']                ?? null,
                'adaptive_adjustment_roi'              => $result['adaptive_adjustment_roi']              ?? null,
                'adaptive_bounds_applied'              => $result['adaptive_bounds_applied']              ?? false,
                // PM-15: Cycle caution layer evidence
                'cycle_pm_caution_used'        => $result['cycle_pm_caution_used']        ?? false,
                'cycle_pm_caution_applied'     => $result['cycle_pm_caution_applied']     ?? false,
                'cycle_pm_caution_reason'      => $result['cycle_pm_caution_reason']      ?? null,
                'cycle_pm_model_state'         => $result['cycle_pm_model_state']         ?? null,
                'cycle_pm_model_risk'          => $result['cycle_pm_model_risk']          ?? null,
                'cycle_pm_model_actionability' => $result['cycle_pm_model_actionability'] ?? null,
                // PM-16: Cycle positive support layer evidence — model-state fields always updated;
                // applied/reason omitted when false so array_merge preserves the prior durable value
                // in status.json when support did not fire this run.
                'cycle_pm_support_used'              => $result['cycle_pm_support_used']              ?? false,
                'cycle_pm_support_model_state'       => $result['cycle_pm_support_model_state']       ?? null,
                'cycle_pm_support_model_risk'        => $result['cycle_pm_support_model_risk']        ?? null,
                'cycle_pm_support_model_actionability' => $result['cycle_pm_support_model_actionability'] ?? null,
                // PM-17: Profit capture layer evidence — always updated so drawdown is visible
                'pm17_capture_mode'      => $result['pm17_capture_mode']      ?? null,
                'pm17_capture_reason'    => $result['pm17_capture_reason']    ?? null,
                'pm17_drawdown'          => $result['pm17_drawdown']          ?? null,
                'pm17_drawdown_fraction' => $result['pm17_drawdown_fraction'] ?? null,
                'pm17_peak_meaningful'   => $result['pm17_peak_meaningful']   ?? false,
                'pm17_applied'           => $result['pm17_applied']           ?? false,
                // PM-18 (pm_refine): Peak-drawdown refinement v2 evidence — always updated
                'pm_refine_used'                => $result['pm_refine_used']               ?? false,
                'pm_refine_applied'             => $result['pm_refine_applied']            ?? false,
                'pm_refine_reason'              => $result['pm_refine_reason']             ?? null,
                'pm_refine_peak_roi'            => $result['pm_refine_peak_roi']           ?? null,
                'pm_refine_current_roi'         => $result['pm_refine_current_roi']        ?? null,
                'pm_refine_drawdown_from_peak'  => $result['pm_refine_drawdown_from_peak'] ?? null,
                'pm_refine_pullback_state'      => $result['pm_refine_pullback_state']     ?? null,
                'pm_refine_profit_capture_sync' => $result['pm_refine_profit_capture_sync'] ?? false,
                'updated_at'                           => date('c'),
            ];
            if (!empty($result['cycle_pm_support_applied'])) {
                $pm9Status['cycle_pm_support_applied'] = true;
                $pm9Status['cycle_pm_support_reason']  = $result['cycle_pm_support_reason'] ?? null;
            }
            $this->store->updateSymbolStatus($symbol, $pm9Status);
        }
    }

    // =========================================================================
    // PM-10: Post-entry refinement
    // =========================================================================

    /**
     * Compute PM-10 post-entry policy refinement for a single position.
     *
     * Runs BEFORE processPosition() in runActive(). Determines which refinement
     * branch applies this tick and whether the exchange update should be held.
     * All branches are explicit and observable; safety gates in processStepTrailing
     * remain fully active regardless of what this method returns.
     *
     * Branches:
     *   first_lock_protection       — hold first lock when barely armed (weak continuation)
     *   shallow_pullback_protection — hold tightening during significant pullback from peak
     *   continuation_extension      — observe strong continuation above lock (no-op behavioral)
     *   noop                        — no refinement applicable this tick
     *
     * @param array $prevState        Persisted active state from prior tick (may be empty)
     * @param float $peakRoi          Monotonic peak ROI for this position
     * @param float $currentRoi       Current ROI this tick
     * @param float $activationRoiPct Arm threshold from config
     * @param bool  $trailingArmed    Whether trailing is armed this tick
     * @return array Refinement fields (safe to spread into item result)
     */
    private function computePm10Refinement(
        array $prevState,
        float $peakRoi,
        float $currentRoi,
        float $activationRoiPct,
        bool  $trailingArmed
    ): array {
        $prevLockRoi = (float)($prevState['last_lock_roi'] ?? 0.0);
        $stepRoiPct  = (float)($this->config['step_trailing']['step_roi_pct'] ?? 2.0);
        $lockBuf     = (float)($this->config['step_trailing']['lock_buffer_roi_pct'] ?? 0.25);
        $lockFloor   = (float)($this->config['step_trailing']['lock_floor_roi_pct'] ?? 0.0);

        $pm10Cfg = is_array($this->config['pm10_refinement'] ?? null) ? $this->config['pm10_refinement'] : [];
        $firstLockMinFactor    = (float)($pm10Cfg['first_lock_min_continuation_factor'] ?? 0.5);
        $shallowPullbackFactor = (float)($pm10Cfg['shallow_pullback_threshold_factor']  ?? 0.30);
        $contExtMinHeadroom    = (float)($pm10Cfg['continuation_extension_min_headroom'] ?? 1.0);

        $fields = [
            'refinement_policy_stage'              => 'noop',
            'refinement_reason'                    => null,
            'first_lock_protection_active'         => false,
            'continuation_extension_applied'       => false,
            'shallow_pullback_protection_active'   => false,
            'proposed_lock_roi_before_refinement'  => null,
            'proposed_lock_roi_after_refinement'   => null,
            'refinement_delta_roi'                 => null,
            'pm10_hold'                            => false,
        ];

        if (!$trailingArmed) {
            // Not armed — refinement is not applicable pre-activation
            return $fields;
        }

        // Compute what step trailing would propose as targetLockRoi this tick.
        // Mirrors the formula in processStepTrailing / riskMath->calculateStepTrailingLockRoi.
        $rawTargetLockRoi = null;
        if ($activationRoiPct > 0.0 && $stepRoiPct > 0.0 && $currentRoi >= $activationRoiPct) {
            $steps = (int)floor(($currentRoi - $activationRoiPct) / $stepRoiPct);
            if ($steps === 0) {
                $rawTargetLockRoi = max($lockFloor, 0.0);
            } else {
                $rawTargetLockRoi = max($activationRoiPct + ($steps * $stepRoiPct) - $lockBuf, $lockFloor, 0.0);
            }
        }

        $fields['proposed_lock_roi_before_refinement'] = $rawTargetLockRoi;
        $fields['proposed_lock_roi_after_refinement']  = $rawTargetLockRoi; // default: unchanged

        // --- Branch 1: First-lock protection ---
        // When no lock has been placed yet, require the peak to show at least a minimum
        // continuation above activation before committing to the first lock.
        // Prevents an eager lock the moment trailing arms on a barely-crossed threshold.
        if ($prevLockRoi <= 0.0 && $stepRoiPct > 0.0) {
            $minRequired = $activationRoiPct + ($stepRoiPct * $firstLockMinFactor);
            if ($peakRoi < $minRequired) {
                $fields['refinement_policy_stage']              = 'first_lock_protection';
                $fields['refinement_reason']                    = 'weak_continuation_near_activation';
                $fields['first_lock_protection_active']         = true;
                $fields['pm10_hold']                            = true;
                $fields['proposed_lock_roi_after_refinement']   = 0.0; // held at no-lock
                $fields['refinement_delta_roi']                 = $rawTargetLockRoi !== null
                    ? (0.0 - $rawTargetLockRoi)
                    : null;
                return $fields;
            }
        }

        // --- Branch 2: Shallow pullback protection ---
        // When a significant fraction of the peak ROI has been given back and step trailing
        // would tighten the lock, hold to avoid ratcheting the stop tighter into a pullback.
        // Only applies when a lock is already placed (prevLockRoi > 0) and step would tighten.
        if ($prevLockRoi > 0.0 && $peakRoi > 0.0
            && $rawTargetLockRoi !== null && $rawTargetLockRoi > $prevLockRoi
        ) {
            $pullbackFraction = ($peakRoi - $currentRoi) / $peakRoi;
            if ($pullbackFraction > $shallowPullbackFactor) {
                $fields['refinement_policy_stage']              = 'shallow_pullback_protection';
                $fields['refinement_reason']                    = 'significant_pullback_from_peak';
                $fields['shallow_pullback_protection_active']   = true;
                $fields['pm10_hold']                            = true;
                $fields['proposed_lock_roi_after_refinement']   = $prevLockRoi; // held at current lock
                $fields['refinement_delta_roi']                 = $prevLockRoi - $rawTargetLockRoi;
                return $fields;
            }
        }

        // --- Branch 3: Continuation extension (observational) ---
        // When the position is well above the last lock and price is continuing strongly,
        // record that PM is in a healthy continuation phase. Step trailing handles the
        // actual lock advancement; this branch confirms PM is not over-tightening.
        if ($prevLockRoi > 0.0 && ($currentRoi - $prevLockRoi) >= $contExtMinHeadroom) {
            $fields['refinement_policy_stage']        = 'continuation_extension';
            $fields['refinement_reason']              = 'strong_continuation_above_lock';
            $fields['continuation_extension_applied'] = true;
            $fields['refinement_delta_roi']           = 0.0;
            // pm10_hold remains false — step trailing proceeds normally
        }

        return $fields;
    }

    // =========================================================================
    // PM-11: Bounded adaptive post-entry refinement
    // =========================================================================

    /**
     * Compute PM-11 bounded adaptive refinement for a single position.
     *
     * Runs AFTER computePm10Refinement() in runActive(). Classifies the post-entry
     * regime into one of five explicit adaptive modes and applies a small bounded
     * adjustment. All branches are explicit and observable.
     *
     * Adaptive modes:
     *   weak_continuation    — armed but barely above activation; PM-10 first_lock hold confirmed
     *   steady_continuation  — normal progress; no special adjustment needed
     *   strong_continuation  — well above last lock; allow step trailing to proceed (no PM-10 hold)
     *   shallow_pullback     — significant pullback from peak; PM-10 hold confirmed
     *   flat_carry           — armed with lock but stalled; no adjustment (carry forward)
     *
     * The pm11_hold_override may set or release the pm10_hold flag:
     *   null  — no change (PM-10 decision stands)
     *   true  — force hold (reinforce or extend PM-10 hold)
     *   false — release hold (strong continuation overrides PM-10 continuation_extension noop)
     *
     * @param array $pm10Fields       Output from computePm10Refinement()
     * @param array $prevState        Persisted active state from prior tick (may be empty)
     * @param float $peakRoi          Monotonic peak ROI for this position
     * @param float $currentRoi       Current ROI this tick
     * @param float $activationRoiPct Arm threshold from config
     * @param bool  $trailingArmed    Whether trailing is armed this tick
     * @return array Adaptive fields (spread into itemResult, pm11_hold_override is internal)
     */
    private function computePm11Adaptive(
        array $pm10Fields,
        array $prevState,
        float $peakRoi,
        float $currentRoi,
        float $activationRoiPct,
        bool  $trailingArmed
    ): array {
        $stepRoiPct  = (float)($this->config['step_trailing']['step_roi_pct'] ?? 2.0);
        $pm11Cfg     = is_array($this->config['pm11_adaptive'] ?? null) ? $this->config['pm11_adaptive'] : [];
        $strongFactor   = (float)($pm11Cfg['strong_continuation_headroom_factor'] ?? 2.0);
        $maxExtension   = (float)($pm11Cfg['max_extension_roi']                   ?? 0.5);
        $flatCarryFactor = (float)($pm11Cfg['flat_carry_headroom_factor']         ?? 0.3);

        $prevLockRoi = (float)($prevState['last_lock_roi'] ?? 0.0);

        $out = [
            'adaptive_refinement_mode'   => 'steady_continuation',
            'adaptive_refinement_reason' => 'normal_progress',
            'adaptive_input_regime'      => 'not_armed',
            'adaptive_strength_bucket'   => 'steady',
            'adaptive_action_taken'      => 'noop',
            'adaptive_adjustment_roi'    => 0.0,
            'adaptive_bounds_applied'    => false,
            'pm11_hold_override'         => null,
        ];

        if (!$trailingArmed) {
            // Not armed — adaptive layer is not applicable pre-activation
            return $out;
        }

        // Characterise arm state for observability
        $hasLock   = $prevLockRoi > 0.0;
        $headroom  = $hasLock ? max(0.0, $currentRoi - $prevLockRoi) : 0.0;
        $pm10Stage = $pm10Fields['refinement_policy_stage'] ?? 'noop';

        $out['adaptive_input_regime'] = $hasLock ? 'armed_with_lock' : 'armed_no_lock';

        // --- Mode 1: weak_continuation ---
        // PM-10 first_lock_protection active, OR armed but peak barely above activation.
        if ($pm10Stage === 'first_lock_protection'
            || (!$hasLock && $activationRoiPct > 0.0 && $peakRoi < ($activationRoiPct + $stepRoiPct * 0.5))
        ) {
            $out['adaptive_refinement_mode']   = 'weak_continuation';
            $out['adaptive_refinement_reason'] = 'barely_above_activation_or_first_lock_hold';
            $out['adaptive_strength_bucket']   = 'weak';
            $out['adaptive_action_taken']      = 'hold_confirmed';
            $out['adaptive_adjustment_roi']    = 0.0;
            // Reinforce PM-10 hold (it may already be set; this makes the intent explicit)
            $out['pm11_hold_override']         = true;
            return $out;
        }

        // --- Mode 2: shallow_pullback ---
        // PM-10 shallow_pullback_protection active, OR pullback fraction exceeds threshold.
        $pullbackFraction = ($peakRoi > 0.0) ? (($peakRoi - $currentRoi) / $peakRoi) : 0.0;
        $pullbackThreshold = (float)($this->config['pm10_refinement']['shallow_pullback_threshold_factor'] ?? 0.30);
        if ($pm10Stage === 'shallow_pullback_protection'
            || ($hasLock && $pullbackFraction > $pullbackThreshold)
        ) {
            $out['adaptive_refinement_mode']   = 'shallow_pullback';
            $out['adaptive_refinement_reason'] = 'significant_pullback_from_peak';
            $out['adaptive_strength_bucket']   = 'weak';
            $out['adaptive_action_taken']      = 'hold_confirmed';
            $out['adaptive_adjustment_roi']    = 0.0;
            // Reinforce PM-10 hold
            $out['pm11_hold_override']         = true;
            return $out;
        }

        // --- Mode 3: strong_continuation ---
        // Position is well above last lock and price is continuing (headroom >= factor × step).
        if ($hasLock && $stepRoiPct > 0.0 && $headroom >= ($stepRoiPct * $strongFactor)) {
            $rawExtension = $headroom * 0.1; // observational: 10% of headroom
            $boundsApplied = $rawExtension > $maxExtension;
            $extension     = min($rawExtension, $maxExtension);

            $out['adaptive_refinement_mode']   = 'strong_continuation';
            $out['adaptive_refinement_reason'] = 'headroom_above_strong_threshold';
            $out['adaptive_strength_bucket']   = 'strong';
            $out['adaptive_action_taken']      = 'extension_noted';
            $out['adaptive_adjustment_roi']    = round($extension, 4);
            $out['adaptive_bounds_applied']    = $boundsApplied;
            // Release any PM-10 hold so step trailing proceeds without interference
            $out['pm11_hold_override']         = ($pm10Fields['pm10_hold'] ?? false) ? false : null;
            return $out;
        }

        // --- Mode 4: flat_carry ---
        // Armed with a lock but headroom is negligible (stalled, no material progress this tick).
        if ($hasLock && $stepRoiPct > 0.0 && $headroom < ($stepRoiPct * $flatCarryFactor)) {
            $out['adaptive_refinement_mode']   = 'flat_carry';
            $out['adaptive_refinement_reason'] = 'headroom_below_flat_carry_threshold';
            $out['adaptive_strength_bucket']   = 'weak';
            $out['adaptive_action_taken']      = 'noop';
            $out['adaptive_adjustment_roi']    = 0.0;
            // No hold change — PM-10 decision stands
            return $out;
        }

        // --- Mode 5: steady_continuation ---
        // Normal progress, no special condition.
        $out['adaptive_refinement_mode']   = 'steady_continuation';
        $out['adaptive_refinement_reason'] = 'normal_progress';
        $out['adaptive_strength_bucket']   = 'steady';
        $out['adaptive_action_taken']      = 'noop';
        $out['adaptive_adjustment_roi']    = 0.0;
        return $out;
    }

    // =========================================================================
    // PM-17: Profit capture — drawdown from peak ROI tracking
    // =========================================================================

    /**
     * Compute PM-17 profit capture classification for a single position.
     *
     * Runs AFTER computePm11Adaptive() in runActive(). Uses peak_roi and current_roi
     * (already computed by the runActive loop) to classify the position's drawdown
     * state and decide whether to override the pm10_hold gate.
     *
     * Branches (in priority order):
     *   no_effect           — peak not yet meaningful (below activation + headroom_min)
     *   peak_drawdown       — significant drawdown from peak; override hold → force step trailing
     *   shallow_pullback_hold — small drawdown; allow continuation, do not force
     *   lock_strengthen     — at or near peak; override any hold so lock proceeds immediately
     *   intermediate_giveback — drawdown between shallow and aggressive thresholds; observe only
     *
     * All overrides are additive-only: they clear pm10_hold but never reduce an existing lock.
     * The step-trailing ratchet (isSLImproving) remains fully active and enforces monotonicity.
     *
     * @param float $peakRoi          Monotonic peak ROI for this position
     * @param float $currentRoi       Current ROI this tick
     * @param float $activationRoiPct Arm threshold from config
     * @param bool  $trailingArmed    Whether trailing is armed this tick
     * @param bool  $holdActive       Whether pm10_hold is currently set (from PM-10 or PM-11)
     * @return array PM-17 fields (safe to read from item result)
     */
    private function computePm17ProfitCapture(
        float $peakRoi,
        float $currentRoi,
        float $activationRoiPct,
        bool  $trailingArmed,
        bool  $holdActive
    ): array {
        $pm17Cfg = is_array($this->config['pm17_profit_capture'] ?? null)
            ? $this->config['pm17_profit_capture']
            : [];

        $peakHeadroomMin          = (float)($pm17Cfg['peak_headroom_min']                   ?? 0.5);
        $shallowDrawdownMaxFrac   = (float)($pm17Cfg['shallow_drawdown_max_fraction']        ?? 0.20);
        $lockStrengthenAtPeakMax  = (float)($pm17Cfg['lock_strengthen_at_peak_max_fraction'] ?? 0.05);
        $aggressiveDrawdownFrac   = (float)($pm17Cfg['aggressive_drawdown_fraction']         ?? 0.35);

        $drawdown         = round($peakRoi - $currentRoi, 4);
        $drawdownFraction = ($peakRoi > 0.0) ? round($drawdown / $peakRoi, 4) : 0.0;
        $peakMeaningful   = $trailingArmed && ($peakRoi >= ($activationRoiPct + $peakHeadroomMin));

        $base = [
            'pm17_capture_mode'       => 'no_effect',
            'pm17_capture_reason'     => null,
            'pm17_drawdown'           => $drawdown,
            'pm17_drawdown_fraction'  => $drawdownFraction,
            'pm17_peak_meaningful'    => $peakMeaningful,
            'pm17_override_hold'      => false,
            'pm17_applied'            => false,
        ];

        if (!$peakMeaningful) {
            // Peak has not reached a meaningful level above activation.
            // Do not apply profit capture yet — allow position to grow.
            return $base;
        }

        // --- Branch: peak_drawdown (aggressive giveback) ---
        // drawdownFraction >= aggressive threshold → significant profit is being given back.
        // Override pm10_hold so step trailing can tighten the stop and preserve remaining profit.
        if ($drawdownFraction >= $aggressiveDrawdownFrac) {
            $base['pm17_capture_mode']   = 'peak_drawdown';
            $base['pm17_capture_reason'] = 'pm_profit_capture_peak_drawdown';
            $base['pm17_override_hold']  = $holdActive; // only meaningful when hold was set
            $base['pm17_applied']        = true;        // always applied: drawdown is actively captured
            return $base;
        }

        // --- Branch: lock_strengthen (at or near peak) ---
        // drawdownFraction <= at-peak threshold → position is at or very close to peak.
        // Override any pm10_hold so the step-trailing lock proceeds immediately rather than
        // being deferred by first_lock_protection or PM-11 holds.
        if ($drawdownFraction <= $lockStrengthenAtPeakMax) {
            $base['pm17_capture_mode']   = 'lock_strengthen';
            $base['pm17_capture_reason'] = 'pm_profit_capture_lock_strengthen';
            $base['pm17_override_hold']  = $holdActive;
            $base['pm17_applied']        = true; // always applied: at-peak lock is actively enforced
            return $base;
        }

        // --- Branch: shallow_pullback_hold ---
        // Small drawdown (above at-peak but <= shallow threshold) → allow continuation.
        // The position may recover to new highs; do not force a tighter stop.
        if ($drawdownFraction <= $shallowDrawdownMaxFrac) {
            $base['pm17_capture_mode']   = 'shallow_pullback_hold';
            $base['pm17_capture_reason'] = 'pm_profit_capture_shallow_pullback_hold';
            // pm17_override_hold remains false — allow PM-10/PM-11 hold to stand
            return $base;
        }

        // --- Branch: intermediate_giveback ---
        // Drawdown is between shallow threshold and aggressive threshold.
        // Observe and record; no hold override in this band.
        $base['pm17_capture_mode']   = 'intermediate_giveback';
        $base['pm17_capture_reason'] = 'pm_profit_capture_intermediate_giveback';
        return $base;
    }

    // =========================================================================
    // PM-18 (pm_refine): Peak-drawdown refinement v2
    // =========================================================================

    /**
     * Compute PM-18 (pm_refine) peak-drawdown refinement v2 for a single position.
     *
     * Runs AFTER computePm17ProfitCapture() in runActive(). Uses absolute drawdown
     * (ROI points, not fraction) from peak to classify the position into one of three
     * clear zones and apply a decisive hold override on the real PM action paths.
     *
     * Zones (using absolute drawdown = peak_roi - current_roi):
     *   no_peak      — peak not yet meaningful; skip evaluation entirely
     *   growth       — low drawdown (at or near peak); release any hold, support continuation
     *   shallow_pullback — small drawdown; set hold to avoid premature tightening
     *   protection   — meaningful drawdown; release hold unconditionally so step trailing fires
     *
     * pm_refine_override_hold:
     *   null  — no change (peak not meaningful)
     *   true  — set hold (shallow_pullback zone)
     *   false — release hold (growth or protection zone; decisive for protection)
     *
     * Constraints:
     *   - NEVER loosens stops or reduces existing lock
     *   - NEVER overrides PM caution blocks
     *   - Only affects pm10_hold gate; step trailing ratchet remains fully active
     *   - pm_refine_applied=true for all zones where peak is meaningful
     *
     * @param float $peakRoi          Monotonic peak ROI for this position
     * @param float $currentRoi       Current ROI this tick
     * @param float $activationRoiPct Arm threshold from config
     * @param bool  $trailingArmed    Whether trailing is armed this tick
     * @param bool  $holdActive       Whether pm10_hold is currently set (post PM-17)
     * @return array PM-18 fields (safe to spread into item result)
     */
    private function computePmRefine(
        float $peakRoi,
        float $currentRoi,
        float $activationRoiPct,
        bool  $trailingArmed,
        bool  $holdActive,
        bool  $profitCaptureApplied = false
    ): array {
        $pmRefineCfg = is_array($this->config['pm_refine'] ?? null) ? $this->config['pm_refine'] : [];
        $enabled         = (bool)($pmRefineCfg['pm_refine_enabled']          ?? true);
        $peakHeadroomMin = (float)($pmRefineCfg['peak_headroom_min']         ?? 0.5);
        $shallowMaxAbs   = (float)($pmRefineCfg['shallow_drawdown_max_abs']  ?? 0.30);
        $protectMinAbs   = (float)($pmRefineCfg['protect_drawdown_min_abs']  ?? 1.0);
        $strictness      = (string)($pmRefineCfg['pm_refine_drawdown_strictness'] ?? 'normal');

        // Strict mode: tighten thresholds so protection triggers sooner
        if ($strictness === 'strict') {
            $shallowMaxAbs *= 0.7;
            $protectMinAbs *= 0.7;
        }

        $drawdown = round($peakRoi - $currentRoi, 4);

        $base = [
            'pm_refine_used'                => false,
            'pm_refine_applied'             => false,
            'pm_refine_reason'              => null,
            'pm_refine_peak_roi'            => round($peakRoi, 4),
            'pm_refine_current_roi'         => round($currentRoi, 4),
            'pm_refine_drawdown_from_peak'  => $drawdown,
            'pm_refine_pullback_state'      => 'no_peak',
            'pm_refine_override_hold'       => null,
            'pm_refine_profit_capture_sync' => $profitCaptureApplied,
        ];

        if (!$enabled) {
            return $base;
        }

        // Gate: trailing must be armed for refinement to apply
        if (!$trailingArmed) {
            return $base;
        }

        // Drawdown-first gate: if drawdown is decisive (>= protect threshold), ALWAYS evaluate
        // regardless of peakRoi headroom — this is the primary trigger for reducing no_effect.
        // For other zones (shallow pullback, growth) we still require peak to be meaningful.
        $peakMeaningful  = ($peakRoi >= ($activationRoiPct + $peakHeadroomMin));
        $drawdownDecisive = ($drawdown >= $protectMinAbs);

        if (!$peakMeaningful && !$drawdownDecisive) {
            // Peak not yet meaningful AND drawdown not decisive → no refinement action
            return $base;
        }

        $base['pm_refine_used'] = true;

        // --- Zone: protection (meaningful drawdown) ---
        // Drawdown >= protect_min_abs ROI points — significant profit is being given back.
        // DECISIVE: always release hold so step trailing fires and locks in remaining profit.
        // Fires even when peakMeaningful gate is not met — drawdown alone is sufficient trigger.
        if ($drawdown >= $protectMinAbs) {
            $base['pm_refine_pullback_state'] = 'protection';
            $base['pm_refine_applied']        = true;
            // When profit capture already applied and drawdown is still growing, strengthen further
            if ($profitCaptureApplied) {
                $base['pm_refine_reason']     = 'pm_refine_lock_strengthen';
            } else {
                $base['pm_refine_reason']     = 'pm_refine_peak_drawdown_protect';
            }
            // Release hold unconditionally — step trailing must fire to protect remaining profit
            $base['pm_refine_override_hold']  = false;
            return $base;
        }

        // Below here we only evaluate when peak is meaningful (shallow pullback + growth zones)
        if (!$peakMeaningful) {
            // Drawdown was decisive but already handled above; this branch is unreachable
            return $base;
        }

        // --- Zone: shallow_pullback (small drawdown) ---
        // Drawdown between shallow_max_abs and protect_min_abs — moderate giveback.
        // Hold tightening: the position may recover to new highs; avoid locking in a pullback.
        if ($drawdown > $shallowMaxAbs) {
            $base['pm_refine_pullback_state'] = 'shallow_pullback';
            $base['pm_refine_applied']        = true;
            $base['pm_refine_reason']         = 'pm_refine_shallow_pullback_hold';
            // Set hold to avoid premature tightening during pullback
            $base['pm_refine_override_hold']  = true;
            return $base;
        }

        // --- Zone: growth (at or near peak, low drawdown) ---
        // Drawdown <= shallow_max_abs — position is at or very close to its peak.
        // Support continuation: release any hold so step trailing can lock in gains.
        $base['pm_refine_pullback_state'] = 'growth';
        $base['pm_refine_applied']        = true;
        if ($drawdown <= 0.0) {
            // New peak or exactly at peak — lock strengthen
            $base['pm_refine_reason']      = 'pm_refine_lock_strengthen';
        } else {
            // Just below peak — allow continuation (hold the position open, not the stop)
            $base['pm_refine_reason']      = 'pm_refine_growth_hold';
        }
        // Release hold so step trailing can proceed and lock in gains at or near peak
        $base['pm_refine_override_hold'] = false;
        return $base;
    }


    /**
     * Run shadow trailing pass for all open positions.
     * Does NOT call any exchange API — computes diagnostics only.
     *
     * @param array $positions     Raw exchange positions
     * @param array $botConfig     Bot config (for trailing parameters)
     * @return array Shadow result with per-position diagnostics
     */
    public function runShadow(array $positions, array $botConfig, array $botTrades = []): array
    {
        $items = [];
        $ts    = date('c');
        $nowTs = time();

        // Aggregate counters
        $positionsArmed     = 0;
        $positionsTightened = 0;
        $positionsExitReady = 0;
        $peakRoiSum         = 0.0;
        $currentRoiSum      = 0.0;

        // Comparison aggregate counters
        $comparedTotal              = 0;
        $pmTighterTotal             = 0;
        $pmLooserTotal              = 0;
        $pmSameDirectionTotal       = 0;
        $stopGapDiffAbsSum          = 0.0;
        $lockDiffRoiAbsSum          = 0.0;
        $positiveExtensionCount     = 0;
        $postLockExtensionSum       = 0.0;
        $maxPostLockExtension       = 0.0;

        // Comparison diagnostic counters
        $comparisonUnavailableTotal = 0;
        $unavailableReasonDist      = [];

        // Build bot-trade lookup by canonical "symbol_side" key (normalise buy/sell→long/short)
        $botTradeByKey    = [];
        $botMatchableCount = 0;
        foreach ($botTrades as $bt) {
            $bSym  = (string)($bt['symbol'] ?? '');
            $bSide = strtolower((string)($bt['side'] ?? ''));
            if ($bSide === 'buy')  { $bSide = 'long'; }
            if ($bSide === 'sell') { $bSide = 'short'; }
            if ($bSym !== '' && in_array($bSide, ['long', 'short'], true)) {
                $botTradeByKey[$bSym . '_' . $bSide] = $bt;
                $botMatchableCount++;
            }
        }

        // Shadow trailing config from PM config block (set in trading_bot/config/config.php profit_manager.shadow_trailing)
        $shadowCfg = is_array($this->config['shadow_trailing'] ?? null) ? $this->config['shadow_trailing'] : [];

        // Bot.json execution block as runtime override / fallback source
        $execCfg = is_array($botConfig['execution'] ?? null) ? $botConfig['execution'] : [];

        $activationRoiPct = (float)($shadowCfg['activation_roi_pct'] ?? $execCfg['trailing_activation_roi'] ?? 3.5);
        $firstLockRoiPct  = (float)($shadowCfg['first_lock_roi_pct'] ?? 0.0);
        $stepRoiPct       = (float)($shadowCfg['step_roi_pct'] ?? $execCfg['step_trailing_step_roi_pct'] ?? 2.0);
        $lockBufferRoiPct = (float)($shadowCfg['lock_buffer_roi_pct'] ?? $execCfg['step_trailing_lock_buffer_roi_pct'] ?? 0.5);
        $cooldownSec      = (int)($shadowCfg['cooldown_sec'] ?? $execCfg['step_trailing_cooldown_sec'] ?? 30);
        $minDistancePct   = (float)($shadowCfg['min_distance_to_price_pct'] ?? $execCfg['step_trailing_min_distance_to_price_pct'] ?? 1.0);

        foreach ($positions as $position) {
            $symbol = (string)($position['symbol'] ?? '');
            if ($symbol === '') {
                continue;
            }

            $side = strtolower((string)($position['side'] ?? ''));
            if (!in_array($side, ['buy', 'sell', 'long', 'short'], true)) {
                continue;
            }
            if ($side === 'buy')  { $side = 'long'; }
            if ($side === 'sell') { $side = 'short'; }

            // Position metrics
            $positionIM    = (float)($position['positionIM'] ?? 0);
            $unrealisedPnl = (float)($position['unrealisedPnl'] ?? 0);
            $markPrice     = (float)($position['markPrice'] ?? 0);
            $avgPrice      = (float)($position['avgPrice'] ?? 0);
            $leverage      = (float)($position['leverage'] ?? 1);
            if ($leverage <= 0.0) { $leverage = 1.0; }

            $currentRoi = ($positionIM > 0) ? round(($unrealisedPnl / $positionIM) * 100.0, 4) : 0.0;

            // Determine trade key (stable per position across ticks)
            $tradeKey = $symbol . '_' . $side;
            if (!empty($position['orderId'])) {
                $tradeKey = (string)$position['orderId'];
            } elseif (!empty($position['trade_id'])) {
                $tradeKey = (string)$position['trade_id'];
            }

            // Load persisted shadow state for continuity between ticks
            $prevState = $this->store->loadShadowState($tradeKey);

            // --- Peak ROI: monotonic, only increases ---
            $prevPeakRoi = (float)($prevState['peak_roi'] ?? 0.0);
            $peakRoi     = max($prevPeakRoi, $currentRoi);

            // --- Trailing arm state ---
            $trailingArmed = (bool)($prevState['trailing_armed'] ?? false);
            $lastLockRoi   = (float)($prevState['last_lock_roi'] ?? 0.0);
            $lastMoveTs    = (int)($prevState['last_move_ts'] ?? 0);
            $prevStop      = (float)($prevState['proposed_stop_price'] ?? 0.0);

            // --- Arm check: activation threshold crossed ---
            $justArmed = false;
            if (!$trailingArmed && $activationRoiPct > 0.0 && $peakRoi >= $activationRoiPct) {
                $trailingArmed = true;
                $justArmed     = true;
            }

            // --- Cooldown check ---
            $cooldownActive = false;
            if ($cooldownSec > 0 && $lastMoveTs > 0) {
                $cooldownActive = (($nowTs - $lastMoveTs) < $cooldownSec);
            }

            // --- Compute proposed lock and action ---
            $proposedLockRoi    = $lastLockRoi;
            $proposedStopPrice  = $prevStop;
            $proposedAction     = 'hold';
            $minDistanceBlocked = false;

            if (!$trailingArmed) {
                // Not yet armed — just watching
                $proposedAction = 'hold';

            } elseif ($justArmed && $lastLockRoi <= 0.0) {
                // Arm event: record arm action, set initial soft lock ROI
                // No stop price placed yet on arm — placement deferred to next tighten cycle
                $proposedAction  = 'arm';
                $proposedLockRoi = max($firstLockRoiPct, 0.0);

            } else {
                // Armed: compute target lock from peak ROI using step ratchet
                if ($stepRoiPct > 0.0 && $avgPrice > 0.0 && $peakRoi >= $activationRoiPct) {
                    $steps = (int)floor(($peakRoi - $activationRoiPct) / $stepRoiPct);

                    if ($steps === 0) {
                        // Peak is between activation and first step: use soft first lock
                        $targetLockRoi = max($firstLockRoiPct, 0.0);
                    } else {
                        // Normal step ratchet
                        $targetLockRoi = $activationRoiPct + ($steps * $stepRoiPct) - $lockBufferRoiPct;
                        $targetLockRoi = max($targetLockRoi, $firstLockRoiPct, 0.0);
                    }

                    // Monotonic check: only tighten, never loosen
                    if ($targetLockRoi > $lastLockRoi) {
                        if ($cooldownActive) {
                            // Cooldown blocking — do not move
                            $proposedAction    = 'hold';
                            $proposedLockRoi   = $lastLockRoi;
                        } else {
                            // Compute candidate stop price from lock ROI
                            $roiPerUnit    = $targetLockRoi / 100.0 / $leverage;
                            $candidateStop = ($side === 'long')
                                ? $avgPrice * (1.0 + $roiPerUnit)
                                : $avgPrice * (1.0 - $roiPerUnit);
                            $candidateStop = round($candidateStop, 8);

                            // Min distance to current price check (price %, scaled by leverage)
                            if ($markPrice > 0.0 && $minDistancePct > 0.0) {
                                $distancePct         = abs($markPrice - $candidateStop) / $markPrice * 100.0;
                                $minDistEffective    = $minDistancePct / max(1.0, $leverage);
                                $minDistanceBlocked  = ($distancePct < $minDistEffective);
                            }

                            if ($minDistanceBlocked) {
                                $proposedAction = 'hold';
                            } else {
                                $proposedStopPrice = $candidateStop;
                                $proposedLockRoi   = $targetLockRoi;
                                // Action type: soft = first lock from zero, step = subsequent tighten
                                $proposedAction    = ($lastLockRoi <= 0.0) ? 'tighten_soft' : 'tighten_step';
                            }
                        }
                    }
                    // else: targetLockRoi <= lastLockRoi → monotonic guard, keep hold
                }
            }

            // --- Update persistent counters ---
            $tightenAction = ($proposedAction === 'tighten_soft' || $proposedAction === 'tighten_step');
            $newLastLockRoi = $tightenAction ? $proposedLockRoi : $lastLockRoi;
            $newLastMoveTs  = $tightenAction ? $nowTs : $lastMoveTs;

            // --- Build shadow state ---
            $shadowState = [
                'trade_id'             => $tradeKey,
                'symbol'               => $symbol,
                'side'                 => $side,
                'owner_mode'           => 'profit_manager_shadow',
                'current_roi'          => $currentRoi,
                'peak_roi'             => round($peakRoi, 4),
                'trailing_armed'       => $trailingArmed,
                'proposed_lock_roi'    => round($proposedLockRoi, 4),
                'proposed_stop_price'  => $proposedStopPrice,
                'last_lock_roi'        => round($newLastLockRoi, 4),
                'last_move_ts'         => $newLastMoveTs,
                'proposed_action'      => $proposedAction,
                'cooldown_active'      => $cooldownActive,
                'min_distance_blocked' => $minDistanceBlocked,
                'updated_at'           => $ts,
            ];

            // --- Bot vs PM comparison (when bot trade data is available) ---
            $botKey   = $symbol . '_' . $side;
            $botTrade = $botTradeByKey[$botKey] ?? null;
            if ($botTrade !== null) {
                $botStopPrice = (float)($botTrade['current_effective_stop_price'] ?? 0);
                $botLockRoi   = (float)($botTrade['floor_locked_roi'] ?? $botTrade['step_lock_roi'] ?? 0);

                $stopGapDiffPct     = 0.0;
                $pmMoreConservative = false;
                $pmMoreAggressive   = false;

                // Compare stop distances when both stops are set
                if ($markPrice > 0.0 && $proposedStopPrice > 0.0 && $botStopPrice > 0.0) {
                    $pmDistFromPrice  = abs($markPrice - $proposedStopPrice);
                    $botDistFromPrice = abs($markPrice - $botStopPrice);
                    // Positive = PM stop is further from price (looser); negative = PM is closer (tighter)
                    $stopGapDiffPct = round(($pmDistFromPrice - $botDistFromPrice) / $markPrice * 100.0, 4);
                    if ($side === 'long') {
                        $pmMoreConservative = ($proposedStopPrice > $botStopPrice);
                        $pmMoreAggressive   = ($proposedStopPrice < $botStopPrice);
                    } else {
                        $pmMoreConservative = ($proposedStopPrice < $botStopPrice);
                        $pmMoreAggressive   = ($proposedStopPrice > $botStopPrice);
                    }
                }

                $lockDiffRoi = round($proposedLockRoi - $botLockRoi, 4);

                // Post-lock extension: extra ROI available above PM's proposed lock
                $postLockExtensionRoi = 0.0;
                if ($proposedLockRoi > 0.0 && $currentRoi > $proposedLockRoi) {
                    $postLockExtensionRoi = round($currentRoi - $proposedLockRoi, 4);
                }

                $comparisonEntry = [
                    'trade_id'                    => $tradeKey,
                    'symbol'                      => $symbol,
                    'side'                        => $side,
                    'current_roi'                 => $currentRoi,
                    'peak_roi'                    => round($peakRoi, 4),
                    'bot_effective_stop_price'    => $botStopPrice,
                    'bot_last_lock_roi'           => $botLockRoi,
                    'pm_shadow_proposed_stop'     => $proposedStopPrice,
                    'pm_shadow_proposed_lock_roi' => round($proposedLockRoi, 4),
                    'pm_shadow_proposed_action'   => $proposedAction,
                    'stop_gap_difference_pct'     => $stopGapDiffPct,
                    'lock_difference_roi'         => $lockDiffRoi,
                    'pm_more_conservative'        => $pmMoreConservative,
                    'pm_more_aggressive'          => $pmMoreAggressive,
                    'post_lock_extension_roi'     => $postLockExtensionRoi,
                    'compared_at'                 => $ts,
                ];

                $shadowState['bot_comparison'] = $comparisonEntry;

                // Accumulate comparison counters
                $comparedTotal++;
                if ($pmMoreConservative) {
                    $pmTighterTotal++;
                } elseif ($pmMoreAggressive) {
                    $pmLooserTotal++;
                } else {
                    $pmSameDirectionTotal++;
                }
                $stopGapDiffAbsSum += abs($stopGapDiffPct);
                $lockDiffRoiAbsSum += abs($lockDiffRoi);
                if ($postLockExtensionRoi > 0.0) {
                    $positiveExtensionCount++;
                    $postLockExtensionSum += $postLockExtensionRoi;
                    if ($postLockExtensionRoi > $maxPostLockExtension) {
                        $maxPostLockExtension = $postLockExtensionRoi;
                    }
                }
            } else {
                // No matching bot trade — record reason for diagnostics; do NOT set bot_comparison=null
                $unavailableReason = (count($botTrades) === 0)
                    ? 'no_bot_trades_loaded'
                    : 'no_matching_bot_trade';
                $shadowState['comparison_unavailable_reason'] = $unavailableReason;
                $unavailableReasonDist[$unavailableReason]  = ($unavailableReasonDist[$unavailableReason] ?? 0) + 1;
                $comparisonUnavailableTotal++;
            }

            $this->store->saveShadowState($tradeKey, $shadowState);

            $items[] = $shadowState;

            // Aggregate stats
            $peakRoiSum    += $peakRoi;
            $currentRoiSum += $currentRoi;
            if ($trailingArmed)   { $positionsArmed++; }
            if ($tightenAction)   { $positionsTightened++; }
            if ($proposedAction === 'exit_ready') { $positionsExitReady++; }
        }

        $positionsSeen      = count($positions);
        $positionsProcessed = count($items);
        $avgPeakRoi         = $positionsProcessed > 0 ? round($peakRoiSum    / $positionsProcessed, 4) : 0.0;
        $avgCurrentRoi      = $positionsProcessed > 0 ? round($currentRoiSum / $positionsProcessed, 4) : 0.0;

        // Comparison aggregate metrics (this run)
        $avgStopGapDiffPct       = $comparedTotal > 0 ? round($stopGapDiffAbsSum / $comparedTotal, 4) : null;
        $avgLockDiffRoi          = $comparedTotal > 0 ? round($lockDiffRoiAbsSum / $comparedTotal, 4) : null;
        $avgPostLockExtensionRoi = $positiveExtensionCount > 0 ? round($postLockExtensionSum / $positiveExtensionCount, 4) : null;
        $maxPostLockExtensionRoi = $maxPostLockExtension > 0.0 ? round($maxPostLockExtension, 4) : null;

        $comparisonMetrics = [
            'compared_positions_total'           => $comparedTotal,
            'pm_vs_bot_tighter_total'            => $pmTighterTotal,
            'pm_vs_bot_looser_total'             => $pmLooserTotal,
            'pm_vs_bot_same_direction_total'     => $pmSameDirectionTotal,
            'average_stop_gap_difference_pct'    => $avgStopGapDiffPct,
            'average_lock_difference_roi'        => $avgLockDiffRoi,
            'positions_with_positive_extension'  => $positiveExtensionCount,
            'average_post_lock_extension_roi'    => $avgPostLockExtensionRoi,
            'max_post_lock_extension_roi'        => $maxPostLockExtensionRoi,
        ];

        $journal = [
            'ts'                   => $ts,
            'trailing_owner'       => 'profit_manager_shadow',
            'pm_shadow_active'     => $positionsProcessed > 0,
            'positions_seen'       => $positionsSeen,
            'positions_processed'  => $positionsProcessed,
            'positions_armed'      => $positionsArmed,
            'positions_tightened'  => $positionsTightened,
            'positions_exit_ready' => $positionsExitReady,
            'average_peak_roi'     => $avgPeakRoi,
            'average_current_roi'  => $avgCurrentRoi,
            // Flat comparison fields (top-level for direct access)
            'compared_positions_total'                   => $comparedTotal,
            'pm_vs_bot_tighter_total'                    => $pmTighterTotal,
            'pm_vs_bot_looser_total'                     => $pmLooserTotal,
            'pm_vs_bot_same_direction_total'             => $pmSameDirectionTotal,
            'average_stop_gap_difference_pct'            => $avgStopGapDiffPct,
            'average_lock_difference_roi'                => $avgLockDiffRoi,
            'average_post_lock_extension_roi'            => $avgPostLockExtensionRoi,
            'max_post_lock_extension_roi'                => $maxPostLockExtensionRoi,
            // Nested comparison block (for backward compat)
            'comparison'           => $comparisonMetrics,
            // Comparison diagnostics (per-run)
            'comparison_matches_found_total'             => $comparedTotal,
            'comparison_unavailable_total'               => $comparisonUnavailableTotal,
            'comparison_unavailable_reason_distribution' => $unavailableReasonDist,
            'bot_active_trades_provided'                 => count($botTrades),
            'bot_active_trades_matchable'                => $botMatchableCount,
            'items'                => $items,
        ];

        return $journal;
    }

    /**
     * PM-15: Read coin_cycle_decision_model for a single symbol from passport file.
     *
     * Returns the raw decision model array if available, or null if unavailable.
     * This is a best-effort read — any failure returns null (no exception propagation).
     *
     * @param  string $symbol
     * @return array|null
     */
    private function readCycleDecisionModel(string $symbol): ?array
    {
        $passportsDir = $this->config['_pm_passports_dir'] ?? null;
        if ($passportsDir === null || $symbol === '') {
            return null;
        }
        $path = $passportsDir . '/' . strtoupper($symbol) . '.json';
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $passport = json_decode($raw, true);
        if (!is_array($passport) || !is_array($passport['coin_cycle_decision_model'] ?? null)) {
            return null;
        }
        return $passport['coin_cycle_decision_model'];
    }

    // =========================================================================
    // Shadow Trailing Mode (trailing_owner = profit_manager_shadow)
    // =========================================================================

    /**
     * Run shadow trailing pass for all open positions.
     * Does NOT call any exchange API — computes diagnostics only.
     *
     * @param array $positions     Raw exchange positions
     * @param array $botConfig     Bot config (for trailing parameters)
     * @return array Shadow result with per-position diagnostics
     */
    public function runShadow(array $positions, array $botConfig, array $botTrades = []): array
    {
        $items = [];
        $ts    = date('c');
        $nowTs = time();

        // Aggregate counters
        $positionsArmed     = 0;
        $positionsTightened = 0;
        $positionsExitReady = 0;
        $peakRoiSum         = 0.0;
        $currentRoiSum      = 0.0;

        // Comparison aggregate counters
        $comparedTotal              = 0;
        $pmTighterTotal             = 0;
        $pmLooserTotal              = 0;
        $pmSameDirectionTotal       = 0;
        $stopGapDiffAbsSum          = 0.0;
        $lockDiffRoiAbsSum          = 0.0;
        $positiveExtensionCount     = 0;
        $postLockExtensionSum       = 0.0;
        $maxPostLockExtension       = 0.0;

        // Comparison diagnostic counters
        $comparisonUnavailableTotal = 0;
        $unavailableReasonDist      = [];

        // Build bot-trade lookup by canonical "symbol_side" key (normalise buy/sell→long/short)
        $botTradeByKey    = [];
        $botMatchableCount = 0;
        foreach ($botTrades as $bt) {
            $bSym  = (string)($bt['symbol'] ?? '');
            $bSide = strtolower((string)($bt['side'] ?? ''));
            if ($bSide === 'buy')  { $bSide = 'long'; }
            if ($bSide === 'sell') { $bSide = 'short'; }
            if ($bSym !== '' && in_array($bSide, ['long', 'short'], true)) {
                $botTradeByKey[$bSym . '_' . $bSide] = $bt;
                $botMatchableCount++;
            }
        }

        // Shadow trailing config from PM config block (set in trading_bot/config/config.php profit_manager.shadow_trailing)
        $shadowCfg = is_array($this->config['shadow_trailing'] ?? null) ? $this->config['shadow_trailing'] : [];

        // Bot.json execution block as runtime override / fallback source
        $execCfg = is_array($botConfig['execution'] ?? null) ? $botConfig['execution'] : [];

        $activationRoiPct = (float)($shadowCfg['activation_roi_pct'] ?? $execCfg['trailing_activation_roi'] ?? 3.5);
        $firstLockRoiPct  = (float)($shadowCfg['first_lock_roi_pct'] ?? 0.0);
        $stepRoiPct       = (float)($shadowCfg['step_roi_pct'] ?? $execCfg['step_trailing_step_roi_pct'] ?? 2.0);
        $lockBufferRoiPct = (float)($shadowCfg['lock_buffer_roi_pct'] ?? $execCfg['step_trailing_lock_buffer_roi_pct'] ?? 0.5);
        $cooldownSec      = (int)($shadowCfg['cooldown_sec'] ?? $execCfg['step_trailing_cooldown_sec'] ?? 30);
        $minDistancePct   = (float)($shadowCfg['min_distance_to_price_pct'] ?? $execCfg['step_trailing_min_distance_to_price_pct'] ?? 1.0);

        foreach ($positions as $position) {
            $symbol = (string)($position['symbol'] ?? '');
            if ($symbol === '') {
                continue;
            }

            $side = strtolower((string)($position['side'] ?? ''));
            if (!in_array($side, ['buy', 'sell', 'long', 'short'], true)) {
                continue;
            }
            if ($side === 'buy')  { $side = 'long'; }
            if ($side === 'sell') { $side = 'short'; }

            // Position metrics
            $positionIM    = (float)($position['positionIM'] ?? 0);
            $unrealisedPnl = (float)($position['unrealisedPnl'] ?? 0);
            $markPrice     = (float)($position['markPrice'] ?? 0);
            $avgPrice      = (float)($position['avgPrice'] ?? 0);
            $leverage      = (float)($position['leverage'] ?? 1);
            if ($leverage <= 0.0) { $leverage = 1.0; }

            $currentRoi = ($positionIM > 0) ? round(($unrealisedPnl / $positionIM) * 100.0, 4) : 0.0;

            // Determine trade key (stable per position across ticks)
            $tradeKey = $symbol . '_' . $side;
            if (!empty($position['orderId'])) {
                $tradeKey = (string)$position['orderId'];
            } elseif (!empty($position['trade_id'])) {
                $tradeKey = (string)$position['trade_id'];
            }

            // Load persisted shadow state for continuity between ticks
            $prevState = $this->store->loadShadowState($tradeKey);

            // --- Peak ROI: monotonic, only increases ---
            $prevPeakRoi = (float)($prevState['peak_roi'] ?? 0.0);
            $peakRoi     = max($prevPeakRoi, $currentRoi);

            // --- Trailing arm state ---
            $trailingArmed = (bool)($prevState['trailing_armed'] ?? false);
            $lastLockRoi   = (float)($prevState['last_lock_roi'] ?? 0.0);
            $lastMoveTs    = (int)($prevState['last_move_ts'] ?? 0);
            $prevStop      = (float)($prevState['proposed_stop_price'] ?? 0.0);

            // --- Arm check: activation threshold crossed ---
            $justArmed = false;
            if (!$trailingArmed && $activationRoiPct > 0.0 && $peakRoi >= $activationRoiPct) {
                $trailingArmed = true;
                $justArmed     = true;
            }

            // --- Cooldown check ---
            $cooldownActive = false;
            if ($cooldownSec > 0 && $lastMoveTs > 0) {
                $cooldownActive = (($nowTs - $lastMoveTs) < $cooldownSec);
            }

            // --- Compute proposed lock and action ---
            $proposedLockRoi    = $lastLockRoi;
            $proposedStopPrice  = $prevStop;
            $proposedAction     = 'hold';
            $minDistanceBlocked = false;

            if (!$trailingArmed) {
                // Not yet armed — just watching
                $proposedAction = 'hold';

            } elseif ($justArmed && $lastLockRoi <= 0.0) {
                // Arm event: record arm action, set initial soft lock ROI
                // No stop price placed yet on arm — placement deferred to next tighten cycle
                $proposedAction  = 'arm';
                $proposedLockRoi = max($firstLockRoiPct, 0.0);

            } else {
                // Armed: compute target lock from peak ROI using step ratchet
                if ($stepRoiPct > 0.0 && $avgPrice > 0.0 && $peakRoi >= $activationRoiPct) {
                    $steps = (int)floor(($peakRoi - $activationRoiPct) / $stepRoiPct);

                    if ($steps === 0) {
                        // Peak is between activation and first step: use soft first lock
                        $targetLockRoi = max($firstLockRoiPct, 0.0);
                    } else {
                        // Normal step ratchet
                        $targetLockRoi = $activationRoiPct + ($steps * $stepRoiPct) - $lockBufferRoiPct;
                        $targetLockRoi = max($targetLockRoi, $firstLockRoiPct, 0.0);
                    }

                    // Monotonic check: only tighten, never loosen
                    if ($targetLockRoi > $lastLockRoi) {
                        if ($cooldownActive) {
                            // Cooldown blocking — do not move
                            $proposedAction    = 'hold';
                            $proposedLockRoi   = $lastLockRoi;
                        } else {
                            // Compute candidate stop price from lock ROI
                            $roiPerUnit    = $targetLockRoi / 100.0 / $leverage;
                            $candidateStop = ($side === 'long')
                                ? $avgPrice * (1.0 + $roiPerUnit)
                                : $avgPrice * (1.0 - $roiPerUnit);
                            $candidateStop = round($candidateStop, 8);

                            // Min distance to current price check (price %, scaled by leverage)
                            if ($markPrice > 0.0 && $minDistancePct > 0.0) {
                                $distancePct         = abs($markPrice - $candidateStop) / $markPrice * 100.0;
                                $minDistEffective    = $minDistancePct / max(1.0, $leverage);
                                $minDistanceBlocked  = ($distancePct < $minDistEffective);
                            }

                            if ($minDistanceBlocked) {
                                $proposedAction = 'hold';
                            } else {
                                $proposedStopPrice = $candidateStop;
                                $proposedLockRoi   = $targetLockRoi;
                                // Action type: soft = first lock from zero, step = subsequent tighten
                                $proposedAction    = ($lastLockRoi <= 0.0) ? 'tighten_soft' : 'tighten_step';
                            }
                        }
                    }
                    // else: targetLockRoi <= lastLockRoi → monotonic guard, keep hold
                }
            }

            // --- Update persistent counters ---
            $tightenAction = ($proposedAction === 'tighten_soft' || $proposedAction === 'tighten_step');
            $newLastLockRoi = $tightenAction ? $proposedLockRoi : $lastLockRoi;
            $newLastMoveTs  = $tightenAction ? $nowTs : $lastMoveTs;

            // --- Build shadow state ---
            $shadowState = [
                'trade_id'             => $tradeKey,
                'symbol'               => $symbol,
                'side'                 => $side,
                'owner_mode'           => 'profit_manager_shadow',
                'current_roi'          => $currentRoi,
                'peak_roi'             => round($peakRoi, 4),
                'trailing_armed'       => $trailingArmed,
                'proposed_lock_roi'    => round($proposedLockRoi, 4),
                'proposed_stop_price'  => $proposedStopPrice,
                'last_lock_roi'        => round($newLastLockRoi, 4),
                'last_move_ts'         => $newLastMoveTs,
                'proposed_action'      => $proposedAction,
                'cooldown_active'      => $cooldownActive,
                'min_distance_blocked' => $minDistanceBlocked,
                'updated_at'           => $ts,
            ];

            // --- Bot vs PM comparison (when bot trade data is available) ---
            $botKey   = $symbol . '_' . $side;
            $botTrade = $botTradeByKey[$botKey] ?? null;
            if ($botTrade !== null) {
                $botStopPrice = (float)($botTrade['current_effective_stop_price'] ?? 0);
                $botLockRoi   = (float)($botTrade['floor_locked_roi'] ?? $botTrade['step_lock_roi'] ?? 0);

                $stopGapDiffPct     = 0.0;
                $pmMoreConservative = false;
                $pmMoreAggressive   = false;

                // Compare stop distances when both stops are set
                if ($markPrice > 0.0 && $proposedStopPrice > 0.0 && $botStopPrice > 0.0) {
                    $pmDistFromPrice  = abs($markPrice - $proposedStopPrice);
                    $botDistFromPrice = abs($markPrice - $botStopPrice);
                    // Positive = PM stop is further from price (looser); negative = PM is closer (tighter)
                    $stopGapDiffPct = round(($pmDistFromPrice - $botDistFromPrice) / $markPrice * 100.0, 4);
                    if ($side === 'long') {
                        $pmMoreConservative = ($proposedStopPrice > $botStopPrice);
                        $pmMoreAggressive   = ($proposedStopPrice < $botStopPrice);
                    } else {
                        $pmMoreConservative = ($proposedStopPrice < $botStopPrice);
                        $pmMoreAggressive   = ($proposedStopPrice > $botStopPrice);
                    }
                }

                $lockDiffRoi = round($proposedLockRoi - $botLockRoi, 4);

                // Post-lock extension: extra ROI available above PM's proposed lock
                $postLockExtensionRoi = 0.0;
                if ($proposedLockRoi > 0.0 && $currentRoi > $proposedLockRoi) {
                    $postLockExtensionRoi = round($currentRoi - $proposedLockRoi, 4);
                }

                $comparisonEntry = [
                    'trade_id'                    => $tradeKey,
                    'symbol'                      => $symbol,
                    'side'                        => $side,
                    'current_roi'                 => $currentRoi,
                    'peak_roi'                    => round($peakRoi, 4),
                    'bot_effective_stop_price'    => $botStopPrice,
                    'bot_last_lock_roi'           => $botLockRoi,
                    'pm_shadow_proposed_stop'     => $proposedStopPrice,
                    'pm_shadow_proposed_lock_roi' => round($proposedLockRoi, 4),
                    'pm_shadow_proposed_action'   => $proposedAction,
                    'stop_gap_difference_pct'     => $stopGapDiffPct,
                    'lock_difference_roi'         => $lockDiffRoi,
                    'pm_more_conservative'        => $pmMoreConservative,
                    'pm_more_aggressive'          => $pmMoreAggressive,
                    'post_lock_extension_roi'     => $postLockExtensionRoi,
                    'compared_at'                 => $ts,
                ];

                $shadowState['bot_comparison'] = $comparisonEntry;

                // Accumulate comparison counters
                $comparedTotal++;
                if ($pmMoreConservative) {
                    $pmTighterTotal++;
                } elseif ($pmMoreAggressive) {
                    $pmLooserTotal++;
                } else {
                    $pmSameDirectionTotal++;
                }
                $stopGapDiffAbsSum += abs($stopGapDiffPct);
                $lockDiffRoiAbsSum += abs($lockDiffRoi);
                if ($postLockExtensionRoi > 0.0) {
                    $positiveExtensionCount++;
                    $postLockExtensionSum += $postLockExtensionRoi;
                    if ($postLockExtensionRoi > $maxPostLockExtension) {
                        $maxPostLockExtension = $postLockExtensionRoi;
                    }
                }
            } else {
                // No matching bot trade — record reason for diagnostics; do NOT set bot_comparison=null
                $unavailableReason = (count($botTrades) === 0)
                    ? 'no_bot_trades_loaded'
                    : 'no_matching_bot_trade';
                $shadowState['comparison_unavailable_reason'] = $unavailableReason;
                $unavailableReasonDist[$unavailableReason]  = ($unavailableReasonDist[$unavailableReason] ?? 0) + 1;
                $comparisonUnavailableTotal++;
            }

            $this->store->saveShadowState($tradeKey, $shadowState);

            $items[] = $shadowState;

            // Aggregate stats
            $peakRoiSum    += $peakRoi;
            $currentRoiSum += $currentRoi;
            if ($trailingArmed)   { $positionsArmed++; }
            if ($tightenAction)   { $positionsTightened++; }
            if ($proposedAction === 'exit_ready') { $positionsExitReady++; }
        }

        $positionsSeen      = count($positions);
        $positionsProcessed = count($items);
        $avgPeakRoi         = $positionsProcessed > 0 ? round($peakRoiSum    / $positionsProcessed, 4) : 0.0;
        $avgCurrentRoi      = $positionsProcessed > 0 ? round($currentRoiSum / $positionsProcessed, 4) : 0.0;

        // Comparison aggregate metrics (this run)
        $avgStopGapDiffPct       = $comparedTotal > 0 ? round($stopGapDiffAbsSum / $comparedTotal, 4) : null;
        $avgLockDiffRoi          = $comparedTotal > 0 ? round($lockDiffRoiAbsSum / $comparedTotal, 4) : null;
        $avgPostLockExtensionRoi = $positiveExtensionCount > 0 ? round($postLockExtensionSum / $positiveExtensionCount, 4) : null;
        $maxPostLockExtensionRoi = $maxPostLockExtension > 0.0 ? round($maxPostLockExtension, 4) : null;

        $comparisonMetrics = [
            'compared_positions_total'           => $comparedTotal,
            'pm_vs_bot_tighter_total'            => $pmTighterTotal,
            'pm_vs_bot_looser_total'             => $pmLooserTotal,
            'pm_vs_bot_same_direction_total'     => $pmSameDirectionTotal,
            'average_stop_gap_difference_pct'    => $avgStopGapDiffPct,
            'average_lock_difference_roi'        => $avgLockDiffRoi,
            'positions_with_positive_extension'  => $positiveExtensionCount,
            'average_post_lock_extension_roi'    => $avgPostLockExtensionRoi,
            'max_post_lock_extension_roi'        => $maxPostLockExtensionRoi,
        ];

        $journal = [
            'ts'                   => $ts,
            'trailing_owner'       => 'profit_manager_shadow',
            'pm_shadow_active'     => $positionsProcessed > 0,
            'positions_seen'       => $positionsSeen,
            'positions_processed'  => $positionsProcessed,
            'positions_armed'      => $positionsArmed,
            'positions_tightened'  => $positionsTightened,
            'positions_exit_ready' => $positionsExitReady,
            'average_peak_roi'     => $avgPeakRoi,
            'average_current_roi'  => $avgCurrentRoi,
            // Flat comparison fields (top-level for direct access)
            'compared_positions_total'                   => $comparedTotal,
            'pm_vs_bot_tighter_total'                    => $pmTighterTotal,
            'pm_vs_bot_looser_total'                     => $pmLooserTotal,
            'pm_vs_bot_same_direction_total'             => $pmSameDirectionTotal,
            'average_stop_gap_difference_pct'            => $avgStopGapDiffPct,
            'average_lock_difference_roi'                => $avgLockDiffRoi,
            'average_post_lock_extension_roi'            => $avgPostLockExtensionRoi,
            'max_post_lock_extension_roi'                => $maxPostLockExtensionRoi,
            // Nested comparison block (for backward compat)
            'comparison'           => $comparisonMetrics,
            // Comparison diagnostics (per-run)
            'comparison_matches_found_total'             => $comparedTotal,
            'comparison_unavailable_total'               => $comparisonUnavailableTotal,
            'comparison_unavailable_reason_distribution' => $unavailableReasonDist,
            'bot_active_trades_provided'                 => count($botTrades),
            'bot_active_trades_matchable'                => $botMatchableCount,
            'items'                => $items,
        ];

        return $journal;
    }
}

/* RULES
- Step trailing has priority over dumb trailing
- All calculations use RiskMath
- Anti-spam via StopApplier
- Status updated for each symbol after processing
- Idempotent: repeated runs safe (ratchet only, no rollback)
*/
