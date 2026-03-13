<?php
/**
 * Tredercopis Core - System Error Handler
 * 
 * Global error handling with environment-aware behavior.
 * dev → shows stacktrace, prod → logs only
 */

namespace Core\System;

use Core\System\System;
use Core\Logger\Logger;

class SystemError
{
    /** @var array|null Last error info */
    private static ?array $lastError = null;
    
    /** @var bool Whether handler is registered */
    private static bool $registered = false;
    
    /**
     * Register global error/exception handlers
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        
        set_exception_handler([self::class, 'handleException']);
        set_error_handler([self::class, 'handleError']);
        register_shutdown_function([self::class, 'handleShutdown']);
        
        self::$registered = true;
    }
    
    /**
     * Handle a throwable exception
     */
    public static function handle(\Throwable $e): void
    {
        self::$lastError = [
            'type' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'timestamp' => date('Y-m-d H:i:s'),
        ];
        
        // Always log
        self::log($e);
        
        // Emit error event if available
        if (class_exists('\Core\System\SystemEvents') && System::isInitialized()) {
            try {
                System::events()->emit('system.error', self::$lastError);
            } catch (\Throwable $eventError) {
                // Ignore event errors during error handling
            }
        }
        
        // Render based on environment
        self::render();
    }
    
    /**
     * Exception handler callback
     */
    public static function handleException(\Throwable $e): void
    {
        self::handle($e);
    }
    
