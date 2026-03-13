<?php

declare(strict_types=1);

namespace Core\System;

/**
 * Core System class - единая точка входа для всей инфраструктуры
 * 
 * Provides:
 * - System paths (file system): System::path('root'), System::path('modules'), etc.
 * - Web paths (URLs): System::web('admin'), System::baseUrl(), etc.
 * - Environment: System::env()
 * - Configuration: System::config()
 */
final class System
{
    private static bool $initialized = false;
    
    /** @var string Base URL for web paths (auto-detected or configured) */
    private static string $baseUrl = '';
    
    /** @var string Admin path prefix */
    private static string $adminPath = '/admin';

    private function __construct()
    {
    }

    /**
     * Initialize the system. Can be called without arguments.
     * Safe to call multiple times - will skip if already initialized.
     */
    public static function init(array $config = []): void
    {
        if (self::$initialized) {
            return; // Safe re-entry - don't throw, just skip
        }

        $root = $config['root'] ?? (defined('ROOT') ? ROOT : dirname(__DIR__, 2));
        $env = $config['env'] ?? 'dev';

        // Initialize SystemPaths with base paths
        SystemPaths::instance()->init($root);
        
        // Bootstrap: scan manifest.json files and register module paths
        SystemPathsBootstrap::scan(SystemPaths::instance());
        
        // Mark bootstrap as complete (allowAdditionalProperties is true by default)
        SystemPaths::instance()->finishBootstrap();
        
        SystemEnv::instance()->init($env);
        SystemConfig::instance()->init($config);
        SystemRuntime::instance();

        self::ensureRuntimeDirectories();
        
        // Initialize web paths
        self::$baseUrl = $config['base_url'] ?? self::detectBaseUrl();
        self::$adminPath = $config['admin_path'] ?? '/admin';

        // Initialize version from config
        $systemConfig = SystemConfig::instance()->load('system');
        $coreVersion = $systemConfig['core_version'] ?? '1.0.0';
        SystemVersion::instance()->init($coreVersion);

        // Set config cache file
        $cacheFile = SystemPaths::instance()->get('cache') . '/config.php';
        SystemConfig::instance()->setCacheFile($cacheFile);

        self::$initialized = true;
    }

    public static function isInitialized(): bool
    {
        return self::$initialized;
    }

    public static function env(): string
    {
        self::checkInitialized();
        return SystemEnv::instance()->get();
    }

    public static function path(string $key): string
    {
        self::checkInitialized();
        return SystemPaths::instance()->get($key);
    }

    public static function runtime(): SystemRuntime
    {
        self::checkInitialized();
        return SystemRuntime::instance();
    }

    public static function config(): SystemConfig
    {
        self::checkInitialized();
        return SystemConfig::instance();
    }

    public static function paths(): SystemPaths
    {
        self::checkInitialized();
        return SystemPaths::instance();
    }

    // ============================================================
    // LOGGING - System-wide logging service
    // ============================================================

    /**
     * Log a message to a specific channel
     * 
     * Channels:
     *   - system: General system messages -> runtime/logs/system.log
     *   - gateway: API gateway requests -> runtime/logs/gateway.log
     *   - cron: Scheduled tasks -> runtime/logs/cron.log
     *   - module: Module operations -> runtime/logs/module.log
     *   - security: Security events -> runtime/logs/security.log
     * 
     * @param string $channel Log channel name
     * @param string $message Log message
     * @param array $context Additional context data
     */
    public static function log(string $channel, string $message, array $context = []): void
    {
        self::checkInitialized();
        \Core\Logger\Logger::write($channel, $message, $context);
    }

    // ============================================================
    // STATE - System state layer
    // ============================================================

    /**
     * Get the system state instance
     * 
     * Usage:
     *   System::state()->isInstalled(): bool
     *   System::state()->markInstalled(array $meta)
     *   System::state()->getInstallMeta(): array
     * 
     * @return SystemState
     */
    public static function state(): SystemState
    {
        self::checkInitialized();
        return SystemState::instance();
    }

    // ============================================================
    // AUTH - Authentication layer
    // ============================================================

    /**
     * Get the auth manager instance
     * 
     * Usage:
     *   System::auth()->login($username, $password): bool
     *   System::auth()->logout()
     *   System::auth()->check(): bool
     *   System::auth()->user(): array
     *   System::auth()->isAdmin(): bool
     * 
     * @return \Core\Auth\AuthManager
     */
    public static function auth(): \Core\Auth\AuthManager
    {
        self::checkInitialized();
        return \Core\Auth\AuthManager::instance();
    }

