<?php

declare(strict_types=1);

namespace Modules\Strategy\FilterEngine\Filters;

use Modules\Strategy\FilterEngine\FilterResult;

final class MissingReclaimFilter
{
    public function id(): string { return 'missing_reclaim_filter'; }

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
