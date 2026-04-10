<?php
declare(strict_types=1);

namespace Modules\System\ProfitManager;

use Core\System\SystemPaths;

/**
 * Profit Manager Service
 * 
 * P2 контур: Trailing stop management and profit locking.
 * Does NOT open trades, does NOT set leverage — only manages existing positions.
 * 
 * NO HARDCODE / CONFIG FIRST / SystemPaths ONLY
 */
final class ProfitManagerService
{
    private ?string $moduleBase = null;
    private ?string $storageDir = null;
    private array $config = [];
    private ?string $configError = null;
    private array $errors = [];
    private array $warnings = [];

    /** Diagnostics from last loadBotActiveTrades() call */
    private array $botTradeLoadDiag = ['loaded' => 0, 'matchable' => 0, 'storage_dir' => null];
    
    /** @var Lib\Store */
    private $store;
    
    /** @var Lib\RiskMath */
    private $riskMath;
    
    /** @var Lib\PositionSelector */
    private $selector;
    
    /** @var Lib\Validator */
    private $validator;
    
    /** @var Lib\StopApplier */
    private $applier;
    
    /** @var Lib\ProfitManager */
    private $profitManager;
    
    /** @var ProfitManagerGateway */
    private $gateway;
    
    public function __construct()
    {
        $this->moduleBase = $this->resolveModuleBase();
        
        if ($this->moduleBase === null) {
            $this->configError = 'module_base_unknown';
            return;
        }
        
        $this->storageDir = $this->moduleBase . '/storage';
        $this->config = $this->loadConfig();
        
        // Initialize sub-components
        $this->store = new Lib\Store($this->storageDir, $this->config);
        $this->riskMath = new Lib\RiskMath($this->config);
        $this->selector = new Lib\PositionSelector($this->config);
        $this->validator = new Lib\Validator($this->config);
        
        // Initialize gateway
        $mode = $this->config['module']['mode'] ?? 'dry';
        if ($mode === 'live') {
            $this->initGateway();
        } else {
            // Dry mode: still need gateway for reading positions
            $this->initGateway();
        }
        
        // Initialize stop applier (needs gateway)
        if ($this->gateway !== null) {
            $this->applier = new Lib\StopApplier($this->config, $this->store, $this->riskMath, $this->gateway);
        }
        
        // Initialize main profit manager
        if ($this->gateway !== null && $this->applier !== null) {
            $this->profitManager = new Lib\ProfitManager(
                $this->config,
                $this->store,
                $this->riskMath,
                $this->selector,
                $this->applier,
                $this->validator,
                $this->gateway
            );
        } elseif ($this->gateway !== null) {
            // Shadow mode: PM can operate without stop applier (read-only)
            $this->profitManager = new Lib\ProfitManager(
                $this->config,
                $this->store,
                $this->riskMath,
                $this->selector,
                new Lib\StopApplier($this->config, $this->store, $this->riskMath, $this->gateway),
                $this->validator,
                $this->gateway
            );
        }
    }
    
