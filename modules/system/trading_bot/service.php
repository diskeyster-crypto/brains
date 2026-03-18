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
        
        $this->storageDir = $this->moduleBase . '/storage';
        $this->logsDir = $this->moduleBase . '/storage/logs'; // P7 fix: logs in storage/logs per manifest
        $this->config = $this->loadConfig();
        
        // Initialize sub-components
        $this->riskEngine = new Lib\BotRiskEngine($this->config);
        $this->trailingEngine = new Lib\BotTrailingEngine($this->config);
        $this->validator = new Lib\BotValidator($this->config);
        $this->store = new Lib\BotStore($this->storageDir, $this->config);
        
        // P6: Initialize gateway for LIVE mode only
        $mode = $this->config['module']['mode'] ?? 'dry';
        if ($mode === 'live') {
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
            'intent_results' => [],
            'intents_processed' => 0,
            'intents_opened' => 0,
            'intents_skipped' => 0,
            'intents_rejected_exec' => 0,
            'intents_failed_exec' => 0,
            'rejection_reason_stats' => [],
            'close_reason_stats' => [],
        ];
        
        try {
            // Step 1: Reconcile with exchange
            if ($this->config['module']['reconcile_before_action'] ?? true) {
                $reconcileResult = $this->reconcileWithExchange();
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

            if ($brainControlled) {
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
                    // Brain approved zero intents — valid decision, NOT an error
                    $inputSource = 'brain_live_intents';
                    $intentsResult = $brainLiveResult;
                    $this->warnings[] = 'Brain-controlled mode active: approved live intents = 0. No trades executed. Legacy fallback disabled.';
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
                    // V4: Include effective trailing contract snapshot for runtime debug truth
                    $result['effective_trailing_contract'] = [
                        'enabled' => $firstTrailing['enabled'] ?? null,
                        'activation_roi_pct' => $firstTrailing['activation_roi_pct'] ?? null,
                        'drawdown_factor' => $firstTrailing['drawdown_factor'] ?? null,
                        'drawdown_factor_source' => $firstTrailing['drawdown_factor_source'] ?? null,
                        'min_step' => $firstTrailing['min_step'] ?? null,
                        'min_lock_roi' => $firstTrailing['min_lock_roi'] ?? null,
                        'break_even_enabled' => $firstTrailing['break_even_enabled'] ?? null,
                        'exit_mode' => $firstTrailing['exit_mode'] ?? null,
                        'brain_trailing_applied' => $firstTrailing['brain_trailing_applied'] ?? null,
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
                $result['effective_trailing_contract'] = null;
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
            
            if ($mode !== 'test') {
                $executedThisRun = 0;
                $deferredChecked = 0;
                $maxDeferredPerRun = (int)($this->config['execution']['max_deferred_intents_per_run'] ?? 10);
                if ($maxDeferredPerRun <= 0) {
                    $maxDeferredPerRun = 10;
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
                    $result['intents_processed']++;
                    $intentResultRecord = $this->buildIntentResultRecord($intent, $execResult);
                    $result['intent_results'][] = $intentResultRecord;
                    $lifecycleState = $intentResultRecord['lifecycle_state'];
                    if ($lifecycleState === 'opened' || $lifecycleState === 'protected') {
                        $result['intents_opened']++;
                    } elseif ($lifecycleState === 'deferred') {
                        $result['intents_skipped']++;
                    } elseif ($lifecycleState === 'rejected') {
                        $result['intents_rejected_exec']++;
                    } elseif ($lifecycleState === 'failed') {
                        $result['intents_failed_exec']++;
                    }
                    // Track rejection/close reason stats
                    if (in_array($lifecycleState, ['rejected', 'failed'], true) && !empty($intentResultRecord['rejection_reason'])) {
                        $rr = $intentResultRecord['rejection_reason'];
                        $result['rejection_reason_stats'][$rr] = ($result['rejection_reason_stats'][$rr] ?? 0) + 1;
                    }
                    if (!empty($intentResultRecord['close_reason'])) {
                        $cr = $intentResultRecord['close_reason'];
                        $result['close_reason_stats'][$cr] = ($result['close_reason_stats'][$cr] ?? 0) + 1;
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
                            $deferredChecked++;
                            $result['intents_deferred']++;
                            $reason = $execResult['deferred_reason'] ?? $execStatus;
                            if (!isset($deferredReasonCounts[$reason])) {
                                $deferredReasonCounts[$reason] = 0;
                            }
                            $deferredReasonCounts[$reason]++;
                        } elseif (str_starts_with($execStatus, 'rejected_')) {
                            // Soft reject: NOT an error, just a warning
                            // Do NOT increment orders_failed
                            // Do NOT add to $this->errors (safety-stop)
                            $executedThisRun++;
                            $result['intents_rejected_exec']++;
                            $this->warnings[] = "Rejected: {$execStatus} — " . ($execResult['error'] ?? 'no_reason_provided');
                        } else {
                            // Real execution error: counts towards safety-stop
                            $executedThisRun++;
                            $result['orders_failed']++;
                            $result['intents_failed_exec']++;
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
                'executed' => $executedThisRun,
                'deferred_checked' => $deferredChecked,
                'max_execute_per_run' => $maxExecutePerRun,
                'max_deferred_per_run' => $maxDeferredPerRun,
            ];
            
            // Step 5: Update active positions
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
            $result['steps'][] = [
                'step' => 'update_positions',
                'status' => 'ok',
                'updated' => $updateResult['updated'] ?? 0,
                'closed' => $updateResult['closed'] ?? 0,
                'trailing_applied' => $updateResult['trailing_applied'] ?? 0,
                'trailing_failed' => $updateResult['trailing_failed'] ?? 0,
                'trailing_skipped' => $updateResult['trailing_skipped'] ?? 0,
            ];

            // Observability: active protection summary (normalized, not legacy-dependent)
            $activeTrades = $this->store->loadActiveTrades();
            $protectedCount = 0;
            $trailingActiveCount = 0;
            $protectionErrorsCount = 0;
            foreach ($activeTrades as $t) {
                $rt = is_array($t['runtime'] ?? null) ? $t['runtime'] : [];
                $prot = is_array($t['protection'] ?? null) ? $t['protection'] : [];
                $riskTrailing = is_array($t['risk']['trailing'] ?? null) ? $t['risk']['trailing'] : [];

                // Protected = SL price is set (either in protection block or from exchange)
                if ((float)($prot['stop_loss_price'] ?? 0) > 0) {
                    $protectedCount++;
                }

                // Trailing active: prefer normalized trailing state
                // 1) runtime.dumb_trailing_applied (legacy but still written by updateActivePositions)
                // 2) risk.trailing.enabled as fallback indicator that trailing is configured
                $trailingApplied = !empty($rt['dumb_trailing_applied']);
                $trailingEnabled = (bool)($riskTrailing['enabled'] ?? false);
                if ($trailingApplied || ($trailingEnabled && !empty($rt['dumb_trailing_applied_at']))) {
                    $trailingActiveCount++;
                }

                // Protection errors: SL repair attempted but failed
                if (!empty($rt['sl_repair_attempted']) && empty($rt['sl_repair_result']['ok'])) {
                    $protectionErrorsCount++;
                }
                // Also count trailing failures as protection errors
                if (!empty($rt['dumb_trailing_last_error'])) {
                    $protectionErrorsCount++;
                }
            }
            $result['active_protection_summary'] = [
                'active_positions_count' => count($activeTrades),
                'protected_positions_count' => $protectedCount,
                'trailing_active_count' => $trailingActiveCount,
                'protection_errors_count' => $protectionErrorsCount,
            ];
            
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
        // P6.11: Balance snapshot for UI (sticky between runs)
        if ($mode === 'live') {
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
                    'drawdown_factor_source' => $trailing['drawdown_factor_source'] ?? null,
                    'min_step' => $trailing['min_step'] ?? null,
                ],
                'limits' => [
                    'max_open_trades' => $limits['max_open_trades'] ?? null,
                    'one_trade_per_symbol' => $limits['one_trade_per_symbol'] ?? null,
                ],
            ],
        ];
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
        
        return [
            'ok' => true,
            'enabled' => $this->config['module']['enabled'] ?? false,
            'mode' => $this->config['module']['mode'] ?? 'dry',
            'last_run' => $this->store->loadLastRun(),
            'open_positions' => count($this->store->loadActiveTrades()),
            'pending_orders' => count($this->store->loadActiveOrders()),
            'errors_count' => count($this->store->loadErrors()),
        ];
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
        // In non-live mode: nothing to show from exchange
        if (!$this->isLiveMode()) {
            return [
                'ok' => true,
                'positions' => [],
                'count' => 0,
                'source' => 'not_live_mode',
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
