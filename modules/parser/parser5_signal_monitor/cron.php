<?php
declare(strict_types=1);

/**
 * Parser 5: Signal Monitor Engine — Cron Tasks
 *
 * CronManager auto-discovers this file.
 * Handler is called as Parser5SignalMonitorService->execute()
 */
return [
    'execute' => [
        'interval' => 60,
        'enabled' => true,
        'description' => 'Monitor candidates, check entry conditions, publish signals for Executor',
    ],
];

/* RULES
- Metadata only
- No executable logic
*/
