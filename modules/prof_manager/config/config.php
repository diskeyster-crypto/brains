<?php

declare(strict_types=1);

return [
    'enabled'                  => false,
    'mode'                     => 'demo',
    'active_profile'           => 'auto',
    'paper_position_ttl_hours' => 6,

    // ── Fast loop runner (public/cron/prof_manager_fast_loop.php) ─────────
    // ISP cron calls the endpoint once per minute; the loop internally runs
    // lightweight PM fast ticks every fast_loop_interval_seconds.
    'fast_loop_enabled'                            => true,
    'fast_loop_interval_seconds'                   => 15,
    'fast_loop_max_runtime_seconds'                => 55,
    'fast_loop_overlap_guard_seconds'              => 70,
    'fast_loop_max_ticks_per_run'                  => 4,

    // ── Fast tick controls ────────────────────────────────────────────────
    'fast_tick_enabled'                            => true,
    'fast_tick_allow_close'                        => true,
    'fast_tick_allow_lock_move'                    => true,
    'fast_tick_max_positions_per_run'              => 20,
    'fast_tick_min_lock_update_interval_seconds'   => 15,
    'fast_tick_min_close_retry_interval_seconds'   => 15,
    'parser_metrics_fast_cache_ttl_seconds'        => 60,
    'impulse_metrics_fast_cache_ttl_seconds'       => 60,
];
