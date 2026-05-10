<?php

declare(strict_types=1);

namespace Modules\FilterEngine\Filters;

use Modules\FilterEngine\FilterResult;

final class Point3TerminalBreakFilter
{
    public function id(): string { return 'point3_terminal_break_filter'; }

    public function evaluate(array $signalContext, array $filterConfig): FilterResult
    {
        $enabled = (bool)($filterConfig['enabled'] ?? true);
        if (!$enabled) {
            return new FilterResult($this->id(), false, true);
        }

        $reasons = [];
        if ((bool)($signalContext['point3_break_terminal'] ?? false) || (string)($signalContext['point3_break_final_state'] ?? '') === 'terminal') {
            $reasons[] = 'terminal_point3_broken';
        }
        if ((bool)($signalContext['fresh_lower_low_after_point3'] ?? false)) {
            $reasons[] = 'fresh_lower_low_after_point3';
        }
        if ((string)($signalContext['reclaim_loss_final_state'] ?? '') === 'terminal' || (bool)($signalContext['reclaim_level_lost'] ?? false)) {
            $reasons[] = 'reclaim_lost_terminal';
        }
        if (($signalContext['dbl_trace_complete'] ?? true) === false) {
            $reasons[] = 'dbl_trace_incomplete';
        }
        if ((bool)($signalContext['confirmation_ttl_expired'] ?? false)
            || (string)($signalContext['confirmed_pattern_invalid_reason'] ?? '') === 'confirmation_ttl_expired') {
            $reasons[] = 'confirmed_pattern_ttl_expired';
        }
        if (((bool)($signalContext['ob_ask_wall_risk'] ?? false)) && !((bool)($signalContext['ob_soft_demoted'] ?? false))) {
            $reasons[] = 'hard_obc_ask_wall_risk';
        }

        if ($reasons === []) {
            return new FilterResult($this->id(), true, true);
        }

        return new FilterResult(
            $this->id(),
            true,
            false,
            'fatal',
            $reasons[0],
            ['fatal_reasons' => $reasons],
            true,
            true
        );
    }
}
