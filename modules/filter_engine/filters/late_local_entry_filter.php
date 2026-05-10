<?php

declare(strict_types=1);

namespace Modules\FilterEngine\Filters;

use Modules\FilterEngine\FilterResult;

final class LateLocalEntryFilter
{
    public function id(): string { return 'late_local_entry_filter'; }

    public function metadata(): array
    {
        return [
            'filter_id' => $this->id(),
            'title' => 'Late local entry',
            'description' => 'Flags entries that are too far from the local recovery point or that have too little room to the next swing high.',
            'default_severity' => 'soft_block',
            'configurable_fields' => [
                [
                    'key' => 'max_distance_from_point3_pct',
                    'label' => 'Max distance from point3 (%)',
                    'type' => 'float',
                    'default' => 2.5,
                    'min' => 0.0,
                    'max' => 100.0,
                    'help' => 'Late-entry detection starts once entry distance exceeds this threshold.',
                ],
                [
                    'key' => 'min_room_to_recent_high_roi',
                    'label' => 'Min room to recent high (ROI)',
                    'type' => 'float',
                    'default' => 3.0,
                    'min' => 0.0,
                    'max' => 100.0,
                    'help' => 'If remaining upside room is below this threshold, the late-entry filter can trigger.',
                ],
            ],
        ];
    }

    public function evaluate(array $signalContext, array $filterConfig): FilterResult
    {
        $enabled = (bool)($filterConfig['enabled'] ?? true);
        if (!$enabled) {
            return new FilterResult($this->id(), false, true);
        }

        $distance = (float)($signalContext['entry_distance_from_point3_pct'] ?? 0.0);
        $room     = (float)($signalContext['room_to_recent_swing_high_roi'] ?? 999.0);
        $maxDist  = (float)($filterConfig['max_distance_from_point3_pct'] ?? 2.5);
        $minRoom  = (float)($filterConfig['min_room_to_recent_high_roi'] ?? 3.0);
        $detected = (bool)($signalContext['local_late_tiny_room_detected'] ?? false)
            || ($distance > $maxDist && $room < $minRoom);

        if (!$detected) {
            return new FilterResult($this->id(), true, true);
        }

        $severity = (string)($filterConfig['severity'] ?? 'soft_block');
        return new FilterResult(
            $this->id(),
            true,
            false,
            $severity,
            'garbage_local_late_entry_after_recovery',
            ['entry_distance_from_point3_pct' => $distance, 'room_to_recent_swing_high_roi' => $room],
            in_array($severity, ['soft_block', 'hard_block', 'fatal'], true),
            $severity === 'fatal'
        );
    }
}
