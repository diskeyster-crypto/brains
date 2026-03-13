<?php
declare(strict_types=1);

/**
 * Profit Manager Module — Cron Tasks
 *
 * CronManager auto-discovers this file.
 * Handler methods are called on ProfitManagerService instance.
 * 
 * Profit Manager cron tasks:
 * 1. execute - Run profit management cycle (step trailing + dumb trailing)
 */
return [
    'execute' => [
        'interval' => 60, // 1 minute - trailing needs frequent checks
        'enabled' => true,
        'description' => 'Run profit management: step trailing (SL ratchet) and dumb trailing (armed/re-arm)',
    ],
];

/* RULES
- Metadata only
- No executable logic
- ProfitManagerService handles all processing
*/
