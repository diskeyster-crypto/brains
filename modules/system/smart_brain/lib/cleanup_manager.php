<?php
declare(strict_types=1);

/**
 * Smart Brain Module — Cleanup Manager
 *
 * Provides controlled cleanup of runtime artifacts while preserving
 * the Brain knowledge base (passports, user config, base config).
 *
 * Three modes:
 *   1. softCleanup()       — clear temporary runtime artifacts
 *   2. resetSimulator()    — reset simulator state only
 *   3. fullRuntimeReset()  — clear all runtime + simulator data
 */
final class CleanupManager
{
    private string $moduleBase;
    private string $logsDir;

    public function __construct(string $moduleBase)
    {
        $this->moduleBase = rtrim($moduleBase, '/');
        $this->logsDir = $this->moduleBase . '/storage/logs';

        if (!is_dir($this->logsDir)) {
            mkdir($this->logsDir, 0755, true);
        }
    }

    /**
     * Mode 1 — Soft Cleanup.
     *
     * Clears temporary runtime artifacts.
     * Does NOT touch active/closed simulator data or passports.
     *
     * @return array{mode:string,deleted:int,files:list<string>}
     */
    public function softCleanup(): array
    {
        $targets = [
            'storage/candidates.json',
            'storage/monitors.json',
            'storage/signals.json',
            'storage/last_run.json',
            'runtime/brain.lock',
            'runtime/config.snapshot.json',
            'storage/simulator/waiting.json',
        ];

        $deleted = $this->deleteFiles($targets);
        $deleted = array_merge($deleted, $this->deleteLogs());

        $this->writeCleanupLog('soft_cleanup', $deleted);

        return ['mode' => 'soft_cleanup', 'deleted' => count($deleted), 'files' => $deleted];
    }

    /**
     * Mode 2 — Simulator Reset.
     *
     * Resets simulator state without affecting Brain learning data.
     *
     * @return array{mode:string,deleted:int,files:list<string>}
     */
    public function resetSimulator(): array
    {
        $targets = [
            'storage/simulator/waiting.json',
            'storage/simulator/active.json',
            'storage/simulator/closed.json',
            'storage/simulator/stats.json',
        ];

        $deleted = $this->deleteFiles($targets);

        $this->writeCleanupLog('simulator_reset', $deleted);

        return ['mode' => 'simulator_reset', 'deleted' => count($deleted), 'files' => $deleted];
    }

    /**
     * Mode 3 — Full Runtime Reset.
     *
     * Resets all runtime data while preserving Brain knowledge.
     *
     * @return array{mode:string,deleted:int,files:list<string>}
     */
    public function fullRuntimeReset(): array
    {
        $targets = [
            'storage/candidates.json',
            'storage/monitors.json',
            'storage/signals.json',
            'storage/last_run.json',
            'runtime/brain.lock',
            'runtime/config.snapshot.json',
            'storage/simulator/waiting.json',
            'storage/simulator/active.json',
            'storage/simulator/closed.json',
            'storage/simulator/stats.json',
        ];

        $deleted = $this->deleteFiles($targets);
        $deleted = array_merge($deleted, $this->deleteLogs());

        $this->writeCleanupLog('full_runtime_reset', $deleted);

        return ['mode' => 'full_runtime_reset', 'deleted' => count($deleted), 'files' => $deleted];
    }

    /**
     * Delete a list of files (relative to module base). Ignores missing files.
     *
     * @param  list<string> $relativePaths
     * @return list<string> Actually deleted file paths (relative)
     */
    private function deleteFiles(array $relativePaths): array
    {
        $deleted = [];

        foreach ($relativePaths as $rel) {
            $abs = $this->moduleBase . '/' . ltrim($rel, '/');
            if (is_file($abs) && @unlink($abs)) {
                $deleted[] = $rel;
            }
        }

        return $deleted;
    }

    /**
     * Delete all *.log files inside storage/logs/.
     *
     * @return list<string> Deleted log file paths (relative)
     */
    private function deleteLogs(): array
    {
        $deleted = [];
        $pattern = $this->logsDir . '/*.log';
        $files = glob($pattern);

        if (!is_array($files)) {
            return $deleted;
        }

        foreach ($files as $absPath) {
            if (is_file($absPath) && @unlink($absPath)) {
                $deleted[] = 'storage/logs/' . basename($absPath);
            }
        }

        return $deleted;
    }

    /**
     * Append cleanup log entry to storage/logs/cleanup.log.
     *
     * @param string       $mode    Cleanup mode name
     * @param list<string> $deleted List of deleted file paths
     */
    private function writeCleanupLog(string $mode, array $deleted): void
    {
        if (!is_dir($this->logsDir)) {
            mkdir($this->logsDir, 0755, true);
        }

        $entry = date('c') . PHP_EOL
            . 'mode=' . $mode . PHP_EOL
            . 'files_deleted=' . count($deleted) . PHP_EOL
            . PHP_EOL;

        @file_put_contents(
            $this->logsDir . '/cleanup.log',
            $entry,
            FILE_APPEND | LOCK_EX
        );
    }
}
