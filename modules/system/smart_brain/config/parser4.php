<?php
declare(strict_types=1);

return [
    'mode' => 'manual',
    'settings' => [
        'enabled' => true,
        'profiles_key' => 'parser.parser3_manager_behavior.storage',
        'history_key' => 'parser.parser2_history_accumulator.storage',
        'corridor_window_minutes' => 180,
        'min_history_points' => 80,
        'strength_threshold' => 0.80,
        'max_candidates' => 120,
    ],
    'auto_rules' => [
        'allow_brain_override' => false,
        'override_fields' => [],
    ],
];
