<?php
declare(strict_types=1);

/**
 * Executor Bot — Config
 *
 * Executes trading signals produced by Parser 5 (Signal Monitor).
 *
 * CONFIG FIRST / ZERO HARDCODE
 * SystemPaths ONLY (no absolute filesystem paths)
 *
 * @return array<string,mixed>
 */
return [
    /* ======================================================
       CORE
       ====================================================== */

    'module' => [
        'enabled' => true,

        // live | dry
        // dry = does not send orders to Bybit, writes only logs/results
        'mode' => 'dry',

        // Bybit account id from central key center (Core\Bybit::client())
        'account_id' => 'default',

        // Category for Bybit V5 instruments/orders
        'category' => 'linear',
    ],

    /* ======================================================
       INPUTS (SystemPaths)
       ====================================================== */

    'sources' => [
        // SystemPaths key for THIS module base dir
        'module_key' => 'trading.executor_bot',

        // Parser 5 signals storage
        'signals_key'  => 'parser.parser5_signal_monitor.storage',
        'signals_file' => 'signals.json',
    ],

    /* ======================================================
       EXECUTION LIMITS / RISK
       ====================================================== */

    'limits' => [
        // Maximum signals to process per run
        'max_signals_per_run' => 3,

        // Ignore signals with score below this threshold (0..1)
        'min_score' => 0.0,

        // Skip if signal.expires_at <= now (unix timestamp)
        'enforce_expires_at' => true,

        // Blocklist symbols (manual)
        'blocked_symbols' => [],

        // Budget per position in USDT (used for qty estimation)
        'budget_per_position_usd' => 8.0,

        // Leverage hint (used for qty estimation)
        'leverage' => 10,
    ],

    /* ======================================================
       ORDER BUILDING
       ====================================================== */

    'order' => [
        // market | limit
        'type' => 'market',

        // if limit: percent offset from entry_price (e.g. 0.001 = 0.1%)
        'limit_offset' => 0.0,

        // set TP/SL after opening (best effort)
        'set_tp_sl' => true,

        // reduce-only close orders are not handled here
    ],

    /* ======================================================
       STORAGE (relative to module base)
       ====================================================== */

    'output' => [
        // runtime files
        'state'     => 'storage/state.json',
        'last_run'  => 'storage/last_run.json',
        'errors'    => 'storage/errors.json',

        // execution indexes
        'executed'  => 'storage/executed.json',   // {signal_id: {ts, status, ...}}
        'orders'    => 'storage/orders',          // per-signal order responses
        'logs_dir'  => 'storage/logs',
        'log_file'  => 'storage/logs/executor.log',
    ],

    /* ======================================================
       WRITE SAFETY
       ====================================================== */

    'write' => [
        'atomic' => true,
        'backup_before_write' => false,
        'backup_suffix' => '.bak',
    ],

    /* ======================================================
       UI CONTRACT (Parser table)
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
                'key' => 'signals',
                'label' => 'SIGNALS',
                'type' => 'count',
                'source' => ['signals_seen'],
            ],
            [
                'key' => 'processed',
                'label' => 'PROCESSED',
                'type' => 'count_success',
                'source' => ['signals_processed'],
            ],
            [
                'key' => 'opened',
                'label' => 'OPENED',
                'type' => 'count_success',
                'source' => ['opened_ok'],
            ],
            [
                'key' => 'failed',
                'label' => 'FAILED',
                'type' => 'count_danger',
                'source' => ['failed_orders'],
            ],
        ],
    ],
];

/* RULES
- SystemPaths ONLY (no absolute paths like /var/www..., /config/..., etc.)
- Reads signals ONLY from Parser5 via SystemPaths key sources.signals_key
- Writes ONLY inside this module storage/
- UI reads ONLY config['ui']['fields']
- CONFIG FIRST / ZERO HARDCODE
*/
