<?php
declare(strict_types=1);

/**
 * Parser 3: Manager — Behavior Profiler — Cron Tasks
 *
 * CronManager auto-discovers this file.
 * Handler is called as Parser3ManagerBehaviorService->execute()
 */
return [
    'execute' => [
        'interval' => 80,
        'enabled' => true,
        'description' => 'Build behavior profiles + candidates (NO prices in output) from Parser2 RAW history',
    ],
];

/* RULES
- Metadata only
- No executable logic
*/
