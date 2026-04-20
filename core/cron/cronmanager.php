<?php

declare(strict_types=1);

namespace Core\Cron;

use Core\System\System;
use Core\Storage\StorageManager;
use Core\Logger\Logger;

final class CronManager
{
    private const STORAGE_KEY = 'cron_tasks';
    private const LOG_KEY = 'cron_log';
    private const REGISTRY_FILE = 'storage/cron/registry.php';
    
    private static ?self $instance = null;
    private array $tasks = [];
    private bool $loaded = false;

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

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        // 1. Discover cron tasks from modules (primary source)
        $this->discoverModuleCrons();

        // 2. Load from registry file (legacy support)
        $registryPath = System::path('root') . '/' . self::REGISTRY_FILE;
        if (file_exists($registryPath)) {
            $registryTasks = require $registryPath;
            if (is_array($registryTasks)) {
                foreach ($registryTasks as $taskId => $config) {
                    if (!isset($this->tasks[$taskId])) {
                        $this->tasks[$taskId] = [
                            'module' => explode(':', $taskId)[0] ?? $taskId,
                            'handler' => $config['handler'] ?? 'runner.php',
                            'interval' => (int)($config['schedule'] ?? 60),
                            'enabled' => $config['enabled'] ?? true,
                            'description' => $config['description'] ?? '',
                            'last_run' => null,
                            'next_run' => time(),
                            'from_registry' => true,
                        ];
                    }
                }
            }
        }

        // 3. Load runtime data (last_run, next_run, enabled states) from storage
        $storage = StorageManager::instance();
        $storedTasks = $storage->get(self::STORAGE_KEY) ?? [];
        
        foreach ($storedTasks as $taskId => $task) {
            if (!isset($this->tasks[$taskId])) {
                // Task only in storage (manually registered)
                $this->tasks[$taskId] = $task;
            } else {
                // Merge runtime data (last_run, next_run, enabled state)
                $this->tasks[$taskId]['last_run'] = $task['last_run'] ?? $this->tasks[$taskId]['last_run'] ?? null;
                $this->tasks[$taskId]['next_run'] = $task['next_run'] ?? $this->tasks[$taskId]['next_run'] ?? time();
                // Override enabled state from storage if explicitly set
                if (isset($task['enabled'])) {
                    $this->tasks[$taskId]['enabled'] = $task['enabled'];
                }
            }
        }
        
