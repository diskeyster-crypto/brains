<?php
declare(strict_types=1);

namespace Modules\System\TradingBot;

use Core\System\SystemPaths;

/**
 * Trading Bot v1 Service
 * 
 * LIVE Executor - executes trades based on Brain decisions.
 * Bot does NOT "think" - it only executes: open / update / close / reconcile / safety.
 * 
 * Risk = ONLY from Brain (risk-block).
 * NO HARDCODE / CONFIG FIRST / SystemPaths ONLY
 */
final class TradingBotService
{
    use Lib\BotCoreTrait;
    use Lib\BotConfigTrait;
    use Lib\BotSourcesTrait;
    use Lib\BotReconcileTrait;
    use Lib\BotExecutorTrait;
    use Lib\BotApiTrait;
    use Lib\BotCommandsTrait;
    
    private ?string $moduleBase = null;
    private ?string $storageDir = null;
    private ?string $logsDir = null;
    private array $config = [];
    private ?string $configError = null;
    private array $errors = [];
    private array $warnings = [];
    
    /** @var Lib\BotRiskEngine */
    private $riskEngine;
    
    /** @var Lib\BotTrailingEngine */
    private $trailingEngine;
    
    /** @var Lib\BotValidator */
    private $validator;
    
    /** @var Lib\BotStore */
    private $store;
    
    public function __construct()
    {
        $this->moduleBase = $this->resolveModuleBase();
        
        if ($this->moduleBase === null) {
            $this->configError = 'module_base_unknown';
            return;
        }
        
        $this->config = $this->loadConfig();

        // Mode-based storage namespace: live → storage_live/, demo → storage_demo/, paper/dry → storage_paper/
        $mode = $this->config['module']['mode'] ?? 'paper';
        if ($mode === 'live') {
            $storageSuffix = '/storage_live';
        } elseif ($mode === 'demo') {
            $storageSuffix = '/storage_demo';
        } else {
            $storageSuffix = '/storage_paper';
        }
        $this->storageDir = $this->moduleBase . $storageSuffix;
        $this->logsDir    = $this->storageDir . '/logs';

        // Initialize sub-components
        $this->riskEngine    = new Lib\BotRiskEngine($this->config);
        $this->trailingEngine = new Lib\BotTrailingEngine($this->config);
        $this->validator     = new Lib\BotValidator($this->config);
        $this->store         = new Lib\BotStore($this->storageDir, $this->config);

        // Initialize gateway for real-exchange modes (live and demo)
        if ($mode === 'live' || $mode === 'demo') {
            $this->initGateway();
        }
    }
    
