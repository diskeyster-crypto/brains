<?php

declare(strict_types=1);

namespace Modules\FilterEngine\Filters;

use Modules\FilterEngine\FilterResult;

final class MissingReclaimFilter
{
    public function id(): string { return 'missing_reclaim_filter'; }

    public function metadata(): array
    {
        return [
            'filter_id' => $this->id(),
            'title' => 'Missing reclaim confirmation',
            'description' => 'Warns or blocks when the setup has not reclaimed its key level yet.',
            'default_severity' => 'warning',
            'configurable_fields' => [],
        ];
    }

    public function evaluate(array $signalContext, array $filterConfig): FilterResult
    {
        $enabled = (bool)($filterConfig['enabled'] ?? true);
        if (!$enabled) {
            return new FilterResult($this->id(), false, true);
        }

        $reclaim = (bool)($signalContext['reclaim_confirmed'] ?? false)
            || (bool)($signalContext['neckline_reclaim_confirmed'] ?? false)
            || (bool)($signalContext['reclaim_after_flat_detected'] ?? false)
            || (bool)($signalContext['reclaim_retest_held'] ?? false);
        if ($reclaim) {
            return new FilterResult($this->id(), true, true);
        }

        $severity = (string)($filterConfig['severity'] ?? 'warning');
        return new FilterResult(
            $this->id(),
            true,
            false,
            $severity,
            'missing_reclaim_confirmation',
            ['reclaim_confirmed' => false],
            in_array($severity, ['soft_block', 'hard_block', 'fatal'], true),
            $severity === 'fatal'
        );
    }
}
