<?php
declare(strict_types=1);

return [
    'mode' => 'manual',
    'settings' => [
        'enabled' => true,
        'entry_zone_percent' => 0.20,
        'max_wait_minutes' => 60,
        'rebuild_interval_seconds' => 5,
    ],
    'auto_rules' => [
        'allow_brain_override' => false,
        'override_fields' => [],
    ],
];
