<?php
declare(strict_types=1);

return [
    'mode' => 'manual',
    'settings' => [
        'enabled' => true,
        'risk_levels' => [
            ['max_corridor_width' => 0.010, 'leverage' => 6, 'budget_factor' => 0.90],
            ['max_corridor_width' => 0.025, 'leverage' => 5, 'budget_factor' => 0.75],
            ['max_corridor_width' => 0.050, 'leverage' => 4, 'budget_factor' => 0.60],
            ['max_corridor_width' => 1.000, 'leverage' => 3, 'budget_factor' => 0.45],
        ],
        'default_stop_loss_percent' => 0.05,
    ],
    'auto_rules' => [
        'allow_brain_override' => false,
        'override_fields' => [],
    ],
    'user_limits' => [
        'max_budget_per_coin' => 10.0,
        'max_active_tasks' => 5,
        'max_leverage' => 6,
        'brain_mode' => 'balanced',
        'bootstrap_enabled' => true,
        'bootstrap_max_signals' => 8,
        'bootstrap_budget_factor' => 0.35,
        'bootstrap_max_leverage' => 3,
        'min_reliability_after_warmup' => 0.12,
        'warmup_min_trades' => 6,
        'exit_mode' => 'trailing_tp',
        'stop_floor_type' => 'corridor_percent',
        'stop_floor_value' => 0.30,
        'brain_may_tighten_stop' => true,
        'trailing_enabled' => true,
        'trailing_activation_roi' => 0.04,
        'trailing_min_lock_roi' => 0.01,
        'trailing_min_step' => 0.0075,
        'brain_may_delay_trailing' => true,
        'fixed_take_profit_roi' => 0.03,
        'hybrid_tp_share' => 0.40,
        'break_even_enabled' => true,
        'break_even_activation_roi' => 0.02,
        // Stop Loss Engine V2
        'stop_mode' => 'brain_managed',
        'simple_stop_liq_factor' => 0.15,
        'brain_stop_corridor_factor' => 0.25,
        'brain_stop_volatility_factor' => 0.50,
        'brain_stop_liq_safety_factor' => 0.30,
        // Early Failure Guard
        'early_failure_enabled' => true,
        'early_failure_window_minutes' => 5,
        'early_failure_max_adverse_roi' => -0.008,
    ],
];
