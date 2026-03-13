<?php
/**
 * EntryPoint Contract
 * 
 * Standardizes all entry points in Tredercopis Core.
 * Every entry point (index.php, public/index.php, scripts/all, modules/all/runner.php)
 * MUST follow this contract.
 * 
 * Contract requirements:
 * 1. Define ROOT constant: define('ROOT', __DIR__) or define('ROOT', dirname(__DIR__))
 * 2. Require bootstrap: require_once ROOT . '/core/bootstrap.php'
 * 3. Initialize system: System::init(['root' => ROOT])
 * 4. Handle errors via System::error() (web entry points)
 * 
 * @package Core\System
 */

declare(strict_types=1);

namespace Core\System;

/**
 * EntryPoint - Standard entry point initialization
 * 
 * Usage in entry point files:
 * 
 * CLI entry (index.php):
 *   define('ROOT', __DIR__);
 *   require_once ROOT . '/core/bootstrap.php';
 *   \Core\System\EntryPoint::initCli(ROOT);
 * 
 * Web entry (public/index.php):
 *   define('ROOT', dirname(__DIR__));
 *   require_once ROOT . '/core/bootstrap.php';
 *   \Core\System\EntryPoint::initWeb(ROOT);
 * 
 * Script entry (scripts/*.php):
 *   define('ROOT', dirname(__DIR__));
 *   require_once ROOT . '/core/bootstrap.php';
 *   \Core\System\EntryPoint::initScript(ROOT);
 */
final class EntryPoint
{
    private static bool $initialized = false;
    private static string $type = 'unknown';
    private static float $startTime = 0;
    
    /**
     * Initialize CLI entry point (index.php)
     * 
     * @param string $root Root path
     * @param array $options Additional options
     */
    public static function initCli(string $root, array $options = []): void
    {
        self::$startTime = microtime(true);
        self::$type = 'cli';
        
        System::init(array_merge([
            'root' => $root,
            'env' => $options['env'] ?? 'dev',
        ], $options));
        
        self::$initialized = true;
    }
    
    /**
     * Initialize Web entry point (public/index.php)
     * 
     * @param string $root Root path
     * @param array $options Additional options
     */
    public static function initWeb(string $root, array $options = []): void
    {
        self::$startTime = microtime(true);
        self::$type = 'web';
        
        System::init(array_merge([
            'root' => $root,
            'env' => $options['env'] ?? 'dev',
        ], $options));
        
        // Start session for web mode
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        self::$initialized = true;
    }
    
    /**
     * Initialize Script entry point (scripts/all.php, modules/all/runner.php)
     * 
     * @param string $root Root path
     * @param array $options Additional options
     */
    public static function initScript(string $root, array $options = []): void
    {
        self::$startTime = microtime(true);
        self::$type = 'script';
        
        System::init(array_merge([
            'root' => $root,
            'env' => $options['env'] ?? 'dev',
        ], $options));
        
        self::$initialized = true;
    }
    
    /**
     * Get entry point type
     * 
     * @return string Entry point type (cli, web, script, unknown)
     */
    public static function getType(): string
    {
        return self::$type;
    }
    
    /**
     * Check if entry point is initialized
     * 
     * @return bool
     */
    public static function isInitialized(): bool
    {
        return self::$initialized;
    }
    
    /**
     * Get start time
     * 
     * @return float Start time in microseconds
     */
    public static function getStartTime(): float
    {
        return self::$startTime;
    }
    
    /**
     * Get elapsed time since entry point initialization
     * 
     * @return float Elapsed time in seconds
     */
    public static function getElapsedTime(): float
    {
        return microtime(true) - self::$startTime;
    }
    
    /**
     * Validate entry point requirements
     * 
     * Checks:
     * 1. ROOT is defined
     * 2. Bootstrap is loaded
     * 3. System is initialized
     * 
     * @return array Validation results
     */
    public static function validate(): array
    {
        $issues = [];
        
        if (!defined('ROOT')) {
            $issues[] = 'ROOT constant is not defined';
        }
        
        if (!class_exists(System::class)) {
            $issues[] = 'System class not loaded (bootstrap missing?)';
        }
        
        if (!System::isInitialized()) {
            $issues[] = 'System::init() was not called';
        }
        
        return [
            'valid' => empty($issues),
            'type' => self::$type,
            'initialized' => self::$initialized,
            'issues' => $issues,
        ];
    }
    
    /**
     * Get diagnostics info for doctor command
     * 
     * @return array Entry point info
     */
    public static function getDiagnostics(): array
    {
        return [
            'type' => self::$type,
            'initialized' => self::$initialized,
            'start_time' => self::$startTime,
            'elapsed_ms' => round(self::getElapsedTime() * 1000, 2),
            'root_defined' => defined('ROOT'),
            'root_value' => defined('ROOT') ? ROOT : null,
            'sapi' => PHP_SAPI,
        ];
    }
}

/* RULES
- Purpose: Standardize entry point initialization across all entry files
- Config sources: None (uses System::init)
- Paths: Uses ROOT constant defined by entry point
- Logs: No direct logging
- Prohibitions:
  - Entry points MUST define ROOT before requiring bootstrap
  - Entry points MUST call System::init() after bootstrap
  - NO manual path manipulation (use SystemPaths)
*/