    /**
     * Main execution entry point
     * 
     * Sequence:
     * 1. Reconcile with exchange (sync positions/orders)
     * 2. Load intents from Brain signals
     * 3. Validate intents (risk block, required fields)
     * 4. Execute valid intents (open positions)
     * 5. Update active positions (trailing, TP/SL)
     * 6. Close completed positions
     * 7. Safety checks
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
        
        // Run-lock: prevent concurrent executions
        if ($this->config['execution']['run_lock_enabled'] ?? true) {
            $lockResult = $this->acquireRunLock();
            if (!$lockResult['acquired']) {
                $response = [
                    'ts' => $ts,
                    'ok' => true,
                    'status' => 'locked',
                    'message' => 'Another execution is in progress',
                    'duration_ms' => (int)round((microtime(true) - $startTime) * 1000),
                ];

                // Include lock diagnostics when available (useful for Dashboard debugging).
                if (isset($lockResult['lock_meta']) && $lockResult['lock_meta'] !== null) {
                    $response['lock_meta'] = $lockResult['lock_meta'];
                }

                return $response;
            }
            $lockFp = $lockResult['fp'];
        }
        
        $mode = $this->config['module']['mode'] ?? 'dry';
        $result = [
            'ts' => $ts,
            'ok' => true,
            'status' => 'ok',
            'mode' => $mode,
            'steps' => [],
            'intents_loaded' => 0,
            'intents_valid' => 0,
            'intents_rejected' => 0,
            // P6.7: Separate rejected (validation) from rejected (execution) and failed (errors)
            // NOTE: intents_rejected_exec and intents_failed_exec are derived from
            // finalized intent_results after post-processing (single source of truth).
            'intents_rejected_exec' => 0,
            'intents_failed_exec' => 0,
            'intents_rejected_total' => 0,
            // P6.9: Deferred intents (wait_retrace not met yet)
            'intents_deferred' => 0,
            'positions_opened' => 0,
            'positions_updated' => 0,
            'positions_closed' => 0,
            'orders_sent' => 0,
            'orders_filled' => 0,
            'orders_failed' => 0,
            'errors_count' => 0,
            'errors' => [],
            'warnings' => [],
            // P6.11: UI explainability fields
            'selected_intent' => null,
            'selected_decision' => null,
            'balance_snapshot_last' => null,
            'balance_snapshot_ts' => 0,
            'intents_preview' => [],
            'intents_preview_total' => 0,
            // Observability: per-intent result records and lifecycle tracking
            // All summary counts below are re-derived from finalized intent_results
            // after post-processing (see "Derive execution summary counts" block).
            'intent_results' => [],
            'intents_processed' => 0,
            'intents_opened' => 0,
            'intents_skipped' => 0,
            'rejection_reason_stats' => [],
            'close_reason_stats' => [],
            // Effective post-entry contract fields (flat, always populated)
            'effective_exit_mode' => null,
            'effective_break_even_enabled' => null,
            'effective_break_even_activation' => null,
            'effective_trailing_activation' => null,
            'effective_trailing_enabled' => null,
            'effective_drawdown_factor' => null,
            'effective_hybrid_tp_share' => null,
            // Stop control mode (entry_roi / auto / manual)
            'effective_stop_control_mode' => null,
            'effective_stop_loss_from_entry_roi' => null,
        ];
        
        try {
            // Step 1: Reconcile with exchange
            $reconciledThisRun = false;
            if ($this->config['module']['reconcile_before_action'] ?? true) {
                $reconcileResult = $this->reconcileWithExchange();
                $reconciledThisRun = true;
                $result['steps'][] = [
                    'step' => 'reconcile',
                    'status' => $reconcileResult['ok'] ? 'ok' : 'error',
                    'positions_synced' => $reconcileResult['positions_synced'] ?? 0,
                    'orders_synced' => $reconcileResult['orders_synced'] ?? 0,
                    // P3: Include orphan positions in result
                    'orphan_positions_count' => $reconcileResult['orphan_positions_count'] ?? 0,
                    'orphan_positions' => $reconcileResult['orphan_positions'] ?? [],
                ];
                
                // P3: Warn if orphan positions exist
                if (($reconcileResult['orphan_positions_count'] ?? 0) > 0) {
                    $this->warnings[] = "P3: {$reconcileResult['orphan_positions_count']} orphan position(s) found on exchange";
                }
                
                if (!$reconcileResult['ok']) {
                    $this->errors[] = 'Reconcile failed: ' . ($reconcileResult['error'] ?? 'unknown');
                }
            }

            // ── Demo learning mode: force reconcile even when reconcile_before_action is disabled ──
            // This ensures exchange-closed positions are detected every demo cycle.
            if (!$reconciledThisRun && $mode === 'demo') {
                $dlmCfgRec = is_array($this->config['demo_learning_mode'] ?? null) ? $this->config['demo_learning_mode'] : [];
                if (($dlmCfgRec['enabled'] ?? false) && ($dlmCfgRec['force_reconcile_each_run_demo'] ?? false)) {
                    $demoRecResult = $this->reconcileWithExchange();
                    $result['steps'][] = [
                        'step'             => 'reconcile_demo_forced',
                        'status'           => $demoRecResult['ok'] ? 'ok' : 'error',
                        'positions_synced' => $demoRecResult['positions_synced'] ?? 0,
                        'orders_synced'    => $demoRecResult['orders_synced'] ?? 0,
                    ];
                    if (!$demoRecResult['ok']) {
                        $this->errors[] = 'Demo force reconcile failed: ' . ($demoRecResult['error'] ?? 'unknown');
                    }
                }
            }
            
            // P7.6.3: Step 1.5: Apply Brain Commands (before intents)
            $commandsApplyBefore = (bool)($this->config['execution']['commands_apply_before_intents'] ?? true);
            if ($commandsApplyBefore) {
                $cmdRes = $this->applyBrainCommands($mode);
                $result['steps'][] = [
                    'step' => 'apply_commands',
                    'status' => $cmdRes['ok'] ? 'ok' : 'error',
                    'loaded' => $cmdRes['loaded'] ?? 0,
                    'processed' => $cmdRes['processed'] ?? 0,
                    'applied_ok' => $cmdRes['applied_ok'] ?? 0,
                    'applied_failed' => $cmdRes['applied_failed'] ?? 0,
                    'skipped_already_applied' => $cmdRes['skipped_already_applied'] ?? 0,
                    'invalid' => $cmdRes['invalid'] ?? 0,
                    'command_status' => $cmdRes['status'] ?? 'unknown',
                ];
                
                // P7.6.3: Handle command errors
                if ($cmdRes['ok'] !== true) {
                    $commandsEnabled = (bool)($this->config['execution']['commands_enabled'] ?? false);
                    $errorMsg = 'Commands failed: ' . ($cmdRes['status'] ?? 'unknown') . 
                               (empty($cmdRes['errors']) ? '' : ' - ' . implode('; ', $cmdRes['errors']));
                    
                    if ($commandsEnabled) {
                        // Commands are enabled - this is an error
                        $this->errors[] = $errorMsg;
                    } else {
                        // Commands not enabled - just a warning (shouldn't happen, but safety)
                        $this->warnings[] = $errorMsg;
                    }
                }
            }
            
            // Step 2: Detect Brain-controlled mode FIRST (from config, not file load)
            // This MUST happen before any source loading so the execution path
            // branches explicitly: Brain-only vs legacy.
            $brainControlled = $this->detectBrainControlledMode();
            $legacyFallbackAllowed = !$brainControlled;
            $legacyFallbackUsed = false;
            $sourceStatus = 'unknown';
            $sourceError = '';
            $effectiveLiveConfig = [];
            // Flag: defer the "no pending intents" warning until after stale-claim finalization
            // so the message reflects the final post-cleanup lifecycle state, not the pre-load snapshot.
            $noPendingWarningDeferred = false;

            // Step 2.1: Demo mode — Pattern Engine demo feed takes priority when configured
            $demoSourceMode = null;
            if ($mode === 'demo') {
                $demoSourceMode = (string)($this->config['demo_sources']['source_mode'] ?? '');
            }

            if ($demoSourceMode === 'pattern_engine_demo') {
                // Pattern Engine demo feed: read demo_signals.json, no Brain involvement
                $peDemoResult     = $this->loadPatternEngineDemoIntents();
                $intentsResult    = $peDemoResult;
                $inputSource      = 'pattern_engine_demo_feed';
                $brainControlled  = false;
                $legacyFallbackAllowed = false;
                $legacyFallbackUsed    = false;
                $sourceStatus          = $peDemoResult['source_status'] ?? ($peDemoResult['ok'] ? 'loaded' : 'invalid');
                $sourceError           = implode('; ', $peDemoResult['errors'] ?? []);
                $result['demo_source_mode']         = 'pattern_engine_demo';
                $result['demo_source_path']         = $peDemoResult['source_path'] ?? '';
                $result['demo_signals_loaded']      = $peDemoResult['signals_loaded'] ?? 0;
                $result['demo_signals_skipped']     = $peDemoResult['signals_skipped'] ?? 0;
                // Feed freshness diagnostics
                $result['pattern_engine_demo_feed_generated_at'] = $peDemoResult['feed_generated_at'] ?? null;
                $result['demo_feed_freshness_seconds']           = $peDemoResult['feed_freshness_seconds'] ?? null;
                $result['trading_bot_run_at']                    = date('c');
                $result['demo_feed_consumed_this_run']           = ($peDemoResult['signals_loaded'] ?? 0) > 0;
                // PART 1: detailed feed diagnostics
                $result['demo_feed_available_count']             = $peDemoResult['demo_feed_available_count'] ?? 0;
                $result['demo_feed_skipped_due_to_idempotency']  = $peDemoResult['demo_feed_skipped_due_to_idempotency'] ?? 0;
                $result['demo_feed_skipped_due_to_ttl']          = $peDemoResult['demo_feed_skipped_due_to_ttl'] ?? 0;
                $result['demo_feed_skipped_due_to_validation']   = $peDemoResult['demo_feed_skipped_due_to_validation'] ?? 0;
                $result['demo_feed_skipped_other']               = $peDemoResult['demo_feed_skipped_other'] ?? 0;
                // PART 2: rotation diagnostics
                $result['demo_signal_rotation_mode']             = $peDemoResult['demo_signal_rotation_mode'] ?? 'fifo';
                $result['demo_signals_selected_by_rotation']     = $peDemoResult['demo_signals_selected_by_rotation'] ?? 0;
                // demo_learning_mode: cap signals per run
                $dlmCfg = is_array($this->config['demo_learning_mode'] ?? null) ? $this->config['demo_learning_mode'] : [];
                $capApplied = false;
                if (($dlmCfg['enabled'] ?? false) && ($dlmCfg['max_demo_signals_per_run'] ?? 0) > 0) {
                    $maxDemoSignals = (int)$dlmCfg['max_demo_signals_per_run'];
                    $countBeforeCap = count($intentsResult['intents'] ?? []);
                    if ($countBeforeCap > $maxDemoSignals) {
                        $intentsResult['intents'] = array_slice($intentsResult['intents'], 0, $maxDemoSignals);
                        $intentsResult['count']   = $maxDemoSignals;
                        $capApplied = true;
                        $result['demo_feed_skipped_due_to_cap'] = $countBeforeCap - $maxDemoSignals;
                        $result['demo_signals_deferred_by_rotation'] = $countBeforeCap - $maxDemoSignals;
                    }
                }
                if (!$capApplied) {
                    $result['demo_feed_skipped_due_to_cap']      = 0;
                    $result['demo_signals_deferred_by_rotation'] = 0;
                }
                $result['demo_feed_selected_count'] = count($intentsResult['intents'] ?? []);
                // PART 2 (runtime proof): effective demo_learning_mode values
                $result['demo_learning_mode_enabled']              = (bool)($dlmCfg['enabled'] ?? false);
                $result['demo_max_signals_per_run_effective']      = (int)($dlmCfg['max_demo_signals_per_run'] ?? 0);
                $result['demo_max_concurrent_positions_effective'] = (int)($dlmCfg['max_concurrent_demo_positions'] ?? 0);
            } elseif ($brainControlled) {
                // Brain-controlled mode: Brain live intents are the ONLY source.
                // NO legacy fallback is allowed — regardless of source status.
                $brainLiveResult = $this->loadBrainLiveIntents();
                $effectiveLiveConfig = $brainLiveResult['effective_live_config'] ?? [];
                $sourceStatus = $brainLiveResult['source_status'] ?? 'unknown';
                $sourceError = $brainLiveResult['source_error'] ?? '';

                if ($sourceStatus === 'disabled') {
                    $inputSource = 'brain_live_intents_disabled';
                    $intentsResult = $brainLiveResult;
                } elseif ($sourceStatus === 'missing') {
                    // File missing but Brain mode is ON → safe no-trade, NO fallback
                    $inputSource = 'none';
                    $intentsResult = ['ok' => true, 'count' => 0, 'intents' => [], 'errors' => $brainLiveResult['errors'] ?? []];
                    $this->warnings[] = $sourceError !== '' ? $sourceError : 'Brain-controlled mode active: Brain live intents source is missing. No trades executed. Legacy fallback disabled.';
                } elseif ($sourceStatus === 'invalid' || !($brainLiveResult['ok'] ?? false)) {
                    // File invalid/corrupted but Brain mode is ON → safe stop, NO fallback
                    $inputSource = 'brain_live_intents_error';
                    $intentsResult = ['ok' => true, 'count' => 0, 'intents' => [], 'errors' => $brainLiveResult['errors'] ?? []];
                    $this->warnings[] = 'Brain-controlled mode active: live_intents.json is invalid. Run stopped. Legacy fallback is disabled. ' . implode('; ', $brainLiveResult['errors'] ?? []);
                } elseif (($brainLiveResult['count'] ?? 0) === 0) {
                    // Brain has zero *pending* intents available — valid decision, NOT an error.
                    // This does NOT mean Brain never approved any intents.
                    // Previously approved intents may already be claimed/executed/rejected.
                    $inputSource = 'brain_live_intents';
                    $intentsResult = $brainLiveResult;
                    $lifecycleSkipped = $brainLiveResult['lifecycle_skipped'] ?? [];
                    // Warning is deferred until after stale-claim finalization so the message
                    // reflects the final post-cleanup lifecycle state, not the pre-load snapshot.
                    // (e.g. a claimed intent finalized by stale cleanup must appear as rejected,
                    //  not still claimed, in the operator-facing message.)
                    $noPendingWarningDeferred = true;
                } else {
                    $inputSource = 'brain_live_intents';
                    $intentsResult = $brainLiveResult;
                }
            } else {
                // Brain-controlled mode NOT active — legacy signal path
                $intentsResult = $this->loadIntentsFromSignals();
                $inputSource = 'legacy_signals';
                $legacyFallbackUsed = true;
                $sourceStatus = ($intentsResult['ok'] ?? false) ? 'loaded' : 'invalid';
            }

            $result['intents_loaded'] = $intentsResult['count'] ?? 0;
            $result['input_source'] = $inputSource;
            $result['controlled_by_brain'] = $brainControlled;
            $result['effective_selection_mode_from_brain'] = (string)($effectiveLiveConfig['live_signal_selection_mode'] ?? 'n/a');
            $result['strategy_overrides_disabled_or_overridden'] = $brainControlled;
            $result['legacy_fallback_allowed'] = $legacyFallbackAllowed;
            $result['legacy_fallback_used'] = $legacyFallbackUsed;
            $result['brain_controlled_live_mode'] = $brainControlled;
            $result['source_status'] = $sourceStatus;
            $result['source_error_message'] = $sourceError;
            $result['approved_intents_loaded'] = $intentsResult['count'] ?? 0;
            $result['executable_intents_count'] = $intentsResult['count'] ?? 0;
            $result['duplicate_skipped'] = $intentsResult['duplicate_skipped'] ?? 0;
            $result['lifecycle_skipped'] = $intentsResult['lifecycle_skipped'] ?? [];
            $liveIntentsFilePath = $intentsResult['live_intents_path'] ?? '';

            // V3 DIAGNOSTIC: Include Brain detection diagnostics for runtime observability
            $brainDiag = $this->getBrainDetectionDiagnostics();
            $result['brain_detection_diagnostics'] = [
                'bot_sources_trait_runtime_marker' => $brainDiag['bot_sources_trait_runtime_marker'] ?? null,
                'php_file_used_bot_sources_trait' => $brainDiag['php_file_used_bot_sources_trait'] ?? null,
                'brain_storage_path_resolved' => $brainDiag['brain_resolved_paths']['brain_storage_base'] ?? null,
                'smart_brain_base_derived' => $brainDiag['brain_resolved_paths']['smart_brain_base_derived'] ?? null,
                'smart_brain_base_exists' => $brainDiag['brain_resolved_paths']['smart_brain_base_exists'] ?? null,
                'brain_detection_result' => $brainControlled,
                'brain_detection_trace' => $brainDiag['brain_detection_trace'] ?? [],
            ];

            // Observability: merge duplicate-skipped intent result records
            $dupRecords = $intentsResult['duplicate_skipped_records'] ?? [];
            if (!empty($dupRecords) && is_array($dupRecords)) {
                foreach ($dupRecords as $dupRec) {
                    $result['intent_results'][] = $dupRec;
                    $result['intents_processed']++;
                    $result['intents_skipped']++;
                    $rr = $dupRec['rejection_reason'] ?? '';
                    if ($rr !== '') {
                        $result['rejection_reason_stats'][$rr] = ($result['rejection_reason_stats'][$rr] ?? 0) + 1;
                    }
                }
            }
            
            // P6.11: Intents preview (first 10 intents before validation)
            $result['intents_preview_total'] = $intentsResult['count'] ?? 0;
            $result['intents_preview'] = [];
            if (!empty($intentsResult['intents']) && is_array($intentsResult['intents'])) {
                $preview = array_slice($intentsResult['intents'], 0, 10);
                foreach ($preview as $pIntent) {
                    $result['intents_preview'][] = $this->summarizeIntent($pIntent);
                }
            }

            $result['steps'][] = [
                'step' => 'load_intents',
                'status' => 'ok',
                'count' => $intentsResult['count'] ?? 0,
                'source' => $inputSource,
                'brain_controlled' => $brainControlled,
            ];

            // Brain-owned execution limits visibility
            if ($brainControlled) {
                if (!empty($effectiveLiveConfig)) {
                    $result['effective_live_max_positions'] = (int)($effectiveLiveConfig['live_max_positions'] ?? 3);
                    $result['effective_live_one_trade_per_symbol'] = (bool)($effectiveLiveConfig['live_one_trade_per_symbol'] ?? true);
                }
                $result['effective_trailing_contract_source'] = 'brain_intent';
                $result['limits_controlled_by_brain'] = true;
                // V3: Brain-controlled trailing visibility
                $result['trailing_controlled_by_brain'] = true;
                $result['local_trailing_toggles_overridden'] = true;
                $result['execution_identity_key'] = 'intent_id';
                $result['execution_key_basis'] = 'intent_id';
                $result['dedupe_basis'] = 'intent_id';
                // V3: Extract normalized_drawdown_factor_source and effective trailing contract from first available intent
                $result['normalized_drawdown_factor_source'] = 'n/a';
                $result['effective_trailing_contract'] = null;
                if (!empty($intentsResult['intents'])) {
                    $firstIntent = $intentsResult['intents'][0] ?? [];
                    $firstRisk = is_array($firstIntent['risk'] ?? null) ? $firstIntent['risk'] : [];
                    $firstTrailing = is_array($firstRisk['trailing'] ?? null) ? $firstRisk['trailing'] : [];
                    $result['normalized_drawdown_factor_source'] = $firstTrailing['drawdown_factor_source'] ?? 'n/a';
                    // V5: Include effective trailing contract snapshot for runtime truth
                    // This snapshot must match what bot_executor actually uses for execution
                    $result['effective_trailing_contract'] = [
                        'enabled' => $firstTrailing['enabled'] ?? null,
                        'trailing_mode' => $firstTrailing['trailing_mode'] ?? 'roi_giveback',
                        'activation_roi_pct' => $firstTrailing['activation_roi_pct'] ?? null,
                        'drawdown_factor' => $firstTrailing['drawdown_factor'] ?? null,
                        'trailing_price_distance_pct' => $firstTrailing['trailing_price_distance_pct'] ?? null,
                        'trailing_distance_roi' => $firstTrailing['trailing_distance_roi'] ?? null,
                        'trailing_preset_mode' => $firstTrailing['trailing_preset_mode'] ?? null,
                        'trailing_contract_source' => $firstTrailing['trailing_contract_source'] ?? null,
                        'trailing_activation_floor_roi' => $firstTrailing['trailing_activation_floor_roi'] ?? null,
                        'trailing_floor_lock_roi' => $firstTrailing['trailing_floor_lock_roi'] ?? null,
                        'min_step' => $firstTrailing['min_step'] ?? null,
                        'min_lock_roi' => $firstTrailing['min_lock_roi'] ?? null,
                        'break_even_enabled' => $firstTrailing['break_even_enabled'] ?? null,
                        'break_even_activation_roi' => $firstTrailing['break_even_activation_roi'] ?? null,
                        'exit_mode' => $firstTrailing['exit_mode'] ?? null,
                        'fixed_take_profit_roi' => $firstTrailing['fixed_take_profit_roi'] ?? null,
                        'hybrid_tp_share' => $firstTrailing['hybrid_tp_share'] ?? null,
                        'brain_trailing_applied' => $firstTrailing['brain_trailing_applied'] ?? null,
                        'effective_trailing_contract_source' => $firstTrailing['effective_trailing_contract_source'] ?? 'brain_risk_trailing',
                        'unit_system' => $firstTrailing['unit_system'] ?? null,
                    ];
                }
            } else {
                $result['effective_trailing_contract_source'] = 'bot_local_config';
                $result['limits_controlled_by_brain'] = false;
                // V3: Legacy trailing visibility
                $result['trailing_controlled_by_brain'] = false;
                $result['local_trailing_toggles_overridden'] = false;
                $result['execution_identity_key'] = 'signal_id';
                $result['execution_key_basis'] = 'legacy_signal_id';
                $result['dedupe_basis'] = 'legacy_signal_id';
                $result['normalized_drawdown_factor_source'] = 'legacy_non_brain_mode';
                // Non-brain mode: derive effective contract from bot local trailing config
                $localTrailingCfg = $this->config['execution']['trailing'] ?? [];
                $localDumbTrailingCfg = $this->config['execution']['dumb_trailing'] ?? [];
                $localTrailingEnabled = (bool)($localTrailingCfg['enabled'] ?? $localDumbTrailingCfg['enabled'] ?? false);
                $result['effective_trailing_contract'] = [
                    'enabled' => $localTrailingEnabled,
                    'trailing_mode' => 'roi_giveback',
                    'activation_roi_pct' => (float)($localTrailingCfg['activation_roi_pct'] ?? 0),
                    'drawdown_factor' => (float)($localDumbTrailingCfg['drawdown_factor_default'] ?? 0.5),
                    'trailing_price_distance_pct' => null,
                    'min_step' => (float)($localTrailingCfg['min_step'] ?? 0),
                    'min_lock_roi' => (float)($localTrailingCfg['min_lock_roi'] ?? 0),
                    'break_even_enabled' => (bool)($localTrailingCfg['break_even_enabled'] ?? false),
                    'break_even_activation_roi' => (float)($localTrailingCfg['break_even_activation_roi'] ?? 0),
                    'exit_mode' => (string)($localTrailingCfg['exit_mode'] ?? 'trailing_tp'),
                    'fixed_take_profit_roi' => (float)($localTrailingCfg['fixed_take_profit_roi'] ?? 0),
                    'hybrid_tp_share' => (float)($localTrailingCfg['hybrid_tp_share'] ?? 0),
                    'brain_trailing_applied' => false,
                    'effective_trailing_contract_source' => 'bot_local_config',
                    'unit_system' => 'activation_pct=percent,drawdown_factor=ratio',
                ];
            }

            // Populate flat effective post-entry contract fields from effective_trailing_contract.
            // These must never be null when the contract is known — null means "not applicable".
            $etc = is_array($result['effective_trailing_contract'] ?? null) ? $result['effective_trailing_contract'] : [];
            if (!empty($etc)) {
                $exitMode = (string)($etc['exit_mode'] ?? 'unknown');
                $result['effective_exit_mode'] = $exitMode;
                $result['effective_break_even_enabled'] = (bool)($etc['break_even_enabled'] ?? false);
                $result['effective_break_even_activation'] = (float)($etc['break_even_activation_roi'] ?? 0);
                $result['effective_trailing_activation'] = (float)($etc['activation_roi_pct'] ?? 0);
                $result['effective_trailing_enabled'] = (bool)($etc['enabled'] ?? false);
                $result['effective_drawdown_factor'] = (float)($etc['drawdown_factor'] ?? 0);
                $result['effective_hybrid_tp_share'] = ($exitMode === 'hybrid_tp')
                    ? (float)($etc['hybrid_tp_share'] ?? 0)
                    : null;
            }

            // Populate effective stop control mode from risk contract
            if ($brainControlled && !empty($intentsResult['intents'])) {
                $firstIntent = $intentsResult['intents'][0] ?? [];
                $firstRisk = is_array($firstIntent['risk'] ?? null) ? $firstIntent['risk'] : [];
                $firstStopControl = is_array($firstRisk['stop_control'] ?? null) ? $firstRisk['stop_control'] : [];
                $result['effective_stop_control_mode'] = (string)($firstStopControl['stop_control_mode'] ?? 'auto');
                $result['effective_stop_loss_from_entry_roi'] = ($firstStopControl['stop_control_mode'] ?? 'auto') === 'entry_roi'
                    ? (float)($firstStopControl['stop_loss_from_entry_roi'] ?? 0)
                    : null;
            } else {
                $result['effective_stop_control_mode'] = 'auto';
                $result['effective_stop_loss_from_entry_roi'] = null;
            }
            
            // Step 3: Validate intents
            $validIntents = [];
            $rejectedIntents = [];
            
            foreach ($intentsResult['intents'] as $intent) {
                $validation = $this->validator->validateIntent($intent);
                
                if ($validation['valid']) {
                    $validIntents[] = $intent;
                } else {
                    $rejectedIntents[] = [
                        'intent' => $intent,
                        'reason' => $validation['reason'],
                        'missing_fields' => $validation['missing_fields'] ?? [],
                    ];
                    $this->store->saveRejectedIntent($intent, $validation);
                    // Lifecycle: Mark validation-rejected intent in live_intents.json
                    if ($brainControlled && !empty($liveIntentsFilePath)) {
                        $iid = $intent['intent_id'] ?? '';
                        if ($iid !== '') {
                            $this->updateLiveIntentStatus($iid, $liveIntentsFilePath, 'rejected', [
                                'reject_reason' => 'rejected_intent_validation: ' . ($validation['reason'] ?? 'unknown'),
                            ]);
                        }
                    }
                }
            }
            
            // P5.12: Sort intents by created_ts DESC (newest first).
            // IMPORTANT: Do NOT truncate to max_intents_per_run here.
            // We must SCAN multiple intents so deferred wait_retrace does not block newer enter_now signals.
            $maxExecutePerRun = (int)($this->config['execution']['max_intents_per_run'] ?? 1);
            $maxScanPerRun = (int)($this->config['execution']['max_scan_intents_per_run'] ?? 50);

            if ($maxExecutePerRun <= 0) {
                $maxExecutePerRun = 1;
            }
            if ($maxScanPerRun <= 0) {
                $maxScanPerRun = 50;
            }

            $totalValidIntents = count($validIntents);

            if ($totalValidIntents > 0) {
                usort($validIntents, function($a, $b) {
                    $tsA = $a['created_ts'] ?? 0;
                    $tsB = $b['created_ts'] ?? 0;
                    return $tsB <=> $tsA; // DESC
                });
            }

            // Scan list: cap only for safety (max_scan_intents_per_run)
            $scanIntents = $validIntents;
            if ($totalValidIntents > $maxScanPerRun) {
                $this->warnings[] = "P5.12: Scan truncated {$totalValidIntents} intents to {$maxScanPerRun} (max_scan_intents_per_run)";
                $scanIntents = array_slice($validIntents, 0, $maxScanPerRun);
            }

            $result['intents_valid'] = $totalValidIntents;
            $result['intents_rejected'] = count($rejectedIntents);
            $result['intents_validation_passed_count'] = $totalValidIntents;
            $result['intents_truncated'] = $totalValidIntents - count($scanIntents);
            $result['steps'][] = [
                'step' => 'validate_intents',
                'status' => 'ok',
                'valid' => count($validIntents),
                'rejected' => count($rejectedIntents),
                'truncated' => $result['intents_truncated'],
            ];
            
            // Step 4: Execute valid intents (open positions)
            $safetyStopThreshold = (int)($this->config['module']['safety_stop_on_errors'] ?? 5);

            // ── Lifecycle: Claim intents before execution ────────────────
            // Atomically mark intents as claimed in live_intents.json to prevent
            // duplicate consumption by concurrent bot ticks.
            $claimResult = ['claimed_count' => 0, 'errors' => []];
            if ($brainControlled && !empty($liveIntentsFilePath) && !empty($scanIntents)) {
                $intentIdsToClaim = [];
                foreach ($scanIntents as $si) {
                    $iid = $si['intent_id'] ?? '';
                    if ($iid !== '') {
                        $intentIdsToClaim[] = $iid;
                    }
                }
                if (!empty($intentIdsToClaim)) {
                    $claimResult = $this->claimLiveIntents($intentIdsToClaim, $liveIntentsFilePath);
                }
            }
            $result['intent_claim'] = $claimResult;
            $result['intents_loaded_count'] = count($intentsResult['intents'] ?? []);
            $result['intents_claimed_now_count'] = $claimResult['claimed_count'] ?? 0;
            $result['steps'][] = [
                'step' => 'claim_intents',
                'status' => empty($claimResult['errors']) ? 'ok' : 'warning',
                'claimed' => $claimResult['claimed_count'] ?? 0,
                'already_claimed' => $claimResult['already_claimed'] ?? 0,
                'not_found' => $claimResult['not_found'] ?? 0,
                'expired_skipped' => $claimResult['expired_skipped'] ?? 0,
            ];

            // ── Lifecycle: Finalize stale claimed intents ────────────────
            // Claimed intents that exceeded the claim timeout are finalized
            // as rejected to prevent zombie records hanging indefinitely.
            $staleClaimResult = ['finalized_count' => 0, 'errors' => []];
            if ($brainControlled && !empty($liveIntentsFilePath)) {
                $claimTimeoutMin = (int)($this->config['execution']['claim_timeout_minutes'] ?? 10);
                $staleClaimResult = $this->finalizeStaleClaimedIntents($liveIntentsFilePath, $claimTimeoutMin);
            }
            $result['stale_claim_finalization'] = $staleClaimResult;
            $result['steps'][] = [
                'step' => 'finalize_stale_claims',
                'status' => empty($staleClaimResult['errors']) ? 'ok' : 'warning',
                'finalized' => $staleClaimResult['finalized_count'] ?? 0,
                'stale_found' => $staleClaimResult['stale_claimed_found'] ?? 0,
            ];
            
            if ($mode !== 'test') {
                $executedThisRun = 0;
                $deferredChecked = 0;
                $maxDeferredPerRun = (int)($this->config['execution']['max_deferred_intents_per_run'] ?? 10);
                if ($maxDeferredPerRun <= 0) {
                    $maxDeferredPerRun = 10;
                }

                // demo_learning_mode: cap max concurrent positions for demo mode
                if ($mode === 'demo') {
                    $dlmCfg = is_array($this->config['demo_learning_mode'] ?? null) ? $this->config['demo_learning_mode'] : [];
                    if (($dlmCfg['enabled'] ?? false) && ($dlmCfg['max_concurrent_demo_positions'] ?? 0) > 0) {
                        $this->config['module']['max_concurrent_positions'] = (int)$dlmCfg['max_concurrent_demo_positions'];
                    }
                }

                // Aggregate deferred reasons to avoid log spam
                $deferredReasonCounts = [];

                foreach ($scanIntents as $intent) {
                    // P6.11: Track selected intent (first processed intent)
                    if ($result['selected_intent'] === null) {
                        $result['selected_intent'] = $this->summarizeIntent($intent);
                    }

                    // P5.12: Stop when max intents executed for this run (deferred does NOT count).
                    if ($executedThisRun >= $maxExecutePerRun) {
                        break;
                    }

                    // P5.12: Safety stop if too many deferred wait_retrace checks in one run.
                    if ($deferredChecked >= $maxDeferredPerRun) {
                        $this->warnings[] = "P5.12: Deferred check limit reached ({$maxDeferredPerRun})";
                        break;
                    }

                    // P5: Break if too many errors
                    if (count($this->errors) >= $safetyStopThreshold) {
                        $this->warnings[] = "P5: Execution stopped early - error threshold ({$safetyStopThreshold}) reached";
                        break;
                    }
                    
                    $execResult = $this->executeIntent($intent, $mode);

                    // Observability: build per-intent result record
                    // NOTE: summary counts are derived from finalized intent_results after
                    // updateActivePositions post-processing (single source of truth).
                    $intentResultRecord = $this->buildIntentResultRecord($intent, $execResult);
                    $result['intent_results'][] = $intentResultRecord;

                    // ── Lifecycle: Update intent status in live_intents.json ──
                    // Every claimed intent MUST reach a terminal state (executed/rejected)
                    // in the same run. Only deferred (wait_retrace) intents may remain
                    // as claimed for the next tick. All other states are finalized here.
                    if ($brainControlled && !empty($liveIntentsFilePath)) {
                        $iid = $intent['intent_id'] ?? '';
                        if ($iid !== '') {
                            $lifecycleState = $intentResultRecord['lifecycle_state'] ?? '';
                            if (in_array($lifecycleState, ['opened', 'protected', 'trailing_active'], true)) {
                                $this->updateLiveIntentStatus($iid, $liveIntentsFilePath, 'executed', [
                                    'execution_result' => $execResult['status'] ?? 'unknown',
                                ]);
                            } elseif (in_array($lifecycleState, ['rejected', 'failed', 'closed'], true)) {
                                $this->updateLiveIntentStatus($iid, $liveIntentsFilePath, 'rejected', [
                                    'reject_reason' => $execResult['status'] ?? 'unknown',
                                    'reject_context' => $execResult['error'] ?? null,
                                ]);
                            } elseif ($lifecycleState === 'deferred') {
                                // Deferred (wait_retrace): leave as claimed — bot will re-check next tick.
                                // This is the ONLY intentional non-terminal outcome for a claimed intent.
                            } else {
                                // Catch-all: unexpected or empty lifecycle state after execution.
                                // Finalize as rejected to prevent claimed intent from hanging indefinitely.
                                $this->updateLiveIntentStatus($iid, $liveIntentsFilePath, 'rejected', [
                                    'reject_reason' => 'rejected_unresolved_lifecycle',
                                    'reject_context' => 'lifecycle_state=' . ($lifecycleState ?: 'empty') . ', exec_status=' . ($execResult['status'] ?? 'unknown'),
                                ]);
                            }
                        }
                    }

                    // P6.11: Track selected decision for UI (first processed intent)
                    if ($result['selected_decision'] === null) {
                        $execStatus = $execResult['status'] ?? '';
                        $decisionType = 'error';
                        if (($execResult['opened'] ?? false) === true) {
                            $decisionType = 'opened';
                        } elseif (str_starts_with($execStatus, 'deferred_')) {
                            $decisionType = 'deferred';
                        } elseif (str_starts_with($execStatus, 'rejected_')) {
                            $decisionType = 'rejected';
                        }
                        
                        $decisionContext = [];
                        if ($decisionType === 'deferred') {
                            $ctx = $execResult['deferred_context'] ?? [];
                            $decisionContext = is_array($ctx) ? $ctx : [];
                        }
                        
                        $rejectedItem = null;
                        if ($decisionType === 'rejected') {
                            $intentId = $intent['id'] ?? ($intent['signal_id'] ?? null);
                            if (is_string($intentId) && $intentId !== '') {
                                $rejData = $this->store->loadRejectedIntentById($intentId);
                                $rejectedItem = !empty($rejData) ? $rejData : null;
                                if (is_array($rejData) && isset($rejData['context']) && is_array($rejData['context'])) {
                                    $decisionContext = $rejData['context'];
                                }
                            }
                        }
                        
                        $result['selected_decision'] = [
                            'type' => $decisionType,
                            'status' => $execStatus,
                            'reason' => $execResult['error'] ?? null,
                            'context' => $decisionContext,
                            'order_id' => $execResult['order_id'] ?? null,
                            'trade_id' => $execResult['trade_id'] ?? null,
                            'filled' => $execResult['filled'] ?? false,
                            'rejected_item' => $rejectedItem,
                        ];
                    }

                    
                    if ($execResult['opened']) {
                        $executedThisRun++;
                        $result['positions_opened']++;
                        $result['orders_sent']++;
                        if ($execResult['filled']) {
                            $result['orders_filled']++;
                        }
                    } else {
                        // P6.7: Distinguish rejected_* (soft reject) from real errors
                        // P6.9: Also handle deferred_* (wait_retrace not met yet)
                        $execStatus = $execResult['status'] ?? '';
                        
                        if (str_starts_with($execStatus, 'deferred_')) {
                            // P6.9: Deferred intent - NOT reject, NOT error
                            // Just a warning, will retry next run
                            // NOTE: intents_deferred is derived from intent_results post-processing
                            $deferredChecked++;
                            $reason = $execResult['deferred_reason'] ?? $execStatus;
                            if (!isset($deferredReasonCounts[$reason])) {
                                $deferredReasonCounts[$reason] = 0;
                            }
                            $deferredReasonCounts[$reason]++;
                        } elseif (str_starts_with($execStatus, 'rejected_')) {
                            // Soft reject: NOT an error, just a warning
                            // Do NOT increment orders_failed
                            // Do NOT add to $this->errors (safety-stop)
                            // NOTE: intents_rejected_exec is derived from intent_results post-processing
                            $executedThisRun++;
                            $this->warnings[] = "Rejected: {$execStatus} — " . ($execResult['error'] ?? 'no_reason_provided');
                        } else {
                            // Real execution error: counts towards safety-stop
                            // NOTE: intents_failed_exec is derived from intent_results post-processing
                            $executedThisRun++;
                            $result['orders_failed']++;
                            $this->errors[] = $execResult['error'] ?? 'Unknown execution error';
                        }
                    }
                }

                // Flush aggregated deferred warnings
                if (!empty($deferredReasonCounts)) {
                    foreach ($deferredReasonCounts as $r => $cnt) {
                        $suffix = ($cnt > 1) ? (' x' . $cnt) : '';
                        $this->warnings[] = 'Deferred: ' . $r . $suffix;
                    }
                }

            }
            
            $result['steps'][] = [
                'step' => 'execute_intents',
                'status' => 'ok',
                'opened' => $result['positions_opened'],
                'failed' => $result['orders_failed'],
                'processed' => $executedThisRun,
                'deferred_checked' => $deferredChecked,
                'max_execute_per_run' => $maxExecutePerRun,
                'max_deferred_per_run' => $maxDeferredPerRun,
            ];
            
            // Step 5: Update active positions
            // Capture active count before update for demo closure tracking
            $demoActiveCountBefore = $mode === 'demo' ? count($this->store->loadActiveTrades()) : 0;
            $updateResult = $this->updateActivePositions($mode);

            // Merge update_positions diagnostics into global warnings/errors (UI explainability)
            if (!empty($updateResult['warnings']) && is_array($updateResult['warnings'])) {
                foreach ($updateResult['warnings'] as $w) {
                    $this->warnings[] = (string)$w;
                }
            }
            if (!empty($updateResult['errors']) && is_array($updateResult['errors'])) {
                foreach ($updateResult['errors'] as $e) {
                    $this->errors[] = (string)$e;
                }
            }

            $result['positions_updated'] = $updateResult['updated'] ?? 0;
            $result['positions_closed'] = $updateResult['closed'] ?? 0;
            $result['positions_closed_by_logical_stop'] = $updateResult['closed_by_logical_stop'] ?? 0;
            $result['positions_closed_by_exchange'] = $updateResult['closed_by_exchange'] ?? 0;
            $result['break_even_applied_count'] = $updateResult['break_even_applied'] ?? 0;
            $result['hybrid_partial_applied_count'] = $updateResult['hybrid_partial_applied'] ?? 0;
            $result['floor_lock_applied_count'] = $updateResult['floor_lock_applied'] ?? 0;
            $result['floor_lock_failed_count'] = $updateResult['floor_lock_failed'] ?? 0;
            $result['profit_addon_applied_count'] = $updateResult['profit_addon_applied'] ?? 0;
            $result['profit_addon_failed_count'] = $updateResult['profit_addon_failed'] ?? 0;
            $result['profit_addon_checked_count'] = $updateResult['profit_addon_checked'] ?? 0;
            $result['profit_addon_trigger_reached_count'] = $updateResult['profit_addon_trigger_reached'] ?? 0;
            $result['profit_addon_eligible_count'] = $updateResult['profit_addon_eligible'] ?? 0;
            $result['profit_addon_attempted_count'] = $updateResult['profit_addon_attempted'] ?? 0;
            $result['profit_addon_too_small_count'] = $updateResult['profit_addon_too_small'] ?? 0;
            $result['profit_addon_skipped_count'] = $updateResult['profit_addon_skipped'] ?? 0;
            $result['profit_addon_skip_reason_distribution'] = $updateResult['profit_addon_skip_reason_distribution'] ?? [];
            $result['profit_addon_fail_reason_distribution'] = $updateResult['profit_addon_fail_reason_distribution'] ?? [];
            $result['reversal_overlay_candidates_seen_count'] = $updateResult['reversal_overlay_candidates_seen'] ?? 0;
            $result['reversal_overlay_activated_count'] = $updateResult['reversal_overlay_activated'] ?? 0;
            $result['reversal_overlay_step_advanced_count'] = $updateResult['reversal_overlay_step_advanced'] ?? 0;
            $result['reversal_overlay_skipped_wrong_pattern_count'] = $updateResult['reversal_overlay_skipped_wrong_pattern'] ?? 0;
            $result['reversal_overlay_skipped_wrong_side_count'] = $updateResult['reversal_overlay_skipped_wrong_side'] ?? 0;
            $result['reversal_overlay_skipped_peak_too_low_count'] = $updateResult['reversal_overlay_skipped_peak_too_low'] ?? 0;
            $result['reversal_overlay_shadow_mirror_seen_count'] = $updateResult['reversal_overlay_shadow_mirror_seen'] ?? 0;
            $result['reversal_overlay_harvest_applied_count'] = $updateResult['reversal_overlay_harvest_applied'] ?? 0;
            $result['steps'][] = [
                'step' => 'update_positions',
                'status' => 'ok',
                'updated' => $updateResult['updated'] ?? 0,
                'closed' => $updateResult['closed'] ?? 0,
                'closed_by_logical_stop' => $updateResult['closed_by_logical_stop'] ?? 0,
                'closed_by_exchange' => $updateResult['closed_by_exchange'] ?? 0,
                'trailing_applied' => $updateResult['trailing_applied'] ?? 0,
                'trailing_failed' => $updateResult['trailing_failed'] ?? 0,
                'trailing_skipped' => $updateResult['trailing_skipped'] ?? 0,
                'break_even_applied' => $updateResult['break_even_applied'] ?? 0,
                'hybrid_partial_applied' => $updateResult['hybrid_partial_applied'] ?? 0,
                'floor_lock_applied' => $updateResult['floor_lock_applied'] ?? 0,
                'floor_lock_failed' => $updateResult['floor_lock_failed'] ?? 0,
                'floor_lock_skipped' => $updateResult['floor_lock_skipped'] ?? 0,
                'profit_addon_applied' => $updateResult['profit_addon_applied'] ?? 0,
                'profit_addon_failed' => $updateResult['profit_addon_failed'] ?? 0,
                'profit_addon_skipped' => $updateResult['profit_addon_skipped'] ?? 0,
                'profit_addon_checked' => $updateResult['profit_addon_checked'] ?? 0,
                'profit_addon_trigger_reached' => $updateResult['profit_addon_trigger_reached'] ?? 0,
                'profit_addon_eligible' => $updateResult['profit_addon_eligible'] ?? 0,
                'profit_addon_attempted' => $updateResult['profit_addon_attempted'] ?? 0,
                'profit_addon_too_small' => $updateResult['profit_addon_too_small'] ?? 0,
                'profit_addon_skip_reason_distribution' => $updateResult['profit_addon_skip_reason_distribution'] ?? [],
                'profit_addon_fail_reason_distribution' => $updateResult['profit_addon_fail_reason_distribution'] ?? [],
                'reversal_overlay_candidates_seen' => $updateResult['reversal_overlay_candidates_seen'] ?? 0,
                'reversal_overlay_activated' => $updateResult['reversal_overlay_activated'] ?? 0,
                'reversal_overlay_step_advanced' => $updateResult['reversal_overlay_step_advanced'] ?? 0,
                'reversal_overlay_skipped_wrong_pattern' => $updateResult['reversal_overlay_skipped_wrong_pattern'] ?? 0,
                'reversal_overlay_skipped_wrong_side' => $updateResult['reversal_overlay_skipped_wrong_side'] ?? 0,
                'reversal_overlay_skipped_peak_too_low' => $updateResult['reversal_overlay_skipped_peak_too_low'] ?? 0,
                'reversal_overlay_shadow_mirror_seen' => $updateResult['reversal_overlay_shadow_mirror_seen'] ?? 0,
                'reversal_overlay_harvest_applied' => $updateResult['reversal_overlay_harvest_applied'] ?? 0,
                'effective_stop_zero_while_protected_count' => $updateResult['effective_stop_zero_while_protected_count'] ?? 0,
                'protection_source_missing_count' => $updateResult['protection_source_missing_count'] ?? 0,
                'best_price_missing_while_trailing_active_count' => $updateResult['best_price_missing_while_trailing_active_count'] ?? 0,
                'top_level_runtime_mismatch_count' => $updateResult['top_level_runtime_mismatch_count'] ?? 0,
            ];

            // ============================================================
            // Demo close pipeline per-run counters
            // ============================================================
            if ($mode === 'demo') {
                $demoActiveCountAfter = count($this->store->loadActiveTrades());
                $result['demo_trades_active_before']               = $demoActiveCountBefore;
                $result['demo_trades_opened_this_run']             = $result['positions_opened'] ?? 0;
                $result['demo_trades_closed_this_run']             = $updateResult['closed'] ?? 0;
                $result['demo_trades_still_active_after']          = $demoActiveCountAfter;
                $result['demo_trades_stale_this_run']              = $updateResult['stale_trades_found'] ?? 0;
                $result['demo_trades_reconciled_this_run']         = $updateResult['updated'] ?? 0;
                $result['demo_trades_finalized_from_exchange_this_run'] = $updateResult['finalized_from_exchange_this_run'] ?? 0;
                $result['demo_trades_finalized_locally_this_run']  = $updateResult['finalized_locally_this_run'] ?? 0;
                $result['demo_average_active_age_minutes']         = $updateResult['avg_active_age_minutes'] ?? null;
                $result['demo_oldest_active_trade_minutes']        = $updateResult['oldest_active_trade_minutes'] ?? null;
                $result['demo_ai_dataset_records_written_this_run']= $updateResult['ai_dataset_records_written'] ?? 0;
                $result['demo_close_failures_this_run']            = $updateResult['close_failures'] ?? 0;
                $result['demo_close_failure_reasons']              = $updateResult['close_failure_reasons'] ?? [];
                $result['top_stale_trade_reasons']                 = $updateResult['stale_trade_reasons'] ?? [];

                // ── PART 3: Open capacity diagnostics ───────────────────────
                $dlmCfgPost = is_array($this->config['demo_learning_mode'] ?? null)
                    ? $this->config['demo_learning_mode'] : [];
                $maxConcurrentDemoPos = ($dlmCfgPost['enabled'] ?? false)
                    ? (int)($dlmCfgPost['max_concurrent_demo_positions'] ?? 0)
                    : (int)($this->config['module']['max_concurrent_positions'] ?? 0);
                $openedThisRun = (int)($result['demo_trades_opened_this_run'] ?? 0);
                if ($maxConcurrentDemoPos > 0) {
                    $capacityAvailable = max(0, $maxConcurrentDemoPos - $demoActiveCountBefore);
                    $capacityUsed      = min($openedThisRun, $capacityAvailable);
                    $blockedByCap      = max(0, $openedThisRun === 0
                        ? (int)($result['demo_signals_attempted'] ?? 0) - $openedThisRun
                        : 0);
                    // More accurate: blocked_by_limits already tracks this
                    $blockedByCap = (int)($result['demo_signals_blocked_by_limits'] ?? 0);
                } else {
                    $capacityAvailable = -1; // unlimited / not enforced
                    $capacityUsed      = $openedThisRun;
                    $blockedByCap      = 0;
                }
                $result['demo_open_capacity_available']          = $capacityAvailable;
                $result['demo_open_capacity_used']               = $capacityUsed;
                $result['demo_open_blocked_by_capacity_count']   = $blockedByCap;

                // ── PART 4: Stale trade prioritization counters ──────────────
                $staleTotal = (int)($updateResult['stale_trades_found'] ?? 0);
                $staleFinalizedExchange = (int)($updateResult['finalized_from_exchange_this_run'] ?? 0);
                $staleFinalizedLocally  = (int)($updateResult['finalized_locally_this_run'] ?? 0);
                // Stale trades finalized = those that closed (either via exchange or local) that were stale
                $stalePrioritized = $staleTotal;
                $staleFinalized   = min($staleTotal, $staleFinalizedExchange + $staleFinalizedLocally);
                $staleRemaining   = max(0, $demoActiveCountAfter - ($demoActiveCountBefore - $staleFinalizedExchange - $staleFinalizedLocally));
                $result['demo_stale_trades_prioritized_this_run'] = $stalePrioritized;
                $result['demo_stale_trades_finalized_this_run']   = $staleFinalized;
                $result['demo_stale_trades_remaining_after_run']  = max(0, $demoActiveCountAfter);

                // ── PART 5: Per-run AI dataset consistency counters ──────────
                $closedThisRun   = (int)($result['demo_trades_closed_this_run'] ?? 0);
                $aiWrittenThisRun= (int)($result['demo_ai_dataset_records_written_this_run'] ?? 0);
                $closedWithoutAi = max(0, $closedThisRun - $aiWrittenThisRun);
                $aiMatchRateRun  = $closedThisRun > 0
                    ? round(($aiWrittenThisRun / $closedThisRun) * 100, 1)
                    : null;
                $result['demo_closed_trades_this_run']              = $closedThisRun;
                $result['demo_closed_without_ai_dataset_this_run']  = $closedWithoutAi;
                $result['demo_closed_to_ai_match_rate_this_run']    = $aiMatchRateRun;
            }

            // ============================================================
            // Post-process: upgrade intent result lifecycle states based on
            // active trade runtime. This enables trailing_active to become a
            // real reachable lifecycle state (not just listed).
            // ============================================================
            $activeTrades = $this->store->loadActiveTrades();
            $tradesBySymbolSide = [];
            foreach ($activeTrades as $t) {
                $key = ($t['symbol'] ?? '') . '_' . strtolower($t['side'] ?? '');
                $tradesBySymbolSide[$key] = $t;
            }
            foreach ($result['intent_results'] as &$ir) {
                if (in_array($ir['lifecycle_state'] ?? '', ['opened', 'protected'], true)) {
                    $key = ($ir['symbol'] ?? '') . '_' . strtolower($ir['side'] ?? '');
                    if (isset($tradesBySymbolSide[$key])) {
                        $trade = $tradesBySymbolSide[$key];
                        // Trailing is considered active when it has been applied to exchange,
                        // not merely enabled in config.
                        if ($this->isTrailingActive($trade)) {
                            $ir['lifecycle_state'] = 'trailing_active';
                            $ir['trailing_status'] = 'active';
                            // trailing_active is a stronger sub-state of protected
                            $ir['protection_status'] = 'protected';
                        }
                    }
                }
            }
            unset($ir);

            // ============================================================
            // Derive execution summary counts from finalized intent_results
            // (single source of truth — no drift between records and counts).
            // ============================================================
            $result['intents_processed'] = count($result['intent_results']);
            $result['intents_validation_passed_count'] = count($result['intent_results']);
            $result['intents_opened'] = 0;
            $result['intents_skipped'] = 0;
            $result['intents_deferred'] = 0;
            $result['intents_rejected_exec'] = 0;
            $result['intents_failed_exec'] = 0;
            $result['intents_rejected_late_entry_count'] = 0;
            $result['intents_rejected_late_entry_distribution'] = [];
            $result['intents_rejected_late_entry_preview'] = [];
            $result['rejection_reason_stats'] = [];
            $result['close_reason_stats'] = [];
            // Execution truth counters — only count real exchange interactions
            $result['intents_order_send_attempted_count'] = 0;
            $result['intents_order_sent_count'] = 0;
            $result['intents_exchange_accepted_count'] = 0;
            $result['intents_position_opened_count'] = 0;
            $result['intents_execution_rejected_count'] = 0;
            $result['intents_execution_guard_passed_count'] = 0;
            $result['intents_terminal_executed_count'] = 0;
            $result['intents_terminal_rejected_count'] = 0;
            $result['intents_terminal_failed_count'] = 0;
            foreach ($result['intent_results'] as $ir) {
                $ls = $ir['lifecycle_state'] ?? '';
                if (in_array($ls, ['opened', 'protected', 'trailing_active'], true)) {
                    $result['intents_opened']++;
                } elseif ($ls === 'deferred') {
                    $result['intents_deferred']++;
                    $result['intents_skipped']++;
                } elseif ($ls === 'skipped') {
                    $result['intents_skipped']++;
                } elseif ($ls === 'rejected') {
                    $result['intents_rejected_exec']++;
                } elseif ($ls === 'failed') {
                    $result['intents_failed_exec']++;
                }

                // Execution truth: count real order/position outcomes
                if (!empty($ir['order_send_attempted'])) {
                    $result['intents_order_send_attempted_count']++;
                }
                if (!empty($ir['order_sent'])) {
                    $result['intents_order_sent_count']++;
                    $result['intents_exchange_accepted_count']++;
                }
                if (!empty($ir['position_opened'])) {
                    $result['intents_position_opened_count']++;
                }

                // Execution rejection: intent processed but rejected before order send
                if (in_array($ls, ['rejected', 'failed'], true) && empty($ir['order_send_attempted'])) {
                    $result['intents_execution_rejected_count']++;
                }

                // Execution guard passed: intent reached beyond guard checks
                if (!empty($ir['execution_guard_passed'])) {
                    $result['intents_execution_guard_passed_count']++;
                }

                // Terminal status distribution
                $ts = $ir['terminal_status'] ?? '';
                if (strpos($ts, 'executed_') === 0) {
                    $result['intents_terminal_executed_count']++;
                } elseif (strpos($ts, 'rejected_') === 0) {
                    $result['intents_terminal_rejected_count']++;
                } elseif (strpos($ts, 'failed_') === 0) {
                    $result['intents_terminal_failed_count']++;
                }

                if (in_array($ls, ['rejected', 'failed'], true) && !empty($ir['rejection_reason'])) {
                    $rr = $ir['rejection_reason'];
                    $result['rejection_reason_stats'][$rr] = ($result['rejection_reason_stats'][$rr] ?? 0) + 1;

                    // Track late_entry rejections separately with sub-reasons and preview
                    if ($rr === 'rejected_late_entry') {
                        $result['intents_rejected_late_entry_count']++;
                        $subreason = $ir['reject_subreason'] ?? $ir['late_entry_subreason'] ?? 'rejected_late_entry_unspecified';
                        $result['intents_rejected_late_entry_distribution'][$subreason] =
                            ($result['intents_rejected_late_entry_distribution'][$subreason] ?? 0) + 1;
                        if (count($result['intents_rejected_late_entry_preview']) < 10) {
                            $result['intents_rejected_late_entry_preview'][] = [
                                'intent_id' => $ir['intent_id'] ?? null,
                                'symbol' => $ir['symbol'] ?? '',
                                'side' => $ir['side'] ?? '',
                                'rejection_reason' => $rr,
                                'reject_subreason' => $subreason,
                                'late_entry_diagnostics' => $ir['late_entry_diagnostics'] ?? [],
                            ];
                        }
                    }
                }
                if (!empty($ir['close_reason'])) {
                    $cr = $ir['close_reason'];
                    $result['close_reason_stats'][$cr] = ($result['close_reason_stats'][$cr] ?? 0) + 1;
                }
            }

            // Stale claim counters for telemetry
            $result['intents_claimed_stale_count'] = $staleClaimResult['stale_claimed_found'] ?? 0;
            $result['intents_claimed_finalized_count'] = $staleClaimResult['finalized_count'] ?? 0;

            // ── Honest lifecycle telemetry ──────────────────────────────
            // Reload live_intents.json to get current lifecycle state after
            // all status updates, stale-claim finalization, and execution.
            // This provides accurate pending/claimed/executed/rejected counts
            // that distinguish "no pending now" from "no approved ever".
            $currentLifecycleCounts = ['pending' => 0, 'claimed' => 0, 'executed' => 0, 'rejected' => 0, 'expired' => 0, 'total' => 0];
            if ($brainControlled && !empty($liveIntentsFilePath) && is_file($liveIntentsFilePath)) {
                $liveData = @json_decode(@file_get_contents($liveIntentsFilePath), true);
                if (is_array($liveData) && !empty($liveData['intents'])) {
                    foreach ($liveData['intents'] as $_li) {
                        $currentLifecycleCounts['total']++;
                        $_ls = $_li['status'] ?? 'pending';
                        if (isset($currentLifecycleCounts[$_ls])) {
                            $currentLifecycleCounts[$_ls]++;
                        }
                    }
                }
            }
            $result['lifecycle_current_counts'] = $currentLifecycleCounts;
            $result['intents_pending_current_count'] = $currentLifecycleCounts['pending'];
            $result['intents_claimed_current_count'] = $currentLifecycleCounts['claimed'];
            $result['intents_executed_current_count'] = $currentLifecycleCounts['executed'];
            $result['intents_rejected_current_count'] = $currentLifecycleCounts['rejected'];
            $result['intents_expired_current_count'] = $currentLifecycleCounts['expired'];

            // Diagnostic: count claimed intents left without terminal commit in this run.
            // After a healthy run this should be 0 (only deferred intents may remain claimed).
            $claimedWithoutTerminal = 0;
            foreach ($result['intent_results'] as $ir) {
                $irLifecycle = $ir['lifecycle_state'] ?? '';
                // If an intent was processed but left in a non-terminal, non-deferred state
                if (!in_array($irLifecycle, ['opened', 'protected', 'trailing_active', 'rejected', 'failed', 'closed', 'deferred', 'skipped'], true)) {
                    $claimedWithoutTerminal++;
                }
            }
            $result['claimed_without_terminal_commit_count'] = $claimedWithoutTerminal;

            // ── Deferred no-pending warning ──────────────────────────────
            // Emit AFTER final lifecycle cleanup so the message reflects the
            // actual post-cleanup state (e.g. stale-claimed → rejected) rather
            // than the pre-load snapshot stored in $lifecycleSkipped.
            if ($noPendingWarningDeferred) {
                $fcClaimed  = $currentLifecycleCounts['claimed'];
                $fcExecuted = $currentLifecycleCounts['executed'];
                $fcRejected = $currentLifecycleCounts['rejected'];
                $fcExpired  = $currentLifecycleCounts['expired'];
                $fcNonPendingTotal = $fcClaimed + $fcExecuted + $fcRejected + $fcExpired;
                if ($fcNonPendingTotal > 0) {
                    $fcParts = [];
                    if ($fcClaimed  > 0) $fcParts[] = $fcClaimed  . ' claimed';
                    if ($fcExecuted > 0) $fcParts[] = $fcExecuted . ' executed';
                    if ($fcRejected > 0) $fcParts[] = $fcRejected . ' rejected';
                    if ($fcExpired  > 0) $fcParts[] = $fcExpired  . ' expired';
                    $this->warnings[] = 'Brain-controlled mode active: no pending intents available. Existing intents: ' . implode(', ', $fcParts) . '. Legacy fallback disabled.';
                } else {
                    $this->warnings[] = 'Brain-controlled mode active: approved live intents = 0. No trades executed. Legacy fallback disabled.';
                }
                $noPendingWarningDeferred = false;
            }

            // ============================================================
            // P6: ROI Expectancy Metrics
            // Compute average win, average loss, winrate, expectancy
            // from closed trade results for measurable ROI analysis.
            // ============================================================
            $closedTrades = $this->store->loadClosedTrades(200);
            $expectancyMetrics = $this->computeExpectancyMetrics($closedTrades);
            $result['expectancy_metrics'] = $expectancyMetrics;

            // ============================================================
            // P7: Per-symbol exit statistics
            // Comprehensive per-symbol stop/trailing/break-even behavior,
            // exit reason distribution, side split, robust stats.
            // ============================================================
            $result['symbol_exit_stats'] = $this->computePerSymbolExitStats($closedTrades);

            // ============================================================
            // Demo Data Sufficiency Metrics
            // Track completeness and AI readiness of local demo dataset.
            // Computed every demo run so Brain always has current counts.
            // ============================================================
            if ($mode === 'demo') {
                $demoSufficiency = $this->computeDemoSufficiencyMetrics();
                $result['demo_closed_trades_total']        = $demoSufficiency['demo_closed_trades_total'];
                $result['demo_closed_trades_complete']     = $demoSufficiency['demo_closed_trades_complete'];
                $result['demo_closed_trades_complete_rate']= $demoSufficiency['demo_closed_trades_complete_rate'];
                $result['ai_dataset_ready']                = $demoSufficiency['ai_dataset_ready'];
                $result['ai_dataset_ready_reason']         = $demoSufficiency['ai_dataset_ready_reason'];
                $result['demo_ai_dataset_records']         = $demoSufficiency['ai_dataset_records'];
                $result['demo_data_sufficiency']           = $demoSufficiency;
                // Persist to dedicated file so Brain can read it regardless of current mode
                $this->store->saveDemoSufficiency($demoSufficiency);

                // --------------------------------------------------------
                // Demo Truth Audit — derive ground-truth metrics directly
                // from storage files. Classifies the primary bottleneck.
                // --------------------------------------------------------
                $learningMaxAgeMin = (int)(
                    $this->config['demo_learning_mode']['learning_max_active_age_minutes'] ?? 240
                );
                // Compute orphan-blocking count from rejection stats so the audit can
                // use real runtime evidence when classifying the primary bottleneck.
                $demoOrphanBlockingCount = 0;
                foreach (['orphan_exchange_position_open_local_missing', 'orphan_exchange_position_stale_unreconciled', 'skipped_exchange_position_exists'] as $_or) {
                    $demoOrphanBlockingCount += (int)($result['rejection_reason_stats'][$_or] ?? 0);
                }
                $result['demo_orphan_positions_detected_count'] = $demoOrphanBlockingCount;
                $result['demo_orphan_positions_blocking_count'] = $demoOrphanBlockingCount;
                $demoTruthAudit = $this->store->computeDemoTruthAudit(
                    $learningMaxAgeMin,
                    $demoOrphanBlockingCount,
                    (int)($result['demo_feed_available_count'] ?? 0),
                    (int)($result['demo_feed_selected_count'] ?? 0),
                    (int)($result['positions_opened'] ?? 0),
                    (int)($result['demo_trades_closed_this_run'] ?? 0)
                );
                $result['demo_truth_audit']              = $demoTruthAudit;
                $result['primary_demo_bottleneck']       = $demoTruthAudit['primary_demo_bottleneck'];
                $result['primary_demo_bottleneck_reason']= $demoTruthAudit['primary_demo_bottleneck_reason'];
                $result['recommended_next_fix_area']     = $demoTruthAudit['recommended_next_fix_area'];
                $result['demo_primary_execution_blocker']= $demoTruthAudit['primary_execution_blocker'] ?? 'none';

                // ── PART 5: Per-run AI match-rate also in sufficiency ────────
                $result['demo_closed_to_ai_match_rate_total'] = $demoTruthAudit['closed_to_ai_dataset_match_rate'] ?? null;
                $result['demo_closed_without_ai_dataset_total'] = $demoTruthAudit['closed_trades_without_ai_dataset_count'] ?? 0;

                // ── PART 6: Closure bottleneck fields ────────────────────────
                $result['primary_demo_closure_bottleneck']        = $demoTruthAudit['primary_demo_bottleneck'];
                $result['primary_demo_closure_bottleneck_reason'] = $demoTruthAudit['primary_demo_bottleneck_reason'];
                $result['recommended_turnover_fix_area']          = $demoTruthAudit['recommended_next_fix_area'];

                // Merge consistency fields into sufficiency for downstream reads
                $demoSufficiency['closed_trades_without_ai_dataset_count'] = $demoTruthAudit['closed_trades_without_ai_dataset_count'];
                $demoSufficiency['ai_dataset_without_closed_trade_count']  = $demoTruthAudit['ai_dataset_without_closed_trade_count'];
                $demoSufficiency['closed_to_ai_dataset_match_rate']        = $demoTruthAudit['closed_to_ai_dataset_match_rate'];
                $demoSufficiency['primary_demo_bottleneck']                = $demoTruthAudit['primary_demo_bottleneck'];
                $demoSufficiency['primary_demo_bottleneck_reason']         = $demoTruthAudit['primary_demo_bottleneck_reason'];
                $demoSufficiency['recommended_next_fix_area']              = $demoTruthAudit['recommended_next_fix_area'];
                // Also persist per-run stats into sufficiency for UI
                $demoSufficiency['demo_closed_trades_this_run']             = $result['demo_closed_trades_this_run'] ?? 0;
                $demoSufficiency['demo_ai_dataset_records_written_this_run']= $result['demo_ai_dataset_records_written_this_run'] ?? 0;
                $demoSufficiency['demo_closed_without_ai_dataset_this_run'] = $result['demo_closed_without_ai_dataset_this_run'] ?? 0;
                $demoSufficiency['demo_closed_to_ai_match_rate_this_run']   = $result['demo_closed_to_ai_match_rate_this_run'] ?? null;
                $this->store->saveDemoSufficiency($demoSufficiency);
                $this->store->saveDemoTruthAudit($demoTruthAudit);
            }

            // ============================================================
            // P0.3: Exchange submit visibility counters
            // Derived from finalized intent_results (single source of truth).
            // ============================================================
            $exchangeSubmitAttempted = 0;
            $exchangeSubmitFailed = 0;
            $exchangeSubmitSuccess = 0;
            $positionOpenConfirmed = 0;
            $protectionApplyFailed = 0;
            $latestExchangeErrorCode = null;
            $latestExchangeErrorMessage = null;
            $lastFailedSymbol = null;
            $lastFailedStage = null;
            $executionGuardBlockedCount = 0;
            $executionStageStats = [];
            $noOrderPathPreview = [];

            foreach ($result['intent_results'] as $ir) {
                // Exchange submit tracking
                if (!empty($ir['exchange_submit_attempted'])) {
                    $exchangeSubmitAttempted++;
                    $ls = $ir['lifecycle_state'] ?? '';
                    if (in_array($ls, ['opened', 'protected', 'trailing_active'], true)) {
                        $exchangeSubmitSuccess++;
                    } elseif (in_array($ls, ['rejected', 'failed'], true)) {
                        $exchangeSubmitFailed++;
                    }
                }

                // Position open confirmed
                $stage = $ir['execution_stage'] ?? '';
                if (in_array($stage, ['position_open_confirmed', 'protection_apply_started', 'finished'], true)) {
                    $positionOpenConfirmed++;
                }
                if ($stage === 'protection_apply_failed') {
                    $protectionApplyFailed++;
                }
                if ($stage === 'execution_guard_blocked') {
                    $executionGuardBlockedCount++;
                }

                // Execution stage stats
                if ($stage !== '') {
                    $executionStageStats[$stage] = ($executionStageStats[$stage] ?? 0) + 1;
                }

                // P0.4: Latest exchange error (last encountered)
                $irExchangeCode = $ir['exchange_response_code'] ?? null;
                $irExchangeMessage = $ir['exchange_response_message'] ?? null;
                if ($irExchangeCode !== null || $irExchangeMessage !== null) {
                    $latestExchangeErrorCode = $irExchangeCode;
                    $latestExchangeErrorMessage = $irExchangeMessage;
                    $lastFailedSymbol = $ir['symbol'] ?? null;
                    $lastFailedStage = $stage;
                }

                // P0.9: No-order-path debug preview (first 5 not-opened intents)
                $ls = $ir['lifecycle_state'] ?? '';
                if (!in_array($ls, ['opened', 'protected', 'trailing_active'], true) && count($noOrderPathPreview) < 5) {
                    $noOrderPathPreview[] = [
                        'symbol' => $ir['symbol'] ?? '',
                        'side' => $ir['side'] ?? '',
                        'intent_id' => $ir['intent_id'] ?? null,
                        'pattern_algorithm' => $ir['pattern_algorithm'] ?? '',
                        'final_outcome' => $ls,
                        'terminal_status' => $ir['terminal_status'] ?? '',
                        'execution_stage' => $stage,
                        'execution_stage_at_failure' => $stage,
                        'main_reason' => $ir['rejection_reason'] ?? ($ir['debug_message'] ?? ''),
                        'reject_subreason' => $ir['reject_subreason'] ?? null,
                        'validation_passed' => !in_array($ir['execution_stage'] ?? '', ['validation_failed', 'missing_required_fields'], true),
                        'order_send_attempted' => !empty($ir['order_send_attempted']),
                        'order_sent' => !empty($ir['order_sent']),
                        'position_opened' => !empty($ir['position_opened']),
                        'exchange_attempted' => !empty($ir['exchange_submit_attempted']),
                        'claimed_at' => $ir['processed_at'] ?? null,
                    ];
                }
            }

            // P0.5: executable_after_dedupe = loaded - duplicate_skipped
            $result['executable_after_dedupe'] = max(0, ($result['approved_intents_loaded'] ?? 0) - ($result['duplicate_skipped'] ?? 0));

            // P0.6: busy_skipped = count of symbol_busy + active_trade_exists + exchange_position_exists + max_positions_reached
            // Includes demo-specific orphan reason codes that replaced the generic skipped_exchange_position_exists.
            $busyReasons = [
                'skipped_symbol_busy',
                'skipped_active_trade_exists',
                'skipped_exchange_position_exists',
                'orphan_exchange_position_open_local_missing',
                'orphan_exchange_position_stale_unreconciled',
                'skipped_max_positions_reached',
            ];
            $busySkipped = 0;
            foreach ($busyReasons as $br) {
                $busySkipped += ($result['rejection_reason_stats'][$br] ?? 0);
            }
            $result['busy_skipped'] = $busySkipped;
            $result['executable_after_busy'] = max(0, ($result['executable_after_dedupe'] ?? 0) - $busySkipped);

            // P0.3: Exchange submit visibility
            $result['exchange_submit_attempted_count'] = $exchangeSubmitAttempted;
            $result['exchange_submit_failed_count'] = $exchangeSubmitFailed;
            $result['exchange_submit_success_count'] = $exchangeSubmitSuccess;
            $result['position_open_confirmed_count'] = $positionOpenConfirmed;
            $result['protection_apply_failed_count'] = $protectionApplyFailed;
            $result['execution_guard_blocked_count'] = $executionGuardBlockedCount;
            $result['execution_stage_stats'] = $executionStageStats;

            // P0.4: Latest exchange error visibility in runtime
            $result['latest_exchange_error_code'] = $latestExchangeErrorCode;
            $result['latest_exchange_error_message'] = $latestExchangeErrorMessage;
            $result['last_failed_symbol'] = $lastFailedSymbol;
            $result['last_failed_stage'] = $lastFailedStage;

            // P0.9: No-order-path debug preview
            $result['no_order_path_preview'] = $noOrderPathPreview;

            // ============================================================
            // Demo pipeline open counters
            // Derived from rejection_reason_stats and intent result counts.
            // Only emitted in demo mode to avoid cluttering live runs.
            // ============================================================
            if ($mode === 'demo') {
                $limitBlockReasons = [
                    'rejected_limits',
                    'skipped_max_positions_reached',
                    'rejected_max_positions_reached',
                    'skipped_active_trade_exists',
                    'rejected_active_trade_exists',
                    'skipped_one_per_symbol',
                    'rejected_one_per_symbol',
                ];
                $demoBlockedByLimits = 0;
                foreach ($limitBlockReasons as $lr) {
                    $demoBlockedByLimits += (int)($result['rejection_reason_stats'][$lr] ?? 0);
                }
                $demoOpened           = (int)($result['intents_opened']             ?? 0);
                $demoAttempted        = (int)($result['intents_processed']          ?? 0);
                $demoValidRejected    = (int)($result['intents_rejected']           ?? 0);
                $demoExchangeBlocked  = (int)($result['exchange_submit_failed_count'] ?? 0);
                // Other blocks = attempted minus opens minus limit-blocks minus exchange-blocks
                $demoOtherBlocked = max(0,
                    $demoAttempted - $demoOpened - $demoBlockedByLimits - $demoExchangeBlocked
                    - (int)($result['intents_deferred'] ?? 0)
                );

                $result['demo_signals_attempted']             = $demoAttempted;
                $result['demo_signals_opened']                = $demoOpened;
                $result['demo_signals_blocked_by_limits']     = $demoBlockedByLimits;
                $result['demo_signals_blocked_by_validation'] = $demoValidRejected;
                $result['demo_signals_blocked_by_exchange']   = $demoExchangeBlocked;
                $result['demo_signals_blocked_other']         = $demoOtherBlocked;
            }

            // ============================================================
            // Active protection summary (normalized detection).
            // trailing_active is a stronger sub-state of protected:
            //   protected_positions_count includes trailing_active trades.
            //   trailing_active_count is a narrower subcount.
            // ============================================================
            $protectedCount = 0;
            $trailingActiveCount = 0;
            $protectionErrorsCount = 0;
            $breakEvenArmedCount = 0;
            $breakEvenAppliedCount = 0;
            $floorLockActiveCount = 0;
            $effectiveStopZeroCount = 0;
            $protectionSourceMissingCount = 0;
            $bestPriceMissingCount = 0;
            $activePositionProtectionDetails = [];
            $contractGenerationCounts = [];
            $legacyActiveTradesCount = 0;
            $currentContractActiveTradesCount = 0;
            $migratedActiveTradesCount = 0;
            foreach ($activeTrades as $t) {
                $rt = is_array($t['runtime'] ?? null) ? $t['runtime'] : [];
                $prot = is_array($t['protection'] ?? null) ? $t['protection'] : [];
                $risk = is_array($t['risk'] ?? null) ? $t['risk'] : [];
                $trailing = is_array($risk['trailing'] ?? null) ? $risk['trailing'] : [];

                // Protected = SL price is set (either in protection block or from exchange)
                if ((float)($prot['stop_loss_price'] ?? 0) > 0) {
                    $protectedCount++;
                }

                // Trailing active: normalized detection via helper
                $isTrailingActive = $this->isTrailingActive($t);
                if ($isTrailingActive) {
                    $trailingActiveCount++;
                }

                // Break-even detection
                $beEnabled = (bool)($trailing['break_even_enabled'] ?? false);
                $beArmed = (bool)($rt['break_even_armed'] ?? false);
                $beApplied = (bool)($rt['break_even_applied'] ?? false);
                if ($beArmed) $breakEvenArmedCount++;
                if ($beApplied) $breakEvenAppliedCount++;

                // Floor lock detection
                if (!empty($rt['floor_lock_active']) || !empty($t['floor_lock_active'])) {
                    $floorLockActiveCount++;
                }

                // Diagnostic counters
                if (!empty($rt['warning_effective_stop_zero_while_protected'])) {
                    $effectiveStopZeroCount++;
                }
                if (!empty($rt['warning_protection_source_missing'])) {
                    $protectionSourceMissingCount++;
                }
                if (!empty($rt['warning_best_price_missing'])) {
                    $bestPriceMissingCount++;
                }

                // Protection errors: SL repair attempted but failed
                if (!empty($rt['sl_repair_attempted']) && empty($rt['sl_repair_result']['ok'])) {
                    $protectionErrorsCount++;
                }
                // Also count trailing failures as protection errors
                if (!empty($rt['dumb_trailing_last_error'])) {
                    $protectionErrorsCount++;
                }

                // Contract generation tracking (Part 5-6: per-trade generation stats)
                $openedWithGen = (string)($t['opened_with_contract_generation'] ?? 'legacy_unknown');
                $currentGen = (string)($t['current_effective_contract_generation'] ?? 'legacy_unknown');
                $isMigrated = (bool)($t['contract_migrated'] ?? false);
                $contractGenerationCounts[$openedWithGen] = ($contractGenerationCounts[$openedWithGen] ?? 0) + 1;
                if ($isMigrated) {
                    $migratedActiveTradesCount++;
                }

                // Per-trade protection state detail (for audit)
                $activePositionProtectionDetails[] = [
                    'symbol' => $t['symbol'] ?? '',
                    'side' => $t['side'] ?? '',
                    'entry_price' => (float)($t['entry_price'] ?? 0),
                    'stop_loss_applied' => (float)($prot['stop_loss_price'] ?? 0) > 0,
                    'trailing_enabled' => (bool)($trailing['enabled'] ?? false),
                    'trailing_active' => $isTrailingActive,
                    'trailing_mode' => (string)($trailing['trailing_mode'] ?? 'roi_giveback'),
                    'trailing_activation_roi_pct' => (float)($trailing['activation_roi_pct'] ?? 0),
                    'trailing_drawdown_factor' => (float)($trailing['drawdown_factor'] ?? 0),
                    'trailing_price_distance_pct' => ($trailing['trailing_price_distance_pct'] ?? null),
                    'trailing_distance_roi' => ($rt['trailing_distance_roi'] ?? ($trailing['trailing_distance_roi'] ?? null)),
                    'trailing_preset_mode' => (string)($rt['trailing_preset_mode'] ?? ($trailing['trailing_preset_mode'] ?? ($t['effective_trailing_preset_mode'] ?? 'custom'))),
                    'trailing_activation_floor_roi' => (float)($trailing['trailing_activation_floor_roi'] ?? ($t['effective_trailing_activation_floor_roi'] ?? 0)),
                    'trailing_floor_lock_roi' => (float)($trailing['trailing_floor_lock_roi'] ?? ($t['effective_trailing_floor_lock_roi'] ?? 0)),
                    'trailing_contract_source' => (string)($trailing['trailing_contract_source'] ?? ($rt['trailing_contract_source'] ?? '')),
                    'break_even_enabled' => $beEnabled,
                    'break_even_activation_roi' => (float)($trailing['break_even_activation_roi'] ?? 0),
                    'break_even_armed' => $beArmed,
                    'break_even_applied' => $beApplied,
                    'exit_mode' => (string)($trailing['exit_mode'] ?? ($rt['effective_exit_mode'] ?? '')),
                    'fixed_take_profit_roi' => (float)($trailing['fixed_take_profit_roi'] ?? 0),
                    'hybrid_tp_share' => (float)($trailing['hybrid_tp_share'] ?? 0),
                    'best_roi_seen' => (float)($rt['best_roi_seen'] ?? 0),
                    'protection_state' => (string)($rt['protection_state'] ?? 'unknown'),
                    'effective_trailing_contract_source' => (string)($rt['effective_trailing_contract_source'] ?? ($trailing['trailing_contract_source'] ?? ($trailing['effective_trailing_contract_source'] ?? 'unknown'))),
                    'trailing_stop_price' => (float)($prot['trailing_stop'] ?? 0),
                    'logical_stop_enabled' => (bool)($risk['logical_stop']['enabled'] ?? false),
                    'logical_stop_roi' => (float)($risk['logical_stop']['logical_stop_roi'] ?? 0),
                    'open_since' => $t['opened_at'] ?? $t['created_at'] ?? null,
                    // Contract generation per-trade (Part 6)
                    'opened_with_exit_mode' => (string)($t['opened_with_exit_mode'] ?? 'unknown'),
                    'contract_generation' => $openedWithGen,
                    'current_effective_contract_generation' => $currentGen,
                    'contract_migrated' => $isMigrated,
                    // Stop control mode per-trade
                    'stop_control_mode' => (string)($prot['stop_control_mode'] ?? ($risk['stop_control']['stop_control_mode'] ?? 'auto')),
                    'stop_loss_from_entry_roi' => ($prot['stop_control_mode'] ?? ($risk['stop_control']['stop_control_mode'] ?? 'auto')) === 'entry_roi'
                        ? (float)($prot['stop_loss_from_entry_roi'] ?? ($risk['stop_control']['stop_loss_from_entry_roi'] ?? 0))
                        : null,
                    // Computed stop price (operator observability)
                    'effective_stop_price' => (float)($prot['stop_loss_price'] ?? 0) > 0
                        ? round((float)$prot['stop_loss_price'], 8)
                        : null,
                    // Initial vs current stop separation
                    'initial_computed_stop_price' => $t['initial_computed_stop_price'] ?? ($t['runtime']['initial_computed_stop_price'] ?? null),
                    'stop_moved_from_initial' => (bool)($t['stop_moved_from_initial'] ?? ($t['runtime']['stop_moved_from_initial'] ?? false)),
                    // Floor lock fields (price_distance_floor mode)
                    'floor_lock_active' => (bool)($rt['floor_lock_active'] ?? ($t['floor_lock_active'] ?? false)),
                    'floor_locked_roi' => (float)($rt['floor_locked_roi'] ?? ($t['floor_locked_roi'] ?? 0)),
                    'floor_stop_price' => (float)($rt['floor_stop_price'] ?? ($t['floor_stop_price'] ?? 0)),
                    'current_effective_stop_price' => (float)($rt['current_effective_stop_price'] ?? ($t['current_effective_stop_price'] ?? 0)),
                    'protection_source_of_truth' => (string)($rt['protection_source_of_truth'] ?? ($t['protection_source_of_truth'] ?? '')),
                    'floor_enforced_via_exchange_stop' => (bool)($rt['floor_enforced_via_exchange_stop'] ?? ($t['floor_enforced_via_exchange_stop'] ?? false)),
                    'floor_enforced_via_bot_exit' => (bool)($rt['floor_enforced_via_bot_exit'] ?? ($t['floor_enforced_via_bot_exit'] ?? false)),
                    // Break-even stop price
                    'break_even_stop_price' => (float)($rt['break_even_stop_price'] ?? ($t['break_even_stop_price'] ?? 0)),
                    // Best price / trailing reference
                    'best_price' => (float)($rt['best_price'] ?? ($t['best_price'] ?? 0)),
                    'trailing_reference_price' => (float)($rt['trailing_reference_price'] ?? ($t['trailing_reference_price'] ?? 0)),
                    // Sync timestamps
                    'last_protection_update_at' => $rt['last_protection_update_at'] ?? ($t['last_protection_update_at'] ?? null),
                    'last_top_level_mirror_sync_at' => $t['last_top_level_mirror_sync_at'] ?? null,
                    // Diagnostic warnings
                    'warning_effective_stop_zero_while_protected' => (bool)($rt['warning_effective_stop_zero_while_protected'] ?? false),
                    'warning_protection_source_missing' => (bool)($rt['warning_protection_source_missing'] ?? false),
                    'warning_best_price_missing' => (bool)($rt['warning_best_price_missing'] ?? false),
                    'warning_floor_lock_active_but_not_enforced' => (bool)($rt['warning_floor_lock_active_but_not_enforced'] ?? false),
                ];
            }
            $result['active_protection_summary'] = [
                'active_positions_count' => count($activeTrades),
                'protected_positions_count' => $protectedCount,
                'trailing_active_count' => $trailingActiveCount,
                'break_even_armed_count' => $breakEvenArmedCount,
                'break_even_applied_count' => $breakEvenAppliedCount,
                'floor_lock_active_count' => $floorLockActiveCount,
                'protection_errors_count' => $protectionErrorsCount,
                'effective_stop_zero_while_protected_count' => $effectiveStopZeroCount,
                'protection_source_missing_count' => $protectionSourceMissingCount,
                'best_price_missing_while_trailing_active_count' => $bestPriceMissingCount,
            ];
            $result['active_position_protection_details'] = $activePositionProtectionDetails;

            // Expose effective_stop_price from first active trade for runtime-level observability
            if (!empty($activePositionProtectionDetails)) {
                $firstTradeProt = $activePositionProtectionDetails[0];
                $result['effective_stop_price'] = $firstTradeProt['effective_stop_price'] ?? null;
                $result['initial_computed_stop_price'] = $firstTradeProt['initial_computed_stop_price'] ?? null;
                $result['stop_moved_from_initial'] = (bool)($firstTradeProt['stop_moved_from_initial'] ?? false);
            } else {
                $result['effective_stop_price'] = null;
                $result['initial_computed_stop_price'] = null;
                $result['stop_moved_from_initial'] = false;
            }

            // Contract generation mix stats (Part 5: operator must see mixed generations)
            // Determine what "current" generation is (from effective trailing contract in this run)
            $effectiveExitModeNow = (string)($result['effective_exit_mode'] ?? 'unknown');
            $botCurrentGeneration = $this->deriveContractGeneration($effectiveExitModeNow);
            foreach ($contractGenerationCounts as $gen => $cnt) {
                if ($gen === $botCurrentGeneration) {
                    $currentContractActiveTradesCount += $cnt;
                } else {
                    $legacyActiveTradesCount += $cnt;
                }
            }
            $result['active_trade_contract_generation_stats'] = [
                'generation_counts' => $contractGenerationCounts,
                'current_bot_generation' => $botCurrentGeneration,
                'current_contract_active_trades_count' => $currentContractActiveTradesCount,
                'legacy_active_trades_count' => $legacyActiveTradesCount,
                'migrated_active_trades_count' => $migratedActiveTradesCount,
                'mixed_generations' => count($contractGenerationCounts) > 1,
            ];
            
            // Step 5b: Rebuild lifecycle_summary in live_intents.json
            // After all intent status updates (executed/rejected) and stale-claim
            // finalization, the top-level lifecycle_summary may be stale.
            // Rebuild it from actual intents array to ensure truth.
            if ($brainControlled && !empty($liveIntentsFilePath)) {
                $this->rebuildLifecycleSummary($liveIntentsFilePath);
            }

            // Step 6: Safety checks
            $safetyResult = $this->performSafetyChecks();
            $result['steps'][] = [
                'step' => 'safety_checks',
                'status' => $safetyResult['ok'] ? 'ok' : 'warning',
                'checks_passed' => $safetyResult['checks_passed'] ?? 0,
                'checks_failed' => $safetyResult['checks_failed'] ?? 0,
            ];
            
        } catch (\Throwable $e) {
            $result['ok'] = false;
            $result['status'] = 'error';
            $this->errors[] = 'Exception: ' . $e->getMessage();
            $this->logError('execute', $e->getMessage(), ['trace' => $e->getTraceAsString()]);
        } finally {
            // Release run-lock
            if ($lockFp !== null) {
                $this->releaseRunLock($lockFp);
            }
        }
        // P6.11: Balance snapshot for UI (sticky between runs) — applies to all real exchange modes
        if ($mode === 'live' || $mode === 'demo') {
            if (is_array($this->balanceCache) && !empty($this->balanceCache)) {
                $result['balance_snapshot_last'] = $this->balanceCache;
                $result['balance_snapshot_ts'] = (int)($this->balanceCacheTs ?? 0);
            } else {
                $prev = $this->store->loadLastRun();
                if (is_array($prev) && isset($prev['balance_snapshot_last']) && $result['balance_snapshot_last'] === null) {
                    $result['balance_snapshot_last'] = $prev['balance_snapshot_last'];
                    $result['balance_snapshot_ts'] = (int)($prev['balance_snapshot_ts'] ?? 0);
                }
            }
        }

        // Finalize result
        $result['errors_count'] = count($this->errors);
        $result['errors'] = $this->errors;
        $result['warnings'] = $this->warnings;
        $result['duration_ms'] = (int)round((microtime(true) - $startTime) * 1000);
        
        // P6.7: Calculate total rejected intents (validation + execution)
        $result['intents_rejected_total'] = ($result['intents_rejected'] ?? 0) + ($result['intents_rejected_exec'] ?? 0);
        
        if ($result['errors_count'] > 0) {
            $result['status'] = 'ok_with_errors';
        }
        
        // B4: Save errors to errors.json
        if (!empty($this->errors)) {
            $this->store->saveErrors($this->errors);
        }

        // Write aggregate UI snapshots so Brain Execution page stays in sync
        $this->store->writeRuntimeSnapshot();

        // Save last run
        $this->store->saveLastRun($result);
        
        return $result;
    }

    /**
     * P6.11: Summarize intent for UI preview (safe compact fields)
     *
     * @param array<string,mixed> $intent
     * @return array<string,mixed>
     */
    private function summarizeIntent(array $intent): array
    {
        $risk = is_array($intent['risk'] ?? null) ? $intent['risk'] : [];
        $limits = is_array($risk['limits'] ?? null) ? $risk['limits'] : [];
        $trailing = is_array($risk['trailing'] ?? null) ? $risk['trailing'] : [];

        return [
            'id' => $intent['id'] ?? null,
            'signal_id' => $intent['signal_id'] ?? null,
            'intent_id' => $intent['intent_id'] ?? null,
            'execution_identity_key' => $intent['execution_identity_key'] ?? ($intent['intent_id'] ?? ($intent['signal_id'] ?? null)),
            'dedupe_basis' => $intent['dedupe_basis'] ?? (!empty($intent['brain_controlled']) ? 'intent_id' : 'legacy_signal_id'),
            'source_signal_id' => $intent['signal_id'] ?? null,
            'symbol' => $intent['symbol'] ?? null,
            'side' => $intent['side'] ?? null,
            'entry_action' => $intent['entry_action'] ?? null,
            'entry_price' => $intent['entry_price'] ?? null,
            'created_ts' => $intent['created_ts'] ?? null,
            'expires_at' => $intent['expires_at'] ?? null,
            'brain_controlled' => !empty($intent['brain_controlled']),
            'risk' => [
                'profile_id' => $risk['profile_id'] ?? null,
                'budget_usdt_per_trade' => $risk['budget_usdt_per_trade'] ?? null,
                'leverage' => $risk['leverage'] ?? null,
                'stop_from_liq_range_pct' => $risk['stop_from_liq_range_pct'] ?? null,
                'slippage_bps' => $risk['slippage_bps'] ?? null,
                'fees_bps' => $risk['fees_bps'] ?? null,
                'order_type' => $risk['order_type'] ?? null,
                'trailing' => [
                    'enabled' => $trailing['enabled'] ?? null,
                    'activation_roi_pct' => $trailing['activation_roi_pct'] ?? null,
                    'mode' => $trailing['mode'] ?? null,
                    'drawdown_factor' => $trailing['drawdown_factor'] ?? null,
                    'min_step' => $trailing['min_step'] ?? null,
                    'min_lock_roi' => $trailing['min_lock_roi'] ?? null,
                    'break_even_enabled' => $trailing['break_even_enabled'] ?? null,
                    'break_even_activation_roi' => $trailing['break_even_activation_roi'] ?? null,
                    'exit_mode' => $trailing['exit_mode'] ?? null,
                    'fixed_take_profit_roi' => $trailing['fixed_take_profit_roi'] ?? null,
                    'hybrid_tp_share' => $trailing['hybrid_tp_share'] ?? null,
                    'brain_trailing_applied' => $trailing['brain_trailing_applied'] ?? null,
                ],
                'limits' => [
                    'max_open_trades' => $limits['max_open_trades'] ?? null,
                    'one_trade_per_symbol' => $limits['one_trade_per_symbol'] ?? null,
                ],
            ],
        ];
    }

