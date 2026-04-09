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

            // Build compact journal entries (one per managed position).
            // Includes the explicit PM-8 stage progression so runtime can show
            // which stages each position reached (not only final outcome).
            $journalItems = [];
            foreach ($items as $item) {
                $journalItems[] = [
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
                    // Timestamp
                    'updated_at'                            => $ts,
                ];
            }

            // Save compact active-owner journal — always overwrite, no stale payload
            $this->store->saveActiveOwnerJournal([
                'ts'                                     => $ts,
                'trailing_owner'                         => 'profit_manager',
                'positions_seen'                         => $runResult['positions_total'] ?? 0,
                'positions_managed'                      => $runResult['positions_managed'] ?? 0,
                // PM-8 aggregated counters (proof that PM was the sole active trailing owner)
                'active_owner_symbols_seen_total'        => $pm8Counters['active_owner_symbols_seen_total']        ?? 0,
                'active_owner_symbols_eligible_total'    => $pm8Counters['active_owner_symbols_eligible_total']    ?? 0,
                'active_owner_proposals_computed_total'  => $pm8Counters['active_owner_proposals_computed_total']  ?? 0,
                'active_owner_apply_attempted_total'     => $pm8Counters['active_owner_apply_attempted_total']     ?? 0,
                'active_owner_apply_success_total'       => $pm8Counters['active_owner_apply_success_total']       ?? 0,
                'active_owner_apply_skipped_total'       => $pm8Counters['active_owner_apply_skipped_total']       ?? 0,
                'active_owner_apply_blocked_total'       => $pm8Counters['active_owner_apply_blocked_total']       ?? 0,
                'items'                                  => array_slice($journalItems, 0, 50),
            ]);

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
