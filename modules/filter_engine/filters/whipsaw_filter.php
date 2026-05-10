<?php

declare(strict_types=1);

namespace Modules\FilterEngine\Filters;

use Modules\FilterEngine\FilterResult;

final class WhipsawFilter
{
    public function id(): string { return 'whipsaw_filter'; }

    public function evaluate(array $signalContext, array $filterConfig): FilterResult
    {
        $enabled = (bool)($filterConfig['enabled'] ?? true);
        if (!$enabled) {
            return new FilterResult($this->id(), false, true);
        }

        $range10m = (float)($signalContext['micro_range_roi_10m'] ?? $signalContext['range_roi_10m'] ?? 0.0);
        $flips60m = (int)($signalContext['direction_flips_60m'] ?? $signalContext['micro_direction_flips_60m'] ?? 0);
        $quality  = (float)($signalContext['candidate_quality_score'] ?? 0.0);

        $maxRange = (float)($filterConfig['max_10m_range_roi'] ?? 15.0);
        $maxFlips = (int)($filterConfig['max_60m_direction_flips'] ?? 10);
        $requiresWeakQuality = (bool)($filterConfig['requires_weak_quality'] ?? true);
        $weakQualityMax = (float)($filterConfig['weak_quality_max_score'] ?? 0.72);
        $weakQualityHit = !$requiresWeakQuality || $quality <= $weakQualityMax;

        $hit = $weakQualityHit && ($range10m >= $maxRange || $flips60m >= $maxFlips);
        if (!$hit) {
            return new FilterResult($this->id(), true, true);
        }

        $severity = (string)($filterConfig['severity'] ?? 'hard_block');
        return new FilterResult(
            $this->id(),
            true,
            false,
            $severity,
            'whipsaw_filter',
            ['micro_range_roi_10m' => $range10m, 'direction_flips_60m' => $flips60m],
            in_array($severity, ['soft_block', 'hard_block', 'fatal'], true),
            $severity === 'fatal'
        );
    }
}
