<?php
declare(strict_types=1);

final class SmartBrainLogger
{
    private string $logFile;
    private string $logsDir;

    public function __construct(string $moduleBase)
    {
        $this->logsDir = rtrim($moduleBase, '/') . '/storage/logs';
        $this->logFile = $this->logsDir . '/smart_brain.log';

        if (!is_dir($this->logsDir)) {
            mkdir($this->logsDir, 0755, true);
        }
    }

    public function log(string $level, string $message): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] [' . strtoupper($level) . '] ' . $message . PHP_EOL;
        @file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Write debug signal log (signal_debug.log).
     * Overwrites the file each cycle. Max 200 lines.
     *
     * @param array<int,string> $lines
     */
    public function writeDebugLog(array $lines): void
    {
        $path = $this->logsDir . '/signal_debug.log';
        $content = implode(PHP_EOL, array_slice($lines, 0, 200)) . PHP_EOL;
        @file_put_contents($path, $content, LOCK_EX);
    }

    /**
     * Write analyzer decision debug log (analyzer_debug.log).
     * Overwrites the file each cycle. Max 200 lines.
     *
     * @param array<int,string> $lines
     */
    public function writeAnalyzerDebugLog(array $lines): void
    {
        $path = $this->logsDir . '/analyzer_debug.log';
        $content = implode(PHP_EOL, array_slice($lines, 0, 200)) . PHP_EOL;
        @file_put_contents($path, $content, LOCK_EX);
    }
}
