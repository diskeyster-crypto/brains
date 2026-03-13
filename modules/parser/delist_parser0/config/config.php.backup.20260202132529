<?php
declare(strict_types=1);

/**
 * Delist Parser 0
 * CONFIG FIRST / ZERO HARDCODE
 * SystemPaths + Backward Compatible
 */
return [

    'enabled' => true,

    /* ======================================================
       FETCH MODE
       ====================================================== */

    'fetch' => [

        // РЕЖИМ:
        // instruments = новый (Bybit v5 instruments-info)
        // api/html     = legacy (announcements)
        'mode' => 'instruments',

        // legacy (не используется в instruments, но оставлено)
        'api_base_url' => 'https://api.bybit.com/v5/announcements/index',
        'locale' => 'ru-RU',
        'types' => [
            'listing' => 'new_crypto',
            'delist'  => 'delistings',
        ],
        'page' => 2,
        'limit' => 20,
        'timeout_sec' => 12,
        'connect_timeout_sec' => 6,
        'max_bytes' => 2000000,
        'user_agent' => 'tredercopis-delister/2.1',
        'retry' => [
            'count' => 1,
            'sleep_ms' => 250,
        ],

        // legacy html
        'sources' => [
            'https://announcements.bybit.com/ru-RU/?category=new_crypto&page=1',
            'https://announcements.bybit.com/ru-RU/?category=delistings&page=1',
        ],
    ],

    /* ======================================================
       SYSTEMPATHS (NEW MODE)
       ====================================================== */

    'sources' => [
        // ключ модуля Parser0 (для moduleBase)
        'module_key' => 'parser.parser0_delist',
    ],

    /* ======================================================
       FILTERS
       ====================================================== */

    'filters' => [
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
        'only_usdt' => true,
    ],

    /* ======================================================
       OUTPUT (INSIDE MODULE ONLY)
       ====================================================== */

    'output' => [
        'whitelist' => 'storage/whitelist.json',
        'blacklist' => 'storage/blacklist.json',
        'state'     => 'storage/state.json',
        'last_run'  => 'storage/last_run.json',
        'raw_dir'   => 'storage/raw',
        'log'       => 'storage/logs/parser0.log',
    ],

    /* ======================================================
       DEBUG
       ====================================================== */

    'debug' => [
        'save_raw' => true,
        'raw_keep_last' => 3,
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
                'key' => 'new',
                'label' => 'NEW',
                'type' => 'count_success',
                'source' => ['whitelist'],
            ],

            [
                'key' => 'delist',
                'label' => 'DELIST',
                'type' => 'count_danger',
                'source' => ['blacklist'],
            ],
        ],
    ],
];

/* RULES
- SystemPaths ONLY для путей
- mode=instruments = основной режим
- api/html оставлены только для backward compatibility
- UI читает ТОЛЬКО config['ui']['fields']
- Output только внутри модуля
- CONFIG FIRST / ZERO HARDCODE
*/
