<?php
declare(strict_types=1);

/**
 * Parser 1: Market Registry
 * CONFIG FIRST / ZERO HARDCODE
 * Backward compatible + SystemPaths
 */
return [

    'enabled' => true,

    /* ======================================================
       CATEGORIES / FILTERS
       ====================================================== */

    'categories' => [
        'linear',
    ],

    'quote_only' => [
        'USDT',
    ],

    'exclude_symbols' => [
        'BTCUSDT',
        'ETHUSDT',
    ],

    'exclude_bases' => [
        'BTC',
        'ETH',
    ],

    'include_regex' => '',

    'exclude_regexes' => [],

    /* ======================================================
       DELIST FILTER (DUAL MODE)
       ====================================================== */

    'use_delist_filter' => true,

    // NEW MODE (SystemPaths — РЕАЛЬНЫЙ KEY ИЗ PACKMAP)
    'sources' => [
        'delist_key'  => 'parser.delist_parser0.storage',
        'delist_file' => 'blacklist.json',
    ],

    // LEGACY MODE (обратная совместимость)
    'delist_source' => 'modules/delist_parser0/storage/blacklist.json',

    /* ======================================================
       BYBIT API
       ====================================================== */

    'bybit' => [
        'base_url' => 'https://api.bybit.com',
        'endpoint_instruments' => '/v5/market/instruments-info',
        'timeout_sec' => 20,
        'connect_timeout_sec' => 10,
        'max_bytes' => 8000000,
        'user_agent' => 'tredercopis-parser1-registry/1.0',
        'retry' => [
            'count' => 1,
            'sleep_ms' => 300,
        ],
        'max_pages_per_category' => 50,
    ],

    /* ======================================================
       OUTPUT (INSIDE MODULE ONLY)
       ====================================================== */

    'output' => [
        'registry' => 'storage/registry.json',
        'active'   => 'storage/active.json',
        'delisted' => 'storage/delisted.json',
        'state'    => 'storage/state.json',
        'last_run' => 'storage/last_run.json',
        'log'      => 'storage/logs/registry.log',
    ],

    /* ======================================================
       WRITE MODE
       ====================================================== */

    'write' => [
        'atomic' => true,
        'backup_before_write' => false,
        'backup_suffix' => '.bak',
    ],

   /* ======================================================
   UI CONTRACT (SCHEMA-DRIVEN) — NEW SERVICE
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
            'key' => 'total',
            'label' => 'TOTAL',
            'type' => 'count',
            'source' => ['raw'],            // NEW
        ],

        [
            'key' => 'after_delist',
            'label' => 'AFTER DELIST',
            'type' => 'count_success',
            'source' => ['afterDelist'],      // NEW
        ],

        [
            'key' => 'filtered',
            'label' => 'FILTERED',
            'type' => 'count_danger',
            'source' => ['filtered'],         // совпадает
        ],

        [
            'key' => 'active',
            'label' => 'ACTIVE',
            'type' => 'count_success',
            'source' => ['active_symbols'],           // совпадает
        ],
    ],
    
     ],
];
/* RULES
- SystemPaths используется при наличии sources.delist_key
- Ключ должен СУЩЕСТВОВАТЬ в PackMap
- Если ключа нет → fallback на delist_source
- Output только внутри модуля
- CONFIG FIRST / ZERO HARDCODE
*/
