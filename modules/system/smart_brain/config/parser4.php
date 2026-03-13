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
        'strength_threshold' => 0.50,
        'max_candidates' => 200,
    ],
    'auto_rules' => [
        'allow_brain_override' => false,
        'override_fields' => [],
    ],
];
