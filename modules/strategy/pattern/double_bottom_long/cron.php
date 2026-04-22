<?php

declare(strict_types=1);

/**
 * Double Bottom Long Strategy — Cron Tasks
 *
 * CronManager auto-discovers this file.
 * tickBatch: advance a queued/running universe scan by one batch.
 */

return [
    'tickBatch' => [
        'interval'    => 60,
        'enabled'     => true,
        'description' => 'Advance Double Bottom Long scan by one batch (queued/running → progress → done)',
    ],
];
