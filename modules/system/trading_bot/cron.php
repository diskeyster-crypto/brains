<?php
declare(strict_types=1);

/**
 * Trading Bot Module — Cron Tasks
 *
 * CronManager auto-discovers this file.
 * Handler methods are called on TradingBotService instance.
 * 
 * Trading Bot cron tasks:
 * 1. execute - Main execution loop (reconcile, load intents, execute, safety)
 */
return [
    'execute' => [
        'interval' => 60, // 1 minute
        'enabled' => true,
        'priority' => 40,  // Run after Pattern Engine (priority 80) in the same cron tick
        'description' => 'Main execution loop: reconcile, load intents, execute, safety checks',
    ],
];

/* RULES
- Metadata only
- No executable logic
- TradingBotService handles all processing
*/
