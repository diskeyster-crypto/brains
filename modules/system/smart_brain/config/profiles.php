<?php
declare(strict_types=1);

return [
    'mode' => 'manual',
    'settings' => [
        'default_profile' => '111',
        'profiles' => [
            '111' => [
                'budget' => 15.0,
                'max_leverage' => 5,
                'stop_loss_range' => 0.20,
                'slippage_bps' => 20,
                'trailing_activate_roi' => 0.06,
                'take_profit_roi' => 5.55,
            ],
        ],
    ],
    'auto_rules' => [
        'allow_brain_override' => false,
        'override_fields' => [],
    ],
];
