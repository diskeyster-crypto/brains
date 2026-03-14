<?php
declare(strict_types=1);

return [
    'mode' => 'manual',
    'settings' => [
        'enabled' => true,
        'risk_levels' => [
            ['max_corridor_width' => 0.008, 'leverage' => 5, 'budget_factor' => 0.80],
            ['max_corridor_width' => 0.020, 'leverage' => 4, 'budget_factor' => 0.60],
            ['max_corridor_width' => 0.040, 'leverage' => 3, 'budget_factor' => 0.45],
            ['max_corridor_width' => 1.000, 'leverage' => 2, 'budget_factor' => 0.30],
        ],
        'default_stop_loss_percent' => 0.04,
    ],
    'auto_rules' => [
        'allow_brain_override' => false,
        'override_fields' => [],
    ],
    'user_limits' => [
        'max_budget_per_coin' => 10.0,
        'max_active_tasks' => 3,
        'max_leverage' => 5,
        'brain_mode' => 'safe',
        'bootstrap_enabled' => true,
        'bootstrap_max_signals' => 2,
        'bootstrap_budget_factor' => 0.25,
        'bootstrap_max_leverage' => 2,
        'min_reliability_after_warmup' => 0.22,
        'warmup_min_trades' => 12,
        'exit_mode' => 'trailing_tp',
        'stop_floor_type' => 'corridor_percent',
        'stop_floor_value' => 0.35,
        'brain_may_tighten_stop' => true,
        'trailing_enabled' => true,
        'trailing_activation_roi' => 0.018,
        'trailing_min_lock_roi' => 0.004,
        'trailing_min_step' => 0.004,
        'brain_may_delay_trailing' => true,
        'fixed_take_profit_roi' => 0.025,
        'hybrid_tp_share' => 0.40,
        'break_even_enabled' => true,
        'break_even_activation_roi' => 0.008,
    ],
];
