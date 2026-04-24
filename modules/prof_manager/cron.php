<?php

declare(strict_types=1);

/**
 * Profit Manager Module — Cron Tasks
 *
 * CronManager auto-discovers this file.
 * tick: read active bot positions, apply profit-lock logic, persist state.
 */

return [
    'tick' => [
        'interval'    => 60,
        'enabled'     => true,
        'description' => 'Read active bot positions, apply step-trailing profit-lock logic, persist planned lock state (paper only).',
    ],
];
