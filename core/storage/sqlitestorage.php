<?php

declare(strict_types=1);

namespace Core\Storage;

use Core\System\System;

final class SqliteStorage implements StorageInterface
{
    private \PDO $pdo;

    public function __construct(?string $path = null)
    {
        $dbPath = $path ?? System::path('storage') . '/storage.db';
        $this->ensureDirectory($dbPath);
        
        $this->pdo = new \PDO("sqlite:{$dbPath}");
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->initTable();
    }

    private function ensureDirectory(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    private function initTable(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS storage (
                key TEXT PRIMARY KEY,
                value TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ');
    }

    public function get(string $key): mixed
    {
        $stmt = $this->pdo->prepare('SELECT value FROM storage WHERE key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if ($row === false) {
            return null;
        }
        
        return json_decode($row['value'], true);
    }

    public function set(string $key, mixed $value): void
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
        
        $stmt = $this->pdo->prepare('
            INSERT INTO storage (key, value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(key) DO UPDATE SET value = ?, updated_at = CURRENT_TIMESTAMP
        ');
        $stmt->execute([$key, $encoded, $encoded]);
    }

    public function delete(string $key): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM storage WHERE key = ?');
        $stmt->execute([$key]);
    }

    public function exists(string $key): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM storage WHERE key = ?');
        $stmt->execute([$key]);
        return $stmt->fetch() !== false;
    }

    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT key, value FROM storage');
        $result = [];
        
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $result[$row['key']] = json_decode($row['value'], true);
        }
        
        return $result;
    }

    public function clear(): void
    {
        $this->pdo->exec('DELETE FROM storage');
    }
}

/* RULES
- Purpose: SQLite storage driver
- Config sources: config/storage.php for database path
- Paths: Uses System::path('storage') for db file
- Logs: None
- Prohibitions:
  - NO SQL injection (use prepared statements)
*/
