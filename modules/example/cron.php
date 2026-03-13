<?php

declare(strict_types=1);

/**
 * Example Module - Cron Tasks
 * 
 * Return array of cron handlers this module provides.
 * CronManager will auto-discover this file.
 * 
 * Format:
 * 'handler_name' => [
 *     'interval' => seconds between runs,
 *     'enabled' => true/false (optional, default true),
 *     'description' => 'Task description' (optional)
 * ]
 * 
 * Handler is called as ExampleService->handler_name()
 */

return [
    'execute' => [
        'interval' => 60000000000,
        'enabled' => true,
        'description' => 'Example periodic task',
    ],
];
