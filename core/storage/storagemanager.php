<?php

declare(strict_types=1);

namespace Core\Storage;

use Core\System\System;

final class StorageManager
{
    private static ?StorageInterface $instance = null;

    private function __construct()
    {
    }

    public static function instance(): StorageInterface
    {
        if (self::$instance === null) {
            self::$instance = self::createStorage();
        }
        return self::$instance;
    }

    private static function createStorage(): StorageInterface
    {
        $config = System::config()->load('storage');
        
        // Validate 'backend' key exists - no silent defaults
        if (!array_key_exists('backend', $config)) {
            throw new \RuntimeException(
                "Missing required 'backend' key in config/storage.php. " .
                "Value must be one of: json, sqlite, mysql. No silent defaults allowed."
            );
        }
        
        $backend = $config['backend'];

        return match ($backend) {
            'json' => self::createJsonStorage($config),
            'sqlite' => self::createSqliteStorage($config),
            'mysql' => self::createMysqlStorage($config),
            default => throw new \InvalidArgumentException("Unknown storage backend: {$backend}"),
        };
    }
    
    private static function createJsonStorage(array $config): JsonStorage
    {
        if (!array_key_exists('json', $config)) {
            throw new \RuntimeException(
                "Missing 'json' section in config/storage.php for json backend."
            );
        }
        // path can be null (uses default), but section must exist
        return new JsonStorage($config['json']['path'] ?? null);
    }
    
    private static function createSqliteStorage(array $config): SqliteStorage
    {
        if (!array_key_exists('sqlite', $config)) {
            throw new \RuntimeException(
                "Missing 'sqlite' section in config/storage.php for sqlite backend."
            );
        }
        return new SqliteStorage($config['sqlite']['path'] ?? null);
    }
    
    private static function createMysqlStorage(array $config): MysqlStorage
    {
        if (!array_key_exists('mysql', $config)) {
            throw new \RuntimeException(
                "Missing 'mysql' section in config/storage.php for mysql backend."
            );
        }
        
        $mysqlConfig = $config['mysql'];
        $required = ['host', 'port', 'database', 'username', 'password', 'table'];
        $missing = [];
        foreach ($required as $key) {
            if (!array_key_exists($key, $mysqlConfig)) {
                $missing[] = $key;
            }
        }
        
        if (!empty($missing)) {
            throw new \RuntimeException(
                "Missing required MySQL config keys in config/storage.php: " . implode(', ', $missing)
            );
        }
        
        return new MysqlStorage($mysqlConfig);
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}

/* RULES
- Purpose: Unified storage facade with driver abstraction
- Config sources: config/storage.php
- Paths: Uses System::path('storage') for JSON driver
- Logs: None
- Prohibitions:
  - NO silent defaults - missing config throws exception
  - NO hardcoded storage paths
*/
