<?php

declare(strict_types=1);

namespace Modules\System\UI;

use Core\System\System;
use Core\Auth\Auth;
use Core\Storage\StorageManager;
use Core\KeyCenter\KeyCenter;
use Core\Logger\Logger;
use Core\Module\ModuleManager;
use Core\Cron\CronManager;

/**
 * System Control Center Controller
 * 
 * Central infrastructure management panel for Tredercopis.
 * ALL data must come from Core services - NO hardcoded values.
 */
final class SystemUIController
{
    private static ?self $instance = null;

    private function __construct()
    {
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * System Overview Dashboard
     */
    public function dashboard(): void
    {
        if (!Auth::check()) {
            header('Location: ' . System::adminUrl('login'));
            exit;
        }

        // Collect REAL data from Core services
        $data = [
            // System info
            'version' => System::version(),
            'env' => System::env(),
            'php_version' => PHP_VERSION,
            
            // Keys
            'keys_count' => $this->getKeysCount(),
            'keys_services' => $this->getKeysServices(),
            
            // Paths
            'paths_count' => $this->getPathsCount(),
            
            // Storage
            'storage_size' => $this->getStorageSize(),
            'storage_backend' => $this->getStorageBackend(),
            
            // Modules
            'modules_count' => $this->getModulesCount(),
            'modules_enabled' => $this->getModulesEnabled(),
            
            // Cron
            'cron_tasks' => $this->getCronTasksCount(),
            'cron_enabled' => $this->getCronEnabledCount(),
            'cron_last_run' => $this->getCronLastRun(),
            'cron_status' => $this->getCronStatus(),
            
            // Pages
            'pages_total' => $this->getPagesTotal(),
            'pages_system' => $this->getPagesSystem(),
            'pages_module' => $this->getPagesModule(),
            
            // Health
            'health_status' => $this->getHealthStatus(),
            
            // Memory
            'memory_usage' => $this->getMemoryUsage(),
            
            // Last update
            'last_update' => $this->getLastUpdate(),
        ];

        $this->render('dashboard', 'Обзор системы', $data);
    }

    /**
     * Updates page (GitHub Center)
     */
    public function updates(): void
    {
        if (!Auth::check()) {
            header('Location: ' . System::adminUrl('login'));
            exit;
        }

        // Redirect to GitHub module (already implemented)
        header('Location: ' . System::web('admin/github'));
        exit;
    }

    /**
     * Storage management page
     */
    public function storage(): void
    {
        if (!Auth::check()) {
            header('Location: ' . System::adminUrl('login'));
            exit;
        }

        $storage = StorageManager::instance();
        
        $data = [
            'backend' => $this->getStorageBackend(),
            'size' => $this->getStorageSize(),
            'keys' => $this->getStorageKeys(),
        ];

        $this->render('storage', 'Хранилище', $data);
    }

    /**
     * System paths page (PathMan)
     */
    public function paths(): void
    {
        if (!Auth::check()) {
            header('Location: ' . System::adminUrl('login'));
            exit;
        }

        $data = [
            'paths' => [
                'root' => System::path('root'),
                'config' => System::path('config'),
                'modules' => System::path('modules'),
                'storage' => System::path('storage'),
                'runtime' => System::path('runtime'),
                'logs' => System::path('logs'),
                'cache' => System::path('cache'),
                'sessions' => System::path('sessions'),
            ],
            'base_url' => System::baseUrl(),
            'admin_url' => System::adminUrl(),
        ];

        $this->render('paths', 'Системные пути', $data);
    }

    /**
     * Keys management page (KeyCenter)
     */
    public function keys(): void
    {
        if (!Auth::check()) {
            header('Location: ' . System::adminUrl('login'));
            exit;
        }

        $keyCenter = KeyCenter::instance();
        
        // Flash message from operations
        $flashMessage = $_SESSION['keys_flash'] ?? null;
        unset($_SESSION['keys_flash']);
        
        $data = [
            'services' => $keyCenter->listServices(),
            'accounts' => $keyCenter->export(),
            'diagnostic' => $keyCenter->diagnostic(),
            'flash' => $flashMessage,
        ];

        $this->render('keys', 'Ключи API', $data);
    }

    /**
     * Add/Update credentials
     */
    public function keysAdd(): void
    {
        if (!Auth::check()) {
            header('Location: ' . System::adminUrl('login'));
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . System::web('admin/system/keys'));
            exit;
        }

        $service = trim($_POST['service'] ?? '');
        $account = trim($_POST['account_id'] ?? '');
        $apiKey = trim($_POST['api_key'] ?? '');
        $apiSecret = trim($_POST['api_secret'] ?? '');
        
        // Default account_id if empty (but not for bybit)
        if (empty($account)) {
            $account = 'default';
        }

        // BLOCK: bybit/default is architecturally invalid
        if ($service === 'bybit' && $account === 'default') {
            $_SESSION['keys_flash'] = ['type' => 'error', 'message' => 'Bybit requires explicit account id (e.g. trading_bot, main, copytrade). Cannot use "default".'];
            header('Location: ' . System::web('admin/system/keys'));
            exit;
        }

        if (empty($service) || empty($apiKey) || empty($apiSecret)) {
            $_SESSION['keys_flash'] = ['type' => 'error', 'message' => 'Все поля обязательны'];
            header('Location: ' . System::web('admin/system/keys'));
            exit;
        }

        try {
            $keyCenter = KeyCenter::instance();
            $keyCenter->setCredentials($service, $account, [
                'api_key' => $apiKey,
                'api_secret' => $apiSecret,
            ]);

            $_SESSION['keys_flash'] = ['type' => 'success', 'message' => "Ключи для {$service}/{$account} сохранены"];
        } catch (\Exception $e) {
            $_SESSION['keys_flash'] = ['type' => 'error', 'message' => 'Ошибка: ' . $e->getMessage()];
        }

        header('Location: ' . System::web('admin/system/keys'));
        exit;
    }

