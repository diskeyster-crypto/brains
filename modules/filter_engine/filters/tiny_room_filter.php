<?php

declare(strict_types=1);

namespace Modules\FilterEngine\Filters;

use Modules\FilterEngine\FilterResult;

final class TinyRoomFilter
{
    public function id(): string { return 'tiny_room_filter'; }

    public function metadata(): array
    {
        return [
            'filter_id' => $this->id(),
            'title' => 'Tiny room to recent high',
            'description' => 'Warns when the remaining room to the recent swing high is too small.',
            'default_severity' => 'warning',
            'configurable_fields' => [
                [
                    'key' => 'min_room_roi',
                    'label' => 'Min room ROI',
                    'type' => 'float',
                    'default' => 2.0,
                    'min' => 0.0,
                    'max' => 100.0,
                    'help' => 'Signals with less available room than this value will trigger the filter.',
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

        $room = (float)($signalContext['room_to_recent_swing_high_roi'] ?? 999.0);
        $minRoom = (float)($filterConfig['min_room_roi'] ?? 2.0);
        if ($room >= $minRoom) {
            return new FilterResult($this->id(), true, true);
        }

        $severity = (string)($filterConfig['severity'] ?? 'warning');
        return new FilterResult(
            $this->id(),
            true,
            false,
            $severity,
            'insufficient_room_to_recent_swing_high',
            ['room_to_recent_swing_high_roi' => $room],
            in_array($severity, ['soft_block', 'hard_block', 'fatal'], true),
            $severity === 'fatal'
        );
    }
}
