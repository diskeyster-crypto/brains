<?php

declare(strict_types=1);

namespace Modules\System\Simulator;

use Core\System\SystemPaths;

/**
 * Simulator Controller
 *
 * UI controller for Parser6 Simulator.
 * Displays trades, statistics, and provides run/reset actions.
 */
final class SimulatorController
{
    private ?string $moduleBase;            // FIX-4.3: Nullable - no fallback path
    private ?string $storageDir;
    private ?string $storageDirEffective;   // Mode-specific storage directory
    private string $currentMode;            // Current mode: 'clean'|'raw'|'compare'
    private array $config;
    private ?string $configError = null;    // FIX-4.3: Config error message if moduleBase is null

    public function __construct()
    {
        $this->moduleBase = $this->resolveModuleBase();
        
        // FIX-4.3: If moduleBase is null, mark config error and skip further initialization
        if ($this->moduleBase === null) {
            $this->configError = 'module_base_unknown';
            $this->storageDir = null;
            $this->storageDirEffective = null;
            $this->currentMode = 'clean';
            $this->config = [];
            return;
        }
        
        $this->storageDir = $this->moduleBase . '/storage';
        $this->config = $this->loadConfig();
        // Initialize mode from query parameter, default to 'clean'
        $this->currentMode = $this->getRequestedMode();
        $this->storageDirEffective = $this->getEffectiveStorageDir($this->currentMode);
    }

    /**
     * Get requested mode from query parameter
     * @return string 'clean'|'raw'|'compare'
     */
    private function getRequestedMode(): string
    {
        $mode = $_GET['mode'] ?? 'clean';
        // Validate mode
        if (!in_array($mode, ['clean', 'raw', 'compare'], true)) {
            $mode = 'clean';
        }
        return $mode;
    }

    /**
     * Get effective storage directory based on mode
     * Uses SystemPaths to resolve base storage and appends mode-specific subdirectory
     * MUST match Parser6SimulatorService::getModeStorageBase() logic exactly
     * @param string $mode 'clean'|'raw'|'compare'
     * @return string Absolute path to mode-specific storage
     */
    private function getEffectiveStorageDir(string $mode): string
    {
        // For 'compare' mode, use root storage (contains comparison last_run.json)
        if ($mode === 'compare') {
            return $this->storageDir;
        }

        // Get comparison config
        $comparison = $this->config['comparison'] ?? [];
        
        // If comparison is disabled, always use root storage
        if (empty($comparison['enabled'])) {
            return $this->storageDir;
        }

        // FIX: RAW mode storage path must match service's getModeStorageBase()
        // Service uses brain/storage/simulator/raw when brain storage is available
        if ($mode === 'raw') {
            $brainKey = $this->config['management']['commands_storage_key'] ?? 'system.brain.storage';
            $paths = SystemPaths::instance();
            try {
                if ($paths->has($brainKey)) {
                    $brainStorage = $paths->get($brainKey);
                    if ($brainStorage && is_dir($brainStorage)) {
                        return $brainStorage . '/simulator/raw';
                    }
                }
            } catch (\Throwable $e) {
                // Fallback to local storage if brain storage key is unavailable or inaccessible
            }
        }

        // CLEAN mode (always) or RAW mode fallback (when brain storage unavailable): 
        // use local storage with mode subdirectory
        $rawDir = $comparison['storage']['raw_dir'] ?? 'raw';
        $cleanDir = $comparison['storage']['clean_dir'] ?? 'clean';
        $subDir = ($mode === 'raw') ? $rawDir : $cleanDir;
        return $this->storageDir . '/' . $subDir;
    }

    /**
     * Main dashboard view
     */
    public function index(): void
    {
        // FIX-4.3: Check for config error before proceeding
        if ($this->configError !== null) {
            $this->render('index', [
                'config_error' => $this->configError,
                'state' => [],
                'last_run' => [],
                'summary' => [],
                'stats_global' => [],
                'active_trades' => [],
                'closed_trades' => [],
                'rejected_trades' => [],
                'config' => [],
                'mode' => $this->currentMode,
                'comparison' => null,
            ]);
            return;
        }
        
        // Use mode-specific storage for loading data
        $state = $this->loadJsonFromEffective('state.json');
        $lastRun = $this->loadJsonFromEffective('last_run.json');
        $summary = $this->loadJsonFromEffective('stats/summary.json');
        $statsGlobal = $this->loadJsonFromEffective('stats_global.json'); // Per ТЗ spec
        $activeTrades = $this->loadActiveTrades();
        $closedTrades = $this->loadClosedTrades(20); // Last 20
        $rejectedTrades = $this->loadRejectedTrades(10); // Last 10

        // For compare mode, load comparison data from root storage
        $comparison = null;
        if ($this->currentMode === 'compare') {
            $rootLastRun = $this->loadJson('last_run.json');
            if (isset($rootLastRun['raw']) && isset($rootLastRun['clean'])) {
                $comparison = [
                    'raw' => $rootLastRun['raw'] ?? [],
                    'clean' => $rootLastRun['clean'] ?? [],
                    'delta' => $rootLastRun['delta'] ?? [],
                ];
            }
        }

        // C-5: Load effective_risk from last_run or active trades
        $effectiveRisk = $lastRun['effective_risk'] ?? null;
        
        // If no effective_risk in last_run, try to get from active trades
        if ($effectiveRisk === null && !empty($activeTrades)) {
            foreach ($activeTrades as $trade) {
                if (!empty($trade['risk']) && ($trade['risk_source'] ?? '') === 'brain_signal') {
                    $effectiveRisk = [
                        'profile_id' => $trade['profile_id'] ?? 'unknown',
                        'risk' => $trade['risk'],
                        'risk_source' => 'brain_signal',
                        'risk_hash' => $trade['risk_hash'] ?? null,
                    ];
                    break;
                }
            }
        }
        
        $viewData = [
            'state' => $state,
            'last_run' => $lastRun,
            'summary' => $summary,
            'stats_global' => $statsGlobal,
            'active_trades' => $activeTrades,
            'closed_trades' => $closedTrades,
            'rejected_trades' => $rejectedTrades,
            'config' => $this->config,
            'mode' => $this->currentMode,
            'comparison' => $comparison,
            'effective_risk' => $effectiveRisk,  // C-5: Pass effective_risk to view
            'parser_statuses' => $this->loadParserStatuses(),  // Parser status for header
        ];

        $this->render('index', $viewData);
    }

