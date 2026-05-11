<?php

declare(strict_types=1);

/**
 * Early Impulse Growth Long — Active Config Overrides
 * Written by the admin UI. Edit via the config page.
 */

return [
    'enabled' => true,
    'handoff_enabled' => true,
    'emit_bot_handoff' => true,

    // Filter engine: quality guards enabled with soft enforcement
    'filter_engine_enabled' => true,
    'filter_enforcement_mode' => 'soft',
    'filter_profile' => 'quality_guards_enabled',
    'filter_profile_active' => 'quality_guards_enabled',

    'enabled_filters' => [
        'wave_quality_filter',
        'orderbook_wall_filter',
    ],

    'filter_config' => [
        'wave_quality_filter' => [
            'enabled' => true,
            'severity' => 'hard_block',
            'block_context_phases' => 'chaotic,spike,downtrend',
            'block_context_reasons' => 'too_many_direction_flips,downward_trend_confirmed,spike_threshold_10m',
            'block_trend_1h_directions' => 'chaotic,down',
            'require_trend_2h_confirmation' => true,
            'allow_unknown_context' => true,
        ],
        'orderbook_wall_filter' => [
            'enabled' => true,
            'severity' => 'hard_block',
            'max_ask_wall_distance_pct' => 0.8,
            'min_ask_wall_notional' => 20000.0,
            'min_ask_wall_strength_score' => 0.60,
            'require_bid_support' => false,
            'min_bid_support_score' => 0.35,
            'min_bid_ask_ratio' => 0.65,
            'allow_missing_orderbook' => true,
            'block_if_orderbook_missing' => false,
        ],
    ],
];
