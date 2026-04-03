<?php
declare(strict_types=1);

/**
 * Pattern Engine Module — Cron Tasks
 *
 * CronManager auto-discovers this file.
 * Handler method 'execute' is called on PatternEngineService instance.
 *
 * Tasks:
 *   pattern_engine:execute — run full pattern detection pipeline (every 2 minutes)
 */
return [
    'execute' => [
        'interval'    => 120, // 2 minutes
        'enabled'     => true,
        'description' => 'Pattern Engine: run full detection pipeline (detectors → signals → scenarios)',
    ],
];
