<?php
/**
 * Router Zones - System-level route grouping
 * 
 * Provides zone-based routing with automatic middleware application.
 */

namespace Core\Router;

class Zones
{
    /**
     * Registered zones
     */
    private static array $zones = [];
    
    /**
     * Default zones
     */
    private static array $defaults = [
        'admin' => [
            'prefix' => '/admin',
            'middleware' => ['auth', 'admin'],
        ],
        'api' => [
            'prefix' => '/api',
            'middleware' => ['auth', 'csrf'],
        ],
        'public' => [
            'prefix' => '/',
            'middleware' => [],
        ],
    ];
    
    /**
     * Initialize default zones
     */
    public static function init(): void
    {
        if (empty(self::$zones)) {
            self::$zones = self::$defaults;
        }
    }
    
    /**
     * Register a zone
     */
    public static function register(string $name, array $config): void
    {
        self::$zones[$name] = array_merge([
            'prefix' => '/',
            'middleware' => [],
        ], $config);
    }
    
    /**
     * Get zone configuration
     */
    public static function get(string $name): ?array
    {
        self::init();
        return self::$zones[$name] ?? null;
    }
    
    /**
     * Get all zones
     */
    public static function all(): array
    {
        self::init();
        return self::$zones;
    }
    
    /**
     * Detect zone for a given path
     */
    public static function detect(string $path): string
    {
        self::init();
        
        // Sort zones by prefix length (longest first)
        $sorted = self::$zones;
        uasort($sorted, function($a, $b) {
            return strlen($b['prefix']) - strlen($a['prefix']);
        });
        
        foreach ($sorted as $name => $config) {
            $prefix = $config['prefix'];
            if ($prefix === '/' || strpos($path, $prefix) === 0) {
                return $name;
            }
        }
        
        return 'public';
    }
    
    /**
     * Get middleware for a zone
     */
    public static function getMiddleware(string $name): array
    {
        $zone = self::get($name);
        return $zone['middleware'] ?? [];
    }
    
    /**
     * Get prefix for a zone
     */
    public static function getPrefix(string $name): string
    {
        $zone = self::get($name);
        return $zone['prefix'] ?? '/';
    }
    
    /**
     * Check if path is in a specific zone
     */
    public static function isInZone(string $path, string $zone): bool
    {
        return self::detect($path) === $zone;
    }
    
    /**
     * Get diagnostics
     */
    public static function getDiagnostics(): array
    {
        self::init();
        
        $result = [];
        foreach (self::$zones as $name => $config) {
            $middleware = empty($config['middleware']) ? 'none' : implode(', ', $config['middleware']);
            $result[$name] = [
                'prefix' => $config['prefix'],
                'middleware' => $middleware,
            ];
        }
        
        return $result;
    }
}

/* RULES
- Purpose: System-level route zone management
- Config sources: Programmatic registration
- Paths: N/A
- Logs: N/A
- Prohibitions: No hardcoded paths, no ../
*/
