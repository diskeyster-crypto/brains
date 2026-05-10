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

    'recovery_window_minutes' => 'int',
    'recovery_min_window_minutes' => 'int',
    'recovery_max_window_minutes' => 'int',

    'prior_decline_lookback_minutes' => 'int',
    'min_prior_decline_pct' => 'float',

    'min_recovery_growth_pct' => 'float',
    'min_recovery_score' => 'float',
    'max_recovery_growth_pct' => 'float',

    'open_interest_enabled' => 'bool',
    'min_open_interest_growth_pct' => 'float',
    'min_open_interest_growth_score' => 'float',
    'allow_missing_open_interest' => 'bool',
    'missing_open_interest_mode' => 'string',

    'current_acceleration_window_minutes' => 'int',

    'filter_engine_enabled' => 'bool',
    'filter_enforcement_mode' => 'string',
    'filter_profile' => 'string',
    'filter_profile_active' => 'string',
    'enabled_filters' => 'array',
    'disabled_filters' => 'array',
    'filter_config' => 'array',

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