    /**
     * Compute demo data sufficiency metrics and AI readiness gate.
     *
     * Delegates counting to BotStore, then applies the readiness thresholds.
     * Readiness requires at least 50 complete closed demo trades.
     *
     * @return array<string,mixed>
     */
    private function computeDemoSufficiencyMetrics(): array
    {
        $metrics = $this->store->computeDemoSufficiencyMetrics();

        $minSamples     = 50;
        $minCompleteRate = 80.0;

        $total        = (int)($metrics['demo_closed_trades_total']        ?? 0);
        $complete     = (int)($metrics['demo_closed_trades_complete']     ?? 0);
        $completeRate = (float)($metrics['demo_closed_trades_complete_rate'] ?? 0.0);

        $aiReady       = false;
        $aiReadyReason = '';

        if ($total === 0) {
            $aiReadyReason = 'No closed demo trades yet — run the demo bot to accumulate data.';
        } elseif ($total < $minSamples) {
            $aiReadyReason = "Insufficient samples: {$total}/{$minSamples} closed demo trades required.";
        } elseif ($completeRate < $minCompleteRate) {
            $aiReadyReason = "Incomplete records: {$completeRate}% complete (need {$minCompleteRate}%). Check close finalization.";
        } else {
            $aiReady       = true;
            $aiReadyReason = "Ready: {$total} closed demo trades, {$completeRate}% complete.";
        }

        $metrics['ai_dataset_ready']        = $aiReady;
        $metrics['ai_dataset_ready_reason'] = $aiReadyReason;
        $metrics['ai_dataset_min_samples']  = $minSamples;

        // Next readiness milestone (propagate from store metrics)
        if (!isset($metrics['next_readiness_milestone'])) {
            $milestones = [10, 25, 50, 100, 250, 500];
            $nextMilestone = null;
            foreach ($milestones as $m) {
                if ($total < $m) {
                    $nextMilestone = $m;
                    break;
                }
            }
            $metrics['next_readiness_milestone'] = $nextMilestone;
        }

        return $metrics;
    }

