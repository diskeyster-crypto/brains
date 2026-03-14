<?php
declare(strict_types=1);

return [
    'mode' => 'manual',
    'settings' => [
        'enabled' => true,
        'entry_zone_percent' => 0.15,
        'max_wait_minutes' => 90,
        'rebuild_interval_seconds' => 10,
    ],
    'auto_rules' => [
        'allow_brain_override' => false,
        'override_fields' => [],
    ],
];
