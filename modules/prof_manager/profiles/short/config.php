<?php

declare(strict_types=1);

/**
 * Short Profile — Baseline Config
 *
 * Baseline step-lock profit management for short positions.
 * Override via modules/prof_manager/config/active.php under profiles['short'].
 *
 * profile_name: baseline_short_lock
 *
 * Rationale for activation_roi=8 (vs long 10):
 *   Dynamic short entries are typically shorter-lived.
 *   Start protection earlier once +8 ROI is reached.
 */

return [
    'profile_name'            => 'baseline_short_lock',
    'side_supported'          => true,

    // Short baseline profit lock
    'init_roi'                => 2.0,
    'activation_roi'          => 8.0,
    'step_roi'                => 3.0,
    'lock_buffer_roi'         => 2.0,
    'lock_floor_roi'          => 4.0,
    'min_update_interval_sec' => 30,
    'min_price_distance_pct'  => 0.15,
    'min_roi_step'            => 1.0,
    'max_updates_per_run'     => 20,
    'default_tick_size'       => 0.0001,

    // Short hybrid disabled for now
    'hybrid_enabled'          => false,
];
