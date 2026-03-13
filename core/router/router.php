<?php

declare(strict_types=1);

namespace Core\Router;

use Core\Logger\Logger;

final class Router
{
    private static ?self $instance = null;
    private array $routes = [];
    private array $middleware = [];

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

    public static function get(string $path, callable $handler): void
    {
        self::instance()->addRoute('GET', $path, $handler);
    }

    public static function post(string $path, callable $handler): void
    {
        self::instance()->addRoute('POST', $path, $handler);
    }

    public static function put(string $path, callable $handler): void
    {
        self::instance()->addRoute('PUT', $path, $handler);
    }

    public static function delete(string $path, callable $handler): void
    {
        self::instance()->addRoute('DELETE', $path, $handler);
    }

    public static function any(string $path, callable $handler): void
    {
        $instance = self::instance();
        foreach (['GET', 'POST', 'PUT', 'DELETE'] as $method) {
            $instance->addRoute($method, $path, $handler);
        }
    }

    private function addRoute(string $method, string $path, callable $handler): void
    {
        $pattern = $this->pathToPattern($path);
        $this->routes[$method][$pattern] = [
            'path' => $path,
            'handler' => $handler,
        ];
    }

    private function pathToPattern(string $path): string
    {
        $pattern = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $path);
        return '#^' . $pattern . '$#';
    }

    public static function middleware(callable $middleware): void
    {
        self::instance()->middleware[] = $middleware;
    }

    /** @var bool Debug mode for router diagnostics */
    private static bool $debugMode = false;
    
    /** @var array Debug info collected during dispatch */
    private static array $debugInfo = [];
    
    /**
     * Enable router debug mode
     * Shows detailed routing information when ?__router=1 is in URL
     */
    public static function enableDebug(): void
    {
        self::$debugMode = true;
    }
    
    /**
     * Check if debug mode is active
     */
    public static function isDebugMode(): bool
    {
        return self::$debugMode || isset($_GET['__router']);
    }
    
    /**
     * Get debug information about last dispatch
     */
    public static function getDebugInfo(): array
    {
        return self::$debugInfo;
    }
    
    /**
     * Show debug map (for ?__router=1)
     */
    public static function debug(): string
    {
        $info = self::$debugInfo;
        
        $output = "<pre style='background:#1e1e1e;color:#ddd;padding:20px;font-family:monospace;'>\n";
        $output .= "<strong style='color:#569cd6;'>ROUTER DEBUG MAP</strong>\n";
        $output .= "================\n\n";
        
        $output .= "<span style='color:#9cdcfe;'>REQUEST_URI:</span>     " . ($info['request_uri'] ?? 'N/A') . "\n";
        $output .= "<span style='color:#9cdcfe;'>Base URL:</span>        " . ($info['base_url'] ?? '(empty)') . "\n";
        $output .= "<span style='color:#9cdcfe;'>Normalized:</span>      " . ($info['normalized_path'] ?? 'N/A') . "\n";
        $output .= "<span style='color:#9cdcfe;'>Method:</span>          " . ($info['method'] ?? 'N/A') . "\n";
        $output .= "<span style='color:#9cdcfe;'>Matched Route:</span>   " . ($info['matched_route'] ?? 'No match (404)') . "\n";
        $output .= "<span style='color:#9cdcfe;'>Middleware:</span>      " . ($info['middleware'] ?? 'none') . "\n";
        
        $output .= "\n<strong style='color:#569cd6;'>Registered Routes ({$info['method']}):</strong>\n";
        foreach (($info['registered_routes'] ?? []) as $pattern => $route) {
            $marker = ($pattern === $info['matched_pattern']) ? '→' : ' ';
            $output .= "  {$marker} {$route['path']}\n";
        }
        
        $output .= "</pre>";
        
        return $output;
    }

    public static function dispatch(): void
    {
        $instance = self::instance();
        
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $uri = parse_url($requestUri, PHP_URL_PATH);
        
        // Collect debug info
        $baseUrl = \Core\System\System::baseUrl();
        self::$debugInfo = [
            'request_uri' => $requestUri,
            'base_url' => $baseUrl,
            'method' => $method,
            'normalized_path' => $uri,
            'matched_route' => null,
            'matched_pattern' => null,
            'middleware' => count($instance->middleware) > 0 ? count($instance->middleware) . ' registered' : 'none',
            'registered_routes' => $instance->routes[$method] ?? [],
        ];
        
        // Normalize path: strip baseUrl prefix if present
        if (!empty($baseUrl) && strpos($uri, $baseUrl) === 0) {
            $uri = substr($uri, strlen($baseUrl));
            if (empty($uri)) {
                $uri = '/';
            }
        }
        
        // Ensure path starts with /
        if (empty($uri) || $uri[0] !== '/') {
            $uri = '/' . $uri;
        }
        
        self::$debugInfo['normalized_path'] = $uri;
        
        // Check for debug mode
        if (self::isDebugMode()) {
            echo self::debug();
            return;
        }

        foreach ($instance->middleware as $middleware) {
            $result = $middleware($method, $uri);
            if ($result === false) {
                return;
            }
        }

        $routes = $instance->routes[$method] ?? [];
        
        foreach ($routes as $pattern => $route) {
            if (preg_match($pattern, $uri, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                
                self::$debugInfo['matched_route'] = $route['path'];
                self::$debugInfo['matched_pattern'] = $pattern;
                
                try {
                    // Extract only named parameters (not numeric keys from preg_match)
                    $namedParams = array_values(array_filter($params, 'is_string', ARRAY_FILTER_USE_KEY));
                    $response = call_user_func_array($route['handler'], $namedParams);
                    
                    if (is_array($response)) {
                        header('Content-Type: application/json');
                        echo json_encode($response, JSON_UNESCAPED_UNICODE);
                    } elseif (is_string($response)) {
                        echo $response;
                    }
                    
                    return;
                    
                } catch (\Throwable $e) {
                    Logger::error("Route error: {$uri}", [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    
                    http_response_code(500);
                    echo "Internal Server Error";
                    return;
                }
            }
        }

        http_response_code(404);
        echo "Not Found";
    }

    public static function redirect(string $url): void
    {
        header("Location: {$url}");
        exit;
    }

    public static function json(mixed $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function getRoutes(): array
    {
        return self::instance()->routes;
    }
}

/* RULES
- Purpose: HTTP request routing for web interface
- Config sources: None (routes registered programmatically)
- Paths: Uses System::web() for URL generation
- Logs: None
- Prohibitions:
  - NO hardcoded URLs
  - NO direct path manipulation
*/