    /**
     * Main execution entry point
     * 
     * @return array Execution result
     */
    public function execute(): array
    {
        $startTime = microtime(true);
        $ts = date('c');
        $lockFp = null;
        
        // Check for config error
        if ($this->configError !== null) {
            return $this->buildErrorResult($ts, $startTime, $this->configError);
        }
        
        // Check if module is enabled
        if (!($this->config['module']['enabled'] ?? false)) {
            return $this->buildDisabledResult($ts, $startTime);
        }

        // Read trailing_owner from config (exposed via config.php proxy from bot.json)
        $trailingOwner = (string)($this->config['execution']['trailing_owner'] ?? 'bot');

        // Shadow mode: PM computes diagnostics only — no exchange stop updates
        if ($trailingOwner === 'profit_manager_shadow') {
            return $this->executeShadow($ts, $startTime);
        }

        // Active PM mode: PM is sole dynamic trailing writer
        if ($trailingOwner === 'profit_manager') {
            return $this->executeActive($ts, $startTime);
        }

        // Check if profit manager is initialized
        if ($this->profitManager === null) {
            return $this->buildErrorResult($ts, $startTime, 'profit_manager_not_initialized');
        }
        
        // Acquire run lock
        $lockFp = $this->store->acquireRunLock();
        if ($lockFp === false) {
            return $this->buildErrorResult($ts, $startTime, 'run_lock_failed');
        }
        
        try {
            // Run profit manager cycle
            $runResult = $this->profitManager->run();
            
            // Build result
            $result = [
                'ts' => $ts,
                'ok' => empty($runResult['errors']),
                'status' => empty($runResult['errors']) ? 'ok' : 'with_errors',
                'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
                'mode' => $this->config['module']['mode'] ?? 'dry',
                'trailing_owner' => $trailingOwner,
                'selected_mode' => $this->selector->getMode(),
                'positions_total' => $runResult['positions_total'] ?? 0,
                'positions_managed' => $runResult['positions_managed'] ?? 0,
                'step_trailing' => $runResult['stats']['step_trailing'] ?? ['applied' => 0, 'skipped' => 0, 'failed' => 0],
                'dumb_trailing' => $runResult['stats']['dumb_trailing'] ?? ['applied' => 0, 'skipped' => 0, 'failed' => 0],
                'items' => array_slice($runResult['items'] ?? [], 0, 50), // Limit items for storage
                'errors' => $runResult['errors'] ?? [],
                'warnings' => $runResult['warnings'] ?? [],
            ];

            // PM-7: Passive passport write-back (best-effort, non-fatal).
            // In bot mode, use persisted shadow states (from any prior shadow runs) as the
            // primary source so that passport PM blocks are kept up-to-date even when
            // trailing_owner=bot.
            $historicalShadowStates = $this->store->loadAllShadowStates();
            $pmStatsBySymbol = $this->aggregatePmStatsBySymbol($historicalShadowStates);
            $passportDiag = $this->tryWritePassportPmStats($pmStatsBySymbol, $ts);

            $result['passport_write_attempted_total'] = $passportDiag['passport_write_attempted_total'] ?? 0;
            $result['passport_write_success_total']   = $passportDiag['passport_write_success_total']   ?? 0;
            $result['passport_write_skipped_total']   = $passportDiag['passport_write_skipped_total']   ?? 0;
            $result['passport_write_error_total']     = $passportDiag['passport_write_error_total']     ?? 0;
            $result['passport_symbols_updated']       = $passportDiag['passport_symbols_updated']       ?? [];
            
            // Save last run
            $this->store->saveLastRun($result);
            
            return $result;
        } catch (\Throwable $e) {
            $this->store->logError('execute exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return $this->buildErrorResult($ts, $startTime, 'exception: ' . $e->getMessage());
        } finally {
            // Release run lock
            $this->store->releaseRunLock($lockFp);
        }
    }

    /**
     * Execute PM in active trailing mode (trailing_owner = profit_manager).
     * PM is the sole dynamic trailing writer. Bot has already skipped its own trailing.
     * Uses the existing trailing logic with real exchange writes and PM observability fields.
     *
     * @param string $ts        ISO timestamp
     * @param float  $startTime microtime start
     * @return array
     */
    private function executeActive(string $ts, float $startTime): array
    {
        if ($this->profitManager === null) {
            return $this->buildErrorResult($ts, $startTime, 'profit_manager_not_initialized');
        }

        $lockFp = $this->store->acquireRunLock();
        if ($lockFp === false) {
            return $this->buildErrorResult($ts, $startTime, 'run_lock_failed');
        }

        try {
            // Load bot active trades for context matching (best-effort, non-fatal — same as shadow mode)
            $botTrades = $this->loadBotActiveTrades();

            $runResult = $this->profitManager->runActive($botTrades, $this->config);

            $items = $runResult['items'] ?? [];

            // Aggregate PM exchange update observability across all items
            $exchangeAttempted = 0;
            $exchangeOk        = 0;
            $exchangeFailed    = 0;
            $firstFailError    = null;
            $pmAppliedAction   = null;
            $pmAppliedStop     = null;
            $pmAppliedLockRoi  = null;
            foreach ($items as $item) {
                if (!empty($item['exchange_update_attempted'])) {
                    $exchangeAttempted++;
                    if (!empty($item['exchange_update_ok'])) {
                        $exchangeOk++;
                        if ($pmAppliedAction === null) {
                            $pmAppliedAction  = $item['applied_action'] ?? null;
                            $pmAppliedStop    = $item['applied_stop_price'] ?? null;
                            $pmAppliedLockRoi = $item['applied_lock_roi'] ?? null;
                        }
                    } else {
                        $exchangeFailed++;
                        if ($firstFailError === null) {
                            $firstFailError = $item['exchange_update_error'] ?? 'unknown';
                        }
                    }
                }
            }

            // PM-7: Passive passport write-back from active-mode observations (best-effort, non-fatal).
            // Supplement current-run items with persisted shadow states for symbols NOT already
            // in the current run (provides coverage even when a position just closed).
            $allActiveItems = $items;
            $historicalShadowStates = $this->store->loadAllShadowStates();
            if (!empty($historicalShadowStates)) {
                $currentSymbols = [];
                foreach ($items as $ai) {
                    $sym = strtoupper((string)($ai['symbol'] ?? ''));
                    if ($sym !== '') {
                        $currentSymbols[$sym] = true;
                    }
                }
                foreach ($historicalShadowStates as $hs) {
                    $sym = strtoupper((string)($hs['symbol'] ?? ''));
                    if ($sym !== '' && !isset($currentSymbols[$sym])) {
                        $allActiveItems[] = $hs;
                    }
                }
            }
            $pmStatsBySymbol = $this->aggregatePmStatsBySymbol($allActiveItems);
            $passportDiag    = $this->tryWritePassportPmStats($pmStatsBySymbol, $ts);

            // PM-8: Extract active-owner proof counters and build compact journal artifact.
            // The journal mirrors shadow_journal.json in purpose but for active-owner decisions.
            $pm8Counters = $runResult['pm8_counters'] ?? [];
            $pm9Counters = $runResult['pm9_counters'] ?? [];
            $pm10Counters = $runResult['pm10_counters'] ?? [];
            $pm11Counters = $runResult['pm11_counters'] ?? [];
            $activationRoiThreshold = $pm8Counters['activation_roi_threshold'] ?? null;

            // Build compact journal entries (one per managed position).
            // Includes the explicit PM-8 stage progression so runtime can show
            // which stages each position reached (not only final outcome).
            $journalItems = [];
            foreach ($items as $item) {
                $entry = [
                    // Identity
                    'symbol'                                => $item['symbol'] ?? null,
                    'side'                                  => $item['side'] ?? null,
                    'owner_mode'                            => $item['owner_mode'] ?? 'profit_manager',
                    // Position metrics
                    'current_roi'                           => $item['current_roi'] ?? null,
                    'peak_roi'                              => $item['peak_roi'] ?? null,
                    'proposed_lock_roi'                     => $item['proposed_lock_roi'] ?? null,
                    'current_stop_or_lock_reference'        => $item['current_stop_or_lock_reference'] ?? null,
                    'proposed_stop_or_lock_reference'       => $item['proposed_stop_or_lock_reference'] ?? null,
                    // PM-8 eligibility threshold (always present so archive can verify why eligible=0)
                    'activation_roi_threshold'              => $item['activation_roi_threshold'] ?? $activationRoiThreshold,
                    // PM-8 explicit stage progression (ordered list of stages reached this tick)
                    'pm_stages_reached'                     => $item['pm_stages_reached'] ?? [],
                    // Stage flags (derived from pm_stages_reached for quick inspection)
                    'eligible_for_pm_management'            => $item['eligible_for_pm_management'] ?? false,
                    'pm_proposal_computed'                  => $item['pm_proposal_computed'] ?? false,
                    // Final stage and outcome fields
                    'pm_management_stage'                   => $item['pm_management_stage'] ?? 'pm_update_skipped',
                    'lock_improvement_detected'             => $item['lock_improvement_detected'] ?? false,
                    'apply_attempted'                       => $item['apply_attempted'] ?? false,
                    'apply_applied'                         => $item['apply_applied'] ?? false,
                    'skip_reason'                           => $item['skip_reason'] ?? null,
                    'block_reason'                          => $item['block_reason'] ?? null,
                    // Ownership clarity: confirms bot was NOT the competing trailing writer
                    'bot_dynamic_trailing_skipped_by_owner' => $item['bot_dynamic_trailing_skipped_by_owner'] ?? true,
                    // PM-9: Multi-tick stabilization evidence per position
                    'previous_lock_roi'                     => $item['previous_lock_roi'] ?? null,
                    'carried_forward_state'                 => $item['carried_forward_state'] ?? false,
                    'regression_prevented'                  => $item['regression_prevented'] ?? false,
                    'duplicate_apply_prevented'             => $item['duplicate_apply_prevented'] ?? false,
                    'noop_same_lock'                        => $item['noop_same_lock'] ?? false,
                    'no_change_reason'                      => $item['no_change_reason'] ?? null,
                    // PM-10: Post-entry refinement evidence per position
                    'refinement_policy_stage'              => $item['refinement_policy_stage']              ?? null,
                    'refinement_reason'                    => $item['refinement_reason']                    ?? null,
                    'first_lock_protection_active'         => $item['first_lock_protection_active']         ?? false,
                    'continuation_extension_applied'       => $item['continuation_extension_applied']       ?? false,
                    'shallow_pullback_protection_active'   => $item['shallow_pullback_protection_active']   ?? false,
                    'proposed_lock_roi_before_refinement'  => $item['proposed_lock_roi_before_refinement']  ?? null,
                    'proposed_lock_roi_after_refinement'   => $item['proposed_lock_roi_after_refinement']   ?? null,
                    'refinement_delta_roi'                 => $item['refinement_delta_roi']                 ?? null,
                    // PM-11: Adaptive refinement evidence per position
                    'adaptive_refinement_mode'             => $item['adaptive_refinement_mode']             ?? null,
                    'adaptive_refinement_reason'           => $item['adaptive_refinement_reason']           ?? null,
                    'adaptive_input_regime'                => $item['adaptive_input_regime']                ?? null,
                    'adaptive_strength_bucket'             => $item['adaptive_strength_bucket']             ?? null,
                    'adaptive_action_taken'                => $item['adaptive_action_taken']                ?? null,
                    'adaptive_adjustment_roi'              => $item['adaptive_adjustment_roi']              ?? null,
                    'adaptive_bounds_applied'              => $item['adaptive_bounds_applied']              ?? false,
                    // Timestamp
                    'updated_at'                            => $ts,
                ];
                // Ineligibility diagnostics (only present when position is NOT eligible)
                if (isset($item['ineligibility_reason'])) {
                    $entry['ineligibility_reason']     = $item['ineligibility_reason'];
                    $entry['roi_gap_to_activation']    = $item['roi_gap_to_activation'] ?? null;
                    if (isset($item['ineligibility_sub_reason'])) {
                        $entry['ineligibility_sub_reason'] = $item['ineligibility_sub_reason'];
                    }
                }
                $journalItems[] = $entry;
            }

            // PM-12: Passive post-entry evidence capture.
            // Build one compact record per managed item from already-produced observability fields.
            // Records are write-only (NDJSON append). They do NOT affect any PM decision.
            $pm12EvidenceRecords     = [];
            $pm12ThisRunWritten      = 0;
            $pm12ThisRunApply        = 0;
            $pm12ThisRunSkip         = 0;
            $pm12ThisRunNoop         = 0;
            $pm12ThisRunRefinement   = 0;
            $pm12ThisRunAdaptive     = 0;
            $pm12ThisRunSymbols      = [];
            foreach ($items as $item) {
                $evSymbol = (string)($item['symbol'] ?? '');
                if ($evSymbol === '') {
                    continue;
                }
                // apply_attempted=true covers both successful applies and exchange-blocked attempts.
                // This ensures every real PM action path is captured regardless of exchange outcome.
                $evAttempted  = !empty($item['apply_attempted']);
                $evApplied    = !empty($item['apply_applied']);
                $evNoop       = !empty($item['noop_same_lock']);
                $evEventType  = $evAttempted ? 'apply' : ($evNoop ? 'noop' : 'skip');
                $evRefinement = isset($item['refinement_policy_stage'])
                    && $item['refinement_policy_stage'] !== null
                    && $item['refinement_policy_stage'] !== 'no_refinement';
                $evAdaptive   = isset($item['adaptive_refinement_mode'])
                    && $item['adaptive_refinement_mode'] !== null;
                // Deterministic event_id: timestamp + symbol (safe) + microsecond suffix
                $evEventId = sprintf(
                    '%s_%s_%s',
                    date('YmdHis'),
                    preg_replace('/[^a-z0-9]/', '', strtolower($evSymbol)),
                    substr(str_replace('.', '', (string)microtime(true)), -7)
                );
                $pm12EvidenceRecords[] = [
                    'event_id'                              => $evEventId,
                    'event_time'                            => $ts,
                    'event_type'                            => $evEventType,
                    'symbol'                                => $evSymbol,
                    'side'                                  => $item['side'] ?? null,
                    'owner_mode'                            => $item['owner_mode'] ?? 'profit_manager',
                    'armed_state'                           => $item['trailing_armed'] ?? false,
                    'current_roi'                           => $item['current_roi'] ?? null,
                    'peak_roi'                              => $item['peak_roi'] ?? null,
                    'previous_lock_roi'                     => $item['previous_lock_roi'] ?? null,
                    'proposed_lock_roi'                     => $item['proposed_lock_roi'] ?? null,
                    'applied_lock_roi'                      => $item['applied_lock_roi'] ?? null,
                    'pm_management_stage'                   => $item['pm_management_stage'] ?? null,
                    'pm_stages_reached'                     => $item['pm_stages_reached'] ?? [],
                    'refinement_policy_stage'               => $item['refinement_policy_stage'] ?? null,
                    'adaptive_refinement_mode'              => $item['adaptive_refinement_mode'] ?? null,
                    'skip_reason'                           => $item['skip_reason'] ?? null,
                    'block_reason'                          => $item['block_reason'] ?? null,
                    'apply_attempted'                       => $evAttempted,
                    'apply_applied'                         => $evApplied,
                    'no_change_reason'                      => $item['no_change_reason'] ?? null,
                    'bot_dynamic_trailing_skipped_by_owner' => $item['bot_dynamic_trailing_skipped_by_owner'] ?? true,
                    'continuation_after_first_lock'         => $item['continuation_extension_applied'] ?? null,
                    'momentum_after_apply'                  => $evAttempted ? ($item['lock_improvement_detected'] ?? null) : null,
                    'carry_forward_state'                   => $item['carried_forward_state'] ?? null,
                    'shallow_pullback_seen'                 => $item['shallow_pullback_protection_active'] ?? null,
                    'updated_at'                            => $ts,
                ];
                $pm12ThisRunWritten++;
                if ($evAttempted)         { $pm12ThisRunApply++; }
                elseif ($evNoop)          { $pm12ThisRunNoop++; }
                else                      { $pm12ThisRunSkip++; }
                if ($evRefinement)        { $pm12ThisRunRefinement++; }
                if ($evAdaptive)          { $pm12ThisRunAdaptive++; }
                $pm12ThisRunSymbols[$evSymbol] = true;
            }
            // Merge this-run PM-12 counts into cumulative pm12_counters.json (persisted across ticks)
            $prevPm12   = $this->store->loadPm12Counters();
            $pm12Totals = [
                'evidence_records_written_total'      => ((int)($prevPm12['evidence_records_written_total']      ?? 0)) + $pm12ThisRunWritten,
                'evidence_apply_events_total'         => ((int)($prevPm12['evidence_apply_events_total']         ?? 0)) + $pm12ThisRunApply,
                'evidence_skip_events_total'          => ((int)($prevPm12['evidence_skip_events_total']          ?? 0)) + $pm12ThisRunSkip,
                'evidence_noop_events_total'          => ((int)($prevPm12['evidence_noop_events_total']          ?? 0)) + $pm12ThisRunNoop,
                'evidence_refinement_events_total'    => ((int)($prevPm12['evidence_refinement_events_total']    ?? 0)) + $pm12ThisRunRefinement,
                'evidence_adaptive_mode_events_total' => ((int)($prevPm12['evidence_adaptive_mode_events_total'] ?? 0)) + $pm12ThisRunAdaptive,
                'evidence_symbols_observed_total'     => ((int)($prevPm12['evidence_symbols_observed_total']     ?? 0)) + count($pm12ThisRunSymbols),
                'updated_at'                          => $ts,
            ];
            $this->store->savePm12Counters($pm12Totals);
            // Append evidence records to bounded NDJSON file (best-effort, non-fatal)
            if (!empty($pm12EvidenceRecords)) {
                $this->store->appendPostEntryEvidence($pm12EvidenceRecords);
            }
            // Per-symbol evidence linkage: update status with last event id/type and sample count
            foreach ($pm12EvidenceRecords as $evRec) {
                $evSym = (string)($evRec['symbol'] ?? '');
                if ($evSym === '') {
                    continue;
                }
                $existingStatus  = $this->store->getSymbolStatus($evSym) ?? [];
                $existingSamples = (int)($existingStatus['evidence_samples_total'] ?? 0);
                $this->store->updateSymbolStatus($evSym, [
                    'last_evidence_event_id'   => $evRec['event_id'],
                    'last_evidence_event_type' => $evRec['event_type'],
                    'evidence_samples_total'   => $existingSamples + 1,
                ]);
            }

            // Pre-initialise shared variables that PM-13 writes and PM-14 reads.
            // PHP does not have block scope so these survive the try-catch; explicit init guards
            // against accessing unset variables when PM-13 fails before assignment.
            $allEvidenceRecords = [];
            $readModel          = [];

            // PM-13: Build and persist a compact read model from all post-entry evidence.
            // The read model is derived entirely from the existing evidence NDJSON.
            // It does NOT affect any PM decision. Generation is best-effort and non-fatal.
            $pm13Counters = [
                'read_model_symbols_total'       => 0,
                'read_model_records_total'        => 0,
                'read_model_apply_records_total'  => 0,
                'read_model_skip_records_total'   => 0,
                'read_model_noop_records_total'   => 0,
                'read_model_generated_ok'         => 0,
                'read_model_generation_error_total' => 0,
            ];
            try {
                $allEvidenceRecords = $this->store->loadPostEntryEvidence(0);
                if (!empty($allEvidenceRecords)) {
                    $readModel = $this->store->buildPostEntryReadModel($allEvidenceRecords, $ts);
                    $this->store->savePostEntryReadModel($readModel);
                    $pm13Counters['read_model_symbols_total']      = count($readModel['symbols'] ?? []);
                    $pm13Counters['read_model_records_total']      = (int)(($readModel['global']['records_total']  ?? 0));
                    $pm13Counters['read_model_apply_records_total']= (int)(($readModel['global']['apply_records_total'] ?? 0));
                    $pm13Counters['read_model_skip_records_total'] = (int)(($readModel['global']['skip_records_total']  ?? 0));
                    $pm13Counters['read_model_noop_records_total'] = (int)(($readModel['global']['noop_records_total']  ?? 0));
                    $pm13Counters['read_model_generated_ok']       = 1;
                }
            } catch (\Throwable $pm13Ex) {
                $pm13Counters['read_model_generation_error_total'] = 1;
            }
            // Merge this-run PM-13 counters into cumulative pm13_counters.json
            $prevPm13   = $this->store->loadPm13Counters();
            $pm13Totals = [
                'read_model_symbols_total'          => $pm13Counters['read_model_symbols_total'],
                'read_model_records_total'          => $pm13Counters['read_model_records_total'],
                'read_model_apply_records_total'    => $pm13Counters['read_model_apply_records_total'],
                'read_model_skip_records_total'     => $pm13Counters['read_model_skip_records_total'],
                'read_model_noop_records_total'     => $pm13Counters['read_model_noop_records_total'],
                'read_model_generated_ok'           => ((int)($prevPm13['read_model_generated_ok']             ?? 0)) + $pm13Counters['read_model_generated_ok'],
                'read_model_generation_error_total' => ((int)($prevPm13['read_model_generation_error_total']   ?? 0)) + $pm13Counters['read_model_generation_error_total'],
                'updated_at'                        => $ts,
            ];
            $this->store->savePm13Counters($pm13Totals);

            // PM-14: Passive outcome linking — join PM post-entry evidence with real closed positions.
            // Primary sources (in order of preference):
            //   1. Trading Bot trades/closed_trades.json + trades/closed/*.json
            //   2. Smart Brain simulator/closed.json (real executed closed trades)
            // Fallback: persisted read model from a previous tick when current tick has no model.
            // A read model is NOT required: when absent, links are built directly from the closed
            // trade data alone (trade-centric path, confidence='low').
            // This is read-only and observational only. It does NOT affect any PM decision.
            // Generation is best-effort and non-fatal.
            $pm14Counters = [
                'outcome_links_generated_total'        => 0,
                'outcome_links_profitable_total'       => 0,
                'outcome_links_losing_total'           => 0,
                'outcome_links_neutral_total'          => 0,
                'outcome_links_low_confidence_total'   => 0,
                'outcome_links_generation_error_total' => 0,
            ];
            try {
                // Load closed trades from authoritative bot/verdict sources.
                // loadBotClosedTrades() now reads both the bot's own trades/closed/ artifacts
                // and the smart_brain simulator/closed.json (real closed positions).
                $closedTrades = $this->loadBotClosedTrades();

                // If the current tick produced no evidence/read-model (e.g. no active positions
                // in this run), fall back to the persisted read model from a previous tick.
                // An empty read model is also acceptable: buildPostEntryOutcomeLinks() will
                // fall back to a trade-centric linking path using the closed trade data alone.
                $effectiveReadModel = !empty($readModel)
                    ? $readModel
                    : $this->store->loadPostEntryReadModel();

                if (!empty($closedTrades)) {
                    $outcomeLinks = $this->store->buildPostEntryOutcomeLinks(
                        $allEvidenceRecords,   // may be empty; last-event index gracefully degrades
                        $effectiveReadModel,   // may be empty; trade-centric path handles this
                        $closedTrades,
                        $ts
                    );
                    $this->store->savePostEntryOutcomeLinks($outcomeLinks);
                    $pm14Counters['outcome_links_generated_total']      = (int)(($outcomeLinks['global']['linked_records_total']      ?? 0));
                    $pm14Counters['outcome_links_profitable_total']     = (int)(($outcomeLinks['global']['profitable_outcomes_total'] ?? 0));
                    $pm14Counters['outcome_links_losing_total']         = (int)(($outcomeLinks['global']['losing_outcomes_total']     ?? 0));
                    $pm14Counters['outcome_links_neutral_total']        = (int)(($outcomeLinks['global']['neutral_outcomes_total']    ?? 0));
                    $pm14Counters['outcome_links_low_confidence_total'] = (int)(($outcomeLinks['global']['low_confidence_total']      ?? 0));
                }
            } catch (\Throwable $pm14Ex) {
                $pm14Counters['outcome_links_generation_error_total'] = 1;
            }
            // Merge this-run PM-14 counters into cumulative pm14_counters.json.
            // Current-snapshot counters (generated/profitable/losing/neutral/low_confidence) simply
            // reflect the latest file state; only error_total is additive across ticks.
            $prevPm14   = $this->store->loadPm14Counters();
            $pm14Totals = [
                'outcome_links_generated_total'        => $pm14Counters['outcome_links_generated_total'],
                'outcome_links_profitable_total'       => $pm14Counters['outcome_links_profitable_total'],
                'outcome_links_losing_total'           => $pm14Counters['outcome_links_losing_total'],
                'outcome_links_neutral_total'          => $pm14Counters['outcome_links_neutral_total'],
                'outcome_links_low_confidence_total'   => $pm14Counters['outcome_links_low_confidence_total'],
                'outcome_links_generation_error_total' => ((int)($prevPm14['outcome_links_generation_error_total'] ?? 0)) + $pm14Counters['outcome_links_generation_error_total'],
                'updated_at'                           => $ts,
            ];
            $this->store->savePm14Counters($pm14Totals);

            // Compact ineligibility summary: surfaces when all seen positions are below threshold.
            // Lets the archive verify the activation threshold and how far each position is from it.
            $ineligibilitySummary = null;
            $pm8Eligible  = (int)($pm8Counters['active_owner_symbols_eligible_total'] ?? 0);
            $pm8Seen      = (int)($pm8Counters['active_owner_symbols_seen_total']     ?? 0);
            if ($pm8Eligible === 0 && $pm8Seen > 0) {
                $ineligReasonCounts    = [];
                $ineligSubReasonCounts = [];
                $maxPeakRoi = null;
                foreach ($items as $item) {
                    $reason = $item['ineligibility_reason'] ?? null;
                    if ($reason !== null) {
                        $ineligReasonCounts[$reason] = ($ineligReasonCounts[$reason] ?? 0) + 1;
                    }
                    $subReason = $item['ineligibility_sub_reason'] ?? null;
                    if ($subReason !== null) {
                        $ineligSubReasonCounts[$subReason] = ($ineligSubReasonCounts[$subReason] ?? 0) + 1;
                    }
                    $pr = $item['peak_roi'] ?? null;
                    if ($pr !== null && ($maxPeakRoi === null || $pr > $maxPeakRoi)) {
                        $maxPeakRoi = $pr;
                    }
                }
                $ineligibilitySummary = [
                    'activation_roi_threshold'          => $activationRoiThreshold,
                    'max_peak_roi_seen'                 => $maxPeakRoi,
                    'ineligibility_reason_counts'       => $ineligReasonCounts,
                    'all_below_activation'              => isset($ineligReasonCounts['below_activation_roi']),
                ];
                if (!empty($ineligSubReasonCounts)) {
                    $ineligibilitySummary['ineligibility_sub_reason_counts'] = $ineligSubReasonCounts;
                }
            }

            // PM-8 proof counters extracted for reuse below.
            $pm8EligibleTotal  = (int)($pm8Counters['active_owner_symbols_eligible_total']   ?? 0);
            $pm8AttemptedTotal = (int)($pm8Counters['active_owner_apply_attempted_total']    ?? 0);
            $pm8SuccessTotal   = (int)($pm8Counters['active_owner_apply_success_total']      ?? 0);
            $pm8BlockedTotal   = (int)($pm8Counters['active_owner_apply_blocked_total']      ?? 0);
            $pm8SeenTotal      = (int)($pm8Counters['active_owner_symbols_seen_total']       ?? 0);

            // Build compact journal payload.
            $journalPayload = [
                'ts'                                     => $ts,
                'trailing_owner'                         => 'profit_manager',
                'activation_roi_threshold'               => $activationRoiThreshold,
                'positions_seen'                         => $runResult['positions_total'] ?? 0,
                'positions_managed'                      => $runResult['positions_managed'] ?? 0,
                // PM-8 aggregated counters (proof that PM was the sole active trailing owner)
                'active_owner_symbols_seen_total'        => $pm8SeenTotal,
                'active_owner_symbols_eligible_total'    => $pm8EligibleTotal,
                'active_owner_proposals_computed_total'  => $pm8Counters['active_owner_proposals_computed_total']  ?? 0,
                'active_owner_apply_attempted_total'     => $pm8AttemptedTotal,
                'active_owner_apply_success_total'       => $pm8SuccessTotal,
                'active_owner_apply_skipped_total'       => $pm8Counters['active_owner_apply_skipped_total']       ?? 0,
                'active_owner_apply_blocked_total'       => $pm8BlockedTotal,
                // PM-9 this-run stabilization deltas (in journal for per-tick tracing)
                'active_owner_cycles_total'                        => $pm9Counters['active_owner_cycles_total']                        ?? 0,
                'this_run_carried_forward'                         => $pm9Counters['this_run_carried_forward']                         ?? 0,
                'this_run_noop_same_lock'                          => $pm9Counters['this_run_noop_same_lock']                          ?? 0,
                'this_run_regression_prevented'                    => $pm9Counters['this_run_regression_prevented']                    ?? 0,
                'this_run_duplicate_apply_prevented'               => $pm9Counters['this_run_duplicate_apply_prevented']               ?? 0,
                'this_run_missing_cleanup'                         => $pm9Counters['this_run_missing_cleanup']                         ?? 0,
                'this_run_closed_cleanup'                          => $pm9Counters['this_run_closed_cleanup']                          ?? 0,
                // PM-10 refinement counters (cumulative; proves refinement branches were exercised)
                'refinement_first_lock_protection_total'       => $pm10Counters['refinement_first_lock_protection_total']       ?? 0,
                'refinement_continuation_extension_total'      => $pm10Counters['refinement_continuation_extension_total']      ?? 0,
                'refinement_shallow_pullback_protection_total' => $pm10Counters['refinement_shallow_pullback_protection_total'] ?? 0,
                'refinement_noop_total'                        => $pm10Counters['refinement_noop_total']                        ?? 0,
                'refinement_symbols_affected_total'            => $pm10Counters['refinement_symbols_affected_total']            ?? 0,
                // PM-10 this-run refinement deltas
                'this_run_refinement_first_lock_protection'    => $pm10Counters['this_run_first_lock_protection']               ?? 0,
                'this_run_refinement_continuation_extension'   => $pm10Counters['this_run_continuation_extension']              ?? 0,
                'this_run_refinement_shallow_pullback'         => $pm10Counters['this_run_shallow_pullback_protection']         ?? 0,
                'this_run_refinement_symbols_affected'         => $pm10Counters['this_run_symbols_affected']                    ?? 0,
                // PM-11 adaptive counters (cumulative; proves adaptive modes were exercised)
                'adaptive_mode_weak_continuation_total'        => $pm11Counters['adaptive_mode_weak_continuation_total']        ?? 0,
                'adaptive_mode_steady_continuation_total'      => $pm11Counters['adaptive_mode_steady_continuation_total']      ?? 0,
                'adaptive_mode_strong_continuation_total'      => $pm11Counters['adaptive_mode_strong_continuation_total']      ?? 0,
                'adaptive_mode_shallow_pullback_total'         => $pm11Counters['adaptive_mode_shallow_pullback_total']         ?? 0,
                'adaptive_mode_flat_carry_total'               => $pm11Counters['adaptive_mode_flat_carry_total']               ?? 0,
                'adaptive_adjustment_applied_total'            => $pm11Counters['adaptive_adjustment_applied_total']            ?? 0,
                'adaptive_adjustment_noop_total'               => $pm11Counters['adaptive_adjustment_noop_total']               ?? 0,
                'adaptive_bounds_hit_total'                    => $pm11Counters['adaptive_bounds_hit_total']                    ?? 0,
                // PM-11 this-run adaptive deltas
                'this_run_adaptive_weak_continuation'          => $pm11Counters['this_run_weak_continuation']                   ?? 0,
                'this_run_adaptive_steady_continuation'        => $pm11Counters['this_run_steady_continuation']                 ?? 0,
                'this_run_adaptive_strong_continuation'        => $pm11Counters['this_run_strong_continuation']                 ?? 0,
                'this_run_adaptive_shallow_pullback'           => $pm11Counters['this_run_shallow_pullback']                    ?? 0,
                'this_run_adaptive_flat_carry'                 => $pm11Counters['this_run_flat_carry']                          ?? 0,
                // PM-12 passive evidence counters (cumulative; proves write-only capture is active)
                'evidence_records_written_total'               => $pm12Totals['evidence_records_written_total'],
                'evidence_apply_events_total'                  => $pm12Totals['evidence_apply_events_total'],
                'evidence_skip_events_total'                   => $pm12Totals['evidence_skip_events_total'],
                'evidence_noop_events_total'                   => $pm12Totals['evidence_noop_events_total'],
                'evidence_refinement_events_total'             => $pm12Totals['evidence_refinement_events_total'],
                'evidence_adaptive_mode_events_total'          => $pm12Totals['evidence_adaptive_mode_events_total'],
                'evidence_symbols_observed_total'              => $pm12Totals['evidence_symbols_observed_total'],
                'evidence_this_run_written'                    => $pm12ThisRunWritten,
                // PM-13 read model counters (proves read model generation is active)
                'read_model_symbols_total'                     => $pm13Totals['read_model_symbols_total'],
                'read_model_records_total'                     => $pm13Totals['read_model_records_total'],
                'read_model_apply_records_total'               => $pm13Totals['read_model_apply_records_total'],
                'read_model_skip_records_total'                => $pm13Totals['read_model_skip_records_total'],
                'read_model_noop_records_total'                => $pm13Totals['read_model_noop_records_total'],
                'read_model_generated_ok'                      => $pm13Totals['read_model_generated_ok'],
                'read_model_generation_error_total'            => $pm13Totals['read_model_generation_error_total'],
                // PM-14 outcome-link counters (proves passive outcome linkage is active)
                'outcome_links_generated_total'                => $pm14Totals['outcome_links_generated_total'],
                'outcome_links_profitable_total'               => $pm14Totals['outcome_links_profitable_total'],
                'outcome_links_losing_total'                   => $pm14Totals['outcome_links_losing_total'],
                'outcome_links_neutral_total'                  => $pm14Totals['outcome_links_neutral_total'],
                'outcome_links_low_confidence_total'           => $pm14Totals['outcome_links_low_confidence_total'],
                'outcome_links_generation_error_total'         => $pm14Totals['outcome_links_generation_error_total'],
                'items'                                  => array_slice($journalItems, 0, 50),
            ];
            if ($ineligibilitySummary !== null) {
                $journalPayload['active_owner_ineligibility_summary'] = $ineligibilitySummary;
            }

            // Only overwrite active_owner_journal.json when this run has positions.
            // A later empty run (no positions) must not erase meaningful PM-8 proof evidence.
            if ($pm8SeenTotal > 0 || !empty($journalItems)) {
                $this->store->saveActiveOwnerJournal($journalPayload);
            }

            // Durable PM-8 proof artifact: written only when this run has meaningful evidence
            // (eligible > 0, apply_attempted > 0, apply_success > 0, or apply_blocked > 0).
            // Never overwritten by a later empty/no-position tick.
            if ($pm8EligibleTotal > 0 || $pm8AttemptedTotal > 0 || $pm8SuccessTotal > 0 || $pm8BlockedTotal > 0) {
                $proofItems = [];
                foreach ($journalItems as $ji) {
                    if (!empty($ji['eligible_for_pm_management']) || !empty($ji['apply_attempted']) || !empty($ji['apply_applied'])) {
                        $proofItems[] = $ji;
                    }
                }
                // Fallback: if nothing filtered but counters say something happened, include all items.
                if (empty($proofItems) && ($pm8SuccessTotal > 0 || $pm8AttemptedTotal > 0)) {
                    $proofItems = $journalItems;
                }
                $proofPayload = [
                    'ts'                                    => $ts,
                    'trailing_owner'                        => 'profit_manager',
                    'positions_seen'                        => $pm8SeenTotal,
                    'active_owner_symbols_eligible_total'   => $pm8EligibleTotal,
                    'active_owner_proposals_computed_total' => $pm8Counters['active_owner_proposals_computed_total'] ?? 0,
                    'active_owner_apply_attempted_total'    => $pm8AttemptedTotal,
                    'active_owner_apply_success_total'      => $pm8SuccessTotal,
                    'active_owner_apply_skipped_total'      => $pm8Counters['active_owner_apply_skipped_total']     ?? 0,
                    'active_owner_apply_blocked_total'      => $pm8BlockedTotal,
                    // PM-12 evidence counters in proof artifact (proves capture was active during eligible runs)
                    'evidence_records_written_total'        => $pm12Totals['evidence_records_written_total'],
                    'evidence_apply_events_total'           => $pm12Totals['evidence_apply_events_total'],
                    'evidence_skip_events_total'            => $pm12Totals['evidence_skip_events_total'],
                    'evidence_noop_events_total'            => $pm12Totals['evidence_noop_events_total'],
                    // PM-13 read model counters in proof artifact
                    'read_model_symbols_total'              => $pm13Totals['read_model_symbols_total'],
                    'read_model_records_total'              => $pm13Totals['read_model_records_total'],
                    'read_model_apply_records_total'        => $pm13Totals['read_model_apply_records_total'],
                    'read_model_generated_ok'               => $pm13Totals['read_model_generated_ok'],
                    // PM-14 outcome-link counters in proof artifact
                    'outcome_links_generated_total'         => $pm14Totals['outcome_links_generated_total'],
                    'outcome_links_profitable_total'        => $pm14Totals['outcome_links_profitable_total'],
                    'outcome_links_losing_total'            => $pm14Totals['outcome_links_losing_total'],
                    'outcome_links_generation_error_total'  => $pm14Totals['outcome_links_generation_error_total'],
                    'items'                                 => array_slice($proofItems, 0, 50),
                ];
                $this->store->saveLastActiveOwnerProof($proofPayload);
            }

            $result = [
                'ts'                             => $ts,
                'ok'                             => empty($runResult['errors']),
                'status'                         => empty($runResult['errors']) ? 'ok' : 'with_errors',
                'duration_ms'                    => (int)((microtime(true) - $startTime) * 1000),
                'mode'                           => $this->config['module']['mode'] ?? 'dry',
                'trailing_owner'                 => 'profit_manager',
                'trailing_runtime_owner'         => 'profit_manager',
                'pm_active'                      => count($items) > 0,
                'pm_applied_action'              => $pmAppliedAction,
                'pm_applied_stop_price'          => $pmAppliedStop,
                'pm_applied_lock_roi'            => $pmAppliedLockRoi,
                'pm_exchange_update_attempted'   => $exchangeAttempted > 0,
                'pm_exchange_update_ok'          => $exchangeOk > 0,
                'pm_exchange_update_error'       => $firstFailError,
                'selected_mode'                  => $this->selector->getMode(),
                'positions_total'                => $runResult['positions_total'] ?? 0,
                'positions_managed'              => $runResult['positions_managed'] ?? 0,
                'step_trailing'                  => $runResult['stats']['step_trailing'] ?? ['applied' => 0, 'skipped' => 0, 'failed' => 0],
                'dumb_trailing'                  => $runResult['stats']['dumb_trailing'] ?? ['applied' => 0, 'skipped' => 0, 'failed' => 0],
                // Bot trade load diagnostics (same as shadow mode)
                'bot_active_trades_loaded_total'          => $this->botTradeLoadDiag['loaded'] ?? 0,
                'bot_active_trades_matchable_total'       => $this->botTradeLoadDiag['matchable'] ?? 0,
                'bot_active_trades_storage_dir'           => $this->botTradeLoadDiag['storage_dir'] ?? null,
                'bot_active_trades_load_error'            => $this->botTradeLoadDiag['error'] ?? null,
                // PM-7 passport write-back observability
                'passport_write_attempted_total'          => $passportDiag['passport_write_attempted_total'] ?? 0,
                'passport_write_success_total'            => $passportDiag['passport_write_success_total'] ?? 0,
                'passport_write_skipped_total'            => $passportDiag['passport_write_skipped_total'] ?? 0,
                'passport_write_error_total'              => $passportDiag['passport_write_error_total'] ?? 0,
                'passport_symbols_updated'                => $passportDiag['passport_symbols_updated'] ?? [],
                // PM-8 active-owner proof counters
                'active_owner_symbols_seen_total'         => $pm8Counters['active_owner_symbols_seen_total']        ?? 0,
                'active_owner_symbols_eligible_total'     => $pm8Counters['active_owner_symbols_eligible_total']    ?? 0,
                'active_owner_proposals_computed_total'   => $pm8Counters['active_owner_proposals_computed_total']  ?? 0,
                'active_owner_apply_attempted_total'      => $pm8Counters['active_owner_apply_attempted_total']     ?? 0,
                'active_owner_apply_success_total'        => $pm8Counters['active_owner_apply_success_total']       ?? 0,
                'active_owner_apply_skipped_total'        => $pm8Counters['active_owner_apply_skipped_total']       ?? 0,
                'active_owner_apply_blocked_total'        => $pm8Counters['active_owner_apply_blocked_total']       ?? 0,
                // PM-8 activation threshold (for archive verification of eligibility conditions)
                'active_owner_activation_roi_threshold'   => $activationRoiThreshold,
                // PM-9 stabilization counters (cumulative; prove multi-tick ownership stability)
                'active_owner_cycles_total'                        => $pm9Counters['active_owner_cycles_total']                        ?? 0,
                'active_owner_positions_carried_forward_total'     => $pm9Counters['active_owner_positions_carried_forward_total']     ?? 0,
                'active_owner_noop_same_lock_total'                => $pm9Counters['active_owner_noop_same_lock_total']                ?? 0,
                'active_owner_regression_prevented_total'          => $pm9Counters['active_owner_regression_prevented_total']          ?? 0,
                'active_owner_duplicate_apply_prevented_total'     => $pm9Counters['active_owner_duplicate_apply_prevented_total']     ?? 0,
                'active_owner_position_missing_cleanup_total'      => $pm9Counters['active_owner_position_missing_cleanup_total']      ?? 0,
                'active_owner_position_closed_cleanup_total'       => $pm9Counters['active_owner_position_closed_cleanup_total']       ?? 0,
                // PM-10 refinement counters (cumulative; prove refinement branches were exercised)
                'refinement_first_lock_protection_total'       => $pm10Counters['refinement_first_lock_protection_total']       ?? 0,
                'refinement_continuation_extension_total'      => $pm10Counters['refinement_continuation_extension_total']      ?? 0,
                'refinement_shallow_pullback_protection_total' => $pm10Counters['refinement_shallow_pullback_protection_total'] ?? 0,
                'refinement_noop_total'                        => $pm10Counters['refinement_noop_total']                        ?? 0,
                'refinement_symbols_affected_total'            => $pm10Counters['refinement_symbols_affected_total']            ?? 0,
                // PM-11 adaptive counters (cumulative; prove adaptive modes were exercised)
                'adaptive_mode_weak_continuation_total'        => $pm11Counters['adaptive_mode_weak_continuation_total']        ?? 0,
                'adaptive_mode_steady_continuation_total'      => $pm11Counters['adaptive_mode_steady_continuation_total']      ?? 0,
                'adaptive_mode_strong_continuation_total'      => $pm11Counters['adaptive_mode_strong_continuation_total']      ?? 0,
                'adaptive_mode_shallow_pullback_total'         => $pm11Counters['adaptive_mode_shallow_pullback_total']         ?? 0,
                'adaptive_mode_flat_carry_total'               => $pm11Counters['adaptive_mode_flat_carry_total']               ?? 0,
                'adaptive_adjustment_applied_total'            => $pm11Counters['adaptive_adjustment_applied_total']            ?? 0,
                'adaptive_adjustment_noop_total'               => $pm11Counters['adaptive_adjustment_noop_total']               ?? 0,
                'adaptive_bounds_hit_total'                    => $pm11Counters['adaptive_bounds_hit_total']                    ?? 0,
                // PM-12 passive evidence counters (cumulative; appear in last_run.json for archive verification)
                'evidence_records_written_total'               => $pm12Totals['evidence_records_written_total'],
                'evidence_apply_events_total'                  => $pm12Totals['evidence_apply_events_total'],
                'evidence_skip_events_total'                   => $pm12Totals['evidence_skip_events_total'],
                'evidence_noop_events_total'                   => $pm12Totals['evidence_noop_events_total'],
                'evidence_refinement_events_total'             => $pm12Totals['evidence_refinement_events_total'],
                'evidence_adaptive_mode_events_total'          => $pm12Totals['evidence_adaptive_mode_events_total'],
                'evidence_symbols_observed_total'              => $pm12Totals['evidence_symbols_observed_total'],
                // PM-13 read model counters (cumulative; appear in last_run.json for archive verification)
                'read_model_symbols_total'                     => $pm13Totals['read_model_symbols_total'],
                'read_model_records_total'                     => $pm13Totals['read_model_records_total'],
                'read_model_apply_records_total'               => $pm13Totals['read_model_apply_records_total'],
                'read_model_skip_records_total'                => $pm13Totals['read_model_skip_records_total'],
                'read_model_noop_records_total'                => $pm13Totals['read_model_noop_records_total'],
                'read_model_generated_ok'                      => $pm13Totals['read_model_generated_ok'],
                'read_model_generation_error_total'            => $pm13Totals['read_model_generation_error_total'],
                // PM-14 outcome-link counters (cumulative; appear in last_run.json for archive verification)
                'outcome_links_generated_total'                => $pm14Totals['outcome_links_generated_total'],
                'outcome_links_profitable_total'               => $pm14Totals['outcome_links_profitable_total'],
                'outcome_links_losing_total'                   => $pm14Totals['outcome_links_losing_total'],
                'outcome_links_neutral_total'                  => $pm14Totals['outcome_links_neutral_total'],
                'outcome_links_low_confidence_total'           => $pm14Totals['outcome_links_low_confidence_total'],
                'outcome_links_generation_error_total'         => $pm14Totals['outcome_links_generation_error_total'],
                'items'                                   => array_slice($items, 0, 50),
                'errors'                                  => $runResult['errors'] ?? [],
                'warnings'                                => $runResult['warnings'] ?? [],
            ];

            $this->store->saveLastRun($result);

            return $result;
        } catch (\Throwable $e) {
            $this->store->logError('executeActive exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->buildErrorResult($ts, $startTime, 'active_exception: ' . $e->getMessage());
        } finally {
            $this->store->releaseRunLock($lockFp);
        }
    }

    /**
     * Execute shadow trailing pass (trailing_owner = profit_manager_shadow).
     * PM reads open positions, computes shadow state, writes diagnostics only.
     * No exchange stop updates are made.
     *
     * @param string $ts        ISO timestamp
     * @param float  $startTime microtime start
     * @return array
     */
    private function executeShadow(string $ts, float $startTime): array
    {
        $lockFp = $this->store->acquireRunLock();
        if ($lockFp === false) {
            return $this->buildErrorResult($ts, $startTime, 'run_lock_failed');
        }

        try {
            // Fetch open positions (read-only)
            if ($this->gateway === null) {
                return $this->buildErrorResult($ts, $startTime, 'gateway_not_available');
            }

            if ($this->profitManager === null) {
                return $this->buildErrorResult($ts, $startTime, 'profit_manager_not_initialized');
            }

            $positionsResult = $this->gateway->getPositions();
            if (!($positionsResult['ok'] ?? false)) {
                return $this->buildErrorResult($ts, $startTime, 'fetch_positions_failed');
            }

            $positions = $positionsResult['positions'] ?? [];

            // Read bot active trades for comparison (best-effort, non-fatal)
            $botTrades = $this->loadBotActiveTrades();

            // Run shadow trailing compute (no exchange writes)
            $shadowResult = $this->profitManager->runShadow($positions, $this->config, $botTrades);

            // Detect closed trades: shadow keys no longer in active trades → save comparison snapshot
            if (!empty($botTrades)) {
                $this->persistClosedTradeSnapshots($shadowResult['items'] ?? [], $botTrades);
            }

            // Load and update aggregate comparison metrics across runs
            $comparisonThisRun = $shadowResult['comparison'] ?? [];
            $comparisonAgg     = $this->updateAggregateComparisonMetrics($comparisonThisRun);

            // Build runtime observability fields
            $shadowItems = $shadowResult['items'] ?? [];
            $shadowActive = count($shadowItems) > 0;

            // PM-7: Passive passport write-back from shadow observations (best-effort, non-fatal).
            // Supplement current-run items with persisted shadow states for symbols NOT already
            // in the current run (covers recently closed positions that are no longer open).
            $allShadowItems = $shadowItems;
            $historicalShadowStates = $this->store->loadAllShadowStates();
            if (!empty($historicalShadowStates)) {
                $currentSymbols = [];
                foreach ($shadowItems as $si) {
                    $sym = strtoupper((string)($si['symbol'] ?? ''));
                    if ($sym !== '') {
                        $currentSymbols[$sym] = true;
                    }
                }
                foreach ($historicalShadowStates as $hs) {
                    $sym = strtoupper((string)($hs['symbol'] ?? ''));
                    if ($sym !== '' && !isset($currentSymbols[$sym])) {
                        $allShadowItems[] = $hs;
                    }
                }
            }
            $pmStatsBySymbol = $this->aggregatePmStatsBySymbol($allShadowItems);
            $passportDiag    = $this->tryWritePassportPmStats($pmStatsBySymbol, $ts);

            // Pick first active item for flat observability fields (multi-position: all in items[])
            $firstItem = $shadowItems[0] ?? [];

            $result = [
                'ts'                          => $ts,
                'ok'                          => true,
                'status'                      => 'shadow_ok',
                'duration_ms'                 => (int)((microtime(true) - $startTime) * 1000),
                'mode'                        => $this->config['module']['mode'] ?? 'dry',
                'trailing_owner'              => 'profit_manager_shadow',
                'pm_shadow_active'            => $shadowActive,
                'pm_shadow_trade_id'          => $firstItem['trade_id'] ?? null,
                'pm_shadow_peak_roi'          => $firstItem['peak_roi'] ?? null,
                'pm_shadow_proposed_stop'     => $firstItem['proposed_stop_price'] ?? null,
                'pm_shadow_proposed_lock_roi' => $firstItem['proposed_lock_roi'] ?? null,
                'pm_shadow_proposed_action'   => $firstItem['proposed_action'] ?? null,
                'pm_shadow_trailing_armed'    => $firstItem['trailing_armed'] ?? null,
                'pm_shadow_cooldown_active'   => $firstItem['cooldown_active'] ?? null,
                'pm_shadow_min_distance_blocked' => $firstItem['min_distance_blocked'] ?? null,
                'positions_seen'              => $shadowResult['positions_seen'] ?? count($positions),
                'positions_processed'         => $shadowResult['positions_processed'] ?? count($shadowItems),
                'positions_armed'             => $shadowResult['positions_armed'] ?? 0,
                'positions_tightened'         => $shadowResult['positions_tightened'] ?? 0,
                'positions_exit_ready'        => $shadowResult['positions_exit_ready'] ?? 0,
                'average_peak_roi'            => $shadowResult['average_peak_roi'] ?? null,
                'average_current_roi'         => $shadowResult['average_current_roi'] ?? null,
                // Bot trade load diagnostics
                'bot_active_trades_loaded_total'              => $this->botTradeLoadDiag['loaded'] ?? 0,
                'bot_active_trades_matchable_total'           => $this->botTradeLoadDiag['matchable'] ?? 0,
                'bot_active_trades_storage_dir'               => $this->botTradeLoadDiag['storage_dir'] ?? null,
                'bot_active_trades_load_error'                => $this->botTradeLoadDiag['error'] ?? null,
                // Comparison metrics (this run)
                'compared_positions_total'                    => $comparisonThisRun['compared_positions_total'] ?? 0,
                'comparison_matches_found_total'              => $shadowResult['comparison_matches_found_total'] ?? 0,
                'comparison_unavailable_total'                => $shadowResult['comparison_unavailable_total'] ?? 0,
                'comparison_unavailable_reason_distribution'  => $shadowResult['comparison_unavailable_reason_distribution'] ?? [],
                'pm_vs_bot_tighter_total'                     => $comparisonThisRun['pm_vs_bot_tighter_total'] ?? 0,
                'pm_vs_bot_looser_total'                      => $comparisonThisRun['pm_vs_bot_looser_total'] ?? 0,
                'pm_vs_bot_same_direction_total'              => $comparisonThisRun['pm_vs_bot_same_direction_total'] ?? 0,
                'average_stop_gap_difference_pct'             => $comparisonThisRun['average_stop_gap_difference_pct'] ?? null,
                'average_lock_difference_roi'                 => $comparisonThisRun['average_lock_difference_roi'] ?? null,
                'average_post_lock_extension_roi'             => $comparisonThisRun['average_post_lock_extension_roi'] ?? null,
                'max_post_lock_extension_roi'                 => $comparisonThisRun['max_post_lock_extension_roi'] ?? null,
                // Comparison aggregate (across all runs)
                'comparison_aggregate'                        => $comparisonAgg,
                // PM-7 passport write-back observability
                'passport_write_attempted_total'              => $passportDiag['passport_write_attempted_total'] ?? 0,
                'passport_write_success_total'                => $passportDiag['passport_write_success_total'] ?? 0,
                'passport_write_skipped_total'                => $passportDiag['passport_write_skipped_total'] ?? 0,
                'passport_write_error_total'                  => $passportDiag['passport_write_error_total'] ?? 0,
                'passport_symbols_updated'                    => $passportDiag['passport_symbols_updated'] ?? [],
                'items'                       => array_slice($shadowItems, 0, 50),
                'errors'                      => [],
                'warnings'                    => [],
            ];

            $this->store->saveLastRun($result);

            // Build explicit shadow journal payload — do NOT reuse $result to avoid any merge gap.
            // Pull comparison fields directly from $shadowResult (top-level keys in runShadow() return).
            $journalItems = [];
            foreach ($shadowItems as $item) {
                $jItem = [
                    'trade_id'             => $item['trade_id'] ?? null,
                    'symbol'               => $item['symbol'] ?? null,
                    'side'                 => $item['side'] ?? null,
                    'owner_mode'           => $item['owner_mode'] ?? 'profit_manager_shadow',
                    'current_roi'          => $item['current_roi'] ?? null,
                    'peak_roi'             => $item['peak_roi'] ?? null,
                    'trailing_armed'       => $item['trailing_armed'] ?? false,
                    'proposed_lock_roi'    => $item['proposed_lock_roi'] ?? null,
                    'proposed_stop_price'  => $item['proposed_stop_price'] ?? null,
                    'proposed_action'      => $item['proposed_action'] ?? 'hold',
                    'cooldown_active'      => $item['cooldown_active'] ?? false,
                    'min_distance_blocked' => $item['min_distance_blocked'] ?? false,
                    'updated_at'           => $item['updated_at'] ?? $ts,
                ];
                // Always include exactly one comparison status field — never leave both absent
                if (isset($item['bot_comparison'])) {
                    $jItem['bot_comparison'] = $item['bot_comparison'];
                } else {
                    $jItem['comparison_unavailable_reason'] = $item['comparison_unavailable_reason'] ?? 'no_bot_trades_loaded';
                }
                $journalItems[] = $jItem;
            }

            // Explicit authoritative shadow journal — all comparison fields always written even when zero
            $shadowJournal = [
                'ts'                                         => $ts,
                'trailing_owner'                             => 'profit_manager_shadow',
                'pm_shadow_active'                           => count($journalItems) > 0,
                'positions_seen'                             => $shadowResult['positions_seen'] ?? count($positions),
                'positions_processed'                        => $shadowResult['positions_processed'] ?? count($journalItems),
                'positions_armed'                            => $shadowResult['positions_armed'] ?? 0,
                'positions_tightened'                        => $shadowResult['positions_tightened'] ?? 0,
                'positions_exit_ready'                       => $shadowResult['positions_exit_ready'] ?? 0,
                'average_peak_roi'                           => $shadowResult['average_peak_roi'] ?? null,
                'average_current_roi'                        => $shadowResult['average_current_roi'] ?? null,
                'compared_positions_total'                   => $shadowResult['compared_positions_total'] ?? 0,
                'comparison_matches_found_total'             => $shadowResult['comparison_matches_found_total'] ?? 0,
                'comparison_unavailable_total'               => $shadowResult['comparison_unavailable_total'] ?? 0,
                'comparison_unavailable_reason_distribution' => $shadowResult['comparison_unavailable_reason_distribution'] ?? [],
                'pm_vs_bot_tighter_total'                    => $shadowResult['pm_vs_bot_tighter_total'] ?? 0,
                'pm_vs_bot_looser_total'                     => $shadowResult['pm_vs_bot_looser_total'] ?? 0,
                'average_stop_gap_difference_pct'            => $shadowResult['average_stop_gap_difference_pct'] ?? null,
                'average_lock_difference_roi'                => $shadowResult['average_lock_difference_roi'] ?? null,
                'average_post_lock_extension_roi'            => $shadowResult['average_post_lock_extension_roi'] ?? null,
                'max_post_lock_extension_roi'                => $shadowResult['max_post_lock_extension_roi'] ?? null,
                'items'                                      => array_slice($journalItems, 0, 50),
            ];

            // Always overwrite shadow_journal.json — no skip, no stale payload
            $this->store->saveShadowJournal($shadowJournal);

            // Always write shadow_comparison_metrics.json (zero counts are still informative)
            $this->store->saveComparisonMetrics(!empty($comparisonAgg) ? $comparisonAgg : [
                'ts'                                         => $ts,
                'compared_positions_total'                   => $shadowResult['compared_positions_total'] ?? 0,
                'comparison_matches_found_total'             => $shadowResult['comparison_matches_found_total'] ?? 0,
                'comparison_unavailable_total'               => $shadowResult['comparison_unavailable_total'] ?? 0,
                'pm_vs_bot_tighter_total'                    => $shadowResult['pm_vs_bot_tighter_total'] ?? 0,
                'pm_vs_bot_looser_total'                     => $shadowResult['pm_vs_bot_looser_total'] ?? 0,
                'average_stop_gap_difference_pct'            => $shadowResult['average_stop_gap_difference_pct'] ?? null,
                'average_lock_difference_roi'                => $shadowResult['average_lock_difference_roi'] ?? null,
                'average_post_lock_extension_roi'            => $shadowResult['average_post_lock_extension_roi'] ?? null,
                'max_post_lock_extension_roi'                => $shadowResult['max_post_lock_extension_roi'] ?? null,
                'last_updated'                               => $ts,
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->store->logError('shadow execute exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->buildErrorResult($ts, $startTime, 'shadow_exception: ' . $e->getMessage());
        } finally {
            $this->store->releaseRunLock($lockFp);
        }
    }

    /**
     * Load bot active trades from the correct mode-specific storage directory.
     * Best-effort: returns [] on any failure. Populates $this->botTradeLoadDiag.
     *
     * @return array[]
     */
    private function loadBotActiveTrades(): array
    {
        $this->botTradeLoadDiag = ['loaded' => 0, 'matchable' => 0, 'storage_dir' => null];
        try {
            $botStorageDir = $this->resolveBotStorageDir();
            if ($botStorageDir === null) {
                $this->botTradeLoadDiag['error'] = 'bot_storage_dir_not_resolved';
                return [];
            }
            $this->botTradeLoadDiag['storage_dir'] = $botStorageDir;
            $activeDir = $botStorageDir . '/trades/active';
            if (!is_dir($activeDir)) {
                // Directory missing simply means no active trades yet — not a config error
                $this->botTradeLoadDiag['loaded']    = 0;
                $this->botTradeLoadDiag['matchable'] = 0;
                return [];
            }
            $trades = [];
            $matchable = 0;
            foreach (glob($activeDir . '/*.json') ?: [] as $path) {
                $content = @file_get_contents($path);
                if ($content === false || $content === '') {
                    continue;
                }
                $trade = json_decode($content, true);
                if (is_array($trade) && !empty($trade)) {
                    $trades[] = $trade;
                    $sym  = (string)($trade['symbol'] ?? '');
                    $side = strtolower((string)($trade['side'] ?? ''));
                    if ($sym !== '' && in_array($side, ['long', 'short', 'buy', 'sell'], true)) {
                        $matchable++;
                    }
                }
            }
            $this->botTradeLoadDiag['loaded']    = count($trades);
            $this->botTradeLoadDiag['matchable'] = $matchable;
            return $trades;
        } catch (\Throwable $e) {
            $this->botTradeLoadDiag['error'] = $e->getMessage();
            return [];
        }
    }

    /**
     * Load closed trades from the Trading Bot's own closed-trade artifacts and
     * from the Smart Brain simulator closed-trade records (best-effort, non-fatal).
     *
     * Sources in order of preference:
     *   1. {botStorageDir}/trades/closed_trades.json (aggregated snapshot)
     *   2. {botStorageDir}/trades/closed/*.json (individual per-trade files)
     *   3. {smart_brain}/storage/simulator/closed.json (real sim-executed closed trades)
     *
     * Records are normalised to the shape expected by buildPostEntryOutcomeLinks():
     *   - status forced to 'closed'
     *   - closed_at converted to UNIX timestamp (integer) for consistent sorting
     *   - live_trade_id populated from trade_id / signal_id
     *   - _pm14_source set for traceability in source_refs
     *
     * @return array[]
     */
    private function loadBotClosedTrades(): array
    {
        $normalised = [];

        // ── Source 1: Trading Bot own closed-trade files ──────────────────────
        // Reads trades/closed_trades.json (aggregated snapshot written by the bot)
        // and individual trades/closed/*.json files as a fallback.
        try {
            $botStorageDir = $this->resolveBotStorageDir();
            if ($botStorageDir !== null) {
                $raw = [];

                // Primary: aggregated snapshot (written by bot writeRuntimeSnapshot()).
                $aggregatedPath = $botStorageDir . '/trades/closed_trades.json';
                if (is_file($aggregatedPath)) {
                    $content = @file_get_contents($aggregatedPath);
                    if ($content !== false && $content !== '') {
                        $decoded = json_decode($content, true);
                        if (is_array($decoded) && !empty($decoded)) {
                            $raw = $decoded;
                        }
                    }
                }

                // Secondary: individual per-trade files (fallback when snapshot absent).
                if (empty($raw)) {
                    $closedDir = $botStorageDir . '/trades/closed';
                    if (is_dir($closedDir)) {
                        foreach (glob($closedDir . '/*.json') ?: [] as $path) {
                            $content = @file_get_contents($path);
                            if ($content === false || $content === '') {
                                continue;
                            }
                            $trade = json_decode($content, true);
                            if (is_array($trade) && !empty($trade)) {
                                $raw[] = $trade;
                            }
                        }
                    }
                }

                foreach ($raw as $trade) {
                    $sym = (string)($trade['symbol'] ?? '');
                    if ($sym === '') {
                        continue;
                    }
                    if (!isset($trade['closed_at']) && !isset($trade['closed_ts'])) {
                        continue;
                    }
                    if (isset($trade['closed_ts']) && is_numeric($trade['closed_ts'])) {
                        $closedAtTs = (int)$trade['closed_ts'];
                    } elseif (isset($trade['closed_at'])) {
                        $closedAtTs = is_numeric($trade['closed_at'])
                            ? (int)$trade['closed_at']
                            : (int)strtotime((string)$trade['closed_at']);
                    } else {
                        $closedAtTs = 0;
                    }
                    $normalised[] = [
                        'symbol'            => $sym,
                        'side'              => $trade['side'] ?? null,
                        'status'            => 'closed',
                        'roi'               => isset($trade['roi']) && is_numeric($trade['roi'])  ? (float)$trade['roi']  : null,
                        'live_roi'          => null,
                        'close_reason'      => $trade['close_reason_normalized'] ?? $trade['close_reason'] ?? null,
                        'live_close_reason' => null,
                        'closed_at'         => $closedAtTs,
                        'mfe'               => isset($trade['mfe']) && is_numeric($trade['mfe']) ? (float)$trade['mfe']  : null,
                        'virtual_trade_id'  => null,
                        'live_trade_id'     => $trade['trade_id'] ?? $trade['signal_id'] ?? null,
                        '_pm14_source'      => 'bot_closed_trades',
                    ];
                }
            }
        } catch (\Throwable $e) {
            // non-fatal; continue to next source
        }

        // ── Source 2: Smart Brain simulator closed trades ─────────────────────
        // The simulator runs signal decisions through a paper-trade loop and writes
        // real closed positions to storage/simulator/closed.json.  These are
        // authoritative bot-verdict closed-trade artifacts — not ai_shadow data.
        try {
            if ($this->moduleBase !== null) {
                $simPath = dirname($this->moduleBase) . '/smart_brain/storage/simulator/closed.json';
                if (is_file($simPath)) {
                    $content = @file_get_contents($simPath);
                    if ($content !== false && $content !== '') {
                        $decoded = json_decode($content, true);
                        if (is_array($decoded)) {
                            foreach ($decoded as $trade) {
                                if (!is_array($trade) || ($trade['status'] ?? '') !== 'closed') {
                                    continue;
                                }
                                $sym = (string)($trade['symbol'] ?? '');
                                if ($sym === '') {
                                    continue;
                                }
                                // Resolve closed_at: simulator writes ISO datetime strings.
                                $closedAtRaw = $trade['closed_at'] ?? null;
                                if ($closedAtRaw === null) {
                                    continue;
                                }
                                $closedAtTs = is_numeric($closedAtRaw)
                                    ? (int)$closedAtRaw
                                    : (int)strtotime((string)$closedAtRaw);
                                if ($closedAtTs <= 0) {
                                    continue;
                                }
                                $normalised[] = [
                                    'symbol'            => $sym,
                                    'side'              => $trade['side'] ?? null,
                                    'status'            => 'closed',
                                    'roi'               => isset($trade['roi']) && is_numeric($trade['roi'])  ? (float)$trade['roi']  : null,
                                    'live_roi'          => null,
                                    'close_reason'      => $trade['reason'] ?? null,
                                    'live_close_reason' => null,
                                    'closed_at'         => $closedAtTs,
                                    'mfe'               => isset($trade['mfe']) && is_numeric($trade['mfe']) ? (float)$trade['mfe']  : null,
                                    'virtual_trade_id'  => null,
                                    'live_trade_id'     => null,
                                    '_pm14_source'      => 'simulator',
                                ];
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // non-fatal
        }

        return $normalised;
    }

    /**
     * Resolve the correct mode-specific bot storage directory.
     * Reads bot.json to determine mode (live/demo/paper) and picks the matching
     * storage_live / storage_demo / storage_paper subdirectory.
     * Falls back through all known suffixes and then the legacy 'storage' dir.
     *
     * @return string|null
     */
    private function resolveBotStorageDir(): ?string
    {
        $candidates = ['system.trading_bot', 'trading.trading_bot', 'modules.trading_bot'];
        $paths = SystemPaths::instance();
        foreach ($candidates as $key) {
            try {
                $p = $paths->get($key);
                if (!is_string($p) || $p === '' || !is_dir($p)) {
                    continue;
                }
                $base    = rtrim($p, '/');
                $botMode = $this->readBotMode($base);
                $ordered = $this->botStorageSuffixOrder($botMode);

                // Prefer a dir that already has the active trades subdir
                foreach ($ordered as $suffix) {
                    $sd = $base . '/' . $suffix;
                    if (is_dir($sd . '/trades/active')) {
                        return $sd;
                    }
                }
                // Fallback: any existing storage dir
                foreach ($ordered as $suffix) {
                    $sd = $base . '/' . $suffix;
                    if (is_dir($sd)) {
                        return $sd;
                    }
                }
            } catch (\Throwable $e) {
                // try next candidate
            }
        }

        // Sibling-path fallback: derive bot base from PM module base directory.
        // Reliable when SystemPaths candidates are not registered (e.g. lightweight cron context).
        if ($this->moduleBase !== null) {
            try {
                $base = dirname($this->moduleBase) . '/trading_bot';
                if (is_dir($base)) {
                    $botMode = $this->readBotMode($base);
                    $ordered = $this->botStorageSuffixOrder($botMode);
                    // Prefer a dir that already has the active trades subdir
                    foreach ($ordered as $suffix) {
                        $sd = $base . '/' . $suffix;
                        if (is_dir($sd . '/trades/active')) {
                            return $sd;
                        }
                    }
                    // Fallback: any existing storage dir
                    foreach ($ordered as $suffix) {
                        $sd = $base . '/' . $suffix;
                        if (is_dir($sd)) {
                            return $sd;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // non-fatal
            }
        }

        return null;
    }

    /**
     * Read the bot module.mode from bot.json (best-effort, defaults to 'demo').
     *
     * @param string $botBase Absolute path to trading_bot module root
     * @return string e.g. 'demo', 'live', 'paper'
     */
    private function readBotMode(string $botBase): string
    {
        try {
            $path = $botBase . '/config/bot.json';
            if (!is_file($path)) {
                return 'demo';
            }
            $json = @file_get_contents($path);
            if ($json === false || $json === '') {
                return 'demo';
            }
            $data = json_decode($json, true);
            if (!is_array($data)) {
                return 'demo';
            }
            $mode = (string)($data['module']['mode'] ?? $data['mode'] ?? 'demo');
            return $mode !== '' ? $mode : 'demo';
        } catch (\Throwable $e) {
            return 'demo';
        }
    }

    /**
     * Return an ordered list of storage directory suffixes to try for a given bot mode.
     * Mode-specific suffix is always first; legacy 'storage' is last fallback.
     *
     * @param string $botMode
     * @return string[]
     */
    private function botStorageSuffixOrder(string $botMode): array
    {
        $modeMap = ['live' => 'storage_live', 'demo' => 'storage_demo', 'paper' => 'storage_paper'];
        $preferred = $modeMap[$botMode] ?? 'storage_demo';
        // Build ordered unique list: preferred first, then the rest, then legacy
        $all = [$preferred];
        foreach ($modeMap as $suffix) {
            if ($suffix !== $preferred) {
                $all[] = $suffix;
            }
        }
        $all[] = 'storage';
        return $all;
    }

    /**
     * Detect trades that have shadow state but are no longer in bot active trades,
     * and persist a closed-trade comparison snapshot (lightweight, best-effort).
     *
     * @param array[] $shadowItems  Items returned by runShadow (still-open positions)
     * @param array[] $botTrades    Current bot active trades
     */
    private function persistClosedTradeSnapshots(array $shadowItems, array $botTrades): void
    {
        try {
            // Build set of active shadow trade keys from this run
            $activeShadowKeys = [];
            foreach ($shadowItems as $item) {
                $key = (string)($item['trade_id'] ?? '');
                if ($key !== '') {
                    $activeShadowKeys[$key] = true;
                }
            }

            // Load all existing shadow state keys from store
            $allShadowKeys = $this->store->loadShadowStateKeys();

            // Build set of current bot trade keys by trade_id
            $activeBotTradeIds = [];
            foreach ($botTrades as $bt) {
                $tid = (string)($bt['trade_id'] ?? $bt['id'] ?? '');
                if ($tid !== '') {
                    $activeBotTradeIds[$tid] = true;
                }
            }

            // Keys with shadow state that are no longer in active shadow items → potential closes
            foreach ($allShadowKeys as $key) {
                if (isset($activeShadowKeys[$key])) {
                    continue; // still active
                }
                // Check if this key is also gone from bot active trades
                if (isset($activeBotTradeIds[$key])) {
                    continue; // still in bot active — just not in PM positions (exchange position may be zero)
                }

                // Load last shadow state for this key
                $prevState = $this->store->loadShadowState($key);
                if (empty($prevState)) {
                    // No shadow state found — save a skip record so the reason is explicit
                    $this->store->saveComparisonSnapshot($key, [
                        'trade_id'             => $key,
                        'snapshot_skip_reason' => 'no_shadow_state_found',
                        'snapshot_ts'          => date('c'),
                    ]);
                    continue;
                }

                // Avoid duplicate snapshots: skip if snapshot already exists
                $existingSnapshot = $this->store->loadComparisonSnapshot($key);
                if (!empty($existingSnapshot)) {
                    continue;
                }

                // Build lightweight comparison snapshot
                $snapshot = [
                    'trade_id'                    => $key,
                    'symbol'                      => $prevState['symbol'] ?? null,
                    'side'                        => $prevState['side'] ?? null,
                    'close_reason'                => 'detected_closed',
                    'close_roi'                   => null,
                    'peak_roi_seen'               => $prevState['peak_roi'] ?? null,
                    'pm_shadow_last_proposed_stop'     => $prevState['proposed_stop_price'] ?? null,
                    'pm_shadow_last_proposed_lock_roi' => $prevState['last_lock_roi'] ?? null,
                    'pm_shadow_last_action'            => $prevState['proposed_action'] ?? null,
                    'bot_effective_stop_price'         => ($prevState['bot_comparison']['bot_effective_stop_price'] ?? null),
                    'estimated_early_close_damage_roi' => null,
                    'estimated_runner_extension_roi'   => $prevState['bot_comparison']['post_lock_extension_roi'] ?? null,
                    'snapshot_ts'                 => date('c'),
                ];

                $this->store->saveComparisonSnapshot($key, $snapshot);
            }
        } catch (\Throwable $e) {
            // Non-fatal: snapshot persistence must never break shadow execution
        }
    }

    /**
     * Load, merge, and save aggregate comparison metrics across runs.
     *
     * @param array $thisRun  Comparison metrics from the current run
     * @return array          Updated aggregate metrics
     */
    private function updateAggregateComparisonMetrics(array $thisRun): array
    {
        try {
            $agg = $this->store->loadComparisonMetrics();

            $prevTotal   = (int)($agg['compared_positions_total'] ?? 0);
            $thisTotal   = (int)($thisRun['compared_positions_total'] ?? 0);
            $newTotal    = $prevTotal + $thisTotal;

            $agg['compared_positions_total']       = $newTotal;
            $agg['pm_vs_bot_tighter_total']        = ((int)($agg['pm_vs_bot_tighter_total'] ?? 0)) + ((int)($thisRun['pm_vs_bot_tighter_total'] ?? 0));
            $agg['pm_vs_bot_looser_total']         = ((int)($agg['pm_vs_bot_looser_total'] ?? 0)) + ((int)($thisRun['pm_vs_bot_looser_total'] ?? 0));
            $agg['pm_vs_bot_same_direction_total'] = ((int)($agg['pm_vs_bot_same_direction_total'] ?? 0)) + ((int)($thisRun['pm_vs_bot_same_direction_total'] ?? 0));

            // Running averages: recompute from accumulated sum (store sum + count)
            if ($thisTotal > 0) {
                $prevStopGapSum  = (float)($agg['_stop_gap_sum'] ?? 0.0);
                $prevLockDiffSum = (float)($agg['_lock_diff_sum'] ?? 0.0);

                $thisStopGapAvg  = (float)($thisRun['average_stop_gap_difference_pct'] ?? 0.0);
                $thisLockDiffAvg = (float)($thisRun['average_lock_difference_roi'] ?? 0.0);
                $thisStopGapSum  = $thisStopGapAvg * $thisTotal;
                $thisLockDiffSum = $thisLockDiffAvg * $thisTotal;

                $newStopGapSum  = $prevStopGapSum + $thisStopGapSum;
                $newLockDiffSum = $prevLockDiffSum + $thisLockDiffSum;

                $agg['_stop_gap_sum']  = $newStopGapSum;
                $agg['_lock_diff_sum'] = $newLockDiffSum;
                $agg['average_stop_gap_difference_pct'] = $newTotal > 0 ? round($newStopGapSum / $newTotal, 4) : null;
                $agg['average_lock_difference_roi']     = $newTotal > 0 ? round($newLockDiffSum / $newTotal, 4) : null;
            }

            // Post-lock extension aggregate
            $thisExtCount = (int)($thisRun['positions_with_positive_extension'] ?? 0);
            $prevExtCount = (int)($agg['positions_with_positive_extension_total'] ?? 0);
            $newExtCount  = $prevExtCount + $thisExtCount;
            $agg['positions_with_positive_extension_total'] = $newExtCount;

            if ($thisExtCount > 0) {
                $prevExtSum   = (float)($agg['_post_lock_extension_sum'] ?? 0.0);
                $thisExtAvg   = (float)($thisRun['average_post_lock_extension_roi'] ?? 0.0);
                $thisExtSum   = $thisExtAvg * $thisExtCount;
                $newExtSum    = $prevExtSum + $thisExtSum;
                $agg['_post_lock_extension_sum']     = $newExtSum;
                $agg['average_post_lock_extension_roi'] = $newExtCount > 0 ? round($newExtSum / $newExtCount, 4) : null;

                $thisMax = (float)($thisRun['max_post_lock_extension_roi'] ?? 0.0);
                $prevMax = (float)($agg['max_post_lock_extension_roi'] ?? 0.0);
                if ($thisMax > $prevMax) {
                    $agg['max_post_lock_extension_roi'] = round($thisMax, 4);
                }
            }

            $agg['last_updated'] = date('c');

            $this->store->saveComparisonMetrics($agg);

            return $agg;
        } catch (\Throwable $e) {
            return [];
        }
    }
    
    /**
     * Get last run result
     * 
     * @return array Last run data
     */
    public function getLastRun(): array
    {
        if ($this->store === null) {
            return [];
        }
        return $this->store->loadLastRun();
    }
    
    /**
     * Get status for all symbols
     * 
     * @return array Status data
     */
    public function getStatus(): array
    {
        if ($this->store === null) {
            return [];
        }
        return $this->store->loadStatus();
    }
    
    /**
     * Get applied events (ring buffer)
     * 
     * @param int $limit Max events to return
     * @return array Applied events
     */
    public function getAppliedEvents(int $limit = 100): array
    {
        if ($this->store === null) {
            return [];
        }
        $events = $this->store->loadAppliedIndex();
        return array_slice($events, -$limit);
    }
    
    // =========================================================================
    // Private Methods
    // =========================================================================

    // =========================================================================
    // PM-7: Passive passport write-back helpers
    // =========================================================================

    /**
     * Aggregate PM item observations per symbol into compact summary stats.
     *
     * Works on both shadow items (proposed_action, bot_comparison) and active items
     * (applied_action, active_context_unavailable_reason). No exchange writes.
     *
     * @param array[] $items  Items from runShadow() or runActive()
     * @return array<string,array>  Keyed by upper-case symbol
     */
    private function aggregatePmStatsBySymbol(array $items): array
    {
        $bySymbol = [];
        foreach ($items as $item) {
            $sym = strtoupper((string)($item['symbol'] ?? ''));
            if ($sym === '') {
                continue;
            }
            if (!isset($bySymbol[$sym])) {
                $bySymbol[$sym] = [
                    '_current_roi_sum'       => 0.0,
                    '_peak_roi_sum'          => 0.0,
                    '_lock_roi_sum'          => 0.0,
                    '_lock_roi_count'        => 0,
                    '_stop_gap_sum'          => 0.0,
                    '_stop_gap_count'        => 0,
                    '_lock_diff_sum'         => 0.0,
                    '_lock_diff_count'       => 0,
                    '_post_lock_ext_sum'     => 0.0,
                    '_post_lock_ext_count'   => 0,
                    'samples_total'                  => 0,
                    'samples_profitable'             => 0,
                    'samples_armed'                  => 0,
                    'samples_tightened'              => 0,
                    'samples_exit_ready'             => 0,
                    'avg_peak_roi'                   => null,
                    'avg_current_roi'                => null,
                    'avg_proposed_lock_roi'          => null,
                    'avg_post_lock_extension_roi'    => null,
                    'max_post_lock_extension_roi'    => null,
                    'avg_stop_gap_difference_pct'    => null,
                    'avg_lock_difference_roi'        => null,
                    'early_close_risk_score'         => null,
                    'comparison_samples_total'       => 0,
                    'comparison_matches_found_total' => 0,
                    'comparison_unavailable_total'   => 0,
                ];
            }

            $s = &$bySymbol[$sym];
            $s['samples_total']++;

            $currentRoi = (float)($item['current_roi'] ?? 0.0);
            $peakRoi    = (float)($item['peak_roi'] ?? 0.0);
            $lockRoi    = $item['proposed_lock_roi'] ?? $item['applied_lock_roi'] ?? null;

            $s['_current_roi_sum'] += $currentRoi;
            $s['_peak_roi_sum']    += $peakRoi;
            if ($lockRoi !== null) {
                $s['_lock_roi_sum']   += (float)$lockRoi;
                $s['_lock_roi_count'] += 1;
            }
            if ($currentRoi > 0.0) {
                $s['samples_profitable']++;
            }
            if (!empty($item['trailing_armed'])) {
                $s['samples_armed']++;
            }

            $action = (string)($item['proposed_action'] ?? $item['applied_action'] ?? '');
            if (in_array($action, ['tighten_soft', 'tighten_step', 'step_sl_update', 'dumb_trailing_set'], true)) {
                $s['samples_tightened']++;
            }
            if ($action === 'exit_ready') {
                $s['samples_exit_ready']++;
            }

            // Comparison data (shadow mode: bot_comparison block)
            if (isset($item['bot_comparison']) && is_array($item['bot_comparison'])) {
                $s['comparison_samples_total']++;
                $s['comparison_matches_found_total']++;
                $bc = $item['bot_comparison'];

                $stopGap    = isset($bc['stop_gap_difference_pct']) ? (float)$bc['stop_gap_difference_pct'] : null;
                $lockDiff   = isset($bc['lock_difference_roi'])     ? (float)$bc['lock_difference_roi']     : null;
                $postLockExt = isset($bc['post_lock_extension_roi']) ? (float)$bc['post_lock_extension_roi'] : null;

                if ($stopGap !== null) {
                    $s['_stop_gap_sum']   += $stopGap;
                    $s['_stop_gap_count'] += 1;
                }
                if ($lockDiff !== null) {
                    $s['_lock_diff_sum']   += $lockDiff;
                    $s['_lock_diff_count'] += 1;
                }
                if ($postLockExt !== null) {
                    $s['_post_lock_ext_sum']   += $postLockExt;
                    $s['_post_lock_ext_count'] += 1;
                    if ($s['max_post_lock_extension_roi'] === null || $postLockExt > $s['max_post_lock_extension_roi']) {
                        $s['max_post_lock_extension_roi'] = $postLockExt;
                    }
                }
            } elseif (
                isset($item['comparison_unavailable_reason']) ||
                isset($item['active_context_unavailable_reason'])
            ) {
                $s['comparison_samples_total']++;
                $s['comparison_unavailable_total']++;
            }
        }
        unset($s);

        // Compute averages and derived scores from accumulators
        foreach ($bySymbol as $sym => &$s) {
            $total = $s['samples_total'];
            if ($total > 0) {
                $s['avg_current_roi']       = round($s['_current_roi_sum'] / $total, 4);
                $s['avg_peak_roi']          = round($s['_peak_roi_sum'] / $total, 4);
                $s['avg_proposed_lock_roi'] = $s['_lock_roi_count'] > 0
                    ? round($s['_lock_roi_sum'] / $s['_lock_roi_count'], 4)
                    : null;
            }
            if ($s['_stop_gap_count'] > 0) {
                $s['avg_stop_gap_difference_pct'] = round($s['_stop_gap_sum'] / $s['_stop_gap_count'], 4);
            }
            if ($s['_lock_diff_count'] > 0) {
                $s['avg_lock_difference_roi'] = round($s['_lock_diff_sum'] / $s['_lock_diff_count'], 4);
            }
            if ($s['_post_lock_ext_count'] > 0) {
                $s['avg_post_lock_extension_roi'] = round($s['_post_lock_ext_sum'] / $s['_post_lock_ext_count'], 4);
            }
            // Early-close risk score: higher positive post-lock extension → higher risk of exiting before peak
            $avgExt = (float)($s['avg_post_lock_extension_roi'] ?? 0.0);
            $s['early_close_risk_score'] = $avgExt > 0.0 ? round(min(1.0, $avgExt / 5.0), 4) : 0.0;

            // Expose lock_roi sample count so passport merge can apply correct weighted average
            // for avg_proposed_lock_roi across cumulative runs.
            $s['samples_with_lock_roi'] = $s['_lock_roi_count'];

            // Remove internal accumulator keys before writing to passport
            unset(
                $s['_current_roi_sum'], $s['_peak_roi_sum'],
                $s['_lock_roi_sum'], $s['_lock_roi_count'],
                $s['_stop_gap_sum'], $s['_stop_gap_count'],
                $s['_lock_diff_sum'], $s['_lock_diff_count'],
                $s['_post_lock_ext_sum'], $s['_post_lock_ext_count']
            );
        }
        unset($s);

        return $bySymbol;
    }

    /**
     * Write per-symbol PM stats into coin passport (passive, best-effort).
     *
     * Locates CoinPassportService via sibling-path or SystemPaths, loads it
     * once, then calls updateProfitManagerStats() for each symbol that has
     * real observations. Fully non-fatal: any failure is captured in the
     * returned observability array.
     *
     * @param array<string,array> $statsBySymbol  Output of aggregatePmStatsBySymbol()
     * @param string              $ts             ISO timestamp for last_updated_at
     * @return array  Observability counters
     */
    private function tryWritePassportPmStats(array $statsBySymbol, string $ts): array
    {
        $attempted = 0;
        $success   = 0;
        $skipped   = 0;
        $errors    = 0;
        $updated   = [];

        if (empty($statsBySymbol)) {
            return [
                'passport_write_attempted_total' => 0,
                'passport_write_success_total'   => 0,
                'passport_write_skipped_total'   => 0,
                'passport_write_error_total'     => 0,
                'passport_symbols_updated'       => [],
                'passport_write_skip_reason'     => 'no_meaningful_pm_data',
            ];
        }

        // Resolve coin_passport module base: sibling path first, then SystemPaths
        $passportBase = null;
        if ($this->moduleBase !== null) {
            $candidate = dirname($this->moduleBase) . '/coin_passport';
            if (is_dir($candidate)) {
                $passportBase = $candidate;
            }
        }
        if ($passportBase === null) {
            try {
                $paths = SystemPaths::instance();
                foreach (['system.coin_passport', 'modules.coin_passport'] as $key) {
                    if ($paths->has($key)) {
                        $p = $paths->get($key);
                        if (is_string($p) && $p !== '' && is_dir($p)) {
                            $passportBase = rtrim($p, '/');
                            break;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        if ($passportBase === null) {
            $skipped = count($statsBySymbol);
            return [
                'passport_write_attempted_total' => 0,
                'passport_write_success_total'   => 0,
                'passport_write_skipped_total'   => $skipped,
                'passport_write_error_total'     => 0,
                'passport_symbols_updated'       => [],
                'passport_write_skip_reason'     => 'passport_unavailable',
            ];
        }

        // Load CoinPassportService (best-effort — require_once is idempotent)
        $passportService = null;
        try {
            $enginePath  = $passportBase . '/lib/passport_engine.php';
            $servicePath = $passportBase . '/service.php';
            if (is_file($enginePath) && is_file($servicePath)) {
                require_once $enginePath;
                require_once $servicePath;
                $passportService = new \CoinPassportService();
            }
        } catch (\Throwable $e) {
            // non-fatal
        }

        if ($passportService === null) {
            $skipped = count($statsBySymbol);
            return [
                'passport_write_attempted_total' => 0,
                'passport_write_success_total'   => 0,
                'passport_write_skipped_total'   => $skipped,
                'passport_write_error_total'     => 0,
                'passport_symbols_updated'       => [],
                'passport_write_skip_reason'     => 'passport_unavailable',
            ];
        }

        foreach ($statsBySymbol as $symbol => $stats) {
            if (($stats['samples_total'] ?? 0) <= 0) {
                $skipped++;
                continue;
            }
            if ($symbol === '') {
                $skipped++;
                continue;
            }
            $stats['last_updated_at'] = $ts;
            $attempted++;
            try {
                $ok = $passportService->updateProfitManagerStats($symbol, $stats);
                if ($ok) {
                    $success++;
                    $updated[] = $symbol;
                } else {
                    $errors++;
                }
            } catch (\Throwable $e) {
                $errors++;
            }
        }

        return [
            'passport_write_attempted_total' => $attempted,
            'passport_write_success_total'   => $success,
            'passport_write_skipped_total'   => $skipped,
            'passport_write_error_total'     => $errors,
            'passport_symbols_updated'       => $updated,
        ];
    }

    /**
     * Resolve module base path via SystemPaths
     */
    private function resolveModuleBase(): ?string
    {
        $paths = SystemPaths::instance();
        
        $candidates = [
            'system.profit_manager',
        ];
        
        foreach ($candidates as $key) {
            try {
                if ($paths->has($key)) {
                    $path = $paths->get($key);
                    if (is_string($path) && $path !== '' && is_dir($path)) {
                        return rtrim($path, '/');
                    }
                }
            } catch (\Throwable $e) {
                // Continue to next candidate
            }
        }
        
        return null;
    }
    
    /**
     * Load config
     */
    private function loadConfig(): array
    {
        $configPath = $this->moduleBase . '/config/config.php';
        
        if (!file_exists($configPath)) {
            $this->configError = 'config_not_found';
            return [];
        }
        
        $config = require $configPath;
        
        if (!is_array($config)) {
            $this->configError = 'config_invalid';
            return [];
        }
        
        return $config;
    }
    
    /**
     * Initialize gateway
     */
    private function initGateway(): void
    {
        try {
            $this->gateway = new ProfitManagerGateway($this->config);
        } catch (\Throwable $e) {
            $this->configError = 'gateway_init_failed: ' . $e->getMessage();
            $this->gateway = null;
        }
    }
    
    /**
     * Build error result
     */
    private function buildErrorResult(string $ts, float $startTime, string $error): array
    {
        $result = [
            'ts' => $ts,
            'ok' => false,
            'status' => 'error',
            'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
            'mode' => $this->config['module']['mode'] ?? 'dry',
            'selected_mode' => null,
            'positions_total' => 0,
            'positions_managed' => 0,
            'step_trailing' => ['applied' => 0, 'skipped' => 0, 'failed' => 0],
            'dumb_trailing' => ['applied' => 0, 'skipped' => 0, 'failed' => 0],
            'items' => [],
            'errors' => [$error],
            'warnings' => [],
        ];
        
        if ($this->store !== null) {
            $this->store->saveLastRun($result);
        }
        
        return $result;
    }
    
    /**
     * Build disabled result
     */
    private function buildDisabledResult(string $ts, float $startTime): array
    {
        return [
            'ts' => $ts,
            'ok' => true,
            'status' => 'disabled',
            'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
            'mode' => $this->config['module']['mode'] ?? 'dry',
            'selected_mode' => null,
            'positions_total' => 0,
            'positions_managed' => 0,
            'step_trailing' => ['applied' => 0, 'skipped' => 0, 'failed' => 0],
            'dumb_trailing' => ['applied' => 0, 'skipped' => 0, 'failed' => 0],
            'items' => [],
            'errors' => [],
            'warnings' => [],
        ];
    }
}

/* RULES
- SystemPaths ONLY (no absolute paths)
- Does NOT open trades, does NOT set leverage
- Only calls setTradingStop on existing positions
- Writes ONLY inside this module storage/
- CONFIG FIRST / ZERO HARDCODE
- Idempotent: repeated runs safe
*/
