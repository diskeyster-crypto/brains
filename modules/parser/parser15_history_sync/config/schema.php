<?php
/**
 * Parser 1.5 History Sync - Config Schema
 */

return [
    'enabled' => [
        'type' => 'boolean',
        'required' => true,
        'default' => true,
    ],
    'sync' => [
        'type' => 'object',
        'properties' => [
            'interval_sec' => [
                'type' => 'integer',
                'min' => 60,
                'max' => 600,
                'default' => 120,
            ],
            'gap_threshold_sec' => [
                'type' => 'integer',
                'min' => 60,
                'max' => 600,
                'default' => 180,
            ],
            'max_backfill_minutes' => [
                'type' => 'integer',
                'min' => 10,
                'max' => 1440,
                'default' => 60,
            ],
        ],
    ],
    'bybit' => [
        'type' => 'object',
        'properties' => [
            'timeout_sec' => [
                'type' => 'integer',
                'min' => 5,
                'max' => 30,
                'default' => 10,
            ],
            'max_points_per_request' => [
                'type' => 'integer',
                'min' => 100,
                'max' => 1000,
                'default' => 1000,
            ],
        ],
    ],
];