    /**
     * Delete credentials
     */
    public function keysDelete(): void
    {
        if (!Auth::check()) {
            header('Location: ' . System::adminUrl('login'));
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . System::web('admin/system/keys'));
            exit;
        }

        $service = trim($_POST['service'] ?? '');
        $account = trim($_POST['account_id'] ?? 'default');

        if (empty($service) || empty($account)) {
            $_SESSION['keys_flash'] = ['type' => 'error', 'message' => 'Не указан сервис или аккаунт'];
            header('Location: ' . System::web('admin/system/keys'));
            exit;
        }

        try {
            $keyCenter = KeyCenter::instance();
            $keyCenter->deleteCredentials($service, $account);

            $_SESSION['keys_flash'] = ['type' => 'success', 'message' => "Ключи {$service}/{$account} удалены"];
        } catch (\Exception $e) {
            $_SESSION['keys_flash'] = ['type' => 'error', 'message' => 'Ошибка: ' . $e->getMessage()];
        }

        header('Location: ' . System::web('admin/system/keys'));
        exit;
    }

    /**
     * Test credentials connection
     * For Bybit: returns full diagnostic from BybitGateway::diagnostic()
     */
    public function keysTest(): void
    {
        if (!Auth::check()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            exit;
        }

        $service = trim($_POST['service'] ?? '');
        $account = trim($_POST['account_id'] ?? 'default');

        if (empty($service) || empty($account)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Не указан сервис или аккаунт']);
            exit;
        }

        // BLOCK: bybit/default is architecturally invalid
        if ($service === 'bybit' && $account === 'default') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Bybit requires explicit account id (e.g. trading_bot). Cannot test "default".']);
            exit;
        }

        try {
            $keyCenter = KeyCenter::instance();
            
            if (!$keyCenter->hasCredentials($service, $account)) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Ключи не найдены']);
                exit;
            }

            // Service-specific test: BYBIT
            if ($service === 'bybit') {
                // Use BybitGateway::diagnostic() for full test
                if (class_exists('\\Core\\Gateway\\Bybit')) {
                    $diagnostic = \Core\Gateway\Bybit::diagnostic($account);
                    
                    // Determine overall success
                    $serverOk = $diagnostic['tests']['server_time']['success'] ?? false;
                    $publicOk = $diagnostic['tests']['public_api']['success'] ?? false;
                    
                    if ($serverOk && $publicOk) {
                        header('Content-Type: application/json');
                        echo json_encode([
                            'success' => true, 
                            'message' => 'Bybit соединение успешно',
                            'diagnostic' => $diagnostic
                        ]);
                        exit;
                    } else {
                        $errorMsg = $diagnostic['tests']['server_time']['error'] ?? 
                                    $diagnostic['tests']['public_api']['error'] ?? 
                                    'Unknown error';
                        header('Content-Type: application/json');
                        echo json_encode([
                            'success' => false, 
                            'message' => 'Bybit test failed: ' . $errorMsg,
                            'diagnostic' => $diagnostic
                        ]);
                        exit;
                    }
                }
                
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Bybit Gateway не найден']);
                exit;
            }
            
            // Generic test - just check keys exist
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => "Ключи для {$service}/{$account} найдены"]);
            
        } catch (\Exception $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()]);
        }
        exit;
    }

    /**
     * Cron management page
     */
    public function cron(): void
    {
        if (!Auth::check()) {
            header('Location: ' . System::adminUrl('login'));
            exit;
        }

        $cron = CronManager::instance();
        
        // Flash message from operations
        $flashMessage = $_SESSION['cron_flash'] ?? null;
        unset($_SESSION['cron_flash']);
        
        $data = [
            'tasks' => $cron->getAll(),
            'log' => $cron->getLog(20),
            'due' => $cron->getDue(),
            'total_count' => count($cron->getAll()),
            'enabled_count' => count(array_filter($cron->getAll(), fn($t) => $t['enabled'] ?? false)),
            'flash' => $flashMessage,
        ];

        $this->render('cron', 'Cron Manager', $data);
    }

    /**
     * Toggle cron task enabled/disabled
     */
    public function cronToggle(): void
    {
        if (!Auth::check()) {
            header('Location: ' . System::adminUrl('login'));
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . System::web('admin/system/cron'));
            exit;
        }

        $taskId = trim($_POST['task_id'] ?? '');
        
        if (empty($taskId)) {
            $_SESSION['cron_flash'] = ['type' => 'error', 'message' => 'Task ID не указан'];
            header('Location: ' . System::web('admin/system/cron'));
            exit;
        }

        try {
            $cron = CronManager::instance();
            $tasks = $cron->getAll();
            
            if (!isset($tasks[$taskId])) {
                throw new \Exception("Задача не найдена: {$taskId}");
            }
            
            if ($tasks[$taskId]['enabled'] ?? false) {
                $cron->disable($taskId);
                $_SESSION['cron_flash'] = ['type' => 'success', 'message' => "Задача {$taskId} отключена"];
            } else {
                $cron->enable($taskId);
                $_SESSION['cron_flash'] = ['type' => 'success', 'message' => "Задача {$taskId} включена"];
            }
        } catch (\Exception $e) {
            $_SESSION['cron_flash'] = ['type' => 'error', 'message' => 'Ошибка: ' . $e->getMessage()];
        }

        header('Location: ' . System::web('admin/system/cron'));
        exit;
    }

    /**
     * Run a specific cron task
     */
    public function cronRunTask(): void
    {
        if (!Auth::check()) {
            header('Location: ' . System::adminUrl('login'));
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . System::web('admin/system/cron'));
            exit;
        }

        $taskId = trim($_POST['task_id'] ?? '');
        
        if (empty($taskId)) {
            $_SESSION['cron_flash'] = ['type' => 'error', 'message' => 'Task ID не указан'];
            header('Location: ' . System::web('admin/system/cron'));
            exit;
        }

        try {
            $cron = CronManager::instance();
            $result = $cron->runTask($taskId);
            
            if ($result['success']) {
                $_SESSION['cron_flash'] = ['type' => 'success', 'message' => "Задача {$taskId} выполнена успешно"];
            } else {
                $_SESSION['cron_flash'] = ['type' => 'error', 'message' => "Ошибка выполнения: " . ($result['message'] ?? 'Unknown error')];
            }
        } catch (\Exception $e) {
            $_SESSION['cron_flash'] = ['type' => 'error', 'message' => 'Ошибка: ' . $e->getMessage()];
        }

        header('Location: ' . System::web('admin/system/cron'));
        exit;
    }

    /**
     * Run all due cron tasks
     */
    public function cronRun(): void
    {
        if (!Auth::check()) {
            header('Location: ' . System::adminUrl('login'));
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . System::web('admin/system/cron'));
            exit;
        }

        try {
            $cron = CronManager::instance();
            $results = $cron->run();
            
            $successCount = count(array_filter($results, fn($r) => $r['success'] ?? false));
            $totalCount = count($results);
            
            if ($totalCount === 0) {
                $_SESSION['cron_flash'] = ['type' => 'info', 'message' => 'Нет задач для выполнения'];
            } else {
                $_SESSION['cron_flash'] = ['type' => 'success', 'message' => "Выполнено задач: {$successCount}/{$totalCount}"];
            }
        } catch (\Exception $e) {
            $_SESSION['cron_flash'] = ['type' => 'error', 'message' => 'Ошибка: ' . $e->getMessage()];
        }

        header('Location: ' . System::web('admin/system/cron'));
        exit;
    }

    /**
     * Save (add/edit) cron task
     */
    public function cronSave(): void
    {
        if (!Auth::check()) {
            header('Location: ' . System::adminUrl('login'));
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . System::web('admin/system/cron'));
            exit;
        }

        $originalTaskId = trim($_POST['original_task_id'] ?? '');
        $module = trim($_POST['module'] ?? '');
        $handler = trim($_POST['handler'] ?? '');
        $interval = (int)($_POST['interval'] ?? 60);

        if (empty($module) || empty($handler)) {
            $_SESSION['cron_flash'] = ['type' => 'error', 'message' => 'Модуль и Handler обязательны'];
            header('Location: ' . System::web('admin/system/cron'));
            exit;
        }

        if ($interval < 10) {
            $interval = 10;
        }

        try {
            // If editing existing task with same ID, just update interval
            if (!empty($originalTaskId)) {
                // For edits, we just update the interval by re-registering
                CronManager::register($module, $handler, $interval);
                $_SESSION['cron_flash'] = ['type' => 'success', 'message' => "Задача {$module}:{$handler} обновлена (интервал: {$interval}s)"];
            } else {
                // New task
                CronManager::register($module, $handler, $interval);
                $_SESSION['cron_flash'] = ['type' => 'success', 'message' => "Задача {$module}:{$handler} добавлена"];
            }
        } catch (\Exception $e) {
            $_SESSION['cron_flash'] = ['type' => 'error', 'message' => 'Ошибка: ' . $e->getMessage()];
        }

        header('Location: ' . System::web('admin/system/cron'));
        exit;
    }

    /**
     * Delete cron task
     */
    public function cronDelete(): void
    {
        if (!Auth::check()) {
            header('Location: ' . System::adminUrl('login'));
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . System::web('admin/system/cron'));
            exit;
        }

        $taskId = trim($_POST['task_id'] ?? '');

        if (empty($taskId)) {
            $_SESSION['cron_flash'] = ['type' => 'error', 'message' => 'Task ID не указан'];
            header('Location: ' . System::web('admin/system/cron'));
            exit;
        }

        try {
            $cron = CronManager::instance();
            $cron->unregister($taskId);
            $_SESSION['cron_flash'] = ['type' => 'success', 'message' => "Задача {$taskId} удалена"];
        } catch (\Exception $e) {
            $_SESSION['cron_flash'] = ['type' => 'error', 'message' => 'Ошибка: ' . $e->getMessage()];
        }

        header('Location: ' . System::web('admin/system/cron'));
        exit;
    }

    /**
     * Clear cron execution log
     */
    public function cronClearLog(): void
    {
        if (!Auth::check()) {
            header('Location: ' . System::adminUrl('login'));
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . System::web('admin/system/cron'));
            exit;
        }

        try {
            $storage = StorageManager::instance();
            $storage->set('cron_log', []);
            $_SESSION['cron_flash'] = ['type' => 'success', 'message' => 'Лог выполнения очищен'];
        } catch (\Exception $e) {
            $_SESSION['cron_flash'] = ['type' => 'error', 'message' => 'Ошибка: ' . $e->getMessage()];
        }

        header('Location: ' . System::web('admin/system/cron'));
        exit;
    }

    /**
     * System logs page
     */
    public function logs(): void
    {
        if (!Auth::check()) {
            header('Location: ' . System::adminUrl('login'));
            exit;
        }

        $logsPath = System::path('logs');
        $logFiles = [];
        
        if (is_dir($logsPath)) {
            $files = glob($logsPath . '/*.log');
            foreach ($files as $file) {
                $logFiles[] = [
                    'name' => basename($file),
                    'size' => filesize($file),
                    'modified' => filemtime($file),
                    'lines' => $this->countLogLines($file),
                ];
            }
        }

        $data = [
            'logs_path' => $logsPath,
            'log_files' => $logFiles,
            'current_log' => $_GET['log'] ?? 'system.log',
            'log_content' => $this->getLogContent($_GET['log'] ?? 'system.log'),
        ];

        $this->render('logs', 'Системные логи', $data);
    }

    /**
     * Health / Diagnostics page
     */
    public function health(): void
    {
        if (!Auth::check()) {
            header('Location: ' . System::adminUrl('login'));
            exit;
        }

        $data = [
            // PHP Info
            'php_version' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            
            // System
            'system_version' => System::version(),
            'system_env' => System::env(),
            'system_initialized' => System::isInitialized(),
            
            // Extensions
            'extensions' => [
                'curl' => extension_loaded('curl'),
                'json' => extension_loaded('json'),
                'openssl' => extension_loaded('openssl'),
                'mbstring' => extension_loaded('mbstring'),
                'pdo' => extension_loaded('pdo'),
                'pdo_sqlite' => extension_loaded('pdo_sqlite'),
                'pdo_mysql' => extension_loaded('pdo_mysql'),
            ],
            
            // Directories
            'directories' => $this->checkDirectories(),
            
            // Routing
            'routing' => System::getRoutingDiagnostics(),
            
            // Memory
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
        ];

        $this->render('health', 'Диагностика', $data);
    }

    // ============================================================
    // DATA HELPERS (get REAL data from Core services)
    // ============================================================

    private function getKeysCount(): int
    {
        try {
            $keyCenter = KeyCenter::instance();
            $count = 0;
            foreach ($keyCenter->listServices() as $service) {
                $count += count($keyCenter->listAccounts($service));
            }
            return $count;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function getKeysServices(): array
    {
        try {
            return KeyCenter::instance()->listServices();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function getPathsCount(): int
    {
        // Count the defined system paths dynamically
        $pathKeys = ['root', 'config', 'modules', 'storage', 'runtime', 'logs', 'cache', 'sessions'];
        return count($pathKeys);
    }

    private function getStorageSize(): string
    {
        try {
            $storagePath = System::path('storage');
            $size = $this->getDirectorySize($storagePath);
            return $this->formatBytes($size);
        } catch (\Throwable $e) {
            return 'N/A';
        }
    }

    private function getStorageBackend(): string
    {
        try {
            $config = System::config()->load('storage');
            return $config['backend'] ?? 'unknown';
        } catch (\Throwable $e) {
            return 'unknown';
        }
    }

    private function getStorageKeys(): array
    {
        try {
            $storage = StorageManager::instance();
            return $storage->keys();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function getModulesCount(): int
    {
        try {
            return count(ModuleManager::instance()->all());
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function getModulesEnabled(): int
    {
        try {
            $modules = ModuleManager::instance()->all();
            return count(array_filter($modules, fn($m) => $m['enabled'] ?? false));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function getCronTasksCount(): int
    {
        try {
            return count(CronManager::instance()->getAll());
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function getCronEnabledCount(): int
    {
        try {
            $tasks = CronManager::instance()->getAll();
            return count(array_filter($tasks, fn($t) => $t['enabled'] ?? false));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function getCronLastRun(): ?string
    {
        try {
            $log = CronManager::instance()->getLog(1);
            return $log[0]['timestamp'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function getCronStatus(): string
    {
        try {
            $log = CronManager::instance()->getLog(5);
            $errors = 0;
            foreach ($log as $entry) {
                if (!($entry['success'] ?? true)) {
                    $errors++;
                }
            }
            return $errors === 0 ? 'ok' : ($errors <= 2 ? 'warning' : 'error');
        } catch (\Throwable $e) {
            return 'unknown';
        }
    }

    private function getPagesTotal(): int
    {
        try {
            require_once System::path('root') . '/modules/system/pages/registry.php';
            return \Modules\System\Pages\PagesRegistry::instance()->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function getPagesSystem(): int
    {
        try {
            require_once System::path('root') . '/modules/system/pages/registry.php';
            return \Modules\System\Pages\PagesRegistry::instance()->countByType('system');
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function getPagesModule(): int
    {
        try {
            require_once System::path('root') . '/modules/system/pages/registry.php';
            return \Modules\System\Pages\PagesRegistry::instance()->countByType('module');
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function getHealthStatus(): string
    {
        // Check critical components
        $issues = 0;
        
        // Check writable directories
        $dirs = ['runtime', 'logs', 'cache', 'storage'];
        foreach ($dirs as $dir) {
            $path = System::path($dir);
            if (!is_writable($path)) {
                $issues++;
            }
        }
        
        // Check extensions
        if (!extension_loaded('curl')) $issues++;
        if (!extension_loaded('json')) $issues++;
        if (!extension_loaded('openssl')) $issues++;
        
        if ($issues === 0) {
            return 'ok';
        } elseif ($issues <= 2) {
            return 'warning';
        } else {
            return 'error';
        }
    }

    private function getMemoryUsage(): string
    {
        return $this->formatBytes(memory_get_usage(true));
    }

    private function getLastUpdate(): ?string
    {
        try {
            $storage = StorageManager::instance();
            $updates = $storage->get('system/github');
            return $updates['last_fetch'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function getDirectorySize(string $path): int
    {
        $size = 0;
        if (!is_dir($path)) {
            return $size;
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
        
        return $size;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    private function countLogLines(string $file): int
    {
        if (!file_exists($file)) {
            return 0;
        }
        return count(file($file));
    }

    private function getLogContent(string $filename): string
    {
        $path = System::path('logs') . '/' . basename($filename);
        if (!file_exists($path)) {
            return '';
        }
        
        // Get last 100 lines
        $lines = file($path);
        $lastLines = array_slice($lines, -100);
        return implode('', $lastLines);
    }

    private function checkDirectories(): array
    {
        $dirs = [
            'runtime' => System::path('runtime'),
            'logs' => System::path('logs'),
            'cache' => System::path('cache'),
            'storage' => System::path('storage'),
            'sessions' => System::path('sessions'),
        ];
        
        $result = [];
        foreach ($dirs as $name => $path) {
            $result[$name] = [
                'path' => $path,
                'exists' => is_dir($path),
                'writable' => is_writable($path),
            ];
        }
        
        return $result;
    }

    // ============================================================
    // RENDER HELPER
    // ============================================================

    private function render(string $view, string $title, array $data = []): void
    {
        $viewPath = System::path('root') . '/modules/system/ui/views/' . $view . '.php';
        
        if (!file_exists($viewPath)) {
            echo "View not found: {$view}";
            return;
        }
        
        // Extract data for view
        extract($data);
        
        // Capture view content
        ob_start();
        include $viewPath;
        $content = ob_get_clean();
        
        // Load layout
        $layoutPath = System::path('root') . '/admin/views/layout.php';
        require_once $layoutPath;
        
        // Render with layout
        echo renderLayout($title, $content, 'system', [
            'page_title' => $title,
        ]);
    }
}
