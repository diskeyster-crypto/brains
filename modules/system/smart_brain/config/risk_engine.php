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
    ],
];
