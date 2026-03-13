<?php

declare(strict_types=1);

namespace Modules\System\Category;

use Core\System\System;
use Core\Auth\Auth;
use Core\Logger\Logger;
use Core\Module\ModuleManager;

/**
 * Category Controller
 * 
 * Generic controller for module categories (signal, trading, etc).
 * Uses the same ModuleManager system as parsers - no duplicate systems.
 * 
 * This is a generalization of ParserController for other categories.
 */
final class CategoryController
{
    private static ?self $instance = null;
    private string $category = 'signal';
    private string $categoryTitle = 'Signals';

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
     * Set the active category
     */
    public function setCategory(string $category, string $title = ''): self
    {
        $this->category = $category;
        $this->categoryTitle = $title ?: ucfirst($category) . 's';
        return $this;
    }

    /**
     * Main Category UI
     */
    public function index(): string
    {
        if (!Auth::check()) {
            header('Location: ' . System::adminUrl('login'));
            exit;
        }

        $modules = $this->discoverModules();
        $flash = $this->getFlash();
        
        $category = $this->category;
        $categoryTitle = $this->categoryTitle;

        ob_start();
        $title = $categoryTitle . ' Manager';
        require __DIR__ . '/views/index.php';
        $content = ob_get_clean();

        return $this->wrapLayout($title, $content);
    }

