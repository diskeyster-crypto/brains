<?php

declare(strict_types=1);

namespace Core\System;

final class SystemRuntime
{
    private static ?self $instance = null;
    private float $startTime;
    private array $data = [];
    private ?string $docrootMode = null;

    private function __construct()
    {
        $this->startTime = microtime(true);
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getStartTime(): float
    {
        return $this->startTime;
    }

    public function getElapsedTime(): float
    {
        return microtime(true) - $this->startTime;
    }

    public function getMemoryUsage(): int
    {
        return memory_get_usage(true);
    }

    public function getPeakMemoryUsage(): int
    {
        return memory_get_peak_usage(true);
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function all(): array
    {
        return $this->data;
    }

    /**
     * Detect and return docroot mode
     * 
     * Returns:
     *   'public' - proper setup, docroot = /public
     *   'root'   - legacy setup, docroot = / (root)
     *   'cli'    - CLI mode, no docroot
     * 
     * Priority:
     *   1. Explicitly configured in config/system.php (docroot_mode != 'auto')
     *   2. Auto-detection based on SCRIPT_NAME
     * 
     * @return string Docroot mode
     */
    public function getDocrootMode(): string
    {
        if ($this->docrootMode !== null) {
            return $this->docrootMode;
        }

        // CLI mode
        if (PHP_SAPI === 'cli') {
            $this->docrootMode = 'cli';
            return $this->docrootMode;
        }

        // Check config first (no guessing if explicitly set)
        $configPath = (defined('ROOT') ? ROOT : dirname(dirname(__DIR__))) . '/config/system.php';
        if (file_exists($configPath)) {
            $config = include $configPath;
            if (is_array($config) && isset($config['docroot_mode']) && $config['docroot_mode'] !== 'auto') {
                $configMode = $config['docroot_mode'];
                if (in_array($configMode, ['public', 'root'], true)) {
                    $this->docrootMode = $configMode;
                    return $this->docrootMode;
                }
            }
        }

        // Fallback to auto-detection (heuristics)
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        
        // Check if SCRIPT_NAME contains /public/
        if (str_contains($scriptName, '/public/')) {
            $this->docrootMode = 'root';
            return $this->docrootMode;
        }
        
        // Check if running from public directory
        if (defined('ROOT') && !empty($documentRoot)) {
            $publicPath = ROOT . '/public';
            $normalizedDocRoot = rtrim(realpath($documentRoot) ?: $documentRoot, '/');
            $normalizedPublic = rtrim(realpath($publicPath) ?: $publicPath, '/');
            
            if ($normalizedDocRoot === $normalizedPublic) {
                $this->docrootMode = 'public';
                return $this->docrootMode;
            }
        }
        
        // Default to 'public' if we can't determine (safer assumption)
        $this->docrootMode = 'public';
        return $this->docrootMode;
    }

    /**
     * Get the source of docroot mode detection
     * 
     * @return string 'config' | 'auto'
     */
    public function getDocrootModeSource(): string
    {
        if (PHP_SAPI === 'cli') {
            return 'cli';
        }
        
        $configPath = (defined('ROOT') ? ROOT : dirname(dirname(__DIR__))) . '/config/system.php';
        if (file_exists($configPath)) {
            $config = include $configPath;
            if (is_array($config) && isset($config['docroot_mode']) && $config['docroot_mode'] !== 'auto') {
                return 'config';
            }
        }
        
        return 'auto';
    }

    /**
     * Set docroot mode manually (for testing or special cases)
     * 
     * @param string $mode 'public' | 'root' | 'cli'
     */
    public function setDocrootMode(string $mode): void
    {
        if (!in_array($mode, ['public', 'root', 'cli'], true)) {
            throw new \InvalidArgumentException("Invalid docroot mode: {$mode}. Must be 'public', 'root', or 'cli'.");
        }
        $this->docrootMode = $mode;
    }

    /**
     * Check if running in proper docroot mode (docroot = /public)
     * 
     * @return bool
     */
    public function isProperDocroot(): bool
    {
        return $this->getDocrootMode() === 'public';
    }

    /**
     * Check if running in legacy docroot mode (docroot = /)
     * 
     * @return bool
     */
    public function isLegacyDocroot(): bool
    {
        return $this->getDocrootMode() === 'root';
    }

    /**
     * Get runtime diagnostics
     * 
     * @return array
     */
    /**
     * Get runtime diagnostics
     * 
     * @return array
     */
    public function getDiagnostics(): array
    {
        return [
            'docroot_mode' => $this->getDocrootMode(),
            'docroot_mode_source' => $this->getDocrootModeSource(),
            'is_proper_docroot' => $this->isProperDocroot(),
            'is_legacy_docroot' => $this->isLegacyDocroot(),
            'script_name' => $_SERVER['SCRIPT_NAME'] ?? '(not set)',
            'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? '(not set)',
            'elapsed_ms' => round($this->getElapsedTime() * 1000, 2),
            'memory_mb' => round($this->getMemoryUsage() / 1024 / 1024, 2),
            'peak_memory_mb' => round($this->getPeakMemoryUsage() / 1024 / 1024, 2),
        ];
    }
}

/* RULES
- Purpose: Runtime data storage (in-memory cache during request) + docroot detection
- Config sources: None
- Paths: None
- Logs: None
- Prohibitions:
  - NO persistent storage (use StorageManager instead)
*/

/* RULES
- Purpose: Runtime data storage (in-memory cache during request)
- Config sources: None
- Paths: None
- Logs: None
- Prohibitions:
  - NO persistent storage (use StorageManager instead)
*/
