<?php
declare(strict_types=1);

/**
 * Parser 2: History Accumulator — Cron definition
 */

return [
    // CronManager contract: module_id:handler
    'execute' => [
        'enabled' => true,
        // Every minute
        'rule' => '*/1 * * * *',
        'title' => 'Parser2 History Accumulator',
        'description' => 'Fetches Bybit public tickers and appends compact history snapshots for active symbols.',
    ],
];

/* RULES
- CronManager reads this file to register tasks
- Handler name must match service method: Parser2HistoryAccumulatorService::execute()
*/
