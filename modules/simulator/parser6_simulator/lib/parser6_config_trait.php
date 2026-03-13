<?php

declare(strict_types=1);

use Core\System\SystemPaths;

/**
 * Parser6ConfigTrait - Configuration, storage setup, and utility methods
 * 
 * Methods for loading configs, resolving paths, and ensuring directories.
 */
trait Parser6ConfigTrait
{
    /**
     * Resolve module base path using SystemPaths
     */
    private function resolveModuleBase(SystemPaths $paths): ?string
    {
        foreach ($this->getModuleBasePathKeys() as $key) {
            if (!$paths->has($key)) {
                continue;
            }
            $path = $paths->get($key);
            if ($path !== null && is_dir($path)) {
                return $path;
            }
        }
        return null;
    }

    /**
     * Get possible SystemPaths keys for module base
     */
    private function getModuleBasePathKeys(): array
    {
        return ['simulator.parser6_simulator', 'parser.parser6_simulator', 'parser6_simulator', 'module.parser6_simulator', 'modules.parser6_simulator'];
    }

    /**
     * Load config from JSON file
     */
    private function loadConfig(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }
        $json = file_get_contents($path);
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Load config with user overrides (config.php + config_user.json)
     */
    private function loadConfigWithUserOverrides(): array
    {
        $configPath = $this->moduleBase . '/config/config.php';
        $userConfigPath = $this->moduleBase . '/config/config_user.json';

        $baseConfig = [];
        if (file_exists($configPath)) {
            $baseConfig = include $configPath;
            if (!is_array($baseConfig)) {
                $baseConfig = [];
            }
        }

        $userOverrides = $this->loadConfig($userConfigPath);
        return $this->deepMerge($baseConfig, $userOverrides);
    }

    /**
     * Deep merge two arrays (override values recursively)
     */
    private function deepMerge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->deepMerge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    /**
     * Ensure root storage directories exist
     */
    private function ensureStorageDirectories(): void
    {
        $dirs = [
            $this->storageDir,
            $this->storageDir . '/trades',
            $this->storageDir . '/trades/active',
            $this->storageDir . '/trades/closed',
            $this->storageDir . '/trades/rejected',
            $this->storageDir . '/dataset',
            $this->storageDir . '/stats',
            $this->storageDir . '/logs',
        ];
        foreach ($dirs as $dir) {
            $this->ensureDir($dir);
        }
    }

    /**
     * Ensure mode-specific storage directories exist
     */
    private function ensureModeStorageDirectories(string $modeStorageBase): void
    {
        $dirs = [
            $modeStorageBase,
            $modeStorageBase . '/trades',
            $modeStorageBase . '/trades/active',
            $modeStorageBase . '/trades/closed',
            $modeStorageBase . '/trades/rejected',
            $modeStorageBase . '/dataset',
            $modeStorageBase . '/stats',
            $modeStorageBase . '/logs',
        ];
        foreach ($dirs as $dir) {
            $this->ensureDir($dir);
        }
    }

    /**
     * Get storage base directory for mode
     */
    private function getModeStorageBase(string $mode): string
    {
        // RAW mode: Brain-based storage (unified key from config)
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
            } catch (\Exception $e) {
                // Fallback to local storage if key not found
            }
        }
        
        // CLEAN mode or fallback: local storage
        return $this->storageDir . '/' . $mode;
    }

    /**
     * Ensure directory exists (create if missing)
     */
    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    /**
     * Write JSON file atomically (to temp then rename)
     */
    private function writeJsonAtomic(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tempPath = $path . '.tmp.' . getmypid();
        file_put_contents($tempPath, $json);
        rename($tempPath, $path);
    }

    /**
     * Write errors to log file
     */
    private function writeErrors(): void
    {
        if (!empty($this->errors)) {
            $path = $this->logsDir . '/errors.log';
            file_put_contents($path, json_encode($this->errors, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);
        }
    }

    /**
     * Emit event (placeholder for event system)
     */
    private function emitEvent(string $type, array $data): void
    {
        // Event emission placeholder - can be extended for webhooks/notifications
        $event = [
            'type' => $type,
            'ts' => date('c'),
            'data' => $data,
        ];
        $path = $this->logsDir . '/events.log';
        file_put_contents($path, json_encode($event) . "\n", FILE_APPEND);
    }
}

/* RULES
 * NO HARDCODE. CONFIG FIRST. SystemPaths ONLY.
 * Methods in this trait handle configuration loading and storage setup.
 * All paths must come from SystemPaths or config - never hardcoded.
 */
