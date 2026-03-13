<?php

declare(strict_types=1);

use Core\System\SystemPaths;
use Core\Gateway\Bybit;

// Load trait files
require_once __DIR__ . '/lib/parser6_config_trait.php';
require_once __DIR__ . '/lib/parser6_signals_risk_trait.php';
require_once __DIR__ . '/lib/parser6_pricefeed_trait.php';
require_once __DIR__ . '/lib/parser6_store_trait.php';
require_once __DIR__ . '/lib/parser6_engine_trait.php';
require_once __DIR__ . '/lib/parser6_dataset_trait.php';
require_once __DIR__ . '/lib/parser6_stats_trait.php';
require_once __DIR__ . '/lib/parser6_integration_trait.php';

/**
 * Parser 6: Simulator Service (Live Trading Engine)
 *
 * LIVE simulator that fetches prices directly from Bybit API.
 * Per ТЗ spec: Parser6 is a LIVE ENGINE, NOT dependent on Parser2 history.
 * 
 * Key changes per ТЗ:
 * 1. Uses Bybit API for live prices (NOT Parser2 NDJSON)
 * 2. Updates active trades each cycle with current price
 * 3. Entry timeout ONLY for entry validation (not closing trades)
 * 4. Symbol blocking - reject signals for symbols in active_trades
 * 5. Live trailing stop updates each tick
 * 6. Full trade lifecycle saved to training_dataset.ndjson
 *
 * NO HARDCODE. CONFIG FIRST. SystemPaths ONLY.
 */
final class Parser6SimulatorService
{
    // Include all traits
    use \Parser6ConfigTrait;
    use \Parser6SignalsRiskTrait;
    use \Parser6PricefeedTrait;
    use \Parser6StoreTrait;
    use \Parser6EngineTrait;
    use \Parser6DatasetTrait;
    use \Parser6StatsTrait;
    use \Parser6IntegrationTrait;

    private const SCHEMA_VERSION = '1.0';
    
    /**
     * STEP 1.1: Dynamic SL offset coefficient (multiplied by liq_price)
     * Per ТЗ: offset = max(1e-8, abs($liqPrice) * SL_OFFSET_COEFFICIENT)
     * This ensures SL offset scales with price (works for cheap coins < $0.01)
     */
    private const SL_OFFSET_COEFFICIENT = 1e-6;
    
    /**
     * STEP 1.1: Minimum absolute SL offset (floor value)
     * Prevents zero offset for very low priced assets
     */
    private const SL_OFFSET_MIN_ABSOLUTE = 1e-8;
    
    /** Maximum display value for profit factor (caps infinite/very high values) */
    private const MAX_PROFIT_FACTOR_DISPLAY = 999.99;

    private ?string $moduleBase;
    private string $storageDir;
    private string $logsDir;
    private array $config;
    private array $errors = [];
    private array $rejections = [];
    private ?string $configError = null; // Stores config error for execute()

    public function __construct()
    {
        $paths = SystemPaths::instance();

        // Get module base path - NO FALLBACK, CONFIG FIRST
        $this->moduleBase = $this->resolveModuleBase($paths);
        
        // If module base not found via SystemPaths, mark as config error
        if ($this->moduleBase === null) {
            $this->configError = 'module_base_unknown';
            $this->storageDir = '';
            $this->logsDir = '';
            $this->config = [];
            return;
        }
        
        $this->storageDir = $this->moduleBase . '/storage';
        $this->logsDir = $this->storageDir . '/logs';

        // Load config with user overrides (Part 1 of TZ)
        $this->config = $this->loadConfigWithUserOverrides();
    }

