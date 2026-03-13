<?php
declare(strict_types=1);

/**
 * Parser 1.5: History Sync — Cron definition
 * 
 * CronManager auto-discovers this file.
 * Handler is called as Parser15HistorySyncService->execute()
 */

return [
    // CronManager contract: module_id:handler
    'execute' => [
        'enabled' => true,
        // Every 2 minutes
        'rule' => '*/2 * * * *',
        'interval' => 120,
        'title' => 'Parser 1.5 History Sync',
        'description' => 'Backfill and gap repair for Parser2 NDJSON history files via Bybit API.',
    ],
];

/* RULES
- CronManager reads this file to register tasks
- Handler name must match service method: Parser15HistorySyncService::execute()
*/