    /**
     * P6: Compute expectancy metrics from closed trades.
     *
     * Returns: average_win, average_loss, winrate, expectancy,
     * close_reason_stats, roi_by_symbol, roi_by_side, roi_by_pattern.
     *
     * @param array $closedTrades Array of closed trade records
     * @return array Expectancy metrics
     */
    private function computeExpectancyMetrics(array $closedTrades): array
    {
        $metrics = [
            'total_closed' => 0,
            'wins' => 0,
            'losses' => 0,
            'total_win_roi' => 0.0,
            'total_loss_roi' => 0.0,
            'average_win' => 0.0,
            'average_loss' => 0.0,
            'winrate' => 0.0,
            'expectancy' => 0.0,
            'close_reason_stats' => [],
            'roi_by_symbol' => [],
            'roi_by_side' => [],
            'roi_by_pattern' => [],
            'roi_by_pattern_side' => [],
        ];

        if (empty($closedTrades)) {
            return $metrics;
        }

        foreach ($closedTrades as $trade) {
            $roi = (float)($trade['realized_roi'] ?? $trade['roi_pct'] ?? $trade['pnl_pct'] ?? 0);
            $symbol = (string)($trade['symbol'] ?? 'unknown');
            $side = (string)($trade['side'] ?? 'unknown');
            $pattern = (string)($trade['pattern_algorithm'] ?? $trade['pattern'] ?? 'unknown');
            $closeReason = (string)($trade['close_reason'] ?? 'unknown');

            $metrics['total_closed']++;

            if ($roi > 0) {
                $metrics['wins']++;
                $metrics['total_win_roi'] += $roi;
            } else {
                $metrics['losses']++;
                $metrics['total_loss_roi'] += $roi;
            }

            // Close reason stats
            $metrics['close_reason_stats'][$closeReason] = ($metrics['close_reason_stats'][$closeReason] ?? 0) + 1;

            // ROI by symbol
            if (!isset($metrics['roi_by_symbol'][$symbol])) {
                $metrics['roi_by_symbol'][$symbol] = ['total_roi' => 0.0, 'count' => 0, 'wins' => 0, 'losses' => 0];
            }
            $metrics['roi_by_symbol'][$symbol]['total_roi'] += $roi;
            $metrics['roi_by_symbol'][$symbol]['count']++;
            $metrics['roi_by_symbol'][$symbol][$roi > 0 ? 'wins' : 'losses']++;

            // ROI by side
            if (!isset($metrics['roi_by_side'][$side])) {
                $metrics['roi_by_side'][$side] = ['total_roi' => 0.0, 'count' => 0, 'wins' => 0, 'losses' => 0];
            }
            $metrics['roi_by_side'][$side]['total_roi'] += $roi;
            $metrics['roi_by_side'][$side]['count']++;
            $metrics['roi_by_side'][$side][$roi > 0 ? 'wins' : 'losses']++;

            // ROI by pattern
            if (!isset($metrics['roi_by_pattern'][$pattern])) {
                $metrics['roi_by_pattern'][$pattern] = ['total_roi' => 0.0, 'count' => 0, 'wins' => 0, 'losses' => 0];
            }
            $metrics['roi_by_pattern'][$pattern]['total_roi'] += $roi;
            $metrics['roi_by_pattern'][$pattern]['count']++;
            $metrics['roi_by_pattern'][$pattern][$roi > 0 ? 'wins' : 'losses']++;

            // ROI by pattern × side
            $patternSide = $pattern . '×' . $side;
            if (!isset($metrics['roi_by_pattern_side'][$patternSide])) {
                $metrics['roi_by_pattern_side'][$patternSide] = ['total_roi' => 0.0, 'count' => 0, 'wins' => 0, 'losses' => 0];
            }
            $metrics['roi_by_pattern_side'][$patternSide]['total_roi'] += $roi;
            $metrics['roi_by_pattern_side'][$patternSide]['count']++;
            $metrics['roi_by_pattern_side'][$patternSide][$roi > 0 ? 'wins' : 'losses']++;
        }

        // Compute averages and expectancy
        if ($metrics['wins'] > 0) {
            $metrics['average_win'] = round($metrics['total_win_roi'] / $metrics['wins'], 4);
        }
        if ($metrics['losses'] > 0) {
            $metrics['average_loss'] = round($metrics['total_loss_roi'] / $metrics['losses'], 4);
        }
        if ($metrics['total_closed'] > 0) {
            $metrics['winrate'] = round($metrics['wins'] / $metrics['total_closed'], 4);
            // Expectancy = (winrate * avg_win) + ((1-winrate) * avg_loss)
            $metrics['expectancy'] = round(
                ($metrics['winrate'] * $metrics['average_win']) + ((1 - $metrics['winrate']) * $metrics['average_loss']),
                4
            );
        }

        return $metrics;
    }

