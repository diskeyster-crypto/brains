<?php

declare(strict_types=1);

namespace Core\Storage;

final class MysqlStorage implements StorageInterface
{
    private \PDO $pdo;
    private string $table;

    public function __construct(array $config)
    {
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 3306;
        $database = $config['database'] ?? 'tredercopis';
        $username = $config['username'] ?? 'root';
        $password = $config['password'] ?? '';
        $this->table = $config['table'] ?? 'storage';

        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
        $this->pdo = new \PDO($dsn, $username, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        
        $this->initTable();
    }

    private function initTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->table} (
                `key` VARCHAR(255) PRIMARY KEY,
                `value` LONGTEXT,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function get(string $key): mixed
    {
        $stmt = $this->pdo->prepare("SELECT value FROM {$this->table} WHERE `key` = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        
        if ($row === false) {
            return null;
        }
        
        return json_decode($row['value'], true);
    }

    public function set(string $key, mixed $value): void
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
        
        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->table} (`key`, `value`) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE `value` = ?
        ");
        $stmt->execute([$key, $encoded, $encoded]);
    }

    public function delete(string $key): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE `key` = ?");
        $stmt->execute([$key]);
    }

    public function exists(string $key): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM {$this->table} WHERE `key` = ?");
        $stmt->execute([$key]);
        return $stmt->fetch() !== false;
    }

    public function all(): array
    {
        $stmt = $this->pdo->query("SELECT `key`, `value` FROM {$this->table}");
        $result = [];
        
        while ($row = $stmt->fetch()) {
            $result[$row['key']] = json_decode($row['value'], true);
        }
        
        return $result;
    }

    public function clear(): void
    {
        $this->pdo->exec("TRUNCATE TABLE {$this->table}");
    }
}

/* RULES
- Purpose: MySQL storage driver
- Config sources: config/storage.php for connection params
- Paths: None (database connection)
- Logs: None
- Prohibitions:
  - NO SQL injection (use prepared statements)
  - NO storing DB credentials in code
*/
