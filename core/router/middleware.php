<?php
/**
 * Tredercopis Core - Router Middleware
 * 
 * Provides middleware support for routes:
 * - auth: Requires authenticated user
 * - admin: Requires admin role
 * - csrf: Validates CSRF token
 * - guest: Only for non-authenticated users
 */

declare(strict_types=1);

namespace Core\Router;

use Core\System\System;

final class Middleware
{
    /** @var array<string, callable> Registered middleware handlers */
    private static array $handlers = [];
    
    /** @var bool Whether built-in middleware is registered */
    private static bool $initialized = false;

    /**
     * Initialize built-in middleware
     */
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        // Auth middleware - requires authenticated user
        self::register('auth', function (callable $next) {
            if (!System::auth()->check()) {
                Router::redirect(System::web('admin/login'));
                return null;
            }
            return $next();
        });

        // Admin middleware - requires admin role
        self::register('admin', function (callable $next) {
            if (!System::auth()->check()) {
                Router::redirect(System::web('admin/login'));
                return null;
            }
            if (!System::auth()->isAdmin()) {
                http_response_code(403);
                echo 'Access denied. Admin privileges required.';
                return null;
            }
            return $next();
        });

        // Guest middleware - only for non-authenticated users
        self::register('guest', function (callable $next) {
            if (System::auth()->check()) {
                Router::redirect(System::web('admin'));
                return null;
            }
            return $next();
        });

        // CSRF middleware - validates token on POST requests
        self::register('csrf', function (callable $next) {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
                if (!System::auth()->validateCsrfToken($token)) {
                    http_response_code(403);
                    echo 'CSRF token validation failed.';
                    return null;
                }
            }
            return $next();
        });

        self::$initialized = true;
    }

    /**
     * Register a middleware handler
     *
     * @param string $name Middleware name
     * @param callable $handler Handler function: function(callable $next): mixed
     */
    public static function register(string $name, callable $handler): void
    {
        self::$handlers[$name] = $handler;
    }

    /**
     * Check if middleware is registered
     *
     * @param string $name Middleware name
     * @return bool
     */
    public static function has(string $name): bool
    {
        return isset(self::$handlers[$name]);
    }

    /**
     * Get a middleware handler
     *
     * @param string $name Middleware name
     * @return callable|null
     */
    public static function get(string $name): ?callable
    {
        return self::$handlers[$name] ?? null;
    }

    /**
     * Run middleware chain
     *
     * @param array $middlewareNames List of middleware names
     * @param callable $finalHandler The final request handler
     * @return mixed
     */
    public static function run(array $middlewareNames, callable $finalHandler): mixed
    {
        self::init();

        // Build middleware chain from inside out
        $chain = $finalHandler;
        
        foreach (array_reverse($middlewareNames) as $name) {
            $middleware = self::get($name);
            if ($middleware === null) {
                System::log('system', "Unknown middleware: {$name}");
                continue;
            }
            
            $next = $chain;
            $chain = fn() => $middleware($next);
        }

        return $chain();
    }

    /**
     * Get all registered middleware names
     *
     * @return array<string>
     */
    public static function all(): array
    {
        return array_keys(self::$handlers);
    }
}

/* RULES
- Purpose: Middleware support for Router (auth, admin, csrf, guest)
- Config sources: None
- Paths: Uses System::web() for redirects
- Logs: Logs unknown middleware via System::log()
- Prohibitions:
  - NO direct header() calls outside redirect
  - NO hardcoded paths
*/