    /**
     * API: List all modules in category
     */
    public function apiList(): void
    {
        if (!Auth::check()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $modules = $this->discoverModules();
        $this->jsonResponse($modules);
    }

    /**
     * API: Run module manually
     */
    public function apiRun(): void
    {
        if (!Auth::check()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $module = $input['module'] ?? '';

        if (empty($module)) {
            $this->jsonResponse(['error' => 'Module name required'], 400);
            return;
        }

        $result = $this->executeModule($module);
        $this->jsonResponse($result);
    }

    /**
     * API: Clear module storage
     */
    public function apiClear(): void
    {
        if (!Auth::check()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $module = $input['module'] ?? '';

        if (empty($module)) {
            $this->jsonResponse(['error' => 'Module name required'], 400);
            return;
        }

        $result = $this->clearStorage($module);
        $this->jsonResponse($result);
    }

    /**
     * API: Get module config
     */
    public function apiConfig(): void
    {
        if (!Auth::check()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $module = $_GET['module'] ?? '';

        if (empty($module)) {
            $this->jsonResponse(['error' => 'Module name required'], 400);
            return;
        }

        $config = $this->loadConfig($module);
        $this->jsonResponse($config);
    }

    /**
     * API: Save module config
     */
    public function apiConfigSave(): void
    {
        if (!Auth::check()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $module = $input['module'] ?? '';
        $config = $input['config'] ?? null;

        if (empty($module)) {
            $this->jsonResponse(['error' => 'Module name required'], 400);
            return;
        }

        if (!is_array($config)) {
            $this->jsonResponse(['error' => 'Config must be an array'], 400);
            return;
        }

        $result = $this->saveConfig($module, $config);
        $this->jsonResponse($result);
    }

    /**
     * POST handler for form actions
     */
    public function handleAction(): void
    {
        if (!Auth::check()) {
            header('Location: ' . System::adminUrl('login'));
            exit;
        }

        $action = $_POST['action'] ?? '';
        $module = $_POST['module'] ?? '';

        switch ($action) {
            case 'run':
                $result = $this->executeModule($module);
                $this->setFlash($result['success'] ? 'success' : 'error', $result['message'] ?? 'Executed');
                break;

            case 'clear':
                $result = $this->clearStorage($module);
                $this->setFlash($result['success'] ? 'success' : 'error', $result['message'] ?? 'Cleared');
                break;

            default:
                $this->setFlash('error', 'Unknown action');
        }

        header('Location: ' . System::web('admin/' . $this->category));
        exit;
    }

    /**
     * Discover all modules in category
     */
    private function discoverModules(): array
    {
        $moduleManager = ModuleManager::instance();
        $categoryModules = $moduleManager->getByCategory($this->category);
        
        $modules = [];
        foreach ($categoryModules as $moduleName => $moduleInfo) {
            $moduleDir = $moduleInfo['path'] ?? '';
            if (empty($moduleDir) || !is_dir($moduleDir)) {
                continue;
            }
            
            $module = $this->buildModuleInfo($moduleName, $moduleDir);
            if ($module !== null) {
                $modules[] = $module;
            }
        }
        
        return $modules;
    }

    /**
     * Build module info from directory
     */
    private function buildModuleInfo(string $moduleName, string $moduleDir): ?array
    {
        $cronFile = $moduleDir . '/cron.php';
        $cronConfig = [];
        
        if (is_file($cronFile)) {
            $cronConfig = require $cronFile;
            if (!is_array($cronConfig)) {
                $cronConfig = [];
            }
        }

        // Get interval from cron config
        $interval = '—';
        $enabled = false;
        $description = '';
        
        foreach ($cronConfig as $handler => $taskConfig) {
            if (is_array($taskConfig)) {
                $intervalSec = $taskConfig['interval'] ?? 0;
                $interval = $this->formatInterval((int)$intervalSec);
                $enabled = (bool)($taskConfig['enabled'] ?? false);
                $description = $taskConfig['description'] ?? '';
                break;
            }
        }

        // Read last_run.json
        $lastRunFile = $moduleDir . '/storage/last_run.json';
        $lastRunData = [];
        
        if (is_file($lastRunFile)) {
            $data = json_decode((string)file_get_contents($lastRunFile), true);
            if (is_array($data)) {
                $lastRunData = $data;
            }
        }

        // Extract values
        $lastRun = $lastRunData['ts'] ?? $lastRunData['timestamp'] ?? null;
        $status = ($lastRunData['ok'] ?? $lastRunData['success'] ?? false) ? 'ok' : 'error';
        $durationMs = $lastRunData['duration_ms'] ?? null;

        $title = ucwords(str_replace(['_', '-'], ' ', $moduleName));

        return [
            'name' => $moduleName,
            'title' => $title,
            'description' => $description,
            'enabled' => $enabled,
            'last_run' => $lastRun,
            'duration_ms' => $durationMs,
            'cron_interval' => $interval,
            'status' => $status,
            'has_config' => is_file($moduleDir . '/config/config.php'),
        ];
    }

    /**
     * Execute module
     */
    private function executeModule(string $moduleName): array
    {
        $moduleDir = $this->findModuleDir($moduleName);
        
        if ($moduleDir === null) {
            return ['success' => false, 'message' => 'Module not found'];
        }

        $serviceFile = $moduleDir . '/service.php';
        if (!is_file($serviceFile)) {
            return ['success' => false, 'message' => 'Service file not found'];
        }

        try {
            require_once $serviceFile;

            $className = str_replace(['_', '-'], ' ', $moduleName);
            $className = str_replace(' ', '', ucwords($className));
            $className .= 'Service';

            if (!class_exists($className)) {
                return ['success' => false, 'message' => "Service class {$className} not found"];
            }

            $service = new $className();
            
            if (!method_exists($service, 'execute')) {
                return ['success' => false, 'message' => 'Service has no execute() method'];
            }

            $startTime = microtime(true);
            $result = $service->execute();
            $duration = round((microtime(true) - $startTime) * 1000);

            $isSuccess = ($result['success'] ?? false) || ($result['ok'] ?? false);
            $message = "Executed in {$duration}ms";

            return [
                'success' => $isSuccess,
                'message' => $isSuccess ? $message : ($result['message'] ?? 'Failed'),
                'result' => $result,
                'duration_ms' => $duration,
            ];

        } catch (\Throwable $e) {
            Logger::error("Module execute error: " . $e->getMessage(), [
                'module' => $moduleName,
                'category' => $this->category,
            ]);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Find module directory
     */
    private function findModuleDir(string $moduleName): ?string
    {
        $modulesDir = System::path('root') . '/modules';
        
        // Check category folder first
        $categoryPath = $modulesDir . '/' . $this->category . '/' . $moduleName;
        if (is_dir($categoryPath)) {
            return $categoryPath;
        }
        
        // Fallback to flat structure
        $flatPath = $modulesDir . '/' . $moduleName;
        if (is_dir($flatPath)) {
            return $flatPath;
        }
        
        return null;
    }

    /**
     * Clear storage
     */
    private function clearStorage(string $moduleName): array
    {
        $moduleDir = $this->findModuleDir($moduleName);
        if ($moduleDir === null) {
            return ['success' => false, 'message' => 'Module not found'];
        }
        
        $storageDir = $moduleDir . '/storage';

        if (!is_dir($storageDir)) {
            return ['success' => false, 'message' => 'Storage directory not found'];
        }

        $files = glob($storageDir . '/*') ?: [];
        $deleted = 0;

        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
                $deleted++;
            }
        }

        return [
            'success' => true,
            'message' => "Deleted {$deleted} files",
            'deleted' => $deleted,
        ];
    }

    /**
     * Load config
     */
    private function loadConfig(string $moduleName): array
    {
        $moduleDir = $this->findModuleDir($moduleName);
        if ($moduleDir === null) {
            return ['success' => false, 'error' => 'Module not found'];
        }
        
        $configFile = $moduleDir . '/config/config.php';

        if (!is_file($configFile)) {
            return ['success' => false, 'error' => 'Config file not found'];
        }

        try {
            $config = require $configFile;
            return [
                'success' => true,
                'config' => $config,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Save config with protected keys preservation
     * 
     * Uses ConfigGuard for safe, non-destructive config saving.
     * Protected keys (sources, output, ui, etc.) are never overwritten from UI.
     * 
     * @param string $moduleName Module name
     * @param array<string, mixed> $newConfig New config values from UI
     * @return array<string, mixed> Result with success status
     */
    private function saveConfig(string $moduleName, array $newConfig): array
    {
        $moduleDir = $this->findModuleDir($moduleName);
        if ($moduleDir === null) {
            return ['success' => false, 'error' => 'Module not found'];
        }
        
        $configFile = $moduleDir . '/config/config.php';

        if (!is_file($configFile)) {
            return ['success' => false, 'error' => 'Config file not found'];
        }

        try {
            // Backup
            $backupFile = $configFile . '.backup.' . date('YmdHis');
            copy($configFile, $backupFile);

            // Use ConfigGuard for safe, non-destructive config saving
            $configGuardPath = __DIR__ . '/../../../core/config/configguard.php';
            if (file_exists($configGuardPath)) {
                require_once $configGuardPath;
                
                if (class_exists('ConfigGuard')) {
                    $result = \ConfigGuard::save($configFile, $newConfig);
                    
                    if ($result) {
                        return [
                            'success' => true,
                            'message' => 'Config saved (via ConfigGuard)',
                            'backup' => basename($backupFile),
                        ];
                    } else {
                        return ['success' => false, 'error' => 'ConfigGuard save failed'];
                    }
                }
            }

            // Fallback: safe merge with protected keys preservation
            $existingConfig = require $configFile;
            if (!is_array($existingConfig)) {
                return ['success' => false, 'error' => 'Cannot read existing config'];
            }

            // Protected keys that must NEVER be overwritten from UI
            $protectedKeys = ['sources', 'output', 'ui', 'systempaths', 'paths', 'internal', 'profiles'];
            
            // Preserve protected sections from original config
            foreach ($protectedKeys as $key) {
                if (isset($existingConfig[$key])) {
                    $newConfig[$key] = $existingConfig[$key];
                }
            }

            // Deep merge: existing config + new values (protected keys already preserved)
            $mergedConfig = array_replace_recursive($existingConfig, $newConfig);

            $phpCode = "<?php\ndeclare(strict_types=1);\n\n";
            $phpCode .= "return " . var_export($mergedConfig, true) . ";\n";

            if (file_put_contents($configFile, $phpCode) === false) {
                return ['success' => false, 'error' => 'Failed to write config'];
            }

            return [
                'success' => true,
                'message' => 'Config saved',
                'backup' => basename($backupFile),
            ];

        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function formatInterval(int $seconds): string
    {
        if ($seconds <= 0) return '—';
        if ($seconds < 60) return "{$seconds}s";
        if ($seconds < 3600) return ((int)($seconds / 60)) . "m";
        return ((int)($seconds / 3600)) . "h";
    }

    private function setFlash(string $type, string $message): void
    {
        $_SESSION['category_flash_' . $this->category] = [
            'type' => $type,
            'message' => $message,
        ];
    }

    private function getFlash(): ?array
    {
        $key = 'category_flash_' . $this->category;
        if (!isset($_SESSION[$key])) {
            return null;
        }
        $flash = $_SESSION[$key];
        unset($_SESSION[$key]);
        return $flash;
    }

    private function jsonResponse(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function wrapLayout(string $title, string $content): string
    {
        require_once System::path('root') . '/admin/views/layout.php';
        return renderLayout($title, $content, $this->category, []);
    }
}
