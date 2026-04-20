<?php

declare(strict_types=1);

/**
 * Fish Strategy — Cron Tasks
 *
 * CronManager auto-discovers this file.
 * The handler method is called on FishService (see mod_class.txt for the
 * fully-qualified class name used by CronManager).
 *
 * tickBatch:
 *   - run_status = queued  → build universe, transition to running, process first batch
 *   - run_status = running → process next batch_size symbols and save progress
 *   - run_status = done | failed | idle → no-op, returns immediately
 *
 * Interval is intentionally short (60 s) so large all-universe runs complete
 * within a few minutes even for hundreds of symbols.
 * If a batch finishes before the time limit the run continues on the next tick.
 */

return [
    'tickBatch' => [
        'interval'    => 60,
        'enabled'     => true,
        'description' => 'Advance Fish smoke-test run by one batch (queued/running → progress → done)',
    ],
];
