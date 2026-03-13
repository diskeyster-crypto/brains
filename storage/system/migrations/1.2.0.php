<?php
/**
 * Migration: 1.2.0
 * Description: Add event system and global error handler
 * Created: 2026-01-25
 */

return [
    'version' => '1.2.0',
    'description' => 'Add event system and global error handler',
    
    /**
     * Run the migration
     */
    'up' => function() {
        // Register default system events
        // Events are registered at runtime, nothing to persist
        
        // Ensure logs directory exists for error handler
        $logsDir = \Core\System\System::path('logs');
        if (!is_dir($logsDir)) {
            mkdir($logsDir, 0755, true);
        }
        
        return true;
    },
    
    /**
     * Reverse the migration
     */
    'down' => function() {
        // Nothing to reverse
        return true;
    },
];
