<?php
declare(strict_types=1);

/**
 * Smart Brain Module — Cron Tasks
 *
 * CronManager auto-discovers this file.
 * Handler methods are called on SmartBrainService instance.
 */
return [
    'execute' => [
        'interval' => 120,
        'enabled' => true,
        'description' => 'Run Smart Brain cycle: Parser4 → Corridor → Risk → Simulator',
    ],
];
