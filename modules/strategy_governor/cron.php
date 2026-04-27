<?php

declare(strict_types=1);

/**
 * Strategy Governor Module — Cron Tasks
 *
 * CronManager auto-discovers this file.
 * tick: observe strategy signals, active positions, closed trades; write
 * shadow-only recommended decisions.  Never blocks orders.
 */

return [
    'tick' => [
        'interval'    => 60,
        'enabled'     => true,
        'description' => 'Observe strategy signals, queues, active positions and closed trades. '
            . 'Write shadow-only recommended routing decisions. V1 does not block any orders.',
    ],
];
