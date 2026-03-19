<?php
declare(strict_types=1);

return [
    'mode' => 'manual',
    'settings' => [
        'default_profile' => '111',
        'profiles' => [
            '111' => [
                'budget' => 10.0,
                'max_leverage' => 5,
                'stop_loss_range' => 0.35,
                'slippage_bps' => 20,
                'trailing_activate_roi' => 0.03,
                'take_profit_roi' => 0.85,
            ],
        ],
    ],
    'auto_rules' => [
        'allow_brain_override' => false,
        'override_fields' => [],
    ],
];
