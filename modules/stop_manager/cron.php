<?php

declare(strict_types=1);

/**
 * Stop Manager Module — Cron Tasks
 *
 * CronManager auto-discovers this file.
 * tick: read bot active positions, compute/update local stop state.
 */

return [
    'tick' => [
        'interval'    => 60,
        'enabled'     => true,
        'description' => 'Read active bot positions, compute stop prices using liq_distance_percent mode, apply breakeven rules, persist local stop state.',
    ],
];
