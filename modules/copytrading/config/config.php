<?php
declare(strict_types=1);

/**
 * Copytrading Parser — Configuration
 *
 * @package Modules\Copytrading
 */
return [
    // Enable/disable module
    'enabled' => true,
    
    // Tracked traders (array of leaderMark IDs)
    // Example: ['1F9xX0kUmFX9Q0YUu4ma9g==', 'anotherTraderMarkId']
    'traders' => [],
    
    // Bybit API settings
    'api' => [
        'base_url' => 'https://www.bybit.com',
        'positions_endpoint' => '/x-api/fapi/beehive/public/v1/common/position/list',
        'history_endpoint' => '/x-api/fapi/beehive/public/v1/common/leader-history',
        'timeout' => 30,
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'request_delay' => 1.5, // Delay between requests in seconds (to avoid rate limiting)
        'max_retries' => 3, // Max retries per request
    ],
    
    // History pagination
    'history' => [
        'page_size' => 50,
        'max_pages' => 5, // Max pages to fetch per trader
    ],
    
    // Storage settings
    'storage' => [
        'activ_file' => 'storage/activ.json',
        'history_file' => 'storage/history.json',
        'last_run_file' => 'storage/last_run.json',
        'log_file' => 'storage/copytrading.log',
    ],
    
    // Logging
    'logging' => [
        'max_log_size' => 5242880, // 5MB
        'debug' => false,
    ],
];
