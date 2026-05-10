<?php

declare(strict_types=1);

namespace Modules\FilterEngine\Filters;

use Modules\FilterEngine\FilterResult;

final class DailyExtensionFilter
{
    public function id(): string { return 'daily_extension_filter'; }

    public function metadata(): array
    {
        return [
            'filter_id' => $this->id(),
            'title' => 'Daily extension',
            'description' => 'Blocks overly extended entries that are already hot versus the daily range.',
            'default_severity' => 'hard_block',
            'configurable_fields' => [
                [
                    'key' => 'hot_pct',
                    'label' => 'Daily extension hot threshold (%)',
                    'type' => 'float',
                    'default' => 35.0,
                    'min' => 0.0,
                    'max' => 1000.0,
                    'help' => 'Extension above this percentage is considered hot.',
                ],
                [
                    'key' => 'position_in_range_max_pct',
                    'label' => 'Max position in day range (%)',
                    'type' => 'float',
                    'default' => 80.0,
                    'min' => 0.0,
                    'max' => 100.0,
                    'help' => 'Triggers once price is already this high inside the daily range.',
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

        $dailyExt = (float)($signalContext['daily_extension_pct']
            ?? $signalContext['intraday_extension_from_daily_low_pct']
            ?? 0.0);
        $rangePos = (float)($signalContext['position_in_day_range_pct']
            ?? $signalContext['position_in_range_pct']
            ?? 0.0);

        $hotPct = (float)($filterConfig['hot_pct'] ?? 35.0);
        $rangeMax = (float)($filterConfig['position_in_range_max_pct'] ?? 80.0);

        $hit = $dailyExt >= $hotPct && $rangePos >= $rangeMax;
        if (!$hit) {
            return new FilterResult($this->id(), true, true);
        }

        $severity = (string)($filterConfig['severity'] ?? 'hard_block');
        return new FilterResult(
            $this->id(),
            true,
            false,
            $severity,
            'garbage_late_daily_extension_long',
            ['daily_extension_pct' => $dailyExt, 'position_in_range_pct' => $rangePos],
            in_array($severity, ['soft_block', 'hard_block', 'fatal'], true),
            $severity === 'fatal'
        );
    }
}
