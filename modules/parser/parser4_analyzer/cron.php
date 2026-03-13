<?php
declare(strict_types=1);

/**
 * Parser 4: Analyzer — Cron Tasks
 *
 * CronManager auto-discovers this file.
 * Handler is called as Parser4AnalyzerService->execute()
 */
return [
    'execute' => [
        'interval' => 60,
        'enabled' => true,
        'description' => 'Analyze Parser2 history, calculate rolling metrics, output blocklist/watchlist/candidates',
    ],
];

/* RULES
- Metadata only
- No executable logic
*/
