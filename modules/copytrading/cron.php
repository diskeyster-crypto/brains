<?php
declare(strict_types=1);

/**
 * Copytrading Parser — Cron Tasks
 *
 * CronManager auto-discovers this file.
 * Handler is called as CopytradingService->execute()
 */
return [
    'execute' => [
        'interval' => 1800, // 30 minutes
        'enabled' => true,
        'description' => 'Fetch trader positions and history from Bybit copytrading',
    ],
];

/* RULES
- Metadata only
- No executable logic
*/
