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

            // Run shadow trailing compute (no exchange writes)
            $shadowResult = $this->profitManager->runShadow($positions, $this->config);

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
