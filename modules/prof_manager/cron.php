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
        'description' => 'Calls ProfManagerService router. Routes long positions to legacy_safe_long profile (step-trailing lock planner). Short positions unsupported (stub).',
    ],
];
