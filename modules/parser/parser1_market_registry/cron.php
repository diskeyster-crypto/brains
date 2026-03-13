<?php
declare(strict_types=1);

/**
 * Parser 1: Market Registry — Cron Tasks
 *
 * CronManager auto-discovers this file.
 * Handler is called as Parser1MarketRegistryService->execute()
 */
return [
    'execute' => [
        'interval' => 5400, // 3 hours
        'enabled' => true,
        'description' => 'Fetch Bybit instruments registry and rebuild active market registry (delist-filtered)',
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