    /**
     * Main execution entry point
     *
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $startTime = microtime(true);
        $ts = date('c');
        $nowTs = time();
        
        // FIX #1: Check for config error (module_base_unknown)
        // If SystemPaths didn't provide valid path, abort with config_error
        if ($this->configError !== null) {
            $result = [
                'ts' => $ts,
                'ok' => false,
                'success' => false,
                'status' => 'config_error',
                'payload' => [
                    'error' => $this->configError,
                    'details' => 'Module base path not found via SystemPaths. Tried keys: ' 
                        . implode(', ', $this->getModuleBasePathKeys()),
                ],
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ];
            // Try to write last_run.json if we have a fallback path
            $this->writeConfigErrorLastRun($result);
            return $result;
        }
        
        // Check if module is enabled
        if (!($this->config['enabled'] ?? false)) {
            $result = [
                'ts' => $ts,
                'ok' => true,
                'status' => 'disabled',
                'duration_ms' => 0,
            ];
            $this->writeLastRun($result);
            return $result;
        }
        
        // Check for comparison mode (RAW vs CLEAN)
        $comparison = $this->config['comparison'] ?? [];
        $comparisonEnabled = (bool)($comparison['enabled'] ?? false);
        $runBoth = (bool)($comparison['run_both'] ?? false);
        $defaultMode = (string)($comparison['default_mode'] ?? 'clean');
        
        if ($comparisonEnabled && $runBoth) {
            // Run both RAW and CLEAN modes, return comparison
            return $this->executeComparison($startTime);
        }
        
        // Single mode execution (legacy behavior or comparison disabled)
        // C1: Route through runSimulationMode() - unified execution path
        $mode = $comparisonEnabled ? $defaultMode : 'clean';
        $result = $this->runSimulationMode($mode);
        
        // FIX-4.2-B: Copy CLEAN artifacts to root for compatibility (single mode)
        if ($mode === 'clean') {
            $this->copyCleanArtifactsToRoot();
        }
        
        return $result;
    }
    
    /**
     * Execute comparison mode: run both RAW and CLEAN simulations
     * Per ТЗ: run_both=true runs both modes and computes delta
     *
     * @param float $startTime Execution start time
     * @return array Combined result with raw/clean/delta
     */
    private function executeComparison(float $startTime): array
    {
        $ts = date('c');
        
        // Ensure base storage directories exist
        $this->ensureStorageDirectories();
        
        // Run RAW mode first
        $rawResult = $this->runSimulationMode('raw');
        
        // Run CLEAN mode
        $cleanResult = $this->runSimulationMode('clean');
        
        // FIX-4.2: Copy CLEAN artifacts to root for Brain compatibility
        // Per ТЗ: Root storage must contain CLEAN data as source of truth
        $this->copyCleanArtifactsToRoot();
        
        // Compute delta (clean - raw)
        $delta = $this->computeComparison($rawResult, $cleanResult);
        
        $durationMs = (int)round((microtime(true) - $startTime) * 1000);
        
        // Build combined result for root last_run.json
        $result = [
            'ts' => $ts,
            'ok' => $rawResult['ok'] && $cleanResult['ok'],
            'status' => 'comparison_complete',
            'comparison_enabled' => true,
            'duration_ms' => $durationMs,
            'raw' => $rawResult,
            'clean' => $cleanResult,
            'delta' => $delta,
            'raw_last_run_file' => 'raw/last_run.json',
            'clean_last_run_file' => 'clean/last_run.json',
        ];
        
        // Write main last_run.json to storage root (not mode-specific)
        $this->writeLastRun($result);
        
        return $result;
    }
    
