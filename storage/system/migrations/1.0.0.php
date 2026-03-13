<?php
/**
 * Migration: 1.0.0
 * Description: Initial core infrastructure setup
 * Created: 2026-01-25
 */

return [
    'version' => '1.0.0',
    'description' => 'Initial core infrastructure setup',
    
    /**
     * Run the migration
     */
    'up' => function() {
        // Create required directories using proper path handling
        $storage = \Core\System\System::path('storage');
        $runtime = \Core\System\System::path('runtime');
        
        $dirs = [
            $storage . '/system',
            $storage . '/credentials',
            $storage . '/cron',
            $runtime . '/logs',
            $runtime . '/cache',
            $runtime . '/sessions',
        ];
        
        foreach ($dirs as $path) {
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }
        
        return true;
    },
    
    /**
     * Reverse the migration
     */
    'down' => function() {
        // Cannot reverse initial setup
        return true;
    },
];
