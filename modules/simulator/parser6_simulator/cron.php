<?php
declare(strict_types=1);

/**
 * Parser 6: Simulator — Cron Tasks
 *
 * CronManager auto-discovers this file.
 * Handler is called as Parser6SimulatorService->execute()
 */
return [
    'execute' => [
        'interval' => 60,
        'enabled' => true,
        'description' => 'Simulate Parser5 signals using Parser2 NDJSON price stream',
    ],
];

/* RULES
- Metadata only
- No executable logic
- Uses Parser2 NDJSON, NOT Bybit API
*/