    /**
     * Run simulation for a specific mode (raw or clean)
     * Per ТЗ: Each mode writes to its own storage subdirectory
     *
     * @param string $mode 'raw' or 'clean'
     * @return array Simulation result/summary
     */
    private function runSimulationMode(string $mode): array
    {
        $ts = date('c');
        $nowTs = time();
        $modeStartTime = microtime(true);
        
        // Reset errors/rejections for this mode
        $this->errors = [];
        $this->rejections = [];
        
        // Reset per-run price cache
        $this->livePriceCache = [];
        
        // Get mode-specific storage base
        $modeStorageBase = $this->getModeStorageBase($mode);
        $this->ensureModeStorageDirectories($modeStorageBase);
        
        $stats = [
            'mode' => $mode,
            'signals_loaded' => 0,
            'signals_eligible' => 0,
            'opened_now' => 0,
            'updated_active' => 0,
            'closed_now' => 0,
            'expired_now' => 0,
            'errors_count' => 0,
            'rejections_count' => 0,
            'max_open_trades_effective' => 'unknown',
            'enter_now_count' => 0,
            'wait_retrace_count' => 0,
            'reject_late_count' => 0,
            // P3.2: Separate closed and rejected reasons (no ambiguous reject_by_reason)
            'closed_by_reason' => [],
            'rejected_by_reason' => [],
        ];
        
        // 1. Load signals by mode
        $signals = $this->loadSignalsByMode($mode);
        $stats['signals_loaded'] = count($signals);
        
        // 2. Load executed index (mode-specific)
        $executedIndex = $this->loadExecutedIndexForMode($modeStorageBase);
        
        // 3. Filter eligible signals
        $eligibleSignals = $this->filterEligibleSignalsForMode($signals, $executedIndex, $nowTs, $modeStorageBase);

        // P4.3 BUGFIX: Dedup eligible signals by tradeId (id/generateSignalId)
        // Prevent double-processing the same signal multiple times in a single RUN.
        if (!empty($eligibleSignals)) {
            $seen = [];
            $deduped = [];
            foreach ($eligibleSignals as $sig) {
                $sid = $sig['id'] ?? $this->generateSignalId($sig);
                if (isset($seen[$sid])) {
                    continue;
                }
                $seen[$sid] = true;
                $deduped[] = $sig;
            }
            $eligibleSignals = $deduped;
        }

        $stats['signals_eligible'] = count($eligibleSignals);
        
        // C-4: Apply Brain management commands ONLY for CLEAN mode
        // RAW mode should run without Brain management for honest comparison
        $state = $this->loadStateForMode($modeStorageBase);
        
        if ($mode === 'clean') {
            // CLEAN mode: apply management commands
            $managementCounters = $this->applyBrainManagementCommandsForMode($modeStorageBase, $state, $nowTs);
        } else {
            // C-4: RAW mode - skip management commands, set counters to 0
            $managementCounters = [
                'management_commands_loaded' => 0,
                'management_commands_applied' => 0,
                'management_commands_expired' => 0,
                'management_commands_ignored_not_found_trade' => 0,
                'management_skipped_reason' => 'raw_mode',
            ];
        }
        
        // C3: Add management counters to stats
        $stats['management_commands_loaded'] = $managementCounters['management_commands_loaded'];
        $stats['management_commands_applied'] = $managementCounters['management_commands_applied'];
        $stats['management_commands_expired'] = $managementCounters['management_commands_expired'];
        $stats['management_commands_ignored_not_found_trade'] = $managementCounters['management_commands_ignored_not_found_trade'];
        // C-4: Add skip reason for RAW mode transparency
        if (isset($managementCounters['management_skipped_reason'])) {
            $stats['management_skipped_reason'] = $managementCounters['management_skipped_reason'];
        }
        
        // 4. Update active trades (mode-specific)
        // Reload active trades after management commands were applied
        $activeTrades = $this->loadActiveTradesForMode($modeStorageBase);
        foreach ($activeTrades as $tradeId => $trade) {
            $updateResult = $this->updateActiveTradeForMode($trade, $nowTs, $modeStorageBase);
            
            if ($updateResult['status'] === 'closed') {
                $stats['closed_now']++;
                $this->closeTradeForMode($trade, $updateResult, $modeStorageBase);
                $executedIndex['completed'][$tradeId] = [
                    'closed_at' => $updateResult['closed_at'] ?? date('c'),
                    'result' => $updateResult['close_reason'] ?? 'unknown',
                    'roi' => $updateResult['roi'] ?? 0.0,
                ];
                // P3.2: Track CLOSE reasons (take_profit, stop_loss, trailing_stop, etc.)
                if (isset($updateResult['close_reason'])) {
                    $reason = $updateResult['close_reason'];
                    $stats['closed_by_reason'][$reason] = ($stats['closed_by_reason'][$reason] ?? 0) + 1;
                }
            } elseif ($updateResult['status'] === 'rejected') {
                $stats['expired_now']++;
                $this->saveRejectedTradeForMode($trade, $updateResult, $modeStorageBase);
                $this->deleteActiveTradeForMode($tradeId, $modeStorageBase);
                $executedIndex['completed'][$tradeId] = [
                    'closed_at' => $updateResult['closed_at'] ?? date('c'),
                    'result' => $updateResult['close_reason'] ?? 'rejected',
                    'roi' => 0.0,
                ];
                // P3.2: Track REJECT reasons (reject_* prefix)
                $reason = $updateResult['close_reason'] ?? 'rejected';
                $stats['rejected_by_reason'][$reason] = ($stats['rejected_by_reason'][$reason] ?? 0) + 1;
            } elseif ($updateResult['status'] === 'expired_entry') {
                $stats['expired_now']++;
                $this->saveRejectedTradeForMode($trade, $updateResult, $modeStorageBase);
                $this->deleteActiveTradeForMode($tradeId, $modeStorageBase);
                $executedIndex['completed'][$tradeId] = [
                    'closed_at' => $updateResult['closed_at'] ?? date('c'),
                    'result' => 'expired_entry_timeout',
                    'roi' => 0.0,
                ];
                // P3.2: This is a REJECTION (entry never filled)
                $stats['rejected_by_reason']['expired_entry_timeout'] = ($stats['rejected_by_reason']['expired_entry_timeout'] ?? 0) + 1;
            } else {
                $stats['updated_active']++;
            }
        }
        
        // 5. Open new trades
        // P0.3: Mode-aware limits calculation
        // CLEAN mode: limits from first valid signal.risk.limits
        // RAW mode: limits from risk_active.json
        $limits = [];
        $limitsSource = 'unknown';
        $limitsProfileId = null;
        
        if ($mode === 'clean') {
            // CLEAN mode: find first signal with valid risk.limits
            foreach ($eligibleSignals as $signal) {
                if (!empty($signal['risk']['limits'])) {
                    $limits = $signal['risk']['limits'];
                    $limitsSource = 'signal.risk';
                    $limitsProfileId = $signal['risk']['profile_id'] ?? $signal['profile_id'] ?? 'brain_signal';
                    break;
                }
            }
            // Fallback to Brain active profile if no signals have limits
            if (empty($limits)) {
                $brainRisk = $this->loadBrainActiveRiskProfile();
                if (!$brainRisk['is_fallback']) {
                    $limits = $brainRisk['limits'] ?? [];
                    $limitsSource = 'brain_active_fallback';
                    $limitsProfileId = $brainRisk['profile_id'] ?? 'brain_active';
                }
            }
        } else {
            // RAW mode: limits ONLY from risk_active.json
            $brainRisk = $this->loadBrainActiveRiskProfile();
            if (!$brainRisk['is_fallback']) {
                $limits = $brainRisk['limits'] ?? [];
                $limitsSource = 'brain_risk_active';
                $limitsProfileId = $brainRisk['profile_id'] ?? 'brain_active';
            }
        }
        
        $maxOpenTradesConfig = (int)($limits['max_open_trades'] ?? 0);
        $maxOpenTrades = $maxOpenTradesConfig <= 0 ? PHP_INT_MAX : $maxOpenTradesConfig;
        $maxOpenTradesEffective = $maxOpenTradesConfig <= 0 ? 'unlimited' : $maxOpenTradesConfig;
        $oneTradePerSymbol = (bool)($limits['one_trade_per_symbol'] ?? true);
        
        $stats['max_open_trades_effective'] = $maxOpenTradesEffective;
        $stats['one_trade_per_symbol_effective'] = $oneTradePerSymbol;
        $stats['limits_source'] = $limitsProfileId ?? 'default';
        $stats['limits_missing'] = empty($limits);
        
        $currentOpenCount = count($this->loadActiveTradesForMode($modeStorageBase));
        $activeSymbols = $this->getActiveSymbolsForMode($modeStorageBase);

        // P4.3 BUGFIX: In one_trade_per_symbol mode, lock symbol within this RUN
        // even if the first attempt was rejected (prevents duplicate reject_late for same symbol).
        $batchSymbols = [];
        
        foreach ($eligibleSignals as $signal) {
            if ($maxOpenTrades > 0 && $currentOpenCount >= $maxOpenTrades) {
                break;
            }
            
            $symbol = $signal['symbol'] ?? '';

            // P4.3 BUGFIX: unify tradeId for this signal
            $tradeId = $signal['id'] ?? $this->generateSignalId($signal);

            // Skip if already completed during this same RUN (can happen after entry reject gets recorded)
            if (isset(($executedIndex['completed'] ?? [])[$tradeId])) {
                continue;
            }

            // Batch lock by symbol (prevents duplicates inside one RUN)
            if ($oneTradePerSymbol) {
                if (isset($batchSymbols[$symbol])) {
                    continue;
                }
                $batchSymbols[$symbol] = true;
            }
            
            if ($oneTradePerSymbol && isset($activeSymbols[$symbol])) {
                continue;
            }
            
            // Count entry_action types
            $entryAction = $signal['entry_action'] ?? 'enter_now';
            if ($entryAction === 'enter_now') {
                $stats['enter_now_count']++;
            } elseif ($entryAction === 'wait_retrace') {
                $stats['wait_retrace_count']++;
            }
            
            // C-5: CLEAN mode REQUIRES risk block from Brain - reject if missing
            if ($mode === 'clean' && empty($signal['risk'])) {
                $this->rejections[] = "reject_missing_risk_block: {$symbol} - CLEAN mode requires Brain risk block";
                $stats['rejections_count']++;
                // P3.2: This is a REJECTION reason
                $stats['rejected_by_reason']['reject_missing_risk_block'] = ($stats['rejected_by_reason']['reject_missing_risk_block'] ?? 0) + 1;
                
                // Save rejected trade for audit trail
                $rejectTrade = [
                    'trade_id' => $tradeId,
                    'signal_id' => $signal['id'] ?? null,
                    'symbol' => $symbol,
                    'side' => $signal['side'] ?? 'unknown',
                    'reject_reason' => 'reject_missing_risk_block',
                    'reject_details' => 'CLEAN mode requires Brain risk block but signal has no risk field',
                    'rejected_at' => date('c'),
                    'rejected_ts' => $nowTs,
                    'mode' => $mode,
                ];
                $this->saveRejectedTradeForMode($rejectTrade, ['close_reason' => 'reject_missing_risk_block'], $modeStorageBase);

                // P4.3 BUGFIX: mark executed index so we don't re-process this signal on next RUN
                $executedIndex['completed'][$tradeId] = [
                    'closed_at' => date('c'),
                    'result' => 'reject_missing_risk_block',
                    'roi' => 0.0,
                ];
                continue;
            }
            
            // Open new trade
            $rejectionCountBefore = count($this->rejections);
            $openedTrade = $this->openNewTradeForMode($signal, $nowTs, $modeStorageBase);
            if ($openedTrade !== null) {
                $stats['opened_now']++;
                $currentOpenCount++;
                $activeSymbols[$symbol] = true;
            } else {
                // P4.3 BUGFIX: If openNewTradeForMode() produced a rejection, mark executed_index
                // so this signal won't be processed again in the next RUN.
                if (count($this->rejections) > $rejectionCountBefore) {
                    $lastRejection = end($this->rejections);

                    if (is_string($lastRejection) && $lastRejection !== '') {
                        // Reason is the prefix before ":" (e.g. "reject_late")
                        $reason = trim(strtok($lastRejection, ':'));
                        if ($reason === '') {
                            $reason = 'rejected';
                        }

                        // Track rejected reasons (generic)
                        $stats['rejected_by_reason'][$reason] = ($stats['rejected_by_reason'][$reason] ?? 0) + 1;
                        if ($reason === 'reject_late') {
                            $stats['reject_late_count']++;
                        }

                        // Mark executed index (entry reject)
                        $executedIndex['completed'][$tradeId] = [
                            'closed_at' => date('c'),
                            'result' => $reason,
                            'roi' => 0.0,
                        ];
                    }
                }
            }
        }
        
        // 6. Update executed index
        $executedIndex['updated_at'] = $ts;
        $this->saveExecutedIndexForMode($executedIndex, $modeStorageBase);
        
        // 7. Calculate aggregate statistics
        $closedTrades = $this->loadClosedTradesForMode($modeStorageBase);
        $stats['total_closed'] = count($closedTrades);
        
        // Calculate win rate, avg ROI, etc.
        if (!empty($closedTrades)) {
            $wins = 0;
            $roiSum = 0.0;
            $roiList = [];
            $durationSum = 0;
            
            foreach ($closedTrades as $trade) {
                $roi = (float)($trade['close_result']['roi'] ?? $trade['roi'] ?? 0);
                $roiList[] = $roi;
                $roiSum += $roi;
                if ($roi > 0) {
                    $wins++;
                }
                // P3.1: Calculate duration from correct timestamp fields (sim_trade_v1 + legacy fallback)
                // Priority: entry.opened_ts → opened_ts
                $openedTs = (int)($trade['entry']['opened_ts'] ?? $trade['opened_ts'] ?? 0);
                // Priority: close_result.close_ts → closed_ts
                $closedTs = (int)($trade['close_result']['close_ts'] ?? $trade['closed_ts'] ?? 0);
                if ($openedTs > 0 && $closedTs > $openedTs) {
                    $durationSum += ($closedTs - $openedTs) / 60; // minutes
                }
            }
            
            $stats['win_rate'] = round($wins / count($closedTrades), 4);
            $stats['avg_roi'] = round($roiSum / count($closedTrades), 4);
            
            // Median ROI
            sort($roiList);
            $mid = count($roiList) / 2;
            $stats['median_roi'] = count($roiList) % 2 === 0
                ? ($roiList[$mid - 1] + $roiList[$mid]) / 2
                : $roiList[(int)floor($mid)];
            $stats['median_roi'] = round($stats['median_roi'], 4);
            
            $stats['duration_avg_min'] = round($durationSum / count($closedTrades), 2);
        } else {
            $stats['win_rate'] = 0;
            $stats['avg_roi'] = 0;
            $stats['median_roi'] = 0;
            $stats['duration_avg_min'] = 0;
        }
        
        // Count rejected trades
        $rejectedTrades = $this->loadRejectedTradesForMode($modeStorageBase);
        $stats['rejected_count'] = count($rejectedTrades);
        
        // P0.2: Mode-aware effective_risk calculation
        // FORBIDDEN: Taking effective_risk from old trades as "source of truth"
        // RAW mode: ONLY from risk_active.json
        // CLEAN mode: from first signal with risk, fallback to risk_active.json
        $effectiveRisk = null;
        
        if ($mode === 'raw') {
            // RAW mode: effective_risk ONLY from brain/storage/runtime/risk_active.json
            $brainRiskForEffective = $this->loadBrainActiveRiskProfile();
            if (!$brainRiskForEffective['is_fallback']) {
                $effectiveRisk = [
                    'profile_id' => $brainRiskForEffective['profile_id'] ?? 'unknown',
                    'risk_source' => 'brain_risk_active',
                    'risk_hash' => sha1(json_encode($brainRiskForEffective)),
                    'risk' => [
                        'budget_usdt_per_trade' => $brainRiskForEffective['budget_usdt_per_trade'] ?? 0,
                        'leverage' => $brainRiskForEffective['leverage'] ?? 0,
                        'stop_from_liq_range_pct' => $brainRiskForEffective['stop_from_liq_range_pct'] ?? 0,
                        'slippage_bps' => $brainRiskForEffective['slippage_bps'] ?? 0,
                        'fees_bps' => $brainRiskForEffective['fees_bps'] ?? 0,
                        'order_type' => $brainRiskForEffective['order_type'], // P2.1: no fallback
                        'trailing' => $brainRiskForEffective['trailing'] ?? [],
                        'take_profit' => $brainRiskForEffective['take_profit'] ?? [],
                        'limits' => $brainRiskForEffective['limits'] ?? [],
                    ],
                ];
            }
            // If is_fallback=true → effective_risk stays null (no substitution)
        } else {
            // CLEAN mode: from loaded signals with risk, fallback to risk_active.json
            // Try to find first signal with valid risk block
            $signals = $this->loadSignalsByMode('clean');
            foreach ($signals as $signal) {
                if (!empty($signal['risk'])) {
                    $signalRisk = $signal['risk'];
                    $effectiveRisk = [
                        'profile_id' => $signalRisk['profile_id'] ?? $signal['profile_id'] ?? 'brain_signal',
                        'risk_source' => 'brain_signal',
                        'risk_hash' => sha1(json_encode($signalRisk)),
                        'risk' => $signalRisk,
                    ];
                    break;
                }
            }
            // Fallback to risk_active.json if no signals with risk
            if ($effectiveRisk === null) {
                $brainRiskForEffective = $this->loadBrainActiveRiskProfile();
                if (!$brainRiskForEffective['is_fallback']) {
                    $effectiveRisk = [
                        'profile_id' => $brainRiskForEffective['profile_id'] ?? 'unknown',
                        'risk_source' => 'brain_active_profile',
                        'risk_hash' => sha1(json_encode($brainRiskForEffective)),
                        'risk' => [
                            'budget_usdt_per_trade' => $brainRiskForEffective['budget_usdt_per_trade'] ?? 0,
                            'leverage' => $brainRiskForEffective['leverage'] ?? 0,
                            'stop_from_liq_range_pct' => $brainRiskForEffective['stop_from_liq_range_pct'] ?? 0,
                            'slippage_bps' => $brainRiskForEffective['slippage_bps'] ?? 0,
                            'fees_bps' => $brainRiskForEffective['fees_bps'] ?? 0,
                            'order_type' => $brainRiskForEffective['order_type'], // P2.1: no fallback
                            'trailing' => $brainRiskForEffective['trailing'] ?? [],
                            'take_profit' => $brainRiskForEffective['take_profit'] ?? [],
                            'limits' => $brainRiskForEffective['limits'] ?? [],
                        ],
                    ];
                }
            }
        }
        
        // Finalize
        $modeDurationMs = (int)round((microtime(true) - $modeStartTime) * 1000);
        $stats['errors_count'] = count($this->errors);
        $stats['rejections_count'] = count($this->rejections);
        
        $hasErrors = !empty($this->errors);
        $hasRejections = !empty($this->rejections);
        
        if ($hasErrors) {
            $status = 'ok_with_errors';
        } elseif ($hasRejections) {
            $status = 'ok_with_rejections';
        } else {
            $status = 'ok';
        }
        
        $result = [
            'ts' => $ts,
            'ok' => !$hasErrors,
            'status' => $status,
            'mode' => $mode,
            'duration_ms' => $modeDurationMs,
            'errors' => $this->errors,
            'rejections' => $this->rejections,
        ] + $stats;
        
        // C-5: Add effective_risk to result for CLEAN mode
        if ($effectiveRisk !== null) {
            $result['effective_risk'] = $effectiveRisk;
        }
        
        // FIX-4.1: Write all mode-specific artifacts BEFORE last_run.json
        // Per ТЗ: All artifacts must exist in mode storage (raw/clean directories)
        $this->updateStatisticsForMode($modeStorageBase);
        $this->generateSimulationSummaryForMode($modeStorageBase);
        // P3.0: Pass mode storage base to buildState for mode-aware state building
        $this->writeStateForMode($modeStorageBase, $this->buildState($ts, $modeStorageBase));
        $this->rebuildRejectedIndexFromDirForMode($modeStorageBase);
        
        // Write mode-specific last_run.json
        $this->writeModeLastRun($result, $modeStorageBase);
        
        return $result;
    }
    
