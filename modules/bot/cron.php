<?php

declare(strict_types=1);

/**
 * Bot Module — Cron Tasks
 *
 * CronManager auto-discovers this file.
 * tick: ingest strategy handoff queue, update bot order queue.
 */

return [
    'tick' => [
        'interval'    => 60,
        'enabled'     => true,
        'description' => 'Ingest double_bottom_long handoff queue, refresh bot order queue lifecycle state.',
    ],
];
