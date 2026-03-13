<?php

declare(strict_types=1);

namespace Core\System;

/**
 * SystemPaths - ЕДИНСТВЕННЫЙ реестр путей в системе Tredercopis
 * 
 * АРХИТЕКТУРНЫЙ ЗАКОН:
 * Модули НЕ знают структуру файловой системы.
 * Модули НЕ вычисляют пути.
 * Модули получают пути ТОЛЬКО через: SystemPaths::instance()->get()
 * 
 * PackMap format: {category}.{module}.{path_key}
 * Example keys:
 *   - parser.parser1_market_registry.storage
 *   - parser.delist_parser0.config
 *   - signal.executor.storage
 * 
 * NO FILESYSTEM FALLBACKS - paths must be registered via manifest.json
 */
final class SystemPaths
{
    private static ?self $instance = null;
    private array $paths = [];
    private string $root = '';
    
    /** @var bool Whether additional path registrations are allowed after bootstrap */
    private bool $allowAdditionalProperties = true;
    
    /** @var bool Whether bootstrap has been completed */
    private bool $bootstrapped = false;

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

    public function init(string $root): void
    {
        $this->root = $root;
        $this->paths = [
            // Core system paths - these are always available
            'root' => $root,
            'core' => $root . '/core',
            'config' => $root . '/config',
            'modules' => $root . '/modules',
            'storage' => $root . '/storage',
            'runtime' => $root . '/runtime',
            'admin' => $root . '/admin',
            'logs' => $root . '/runtime/logs',
            'cache' => $root . '/runtime/cache',
            'sessions' => $root . '/runtime/sessions',
            'public' => $root . '/public',
            
            // Module category base paths
            'modules.parser' => $root . '/modules/parser',
            'modules.signal' => $root . '/modules/signal',
            'modules.trading' => $root . '/modules/trading',
            'modules.system' => $root . '/modules/system',
            'modules.simulator' => $root . '/modules/simulator',
        ];
        
        // Note: Module-specific paths are registered via SystemPathsBootstrap::scan()
        // which reads manifest.json files
    }
    
    /**
     * Mark bootstrap as complete
     * After this, no more path registrations are allowed (if allowAdditionalProperties is false)
     */
    public function finishBootstrap(): void
    {
        $this->bootstrapped = true;
    }
    
    /**
     * Set whether additional path registrations are allowed
     */
    public function setAllowAdditionalProperties(bool $allow): void
    {
        $this->allowAdditionalProperties = $allow;
    }

    /**
     * Get a registered path by key
     * 
     * Supported key formats:
     * - 'root', 'core', 'modules', etc. - base system paths
     * - 'parser.{name}' - parser module directory
     * - 'parser.{name}.config' - parser config.php
     * - 'parser.{name}.schema' - parser schema.php
     * - 'parser.{name}.storage' - parser storage directory
     * - 'parser.{name}.logs' - parser logs directory
     * 
     * @throws \RuntimeException if path key is not registered
     */
    public function get(string $key): string
    {
        // Self-healing guard: some legacy entrypoints define ROOT but forget System::init().
        // In that case, SystemPaths may be used before being initialized/bootstrapped.
        // We try a safe, minimal auto-init + auto-bootstrap using ROOT.
        if (!isset($this->paths[$key])) {
            $this->attemptAutoInitAndBootstrap();
        }

        if (!isset($this->paths[$key])) {
            throw new \RuntimeException(
                "SystemPaths: Unknown path key '{$key}'. " .
                "All paths must be registered via module manifests (manifest.json / manifest.php). " .
                "Use SystemPaths::instance()->dump() to see available keys."
            );
        }
        return $this->paths[$key];
    }

