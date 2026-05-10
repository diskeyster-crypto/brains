<?php

declare(strict_types=1);

namespace Modules\FilterEngine\Filters;

use Modules\FilterEngine\FilterResult;

final class LowQualityWithoutObcFilter
{
    public function id(): string { return 'low_quality_without_obc_filter'; }

    public function evaluate(array $signalContext, array $filterConfig): FilterResult
    {
        $enabled = (bool)($filterConfig['enabled'] ?? true);
        if (!$enabled) {
            return new FilterResult($this->id(), false, true);
        }

        $quality = (float)($signalContext['candidate_quality_score'] ?? 0.0);
        $maxQ = (float)($filterConfig['max_score'] ?? 0.70);
        $obConfirmed = (bool)($signalContext['obc_quality_confirmed'] ?? false)
            || (bool)($signalContext['ob_bid_support'] ?? false)
            || (bool)($signalContext['ob_ask_eaten'] ?? false);
        $requireGeneric = (bool)($filterConfig['require_generic_warning'] ?? true);
        $hasGeneric = in_array('generic_entry_context_score_low', (array)($signalContext['final_low_quality_causes'] ?? []), true)
            || ((string)($signalContext['final_low_quality_primary_cause'] ?? '') === 'generic_entry_context_score_low');

        $hit = $quality <= $maxQ && !$obConfirmed && (!$requireGeneric || $hasGeneric);
        if (!$hit) {
            return new FilterResult($this->id(), true, true);
        }

        $severity = (string)($filterConfig['severity'] ?? 'soft_block');
        return new FilterResult(
            $this->id(),
            true,
            false,
            $severity,
            // reason is kept for human-readable diagnostics; filter_engine.php uses filter_id in aggregated lists
            'garbage_low_quality_without_obc_confirmation',
            ['candidate_quality_score' => $quality, 'obc_quality_confirmed' => $obConfirmed],
            in_array($severity, ['soft_block', 'hard_block', 'fatal'], true),
            $severity === 'fatal'
        );
    }
}
