<?php
declare(strict_types=1);

namespace Modules\System\TradingBot;

use Core\Auth\Auth;
use Core\System\System;
use Core\System\SystemPaths;

/**
 * Trading Bot Controller
 * 
 * Admin UI controller for Trading Bot module.
 * Handles all UI pages and API endpoints.
 * 
 * NO HARDCODE / CONFIG FIRST / SystemPaths ONLY
 */
final class TradingBotController
{
    private ?string $moduleBase = null;
    private ?string $storageDir = null;
    private array $config = [];
    private ?string $configError = null;
    
    public function __construct()
    {
        $this->moduleBase = $this->resolveModuleBase();
        
        if ($this->moduleBase === null) {
            $this->configError = 'module_base_unknown';
            return;
        }
        
        $this->storageDir = $this->moduleBase . '/storage';
        $this->config = $this->loadConfig();
    }
    
    /**
     * Dashboard tab
     */
    public function index(): string
    {
        if (!Auth::check()) {
            header('Location: ' . System::adminUrl('login'));
            exit;
        }
        
        $title = 'Trading Bot';
        $tab = 'dashboard';
        
        // Load status data
        $status = $this->loadStatus();
        $lastRun = $this->loadLastRun();

        // Live stats & exchange positions for dashboard (fallback to storage if gateway is unavailable)
        $stats = [];
        $exchangePositions = ['ok' => false, 'positions' => [], 'count' => 0];

        try {
            require_once $this->moduleBase . '/service.php';
            $service = new TradingBotService();

            $statsRes = $service->getStats();
            if (is_array($statsRes)) {
                $stats = is_array($statsRes['stats'] ?? null) ? $statsRes['stats'] : [];
            }

            $exchangePositions = $service->getExchangePositionsUi();
        } catch (\Throwable $e) {
            // Keep dashboard renderable even if gateway/config has issues
            $stats = [
                'source' => 'error',
                'error' => $e->getMessage(),
            ];
            $exchangePositions = [
                'ok' => false,
                'error' => $e->getMessage(),
                'positions' => [],
                'count' => 0,
            ];
        }


        
        // Load positions and orders if available
        $positions = $this->loadPositions();
        $orders = $this->loadOrders();
        $balance = $this->loadBalance();
        
        // Load 10 recent closed trades for dashboard widget
        $recentClosedTrades = $this->loadClosedTrades();
        $recentClosedTrades = array_slice($recentClosedTrades, 0, 10);
        
        // Load 10 recent ProfitManager applied events for dashboard widget
        $profitManagerApplied = $this->loadProfitManagerAppliedEvents(10);
        
        ob_start();
        require $this->moduleBase . '/views/index.php';
        $content = ob_get_clean();
        
        return $this->wrapLayout($title, $content);
    }
    
    /**
     * Intents tab
     */
    public function intents(): string
    {
        if (!Auth::check()) {
            header('Location: ' . System::adminUrl('login'));
            exit;
        }
        
        $title = 'Trading Bot - Intents';
        $tab = 'intents';
        
        // Load intents data
        $intents = $this->loadIntents();
        $rejected = $this->loadRejected();
        $lastRun = $this->loadLastRun();
        
        ob_start();
        require $this->moduleBase . '/views/intents.php';
        $content = ob_get_clean();
        
        return $this->wrapLayout($title, $content);
    }
    
    /**
     * Trades tab
     */
    public function trades(): string
    {
        if (!Auth::check()) {
            header('Location: ' . System::adminUrl('login'));
            exit;
        }
        
        $title = 'Trading Bot - Trades';
        $tab = 'trades';
        
        // Load trades data
        $openTrades = $this->loadOpenTrades();
        $closedTrades = $this->loadClosedTrades();
        $lastRun = $this->loadLastRun();
        
        ob_start();
        require $this->moduleBase . '/views/trades.php';
        $content = ob_get_clean();
        
        return $this->wrapLayout($title, $content);
    }
    
    /**
     * Orders tab
     */
    public function orders(): string
    {
        if (!Auth::check()) {
            header('Location: ' . System::adminUrl('login'));
            exit;
        }
        
        $title = 'Trading Bot - Orders';
        $tab = 'orders';
        
        // Load orders data
        $orders = $this->loadOrders();
        $lastRun = $this->loadLastRun();
        
        ob_start();
        require $this->moduleBase . '/views/orders.php';
        $content = ob_get_clean();
        
        return $this->wrapLayout($title, $content);
    }

    /**
     * Profit tab (Profit Manager)
     * UI wrapper around separate Profit Manager module.
     */
    public function profit(): string
    {
        if (!Auth::check()) {
            header('Location: ' . System::adminUrl('login'));
            exit;
        }

        $title = 'Trading Bot - Profit';
        $tab = 'profit';

        $profitData = $this->loadProfitManagerUiData();
        $pmLastRun = $profitData['last_run'] ?? [];
        $pmStatus = $profitData['status'] ?? [];
        $pmApplied = $profitData['applied_index'] ?? [];

        ob_start();
        require $this->moduleBase . '/views/profit.php';
        $content = ob_get_clean();

        return $this->wrapLayout($title, $content);
    }

