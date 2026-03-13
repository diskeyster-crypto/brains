<?php

declare(strict_types=1);

namespace Core\Storage;

use Core\System\System;

final class JsonStorage implements StorageInterface
{
    private string $storagePath;
    private array $data = [];
    private bool $loaded = false;

    public function __construct(?string $path = null)
    {
        $this->storagePath = $path ?? System::path('storage') . '/data.json';
        $this->ensureDirectory();
    }

    private function ensureDirectory(): void
    {
        $dir = dirname($this->storagePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        if (file_exists($this->storagePath)) {
            $content = @file_get_contents($this->storagePath);
            if ($content === false) {
                // File read error - return empty data, don't crash
                $this->data = [];
            } else {
                $decoded = json_decode($content, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    // Corrupted JSON - backup the file and start fresh
                    $backupPath = $this->storagePath . '.corrupted.' . date('YmdHis');
                    @rename($this->storagePath, $backupPath);
                    $this->data = [];
                } else {
                    $this->data = $decoded ?? [];
                }
            }
        }
        $this->loaded = true;
    }

    /**
     * Atomic save: write to tmp file, then rename
     * This prevents corruption on concurrent writes
     */
    private function save(): void
    {
        $tmpPath = $this->storagePath . '.tmp.' . getmypid();
        
        $content = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        // Write to temporary file
        if (file_put_contents($tmpPath, $content, LOCK_EX) === false) {
            throw new \RuntimeException("Failed to write to temporary file: {$tmpPath}");
        }
        
        // Atomic rename
        if (!rename($tmpPath, $this->storagePath)) {
            @unlink($tmpPath); // Clean up tmp file on failure
            throw new \RuntimeException("Failed to rename temporary file to: {$this->storagePath}");
        }
    }

    public function get(string $key): mixed
    {
        $this->load();
        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->load();
        $this->data[$key] = $value;
        $this->save();
    }

    public function delete(string $key): void
    {
        $this->load();
        unset($this->data[$key]);
        $this->save();
    }

    public function exists(string $key): bool
    {
        $this->load();
        return array_key_exists($key, $this->data);
    }

    public function all(): array
    {
        $this->load();
        return $this->data;
    }

    public function clear(): void
    {
        $this->data = [];
        $this->save();
    }
}

/* RULES
- Purpose: JSON file storage driver with atomic writes
- Config sources: Path provided during construction
- Paths: Must use System::path() for file location
- Logs: None
- Prohibitions:
  - NO direct file writes (use tmp+rename for atomicity)
  - NO storing sensitive data without encryption
*/
