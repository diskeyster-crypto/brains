<?php

declare(strict_types=1);

/**
 * Parser 6: Simulator — Configuration
 *
 * LIVE Trading Simulator that fetches prices directly from Bybit API.
 * Per ТЗ spec: Parser6 is a LIVE ENGINE, NOT dependent on Parser2 history.
 *
 * RULES (Tredercopis Architecture):
 * - CONFIG FIRST / ZERO-HARDCODE
 * - No physical paths inside config
 * - Inter-parser sources must be SystemPaths keys
 */
return [
    'enabled' => true,

    // -------------------------------------------------------
    // INPUT SOURCES (SystemPaths keys)
    // -------------------------------------------------------
    'sources' => [
        'signals_key'   => 'system.brain.storage',
        'signals_file'  => 'signals.json',
        // Note: history_key is kept for backward compatibility but 
        // the simulator now uses LIVE Bybit API for prices
        'history_key'   => 'parser.parser2_history_accumulator.storage',
    ],

    // -------------------------------------------------------
    // LIVE PRICE SOURCE (per ТЗ spec - Bybit API)
    // -------------------------------------------------------
    'live_price' => [
        // Per ТЗ: "Только Bybit API: v5/market/tickers или v5/market/kline interval=1"
        'source' => 'bybit',
        'endpoint' => 'market.tickers',  // v5/market/tickers
        'category' => 'linear',          // perpetual futures
        'cache_seconds' => 5,            // Price cache TTL
    ],

    // -------------------------------------------------------
    // TICK FORMAT ADAPTER (per ТЗ section 7)
    // Configurable key mappings for Parser2 NDJSON format
    // -------------------------------------------------------
    'tick_format' => [
        // Keys to try for timestamp extraction (first match wins)
        'ts_keys' => ['ts', 't', 'time', 'timestamp', 'ts_ms'],
        // Keys to try for price extraction (first match wins)
        'price_keys' => ['price', 'last', 'close', 'p', 'lastPrice', 'markPrice'],
    ],

    // -------------------------------------------------------
    // RISK MANAGEMENT
    // C2.1: Risk parameters are ONLY sourced from Brain Risk Profile
    // Parser6 does NOT have its own risk defaults - Brain is single source of truth
    // Legacy settings kept for backward compatibility - NOT used in new code
    // -------------------------------------------------------
    'risk' => [
        '_LEGACY_DO_NOT_USE' => true,
        '_NOTE' => 'C2.1: All risk parameters come from Brain Risk Profile. Parser6 does not define defaults.',
        'budget_per_coin_usdt' => 20.0,      // LEGACY - NOT USED
        'leverage' => 15,                     // LEGACY - NOT USED
        'max_open_trades' => 0,               // LEGACY - Brain controls this
        'one_trade_per_symbol' => true,       // LEGACY - Brain controls this
        'slippage_pct' => 0.0005,             // LEGACY - NOT USED
    ],

    // -------------------------------------------------------
    // C2.1: risk_defaults REMOVED per ТЗ
    // Brain Risk Profile is the single source of truth for all risk parameters.
    // Parser6 reads risk from:
    // - CLEAN mode: signal.risk (Brain enriched)
    // - RAW mode: brain/storage/runtime/risk_active.json or profiles
    // -------------------------------------------------------

    // -------------------------------------------------------
    // EXECUTION PARAMETERS
    // -------------------------------------------------------
    'execution' => [
        'strict_entry' => true,          // true: wait for entry price touch. false: open immediately
        'entry_timeout_minutes' => 10,   // if strict_entry and no touch -> reject signal (reject_entry_not_reached)
        'max_duration_minutes' => 1440,  // 24 hours max trade duration after opening (0 = unlimited)
        'fee_taker_pct' => 0.0006,
        'fee_maker_pct' => 0.0002,
    ],

    // -------------------------------------------------------
    // TRAILING STOP CONFIGURATION
    // -------------------------------------------------------
    'trailing' => [
        'enabled' => true,
        'activation_profit_pct' => 0.02, // enable trailing when price moved >2% from entry
        'trail_distance_pct' => 0.01,    // trailing stop at 1% distance
        'trailing_replaces_sl' => true,
    ],

    // -------------------------------------------------------
    // TRADE POLICY
    // -------------------------------------------------------
    'policy' => [
        'allow_tp' => true,
        'allow_sl' => true,
        'allow_trailing' => true,
        'close_on_expire' => true,       // if signal expired and trade active -> close at current price
    ],

    // -------------------------------------------------------
    // DATASET GENERATION (for ML training)
    // -------------------------------------------------------
    'dataset' => [
        'enabled' => true,
        'save_features' => true,
        'features_window_minutes' => 30,
    ],

    // -------------------------------------------------------
    // RAW vs CLEAN COMPARISON MODE
    // Allows comparing signals before/after Brain gateway processing
    // -------------------------------------------------------
    'comparison' => [
        'enabled' => true,             // Enable comparison mode (safe default: disabled)
        'default_mode' => 'clean',      // clean|raw - default mode if run_both=false
        'run_both' => true,            // If true, one execute() runs both raw and clean (safe default: disabled)

        // Signal sources for each mode (SystemPaths keys)
        'signals' => [
            'clean_signals_key' => 'system.brain.storage',                  // brain/storage/signals.json
            'clean_signals_file' => 'signals.json',
            'raw_signals_key' => 'parser.parser5_signal_monitor.storage',   // parser5/storage/signals.json
            'raw_signals_file' => 'signals.json',
        ],

        // Storage subdirectories for each mode
        'storage' => [
            'base_key' => 'simulator.parser6_simulator.storage',  // Main module storage
            'raw_dir' => 'raw',                                   // storage/raw/
            'clean_dir' => 'clean',                               // storage/clean/
        ],
    ],

    // -------------------------------------------------------
    // OUTPUT PATHS (relative to module storage)
    // -------------------------------------------------------
    'output' => [
        'state' => 'storage/state.json',
        'last_run' => 'storage/last_run.json',
        'errors' => 'storage/errors.json',
        'executed_index' => 'storage/executed_index.json',

        'trades_active_dir' => 'storage/trades/active',
        'trades_closed_dir' => 'storage/trades/closed',

        'dataset_dir' => 'storage/dataset',
        'stats_dir' => 'storage/stats',
        'log' => 'storage/logs/simulator.log',
    ],

    // -------------------------------------------------------
    // STEP 6: BRAIN MANAGEMENT - apply commands from Brain
    // Brain can adjust trailing.drawdown_factor of active trades
    // -------------------------------------------------------
    'management' => [
        'enabled' => true,
        'commands_storage_key' => 'system.brain.storage',    // Brain storage key
        'commands_filename' => 'management/commands.json',    // Commands file from Brain
    ],

    // -------------------------------------------------------
    // UI FIELDS FOR PARSER MANAGER
    // -------------------------------------------------------
    'ui' => [
        'fields' => [
            ['key' => 'parser', 'label' => 'PARSER', 'type' => 'parser_name', 'source' => ['_title', '_module']],
            ['key' => 'status', 'label' => 'STATUS', 'type' => 'status_badge', 'source' => ['_enabled', 'ok']],
            ['key' => 'last_run', 'label' => 'LAST RUN', 'type' => 'datetime', 'source' => ['ts']],
            ['key' => 'duration', 'label' => 'DURATION', 'type' => 'duration_ms', 'source' => ['duration_ms']],
            ['key' => 'open', 'label' => 'OPEN', 'type' => 'count_success', 'source' => ['open_trades']],
            ['key' => 'closed', 'label' => 'CLOSED', 'type' => 'count', 'source' => ['closed_trades']],
            ['key' => 'winrate', 'label' => 'WINRATE', 'type' => 'percent', 'source' => ['winrate']],
            ['key' => 'roi_sum', 'label' => 'ROI SUM', 'type' => 'percent', 'source' => ['roi_sum']],
        ],
    ],
];