    /**
     * Compute comparison delta between raw and clean results
     * Per ТЗ: delta = clean - raw for numeric metrics
     *
     * @param array $raw RAW mode result
     * @param array $clean CLEAN mode result
     * @return array Delta values
     */
    private function computeComparison(array $raw, array $clean): array
    {
        $numericKeys = [
            'signals_loaded', 'signals_eligible', 'opened_now', 'closed_now',
            'total_closed', 'rejected_count', 'enter_now_count', 'wait_retrace_count',
            'reject_late_count', 'win_rate', 'avg_roi', 'median_roi', 'duration_avg_min',
        ];
        
        $delta = [];
        
        // Compute delta for numeric fields
        foreach ($numericKeys as $key) {
            $rawVal = is_numeric($raw[$key] ?? null) ? (float)$raw[$key] : 0;
            $cleanVal = is_numeric($clean[$key] ?? null) ? (float)$clean[$key] : 0;
            $delta[$key] = round($cleanVal - $rawVal, 4);
        }
        
        // P3.2: Compute delta for closed_by_reason map (close reasons like take_profit, stop_loss, trailing_stop)
        $rawClosedReasons = $raw['closed_by_reason'] ?? [];
        $cleanClosedReasons = $clean['closed_by_reason'] ?? [];
        $allClosedReasons = array_unique(array_merge(array_keys($rawClosedReasons), array_keys($cleanClosedReasons)));
        
        $delta['closed_by_reason'] = [];
        foreach ($allClosedReasons as $reason) {
            $rawCount = (int)($rawClosedReasons[$reason] ?? 0);
            $cleanCount = (int)($cleanClosedReasons[$reason] ?? 0);
            $delta['closed_by_reason'][$reason] = $cleanCount - $rawCount;
        }
        
        // P3.2: Compute delta for rejected_by_reason map (rejection reasons like reject_late, expired_entry_timeout)
        $rawRejectedReasons = $raw['rejected_by_reason'] ?? [];
        $cleanRejectedReasons = $clean['rejected_by_reason'] ?? [];
        $allRejectedReasons = array_unique(array_merge(array_keys($rawRejectedReasons), array_keys($cleanRejectedReasons)));
        
        $delta['rejected_by_reason'] = [];
        foreach ($allRejectedReasons as $reason) {
            $rawCount = (int)($rawRejectedReasons[$reason] ?? 0);
            $cleanCount = (int)($cleanRejectedReasons[$reason] ?? 0);
            $delta['rejected_by_reason'][$reason] = $cleanCount - $rawCount;
        }
        
        return $delta;
    }

    /**
     * Public entrypoint for running simulation in a specific mode
     * 
     * Per TZ: Controllers should use run($mode) instead of execute()
     * 
     * @param string $mode 'clean'|'raw'|'compare'
     * @return array Run result
     */
    public function run(string $mode = 'clean'): array
    {
        // Validate mode
        if (!in_array($mode, ['clean', 'raw', 'compare'], true)) {
            $mode = 'clean';
        }
        
        // If mode is 'compare', delegate to execute() which runs comparison
        if ($mode === 'compare') {
            return $this->execute();
        }
        
        // For clean/raw mode, run single mode simulation
        return $this->runSimulationMode($mode);
    }
}

/* RULES
 * NO HARDCODE. CONFIG FIRST. SystemPaths ONLY.
 * This file is a FACADE - all implementation is in lib/*.php traits.
 * Only facade methods (__construct, execute, executeComparison, runSimulationMode, computeComparison, run) belong here.
 * All other methods must be in the appropriate trait file.
 */
