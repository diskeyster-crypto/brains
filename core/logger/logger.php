<?php

declare(strict_types=1);

namespace Core\Logger;

use Core\System\System;

final class Logger
{
    private const LEVELS = ['debug', 'info', 'warning', 'error', 'critical'];
    
    // Log channels
    public const CHANNEL_SYSTEM = 'system';
    public const CHANNEL_SECURITY = 'security';
    public const CHANNEL_CRON = 'cron';
    
    private static ?self $instance = null;
    private string $logsDir;

    private function __construct()
    {
        $this->logsDir = System::path('logs');
        $this->ensureDirectory();
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->logsDir)) {
            mkdir($this->logsDir, 0755, true);
        }
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function debug(string $message, array $context = [], string $channel = self::CHANNEL_SYSTEM): void
    {
        self::instance()->log('DEBUG', $message, $context, $channel);
    }

    public static function info(string $message, array $context = [], string $channel = self::CHANNEL_SYSTEM): void
    {
        self::instance()->log('INFO', $message, $context, $channel);
    }

    public static function warning(string $message, array $context = [], string $channel = self::CHANNEL_SYSTEM): void
    {
        self::instance()->log('WARNING', $message, $context, $channel);
    }

    public static function error(string $message, array $context = [], string $channel = self::CHANNEL_SYSTEM): void
    {
        self::instance()->log('ERROR', $message, $context, $channel);
    }

    public static function critical(string $message, array $context = [], string $channel = self::CHANNEL_SYSTEM): void
    {
        self::instance()->log('CRITICAL', $message, $context, $channel);
    }

    /**
     * Security-specific logging (goes to security.log)
     */
    public static function security(string $message, array $context = []): void
    {
        self::instance()->log('INFO', $message, $context, self::CHANNEL_SECURITY);
    }

    /**
     * Cron-specific logging (goes to cron.log)
     */
    public static function cron(string $message, array $context = []): void
    {
        self::instance()->log('INFO', $message, $context, self::CHANNEL_CRON);
    }

    /**
     * Write to a named channel (called by System::log())
     * 
     * Supported channels:
     * - system: General system messages
     * - gateway: API gateway requests/responses
     * - cron: Scheduled task execution
     * - module: Module operations
     * - security: Security events
     * 
     * @param string $channel Channel name
     * @param string $message Log message
     * @param array $context Additional context
     */
    public static function write(string $channel, string $message, array $context = []): void
    {
        self::instance()->log('INFO', $message, $context, $channel);
    }

    private function log(string $level, string $message, array $context = [], string $channel = self::CHANNEL_SYSTEM): void
    {
        // ISO 8601 timestamp
        $timestamp = date('c'); // e.g., 2026-01-25T12:00:00+00:00
        
        // Determine source from backtrace
        $source = $this->getSource();
        
        $contextStr = '';
        if (!empty($context)) {
            $contextStr = ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }

        // Format: 2026-01-25T12:00:00Z [LEVEL] [source] message {json}
        $logLine = "{$timestamp} [{$level}] [{$source}] {$message}{$contextStr}" . PHP_EOL;

        $logFile = $this->logsDir . '/' . $channel . '.log';
        file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
    }

    private function getSource(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
        
        // Find the first caller outside of Logger class
        foreach ($trace as $frame) {
            $class = $frame['class'] ?? '';
            if ($class !== self::class && $class !== '') {
                return basename(str_replace('\\', '/', $class));
            }
            if (!isset($frame['class']) && isset($frame['file'])) {
                return basename($frame['file'], '.php');
            }
        }
        
        return 'unknown';
    }

    public function getLogPath(string $channel = self::CHANNEL_SYSTEM): string
    {
        return $this->logsDir . '/' . $channel . '.log';
    }

    public function readLogs(int $lines = 100, string $channel = self::CHANNEL_SYSTEM): array
    {
        $logFile = $this->logsDir . '/' . $channel . '.log';
        
        if (!file_exists($logFile)) {
            return [];
        }

        $file = new \SplFileObject($logFile, 'r');
        $file->seek(PHP_INT_MAX);
        $totalLines = $file->key();

        $startLine = max(0, $totalLines - $lines);
        $logs = [];

        $file->seek($startLine);
        while (!$file->eof()) {
            $line = $file->fgets();
            if (trim($line) !== '') {
                $logs[] = $line;
            }
        }

        return $logs;
    }

    public function clear(string $channel = self::CHANNEL_SYSTEM): void
    {
        $logFile = $this->logsDir . '/' . $channel . '.log';
        if (file_exists($logFile)) {
            file_put_contents($logFile, '');
        }
    }

    /**
     * Get all available log channels
     */
    public function getChannels(): array
    {
        return [self::CHANNEL_SYSTEM, self::CHANNEL_SECURITY, self::CHANNEL_CRON];
    }
}

/* RULES
- Purpose: Multi-channel logging system
- Config sources: None (uses System::path('logs'))
- Paths: Writes to runtime/logs/ via System::path()
- Logs: This IS the logging system - writes to system.log, security.log, cron.log
- Prohibitions:
  - NO hardcoded log paths
  - NO writing outside runtime/logs/
*/