    /**
     * Attempt to initialize and bootstrap SystemPaths if ROOT is defined.
     * This is a non-invasive fallback to prevent fatal errors in legacy entrypoints.
     */
    private function attemptAutoInitAndBootstrap(): void
    {
        // Auto-init (only if root is not set)
        if ($this->root === '' && defined('ROOT')) {
            $root = (string) constant('ROOT');
            if ($root !== '' && is_dir($root) && is_file($root . '/core/bootstrap.php')) {
                $this->init($root);
            }
        }

        // Auto-bootstrap scan (only if initialized and not bootstrapped)
        if ($this->root !== '' && !$this->bootstrapped) {
            // Some legacy entrypoints only require systempaths.php and forget to require
            // systempaths_bootstrap.php. We can safely self-load it if available.
            if (!class_exists(SystemPathsBootstrap::class)) {
                $bootstrapFile = $this->root . '/core/system/systempaths_bootstrap.php';
                if (is_file($bootstrapFile)) {
                    require_once $bootstrapFile;
                }
            }

            if (class_exists(SystemPathsBootstrap::class)) {
                // Register module paths via manifests
                SystemPathsBootstrap::scan($this);
                $this->finishBootstrap();
            }
        }
    }

    /**
     * Check if a path key exists
     */
    public function has(string $key): bool
    {
        return isset($this->paths[$key]);
    }

    /**
     * Get all registered paths
     */
    public function all(): array
    {
        return $this->paths;
    }
    
    /**
     * Dump all registered paths for debugging
     * 
     * @param bool $sorted Sort keys alphabetically
     * @return array All registered paths
     */
    public function dump(bool $sorted = true): array
    {
        $paths = $this->paths;
        if ($sorted) {
            ksort($paths);
        }
        return $paths;
    }

    /**
     * Register a custom path
     * 
     * @throws \RuntimeException if registration is not allowed
     */
    public function register(string $key, string $path): void
    {
        if ($this->bootstrapped && !$this->allowAdditionalProperties) {
            throw new \RuntimeException(
                "SystemPaths: Cannot register path '{$key}' after bootstrap. " .
                "Call setAllowAdditionalProperties(true) to allow dynamic registration."
            );
        }
        $this->paths[$key] = $path;
    }

    /**
     * Convenience method for parser paths
     * 
     * Usage:
     *   $paths->parserPath('parser1_market_registry', 'storage')
     *   Returns: /path/to/modules/parser/parser1_market_registry/storage
     * 
     * @param string $parserName Parser module name (e.g., 'parser1_market_registry')
     * @param string|null $subpath Optional subpath ('config', 'schema', 'storage', 'logs')
     * @return string Full path
     */
    public function parserPath(string $parserName, ?string $subpath = null): string
    {
        $key = 'parser.' . $parserName;
        if ($subpath !== null) {
            $key .= '.' . $subpath;
        }
        return $this->get($key);
    }

    /**
     * Get list of all registered module names by category
     * 
     * @param string $category Module category (e.g., 'parser', 'signal', 'trading')
     * @return array List of module names
     */
    public function listModules(string $category): array
    {
        $modules = [];
        foreach ($this->paths as $key => $path) {
            if (preg_match('/^' . preg_quote($category, '/') . '\.([^.]+)$/', $key, $matches)) {
                $modules[] = $matches[1];
            }
        }
        return $modules;
    }
    
    /**
     * Get list of all registered parser names
     * Convenience wrapper for listModules('parser')
     */
    public function listParsers(): array
    {
        return $this->listModules('parser');
    }
    
    /**
     * Get statistics about registered paths
     */
    public function stats(): array
    {
        $categories = [];
        foreach ($this->paths as $key => $path) {
            $parts = explode('.', $key);
            $cat = $parts[0];
            if (!isset($categories[$cat])) {
                $categories[$cat] = 0;
            }
            $categories[$cat]++;
        }
        
        return [
            'total_paths' => count($this->paths),
            'categories' => $categories,
            'bootstrapped' => $this->bootstrapped,
            'allow_additional' => $this->allowAdditionalProperties,
        ];
    }
}

/* RULES
- Purpose: Centralized file system paths management
- Config sources: manifest.json files (via SystemPathsBootstrap)
- Paths: This is THE path center - all paths go through here
- Logs: None
- Prohibitions:
  - NO ../ in path definitions
  - NO hardcoded absolute paths
  - NO ad-hoc path guessing (module paths must come from manifests)
  - All module paths MUST be registered via manifests (manifest.json / manifest.php)
*/
