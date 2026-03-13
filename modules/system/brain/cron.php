<?php
declare(strict_types=1);

/**
 * Brain Module — Cron Tasks
 *
 * CronManager auto-discovers this file.
 * Handler methods are called on BrainService instance.
 * 
 * Brain cron tasks:
 * 1. execute - Lightweight maintenance (passports, feedback, overrides)
 * 2. runPipeline - Full orchestration pipeline (Parser5→Simulator→Executor)
 */
return [
    'execute' => [
        'interval' => 120, // 5 minutes
        'enabled' => true,
        'description' => 'Recalculate passports, update overrides, apply feedback learning',
    ],
    'runPipeline' => [
        'interval' => 120, // 5 minutes - same as manual "Run" button
        'enabled' => true,
        'description' => 'Full orchestration pipeline: Parser5→Brain→Simulator→Executor',
    ],
];

/* RULES
- Metadata only
- No executable logic
- BrainService handles all processing
*/
