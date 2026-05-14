<?php

declare(strict_types=1);

/**
 * Stop Manager Module — Active Config Overrides
 * Written by the admin UI. Edit via the config page.
 */

return [
    'enabled' => true,
    'mode'    => 'paper',
    'profiles' => [
        'long' => [
            'enabled'                => true,
            'emergency_stop_enabled' => true,
            'emergency_stop_roi'     => -15.0,
            'min_age_seconds'        => 60,
            'applies_to_strategies'  => ['double_bottom_long', '*'],
        ],
        'short' => [
            'enabled'                => true,
            'emergency_stop_enabled' => true,
            'emergency_stop_roi'     => -20.0,
            'min_age_seconds'        => 60,
            'applies_to_strategies'  => ['dynamic_strategies'],
            'close_reason'           => 'short_emergency_roi_cap',
            'close_guard'            => 'short_emergency_stop',
        ],
    ],
];
