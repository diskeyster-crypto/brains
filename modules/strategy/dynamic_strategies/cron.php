<?php

declare(strict_types=1);

/**
 * Dynamic Strategies — Cron Tasks
 *
 * CronManager auto-discovers this file.
 * tickRun: execute one full evaluation cycle (respects enabled flag in config).
 */

return [
    'tickRun' => [
        'interval'    => 60,
        'enabled'     => true,
        'description' => 'Dynamic Strategies — run one evaluation cycle (enabled guard applies)',
    ],
];