    /**
     * API: Get current state
     */
    public function apiState(): void
    {
        header('Content-Type: application/json');

        // FIX-4.3: Return error if moduleBase is not configured
        if ($this->configError !== null) {
            echo json_encode([
                'success' => false,
                'error' => $this->configError,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            return;
        }

        echo json_encode([
            'success' => true,
            'mode' => $this->currentMode,
            'state' => $this->loadJsonFromEffective('state.json'),
            'last_run' => $this->loadJsonFromEffective('last_run.json'),
            'summary' => $this->loadJsonFromEffective('stats/summary.json'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * API: Get active trades
     */
    public function apiTradesActive(): void
    {
        header('Content-Type: application/json');

        // FIX-4.3: Return error if moduleBase is not configured
        if ($this->configError !== null) {
            echo json_encode([
                'success' => false,
                'error' => $this->configError,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            return;
        }

        $trades = $this->loadActiveTrades();

        echo json_encode([
            'success' => true,
            'count' => count($trades),
            'trades' => array_values($trades),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * API: Get closed trades
     */
    public function apiTradesClosed(): void
    {
        header('Content-Type: application/json');

        // TASK 7: Guard against configError
        if ($this->configError !== null) {
            echo json_encode([
                'success' => false,
                'error' => $this->configError,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            return;
        }

        $limit = (int)($_GET['limit'] ?? 50);
        $offset = (int)($_GET['offset'] ?? 0);

        $trades = $this->loadClosedTrades($limit, $offset);

        echo json_encode([
            'success' => true,
            'count' => count($trades),
            'trades' => array_values($trades),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * API: Get single trade details
     * D2: Use effective storage dir based on mode
     */
    public function apiTrade(): void
    {
        header('Content-Type: application/json');

        // D1: Check config error
        if ($this->configError !== null) {
            echo json_encode([
                'success' => false,
                'error' => $this->configError,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            return;
        }

        $id = $_GET['id'] ?? '';
        if ($id === '') {
            echo json_encode(['success' => false, 'error' => 'missing_id']);
            return;
        }

        // D2: Use effective storage dir based on mode
        $effectiveDir = $this->storageDirEffective ?? $this->storageDir;

        // Try active first
        $activePath = $effectiveDir . '/trades/active/' . $id . '.json';
        if (is_file($activePath)) {
            $trade = json_decode(file_get_contents($activePath), true);
            $trade['_status'] = 'active';
            $trade['_mode'] = $this->currentMode;
            echo json_encode(['success' => true, 'trade' => $trade]);
            return;
        }

        // Try closed
        $closedPath = $effectiveDir . '/trades/closed/' . $id . '.json';
        if (is_file($closedPath)) {
            $trade = json_decode(file_get_contents($closedPath), true);
            $trade['_status'] = 'closed';
            $trade['_mode'] = $this->currentMode;

            // Load dataset if exists
            $datasetPath = $effectiveDir . '/dataset/' . $id . '.jsonl';
            if (is_file($datasetPath)) {
                $dataset = [];
                $lines = file($datasetPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach (array_slice($lines, -100) as $line) {
                    $row = json_decode($line, true);
                    if ($row) {
                        $dataset[] = $row;
                    }
                }
                $trade['_dataset'] = $dataset;
            }

            echo json_encode(['success' => true, 'trade' => $trade]);
            return;
        }

        echo json_encode(['success' => false, 'error' => 'trade_not_found', 'mode' => $this->currentMode]);
    }

    /**
     * API: Run one simulation step
     */
    public function apiRun(): void
    {
        header('Content-Type: application/json');

        // FIX-4.3: Return error if moduleBase is not configured
        if ($this->configError !== null) {
            echo json_encode([
                'success' => false,
                'error' => $this->configError,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
            return;
        }

        try {
            $servicePath = $this->moduleBase . '/service.php';
            if (is_file($servicePath)) {
                require_once $servicePath;
            }

            if (!class_exists('Parser6SimulatorService')) {
                echo json_encode(['success' => false, 'error' => 'service_class_not_found']);
                return;
            }

            // Get mode from request (POST body or query parameter)
            $mode = $_POST['mode'] ?? $_GET['mode'] ?? 'clean';
            if (!in_array($mode, ['clean', 'raw', 'compare'], true)) {
                $mode = 'clean';
            }

            $service = new \Parser6SimulatorService();
            // Use run($mode) instead of execute() per TZ requirement
            $result = $service->run($mode);

            echo json_encode([
                'success' => true,
                'result' => $result,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * API: Reset simulator (clear all storage)
     * C5.2: Updated to clear root + raw + clean directories
     */
    public function apiReset(): void
    {
        header('Content-Type: application/json');

        // FIX-4.3: Return error if moduleBase is not configured
        if ($this->configError !== null) {
            echo json_encode([
                'success' => false,
                'error' => $this->configError,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
            return;
        }

        // Verify confirmation
        $confirm = $_POST['confirm'] ?? '';
        if ($confirm !== 'yes') {
            echo json_encode(['success' => false, 'error' => 'confirmation_required']);
            return;
        }

        try {
            $deleted = 0;
            
            // C5.2: Clear root + raw + clean directories
            $dirsToReset = [
                $this->storageDir,
                $this->storageDir . '/raw',
                $this->storageDir . '/clean',
            ];
            
            foreach ($dirsToReset as $baseDir) {
                if (!is_dir($baseDir)) {
                    continue;
                }
                
                // Clear active trades
                $activeDir = $baseDir . '/trades/active';
                if (is_dir($activeDir)) {
                    foreach (glob($activeDir . '/*.json') as $file) {
                        unlink($file);
                        $deleted++;
                    }
                }

                // Clear closed trades
                $closedDir = $baseDir . '/trades/closed';
                if (is_dir($closedDir)) {
                    foreach (glob($closedDir . '/*.json') as $file) {
                        unlink($file);
                        $deleted++;
                    }
                }
                
                // Clear rejected trades
                $rejectedDir = $baseDir . '/trades/rejected';
                if (is_dir($rejectedDir)) {
                    foreach (glob($rejectedDir . '/*.json') as $file) {
                        unlink($file);
                        $deleted++;
                    }
                }

                // Clear dataset
                $datasetDir = $baseDir . '/dataset';
                if (is_dir($datasetDir)) {
                    foreach (glob($datasetDir . '/*.jsonl') as $file) {
                        unlink($file);
                        $deleted++;
                    }
                }
                
                // Clear training datasets
                $trainingFiles = [
                    $baseDir . '/training_dataset.ndjson',
                    $baseDir . '/training_trailing_sim.ndjson',
                    $baseDir . '/trades_rejected.json',
                    $baseDir . '/events.ndjson',
                    $baseDir . '/state.json',
                    $baseDir . '/simulation.json',
                    $baseDir . '/last_run.json',
                    $baseDir . '/stats_global.json',
                    $baseDir . '/executed_index.json',
                    $baseDir . '/stats/summary.json',
                ];
                
                foreach ($trainingFiles as $file) {
                    if (is_file($file)) {
                        unlink($file);
                        $deleted++;
                    }
                }
            }

            // Reset executed index (root)
            $this->writeJson('executed_index.json', [
                'updated_at' => date('c'),
                'completed' => [],
            ]);

            // Reset state (root)
            $this->writeJson('state.json', [
                'ts' => date('c'),
                'ok' => true,
                'status' => 'reset',
                'open_trades' => 0,
                'closed_trades' => 0,
                'signals_seen' => 0,
                'signals_skipped' => 0,
                'winrate' => 0,
                'roi_sum' => 0,
                'errors_count' => 0,
            ]);

            // Reset statistics (root)
            $this->writeJson('stats/summary.json', [
                'updated_at' => date('c'),
                'total_trades_opened' => 0,
                'total_trades_closed' => 0,
                'wins' => 0,
                'losses' => 0,
                'winrate' => 0,
                'roi_sum' => 0,
                'roi_avg' => 0,
                'max_drawdown_roi' => 0,
                'avg_duration_minutes' => 0,
                'profit_factor' => 0,
                'closed_by' => [
                    'tp' => 0,
                    'sl' => 0,
                    'trailing_sl' => 0,
                    'expired_signal' => 0,
                    'expired_entry_timeout' => 0,
                ],
            ]);
            
            // Reset stats_global.json (root)
            $this->writeJson('stats_global.json', [
                'total_trades' => 0,
                'wins' => 0,
                'losses' => 0,
                'winrate' => 0,
                'total_roi' => 0,
                'avg_roi' => 0,
                'max_drawdown' => 0,
                'avg_duration' => 0,
                'profit_factor' => 0,
            ]);
            
            // Reset ticks_last_seen.json
            $this->writeJson('ticks_last_seen.json', [
                'updated_at' => date('c'),
                'symbols' => [],
            ]);
            
            // P2.2: Create baseline files for ROOT/RAW/CLEAN
            $baseDirsToCreate = [
                $this->storageDir,
                $this->storageDir . '/raw',
                $this->storageDir . '/clean',
            ];
            
            foreach ($baseDirsToCreate as $baseDir) {
                // Ensure directories exist
                $subDirs = [
                    '/trades/active',
                    '/trades/closed',
                    '/trades/rejected',
                    '/stats',
                    '/logs',
                ];
                foreach ($subDirs as $subDir) {
                    $fullPath = $baseDir . $subDir;
                    if (!is_dir($fullPath)) {
                        @mkdir($fullPath, 0755, true);
                    }
                }
                
                // Write baseline files
                $baselineExecutedIndex = json_encode([
                    'updated_at' => date('c'),
                    'completed' => [],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                file_put_contents($baseDir . '/executed_index.json', $baselineExecutedIndex);
                
                $baselineState = json_encode([
                    'ts' => date('c'),
                    'ok' => true,
                    'status' => 'reset',
                    'open_trades' => 0,
                    'closed_trades' => 0,
                    'signals_seen' => 0,
                    'signals_skipped' => 0,
                    'winrate' => 0,
                    'roi_sum' => 0,
                    'errors_count' => 0,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                file_put_contents($baseDir . '/state.json', $baselineState);
                
                $baselineSimulation = json_encode([
                    'updated_at' => date('c'),
                    'total_signals' => 0,
                    'processed' => 0,
                    'closed' => 0,
                    'rejected' => 0,
                    'source' => 'reset',
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                file_put_contents($baseDir . '/simulation.json', $baselineSimulation);
                
                $baselineStatsGlobal = json_encode([
                    'total_trades' => 0,
                    'total_closed' => 0,
                    'total_rejected' => 0,
                    'wins' => 0,
                    'losses' => 0,
                    'winrate' => 0,
                    'total_roi' => 0,
                    'avg_roi' => 0,
                    'max_drawdown' => 0,
                    'avg_duration' => 0,
                    'profit_factor' => 0,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                file_put_contents($baseDir . '/stats_global.json', $baselineStatsGlobal);
                
                $baselineStatsSummary = json_encode([
                    'updated_at' => date('c'),
                    'total_trades_opened' => 0,
                    'total_trades_closed' => 0,
                    'wins' => 0,
                    'losses' => 0,
                    'winrate' => 0,
                    'roi_sum' => 0,
                    'roi_avg' => 0,
                    'max_drawdown_roi' => 0,
                    'avg_duration_minutes' => 0,
                    'profit_factor' => 0,
                    'closed_by' => [
                        'tp' => 0,
                        'sl' => 0,
                        'trailing_sl' => 0,
                        'expired_signal' => 0,
                        'expired_entry_timeout' => 0,
                    ],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                if (!is_dir($baseDir . '/stats')) {
                    @mkdir($baseDir . '/stats', 0755, true);
                }
                file_put_contents($baseDir . '/stats/summary.json', $baselineStatsSummary);
                
                $baselineLastRun = json_encode([
                    'ts' => date('c'),
                    'ok' => true,
                    'status' => 'reset',
                    'duration_ms' => 0,
                    'signals_loaded' => 0,
                    'signals_eligible' => 0,
                    'opened_now' => 0,
                    'updated_active' => 0,
                    'closed_now' => 0,
                    'expired_now' => 0,
                    'errors_count' => 0,
                    'rejections_count' => 0,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                file_put_contents($baseDir . '/last_run.json', $baselineLastRun);
                
                $baselineTradesRejected = json_encode([], JSON_PRETTY_PRINT);
                file_put_contents($baseDir . '/trades_rejected.json', $baselineTradesRejected);
            }

            echo json_encode([
                'success' => true,
                'message' => 'Simulator storage has been reset (root + raw + clean)',
                'deleted' => $deleted,
            ]);
        } catch (\Throwable $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * API: Get configuration (Part 1 of TZ)
     * 
     * Returns merged config from config.php + user_config.json
     */
    public function apiGetConfig(): void
    {
        header('Content-Type: application/json');
        
        $config = $this->loadConfigWithUserOverrides();
        
        echo json_encode([
            'success' => true,
            'config' => $config,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
    
    /**
     * API: Save configuration (Part 1 of TZ)
     * 
     * Saves user config overrides to user_config.json (atomic write)
     */
    public function apiSaveConfig(): void
    {
        header('Content-Type: application/json');
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
            return;
        }
        
        try {
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            if (!is_array($data) || empty($data['config'])) {
                echo json_encode(['success' => false, 'error' => 'invalid_config_data']);
                return;
            }
            
            $newConfig = $data['config'];
            
            // Validate config structure
            $allowedKeys = ['risk', 'execution', 'trailing', 'policy', 'dataset', 'live_price'];
            $filteredConfig = [];
            foreach ($allowedKeys as $key) {
                if (isset($newConfig[$key])) {
                    $filteredConfig[$key] = $newConfig[$key];
                }
            }
            
            // Atomic write: tmp -> rename
            $userConfigPath = $this->storageDir . '/user_config.json';
            $tmpPath = $userConfigPath . '.tmp.' . uniqid();
            
            $jsonData = json_encode([
                'updated_at' => date('c'),
                'config' => $filteredConfig,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            
            if (file_put_contents($tmpPath, $jsonData) === false) {
                echo json_encode(['success' => false, 'error' => 'write_failed']);
                return;
            }
            
            if (!rename($tmpPath, $userConfigPath)) {
                @unlink($tmpPath);
                echo json_encode(['success' => false, 'error' => 'rename_failed']);
                return;
            }
            
            // Log config_updated event
            $this->logEvent('config_updated', ['keys' => array_keys($filteredConfig)]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Configuration saved',
            ]);
        } catch (\Throwable $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * API: Reset user config to defaults (Part 1 of TZ)
     */
    public function apiResetConfig(): void
    {
        header('Content-Type: application/json');
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
            return;
        }
        
        try {
            $userConfigPath = $this->storageDir . '/user_config.json';
            if (is_file($userConfigPath)) {
                unlink($userConfigPath);
            }
            
            $this->logEvent('config_reset', []);
            
            echo json_encode([
                'success' => true,
                'message' => 'Configuration reset to defaults',
                'config' => $this->loadConfig(),
            ]);
        } catch (\Throwable $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * API: Get trade candles for chart (Part 2 of TZ)
     * C3: Support mode parameter and fallback to trade events if Parser2 data is missing
     * 
     * Returns kline data for trade visualization
     */
    public function apiTradeCandles(): void
    {
        header('Content-Type: application/json');
        
        $tradeId = $_GET['id'] ?? '';
        if ($tradeId === '') {
            echo json_encode(['success' => false, 'error' => 'missing_trade_id']);
            return;
        }
        
        // C3: Support mode parameter for mode-specific storage
        $mode = $_GET['mode'] ?? $this->currentMode;
        if (!in_array($mode, ['clean', 'raw', 'compare'], true)) {
            $mode = 'clean';
        }
        
        // Load trade from mode-specific storage
        $trade = $this->findTradeInMode($tradeId, $mode);
        if ($trade === null) {
            // Fallback to default findTrade
            $trade = $this->findTrade($tradeId);
        }
        
        if ($trade === null) {
            echo json_encode(['success' => false, 'error' => 'trade_not_found']);
            return;
        }
        
        $symbol = $trade['symbol'] ?? '';
        if ($symbol === '') {
            echo json_encode(['success' => false, 'error' => 'missing_symbol']);
            return;
        }
        
        try {
            $candles = $this->fetchTradeCandles($trade);
            $dataSource = 'parser2';
            
            // C3: If no candles from Parser2, generate fallback from trade events
            if (empty($candles)) {
                $candles = $this->generateFallbackChart($trade);
                $dataSource = 'fallback_events';
            }
            
            $markers = $this->buildChartMarkers($trade);
            
            echo json_encode([
                'success' => true,
                'symbol' => $symbol,
                'candles' => $candles,
                'markers' => $markers,
                'data_source' => $dataSource,  // C3: Indicate data source
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * C3: Find trade in mode-specific storage
     */
    private function findTradeInMode(string $tradeId, string $mode): ?array
    {
        $effectiveDir = $this->getEffectiveStorageDir($mode);
        
        // Try active first
        $activePath = $effectiveDir . '/trades/active/' . $tradeId . '.json';
        if (is_file($activePath)) {
            $trade = json_decode(file_get_contents($activePath), true);
            if (is_array($trade)) {
                $trade['_status'] = 'active';
                $trade['_mode'] = $mode;
                return $trade;
            }
        }
        
        // Try closed
        $closedPath = $effectiveDir . '/trades/closed/' . $tradeId . '.json';
        if (is_file($closedPath)) {
            $trade = json_decode(file_get_contents($closedPath), true);
            if (is_array($trade)) {
                $trade['_status'] = 'closed';
                $trade['_mode'] = $mode;
                return $trade;
            }
        }
        
        return null;
    }
    
    /**
     * C3: Generate fallback chart data from trade events
     * Used when Parser2 candles are not available
     */
    private function generateFallbackChart(array $trade): array
    {
        $candles = [];
        $events = $trade['events'] ?? [];
        
        // P1.7: Get key prices from trade data (sim_trade_v1 compatible)
        // Entry: entry.opened_price > entry.target_price > entry.entry_price_signal
        $entryPrice = (float)($trade['entry']['opened_price'] 
            ?? $trade['entry']['target_price'] 
            ?? $trade['entry']['entry_price_signal'] ?? 0);
        $entryTs = (int)($trade['entry']['opened_ts'] ?? $trade['created_ts'] ?? 0);
        
        // P1.7: Close price from close_result (sim_trade_v1)
        $closeResult = $trade['close_result'] ?? [];
        $closePrice = (float)($closeResult['close_price'] 
            ?? $trade['closed_price']
            ?? $trade['market']['last_price'] ?? 0);
        $closeTs = (int)($closeResult['close_ts'] 
            ?? $trade['closed_ts'] 
            ?? $trade['market']['last_ts_unix'] ?? time());
        
        // P1.7: SL/TP prices (sim_trade_v1 compatible)
        $slPrice = (float)($trade['targets']['stop_loss_current'] 
            ?? $trade['sl_price_current'] 
            ?? $trade['sl_price_initial'] ?? 0);
        $tpPrice = (float)($trade['targets']['take_profit'] ?? 0);
        
        // Build price points from events
        $pricePoints = [];
        
        foreach ($events as $event) {
            $eventPrice = $event['price'] ?? $event['fill_price'] ?? null;
            $eventTs = strtotime($event['ts'] ?? '') ?: 0;
            
            if ($eventPrice !== null && $eventTs > 0) {
                $pricePoints[] = [
                    'ts' => $eventTs,
                    'price' => (float)$eventPrice,
                ];
            }
        }
        
        // Add entry point
        if ($entryPrice > 0 && $entryTs > 0) {
            $pricePoints[] = ['ts' => $entryTs, 'price' => $entryPrice];
        }
        
        // Add close point if trade is closed
        if ($closePrice > 0 && $closeTs > 0 && ($trade['_status'] ?? '') === 'closed') {
            $pricePoints[] = ['ts' => $closeTs, 'price' => $closePrice];
        }
        
        // Sort by timestamp
        usort($pricePoints, fn($a, $b) => $a['ts'] <=> $b['ts']);
        
        // Generate simple candles from price points
        if (!empty($pricePoints)) {
            $candles = $this->generateOHLCFromTicks($pricePoints, 60);
        }
        
        // P1.7: If still empty or only 1 candle, create a minimal chart with at least 3 candles
        // This prevents "No chart data available" message
        if (count($candles) < 3 && $entryPrice > 0 && $entryTs > 0) {
            // Collect valid prices for high/low calculation (filter zeros)
            $validPrices = array_filter([$entryPrice, $slPrice, $tpPrice, $closePrice], fn($p) => $p > 0);
            
            // Ensure we have at least the entry price for high/low
            if (empty($validPrices)) {
                $validPrices = [$entryPrice];
            }
            
            $highPrice = max($validPrices);
            $lowPrice = min($validPrices);
            $finalPrice = $closePrice > 0 ? $closePrice : $entryPrice;
            
            // P1.7: Generate 3 candles (before entry, at entry, after entry/close)
            $candleInterval = 60; // 1 minute
            $candles = [
                // Candle before entry
                [
                    'time' => $entryTs - $candleInterval,
                    'open' => $entryPrice,
                    'high' => $entryPrice,
                    'low' => $lowPrice,
                    'close' => $entryPrice,
                    'volume' => 0,
                ],
                // Entry candle
                [
                    'time' => $entryTs,
                    'open' => $entryPrice,
                    'high' => $highPrice,
                    'low' => $lowPrice,
                    'close' => $finalPrice > 0 ? (($entryPrice + $finalPrice) / 2) : $entryPrice,
                    'volume' => 0,
                ],
                // Close candle
                [
                    'time' => $closeTs > $entryTs ? $closeTs : ($entryTs + $candleInterval),
                    'open' => $finalPrice > 0 ? (($entryPrice + $finalPrice) / 2) : $entryPrice,
                    'high' => $highPrice,
                    'low' => $lowPrice,
                    'close' => $finalPrice,
                    'volume' => 0,
                ],
            ];
        }
        
        return $candles;
    }

    /**
     * API: Export dataset as ZIP
     * P3.3: Mode-aware export - reads from mode-specific storage directory
     */
    public function apiExportDataset(): void
    {
        // P3.3: Get mode from query parameter (valid: raw|clean, default: clean)
        $mode = strtolower($_GET['mode'] ?? 'clean');
        if (!in_array($mode, ['raw', 'clean'], true)) {
            $mode = 'clean';
        }
        
        // P3.3: Use mode-specific storage directory
        $baseDir = $this->getEffectiveStorageDir($mode);
        
        $datasetDir = $baseDir . '/dataset';
        $closedDir = $baseDir . '/trades/closed';

        $tmpZip = sys_get_temp_dir() . '/simulator_export_' . $mode . '_' . time() . '.zip';
        $zip = new \ZipArchive();

        if ($zip->open($tmpZip, \ZipArchive::CREATE) !== true) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'cannot_create_zip']);
            return;
        }

        // Add dataset files
        if (is_dir($datasetDir)) {
            foreach (glob($datasetDir . '/*.jsonl') as $file) {
                $zip->addFile($file, 'dataset/' . basename($file));
            }
        }

        // Add closed trades
        if (is_dir($closedDir)) {
            foreach (glob($closedDir . '/*.json') as $file) {
                $zip->addFile($file, 'trades_closed/' . basename($file));
            }
        }

        // P3.3: Add summary from mode directory
        $summaryPath = $baseDir . '/stats/summary.json';
        if (is_file($summaryPath)) {
            $zip->addFile($summaryPath, 'summary.json');
        }
        
        // P3.3: Add stats_global.json
        $statsGlobalPath = $baseDir . '/stats_global.json';
        if (is_file($statsGlobalPath)) {
            $zip->addFile($statsGlobalPath, 'stats_global.json');
        }
        
        // P3.3: Add last_run.json
        $lastRunPath = $baseDir . '/last_run.json';
        if (is_file($lastRunPath)) {
            $zip->addFile($lastRunPath, 'last_run.json');
        }

        $zip->close();

        // P3.3: Send file with mode in filename
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="simulator_export_' . $mode . '_' . date('Y-m-d_His') . '.zip"');
        header('Content-Length: ' . filesize($tmpZip));
        readfile($tmpZip);
        unlink($tmpZip);
    }

    // =========================================================
    // HELPERS
    // =========================================================

    /**
     * Resolve module base path from SystemPaths
     * FIX-4.3: No fallback path - returns null if not found
     *
     * @return string|null Module base path or null if not configured
     */
    private function resolveModuleBase(): ?string
    {
        $paths = SystemPaths::instance();
        $candidates = ['simulator.parser6_simulator', 'parser.parser6_simulator'];

        foreach ($candidates as $key) {
            try {
                $p = $paths->get($key);
                if (is_string($p) && $p !== '') {
                    return rtrim($p, '/');
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // FIX-4.3: No fallback - return null to indicate config error
        return null;
    }

    private function loadConfig(): array
    {
        $configPath = $this->moduleBase . '/config/config.php';
        if (!is_file($configPath)) {
            return [];
        }
        $cfg = require $configPath;
        return is_array($cfg) ? $cfg : [];
    }

    private function loadJson(string $relativePath): array
    {
        $path = $this->storageDir . '/' . $relativePath;
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    /**
     * Load JSON from mode-effective storage directory
     * @param string $relativePath Relative path within storage
     * @return array Decoded JSON data or empty array
     */
    private function loadJsonFromEffective(string $relativePath): array
    {
        $path = $this->storageDirEffective . '/' . $relativePath;
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    private function writeJson(string $relativePath, array $data): void
    {
        $path = $this->storageDir . '/' . $relativePath;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function loadActiveTrades(): array
    {
        // Use mode-effective storage for trades
        $dir = $this->storageDirEffective . '/trades/active';
        if (!is_dir($dir)) {
            return [];
        }

        $trades = [];
        foreach (glob($dir . '/*.json') as $file) {
            $trade = json_decode(file_get_contents($file), true);
            if (is_array($trade)) {
                // C2.4: Normalize old trades for UI display (without modifying files)
                $trade = $this->normalizeTradeForUI($trade);
                $trades[] = $trade;
            }
        }

        return $trades;
    }
    
    /**
     * C2.4: Normalize trade fields for UI display
     * Adds missing fields that UI expects, without modifying the actual trade files
     * Used for legacy trades that might be missing new schema fields
     */
    private function normalizeTradeForUI(array $trade): array
    {
        // Ensure market block exists
        if (!isset($trade['market'])) {
            $trade['market'] = [
                'last_price' => $trade['entry']['opened_price'] ?? 0,
                'last_ts_unix' => $trade['entry']['opened_ts'] ?? time(),
            ];
        }
        
        // Ensure pnl block has required fields
        if (!isset($trade['pnl'])) {
            $trade['pnl'] = [];
        }
        
        // Calculate roi_unrealized_pct if missing
        if (!isset($trade['pnl']['roi_unrealized_pct'])) {
            // Try to get from existing fields
            if (isset($trade['pnl']['roi_margin'])) {
                $trade['pnl']['roi_unrealized_pct'] = $trade['pnl']['roi_margin'];
            } elseif (isset($trade['pnl']['unrealized'])) {
                // unrealized is a ratio, convert to percentage
                $trade['pnl']['roi_unrealized_pct'] = $trade['pnl']['unrealized'] * 100;
            } else {
                $trade['pnl']['roi_unrealized_pct'] = 0.0;
            }
        }
        
        // Calculate pnl_unrealized_usdt if missing
        if (!isset($trade['pnl']['pnl_unrealized_usdt'])) {
            $budget = $trade['entry']['budget_usdt'] ?? $trade['entry']['margin_usdt'] ?? 50;
            $roiPct = $trade['pnl']['roi_unrealized_pct'] ?? 0;
            $trade['pnl']['pnl_unrealized_usdt'] = $budget * ($roiPct / 100);
        }
        
        // Ensure status field exists
        if (!isset($trade['status'])) {
            // If trade has opened_price, it's open; otherwise pending
            $trade['status'] = isset($trade['entry']['opened_price']) && $trade['entry']['opened_price'] > 0 
                ? 'open' 
                : 'pending_entry';
        }
        
        return $trade;
    }

    private function loadClosedTrades(int $limit = 50, int $offset = 0): array
    {
        // Use mode-effective storage for trades
        $dir = $this->storageDirEffective . '/trades/closed';
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.json');
        // Sort by modification time descending
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));

        $trades = [];
        $count = 0;
        foreach (array_slice($files, $offset, $limit) as $file) {
            $trade = json_decode(file_get_contents($file), true);
            if (is_array($trade)) {
                $trades[] = $trade;
            }
        }

        return $trades;
    }
    
    /**
     * Load rejected trades (per ТЗ spec section 4)
     */
    private function loadRejectedTrades(int $limit = 50, int $offset = 0): array
    {
        // Use mode-effective storage for trades
        $dir = $this->storageDirEffective . '/trades/rejected';
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.json');
        // Sort by modification time descending
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));

        $trades = [];
        foreach (array_slice($files, $offset, $limit) as $file) {
            $trade = json_decode(file_get_contents($file), true);
            if (is_array($trade)) {
                $trades[] = $trade;
            }
        }

        return $trades;
    }

    private function render(string $view, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        $viewPath = __DIR__ . '/views/' . $view . '.php';
        if (is_file($viewPath)) {
            include $viewPath;
        } else {
            echo "View not found: {$view}";
        }
    }
    
    /**
     * Load config with user overrides (Part 1 of TZ)
     */
    private function loadConfigWithUserOverrides(): array
    {
        $baseConfig = $this->loadConfig();
        
        $userConfigPath = $this->storageDir . '/user_config.json';
        if (!is_file($userConfigPath)) {
            return $baseConfig;
        }
        
        $userConfig = json_decode(file_get_contents($userConfigPath), true);
        if (!is_array($userConfig) || !isset($userConfig['config'])) {
            return $baseConfig;
        }
        
        // Deep merge user config over base config
        return $this->deepMerge($baseConfig, $userConfig['config']);
    }
    
    /**
     * Deep merge arrays
     */
    private function deepMerge(array $base, array $override): array
    {
        $result = $base;
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($result[$key]) && is_array($result[$key])) {
                $result[$key] = $this->deepMerge($result[$key], $value);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }
    
    /**
     * Find trade by ID (active or closed)
     */
    private function findTrade(string $tradeId): ?array
    {
        // Try active
        $activePath = $this->storageDir . '/trades/active/' . $tradeId . '.json';
        if (is_file($activePath)) {
            $trade = json_decode(file_get_contents($activePath), true);
            if (is_array($trade)) {
                $trade['_status'] = 'active';
                return $trade;
            }
        }
        
        // Try closed
        $closedPath = $this->storageDir . '/trades/closed/' . $tradeId . '.json';
        if (is_file($closedPath)) {
            $trade = json_decode(file_get_contents($closedPath), true);
            if (is_array($trade)) {
                $trade['_status'] = 'closed';
                return $trade;
            }
        }
        
        return null;
    }
    
    /**
     * Fetch candles for trade from Bybit (Part 2 of TZ)
     */
    /**
     * Fetch trade candles from Parser2 local history (per spec)
     * 
     * Source: modules/parser/parser2_history_accumulator/storage/{SYMBOL}/{DATE}.ndjson
     * 
     * Range:
     * - 30-60 candles BEFORE entry (30 min = 30 candles @ 1m)
     * - Entire trade duration
     * - 10-30 candles AFTER close
     */
    private function fetchTradeCandles(array $trade): array
    {
        $symbol = $trade['symbol'] ?? '';
        $entryTs = $trade['entry']['opened_ts'] ?? $trade['signal_ts'] ?? null;
        $closeTs = $trade['closed_ts'] ?? time();
        
        if ($entryTs === null || $symbol === '') {
            return [];
        }
        
        // Calculate time range: 60 min before entry to 30 min after close
        $startTs = $entryTs - 3600; // 60 min before entry (60 candles @ 1m)
        $endTs = $closeTs + 1800;   // 30 min after close (30 candles @ 1m)
        
        // Check cache first
        $cacheKey = md5($symbol . '_' . $startTs . '_' . $endTs . '_parser2');
        $cachePath = $this->storageDir . '/cache/candles_' . $cacheKey . '.json';
        $cacheDir = dirname($cachePath);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        // Cache for longer since Parser2 data is deterministic/historical
        if (is_file($cachePath)) {
            $cached = json_decode(file_get_contents($cachePath), true);
            if (is_array($cached) && isset($cached['expires_at']) && $cached['expires_at'] > time()) {
                return $cached['candles'] ?? [];
            }
        }
        
        // Fetch from Parser2 local history
        try {
            $ticks = $this->loadParser2Ticks($symbol, $startTs, $endTs);
            
            if (empty($ticks)) {
                return [];
            }
            
            // Generate OHLC candles from ticks (1 minute candles)
            $candles = $this->generateOHLCFromTicks($ticks, 60); // 60 seconds = 1 minute
            
            // Cache for 24 hours (Parser2 data is historical and immutable)
            file_put_contents($cachePath, json_encode([
                'expires_at' => time() + 86400,
                'candles' => $candles,
            ]));
            
            return $candles;
        } catch (\Throwable $e) {
            return [];
        }
    }
    
    /**
     * Load ticks from Parser2 history storage
     * 
     * Path: Uses SystemPaths key 'parser.parser2_history_accumulator.storage'
     * which points to modules/parser/parser2_history_accumulator/storage
     * Then: {SYMBOL}/{YYYY-MM-DD}.ndjson
     */
    private function loadParser2Ticks(string $symbol, int $startTs, int $endTs): array
    {
        $paths = SystemPaths::instance();
        
        // Use the .storage key which already points to storage directory
        // Do NOT add /storage manually - the key should resolve to storage path directly
        try {
            $parser2Storage = $paths->get('parser.parser2_history_accumulator.storage');
        } catch (\Throwable $e) {
            // Fallback: try base key + /storage if .storage key doesn't exist
            try {
                $parser2Base = $paths->get('parser.parser2_history_accumulator');
                $parser2Storage = rtrim($parser2Base, '/') . '/storage';
            } catch (\Throwable $e2) {
                return [];
            }
        }
        
        if (empty($parser2Storage)) {
            return [];
        }
        
        $symbolDir = rtrim($parser2Storage, '/') . '/' . $symbol;
        
        if (!is_dir($symbolDir)) {
            return [];
        }
        
        // Determine which date files to read
        $startDate = date('Y-m-d', $startTs);
        $endDate = date('Y-m-d', $endTs);
        
        $datesToRead = [];
        $current = strtotime($startDate);
        $end = strtotime($endDate);
        
        while ($current <= $end) {
            $datesToRead[] = date('Y-m-d', $current);
            $current = strtotime('+1 day', $current);
        }
        
        $ticks = [];
        
        foreach ($datesToRead as $dateStr) {
            $filePath = $symbolDir . '/' . $dateStr . '.ndjson';
            
            if (!is_file($filePath)) {
                continue;
            }
            
            $handle = fopen($filePath, 'r');
            if ($handle === false) {
                continue;
            }
            
            try {
                while (($line = fgets($handle)) !== false) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    
                    $tick = json_decode($line, true);
                    if (!is_array($tick)) {
                        continue;
                    }
                    
                    // Get timestamp and price
                    $tickTs = $tick['ts_unix'] ?? null;
                    $price = $tick['last_price'] ?? null;
                    
                    if ($tickTs === null || $price === null) {
                        continue;
                    }
                    
                    // Filter by time range
                    if ($tickTs < $startTs || $tickTs > $endTs) {
                        continue;
                    }
                    
                    $ticks[] = [
                        'ts' => (int)$tickTs,
                        'price' => (float)$price,
                    ];
                }
            } finally {
                fclose($handle);
            }
        }
        
        // Sort by timestamp
        usort($ticks, fn($a, $b) => $a['ts'] <=> $b['ts']);
        
        return $ticks;
    }
    
    /**
     * Generate OHLC candles from ticks
     * 
     * @param array $ticks Array of ['ts' => int, 'price' => float]
     * @param int $intervalSec Candle interval in seconds (60 = 1 minute)
     * @return array Array of candles
     */
    private function generateOHLCFromTicks(array $ticks, int $intervalSec = 60): array
    {
        if (empty($ticks)) {
            return [];
        }
        
        $candles = [];
        $currentCandle = null;
        $currentBucket = null;
        
        foreach ($ticks as $tick) {
            $ts = $tick['ts'];
            $price = $tick['price'];
            
            // Calculate which bucket this tick belongs to
            $bucket = (int)floor($ts / $intervalSec) * $intervalSec;
            
            if ($currentBucket === null || $bucket !== $currentBucket) {
                // Save previous candle
                if ($currentCandle !== null) {
                    $candles[] = $currentCandle;
                }
                
                // Start new candle
                $currentBucket = $bucket;
                $currentCandle = [
                    'time' => $bucket,
                    'open' => $price,
                    'high' => $price,
                    'low' => $price,
                    'close' => $price,
                    'volume' => 0, // Parser2 doesn't store volume per tick
                ];
            } else {
                // Update current candle
                $currentCandle['high'] = max($currentCandle['high'], $price);
                $currentCandle['low'] = min($currentCandle['low'], $price);
                $currentCandle['close'] = $price;
            }
        }
        
        // Don't forget the last candle
        if ($currentCandle !== null) {
            $candles[] = $currentCandle;
        }
        
        return $candles;
    }
    
    /**
     * Build chart markers for trade (Part 2 of TZ)
     */
    private function buildChartMarkers(array $trade): array
    {
        $markers = [];
        $isActive = ($trade['_status'] ?? 'closed') === 'active';
        
        // P1.7: Entry marker - support sim_trade_v1 fields
        // entry.opened_price > entry.target_price > entry.entry_price_signal (legacy)
        $entryPrice = $trade['entry']['opened_price'] 
            ?? $trade['entry']['target_price'] 
            ?? $trade['entry']['entry_price_signal'] 
            ?? $trade['opened_price'] ?? null;
        $entryTs = $trade['entry']['opened_ts'] ?? $trade['signal_ts'] ?? null;
        if ($entryPrice !== null && $entryTs !== null) {
            $markers[] = [
                'type' => 'entry',
                'price' => (float)$entryPrice,
                'time' => (int)$entryTs,
                'label' => 'Entry',
                'color' => '#0d6efd',
            ];
        }
        
        // Entry filled marker (if entry was by touch)
        $filled = $trade['entry']['filled'] ?? $trade['_filled'] ?? false;
        if ($filled && $entryPrice !== null && $entryTs !== null) {
            $markers[] = [
                'type' => 'filled',
                'price' => (float)$entryPrice,
                'time' => (int)$entryTs,
                'label' => 'Filled',
                'color' => '#198754',
            ];
        }
        
        // Take Profit line
        $tp = $trade['targets']['take_profit'] ?? $trade['take_profit'] ?? null;
        if ($tp !== null) {
            $markers[] = [
                'type' => 'tp_line',
                'price' => (float)$tp,
                'label' => 'TP',
                'color' => '#198754',
            ];
        }
        
        // P1.7: Stop Loss line - support sim_trade_v1 fields
        // targets.stop_loss_current > sl_price_current > sl_price_initial > targets.stop_loss_initial
        $sl = $trade['targets']['stop_loss_current'] 
            ?? $trade['sl_price_current']
            ?? $trade['sl_price_initial']
            ?? $trade['targets']['stop_loss_initial'] ?? null;
        if ($sl !== null) {
            $markers[] = [
                'type' => 'sl_line',
                'price' => (float)$sl,
                'label' => 'SL',
                'color' => '#dc3545',
            ];
        }
        
        // P1.7: Close marker (if closed) - use sim_trade_v1 close_result
        if (!$isActive) {
            $closeResult = $trade['close_result'] ?? [];
            $closePrice = $closeResult['close_price'] ?? $trade['closed_price'] ?? null;
            $closeTs = $closeResult['close_ts'] ?? $trade['closed_ts'] ?? null;
            $closeReason = $closeResult['close_reason'] ?? $trade['close_reason'] ?? 'unknown';
            
            // Fallback: try to get close info from events
            if (($closePrice === null || $closeTs === null) && !empty($trade['events'])) {
                foreach ($trade['events'] as $event) {
                    if (($event['type'] ?? '') === 'closed') {
                        $closePrice = $closePrice ?? $event['price'] ?? null;
                        $closeTs = $closeTs ?? strtotime($event['ts'] ?? '') ?? null;
                        $closeReason = $closeReason !== 'unknown' ? $closeReason : ($event['reason'] ?? 'unknown');
                        break;
                    }
                }
            }
            
            if ($closePrice !== null && $closeTs !== null) {
                // P1.7: Map close_reason to marker label
                $reasonMap = [
                    'take_profit' => 'TP',
                    'stop_loss' => 'SL',
                    'trailing_stop' => 'TRAIL',
                    'expired_signal' => 'EXPIRED',
                    'expired_entry_timeout' => 'EXPIRED',
                    'tp' => 'TP',
                    'sl' => 'SL',
                    'trailing_sl' => 'TRAIL',
                ];
                $reasonLabel = $reasonMap[$closeReason] ?? strtoupper($closeReason);
                
                $color = '#6c757d'; // default gray
                if (in_array($closeReason, ['take_profit', 'tp'])) $color = '#198754'; // green
                elseif (in_array($closeReason, ['stop_loss', 'sl', 'trailing_stop', 'trailing_sl'])) $color = '#dc3545'; // red
                
                $markers[] = [
                    'type' => 'close',
                    'price' => (float)$closePrice,
                    'time' => (int)$closeTs,
                    'label' => 'Close (' . $reasonLabel . ')',
                    'color' => $color,
                ];
            }
        }
        
        return $markers;
    }
    
    /**
     * Log event to events.ndjson
     */
    private function logEvent(string $type, array $data): void
    {
        $eventsPath = $this->storageDir . '/events.ndjson';
        $event = [
            'ts' => date('c'),
            'type' => $type,
            'data' => $data,
        ];
        file_put_contents($eventsPath, json_encode($event) . "\n", FILE_APPEND | LOCK_EX);
    }
    
    // ========================================================================
    // C6.2: SIMULATOR SELFTEST ENDPOINT
    // ========================================================================
    
    /**
     * C6.2: Simulator selftest endpoint
     * GET /admin/simulator/api/selftest
     * 
     * Returns:
     * - ok
     * - signals_loaded_from_brain
     * - active_trades_count_clean
     * - datasets_exist{training_dataset, trailing_sim}
     */
    public function apiSelftest(): void
    {
        header('Content-Type: application/json');
        
        // FIX-4.3: Return error if moduleBase is not configured
        if ($this->configError !== null) {
            echo json_encode([
                'success' => false,
                'ok' => false,
                'error' => $this->configError,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            return;
        }
        
        $result = [
            'ok' => true,
            'signals_loaded_from_brain' => false,
            'active_trades_count' => 0,
            'active_trades_source' => 'none',
            'datasets_exist' => [
                'training_dataset' => false,
                'trailing_sim' => false,
            ],
            'errors' => [],
        ];
        
        try {
            // Check if Brain signals can be loaded
            $brainStoragePath = null;
            try {
                $brainStoragePath = SystemPaths::instance()->get('system.brain.storage');
            } catch (\Throwable $e) {
                try {
                    $brainStoragePath = SystemPaths::instance()->get('system.brain') . '/storage';
                } catch (\Throwable $e2) {
                    $result['errors'][] = 'Cannot resolve Brain storage path';
                }
            }
            
            if ($brainStoragePath && is_file($brainStoragePath . '/signals.json')) {
                $result['signals_loaded_from_brain'] = true;
            }
            
            // Count active trades (prefers clean mode, fallback to root storage)
            $cleanActiveDir = $this->storageDir . '/clean/trades/active';
            if (is_dir($cleanActiveDir)) {
                $files = glob($cleanActiveDir . '/*.json') ?: [];
                $result['active_trades_count'] = count($files);
                $result['active_trades_source'] = 'clean';
            } else {
                // Fallback to root storage when clean mode not configured
                $rootActiveDir = $this->storageDir . '/trades/active';
                if (is_dir($rootActiveDir)) {
                    $files = glob($rootActiveDir . '/*.json') ?: [];
                    $result['active_trades_count'] = count($files);
                    $result['active_trades_source'] = 'root';
                } else {
                    $result['active_trades_count'] = 0;
                    $result['active_trades_source'] = 'none';
                }
            }
            
            // Check datasets exist
            $cleanDir = $this->storageDir . '/clean';
            
            // Training dataset
            $trainingPath = $cleanDir . '/training_dataset.ndjson';
            if (!is_file($trainingPath)) {
                $trainingPath = $this->storageDir . '/training_dataset.ndjson';
            }
            $result['datasets_exist']['training_dataset'] = is_file($trainingPath);
            
            // Trailing sim dataset
            $trailingPath = $cleanDir . '/training_trailing_sim.ndjson';
            if (!is_file($trailingPath)) {
                $trailingPath = $this->storageDir . '/training_trailing_sim.ndjson';
            }
            $result['datasets_exist']['trailing_sim'] = is_file($trailingPath);
            
        } catch (\Throwable $e) {
            $result['ok'] = false;
            $result['errors'][] = $e->getMessage();
        }
        
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
    
    /**
     * Load parser statuses from /modules/parser/
     * Reads state.json from each parser's storage directory
     * 
     * Parser state.json formats vary:
     * - parser2: has "status": "ok" or "enabled": true
     * - parser1, parser3: has "errors_count": 0 and timestamp
     * - delist_parser0: has "errors": 0
     * - parser15: has "last_global_sync_ts" timestamp
     * 
     * @return array Parser statuses ['name' => ['ok' => bool, 'status' => string, 'last_run' => string]]
     */
    private function loadParserStatuses(): array
    {
        $statuses = [];
        
        try {
            $paths = SystemPaths::instance();
            $parsersBasePath = $paths->get('parser');
        } catch (\Throwable $e) {
            // Fallback to direct path
            $root = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 3);
            $parsersBasePath = $root . '/modules/parser';
        }
        
        if (!is_dir($parsersBasePath)) {
            return $statuses;
        }
        
        // Scan parser directories
        $dirs = @scandir($parsersBasePath);
        if ($dirs === false) {
            return $statuses;
        }
        
        $parserDirs = array_filter($dirs, function($d) use ($parsersBasePath) {
            return $d !== '.' && $d !== '..' && is_dir($parsersBasePath . '/' . $d);
        });
        
        foreach ($parserDirs as $parserDir) {
            $stateFile = $parsersBasePath . '/' . $parserDir . '/storage/state.json';
            $lastRunFile = $parsersBasePath . '/' . $parserDir . '/storage/last_run.json';
            
            $status = [
                'name' => $parserDir,
                'ok' => false,
                'status' => 'unknown',
                'last_run' => null,
            ];
            
            // FIX: Check both state.json and last_run.json
            // Many parsers (P4, P5, etc.) use last_run.json instead of state.json
            $state = null;
            
            if (is_file($stateFile)) {
                $content = @file_get_contents($stateFile);
                if ($content !== false) {
                    $state = @json_decode($content, true);
                }
            }
            
            // If no state.json or it failed to load, try last_run.json
            if (!is_array($state) && is_file($lastRunFile)) {
                $content = @file_get_contents($lastRunFile);
                if ($content !== false) {
                    $state = @json_decode($content, true);
                }
            }
            
            if (is_array($state)) {
                // Determine OK status based on available fields
                // Different parsers use different formats
                $isOk = $this->determineParserOkStatus($state);
                
                $status['ok'] = $isOk;
                $status['status'] = $state['status'] ?? ($isOk ? 'ok' : 'unknown');
                $status['last_run'] = $state['ts'] ?? $state['last_run'] ?? null;
                
                // Extract additional info for display
                // Use errors_count first, fallback to errors field
                if (isset($state['errors_count'])) {
                    $status['errors'] = (int)$state['errors_count'];
                } elseif (isset($state['errors'])) {
                    $status['errors'] = is_array($state['errors']) 
                        ? count($state['errors']) 
                        : (int)$state['errors'];
                }
            }
            
            // Short display name (remove prefix like "parser1_", "parser2_" etc.)
            $displayName = preg_replace('/^(parser\d+_|delist_)/i', '', $parserDir);
            $status['display_name'] = ucfirst(str_replace('_', ' ', $displayName));
            
            $statuses[$parserDir] = $status;
        }
        
        // Sort by name
        ksort($statuses);
        
        return $statuses;
    }
    
    /**
     * Determine if parser is OK based on its state.json content
     * Different parsers use different status formats
     */
    private function determineParserOkStatus(array $state): bool
    {
        // Check explicit "ok" field first
        if (isset($state['ok'])) {
            return (bool)$state['ok'];
        }
        
        // Check "status" field (parser2 uses this)
        if (isset($state['status'])) {
            return strtolower($state['status']) === 'ok';
        }
        
        // Check "enabled" field (parser2 uses this)
        if (isset($state['enabled'])) {
            return (bool)$state['enabled'];
        }
        
        // Check errors_count (parser1, parser3 use this)
        if (isset($state['errors_count'])) {
            return (int)$state['errors_count'] === 0;
        }
        
        // Check errors (delist_parser0 uses this)
        if (isset($state['errors'])) {
            return (int)$state['errors'] === 0;
        }
        
        // Check for timestamp - if recent, parser is likely working
        // parser15 has last_global_sync_ts
        if (isset($state['last_global_sync_ts'])) {
            // If sync was within last 24 hours, consider it ok
            $lastSync = (int)$state['last_global_sync_ts'];
            return (time() - $lastSync) < 86400;
        }
        
        // If we have a timestamp (ts field), assume working if recent
        if (isset($state['ts'])) {
            try {
                $ts = strtotime($state['ts']);
                if ($ts !== false) {
                    // If state was updated within last 24 hours, consider it ok
                    return (time() - $ts) < 86400;
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
        
        // Default: if state file exists and has content, assume ok
        return !empty($state);
    }
}
