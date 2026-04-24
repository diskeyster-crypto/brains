<?php

declare(strict_types=1);

return [
    'enabled'                  => false,
    'mode'                     => 'demo',
    'active_profile'           => 'legacy_safe',
    'paper_position_ttl_hours' => 6,
    'profiles'       => [
        'legacy_safe' => [
            'init_roi'               => 2.0,
            'activation_roi'         => 10.0,
            'step_roi'               => 3.0,
            'lock_buffer_roi'        => 2.0,
            'lock_floor_roi'         => 5.0,
            'min_update_interval_sec'=> 30,
            'min_price_distance_pct' => 0.15,
            'min_roi_step'           => 1.0,
            'max_updates_per_run'    => 20,
            'default_tick_size'      => 0.0001,
        ],
    ],
];