    /**
     * Compute per-symbol exit statistics from closed trades.
     *
     * Produces symbol-level breakdown of stop/trailing/break-even behavior,
     * exit reason distribution, side split, and robust stats (median/percentiles).
     *
     * ── MAE DATA FLOW ───────────────────────────────────────────────────
     * This function is the PRIMARY data source for MAE adaptive logical stop:
     *   1. Reads MAE from closed trades (mae_roi → mae_pct → mae fallback chain)
     *   2. Splits into mae_winners / mae_losers arrays per symbol + side
     *   3. Computes robust stats (median, p25, p75, p80) via computeRobustStats()
     *   4. Stored in bot's last_run.json as symbol_exit_stats
     *   5. Mirrored to Brain via readBotExecutionMirror()
     *   6. Persisted in passport by CoinPassportEngine::enrichWithExecutionProfile()
     *   7. Consumed by computePerSymbolHints() for adaptive stop suggestion
     *
     * @param array $closedTrades Array of closed trade records
     * @return array Keyed by symbol, each containing exit behavior stats
     */
    private function computePerSymbolExitStats(array $closedTrades): array
    {
        if (empty($closedTrades)) {
            return [];
        }

        // Phase 1: Collect raw data per symbol
        $raw = [];
        foreach ($closedTrades as $trade) {
            $symbol = (string)($trade['symbol'] ?? 'unknown');
            $side = (string)($trade['side'] ?? 'unknown');
            $closeReason = (string)($trade['close_reason'] ?? 'unknown');
            $roi = (float)($trade['realized_roi'] ?? $trade['roi_pct'] ?? $trade['pnl_pct'] ?? 0);
            $entryPrice = (float)($trade['entry_price'] ?? 0);
            $closePrice = (float)($trade['close_price'] ?? 0);
            $initialStop = (float)($trade['initial_computed_stop_price'] ?? $trade['effective_stop_price'] ?? 0);

            // MAE: maximum adverse excursion (ROI-based, as ratio)
            $mae = (float)($trade['mae_roi'] ?? $trade['mae_pct'] ?? $trade['mae'] ?? 0);
            // Normalize: MAE should be positive (absolute adverse move)
            $maeAbs = abs($mae);

            if (!isset($raw[$symbol])) {
                $raw[$symbol] = [
                    'rois' => [],
                    'rois_long' => [],
                    'rois_short' => [],
                    'close_reasons' => [],
                    'trailing_enabled_count' => 0,
                    'trailing_active_count' => 0,
                    'break_even_enabled_count' => 0,
                    'break_even_armed_count' => 0,
                    'break_even_applied_count' => 0,
                    'stop_distances' => [],
                    'wins' => 0,
                    'losses' => 0,
                    'win_rois' => [],
                    'loss_rois' => [],
                    'mae_winners' => [],
                    'mae_losers' => [],
                    'sides' => [
                        'long' => ['rois' => [], 'wins' => 0, 'losses' => 0, 'reasons' => [], 'mae_winners' => [], 'mae_losers' => []],
                        'short' => ['rois' => [], 'wins' => 0, 'losses' => 0, 'reasons' => [], 'mae_winners' => [], 'mae_losers' => []],
                    ],
                ];
            }

            $raw[$symbol]['rois'][] = $roi;

            if ($roi > 0) {
                $raw[$symbol]['wins']++;
                $raw[$symbol]['win_rois'][] = $roi;
                if ($maeAbs > 0) {
                    $raw[$symbol]['mae_winners'][] = $maeAbs;
                }
            } else {
                $raw[$symbol]['losses']++;
                $raw[$symbol]['loss_rois'][] = $roi;
                if ($maeAbs > 0) {
                    $raw[$symbol]['mae_losers'][] = $maeAbs;
                }
            }

            // Close reason distribution
            $raw[$symbol]['close_reasons'][$closeReason] = ($raw[$symbol]['close_reasons'][$closeReason] ?? 0) + 1;

            // Protection state flags
            if (!empty($trade['trailing_enabled'])) {
                $raw[$symbol]['trailing_enabled_count']++;
            }
            if (!empty($trade['trailing_active'])) {
                $raw[$symbol]['trailing_active_count']++;
            }
            if (!empty($trade['break_even_enabled'])) {
                $raw[$symbol]['break_even_enabled_count']++;
            }
            if (!empty($trade['break_even_armed'])) {
                $raw[$symbol]['break_even_armed_count']++;
            }
            if (!empty($trade['break_even_applied'])) {
                $raw[$symbol]['break_even_applied_count']++;
            }

            // Stop distance from entry (ratio)
            if ($entryPrice > 0 && $initialStop > 0) {
                $raw[$symbol]['stop_distances'][] = round(abs($initialStop - $entryPrice) / $entryPrice, 6);
            }

            // Side split
            $sideKey = ($side === 'long' || $side === 'short') ? $side : 'unknown';
            if ($sideKey === 'long' || $sideKey === 'short') {
                $raw[$symbol]['sides'][$sideKey]['rois'][] = $roi;
                $raw[$symbol]['sides'][$sideKey][$roi > 0 ? 'wins' : 'losses']++;
                $raw[$symbol]['sides'][$sideKey]['reasons'][$closeReason] = ($raw[$symbol]['sides'][$sideKey]['reasons'][$closeReason] ?? 0) + 1;
                if ($sideKey === 'long') {
                    $raw[$symbol]['rois_long'][] = $roi;
                } else {
                    $raw[$symbol]['rois_short'][] = $roi;
                }
                // MAE per side for winning/losing trades
                if ($maeAbs > 0) {
                    if ($roi > 0) {
                        $raw[$symbol]['sides'][$sideKey]['mae_winners'][] = $maeAbs;
                    } else {
                        $raw[$symbol]['sides'][$sideKey]['mae_losers'][] = $maeAbs;
                    }
                }
            }
        }

        // Phase 2: Build finalized stats with robust metrics
        $stats = [];
        foreach ($raw as $symbol => $d) {
            $count = count($d['rois']);
            if ($count === 0) {
                continue;
            }

            $winrate = $count > 0 ? round($d['wins'] / $count, 4) : 0;
            $avgWin = count($d['win_rois']) > 0 ? round(array_sum($d['win_rois']) / count($d['win_rois']), 4) : 0;
            $avgLoss = count($d['loss_rois']) > 0 ? round(array_sum($d['loss_rois']) / count($d['loss_rois']), 4) : 0;
            $expectancy = round(($winrate * $avgWin) + ((1 - $winrate) * $avgLoss), 4);

            $trailingActivationRate = $d['trailing_enabled_count'] > 0
                ? round($d['trailing_active_count'] / $d['trailing_enabled_count'], 4) : 0;
            $trailingCloseCount = ($d['close_reasons']['closed_by_trailing'] ?? 0);
            $beApplyRate = $d['break_even_enabled_count'] > 0
                ? round($d['break_even_applied_count'] / $d['break_even_enabled_count'], 4) : 0;

            $stopHitCount = ($d['close_reasons']['closed_by_logical_stop'] ?? 0)
                + ($d['close_reasons']['close_stop_loss'] ?? 0)
                + ($d['close_reasons']['stop_loss'] ?? 0);

            $entry = [
                'symbol' => $symbol,
                'trades_count' => $count,
                'wins' => $d['wins'],
                'losses' => $d['losses'],
                'winrate' => $winrate,
                'avg_roi' => round(array_sum($d['rois']) / $count, 4),
                'roi_stats' => $this->computeRobustStats($d['rois']),
                'average_win' => $avgWin,
                'average_loss' => $avgLoss,
                'expectancy' => $expectancy,
                'close_reason_distribution' => $d['close_reasons'],
                'stop_hit_count' => $stopHitCount,
                'trailing_enabled_count' => $d['trailing_enabled_count'],
                'trailing_active_count' => $d['trailing_active_count'],
                'trailing_activation_rate' => $trailingActivationRate,
                'trailing_close_count' => $trailingCloseCount,
                'break_even_enabled_count' => $d['break_even_enabled_count'],
                'break_even_armed_count' => $d['break_even_armed_count'],
                'break_even_applied_count' => $d['break_even_applied_count'],
                'break_even_apply_rate' => $beApplyRate,
            ];

            // Stop distance stats (only if we have data)
            if (!empty($d['stop_distances'])) {
                $entry['stop_distance_stats'] = $this->computeRobustStats($d['stop_distances']);
            }

            // MAE stats for winning trades (key for adaptive logical stop)
            if (!empty($d['mae_winners'])) {
                sort($d['mae_winners']); // Pre-sort once for all percentile computations
                $maeWinStats = $this->computeRobustStats($d['mae_winners']);
                // Add p80 — array already sorted above
                $maeWinStats['p80'] = round($this->percentile($d['mae_winners'], 80), 4);
                $entry['mae_winners_stats'] = $maeWinStats;
            }
            if (!empty($d['mae_losers'])) {
                $entry['mae_losers_stats'] = $this->computeRobustStats($d['mae_losers']);
            }

            // Side split
            $sides = [];
            foreach (['long', 'short'] as $s) {
                $sideData = $d['sides'][$s];
                $sideCount = count($sideData['rois']);
                if ($sideCount > 0) {
                    $sideWinrate = round($sideData['wins'] / $sideCount, 4);
                    $sideEntry = [
                        'trades' => $sideCount,
                        'wins' => $sideData['wins'],
                        'losses' => $sideData['losses'],
                        'winrate' => $sideWinrate,
                        'avg_roi' => round(array_sum($sideData['rois']) / $sideCount, 4),
                        'roi_stats' => $this->computeRobustStats($sideData['rois']),
                        'close_reasons' => $sideData['reasons'],
                    ];
                    // MAE stats per side for winning trades
                    if (!empty($sideData['mae_winners'])) {
                        sort($sideData['mae_winners']); // Pre-sort once for all percentile computations
                        $sideMaeWinStats = $this->computeRobustStats($sideData['mae_winners']);
                        $sideMaeWinStats['p80'] = round($this->percentile($sideData['mae_winners'], 80), 4);
                        $sideEntry['mae_winners_stats'] = $sideMaeWinStats;
                    }
                    if (!empty($sideData['mae_losers'])) {
                        $sideEntry['mae_losers_stats'] = $this->computeRobustStats($sideData['mae_losers']);
                    }
                    $sides[$s] = $sideEntry;
                }
            }
            $entry['by_side'] = $sides;

            $stats[$symbol] = $entry;
        }

        return $stats;
    }

