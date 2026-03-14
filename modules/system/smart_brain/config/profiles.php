<?php
declare(strict_types=1);

return [
    'mode' => 'manual',
    'settings' => [
        'default_profile' => '111',
        'profiles' => [
            '111' => [
                'budget' => 10.0,
                'max_leverage' => 4,
                'stop_loss_range' => 0.30,
                'slippage_bps' => 20,
                'trailing_activate_roi' => 0.018,
                'take_profit_roi' => 1.25,
            ],
        ],
    ],
    'auto_rules' => [
        'allow_brain_override' => false,
        'override_fields' => [],
    ],
];
