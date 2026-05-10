<?php

declare(strict_types=1);

namespace Modules\FilterEngine\Filters;

use Modules\FilterEngine\FilterResult;

final class WhipsawFilter
{
    public function id(): string { return 'whipsaw_filter'; }

    public function metadata(): array
    {
        return [
            'filter_id' => $this->id(),
            'title' => 'Whipsaw / noisy tape',
            'description' => 'Flags weak-quality environments with excessive 10m range or too many 60m direction flips.',
            'default_severity' => 'hard_block',
            'configurable_fields' => [
                [
                    'key' => 'max_10m_range_roi',
                    'label' => 'Max 10m range ROI',
                    'type' => 'float',
                    'default' => 15.0,
                    'min' => 0.0,
                    'max' => 1000.0,
                    'help' => 'Whipsaw can trigger when the 10m range exceeds this ROI threshold.',
                ],
                [
                    'key' => 'max_60m_direction_flips',
                    'label' => 'Max 60m direction flips',
                    'type' => 'int',
                    'default' => 10,
                    'min' => 0,
                    'max' => 1000,
                    'help' => 'Maximum tolerated number of direction flips inside 60 minutes.',
                ],
                [
                    'key' => 'requires_weak_quality',
                    'label' => 'Require weak quality',
                    'type' => 'bool',
                    'default' => true,
                    'help' => 'Only trigger whipsaw when the candidate quality is weak enough.',
                ],
                [
                    'key' => 'weak_quality_max_score',
                    'label' => 'Weak quality max score',
                    'type' => 'float',
                    'default' => 0.72,
                    'min' => 0.0,
                    'max' => 1.0,
                    'help' => 'Candidates above this score are not considered weak quality for whipsaw detection.',
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
            'garbage_whipsaw_weak_quality',
            ['micro_range_roi_10m' => $range10m, 'direction_flips_60m' => $flips60m],
            in_array($severity, ['soft_block', 'hard_block', 'fatal'], true),
            $severity === 'fatal'
        );
    }
}
