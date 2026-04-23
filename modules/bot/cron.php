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
        'description' => 'Discover strategy modules, apply operator overrides, ingest enabled handoff queues, refresh bot order queue lifecycle state.',
    ],
];