    /**
     * Error handler callback - converts errors to exceptions
     */
    public static function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        
        throw new \ErrorException($message, 0, $severity, $file, $line);
    }
    
    /**
     * Shutdown handler - catches fatal errors
     */
    public static function handleShutdown(): void
    {
        $error = error_get_last();
        
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            self::handle(new \ErrorException(
                $error['message'],
                0,
                $error['type'],
                $error['file'],
                $error['line']
            ));
        }
    }
    
    /**
     * Log error to system log
     */
    public static function log(\Throwable $e): void
    {
        $message = sprintf(
            "[%s] %s in %s:%d\n%s",
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );
        
        try {
            if (System::isInitialized()) {
                System::log('system', $message, [
                    'level' => 'ERROR',
                    'exception' => get_class($e),
                ]);
            } else {
                // Fallback to error_log
                error_log($message);
            }
        } catch (\Throwable $logError) {
            error_log($message);
        }
    }
    
    /**
     * Render error output based on environment
     */
    public static function render(): void
    {
        if (php_sapi_name() === 'cli') {
            self::renderCli();
        } else {
            self::renderHttp();
        }
    }
    
    /**
     * Render error for CLI
     */
    private static function renderCli(): void
    {
        if (!self::$lastError) {
            return;
        }
        
        $isDev = self::isDev();
        
        echo "\n";
        echo "╔══════════════════════════════════════════════════════════════════╗\n";
        echo "║                         ERROR                                    ║\n";
        echo "╚══════════════════════════════════════════════════════════════════╝\n\n";
        
        echo "Type: " . self::$lastError['type'] . "\n";
        echo "Message: " . self::$lastError['message'] . "\n";
        
        if ($isDev) {
            echo "File: " . self::$lastError['file'] . "\n";
            echo "Line: " . self::$lastError['line'] . "\n";
            echo "\nStack Trace:\n";
            echo self::$lastError['trace'] . "\n";
        } else {
            echo "\nDetails logged to system.log\n";
        }
        
        echo "\n";
    }
    
    /**
     * Render error for HTTP
     */
    private static function renderHttp(): void
    {
        if (!self::$lastError) {
            return;
        }
        
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }
        
        $isDev = self::isDev();
        $error = self::$lastError;
        
        if ($isDev) {
            // Development: show full details
            $codePreview = self::getCodePreview($error['file'], $error['line']);
            
            echo '<!DOCTYPE html>
<html>
<head>
    <title>Error - Tredercopis Core</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #1a1a2e; color: #eaeaea; padding: 40px; }
        .container { max-width: 1200px; margin: 0 auto; }
        .header { background: #e74c3c; color: white; padding: 20px 30px; border-radius: 8px 8px 0 0; }
        .header h1 { font-size: 24px; margin-bottom: 5px; }
        .header .type { opacity: 0.9; font-size: 14px; }
        .content { background: #16213e; padding: 30px; border-radius: 0 0 8px 8px; }
        .message { font-size: 18px; color: #f39c12; margin-bottom: 20px; padding: 15px; background: rgba(243,156,18,0.1); border-radius: 4px; }
        .location { color: #3498db; margin-bottom: 20px; }
        .code-preview { background: #0f0f23; padding: 20px; border-radius: 4px; overflow-x: auto; margin-bottom: 20px; }
        .code-preview pre { margin: 0; font-family: "Monaco", "Consolas", monospace; font-size: 13px; line-height: 1.6; }
        .code-preview .line { display: block; }
        .code-preview .line-number { color: #666; display: inline-block; width: 50px; user-select: none; }
        .code-preview .line.highlight { background: rgba(231,76,60,0.3); margin: 0 -20px; padding: 0 20px; }
        .trace { background: #0f0f23; padding: 20px; border-radius: 4px; }
        .trace h3 { color: #9b59b6; margin-bottom: 15px; }
        .trace pre { font-family: "Monaco", "Consolas", monospace; font-size: 12px; color: #bdc3c7; white-space: pre-wrap; word-wrap: break-word; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Uncaught Exception</h1>
            <div class="type">' . htmlspecialchars($error['type']) . '</div>
        </div>
        <div class="content">
            <div class="message">' . htmlspecialchars($error['message']) . '</div>
            <div class="location">
                <strong>File:</strong> ' . htmlspecialchars($error['file']) . '<br>
                <strong>Line:</strong> ' . $error['line'] . '
            </div>
            <div class="code-preview">
                <pre>' . $codePreview . '</pre>
            </div>
            <div class="trace">
                <h3>Stack Trace</h3>
                <pre>' . htmlspecialchars($error['trace']) . '</pre>
            </div>
        </div>
    </div>
</body>
</html>';
        } else {
            // Production: show friendly error page
            echo '<!DOCTYPE html>
<html>
<head>
    <title>Error - Tredercopis</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f5f5f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .error-box { text-align: center; padding: 60px; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 500px; }
        .error-icon { font-size: 64px; margin-bottom: 20px; }
        h1 { color: #333; margin-bottom: 10px; }
        p { color: #666; margin-bottom: 30px; }
        a { color: #3498db; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="error-box">
        <div class="error-icon">⚠️</div>
        <h1>Something went wrong</h1>
        <p>We\'re sorry, but an error occurred while processing your request. Our team has been notified.</p>
        <a href="/">← Return to Home</a>
    </div>
</body>
</html>';
        }
    }
    
    /**
     * Get code preview around error line
     */
    private static function getCodePreview(string $file, int $line, int $range = 5): string
    {
        if (!file_exists($file) || !is_readable($file)) {
            return '<span class="line">Unable to read source file</span>';
        }
        
        $lines = file($file);
        if (!$lines) {
            return '<span class="line">Unable to read source file</span>';
        }
        
        $start = max(0, $line - $range - 1);
        $end = min(count($lines), $line + $range);
        
        $output = '';
        for ($i = $start; $i < $end; $i++) {
            $lineNum = $i + 1;
            $isHighlight = ($lineNum === $line) ? ' highlight' : '';
            $code = htmlspecialchars(rtrim($lines[$i]));
            $output .= '<span class="line' . $isHighlight . '"><span class="line-number">' . $lineNum . '</span>' . $code . '</span>' . "\n";
        }
        
        return $output;
    }
    
    /**
     * Check if running in development mode
     */
    private static function isDev(): bool
    {
        try {
            if (System::isInitialized()) {
                return System::env()->isDev();
            }
        } catch (\Throwable $e) {
            // Fallback
        }
        return true;
    }
    
    /**
     * Get last error info
     */
    public static function getLastError(): ?array
    {
        return self::$lastError;
    }
    
    /**
     * Clear last error
     */
    public static function clearLastError(): void
    {
        self::$lastError = null;
    }
}

/* RULES
 * - Purpose: Global error handling with environment-aware output
 * - Config sources: System::env() for dev/prod detection
 * - Paths: Uses System::path() for log paths
 * - Logs: runtime/logs/system.log
 * - Prohibitions: No ../, no hardcoded paths
 */
