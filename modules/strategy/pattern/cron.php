<?php

declare(strict_types=1);

/**
 * Pattern Strategy — Cron Tasks
 *
 * CronManager auto-discovers this file.
 * tickBatch: advance a queued/running universe scan by one batch.
 */

return [
    'tickBatch' => [
        'interval'    => 60,
        'enabled'     => true,
        'description' => 'Advance Pattern scan by one batch (queued/running → progress → done)',
    ],
];
