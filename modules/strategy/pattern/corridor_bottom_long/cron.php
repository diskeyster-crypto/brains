<?php

declare(strict_types=1);

/**
 * Corridor Bottom Long — Cron Tasks
 *
 * CronManager auto-discovers this file.
 * tickRun: execute one full scan cycle (respects enabled flag in config).
 */

return [
    'tickRun' => [
        'interval'    => 60,
        'enabled'     => true,
        'description' => 'Corridor Bottom Long — run one scan cycle (enabled guard applies)',
    ],
];