    // ============================================================
    // MODULES - Module manager accessor
    // ============================================================

    /**
     * Get the module manager instance
     * 
     * Usage:
     *   System::modules()->run('example');
     *   System::modules()->get('example');
     *   System::modules()->all();
     * 
     * @return \Core\Module\ModuleManager
     */
    public static function modules(): \Core\Module\ModuleManager
    {
        self::checkInitialized();
        return \Core\Module\ModuleManager::instance();
    }

    // ============================================================
    // VIEW - Template engine
    // ============================================================

    /**
     * Render a view template
     * 
     * Usage:
     *   System::view('admin/dashboard', ['title' => 'Dashboard']);
     *   System::view('installer/wizard', $data, 'admin');
     * 
     * @param string $view View path
     * @param array $data View data
     * @param string|null $layout Layout name
     * @return string Rendered HTML
     */
    public static function view(string $view, array $data = [], ?string $layout = null): string
    {
        self::checkInitialized();
        return \Core\View\ViewEngine::render($view, $data, $layout);
    }

    // ============================================================
    // WEB PATHS - генерация URL для шаблонов и редиректов
    // ============================================================

    /**
     * Get base URL (e.g., "" for root, "/myapp" for subfolder)
     * 
     * @return string Base URL without trailing slash
     */
    public static function baseUrl(): string
    {
        self::checkInitialized();
        return self::$baseUrl;
    }

    /**
     * Generate web path (URL) for a given route
     * 
     * Examples:
     *   System::web('admin')              -> /admin (or /myapp/admin if in subfolder)
     *   System::web('admin/modules')      -> /admin/modules
     *   System::web('admin/assets/css/style.css') -> /admin/assets/css/style.css
     *   System::web('api/bybit')          -> /api/bybit
     * 
     * @param string $route Route path (without leading slash)
     * @return string Full URL path
     */
    public static function web(string $route = ''): string
    {
        self::checkInitialized();
        
        $route = ltrim($route, '/');
        
        if (empty($route)) {
            return self::$baseUrl ?: '/';
        }
        
        return self::$baseUrl . '/' . $route;
    }

    /**
     * Generate admin panel URL
     * 
     * Examples:
     *   System::adminUrl()           -> /admin
     *   System::adminUrl('modules')  -> /admin/modules
     *   System::adminUrl('cron')     -> /admin/cron
     * 
     * @param string $route Route within admin (without leading slash)
     * @return string Full admin URL path
     */
    public static function adminUrl(string $route = ''): string
    {
        self::checkInitialized();
        
        $adminBase = self::$baseUrl . self::$adminPath;
        
        if (empty($route)) {
            return $adminBase;
        }
        
        return $adminBase . '/' . ltrim($route, '/');
    }

    /**
     * Generate admin asset URL (CSS, JS, images)
     * 
     * Examples:
     *   System::adminAsset('css/style.css') -> /admin/assets/css/style.css
     *   System::adminAsset('js/app.js')     -> /admin/assets/js/app.js
     * 
     * @param string $path Asset path relative to admin/assets/
     * @return string Full asset URL
     */
    public static function adminAsset(string $path): string
    {
        self::checkInitialized();
        return self::$baseUrl . self::$adminPath . '/assets/' . ltrim($path, '/');
    }

