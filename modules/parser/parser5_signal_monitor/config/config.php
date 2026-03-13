<?php

return [
    'enabled' => true,
    'modes' => [
        'instant_signal' => [
            'enabled' => true,
            'abs_return_threshold' => 0.012,
            'min_score' => 0.25,
            'window_minutes_fallback' => 2,
        ],
        'monitor' => [
            'enabled' => true,
            'min_checks' => 2,
            'confirmations_required' => 2,
        ],
    ],
    'sources' => [
        'candidates_storage_key' => 'parser.parser4_analyzer.storage',
        'candidates_filename' => 'candidates.json',
        'history_storage_key' => 'parser.parser2_history_accumulator.storage',
    ],
    'monitor' => [
        'max_monitored' => 50,
        'monitor_ttl_minutes' => 60,
        'min_score' => 0.01,
        'resync_interval' => 5,
    ],
    'entry' => [
        'min_price_move_pct' => 0.0025,
        'max_drawdown_pct' => 0.01,
        'min_monitor_time' => 1,
        'max_monitor_time' => 30,
        'confirmation_count' => 2,
    ],
    'signal' => [
        'max_active_signals' => 20,
        'validity_minutes' => 30,
        'take_profit_pct' => 0.02,
        'stop_loss_pct' => 0.01,
        'min_risk_reward' => 1.5,
    ],
    'output' => [
        'signals' => 'storage/signals.json',
        'monitor' => 'storage/monitor.json',
        'last_run' => 'storage/last_run.json',
        'history_dir' => 'storage/history',
        'log' => 'logs/signal_monitor.log',
    ],
    'logging' => [
        'max_log_size_mb' => 10,
        'debug' => false,
    ],
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
                'key' => 'monitored',
                'label' => 'MONITORED',
                'type' => 'count',
                'source' => ['monitored_count'],
            ],
            [
                'key' => 'signals',
                'label' => 'SIGNALS',
                'type' => 'count_success',
                'source' => ['signals_published'],
            ],
            [
                'key' => 'expired',
                'label' => 'EXPIRED',
                'type' => 'count_danger',
                'source' => ['expired_count'],
            ],
        ],
    ],
];