        $this->loaded = true;
    }
    
    /**
     * Discover cron tasks from module cron.php files
     * 
     * Each module can define cron tasks in /modules/{name}/cron.php
     * or /modules/{category}/{name}/cron.php
     * File should return array:
     * [
     *     'handler_name' => [
     *         'interval' => 60,       // seconds
     *         'enabled' => true,      // optional, default true
     *         'description' => '...'  // optional
     *     ]
     * ]
     */
    private function discoverModuleCrons(): void
    {
        $modulesPath = System::path('modules');
        
        if (!is_dir($modulesPath)) {
            return;
        }
        
        // Supported categories for nested structure
        $categories = ['parser', 'signal', 'trading', 'system', 'simulator', 'strategy'];
        
        $items = scandir($modulesPath);
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            
            $itemPath = $modulesPath . '/' . $item;
            
            if (!is_dir($itemPath)) {
                continue;
            }
            
            // Check if this is a category folder
            if (in_array($item, $categories, true)) {
                // Scan modules within category
                $categoryModules = scandir($itemPath);
                foreach ($categoryModules as $moduleName) {
                    if ($moduleName === '.' || $moduleName === '..') {
                        continue;
                    }
                    
                    $modulePath = $itemPath . '/' . $moduleName;
                    if (is_dir($modulePath)) {
                        $this->discoverModuleCron($moduleName, $modulePath);
                    }
                }
            } else {
                // Legacy: direct module in modules/ folder
                $this->discoverModuleCron($item, $itemPath);
            }
        }
    }
    
    /**
     * Discover cron tasks from a single module
     */
    private function discoverModuleCron(string $moduleName, string $modulePath): void
    {
        $cronFile = $modulePath . '/cron.php';
        
        if (!file_exists($cronFile)) {
            return;
        }
        
        try {
            $cronConfig = require $cronFile;
            
            if (!is_array($cronConfig)) {
                Logger::cron("Invalid cron.php in module {$moduleName}: must return array");
                return;
            }
            
            foreach ($cronConfig as $handlerName => $config) {
                if (!is_array($config)) {
                    continue;
                }
                
                $taskId = "{$moduleName}:{$handlerName}";
                
                $this->tasks[$taskId] = [
                    'module' => $moduleName,
                    'handler' => $handlerName,
                    'interval' => (int)($config['interval'] ?? 60),
                    'enabled' => $config['enabled'] ?? true,
                    'description' => $config['description'] ?? '',
                    'priority' => (int)($config['priority'] ?? 50),
                    'last_run' => null,
                    'next_run' => time(),
                    'from_module' => true,
                    'module_path' => $modulePath,
                ];
            }
            
        } catch (\Throwable $e) {
            Logger::cron("Error loading cron.php from module {$moduleName}", [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * List all tasks from registry
     * 
     * @return array Array of tasks with metadata
     */
    public function list(): array
    {
        $this->load();
        return $this->tasks;
    }

    /**
     * Run a specific task by ID or module name
     * 
     * @param string $taskId Task identifier (e.g., 'example' or 'example:execute')
     * @return array Execution result
     */
    public function runTask(string $taskId): array
    {
        $this->load();
        
        // Find task by exact match or prefix match
        $foundTask = null;
        $foundId = null;
        
        if (isset($this->tasks[$taskId])) {
            $foundTask = $this->tasks[$taskId];
            $foundId = $taskId;
        } else {
            // Try to find by module prefix
            foreach ($this->tasks as $id => $task) {
                if (strpos($id, $taskId . ':') === 0 || $task['module'] === $taskId) {
                    $foundTask = $task;
                    $foundId = $id;
                    break;
                }
            }
        }
        
        if ($foundTask === null) {
            return [
                'success' => false,
                'task_id' => $taskId,
                'message' => "Task not found: {$taskId}",
            ];
        }
        
        return $this->executeTask($foundId, $foundTask);
    }

    private function save(): void
    {
        $storage = StorageManager::instance();
        $storage->set(self::STORAGE_KEY, $this->tasks);
    }

    public static function register(string $module, string $handler, int $intervalSec): void
    {
        $instance = self::instance();
        $instance->load();

        $taskId = "{$module}:{$handler}";
        
        $instance->tasks[$taskId] = [
            'module' => $module,
            'handler' => $handler,
            'interval' => $intervalSec,
            'enabled' => true,
            'last_run' => null,
            'next_run' => time(),
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $instance->save();
        Logger::cron("Cron task registered: {$taskId}", ['interval' => $intervalSec]);
    }

    public function enable(string $taskId): void
    {
        $this->load();
        
        if (!isset($this->tasks[$taskId])) {
            throw new \InvalidArgumentException("Task not found: {$taskId}");
        }

        $this->tasks[$taskId]['enabled'] = true;
        $this->save();
        Logger::cron("Cron task enabled: {$taskId}");
    }

    public function disable(string $taskId): void
    {
        $this->load();
        
        if (!isset($this->tasks[$taskId])) {
            throw new \InvalidArgumentException("Task not found: {$taskId}");
        }

        $this->tasks[$taskId]['enabled'] = false;
        $this->save();
        Logger::cron("Cron task disabled: {$taskId}");
    }

    public function unregister(string $taskId): void
    {
        $this->load();
        unset($this->tasks[$taskId]);
        $this->save();
        Logger::cron("Cron task unregistered: {$taskId}");
    }

    public function getAll(): array
    {
        $this->load();
        return $this->tasks;
    }
    
    /**
     * Alias for getAll() - returns all tasks
     */
    public function getAllTasks(): array
    {
        return $this->getAll();
    }
    
    /**
     * Force refresh - rediscover module crons
     */
    public function refresh(): void
    {
        $this->tasks = [];
        $this->loaded = false;
        $this->load();
    }

    public function getDue(): array
    {
        $this->load();
        $now = time();
        $due = [];

        foreach ($this->tasks as $taskId => $task) {
            if ($task['enabled'] && ($task['next_run'] === null || $task['next_run'] <= $now)) {
                $due[$taskId] = $task;
            }
        }

        // Sort by priority descending: higher priority value runs first.
        // Tasks without explicit priority default to 50.
        uasort($due, static function (array $a, array $b): int {
            return ($b['priority'] ?? 50) <=> ($a['priority'] ?? 50);
        });

        return $due;
    }

    public function run(): array
    {
        $this->load();
        $due = $this->getDue();
        $results = [];

        foreach ($due as $taskId => $task) {
            $result = $this->executeTask($taskId, $task);
            $results[$taskId] = $result;
        }

        return $results;
    }

    private function executeTask(string $taskId, array $task): array
    {
        $startTime = microtime(true);
        $result = [
            'task_id' => $taskId,
            'started_at' => date('Y-m-d H:i:s'),
            'success' => false,
            'message' => '',
            'duration' => 0,
        ];

        try {
            $module = $task['module'];
            $handler = $task['handler'];
            
            // Use stored module_path if available (for category-based modules)
            // Otherwise fallback to flat structure
            if (!empty($task['module_path'])) {
                $modulePath = $task['module_path'] . '/service.php';
            } else {
                // Try category paths first, then flat
                $categories = ['parser', 'signal', 'trading', 'system', 'simulator', 'strategy'];
                $modulePath = System::path('modules') . '/' . $module . '/service.php';
                
                foreach ($categories as $cat) {
                    $catPath = System::path('modules') . '/' . $cat . '/' . $module . '/service.php';
                    if (file_exists($catPath)) {
                        $modulePath = $catPath;
                        break;
                    }
                }
            }
            
            if (!file_exists($modulePath)) {
                throw new \RuntimeException("Module service not found: {$module}");
            }

            // Load module's bootstrap.php first if it exists (loads dependencies like traits)
            $moduleDir = dirname($modulePath);
            $bootstrapFile = $moduleDir . '/bootstrap.php';
            if (file_exists($bootstrapFile)) {
                require_once $bootstrapFile;
            }

            require_once $modulePath;
            
            // Try to read class name from mod_class.txt if it exists (supports namespaced classes)
            $moduleDir = dirname($modulePath);
            $modClassFile = $moduleDir . '/mod_class.txt';
            $className = null;
            
            if (file_exists($modClassFile)) {
                $modClassContent = trim(file_get_contents($modClassFile));
                // Only use if it looks like a class name (contains backslash for namespace or ends with Service)
                if (!empty($modClassContent) && (str_contains($modClassContent, '\\') || str_ends_with($modClassContent, 'Service'))) {
                    $className = $modClassContent;
                }
            }
            
            // Fallback to constructed class name
            if ($className === null) {
                $className = str_replace('_', '', ucwords($module, '_')) . 'Service';
            }
            
            if (!class_exists($className)) {
                throw new \RuntimeException("Service class not found: {$className}");
            }

            $service = new $className();
            if (!method_exists($service, $handler)) {
                throw new \RuntimeException("Handler not found: {$className}::{$handler}");
            }

            $service->$handler();
            
            $result['success'] = true;
            $result['message'] = 'Task completed successfully';
            
            Logger::cron("Cron task executed: {$taskId}");

        } catch (\Throwable $e) {
            $result['message'] = $e->getMessage();
            Logger::cron("Cron task failed: {$taskId}", [
                'error' => $e->getMessage(),
            ]);
        }

        $result['duration'] = round(microtime(true) - $startTime, 4);

        $this->tasks[$taskId]['last_run'] = time();
        $this->tasks[$taskId]['next_run'] = time() + $task['interval'];
        $this->save();

        $this->logExecution($taskId, $result);

        return $result;
    }

    private function logExecution(string $taskId, array $result): void
    {
        $storage = StorageManager::instance();
        $log = $storage->get(self::LOG_KEY) ?? [];
        
        array_unshift($log, [
            'task_id' => $taskId,
            'timestamp' => date('Y-m-d H:i:s'),
            'success' => $result['success'],
            'message' => $result['message'],
            'duration' => $result['duration'],
        ]);

        $log = array_slice($log, 0, 100);
        
        $storage->set(self::LOG_KEY, $log);
    }

    public function getLog(int $limit = 50): array
    {
        $storage = StorageManager::instance();
        $log = $storage->get(self::LOG_KEY) ?? [];
        return array_slice($log, 0, $limit);
    }
}

/* RULES
- Purpose: Cron task scheduling and execution
- Config sources: Tasks registered by modules
- Paths: Uses System::path() for module discovery
- Logs: Writes to Logger::CHANNEL_CRON
- Prohibitions:
  - NO direct file operations outside System::path()
  - NO hardcoded paths
*/
