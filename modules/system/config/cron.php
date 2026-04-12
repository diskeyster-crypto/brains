<?php
declare(strict_types=1);

/**
 * Unified Config Module — Cron Tasks
 *
 * Runs a periodic extraction pass to refresh audit artefacts.
 * The extraction is read-only and does not affect other modules.
 */
return [
    'extract' => [
        'interval'    => 300,  // every 5 minutes
        'enabled'     => true,
        'priority'    => 20,   // low priority — runs after core modules
        'description' => 'Extract and refresh unified config audit artefacts (shadow, read-only)',
    ],
];

/* RULES
- This cron task ONLY calls UnifiedConfigService::extract()
- It MUST NOT mutate other modules' config files
- It MUST NOT affect Smart Brain / Trading Bot / PM / Coin Passport runtime
*/
