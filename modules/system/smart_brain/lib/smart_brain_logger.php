<?php
declare(strict_types=1);

final class SmartBrainLogger
{
    private string $logFile;

    public function __construct(string $moduleBase)
    {
        $this->logFile = rtrim($moduleBase, '/') . '/storage/logs/smart_brain.log';

        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    public function log(string $level, string $message): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] [' . strtoupper($level) . '] ' . $message . PHP_EOL;
        @file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
