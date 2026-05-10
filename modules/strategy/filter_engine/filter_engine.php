<?php

declare(strict_types=1);

namespace Modules\Strategy\FilterEngine;

use Modules\Strategy\FilterEngine\Filters\DailyExtensionFilter;
use Modules\Strategy\FilterEngine\Filters\LateLocalEntryFilter;
use Modules\Strategy\FilterEngine\Filters\LowQualityWithoutObcFilter;
use Modules\Strategy\FilterEngine\Filters\MissingReclaimFilter;
use Modules\Strategy\FilterEngine\Filters\Point3TerminalBreakFilter;
use Modules\Strategy\FilterEngine\Filters\TinyRoomFilter;
use Modules\Strategy\FilterEngine\Filters\WhipsawFilter;

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
            $reason = trim((string)($row['reason'] ?? ''));
            if ($reason === '') {
                $reason = (string)($row['filter_id'] ?? 'unknown_filter');
            }
            $severity = (string)($row['severity'] ?? 'warning');
            $fatalReasons = [];
            if (is_array($row['details']['fatal_reasons'] ?? null)) {
                foreach ((array)$row['details']['fatal_reasons'] as $fr) {
                    $fr = trim((string)$fr);
                    if ($fr !== '') {
                        $fatalReasons[] = $fr;
                    }
                }
            }
            if ($severity === 'fatal' || (bool)($row['fatal'] ?? false)) {
                if (!empty($fatalReasons)) {
                    $fatal = array_merge($fatal, $fatalReasons);
                } else {
                    $fatal[] = $reason;
                }
            } elseif ($severity === 'hard_block') {
                $hard[] = $reason;
            } elseif ($severity === 'soft_block') {
                $soft[] = $reason;
            } else {
                $warn[] = $reason;
            }
            if ((bool)($row['would_block'] ?? false)) {
                $would[] = $reason;
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