    /**
     * Compute robust statistics for a numeric array.
     *
     * Returns avg, median, p25, p75, min, max, count.
     * Safe for empty arrays.
     *
     * @param array $values Numeric values
     * @return array Robust stats
     */
    private function computeRobustStats(array $values): array
    {
        $count = count($values);
        if ($count === 0) {
            return ['avg' => 0, 'median' => 0, 'p25' => 0, 'p75' => 0, 'min' => 0, 'max' => 0, 'count' => 0];
        }

        sort($values);
        $sum = array_sum($values);

        return [
            'avg' => round($sum / $count, 4),
            'median' => round($this->percentile($values, 50), 4),
            'p25' => round($this->percentile($values, 25), 4),
            'p75' => round($this->percentile($values, 75), 4),
            'min' => round(min($values), 4),
            'max' => round(max($values), 4),
            'count' => $count,
        ];
    }

    /**
     * Compute percentile from a sorted array.
     *
     * @param array $sorted Sorted numeric array
     * @param float $p Percentile (0-100)
     * @return float
     */
    private function percentile(array $sorted, float $p): float
    {
        $count = count($sorted);
        if ($count === 0) {
            return 0.0;
        }
        if ($count === 1) {
            return (float)$sorted[0];
        }

        $rank = ($p / 100) * ($count - 1);
        $lower = (int)floor($rank);
        $upper = (int)ceil($rank);
        $frac = $rank - $lower;

        if ($lower === $upper || $upper >= $count) {
            return (float)$sorted[$lower];
        }

        return (float)$sorted[$lower] + $frac * ((float)$sorted[$upper] - (float)$sorted[$lower]);
    }

