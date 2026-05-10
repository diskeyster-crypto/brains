<?php

declare(strict_types=1);

return [
    'raw_no_filters' => [
        'title' => 'Raw / no filters',
        'description' => 'Reference raw-strategy preset. All discovered filters are copied into active config in disabled state.',
        'filter_config' => [],
    ],
    'diagnostic_defaults' => [
        'title' => 'Diagnostic defaults',
        'description' => 'Keeps filters disabled by default but preserves standard severities and editable values for review.',
        'filter_config' => [],
    ],
    'strict_review_template' => [
        'title' => 'Strict review template',
        'description' => 'Reference preset that enables a few structural filters while keeping tuning values strategy-local.',
        'filter_config' => [
            'point3_terminal_break_filter' => [
                'enabled' => true,
                'severity' => 'fatal',
            ],
            'daily_extension_filter' => [
                'enabled' => true,
                'severity' => 'hard_block',
                'hot_pct' => 35.0,
                'position_in_range_max_pct' => 80.0,
            ],
            'whipsaw_filter' => [
                'enabled' => true,
                'severity' => 'hard_block',
                'max_10m_range_roi' => 15.0,
                'max_60m_direction_flips' => 10,
                'requires_weak_quality' => true,
                'weak_quality_max_score' => 0.72,
            ],
        ],
    ],
];
