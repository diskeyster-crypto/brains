<?php

declare(strict_types=1);

return [
    'profiles' => [
        'long' => [
            'init_roi'        => 2.0,
            'activation_roi'  => 5.0,
            'step_roi'        => 3.0,
            'lock_buffer_roi' => 2.0,
            'lock_floor_roi'  => 5.0,

            'roi_staircase_enabled'          => true,
            'roi_staircase_min_peak_roi'     => 5.0,
            'roi_staircase_base_floor_roi'   => 5.0,
            'roi_staircase_step_roi'         => 5.0,
            'roi_staircase_floor_buffer_roi' => 3.0,

            'hybrid_enabled'       => true,
            'hybrid_min_close_roi' => 5.0,

            'chop_exit_enabled'       => true,
            'chop_exit_min_peak_roi'  => 5.0,
            'chop_exit_min_close_roi' => 4.0,
            'chop_exit_require_profit'=> true,

            'trend_birth_hold_enabled'   => true,
            'trend_birth_min_peak_roi'   => 5.0,
            'trend_birth_min_current_roi'=> 4.0,
            'trend_birth_hard_floor_roi' => 3.0,

            'impulse_hold_min_roi' => 8.0,
        ],
    ],

    'pm_exchange_profit_floor_sync_enabled'        => true,
    'pm_exchange_profit_floor_min_roi'             => 5.0,
    'pm_exchange_profit_floor_buffer_roi'          => 2.0,
    'pm_exchange_profit_floor_min_improvement_roi' => 1.0,
];
