<?php
/**
 * System State Layer
 * 
 * Manages system installation state and metadata.
 * Provides API for checking installation status and managing system state.
 * 
 * @package Core\System
 */

declare(strict_types=1);

namespace Core\System;

/**
 * SystemState - System state management
 * 
 * API:
 *   System::state()->isInstalled(): bool
 *   System::state()->markInstalled(array $meta)
 *   System::state()->getInstallMeta(): array
 *   System::state()->get(string $key, mixed $default = null): mixed
 *   System::state()->set(string $key, mixed $value): void
 */
final class SystemState
{
    private static ?self $instance = null;
    private array $state = [];
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

    /**
     * Get storage file path
     */
    private function getStoragePath(): string
    {
        return SystemPaths::instance()->get('storage') . '/system/state.php';
    }

    /**
     * Ensure storage directory exists
     */
    private function ensureDirectory(): void
    {
        $dir = dirname($this->getStoragePath());
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    /**
     * Load state from storage
     */
    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $path = $this->getStoragePath();
        if (file_exists($path)) {
            $this->state = include $path;
            if (!is_array($this->state)) {
                $this->state = [];
            }
        }

        $this->loaded = true;
    }

    /**
     * Save state to storage
     */
    private function save(): void
    {
        $this->ensureDirectory();
        
        $path = $this->getStoragePath();
        $content = "<?php\n/**\n * System state storage (auto-generated)\n * DO NOT EDIT MANUALLY\n */\nreturn " . var_export($this->state, true) . ";\n";
        
        // Atomic write
        $tempPath = $path . '.tmp';
        file_put_contents($tempPath, $content, LOCK_EX);
        rename($tempPath, $path);
    }

    /**
     * Check if system is installed (IRON-CLAD validation)
     * 
     * Rules:
     * 1. State file must exist
     * 2. State must be a valid array
     * 3. 'installed' key must be exactly true
     * 
     * If any condition fails → returns false and logs warning
     * 
     * @return bool True if properly installed
     */
    public function isInstalled(): bool
    {
        $path = $this->getStoragePath();
        
        // 1. File must exist
        if (!file_exists($path)) {
            return false;
        }
        
        // 2. Load and validate structure
        try {
            $state = @include $path;
            
            // File returned garbage or not an array
            if (!is_array($state)) {
                \Core\Logger\Logger::warning('State file exists but returned invalid data (not array)', [
                    'path' => $path,
                    'type' => gettype($state),
                ]);
                return false;
            }
            
            // 3. Must have 'installed' key exactly true
            if (!isset($state['installed'])) {
                \Core\Logger\Logger::warning('State file missing "installed" key', ['path' => $path]);
                return false;
            }
            
            if ($state['installed'] !== true) {
                \Core\Logger\Logger::warning('State file "installed" key is not exactly true', [
                    'path' => $path,
                    'value' => $state['installed'],
                ]);
                return false;
            }
            
            // Cache for future calls
            $this->state = $state;
            $this->loaded = true;
            
            return true;
            
        } catch (\Throwable $e) {
            \Core\Logger\Logger::warning('State file load failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
    
    /**
     * Diagnostic method: get detailed installation state for doctor
     * 
     * @return array Detailed state info
     */
    public function getDiagnostics(): array
    {
        $path = $this->getStoragePath();
        $result = [
            'file_exists' => file_exists($path),
            'file_path' => $path,
            'is_readable' => is_readable($path),
            'is_valid_array' => false,
            'has_installed_key' => false,
            'installed_value' => null,
            'installed_value_type' => null,
            'is_installed' => false,
            'problems' => [],
        ];
        
        if (!$result['file_exists']) {
            $result['problems'][] = 'State file does not exist';
            return $result;
        }
        
        if (!$result['is_readable']) {
            $result['problems'][] = 'State file is not readable';
            return $result;
        }
        
        try {
            $state = @include $path;
            
            if (!is_array($state)) {
                $result['problems'][] = 'State file returned ' . gettype($state) . ' instead of array';
                return $result;
            }
            
            $result['is_valid_array'] = true;
            $result['has_installed_key'] = isset($state['installed']);
            
            if (!$result['has_installed_key']) {
                $result['problems'][] = 'Missing "installed" key in state';
                return $result;
            }
            
            $result['installed_value'] = $state['installed'];
            $result['installed_value_type'] = gettype($state['installed']);
            
            if ($state['installed'] !== true) {
                $result['problems'][] = 'Installed value is not exactly true (bool)';
                return $result;
            }
            
            $result['is_installed'] = true;
            
        } catch (\Throwable $e) {
            $result['problems'][] = 'State file parse error: ' . $e->getMessage();
        }
        
        return $result;
    }

    /**
     * Mark system as installed
     * 
     * @param array $meta Installation metadata
     */
    public function markInstalled(array $meta = []): void
    {
        $this->load();
        
        $this->state['installed'] = true;
        $this->state['installed_at'] = date('c');
        $this->state['core_version'] = $meta['core_version'] ?? '1.0.0';
        $this->state['php_version'] = PHP_VERSION;
        $this->state['admin_user_created'] = $meta['admin_user_created'] ?? false;
        $this->state['installer_version'] = $meta['installer_version'] ?? '1.0.0';
        
        // Merge additional meta
        foreach ($meta as $key => $value) {
            if (!isset($this->state[$key])) {
                $this->state[$key] = $value;
            }
        }
        
        $this->save();
    }

    /**
     * Get installation metadata
     * 
     * @return array Installation metadata
     */
    public function getInstallMeta(): array
    {
        $this->load();
        
        return [
            'installed' => $this->state['installed'] ?? false,
            'installed_at' => $this->state['installed_at'] ?? null,
            'core_version' => $this->state['core_version'] ?? null,
            'php_version' => $this->state['php_version'] ?? null,
            'admin_user_created' => $this->state['admin_user_created'] ?? false,
            'installer_version' => $this->state['installer_version'] ?? null,
        ];
    }

    /**
     * Get a state value
     * 
     * @param string $key State key
     * @param mixed $default Default value if not found
     * @return mixed State value
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->load();
        return $this->state[$key] ?? $default;
    }

    /**
     * Set a state value
     * 
     * @param string $key State key
     * @param mixed $value State value
     */
    public function set(string $key, mixed $value): void
    {
        $this->load();
        $this->state[$key] = $value;
        $this->save();
    }

    /**
     * Get all state data
     * 
     * @return array All state data
     */
    public function all(): array
    {
        $this->load();
        return $this->state;
    }

    /**
     * Reset state (for testing or reinstall)
     */
    public function reset(): void
    {
        $this->state = [];
        $this->loaded = true;
        
        $path = $this->getStoragePath();
        if (file_exists($path)) {
            unlink($path);
        }
    }
}

/* RULES
- Purpose: System state management - installation status, metadata storage
- Config sources: storage/system/state.php (auto-generated)
- Paths: Uses SystemPaths::instance()->get('storage')
- Logs: No direct logging
- Prohibitions:
  - NO hardcoded paths
  - NO manual editing of state.php
  - State file is auto-generated
*/
