<?php
declare(strict_types=1);

return [
    'mode' => 'manual',
    'settings' => [
        'enabled' => true,
        'max_leverage' => 15,
        'max_budget_per_coin' => 20.0,
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
];
