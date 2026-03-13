<?php
declare(strict_types=1);

/**
 * Delist Parser 0 Configuration
 * 
 * This module fetches new listings and delistings from Bybit announcements.
 * It does NOT analyze market data - only tracks listing/delisting events.
 * 
 * CONFIG FIRST / ZERO HARDCODE
 */
return [

    'enabled' => true,

    /* ======================================================
       SOURCES (Bybit Announcements API)
       ====================================================== */

    'sources' => [
        'module_key' => 'parser.delist_parser0',
    ],

    /* ======================================================
       BYBIT ANNOUNCEMENTS API
       ====================================================== */

    'bybit' => [
        // Primary: Announcements API
        'announcements_url' => 'https://api.bybit.com/v5/announcements/index',
        
        // Types to fetch
        'types' => [
            'new_crypto' => 'whitelist',    // new listings → whitelist
            'delistings' => 'blacklist',    // delistings → blacklist
        ],
        
        // Request params
        'locale' => 'ru-RU',
        'limit' => 20,           // announcements per page
        'max_pages' => 3,        // max pages to fetch per type
        
        // HTTP settings
        'timeout_sec' => 15,
        'max_bytes' => 2000000,
        'user_agent' => 'tredercopis-delist-parser0/1.0',
        
        'retry' => [
            'count' => 2,
            'sleep_ms' => 500,
        ],
    ],

    /* ======================================================
       FALLBACK: HTML SCRAPING (if API fails)
       ====================================================== */

    'fallback' => [
        'enabled' => true,
        'urls' => [
            'new_crypto' => 'https://announcements.bybit.com/ru-RU/?category=new_crypto',
            'delistings' => 'https://announcements.bybit.com/ru-RU/?category=delistings',
        ],
    ],

    /* ======================================================
       SYMBOL EXTRACTION
       ====================================================== */

    'extraction' => [
        // Regex to extract symbols from announcement titles
        // Matches: ZORAUSDT, DOGEUSDT, BTC1000USDT, etc.
        'symbol_regex' => '/([A-Z0-9]{2,}USDT)/i',
        
        // Minimum symbol length (excluding USDT suffix)
        'min_base_length' => 2,
        
        // Maximum symbol length (excluding USDT suffix)
        'max_base_length' => 20,
    ],

    /* ======================================================
       OUTPUT (INSIDE MODULE ONLY)
       ====================================================== */

    'output' => [
        'whitelist'  => 'storage/whitelist.json',
        'blacklist'  => 'storage/blacklist.json',
        'state'      => 'storage/state.json',
        'last_run'   => 'storage/last_run.json',
        'log'        => 'storage/logs/delist.log',
    ],

    /* ======================================================
       WRITE MODE
       ====================================================== */

    'write' => [
        'atomic' => true,
        'backup_before_write' => false,
    ],

    /* ======================================================
       UI CONTRACT (SCHEMA-DRIVEN)
       ====================================================== */

    'ui' => [
        'fields' => [
            [
                'key' => 'parser',
                'label' => 'PARSER',
                'type' => 'parser_name',
                'source' => ['_title', '_module'],
            ],
            [
                'key' => 'status',
                'label' => 'STATUS',
                'type' => 'status_badge',
                'source' => ['_enabled', 'ok'],
            ],
            [
                'key' => 'last_run',
                'label' => 'LAST RUN',
                'type' => 'datetime',
                'source' => ['ts'],
            ],
            [
                'key' => 'duration',
                'label' => 'DURATION',
                'type' => 'duration_ms',
                'source' => ['duration_ms'],
            ],
            [
                'key' => 'whitelist',
                'label' => 'WHITELIST',
                'type' => 'count_success',
                'source' => ['whitelist'],
            ],
            [
                'key' => 'blacklist',
                'label' => 'BLACKLIST',
                'type' => 'count_danger',
                'source' => ['blacklist'],
            ],
            [
                'key' => 'errors',
                'label' => 'ERRORS',
                'type' => 'count_warning',
                'source' => ['errors_count'],
            ],
        ],
    ],
];

/* RULES
- Purpose: Fetch new listings and delistings from Bybit
- Outputs: whitelist.json (new coins), blacklist.json (delisted coins)
- SystemPaths: parser.delist_parser0.storage
- Used by: Parser1, Parser3, Parser4, Executor, Pump Detector
- This module does NOT analyze market data
*/
