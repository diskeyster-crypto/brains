<?php
/**
 * Cron Task Registry
 * 
 * Central registry of all scheduled tasks in the system.
 * Each task defines its handler file, schedule, and enabled status.
 * 
 * Schedule format: Integer seconds between runs (e.g., 60 = every minute)
 * 
 * @package Storage\Cron
 */

return [
    // Example task - demonstrates the format
    'example:execute' => [
        'handler' => 'modules/example/runner.php',
        'schedule' => '60', // Every 60 seconds
        'enabled' => true,
        'description' => 'Example module cron task',
    ],
];

/* RULES
- Purpose: Central registry of all cron tasks
- Config sources: This file IS the config
- Paths: Handler paths are relative to project root
- Logs: CronManager logs to runtime/logs/cron.log
- Prohibitions:
  - No PHP code execution here (return array only)
  - Handler files must exist
  - Schedule must be valid integer
*/
