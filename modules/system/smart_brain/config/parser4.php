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
        ],
        'mode' => 'one',  // 'one' | 'any' | 'all'
    ],

    'analyzer_decision' => [
        'enabled' => true,
        'threshold' => 0.65,
        'weights' => [
            'pattern_confidence' => 0.40,
            'trend_match_score' => 0.20,
            'corridor_fit_score' => 0.20,
            'entry_quality_score' => 0.20,
        ],
    ],
];
