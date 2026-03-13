<?php
/**
 * Migration: 1.1.0
 * Description: Add config registry and environment system
 * Created: 2026-01-25
 */

return [
    'version' => '1.1.0',
    'description' => 'Add config registry and environment system',
    
    /**
     * Run the migration
     */
    'up' => function() {
        // Create cache directory for config
        $cacheDir = \Core\System\System::path('cache');
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        return true;
    },
    
    /**
     * Reverse the migration
     */
    'down' => function() {
        // Remove config cache
        $cacheFile = \Core\System\System::path('cache') . '/config.php';
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
        
        return true;
    },
];
