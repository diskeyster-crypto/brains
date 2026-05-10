<?php

declare(strict_types=1);

namespace Modules\FilterEngine;

use Modules\FilterEngine\Filters\DailyExtensionFilter;
use Modules\FilterEngine\Filters\LateLocalEntryFilter;
use Modules\FilterEngine\Filters\LowQualityWithoutObcFilter;
use Modules\FilterEngine\Filters\MissingReclaimFilter;
use Modules\FilterEngine\Filters\Point3TerminalBreakFilter;
use Modules\FilterEngine\Filters\TinyRoomFilter;
use Modules\FilterEngine\Filters\WhipsawFilter;

final class FilterEngine
{
    /** @var array<int,object> */
    private array $filters;

    public function __construct()
    {
        $this->filters = [
            new Point3TerminalBreakFilter(),
            new LowQualityWithoutObcFilter(),
            new MissingReclaimFilter(),
            new LateLocalEntryFilter(),
            new TinyRoomFilter(),
            new DailyExtensionFilter(),
            new WhipsawFilter(),
        ];
    }

    public function evaluate(array $signalContext, array $filterConfig): array
    {
        $results = [];
        foreach ($this->filters as $filter) {
            $id = method_exists($filter, 'id') ? (string)$filter->id() : '';
            $cfg = $id !== '' && is_array($filterConfig[$id] ?? null) ? (array)$filterConfig[$id] : $filterConfig;
            $results[] = $filter->evaluate($signalContext, $cfg);
        }

        $rows = array_map(static fn(FilterResult $r): array => $r->toArray(), $results);

        $fatal = [];
        $hard = [];
        $soft = [];
        $warn = [];
        $would = [];

        foreach ($rows as $row) {
            if (!(bool)($row['enabled'] ?? false) || ((bool)($row['passed'] ?? true) === true)) {
                continue;
            }
            // Always use the canonical filter_id in aggregated output lists — never legacy reason strings.
            $filterId = trim((string)($row['filter_id'] ?? 'unknown_filter'));
            $severity = (string)($row['severity'] ?? 'warning');

            $subFatalReasons = [];
            if (is_array($row['details']['fatal_reasons'] ?? null)) {
                foreach ((array)$row['details']['fatal_reasons'] as $fr) {
                    $fr = trim((string)$fr);
                    if ($fr !== '') {
                        $subFatalReasons[] = $fr;
                    }
                }
            }

            if ($severity === 'fatal' || (bool)($row['fatal'] ?? false)) {
                // For fatal filters keep descriptive sub-reasons (e.g. terminal_point3_broken).
                // Fall back to filter_id when no sub-reasons are present.
                if (!empty($subFatalReasons)) {
                    $fatal = array_merge($fatal, $subFatalReasons);
                } else {
                    $fatal[] = $filterId;
                }
            } elseif ($severity === 'hard_block') {
                $hard[] = $filterId;
            } elseif ($severity === 'soft_block') {
                $soft[] = $filterId;
            } else {
                $warn[] = $filterId;
            }

            if ((bool)($row['would_block'] ?? false)) {
                // would_have_blocked_by_filters uses canonical filter_id only.
                $would[] = $filterId;
            }
        }

        return [
            'filter_results'             => $rows,
            'fatal_filter_reasons'       => array_values(array_unique($fatal)),
            'hard_block_filter_reasons'  => array_values(array_unique($hard)),
            'soft_block_filter_reasons'  => array_values(array_unique($soft)),
            'warning_filter_reasons'     => array_values(array_unique($warn)),
            'would_have_blocked_by_filters' => array_values(array_unique($would)),
        ];
    }
}