    /**
     * Acquire run-lock (prevents concurrent executions)
     * 
     * @return array ['acquired' => bool, 'fp' => resource|null]
     */
    private function acquireRunLock(): array
    {
        $lockFile = $this->config['execution']['run_lock_file'] ?? 'runtime/exec.lock';
        $lockPath = $this->storageDir . '/' . $lockFile;
        
        // Ensure directory exists
        $lockDir = dirname($lockPath);
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0755, true);
        }
        
        $fp = @fopen($lockPath, 'c+');
        if ($fp === false) {
            return ['acquired' => false, 'fp' => null, 'lock_meta' => null];
        }
        
        // Try non-blocking exclusive lock
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            // Another process holds the lock. Try to read lock metadata for better diagnostics.
            $lockMeta = null;
            $raw = @file_get_contents($lockPath);
            if (is_string($raw) && trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $lockMeta = $decoded;
                } else {
                    $lockMeta = ['raw' => trim($raw)];
                }
            }

            fclose($fp);
            return ['acquired' => false, 'fp' => null, 'lock_meta' => $lockMeta];
        }
        // Write lock info (with hostname for distributed deployments)
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode([
            'pid' => getmypid(),
            'hostname' => gethostname() ?: 'unknown',
            'acquired_at' => date('c'),
        ]));
        fflush($fp);
        
        return ['acquired' => true, 'fp' => $fp];
    }
    
    /**
     * Release run-lock
     * 
     * @param resource $fp Lock file pointer
     */
    private function releaseRunLock($fp): void
    {
        if ($fp !== null && is_resource($fp)) {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
    
    /**
     * Get current bot status
     */
    public function getStatus(): array
    {
        if ($this->configError !== null) {
            return [
                'ok' => false,
                'error' => $this->configError,
            ];
        }

        $mode = $this->config['module']['mode'] ?? 'dry';
        $status = [
            'ok' => true,
            'enabled' => $this->config['module']['enabled'] ?? false,
            'mode' => $mode,
            'last_run' => $this->store->loadLastRun(),
            'open_positions' => count($this->store->loadActiveTrades()),
            'pending_orders' => count($this->store->loadActiveOrders()),
            'errors_count' => count($this->store->loadErrors()),
            'gateway_initialized' => ($this->gateway instanceof TradingBotGateway && $this->gateway->isInitialized()),
        ];

        // Safe demo credential diagnostics
        if ($mode === 'demo') {
            $demoCreds = $this->config['module']['credentials']['demo'] ?? [];
            $demoKey    = trim((string)($demoCreds['api_key']    ?? ''));
            $demoSecret = trim((string)($demoCreds['api_secret'] ?? ''));
            $demoUrl    = trim((string)($demoCreds['api_base_url'] ?? 'https://api-demo.bybit.com'));
            $status['demo_credentials_diag'] = [
                'demo_api_key_present'    => $demoKey !== '',
                'demo_api_secret_present' => $demoSecret !== '',
                'demo_api_base_url'       => $demoUrl,
                'is_real_exchange_mode'   => true,
            ];
        }

        return $status;
    }
    
    /**
     * Stop the bot (disable)
     */
    
    /**
     * UI helper: fetch exchange positions and normalize for dashboard rendering.
     *
     * In LIVE mode this performs a signed exchange request.
     * In DRY/TEST mode returns empty positions.
     *
     * Normalized position schema for UI:
     * - symbol
     * - side: long|short
     * - size
     * - avg_price
     * - active_price (markPrice)
     * - stop_loss
     * - trailing_stop
     * - unrealised_pnl
     * - roi_pct (approx, from unrealisedPnl / margin)
     * - position_idx
     */
    public function getExchangePositionsUi(): array
    {
        // In non-exchange modes (paper/dry): nothing to show from exchange
        if (!$this->isRealExchangeMode()) {
            return [
                'ok' => true,
                'positions' => [],
                'count' => 0,
                'source' => 'not_real_exchange_mode',
            ];
        }

        $gateway = $this->getGateway();
        if ($gateway === null) {
            return [
                'ok' => false,
                'error' => 'gateway_not_initialized',
                'positions' => [],
                'count' => 0,
            ];
        }

        try {
            $raw = $gateway->getPositions();
            $raw = is_array($raw) ? $raw : [];

            $positions = [];

            foreach ($raw as $p) {
                if (!is_array($p)) {
                    continue;
                }

                $symbol = (string)($p['symbol'] ?? '');
                if ($symbol === '') {
                    continue;
                }

                $sizeRaw = (float)($p['size'] ?? 0);
                if (abs($sizeRaw) <= 0.0) {
                    continue;
                }

                $sideRaw = (string)($p['side'] ?? '');
                $side = 'long';
                if ($sideRaw === 'Sell' || $sideRaw === 'sell' || $sideRaw === 'SHORT' || $sideRaw === 'short') {
                    $side = 'short';
                }

                $avgPrice = (float)($p['avgPrice'] ?? ($p['avg_price'] ?? 0));
                $markPrice = (float)($p['markPrice'] ?? ($p['mark_price'] ?? ($p['lastPrice'] ?? 0)));

                $unPnl = (float)($p['unrealisedPnl'] ?? ($p['unrealised_pnl'] ?? 0));
                $stopLoss = (float)($p['stopLoss'] ?? ($p['stop_loss'] ?? 0));
                $trailingStop = (float)($p['trailingStop'] ?? ($p['trailing_stop'] ?? 0));
                $positionIdx = (int)($p['positionIdx'] ?? ($p['position_idx'] ?? 0));

                $leverage = (float)($p['leverage'] ?? 0);

                // Estimate ROI based on initial margin (position value / leverage)
                $positionValue = (float)($p['positionValue'] ?? 0);
                if ($positionValue <= 0 && $avgPrice > 0) {
                    $positionValue = abs($sizeRaw) * $avgPrice;
                }

                $roi = 0.0;
                if ($positionValue > 0) {
                    if ($leverage > 0) {
                        $roi = ($unPnl * $leverage / $positionValue) * 100.0;
                    } else {
                        $roi = ($unPnl / $positionValue) * 100.0;
                    }
                }

                $positions[] = [
                    'symbol' => $symbol,
                    'side' => $side,
                    'size' => abs($sizeRaw),
                    'avg_price' => $avgPrice,
                    'active_price' => $markPrice,
                    'stop_loss' => $stopLoss,
                    'trailing_stop' => $trailingStop,
                    'unrealised_pnl' => $unPnl,
                    'roi_pct' => $roi,
                    'position_idx' => $positionIdx,
                ];
            }

            return [
                'ok' => true,
                'positions' => $positions,
                'count' => count($positions),
                'source' => 'gateway',
            ];
        } catch (\Throwable $e) {
            $this->errors[] = 'Failed to fetch exchange positions (UI): ' . $e->getMessage();

            return [
                'ok' => false,
                'error' => $e->getMessage(),
                'positions' => [],
                'count' => 0,
            ];
        }
    }

public function stop(): array
    {
        // Save disabled state
        $this->store->saveState(['enabled' => false, 'stopped_at' => date('c')]);
        
        return [
            'ok' => true,
            'status' => 'stopped',
            'ts' => date('c'),
        ];
    }
    
    /**
     * Build error result
     */
    private function buildErrorResult(string $ts, float $startTime, string $error): array
    {
        return [
            'ts' => $ts,
            'ok' => false,
            'status' => 'config_error',
            'error' => $error,
            'duration_ms' => (int)round((microtime(true) - $startTime) * 1000),
        ];
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
            'duration_ms' => (int)round((microtime(true) - $startTime) * 1000),
        ];
    }
    
    /**
     * Log error to file
     */
    private function logError(string $context, string $message, array $data = []): void
    {
        $this->store->appendErrorLog([
            'ts' => date('c'),
            'context' => $context,
            'message' => $message,
            'data' => $data,
        ]);
    }

    /**
     * Get gateway instance.
     *
     * IMPORTANT:
     * - Traits (reconcile/executor) use $this->getGateway() to access LIVE exchange API.
     * - If gateway is not initialized (e.g., mode != live), returns null.
     *
     * @return TradingBotGateway|null
     */
    private function getGateway(): ?TradingBotGateway
    {
        return ($this->gateway instanceof TradingBotGateway) ? $this->gateway : null;
    }
    
    /**
     * Initialize gateway for LIVE mode (P6)
     * 
     * Creates gateway instance. On error: logs and sets error, but does NOT fatal.
     */
    private function initGateway(): void
    {
        try {
            // account_id is under config['module'], not config['exchange']
            $accountId = $this->config['module']['account_id'] ?? null;
            
            // Validate account_id is configured for LIVE mode
            if (empty($accountId)) {
                $this->errors[] = 'Gateway initialization failed: account_id not configured';
                $this->logError('initGateway', 'Missing account_id configuration for LIVE mode', []);
                return;
            }
            
            $this->gateway = new TradingBotGateway($this->config);
            
            if (!$this->gateway->isInitialized()) {
                $this->errors[] = 'Gateway initialization failed: client not initialized';
                $this->logError('initGateway', 'Gateway client not initialized', ['account_id' => $accountId]);
                $this->gateway = null;
            }
        } catch (\Throwable $e) {
            $this->errors[] = 'Gateway initialization exception: ' . $e->getMessage();
            $this->logError('initGateway', $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            $this->gateway = null;
        }
    }
}

/* RULES
- Service is the main execution entry point (execute())
- Risk = ONLY from Brain signals (risk-block) - NO hardcode
- Bot does NOT "think" - it only executes
- Uses run-lock to prevent concurrent executions
- Writes ONLY to module storage/ directory
- CONFIG FIRST / ZERO HARDCODE / SystemPaths ONLY
- P7.6: Brain commands applied before intents (if commands_apply_before_intents=true)
*/