    /**
     * Settings tab
     */
    public function settings(): string
    {
        if (!Auth::check()) {
            header('Location: ' . System::adminUrl('login'));
            exit;
        }
        
        $title = 'Trading Bot - Settings';
        $tab = 'settings';
        
        // Load config data
        $config = $this->config;
        $configError = $this->configError;
        
        ob_start();
        require $this->moduleBase . '/views/settings.php';
        $content = ob_get_clean();
        
        return $this->wrapLayout($title, $content);
    }
    
    /**
     * Logs tab
     */
    public function logs(): string
    {
        if (!Auth::check()) {
            header('Location: ' . System::adminUrl('login'));
            exit;
        }
        
        $title = 'Trading Bot - Logs';
        $tab = 'logs';
        
        // Load log files
        $logs = $this->loadLogs();
        
        ob_start();
        require $this->moduleBase . '/views/logs.php';
        $content = ob_get_clean();
        
        return $this->wrapLayout($title, $content);
    }
    
    // =========================================================================
    // API Endpoints
    // =========================================================================
    
    /**
     * API: Run bot cycle
     */
    public function apiRun(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['ok' => false, 'error' => 'method_not_allowed']);
            return;
        }
        
        $result = $this->runBot();
        $this->jsonResponse($result);
    }
    
    /**
     * API: Stop bot
     */
    public function apiStop(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['ok' => false, 'error' => 'method_not_allowed']);
            return;
        }
        
        $result = $this->stopBot();
        $this->jsonResponse($result);
    }
    
    /**
     * API: Reconcile positions/orders
     */
    public function apiReconcile(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['ok' => false, 'error' => 'method_not_allowed']);
            return;
        }
        
        $result = $this->reconcileBot();
        $this->jsonResponse($result);
    }
    
    /**
     * API: Get status
     */
    public function apiStatus(): void
    {
        $status = $this->loadStatus();
        $this->jsonResponse(['ok' => true, 'status' => $status]);
    }
    
    /**
     * API: Get stats
     */
    public function apiStats(): void
    {
        $stats = $this->loadStats();
        $this->jsonResponse(['ok' => true, 'stats' => $stats]);
    }

    /**
     * API: Profit Manager state (last_run/status/applied)
     */
    public function apiProfitState(): void
    {
        $this->jsonResponse($this->loadProfitManagerUiData());
    }

    /**
     * API: Run Profit Manager cycle
     */
    public function apiProfitRun(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['ok' => false, 'error' => 'method_not_allowed']);
            return;
        }

        $pmBase = $this->resolveProfitManagerBase();
        if ($pmBase === null) {
            $this->jsonResponse(['ok' => false, 'error' => 'profit_manager_not_found']);
            return;
        }

        try {
            // Load ProfitManager module (bootstrap first, then service)
            $bootstrap = $pmBase . '/bootstrap.php';
            $service = $pmBase . '/service.php';

            if (is_file($bootstrap)) {
                require_once $bootstrap;
            }
            if (is_file($service)) {
                require_once $service;
            }

            if (!class_exists('Modules\\System\\ProfitManager\\ProfitManagerService')) {
                $this->jsonResponse(['ok' => false, 'error' => 'profit_manager_service_not_loaded']);
                return;
            }

            $svc = new \Modules\System\ProfitManager\ProfitManagerService();
            $run = $svc->execute();

            $this->jsonResponse([
                'ok' => (bool)($run['ok'] ?? false),
                'run' => $run,
                'ui' => $this->loadProfitManagerUiData(),
            ]);
        } catch (\Throwable $e) {
            $this->jsonResponse([
                'ok' => false,
                'error' => 'exception',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * API: Save settings
     */
    public function apiSettingsSave(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['ok' => false, 'error' => 'method_not_allowed']);
            return;
        }
        
        $body = $this->getJsonBody();
        $config = $body['config'] ?? null;
        
        if (!is_array($config)) {
            $this->jsonResponse(['ok' => false, 'error' => 'invalid_config']);
            return;
        }
        
        $result = $this->saveConfig($config);
        $this->jsonResponse($result);
    }

    /**
     * API: Close position now (market) and record result to TradingBot storage.
     */
    public function apiPositionClose(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['ok' => false, 'error' => 'method_not_allowed']);
            return;
        }

        $body = $this->getJsonBody();

        $symbol = (string)($body['symbol'] ?? '');
        $side = (string)($body['side'] ?? '');
        $positionIdx = (int)($body['position_idx'] ?? 0);

        if ($symbol === '' || ($side !== 'long' && $side !== 'short')) {
            $this->jsonResponse(['ok' => false, 'error' => 'invalid_payload']);
            return;
        }

        // Delegate to gateway/service if present
        $result = $this->closePositionNow($symbol, $side, $positionIdx);
        $this->jsonResponse($result);
    }

    /**
     * API: Update SL / trailing via Bybit trading-stop.
     */
    public function apiPositionStops(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonResponse(['ok' => false, 'error' => 'method_not_allowed']);
            return;
        }

        $body = $this->getJsonBody();

        $symbol = (string)($body['symbol'] ?? '');
        $side = (string)($body['side'] ?? '');
        $positionIdx = (int)($body['position_idx'] ?? 0);

        if ($symbol === '' || ($side !== 'long' && $side !== 'short')) {
            $this->jsonResponse(['ok' => false, 'error' => 'invalid_payload']);
            return;
        }

        $stopLoss = isset($body['stop_loss']) ? (float)$body['stop_loss'] : null;
        $trailingStop = isset($body['trailing_stop']) ? (float)$body['trailing_stop'] : null;
        $activePrice = isset($body['active_price']) ? (float)$body['active_price'] : null;

        $result = $this->updatePositionStops($symbol, $side, $positionIdx, $stopLoss, $trailingStop, $activePrice);
        $this->jsonResponse($result);
    }
    
    // =========================================================================
    // Helpers
    // =========================================================================
    
    /**
     * Resolve module base path via SystemPaths
     */
    private function resolveModuleBase(): ?string
    {
        $paths = SystemPaths::instance();
        
        // Try multiple keys for compatibility
        $candidates = [
            'system.trading_bot',
            'trading.trading_bot',
            'modules.trading_bot',
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

        $cfg = [];
        if (is_file($configPath)) {
            $loaded = require $configPath;
            if (is_array($loaded)) {
                $cfg = $loaded;
            }
        }

        // Apply UI runtime overrides (config/bot.json)
        $runtimePath = $this->moduleBase . '/config/bot.json';
        if (is_file($runtimePath)) {
            $json = file_get_contents($runtimePath);
            $runtime = is_string($json) ? json_decode($json, true) : null;

            if (is_array($runtime)) {
                // Ensure blocks exist
                if (!isset($cfg['module']) || !is_array($cfg['module'])) {
                    $cfg['module'] = [];
                }
                if (!isset($cfg['sources']) || !is_array($cfg['sources'])) {
                    $cfg['sources'] = [];
                }
                if (!isset($cfg['exchange']) || !is_array($cfg['exchange'])) {
                    $cfg['exchange'] = [];
                }
                if (!isset($cfg['execution']) || !is_array($cfg['execution'])) {
                    $cfg['execution'] = [];
                }
                if (!isset($cfg['validation']) || !is_array($cfg['validation'])) {
                    $cfg['validation'] = [];
                }
                if (!isset($cfg['ui']) || !is_array($cfg['ui'])) {
                    $cfg['ui'] = [];
                }

                // Symbol overrides (per symbol execution policy)
                if (!isset($cfg['symbol_overrides']) || !is_array($cfg['symbol_overrides'])) {
                    $cfg['symbol_overrides'] = [];
                }

                // Legacy flat overrides (compat)
                if (array_key_exists('enabled', $runtime)) {
                    $cfg['module']['enabled'] = (bool)$runtime['enabled'];
                }
                if (isset($runtime['mode']) && is_string($runtime['mode'])) {
                    $mode = strtolower(trim($runtime['mode']));
                    $cfg['module']['mode'] = in_array($mode, ['live', 'dry'], true) ? $mode : ($cfg['module']['mode'] ?? 'dry');
                }
                if (isset($runtime['account_id']) && is_string($runtime['account_id'])) {
                    $acc = trim($runtime['account_id']);
                    if ($acc !== '') {
                        $cfg['module']['account_id'] = $acc;
                    }
                }
                if (array_key_exists('max_positions', $runtime)) {
                    $cfg['module']['max_concurrent_positions'] = max(0, (int)$runtime['max_positions']);
                }
                if (array_key_exists('reconcile_before_action', $runtime)) {
                    $cfg['module']['reconcile_before_action'] = (bool)$runtime['reconcile_before_action'];
                }
                if (array_key_exists('safety_stop_errors', $runtime)) {
                    $cfg['execution']['safety_stop_on_errors'] = max(0, (int)$runtime['safety_stop_errors']);
                }

                // Nested overrides
                if (isset($runtime['sources']) && is_array($runtime['sources'])) {
                    $src = $runtime['sources'];

                    if (isset($src['signals_key']) && is_string($src['signals_key'])) {
                        $cfg['sources']['signals_key'] = trim($src['signals_key']);
                    }
                    if (isset($src['signals_file']) && is_string($src['signals_file'])) {
                        $cfg['sources']['signals_file'] = trim($src['signals_file']);
                    }
                    if (isset($src['risk_active_key']) && is_string($src['risk_active_key'])) {
                        $cfg['sources']['risk_active_key'] = trim($src['risk_active_key']);
                    }
                    if (isset($src['risk_active_file']) && is_string($src['risk_active_file'])) {
                        $cfg['sources']['risk_active_file'] = trim($src['risk_active_file']);
                    }
                    if (isset($src['commands_key']) && is_string($src['commands_key'])) {
                        $cfg['sources']['commands_key'] = trim($src['commands_key']);
                    }
                    if (isset($src['commands_file']) && is_string($src['commands_file'])) {
                        $cfg['sources']['commands_file'] = trim($src['commands_file']);
                    }
                }

                if (isset($runtime['exchange']) && is_array($runtime['exchange'])) {
                    $ex = $runtime['exchange'];

                    if (isset($ex['category']) && is_string($ex['category'])) {
                        $cfg['exchange']['category'] = trim($ex['category']);
                    }
                    if (isset($ex['settle_coin']) && is_string($ex['settle_coin'])) {
                        $cfg['exchange']['settle_coin'] = trim($ex['settle_coin']);
                    }
                    if (array_key_exists('position_idx', $ex)) {
                        $cfg['exchange']['position_idx'] = max(0, (int)$ex['position_idx']);
                    }
                    if (isset($ex['account_type']) && is_string($ex['account_type'])) {
                        $cfg['exchange']['account_type'] = trim($ex['account_type']);
                    }
                    if (isset($ex['tpsl_mode']) && is_string($ex['tpsl_mode'])) {
                        $cfg['exchange']['tpsl_mode'] = trim($ex['tpsl_mode']);
                    }
                    if (isset($ex['sl_trigger_by']) && is_string($ex['sl_trigger_by'])) {
                        $cfg['exchange']['sl_trigger_by'] = trim($ex['sl_trigger_by']);
                    }
                }

                if (isset($runtime['execution']) && is_array($runtime['execution'])) {
                    $exu = $runtime['execution'];

                    $ints = [
                        'max_scan_intents_per_run',
                        'max_intents_per_run',
                        'max_deferred_intents_per_run',
                        'default_entry_timeout_minutes',
                        'exchange_positions_cache_ttl_sec',
                        'balance_cache_ttl_sec',
                        'safety_stop_on_errors',
                        'commands_max_per_run',
                        'balance_required_buffer_pct',
                        'balance_reject_below_usdt',
                    ];

                    foreach ($ints as $k) {
                        if (array_key_exists($k, $exu)) {
                            $cfg['execution'][$k] = (int)$exu[$k];
                        }
                    }

                    $floats = [
                        'default_late_threshold_pct',
                        'retrace_slack_pct',
                        'dumb_trailing_activation_epsilon_pct',
                        'profit_addon_budget_pct',
                    ];

                    foreach ($floats as $k) {
                        if (array_key_exists($k, $exu)) {
                            $cfg['execution'][$k] = (float)$exu[$k];
                        }
                    }

                    $bools = [
                        'require_price_check_live',
                        'commands_enabled',
                        'commands_apply_before_intents',
                        'commands_allow_close',
                        'dumb_trailing_enabled',
                        'enable_trailing_on_open',
                        'balance_strict_stable_coin_only',
                        'reverse_side_enabled',
                        'profit_addon_enabled',
                    ];

                    foreach ($bools as $k) {
                        if (array_key_exists($k, $exu)) {
                            $cfg['execution'][$k] = (bool)$exu[$k];
                        }
                    }

                    if (isset($exu['balance_coin']) && is_string($exu['balance_coin'])) {
                        $cfg['execution']['balance_coin'] = trim($exu['balance_coin']);
                    }
                }

                if (isset($runtime['validation']) && is_array($runtime['validation'])) {
                    $val = $runtime['validation'];

                    if (isset($val['signal_schema_version']) && is_string($val['signal_schema_version'])) {
                        $cfg['validation']['signal_schema_version'] = trim($val['signal_schema_version']);
                    }
                }

                if (isset($runtime['ui']) && is_array($runtime['ui'])) {
                    $ui = $runtime['ui'];

                    if (array_key_exists('max_preview_items', $ui)) {
                        $cfg['ui']['max_preview_items'] = max(1, (int)$ui['max_preview_items']);
                    }
                }
            }
        }

        

                // Symbol overrides from runtime (bot.json)
                if (isset($runtime['symbol_overrides']) && is_array($runtime['symbol_overrides'])) {
                    $clean = [];
                    foreach ($runtime['symbol_overrides'] as $sym => $row) {
                        if (!is_string($sym) || $sym === '') {
                            continue;
                        }
                        $symKey = strtoupper(trim($sym));
                        if ($symKey === '' || !preg_match('/^[A-Z0-9]{3,25}$/', $symKey)) {
                            continue;
                        }
                        if (!is_array($row)) {
                            continue;
                        }

                        $item = [];
                        if (array_key_exists('enabled', $row)) {
                            $item['enabled'] = (bool)$row['enabled'];
                        }
                        if (array_key_exists('reverse_side_enabled', $row)) {
                            $item['reverse_side_enabled'] = (bool)$row['reverse_side_enabled'];
                        }
                        if (array_key_exists('force_side', $row)) {
                            $fs = is_string($row['force_side']) ? strtolower(trim($row['force_side'])) : '';
                            if (in_array($fs, ['long', 'short'], true)) {
                                $item['force_side'] = $fs;
                            }
                        }

                        if (!empty($item)) {
                            $clean[$symKey] = $item;
                        }
                    }

                    $cfg['symbol_overrides'] = $clean;
                }
return $cfg;
    }


    
    /**
     * Load bot status
     */
    private function loadStatus(): array
    {
        $path = $this->storageDir . '/runtime/status.json';
        if (!is_file($path)) {
            return [];
        }
        
        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }
        
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }
    
    /**
     * Load last run result
     */
    private function loadLastRun(): array
    {
        // Prefer the main storage file (written by TradingBotService->store)
        $paths = [
            $this->storageDir . '/last_run.json',
            $this->storageDir . '/runtime/last_run.json',
        ];

        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }

            $content = file_get_contents($path);
            if ($content === false) {
                continue;
            }

            $data = json_decode($content, true);
            if (is_array($data)) {
                return $data;
            }
        }

        return [];
    }

    /**
     * Resolve Profit Manager module base path via SystemPaths
     */
    private function resolveProfitManagerBase(): ?string
    {
        $paths = SystemPaths::instance();

        $candidates = [
            'system.profit_manager',
            'trading.profit_manager',
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
                // Continue
            }
        }

        return null;
    }

    /**
     * Load Profit Manager UI data (from module storage)
     *
     * @return array<string,mixed>
     */
    private function loadProfitManagerUiData(): array
    {
        $pmBase = $this->resolveProfitManagerBase();
        if ($pmBase === null) {
            return [
                'ok' => false,
                'error' => 'profit_manager_not_found',
            ];
        }

        $runtimeDir = $pmBase . '/storage/runtime';

        return [
            'ok' => true,
            'module_base' => $pmBase,
            'runtime_dir' => $runtimeDir,
            'last_run' => $this->readJsonFile($runtimeDir . '/last_run.json'),
            'status' => $this->readJsonFile($runtimeDir . '/status.json'),
            'applied_index' => $this->readJsonFile($runtimeDir . '/applied_index.json'),
        ];
    }

    /**
     * Load ProfitManager applied events (last N)
     *
     * @param int $limit Max events to return
     * @return array<int, array<string,mixed>>
     */
    private function loadProfitManagerAppliedEvents(int $limit = 10): array
    {
        $pmBase = $this->resolveProfitManagerBase();
        if ($pmBase === null) {
            return [];
        }

        $indexPath = $pmBase . '/storage/runtime/applied_index.json';
        $data = $this->readJsonFile($indexPath);
        $events = $data['events'] ?? [];

        if (!is_array($events) || empty($events)) {
            return [];
        }

        // Events are in chronological order, reverse to get newest first
        $events = array_reverse($events);
        
        // Return last N events
        return array_slice($events, 0, $limit);
    }

    /**
     * Read JSON file helper
     *
     * @return array<string,mixed>
     */
    private function readJsonFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $content = @file_get_contents($path);
        if ($content === false || trim($content) === '') {
            return [];
        }

        $data = @json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Load stats
     */
    private function loadStats(): array
    {
        $path = $this->storageDir . '/runtime/stats.json';
        if (!is_file($path)) {
            return [];
        }
        
        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }
        
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }
    
    /**
     * Load intents
     */
    private function loadIntents(): array
    {
        $path = $this->storageDir . '/intents/intents.json';
        if (!is_file($path)) {
            return [];
        }
        
        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }
        
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }
    
    /**
     * Load rejected intents
     */
    private function loadRejected(): array
    {
        $path = $this->storageDir . '/rejected/rejected.json';
        if (!is_file($path)) {
            return [];
        }
        
        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }
        
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }
    
    /**
     * Load open trades
     */
    private function loadOpenTrades(): array
    {
        $path = $this->storageDir . '/trades/open_trades.json';
        if (!is_file($path)) {
            return [];
        }
        
        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }
        
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }
    
    /**
     * Load closed trades
     */
    private function loadClosedTrades(): array
    {
        $path = $this->storageDir . '/trades/closed_trades.json';
        if (!is_file($path)) {
            return [];
        }
        
        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }
        
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }
    
    /**
     * Load orders
     */
    private function loadOrders(): array
    {
        $path = $this->storageDir . '/runtime/orders.json';
        if (!is_file($path)) {
            return [];
        }
        
        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }
        
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }
    
    /**
     * Load positions
     */
    private function loadPositions(): array
    {
        $path = $this->storageDir . '/runtime/positions.json';
        if (!is_file($path)) {
            return [];
        }
        
        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }
        
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }
    
    /**
     * Load balance
     */
    private function loadBalance(): array
    {
        $path = $this->storageDir . '/runtime/balance.json';
        if (!is_file($path)) {
            return [];
        }
        
        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }
        
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }
    
    /**
     * Load logs
     */
    private function loadLogs(): array
    {
        $logDir = $this->storageDir . '/logs';
        if (!is_dir($logDir)) {
            return [];
        }
        
        $logs = [];
        $files = glob($logDir . '/*.log');
        
        foreach ($files as $file) {
            $name = basename($file);
            $content = file_get_contents($file);
            $logs[$name] = $content !== false ? $content : '';
        }
        
        return $logs;
    }
    
    /**
     * Run bot cycle
     */
    private function runBot(): array
    {
        // Load service
        require_once $this->moduleBase . '/service.php';
        
        $service = new TradingBotService();
        return $service->execute();
    }
    
    /**
     * Stop bot
     */
    private function stopBot(): array
    {
        require_once $this->moduleBase . '/service.php';

        try {
            $service = new TradingBotService();
            return $service->stop();
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Reconcile positions/orders
     */
    private function reconcileBot(): array
    {
        // Load service
        require_once $this->moduleBase . '/service.php';
        
        $service = new TradingBotService();
        return $service->forceReconcile();
    }

    /**
     * Close position now via TradingBotGateway (Bybit) and store result.
     * Also marks the local trade as manual_close for proper close_reason tracking.
     */
    private function closePositionNow(string $symbol, string $side, int $positionIdx): array
    {
        require_once $this->moduleBase . '/gateway.php';

        $rollback = null;

        try {
            // Mark BEFORE sending closePosition to ensure close_reason=manual_close with high confidence
            $rollback = $this->markTradeAsManualClose($symbol, $side);

            $gateway = new TradingBotGateway($this->config);
            $res = $gateway->closePositionNow($symbol, $side, $positionIdx);

            // Persist to storage for UI explainability
            $path = $this->storageDir . '/runtime/last_close.json';
            @file_put_contents($path, json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            // If close failed - rollback marker (so we don't poison the trade)
            if (($res['ok'] ?? false) !== true) {
                $this->rollbackManualCloseMarker($rollback);
            }

            return [
                'ok' => (bool)($res['ok'] ?? false),
                'close' => $res,
            ];
        } catch (\Throwable $e) {
            $this->rollbackManualCloseMarker($rollback);

            return [
                'ok' => false,
                'error' => 'exception',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Mark active trade as manual_close (so reconcile can set proper close_reason)
     *
     * @return array{file:string,prev:string}|null
     */
private function markTradeAsManualClose(string $symbol, string $side): ?array
    {
        $activeDir = $this->storageDir . '/trades/active';
        if (!is_dir($activeDir)) {
            return null;
        }

        $files = glob($activeDir . '/*.json');
        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $trade = @json_decode($content, true);
            if (!is_array($trade)) {
                continue;
            }

            $tradeSymbol = $trade['symbol'] ?? '';
            $tradeSide = strtolower($trade['side'] ?? '');
            $sideNorm = strtolower($side);

            if ($sideNorm === 'buy') {
                $sideNorm = 'long';
            } elseif ($sideNorm === 'sell') {
                $sideNorm = 'short';
            }

            if ($tradeSymbol === $symbol && $tradeSide === $sideNorm) {
                $prev = $content;

                // Mark as manual_close
                $trade['manual_close'] = true;
                $trade['manual_close_at'] = date('c');

                @file_put_contents($file, json_encode($trade, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return [
                    'file' => $file,
                    'prev' => $prev,
                ];
            }
        }

        return null;
    }

    /**
     * Rollback manual_close marker if closePositionNow failed.
     *
     * @param array{file:string,prev:string}|null $rollback
     * @return void
     */
    private function rollbackManualCloseMarker(?array $rollback): void
    {
        if (!is_array($rollback)) {
            return;
        }

        $file = (string)($rollback['file'] ?? '');
        $prev = (string)($rollback['prev'] ?? '');

        if ($file === '' || $prev === '') {
            return;
        }

        @file_put_contents($file, $prev);
    }

/**
     * Update position stops via TradingBotGateway (Bybit trading-stop).
     */
    private function updatePositionStops(string $symbol, string $side, int $positionIdx, ?float $stopLoss, ?float $trailingStop, ?float $activePrice): array
    {
        require_once $this->moduleBase . '/gateway.php';

        try {
            $gateway = new TradingBotGateway($this->config);
            $res = $gateway->updatePositionStops($symbol, $side, $positionIdx, $stopLoss, $trailingStop, $activePrice);

            // Persist for UI explainability
            $path = $this->storageDir . '/runtime/last_stops.json';
            @file_put_contents($path, json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return [
                'ok' => (bool)($res['ok'] ?? false),
                'exchange' => $res,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => 'exception',
                'message' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Save config
     */
        private function saveConfig(array $config): array
    {
        // UI MUST NOT overwrite config/config.php.
        // Store only runtime overrides into config/bot.json.
        $runtimePath = $this->moduleBase . '/config/bot.json';

        try {
            $existing = [];
            if (is_file($runtimePath)) {
                $prev = @file_get_contents($runtimePath);
                $decoded = is_string($prev) ? @json_decode($prev, true) : null;
                if (is_array($decoded)) {
                    $existing = $decoded;
                }
            }

            // UI MUST NOT overwrite config/config.php.
            // Store only runtime overrides into config/bot.json (whitelist).
            $runtime = [
                'enabled' => (bool)($config['enabled'] ?? ($existing['enabled'] ?? true)),
                'mode' => (string)($config['mode'] ?? ($existing['mode'] ?? 'dry')),
                'account_id' => (string)($config['account_id'] ?? ($existing['account_id'] ?? 'trading_bot')),
                'max_positions' => (int)($config['max_positions'] ?? ($existing['max_positions'] ?? 10)),
                'reconcile_before_action' => (bool)($config['reconcile_before_action'] ?? ($existing['reconcile_before_action'] ?? true)),

                // Per-symbol overrides (can be edited in UI)
                'symbol_overrides' => is_array($config['symbol_overrides'] ?? null)
                    ? (array)$config['symbol_overrides']
                    : (is_array($existing['symbol_overrides'] ?? null) ? (array)$existing['symbol_overrides'] : []),
                'safety_stop_errors' => (int)($config['safety_stop_errors'] ?? ($existing['safety_stop_errors'] ?? 3)),

                // Nested blocks
                'exchange' => is_array($config['exchange'] ?? null) ? (array)$config['exchange'] : (is_array($existing['exchange'] ?? null) ? (array)$existing['exchange'] : []),
                'sources'  => is_array($config['sources'] ?? null) ? (array)$config['sources'] : (is_array($existing['sources'] ?? null) ? (array)$existing['sources'] : []),
                'execution'=> is_array($config['execution'] ?? null) ? (array)$config['execution'] : (is_array($existing['execution'] ?? null) ? (array)$existing['execution'] : []),
                'validation'=> is_array($config['validation'] ?? null) ? (array)$config['validation'] : (is_array($existing['validation'] ?? null) ? (array)$existing['validation'] : []),
                'ui'       => is_array($config['ui'] ?? null) ? (array)$config['ui'] : (is_array($existing['ui'] ?? null) ? (array)$existing['ui'] : []),
            ];

            // Normalize / validate
            $mode = strtolower(trim((string)$runtime['mode']));
            $runtime['mode'] = in_array($mode, ['live', 'dry'], true) ? $mode : 'dry';

            $runtime['max_positions'] = max(0, (int)$runtime['max_positions']);
            $runtime['safety_stop_errors'] = max(0, (int)$runtime['safety_stop_errors']);

            $acc = trim((string)$runtime['account_id']);
            $runtime['account_id'] = $acc !== '' ? $acc : 'trading_bot';


            // Symbol overrides validation / normalization
            $rawOverrides = is_array($runtime['symbol_overrides'] ?? null) ? (array)$runtime['symbol_overrides'] : [];
            $cleanOverrides = [];
            foreach ($rawOverrides as $sym => $row) {
                if (!is_string($sym) || $sym === '') {
                    continue;
                }
                $symKey = strtoupper(trim($sym));
                if ($symKey === '' || !preg_match('/^[A-Z0-9]{3,25}$/', $symKey)) {
                    continue;
                }
                if (!is_array($row)) {
                    continue;
                }

                $item = [];
                if (array_key_exists('enabled', $row)) {
                    $item['enabled'] = (bool)$row['enabled'];
                }
                if (array_key_exists('reverse_side_enabled', $row)) {
                    $item['reverse_side_enabled'] = (bool)$row['reverse_side_enabled'];
                }
                if (array_key_exists('force_side', $row)) {
                    $fs = is_string($row['force_side']) ? strtolower(trim($row['force_side'])) : '';
                    if (in_array($fs, ['long', 'short'], true)) {
                        $item['force_side'] = $fs;
                    }
                }

                if (!empty($item)) {
                    $cleanOverrides[$symKey] = $item;
                }
            }
            $runtime['symbol_overrides'] = $cleanOverrides;

            // Exchange validation
            if (is_array($runtime['exchange'])) {
                $allowedCategories = ['linear', 'inverse', 'spot', 'option'];
                if (isset($runtime['exchange']['category'])) {
                    $cat = strtolower(trim((string)$runtime['exchange']['category']));
                    if (in_array($cat, $allowedCategories, true)) {
                        $runtime['exchange']['category'] = $cat;
                    } else {
                        unset($runtime['exchange']['category']);
                    }
                }

                if (isset($runtime['exchange']['settle_coin'])) {
                    $runtime['exchange']['settle_coin'] = strtoupper(trim((string)$runtime['exchange']['settle_coin']));
                }

                if (array_key_exists('position_idx', $runtime['exchange'])) {
                    $runtime['exchange']['position_idx'] = max(0, min(2, (int)$runtime['exchange']['position_idx']));
                }

                if (isset($runtime['exchange']['account_type'])) {
                    $runtime['exchange']['account_type'] = strtoupper(trim((string)$runtime['exchange']['account_type']));
                }

                if (isset($runtime['exchange']['tpsl_mode'])) {
                    $tpsl = trim((string)$runtime['exchange']['tpsl_mode']);
                    if (in_array($tpsl, ['Full', 'Partial'], true)) {
                        $runtime['exchange']['tpsl_mode'] = $tpsl;
                    }
                }

                if (isset($runtime['exchange']['sl_trigger_by'])) {
                    $tr = trim((string)$runtime['exchange']['sl_trigger_by']);
                    if (in_array($tr, ['LastPrice', 'IndexPrice', 'MarkPrice'], true)) {
                        $runtime['exchange']['sl_trigger_by'] = $tr;
                    }
                }
            }

            // Sources normalization
            if (is_array($runtime['sources'])) {
                foreach (['signals_key','signals_file','risk_active_key','risk_active_file','commands_key','commands_file'] as $k) {
                    if (isset($runtime['sources'][$k])) {
                        $runtime['sources'][$k] = trim((string)$runtime['sources'][$k]);
                    }
                }
            }

            // Execution normalization (ints/floats/bools)
            if (is_array($runtime['execution'])) {
                $intKeys = [
                    'max_scan_intents_per_run' => [1, 10000],
                    'max_intents_per_run' => [0, 1000],
                    'max_deferred_intents_per_run' => [0, 10000],
                    'default_entry_timeout_minutes' => [1, 1440],
                    'exchange_positions_cache_ttl_sec' => [0, 600],
                    'balance_cache_ttl_sec' => [0, 600],
                    'safety_stop_on_errors' => [0, 1000],
                    'commands_max_per_run' => [0, 1000],
                    'balance_required_buffer_pct' => [0, 100],
                    'balance_reject_below_usdt' => [0, 1000000],
                ];

                foreach ($intKeys as $k => $range) {
                    if (array_key_exists($k, $runtime['execution'])) {
                        $v = (int)$runtime['execution'][$k];
                        $runtime['execution'][$k] = max($range[0], min($range[1], $v));
                    }
                }

                $floatKeys = [
                    'default_late_threshold_pct' => [0.0, 100.0],
                    'retrace_slack_pct' => [0.0, 10.0],
                    'dumb_trailing_activation_epsilon_pct' => [0.0, 10.0],
                    'profit_addon_budget_pct' => [0.0, 500.0],
                ];

                foreach ($floatKeys as $k => $range) {
                    if (array_key_exists($k, $runtime['execution'])) {
                        $v = (float)$runtime['execution'][$k];
                        $runtime['execution'][$k] = max($range[0], min($range[1], $v));
                    }
                }

                $boolKeys = [
                    'require_price_check_live',
                    'commands_enabled',
                    'commands_apply_before_intents',
                    'commands_allow_close',
                    'dumb_trailing_enabled',
                    'enable_trailing_on_open',
                    'balance_strict_stable_coin_only',
                    'reverse_side_enabled',
                    'profit_addon_enabled',
                ];

                foreach ($boolKeys as $k) {
                    if (array_key_exists($k, $runtime['execution'])) {
                        $runtime['execution'][$k] = (bool)$runtime['execution'][$k];
                    }
                }

                if (isset($runtime['execution']['balance_coin'])) {
                    $runtime['execution']['balance_coin'] = strtoupper(trim((string)$runtime['execution']['balance_coin']));
                }
            }

            // Validation normalization
            if (is_array($runtime['validation'])) {
                if (isset($runtime['validation']['signal_schema_version'])) {
                    $runtime['validation']['signal_schema_version'] = trim((string)$runtime['validation']['signal_schema_version']);
                }
            }

            // UI normalization
            if (is_array($runtime['ui'])) {
                if (array_key_exists('max_preview_items', $runtime['ui'])) {
                    $runtime['ui']['max_preview_items'] = max(1, min(500, (int)$runtime['ui']['max_preview_items']));
                }
            }

            // Preserve meta fields from existing unless explicitly provided
            $runtime = array_merge($existing, $runtime, [
                'last_modified' => date('c'),
            ]);

            // Optional fields
            if (!array_key_exists('modified_by', $runtime)) {
                $runtime['modified_by'] = null;
            }
            if (!array_key_exists('notes', $runtime)) {
                $runtime['notes'] = 'Trading Bot v1 runtime settings. Edit via UI or this file.';
            }

$json = json_encode($runtime, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if (!is_string($json)) {
                throw new \RuntimeException('Failed to encode bot.json');
            }

            file_put_contents($runtimePath, $json . "\n", LOCK_EX);

            // Reload merged config
            $this->config = $this->loadConfig();
            $this->configError = null;

            return ['ok' => true, 'message' => 'Runtime config saved'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }


    
    /**
     * Get JSON body from request
     */
    private function getJsonBody(): array
    {
        $content = file_get_contents('php://input');
        if ($content === false) {
            return [];
        }
        
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }
    
    /**
     * Send JSON response
     */
    private function jsonResponse(array $data): void
    {
        header('Content-Type: application/json');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
    
    /**
     * Wrap content in layout
     */
    private function wrapLayout(string $title, string $content): string
    {
        $baseUrl = System::adminUrl('trading');
        
        ob_start();
        require $this->moduleBase . '/views/_layout.php';
        return ob_get_clean();
    }
}

/* RULES
- Controller only (no trading logic)
- Auth check for all pages
- UI renders from exchange (dashboard) with storage fallback
- API endpoints return JSON only
- SystemPaths ONLY for paths
*/
