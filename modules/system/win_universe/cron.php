<?php
declare(strict_types=1);

/**
 * Win Universe Module — Cron Tasks
 *
 * CronManager auto-discovers this file.
 * Handler methods are called on WinUniverseService instance.
 *
 * Tasks:
 *   win_universe:run — compute win universe and persist shadow outputs (every 1 h)
 */
return [
    'run' => [
        'interval'    => 3600, // 1 hour
        'enabled'     => true,
        'description' => 'Win Universe: compute winning coin universe from recent trade results (shadow mode — no effect on trading)',
    ],
];
