<?php

declare(strict_types=1);

/**
 * Long Profile — Default Config
 *
 * Mapped from legacy_safe profile values (config migration: legacy_safe → long).
 * Override via modules/prof_manager/config/active.php under profiles['long'].
 *
 * profile_name: legacy_safe_long
 */

return [
    'profile_name'            => 'legacy_safe_long',
    'init_roi'                => 2.0,
    'activation_roi'          => 10.0,
    'step_roi'                => 3.0,
    'lock_buffer_roi'         => 2.0,
    'lock_floor_roi'          => 5.0,
    'min_update_interval_sec' => 30,
    'min_price_distance_pct'  => 0.15,
    'min_roi_step'            => 1.0,
    'max_updates_per_run'     => 20,
    'default_tick_size'       => 0.0001,
];
