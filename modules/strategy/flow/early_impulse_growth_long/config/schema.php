<?php

declare(strict_types=1);

return [
    'strategy_id' => 'string',
    'enabled' => 'bool',
    'handoff_enabled' => 'bool',
    'mode' => 'string',
    'side' => 'string',

    'batch_size' => 'int',
    'max_symbols_per_run' => 'int',
    'continuous_scan_enabled' => 'bool',
    'auto_requeue_when_done' => 'bool',

    'impulse_window_minutes' => 'int',
    'impulse_min_window_minutes' => 'int',
    'impulse_max_window_minutes' => 'int',

    'min_price_impulse_pct' => 'float',
    'max_price_impulse_pct' => 'float',
    'min_price_impulse_score' => 'float',

    'open_interest_enabled' => 'bool',
    'min_open_interest_growth_pct' => 'float',
    'min_open_interest_growth_score' => 'float',
    'allow_missing_open_interest' => 'bool',
    'missing_open_interest_mode' => 'string',

    'filter_engine_enabled' => 'bool',
    'filter_enforcement_mode' => 'string',
    'filter_profile' => 'string',
    'enabled_filters' => 'array',
    'disabled_filters' => 'array',

    'eig_filter_point3_terminal_break_filter_enabled' => 'bool',
    'eig_filter_low_quality_without_obc_filter_enabled' => 'bool',
    'eig_filter_missing_reclaim_filter_enabled' => 'bool',
    'eig_filter_late_local_entry_filter_enabled' => 'bool',
    'eig_filter_tiny_room_filter_enabled' => 'bool',
    'eig_filter_daily_extension_filter_enabled' => 'bool',
    'eig_filter_whipsaw_filter_enabled' => 'bool',

    'parser2_history_lookback_minutes' => 'int',
    'max_data_staleness_seconds' => 'int',
    'bybit_base_url' => 'string',
    'bybit_timeout_sec' => 'int',
    'bybit_kline_limit' => 'int',
    'bybit_oi_interval' => 'string',
    'bybit_oi_limit' => 'int',

    'max_candidates_store' => 'int',
    'max_rejects_store' => 'int',
    'max_signals_store' => 'int',
];
