<?php
declare(strict_types=1);

/**
 * AI Shadow Module — Cron Tasks
 *
 * CronManager auto-discovers this file.
 * Handler method 'execute' is called on AiShadowService instance.
 *
 * Tasks:
 *   ai_shadow:execute — run live mirror cycle (secondary local evidence accumulation)
 */
return [
    'execute' => [
        'interval'    => 120, // 2 minutes
        'enabled'     => true,
        'description' => 'AI Shadow: run live mirror cycle for local evidence accumulation',
    ],
];
