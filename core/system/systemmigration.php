<?php
/**
 * Tredercopis Core - Migration System
 * 
 * Version-based database and storage migrations.
 */

namespace Core\System;

use Core\System\System;

class SystemMigration
{
    /** @var string Relative path to migrations storage file from storage */
    private static string $storageFile = 'system/migrations.php';
    
    /** @var string Relative path to migration files directory from storage */
    private static string $migrationsDir = 'system/migrations';
    
    /** @var array Applied migrations cache */
    private static ?array $applied = null;
    
    /**
     * Run all pending migrations
     * 
     * @return array Results of migrations
     */
    public static function run(): array
    {
        $results = [];
        $pending = self::pending();
        
        foreach ($pending as $version) {
            $result = self::runMigration($version);
            $results[$version] = $result;
            
            if (!$result['success']) {
                break; // Stop on first failure
            }
        }
        
        return $results;
    }
    
    /**
     * Run a specific migration
     * 
     * @param string $version Version to migrate (e.g., '1.2.0')
     * @return array Migration result
     */
    public static function runMigration(string $version): array
    {
        $file = System::path('storage') . '/' . self::$migrationsDir . '/' . $version . '.php';
        
        if (!file_exists($file)) {
            return [
                'success' => false,
                'version' => $version,
                'error' => 'Migration file not found: ' . $file,
            ];
        }
        
        try {
            $migration = require $file;
            
            if (!is_array($migration) || !isset($migration['up'])) {
                return [
                    'success' => false,
                    'version' => $version,
                    'error' => 'Invalid migration format',
                ];
            }
            
            // Run the migration
            $result = call_user_func($migration['up']);
            
            if ($result === true || $result === null) {
                // Mark as applied
                self::markApplied($version);
                
                System::log('system', "Migration $version applied successfully", [
                    'description' => $migration['description'] ?? '',
                ]);
                
                return [
                    'success' => true,
                    'version' => $version,
                    'description' => $migration['description'] ?? '',
                ];
            } else {
                return [
                    'success' => false,
                    'version' => $version,
                    'error' => 'Migration returned false',
                ];
            }
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'version' => $version,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Get migration status
     * 
     * @return array Status of all migrations
     */
    public static function status(): array
    {
        $applied = self::getApplied();
        $available = self::getAvailable();
        
        $status = [];
        
        foreach ($available as $version) {
            $isApplied = isset($applied[$version]);
            $status[$version] = [
                'version' => $version,
                'applied' => $isApplied,
                'applied_at' => $isApplied ? ($applied[$version]['applied_at'] ?? null) : null,
            ];
        }
        
        // Sort by version
        uksort($status, 'version_compare');
        
        return $status;
    }
    
    /**
     * Get pending migrations
     * 
     * @return array Versions that need to be applied
     */
    public static function pending(): array
    {
        $applied = self::getApplied();
        $available = self::getAvailable();
        
        $pending = array_diff($available, array_keys($applied));
        
        // Sort by version
        usort($pending, 'version_compare');
        
        return $pending;
    }
    
    /**
     * Get available migration versions
     * 
     * @return array Version strings
     */
    public static function getAvailable(): array
    {
        $dir = System::path('storage') . '/' . self::$migrationsDir;
        
        if (!is_dir($dir)) {
            return [];
        }
        
        $files = glob($dir . '/*.php');
        if (!$files) {
            return [];
        }
        
        $versions = [];
        foreach ($files as $file) {
            $version = basename($file, '.php');
            if (preg_match('/^\d+\.\d+\.\d+$/', $version)) {
                $versions[] = $version;
            }
        }
        
        // Sort by version
        usort($versions, 'version_compare');
        
        return $versions;
    }
    
    /**
     * Get applied migrations
     * 
     * @return array Applied migrations with metadata
     */
    public static function getApplied(): array
    {
        if (self::$applied !== null) {
            return self::$applied;
        }
        
        $file = System::path('storage') . '/' . self::$storageFile;
        
        if (!file_exists($file)) {
            self::$applied = [];
            return self::$applied;
        }
        
        self::$applied = require $file;
        
        if (!is_array(self::$applied)) {
            self::$applied = [];
        }
        
        return self::$applied;
    }
    
    /**
     * Mark a migration as applied
     * 
     * @param string $version Version to mark
     * @return bool Success
     */
    private static function markApplied(string $version): bool
    {
        $applied = self::getApplied();
        
        $applied[$version] = [
            'applied_at' => date('c'),
        ];
        
        self::$applied = $applied;
        
        return self::saveApplied();
    }
    
    /**
     * Save applied migrations to storage
     * 
     * @return bool Success
     */
    private static function saveApplied(): bool
    {
        $file = System::path('storage') . '/' . self::$storageFile;
        $dir = dirname($file);
        
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        $content = "<?php\n\n// Applied migrations - DO NOT EDIT MANUALLY\n\nreturn " . var_export(self::$applied, true) . ";\n";
        
        return file_put_contents($file, $content, LOCK_EX) !== false;
    }
    
    /**
     * Clear applied migrations cache
     * 
     * @return void
     */
    public static function clearCache(): void
    {
        self::$applied = null;
    }
    
    /**
     * Create a new migration file
     * 
     * @param string $version Version string (e.g., '1.3.0')
     * @param string $description Migration description
     * @return string|false File path or false on failure
     */
    public static function create(string $version, string $description = ''): string|false
    {
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            return false;
        }
        
        $dir = System::path('storage') . '/' . self::$migrationsDir;
        
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        $file = $dir . '/' . $version . '.php';
        
        if (file_exists($file)) {
            return false; // Already exists
        }
        
        $content = "<?php
/**
 * Migration: $version
 * Description: $description
 * Created: " . date('Y-m-d H:i:s') . "
 */

return [
    'version' => '$version',
    'description' => '$description',
    
    /**
     * Run the migration
     */
    'up' => function() {
        // Migration code here
        return true;
    },
    
    /**
     * Reverse the migration
     */
    'down' => function() {
        // Rollback code here
        return true;
    },
];
";
        
        if (file_put_contents($file, $content, LOCK_EX) !== false) {
            return $file;
        }
        
        return false;
    }
}

/* RULES
 * - Purpose: Version-based system migrations
 * - Config sources: None (uses storage files)
 * - Paths: Uses System::path('storage') + relative paths
 * - Logs: runtime/logs/system.log via System::log()
 * - Prohibitions: No ../, no hardcoded paths, always validate version format
 */
