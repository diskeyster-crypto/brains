<?php

declare(strict_types=1);

namespace Modules\System\Parser;

use Core\System\System;
use Core\Auth\Auth;
use Core\Logger\Logger;
use Core\Module\ModuleManager;

/**
 * Parser Controller
 * 
 * Controller for managing parser modules.
 * Discovers parsers from modules/parser/* directory.
 */
final class ParserController
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
     * Main Parser UI
     */
    public function index(): string
    {
        if (!Auth::check()) {
            header('Location: ' . System::adminUrl('login'));
            exit;
        }

        $parsers = $this->discoverParsers();
        $flash = $this->getFlash();

        ob_start();
        $title = 'Парсеры';
        require __DIR__ . '/views/index.php';
        $content = ob_get_clean();

        return $this->wrapLayout($title, $content);
    }

    /**
     * API: List all parsers
     */
    public function apiList(): void
    {
        if (!Auth::check()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $parsers = $this->discoverParsers();
        $this->jsonResponse($parsers);
    }

    /**
     * API: Run parser manually
     */
    public function apiRun(): void
    {
        if (!Auth::check()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $parser = $input['module'] ?? $input['parser'] ?? '';

        if (empty($parser)) {
            $this->jsonResponse(['error' => 'Parser name required'], 400);
            return;
        }

        $result = $this->executeParser($parser);
        $this->jsonResponse($result);
    }

    /**
     * API: Clear parser storage
     */
    public function apiClear(): void
    {
        if (!Auth::check()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $parser = $input['module'] ?? $input['parser'] ?? '';

        if (empty($parser)) {
            $this->jsonResponse(['error' => 'Parser name required'], 400);
            return;
        }

        $result = $this->clearStorage($parser);
        $this->jsonResponse($result);
    }

    /**
     * API: Get parser config
     */
    public function apiConfig(): void
    {
        if (!Auth::check()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $parser = $_GET['module'] ?? $_GET['parser'] ?? '';

        if (empty($parser)) {
            $this->jsonResponse(['error' => 'Parser name required'], 400);
            return;
        }

        $config = $this->loadConfig($parser);
        $this->jsonResponse($config);
    }

    /**
     * API: Save parser config
     */
    public function apiConfigSave(): void
    {
        if (!Auth::check()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $parser = $input['module'] ?? $input['parser'] ?? '';
        $config = $input['config'] ?? null;

        if (empty($parser)) {
            $this->jsonResponse(['error' => 'Parser name required'], 400);
            return;
        }

        if (!is_array($config)) {
            $this->jsonResponse(['error' => 'Config must be an array'], 400);
            return;
        }

        $result = $this->saveConfig($parser, $config);
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
        $parser = $_POST['module'] ?? $_POST['parser'] ?? '';

        switch ($action) {
            case 'run':
                $result = $this->executeParser($parser);
                $this->setFlash($result['success'] ? 'success' : 'error', $result['message'] ?? 'Executed');
                break;

            case 'clear':
                $result = $this->clearStorage($parser);
                $this->setFlash($result['success'] ? 'success' : 'error', $result['message'] ?? 'Cleared');
                break;

            default:
                $this->setFlash('error', 'Unknown action');
        }

        header('Location: ' . System::web('admin/parser'));
        exit;
    }

    /**
     * Discover all parsers in modules/parser directory
     */
    private function discoverParsers(): array
    {
        $parsersDir = System::path('root') . '/modules/parser';
        
        if (!is_dir($parsersDir)) {
            return [];
        }

        $parsers = [];
        $dirs = scandir($parsersDir);
        
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }
            
            $parserDir = $parsersDir . '/' . $dir;
            if (!is_dir($parserDir)) {
                continue;
            }
            
            // Parser must have cron.php to be discovered
            $cronFile = $parserDir . '/cron.php';
            if (!is_file($cronFile)) {
                continue;
            }
            
            $parser = $this->buildParserInfo($dir, $parserDir);
            if ($parser !== null) {
                $parsers[] = $parser;
            }
        }
        
        return $parsers;
    }

    /**
     * Build parser info from directory
     */
    private function buildParserInfo(string $parserName, string $parserDir): ?array
    {
        $cronFile = $parserDir . '/cron.php';
        $cronConfig = [];
        
        if (is_file($cronFile)) {
            $cronConfig = require $cronFile;
            if (!is_array($cronConfig)) {
                $cronConfig = [];
            }
        }

        // Get interval from cron config
        $interval = '—';
        $intervalSec = 0;
        $enabled = false;
        $description = '';
        
        foreach ($cronConfig as $handler => $taskConfig) {
            if (is_array($taskConfig)) {
                $intervalSec = (int)($taskConfig['interval'] ?? 0);
                $interval = $this->formatInterval($intervalSec);
                $enabled = (bool)($taskConfig['enabled'] ?? false);
                $description = $taskConfig['description'] ?? '';
                break;
            }
        }

        // Read last_run.json (contains all run statistics)
        $lastRunFile = $parserDir . '/storage/last_run.json';
        $lastRunData = [];
        
        if (is_file($lastRunFile)) {
            $data = json_decode((string)file_get_contents($lastRunFile), true);
            if (is_array($data)) {
                $lastRunData = $data;
            }
        }

        // Read state.json for additional stats
        $stateFile = $parserDir . '/storage/state.json';
        $stateData = [];
        
        if (is_file($stateFile)) {
            $data = json_decode((string)file_get_contents($stateFile), true);
            if (is_array($data)) {
                $stateData = $data;
            }
        }

        // Extract values
        $lastRun = $lastRunData['ts'] ?? $lastRunData['timestamp'] ?? null;
        $status = ($lastRunData['ok'] ?? $lastRunData['success'] ?? false) ? 'ok' : 'error';
        $durationMs = $lastRunData['duration_ms'] ?? null;
        $errorsCount = $lastRunData['errors_count'] ?? $stateData['errors'] ?? 0;

        // Collect key metrics from last_run.json
        $metrics = [];
        $metricKeys = [
            'active_symbols', 'processed', 'candidates', 'blocklist', 'watchlist',
            'whitelist', 'blacklist', 'signals', 'delisted_count', 'history_missing',
            'rejected_below_min_abs', 'processed_categories', 'profile'
        ];
        
        foreach ($metricKeys as $key) {
            if (isset($lastRunData[$key])) {
                $metrics[$key] = $lastRunData[$key];
            }
        }
        
        // Also check state.json for metrics
        foreach ($metricKeys as $key) {
            if (!isset($metrics[$key]) && isset($stateData[$key])) {
                $metrics[$key] = $stateData[$key];
            }
        }

        $title = ucwords(str_replace(['_', '-'], ' ', $parserName));

        // Calculate next run time if enabled and interval > 0
        $nextRun = null;
        if ($enabled && $intervalSec > 0 && $lastRun) {
            try {
                $lastRunTime = new \DateTime($lastRun);
                $nextRunTime = clone $lastRunTime;
                $nextRunTime->add(new \DateInterval('PT' . $intervalSec . 'S'));
                $nextRun = $nextRunTime->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
                // Ignore date parse errors
            }
        }

        return [
            'name' => $parserName,
            'title' => $title,
            'description' => $description,
            'enabled' => $enabled,
            'last_run' => $lastRun,
            'next_run' => $nextRun,
            'duration_ms' => $durationMs,
            'cron_interval' => $interval,
            'interval_sec' => $intervalSec,
            'status' => $status,
            'errors_count' => $errorsCount,
            'metrics' => $metrics,
            'has_config' => is_file($parserDir . '/config/config.php'),
            'last_run_data' => $lastRunData,
            'state_data' => $stateData,
        ];
    }

    /**
     * Execute parser
     */
    private function executeParser(string $parserName): array
    {
        $parserDir = $this->findParserDir($parserName);
        
        if ($parserDir === null) {
            return ['success' => false, 'message' => 'Parser not found'];
        }

        $serviceFile = $parserDir . '/service.php';
        if (!is_file($serviceFile)) {
            return ['success' => false, 'message' => 'Service file not found'];
        }

        try {
            require_once $serviceFile;

            // Convert parser name to class name (e.g., delist_parser0 -> DelistParser0Service)
            $className = str_replace(['_', '-'], ' ', $parserName);
            $className = str_replace(' ', '', ucwords($className));
            $className .= 'Service';

            if (!class_exists($className)) {
                return ['success' => false, 'message' => "Service class {$className} not found"];
            }

            $service = new $className();
            
            // Try execute() first, then run()
            $startTime = microtime(true);
            
            if (method_exists($service, 'execute')) {
                $result = $service->execute();
            } elseif (method_exists($service, 'run')) {
                $result = $service->run();
            } else {
                return ['success' => false, 'message' => 'Service has no execute() or run() method'];
            }
            
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
            Logger::error("Parser execute error: " . $e->getMessage(), [
                'parser' => $parserName,
            ]);
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Find parser directory
     */
    private function findParserDir(string $parserName): ?string
    {
        $parsersDir = System::path('root') . '/modules/parser';
        $parserDir = $parsersDir . '/' . $parserName;
        
        if (is_dir($parserDir)) {
            return $parserDir;
        }
        
        return null;
    }

    /**
     * Clear storage
     */
    private function clearStorage(string $parserName): array
    {
        $parserDir = $this->findParserDir($parserName);
        if ($parserDir === null) {
            return ['success' => false, 'message' => 'Parser not found'];
        }
        
        $storageDir = $parserDir . '/storage';

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
    private function loadConfig(string $parserName): array
    {
        $parserDir = $this->findParserDir($parserName);
        if ($parserDir === null) {
            return ['success' => false, 'error' => 'Parser not found'];
        }
        
        $configFile = $parserDir . '/config/config.php';

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
     */
    private function saveConfig(string $parserName, array $newConfig): array
    {
        $parserDir = $this->findParserDir($parserName);
        if ($parserDir === null) {
            return ['success' => false, 'error' => 'Parser not found'];
        }
        
        $configFile = $parserDir . '/config/config.php';

        if (!is_file($configFile)) {
            return ['success' => false, 'error' => 'Config file not found'];
        }

        try {
            // Backup
            $backupFile = $configFile . '.backup.' . date('YmdHis');
            copy($configFile, $backupFile);

            // Use ConfigGuard for safe, non-destructive config saving
            $configGuardPath = System::path('root') . '/core/config/configguard.php';
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
        $_SESSION['parser_flash'] = [
            'type' => $type,
            'message' => $message,
        ];
    }

    private function getFlash(): ?array
    {
        if (!isset($_SESSION['parser_flash'])) {
            return null;
        }
        $flash = $_SESSION['parser_flash'];
        unset($_SESSION['parser_flash']);
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
        return renderLayout($title, $content, 'parser', []);
    }
}
