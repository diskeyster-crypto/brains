<?php

declare(strict_types=1);

namespace Core\Module;

use Core\System\System;
use Core\Storage\StorageManager;
use Core\Logger\Logger;

final class ModuleManager
{
    private const STORAGE_KEY = 'enabled_modules';
    
    /** @var array Supported module categories */
    private const CATEGORIES = ['parser', 'signal', 'trading', 'system'];
    
    private static ?self $instance = null;
    private array $modules = [];
    private array $active = [];
    private array $byCategory = [];
    private bool $discovered = false;

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
     * Get list of supported categories
     */
    public function getCategories(): array
    {
        return self::CATEGORIES;
    }

    public function discover(): array
    {
        if ($this->discovered) {
            return $this->modules;
        }

        $modulesPath = System::path('modules');
        if (!is_dir($modulesPath)) {
            mkdir($modulesPath, 0755, true);
            return [];
        }

        // Initialize category registry
        foreach (self::CATEGORIES as $cat) {
            $this->byCategory[$cat] = [];
        }

        // Scan both:
        // 1. modules/{category}/{module}/ (new structure)
        // 2. modules/{module}/ (legacy flat structure)
        
        $dirs = scandir($modulesPath);
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }

            $itemPath = $modulesPath . '/' . $dir;
            
            // Skip non-directories
            if (!is_dir($itemPath)) {
                continue;
            }
            
            // Check if this is a category folder
            if (in_array($dir, self::CATEGORIES, true)) {
                // Scan category subdirectories
                $this->discoverCategory($dir, $itemPath);
                continue;
            }
            
            // Legacy: Check if this is a direct module (flat structure)
            $this->discoverModule($dir, $itemPath, null);
        }

        $this->discovered = true;
        $this->loadActiveModules();

        return $this->modules;
    }
    
    /**
     * Discover modules within a category folder
     */
    private function discoverCategory(string $category, string $categoryPath): void
    {
        $dirs = scandir($categoryPath);
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }
            
            $modulePath = $categoryPath . '/' . $dir;
            if (!is_dir($modulePath)) {
                continue;
            }
            
            $this->discoverModule($dir, $modulePath, $category);
        }
    }
    
    /**
     * Discover a single module
     */
    private function discoverModule(string $dir, string $modulePath, ?string $category): void
    {
        // Try manifest.php first (preferred)
        $manifestPhpPath = $modulePath . '/manifest.php';
        $manifestJsonPath = $modulePath . '/manifest.json';
        
        $manifest = null;
        
        if (file_exists($manifestPhpPath)) {
            $manifest = require $manifestPhpPath;
        } elseif (file_exists($manifestJsonPath)) {
            $manifest = json_decode(file_get_contents($manifestJsonPath), true);
        }
        
        if (!$manifest || !is_array($manifest)) {
            // Skip modules without valid manifest
            return;
        }
        
        // Category from manifest takes precedence, then folder structure, then 'other'
        $moduleCategory = $manifest['category'] ?? $category ?? 'other';
        
        $moduleInfo = [
            'name' => $manifest['name'] ?? $dir,
            'version' => $manifest['version'] ?? '1.0.0',
            'description' => $manifest['description'] ?? '',
            'author' => $manifest['author'] ?? '',
            'entry' => $manifest['entry'] ?? 'runner.php',
            'requires' => $manifest['requires'] ?? [],
            'path' => $modulePath,
            'category' => $moduleCategory,
            'manifest' => $manifest,
        ];

        $this->modules[$dir] = $moduleInfo;
        
        // Register in category index
        if (!isset($this->byCategory[$moduleCategory])) {
            $this->byCategory[$moduleCategory] = [];
        }
        $this->byCategory[$moduleCategory][$dir] = $moduleInfo;
    }

    /**
     * Run a module by name
     * 
     * @param string $module Module name
     * @return mixed Module execution result
     * @throws \RuntimeException If module not found or entry missing
     */
    public function run(string $module): mixed
    {
        if (!$this->discovered) {
            $this->discover();
        }
        
        $moduleInfo = $this->modules[$module] ?? null;
        if ($moduleInfo === null) {
            throw new \RuntimeException("Module not found: {$module}");
        }
        
        $entryFile = $moduleInfo['path'] . '/' . $moduleInfo['entry'];
        if (!file_exists($entryFile)) {
            throw new \RuntimeException("Module entry file not found: {$entryFile}");
        }
        
        // Check requirements
        foreach ($moduleInfo['requires'] as $required) {
            if ($required === 'bybit') {
                // Special case: bybit is a gateway, not a module
                continue;
            }
            if (!isset($this->modules[$required])) {
                throw new \RuntimeException("Module {$module} requires {$required} which is not available");
            }
        }
        
        System::log('module', "Running module: {$module}", ['entry' => $moduleInfo['entry']]);
        
        return require $entryFile;
    }

    /**
     * Get all discovered modules
     * 
     * @return array All modules
     */
    public function all(): array
    {
        return $this->getAll();
    }
    
    /**
     * Get modules by category
     * 
     * @param string $category Category name (parser, signal, trading, system)
     * @return array Modules in the specified category
     */
    public function getByCategory(string $category): array
    {
        if (!$this->discovered) {
            $this->discover();
        }
        return $this->byCategory[$category] ?? [];
    }
    
    /**
     * Get all modules grouped by category
     * 
     * @return array ['parser' => [...], 'signal' => [...], ...]
     */
    public function getAllByCategory(): array
    {
        if (!$this->discovered) {
            $this->discover();
        }
        return $this->byCategory;
    }

    private function loadActiveModules(): void
    {
        $storage = StorageManager::instance();
        $enabledModules = $storage->get(self::STORAGE_KEY) ?? [];

        foreach ($enabledModules as $moduleName) {
            if (isset($this->modules[$moduleName])) {
                $this->active[$moduleName] = $this->modules[$moduleName];
            }
        }
    }

    public function enable(string $module): bool
    {
        if (!$this->discovered) {
            $this->discover();
        }

        if (!isset($this->modules[$module])) {
            throw new \InvalidArgumentException("Module not found: {$module}");
        }

        if (isset($this->active[$module])) {
            return true;
        }

        $this->active[$module] = $this->modules[$module];
        $this->saveActiveModules();

        Logger::info("Module enabled: {$module}");
        return true;
    }

    public function disable(string $module): bool
    {
        if (!isset($this->active[$module])) {
            return true;
        }

        unset($this->active[$module]);
        $this->saveActiveModules();

        Logger::info("Module disabled: {$module}");
        return true;
    }

    private function saveActiveModules(): void
    {
        $storage = StorageManager::instance();
        $storage->set(self::STORAGE_KEY, array_keys($this->active));
    }

    public function getActive(): array
    {
        if (!$this->discovered) {
            $this->discover();
        }
        return $this->active;
    }

    public function getAll(): array
    {
        if (!$this->discovered) {
            $this->discover();
        }
        return $this->modules;
    }

    public function isEnabled(string $module): bool
    {
        if (!$this->discovered) {
            $this->discover();
        }
        return isset($this->active[$module]);
    }

    public function get(string $module): ?array
    {
        if (!$this->discovered) {
            $this->discover();
        }
        return $this->modules[$module] ?? null;
    }
}

/* RULES
- Purpose: Module discovery and management
- Config sources: modules/{module}/manifest.json
- Paths: Uses System::path('modules')
- Logs: System events to Logger::CHANNEL_SYSTEM
- Prohibitions:
  - NO hardcoded module paths
  - NO modules requiring each other directly
*/
