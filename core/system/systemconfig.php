<?php

declare(strict_types=1);

namespace Core\System;

final class SystemConfig
{
    private static ?self $instance = null;
    private array $config = [];
    private array $loaded = [];
    private bool $allLoaded = false;
    private ?string $cacheFile = null;

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

    public function init(array $config): void
    {
        $this->config = $config;
    }

    /**
     * Set cache file path
     */
    public function setCacheFile(string $path): void
    {
        $this->cacheFile = $path;
    }

    /**
     * Load all config files from config/ directory
     */
    public function loadAll(): void
    {
        if ($this->allLoaded) {
            return;
        }

        // Try to load from cache first
        if ($this->cacheFile && file_exists($this->cacheFile)) {
            $cached = require $this->cacheFile;
            if (is_array($cached)) {
                $this->config = array_merge($this->config, $cached);
                $this->allLoaded = true;
                return;
            }
        }

        $configDir = SystemPaths::instance()->get('config');
        if (!is_dir($configDir)) {
            return;
        }

        $files = glob($configDir . '/*.php');
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (!isset($this->loaded[$name])) {
                $this->load($name);
            }
        }

        // Merge loaded configs into main config with file name as key
        foreach ($this->loaded as $name => $values) {
            $this->config[$name] = $values;
        }

        // Cache for next request
        if ($this->cacheFile) {
            $dir = dirname($this->cacheFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents(
                $this->cacheFile,
                "<?php\nreturn " . var_export($this->config, true) . ";\n"
            );
        }

        $this->allLoaded = true;
    }

    public function load(string $name): array
    {
        if (isset($this->loaded[$name])) {
            return $this->loaded[$name];
        }

        $configPath = SystemPaths::instance()->get('config') . '/' . $name . '.php';
        
        if (!file_exists($configPath)) {
            throw new \RuntimeException("Config file not found: {$name}.php");
        }

        $this->loaded[$name] = require $configPath;
        return $this->loaded[$name];
    }

    /**
     * Get config value by dot notation key
     * Example: get('bybit.base_url') reads config/bybit.php['base_url']
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        
        // First key might be a config file name
        $firstKey = $keys[0];
        
        // Try to load config file if not already loaded
        if (!isset($this->config[$firstKey]) && count($keys) > 1) {
            try {
                $this->load($firstKey);
                $this->config[$firstKey] = $this->loaded[$firstKey];
            } catch (\RuntimeException $e) {
                // Config file doesn't exist, continue with existing config
            }
        }
        
        $value = $this->config;

        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        $keys = explode('.', $key);
        $config = &$this->config;

        foreach ($keys as $i => $k) {
            if ($i === count($keys) - 1) {
                $config[$k] = $value;
            } else {
                if (!isset($config[$k]) || !is_array($config[$k])) {
                    $config[$k] = [];
                }
                $config = &$config[$k];
            }
        }

        // Invalidate cache when config changes
        if ($this->cacheFile && file_exists($this->cacheFile)) {
            @unlink($this->cacheFile);
        }
    }

    /**
     * Check if config key exists
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function all(): array
    {
        $this->loadAll();
        return $this->config;
    }

    /**
     * Clear config cache
     */
    public function clearCache(): void
    {
        if ($this->cacheFile && file_exists($this->cacheFile)) {
            @unlink($this->cacheFile);
        }
        $this->allLoaded = false;
    }
}

/* RULES
- Purpose: System configuration management
- Config sources: config/system.php, config/storage.php, config/bybit.php
- Paths: Uses System::path('config') for config directory
- Logs: None
- Prohibitions:
  - NO creating new config files without explicit approval
  - NO hardcoded config values
*/
