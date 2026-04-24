<?php

declare(strict_types=1);

namespace Modules\ProfManager\Lib;

/**
 * Store
 *
 * Persists Profit Manager runtime state to JSON files.
 *
 * Files:
 *   storage/runtime/last_run.json        — result of the last tick()
 *   storage/runtime/positions_state.json — per-position tracking state
 *   storage/runtime/locks.json           — planned lock prices per position
 *   storage/logs/error.log               — appended error entries
 */
class Store
{
    private string $storageDir;

    public function __construct(string $storageDir)
    {
        $this->storageDir = rtrim($storageDir, '/');
    }

    // =========================================================================
    // Public accessors
    // =========================================================================

    public function readLastRun(): array
    {
        return $this->readJson('runtime/last_run.json', []);
    }

    public function writeLastRun(array $data): void
    {
        $this->writeJson('runtime/last_run.json', $data);
    }

    public function readPositionsState(): array
    {
        return $this->readJson('runtime/positions_state.json', []);
    }

    public function writePositionsState(array $data): void
    {
        $this->writeJson('runtime/positions_state.json', $data);
    }

    public function readLocks(): array
    {
        return $this->readJson('runtime/locks.json', []);
    }

    public function writeLocks(array $data): void
    {
        $this->writeJson('runtime/locks.json', $data);
    }

    /**
     * Append a single error entry to the error log.
     */
    public function appendError(string $context, string $message, array $extra = []): void
    {
        $logPath = $this->storageDir . '/logs/error.log';
        $this->ensureDir(dirname($logPath));

        $entry = json_encode([
            'ts'      => date('c'),
            'context' => $context,
            'message' => $message,
            'extra'   => $extra,
        ]) . "\n";

        file_put_contents($logPath, $entry, FILE_APPEND | LOCK_EX);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function readJson(string $relPath, array $default): array
    {
        $path = $this->storageDir . '/' . $relPath;
        if (!is_file($path) || !is_readable($path)) {
            return $default;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return $default;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : $default;
    }

    private function writeJson(string $relPath, array $data): void
    {
        $path = $this->storageDir . '/' . $relPath;
        $this->ensureDir(dirname($path));
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
}