    /**
     * Set base URL manually (useful for CLI or custom setups)
     * 
     * @param string $baseUrl Base URL (e.g., "/myapp" or "")
     */
    public static function setBaseUrl(string $baseUrl): void
    {
        self::$baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Auto-detect base URL from request
     * 
     * @return string Detected base URL or empty string
     */
    private static function detectBaseUrl(): string
    {
        // CLI mode - no base URL needed
        if (PHP_SAPI === 'cli') {
            return '';
        }

        // Try to detect from SCRIPT_NAME
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        if (!empty($scriptName)) {
            $dir = dirname($scriptName);
            if ($dir !== '/' && $dir !== '\\' && $dir !== '.') {
                return rtrim($dir, '/');
            }
        }

        return '';
    }
    
    /**
     * Get routing diagnostics for doctor command
     * 
     * @return array Routing configuration details
     */
    public static function getRoutingDiagnostics(): array
    {
        $baseUrl = self::$baseUrl;
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        
        // Use SystemRuntime for docroot detection
        $runtime = SystemRuntime::instance();
        $docrootMode = $runtime->getDocrootMode();
        
        // Format docroot mode for display
        $docrootModeDisplay = match($docrootMode) {
            'public' => 'proper (docroot = /public)',
            'root' => 'legacy (docroot = /)',
            'cli' => 'CLI mode',
            default => 'unknown',
        };
        
        // Effective URLs
        $effectiveInstallUrl = self::web('install');
        $effectiveAdminUrl = self::web('admin/login');
        
        return [
            'base_url' => $baseUrl ?: '(empty)',
            'docroot_mode' => $docrootModeDisplay,
            'docroot_mode_raw' => $docrootMode,
            'is_proper_docroot' => $runtime->isProperDocroot(),
            'is_legacy_docroot' => $runtime->isLegacyDocroot(),
            'effective_install_url' => $effectiveInstallUrl,
            'effective_admin_url' => $effectiveAdminUrl,
            'script_name' => $scriptName,
            'request_uri' => $requestUri,
            'admin_path' => self::$adminPath,
        ];
    }

    // ============================================================
    // VERSION - Core version management
    // ============================================================

    /**
     * Get current core version
     * 
     * @return string Version string (e.g., '1.1.0')
     */
    public static function version(): string
    {
        self::checkInitialized();
        return SystemVersion::instance()->get();
    }

    /**
     * Check if current version is compatible with requirement
     * 
     * Supports:
     * - Exact: '1.0.0'
     * - Range: '>=1.0.0', '>1.0.0', '<=1.0.0', '<1.0.0'
     * - Caret: '^1.0' (>=1.0.0 <2.0.0)
     * - Tilde: '~1.0' (>=1.0.0 <1.1.0)
     * 
     * @param string $requirement Version requirement
     * @return bool
     */
    public static function isCompatible(string $requirement): bool
    {
        self::checkInitialized();
        return SystemVersion::instance()->isCompatible($requirement);
    }

    /**
     * Get full version info
     * 
     * @return array Version metadata
     */
    public static function versionInfo(): array
    {
        self::checkInitialized();
        return SystemVersion::instance()->info();
    }

    // ============================================================
    // ERROR HANDLER - Global error handling
    // ============================================================

    /**
     * Get error handler instance
     * 
     * Usage:
     *   System::error()->handle($exception);
     *   System::error()->render();
     *   System::error()->log($exception);
     * 
     * @return SystemError
     */
    public static function error(): string
    {
        return SystemError::class;
    }

    // ============================================================
    // EVENTS - Event system
    // ============================================================

    /**
     * Get events instance
     * 
     * Usage:
     *   System::events()->on('user.login', callable);
     *   System::events()->emit('module.loaded', $payload);
     *   System::events()->off('user.login');
     * 
     * @return SystemEvents
     */
    public static function events(): string
    {
        return SystemEvents::class;
    }

    // ============================================================
    // MIGRATIONS - Database/storage migrations
    // ============================================================

    /**
     * Get migration system instance
     * 
     * Usage:
     *   System::migration()->run();
     *   System::migration()->status();
     *   System::migration()->pending();
     * 
     * @return SystemMigration
     */
    public static function migration(): string
    {
        self::checkInitialized();
        return SystemMigration::class;
    }

    // ============================================================
    // URL - Canonical URL service
    // ============================================================

    /**
     * Get URL service instance
     * 
     * Usage:
     *   System::url()->to('/admin/login');
     *   System::url()->admin('/users');
     *   System::url()->api('/v1/users');
     *   System::url()->asset('css/app.css');
     * 
     * @return Url
     */
    public static function url(): string
    {
        self::checkInitialized();
        return Url::class;
    }

    // ============================================================
    // INTERNAL HELPERS
    // ============================================================

    private static function checkInitialized(): void
    {
        if (!self::$initialized) {
            throw new \RuntimeException('System not initialized. Call System::init() first.');
        }
    }

    private static function ensureRuntimeDirectories(): void
    {
        $paths = SystemPaths::instance();
        $dirs = ['runtime', 'logs', 'cache', 'sessions'];

        foreach ($dirs as $dir) {
            $path = $paths->get($dir);
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }
    }

    public static function reset(): void
    {
        self::$initialized = false;
        self::$baseUrl = '';
        self::$adminPath = '/admin';
    }
}

/* RULES
- Purpose: Core System class - единая точка входа для всей инфраструктуры
- Config sources: config/system.php (via SystemConfig)
- Paths: Provides System::path() and System::web() for all path/URL generation
- Logs: No direct logging
- Prohibitions:
  - NO ../ usage
  - NO hardcoded paths (use System::path() only)
  - NO DOCUMENT_ROOT usage
  - Init must be called ONLY from entrypoints (index.php, public/index.php, scripts/*)
*/
