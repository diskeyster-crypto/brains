<?php
declare(strict_types=1);

return [
    'mode' => 'manual',
    'settings' => [
        'enabled' => true,
        'profiles_key' => 'parser.parser3_manager_behavior.storage',
        'history_key' => 'parser.parser2_history_accumulator.storage',
        'corridor_window_minutes' => 120,
        'min_history_points' => 40,
        'strength_threshold' => 0.40,
        'max_candidates' => 500,
    ],
    'auto_rules' => [
        'allow_brain_override' => false,
        'override_fields' => [],
    ],

    'pattern_algorithms' => [
        'enabled' => [
            'double_bottom',
            'double_top',
            'pullback_trend_continue',
        ],
        'mode' => 'any',  // 'one' | 'any' | 'all'
    ],

    'analyzer_decision' => [
        'enabled' => true,
        'threshold' => 0.45,
        'weights' => [
            'pattern_confidence' => 0.50,
            'trend_match_score' => 0.20,
            'corridor_fit_score' => 0.10,
            'entry_quality_score' => 0.20,
        ],
    ],
];
