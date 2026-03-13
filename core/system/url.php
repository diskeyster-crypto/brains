<?php
/**
 * URL Service - Canonical URL API
 * 
 * Provides a single, consistent way to build URLs throughout the system.
 */

namespace Core\System;

use Core\Router\Zones;

class Url
{
    /**
     * Build URL with baseUrl
     */
    public static function to(string $path, array $params = []): string
    {
        $baseUrl = \Core\System\System::baseUrl();
        
        // Normalize path
        $path = '/' . ltrim($path, '/');
        
        // Build full URL
        $url = $baseUrl . $path;
        
        // Add query parameters
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        
        return $url;
    }
    
    /**
     * Build admin URL
     */
    public static function admin(string $path = ''): string
    {
        $path = '/admin' . ($path ? '/' . ltrim($path, '/') : '');
        return self::to($path);
    }
    
    /**
     * Build API URL
     */
    public static function api(string $path = ''): string
    {
        $path = '/api' . ($path ? '/' . ltrim($path, '/') : '');
        return self::to($path);
    }
    
    /**
     * Build asset URL
     */
    public static function asset(string $path): string
    {
        return self::to('/assets/' . ltrim($path, '/'));
    }
    
    /**
     * Get current URL
     */
    public static function current(): string
    {
        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        
        return $scheme . '://' . $host . $uri;
    }
    
    /**
     * Get current path (without query string)
     */
    public static function path(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';
        return $path;
    }
    
    /**
     * Get query parameters
     */
    public static function query(): array
    {
        return $_GET;
    }
    
    /**
     * Check if current URL matches pattern
     */
    public static function is(string $pattern): bool
    {
        $path = self::path();
        
        // Convert pattern to regex
        $regex = str_replace(['*', '/'], ['.*', '\/'], $pattern);
        return (bool) preg_match('/^' . $regex . '$/', $path);
    }
    
    /**
     * Check if current URL is in a specific zone
     */
    public static function isZone(string $zone): bool
    {
        return Zones::isInZone(self::path(), $zone);
    }
    
    /**
     * Redirect to URL
     */
    public static function redirect(string $path, int $code = 302): void
    {
        $url = self::to($path);
        header("Location: $url", true, $code);
        exit;
    }
    
    /**
     * Redirect to admin URL
     */
    public static function redirectAdmin(string $path = ''): void
    {
        self::redirect('/admin' . ($path ? '/' . ltrim($path, '/') : ''));
    }
    
    /**
     * Redirect to API URL
     */
    public static function redirectApi(string $path = ''): void
    {
        self::redirect('/api' . ($path ? '/' . ltrim($path, '/') : ''));
    }
    
    /**
     * Get normalized path (without baseUrl)
     */
    public static function normalize(string $path): string
    {
        $baseUrl = \Core\System\System::baseUrl();
        
        if ($baseUrl && strpos($path, $baseUrl) === 0) {
            $path = substr($path, strlen($baseUrl)) ?: '/';
        }
        
        return '/' . ltrim($path, '/');
    }
}

/* RULES
- Purpose: Canonical URL building service
- Config sources: System::baseUrl()
- Paths: Only through this service
- Logs: N/A
- Prohibitions: No hardcoded paths, no ../
*/
