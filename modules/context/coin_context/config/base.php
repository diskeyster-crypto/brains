<?php

declare(strict_types=1);

return [
    'enabled' => true,
    'trend_windows_minutes' => [60, 120, 240],
    'corridor_window_minutes' => 240,
    'flat_threshold_pct' => 0.5,
    'trend_threshold_pct' => 1.0,
    'spike_threshold_pct_10m' => 2.0,
    'chaotic_max_direction_flips' => 8,
    'min_history_minutes' => 60,
];
