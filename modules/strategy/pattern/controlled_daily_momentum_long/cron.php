<?php

declare(strict_types=1);

/**
 * Controlled Daily Momentum Long — Cron Tasks
 *
 * CronManager auto-discovers this file.
 * tickRun: execute one full scan cycle (respects enabled flag in config).
 */

return [
    'tickRun' => [
        'interval'    => 60,
        'enabled'     => true,
        'description' => 'Controlled Daily Momentum Long — run one scan cycle (enabled guard applies)',
    ],
];
