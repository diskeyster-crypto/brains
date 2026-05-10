<?php

declare(strict_types=1);

return [
    'strategy_id' => 'early_impulse_growth_long',
    'enabled' => false,
    'handoff_enabled' => false,
    'mode' => 'passive',
    'side' => 'long',

    // rotating universe scan
    'batch_size' => 100,
    'max_symbols_per_run' => 100,
    'continuous_scan_enabled' => true,
    'auto_requeue_when_done' => true,

    // core raw-impulse logic (only 3 strategy conditions)
    'impulse_window_minutes' => 10,
    'impulse_min_window_minutes' => 5,
    'impulse_max_window_minutes' => 10,

    'min_price_impulse_pct' => 0.4,
    'max_price_impulse_pct' => 4.0,
    'min_price_impulse_score' => 0.55,

    'open_interest_enabled' => true,
    'min_open_interest_growth_pct' => 1.0,
    'min_open_interest_growth_score' => 0.55,
    'allow_missing_open_interest' => true,
    'missing_open_interest_mode' => 'diagnostic_only',

    // filter engine integration (strategy-owned profile)
    'filter_engine_enabled' => true,
    'filter_enforcement_mode' => 'diagnostic_only', // diagnostic_only | soft | strict
    'filter_profile' => 'early_impulse_growth_long_default',
    'enabled_filters' => [],
    'disabled_filters' => [],

    // optional strategy-local filter defaults (all disabled by default)
    'eig_filter_point3_terminal_break_filter_enabled' => false,
    'eig_filter_low_quality_without_obc_filter_enabled' => false,
    'eig_filter_missing_reclaim_filter_enabled' => false,
    'eig_filter_late_local_entry_filter_enabled' => false,
    'eig_filter_tiny_room_filter_enabled' => false,
    'eig_filter_daily_extension_filter_enabled' => false,
    'eig_filter_whipsaw_filter_enabled' => false,

    // data source / safety knobs
    'parser2_history_lookback_minutes' => 180,
    'max_data_staleness_seconds' => 180,
    'bybit_base_url' => 'https://api.bybit.com',
    'bybit_timeout_sec' => 6,
    'bybit_kline_limit' => 120,
    'bybit_oi_interval' => '5min',
    'bybit_oi_limit' => 2,

    // storage caps
    'max_candidates_store' => 2000,
    'max_rejects_store' => 2000,
    'max_signals_store' => 1000,
];
