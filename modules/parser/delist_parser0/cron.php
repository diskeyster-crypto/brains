<?php
declare(strict_types=1);

/**
 * Delist Parser 0 — Cron Tasks
 *
 * CronManager auto-discovers this file.
 * Handler is called as DelistParser0Service->execute()
 */
return [
    'execute' => [
        'interval' => 3600, // 1 hour (delistings don't change frequently)
        'enabled' => true,
        'description' => 'Fetch new listings and delistings from Bybit announcements',
    ],
];

/* RULES
- Purpose: Declare cron handlers for this module
- Config sources: None (cron declaration only)
- Paths: None
- Logs: None
- Prohibitions:
  - No network or file operations here
*/
