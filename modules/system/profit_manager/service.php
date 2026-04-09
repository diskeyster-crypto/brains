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
                'items'                       => array_slice($shadowItems, 0, 50),
                'errors'                      => [],
                'warnings'                    => [],
            ];

            $this->store->saveLastRun($result);

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
                $this->botTradeLoadDiag['error'] = 'active_trades_dir_not_found';
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
                    'bot_stop_context'            => ($prevState['bot_comparison']['bot_effective_stop_price'] ?? null),
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
