<?php
declare(strict_types=1);

return [
    'mode' => 'manual',
    'settings' => [
        'enabled' => true,
        'risk_levels' => [
            ['max_corridor_width' => 0.01, 'leverage' => 15, 'budget_factor' => 1.00],
            ['max_corridor_width' => 0.03, 'leverage' => 10, 'budget_factor' => 0.80],
            ['max_corridor_width' => 0.06, 'leverage' => 5,  'budget_factor' => 0.60],
            ['max_corridor_width' => 1.00, 'leverage' => 3,  'budget_factor' => 0.40],
        ],
        'default_stop_loss_percent' => 0.05,
    ],
    'auto_rules' => [
        'allow_brain_override' => false,
        'override_fields' => [],
    ],
    // User-editable hard limits (Stable Config Refactor)
    'user_limits' => [
        'max_budget_per_coin' => 20.0,
        'max_active_tasks' => 10,
        'max_leverage' => 15,
        'brain_mode' => 'balanced',              // safe | balanced | aggressive
        'bootstrap_enabled' => true,
        'bootstrap_max_signals' => 5,
        'bootstrap_budget_factor' => 0.50,
        'bootstrap_max_leverage' => 3,
        'min_reliability_after_warmup' => 0.15,
        'warmup_min_trades' => 10,
        // Exit Policy
        'exit_mode' => 'fixed_tp',               // fixed_tp | trailing_tp | hybrid
        'stop_floor_type' => 'roi_percent',       // roi_percent | corridor_percent
        'stop_floor_value' => 0.03,
        'brain_may_tighten_stop' => true,
        'trailing_enabled' => false,
        'trailing_activation_roi' => 0.02,
        'trailing_min_lock_roi' => 0.005,
        'trailing_min_step' => 0.005,
        'brain_may_delay_trailing' => false,
        'fixed_take_profit_roi' => 0.05,
        'hybrid_tp_share' => 0.5,
        // Exit Safety
        'max_trade_duration_minutes' => 1440,
        'stale_trade_exit_enabled' => false,
        'break_even_enabled' => false,
        'break_even_activation_roi' => 0.01,
    ],
];
