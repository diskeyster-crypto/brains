<?php

declare(strict_types=1);

namespace Modules\FilterEngine\Filters;

use Modules\FilterEngine\FilterResult;

final class TinyRoomFilter
{
    public function id(): string { return 'tiny_room_filter'; }

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
            // reason is kept for human-readable diagnostics; filter_engine.php uses filter_id in aggregated lists
            'insufficient_room_to_recent_swing_high',
            ['room_to_recent_swing_high_roi' => $room],
            in_array($severity, ['soft_block', 'hard_block', 'fatal'], true),
            $severity === 'fatal'
        );
    }
}
