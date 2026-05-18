<?php

declare(strict_types=1);

namespace Modules\FilterEngine;

final class FilterEngine
{
    /** @var array<int,object> */
    private array $filters;

    public function __construct()
    {
        $this->filters = array_values(self::discoverFilters());
    }

    /**
     * @return array<string,object>
     */
    public static function discoverFilters(?string $filtersDir = null): array
    {
        $filtersDir ??= __DIR__ . '/filters';
        $filters = [];
        foreach (self::discoverFilterFiles($filtersDir) as $file) {
            require_once $file;
            $class = self::classNameFromFile($file);
            if ($class === null || !class_exists($class)) {
                continue;
            }
            $filter = new $class();
            if (!method_exists($filter, 'id')) {
                continue;
            }
            $id = trim((string)$filter->id());
            if ($id === '') {
                continue;
            }
            $filters[$id] = $filter;
        }
        ksort($filters);
        return $filters;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function discoverFilterMetadata(?string $filtersDir = null): array
    {
        $meta = [];
        foreach (self::discoverFilters($filtersDir) as $id => $filter) {
            $row = method_exists($filter, 'metadata') ? (array)$filter->metadata() : [];
            $row['filter_id'] = trim((string)($row['filter_id'] ?? $id));
            if ($row['filter_id'] === '') {
                $row['filter_id'] = $id;
            }
            $row['title'] = trim((string)($row['title'] ?? self::humanizeId($row['filter_id'])));
            $row['description'] = trim((string)($row['description'] ?? ''));
            $severity = (string)($row['default_severity'] ?? 'warning');
            $row['default_severity'] = in_array($severity, ['warning', 'soft_block', 'hard_block', 'fatal'], true)
                ? $severity
                : 'warning';

            $fields = [];
            foreach ((array)($row['configurable_fields'] ?? []) as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $key = trim((string)($field['key'] ?? ''));
                $type = trim((string)($field['type'] ?? 'string'));
                if ($key === '' || !in_array($type, ['bool', 'int', 'float', 'string', 'select'], true)) {
                    continue;
                }
                $field['key'] = $key;
                $field['type'] = $type;
                $field['label'] = trim((string)($field['label'] ?? self::humanizeId($key)));
                $field['help'] = trim((string)($field['help'] ?? ''));
                if ($type === 'select') {
                    $allowed = array_values(array_filter(array_map(static fn($v): string => trim((string)$v), (array)($field['allowed_values'] ?? [])), static fn(string $v): bool => $v !== ''));
                    $field['allowed_values'] = $allowed;
                    if (!in_array((string)($field['default'] ?? ''), $allowed, true)) {
                        $field['default'] = $allowed[0] ?? '';
                    }
                }
                $fields[] = $field;
            }
            $row['configurable_fields'] = $fields;
            $meta[$row['filter_id']] = $row;
        }
        ksort($meta);
        return $meta;
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    public static function defaultConfigRow(array $metadata): array
    {
        return self::normalizeConfigRow($metadata, self::defaultConfigRowSkeleton($metadata));
    }

    /**
     * @param array<string,mixed> $metadata
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function normalizeConfigRow(array $metadata, array $row): array
    {
        $normalized = self::defaultConfigRowSkeleton($metadata);
        $normalized['enabled'] = self::toBool($row['enabled'] ?? $normalized['enabled']);
        $severity = (string)($row['severity'] ?? $normalized['severity']);
        $normalized['severity'] = in_array($severity, ['warning', 'soft_block', 'hard_block', 'fatal'], true)
            ? $severity
            : (string)$normalized['severity'];

        foreach ((array)($metadata['configurable_fields'] ?? []) as $field) {
            if (!is_array($field) || !isset($field['key'], $field['type'])) {
                continue;
            }
            $key = (string)$field['key'];
            $value = array_key_exists($key, $row) ? $row[$key] : ($field['default'] ?? null);
            $normalized[$key] = self::castFieldValue((string)$field['type'], $value, $field);
        }

        return $normalized;
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
                $would[] = $filterId;
            }
        }

        return [
            'filter_results' => $rows,
            'fatal_filter_reasons' => array_values(array_unique($fatal)),
            'hard_block_filter_reasons' => array_values(array_unique($hard)),
            'soft_block_filter_reasons' => array_values(array_unique($soft)),
            'warning_filter_reasons' => array_values(array_unique($warn)),
            'would_have_blocked_by_filters' => array_values(array_unique($would)),
        ];
    }

    /**
     * @return list<string>
     */
    private static function discoverFilterFiles(string $filtersDir): array
    {
        $files = glob(rtrim($filtersDir, '/') . '/*.php') ?: [];
        sort($files);
        return array_values(array_filter($files, static fn($file): bool => is_string($file) && is_file($file)));
    }

    private static function classNameFromFile(string $file): ?string
    {
        $base = pathinfo($file, PATHINFO_FILENAME);
        if ($base === '') {
            return null;
        }
        $class = str_replace(' ', '', ucwords(str_replace('_', ' ', $base)));
        return 'Modules\\FilterEngine\\Filters\\' . $class;
    }

    private static function humanizeId(string $id): string
    {
        return ucwords(str_replace('_', ' ', $id));
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    private static function defaultConfigRowSkeleton(array $metadata): array
    {
        $row = [
            'enabled' => false,
            'severity' => (string)($metadata['default_severity'] ?? 'warning'),
        ];
        foreach ((array)($metadata['configurable_fields'] ?? []) as $field) {
            if (!is_array($field) || !isset($field['key'])) {
                continue;
            }
            $row[(string)$field['key']] = $field['default'] ?? null;
        }
        return $row;
    }

    /**
     * @param array<string,mixed> $field
     */
    private static function castFieldValue(string $type, mixed $value, array $field): mixed
    {
        return match ($type) {
            'bool' => self::toBool($value),
            'int' => self::clampInt($value, $field),
            'float' => self::clampFloat($value, $field),
            'select' => self::selectValue($value, $field),
            default => (string)$value,
        };
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (bool)$value;
        }
        $value = strtolower(trim((string)$value));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array<string,mixed> $field
     */
    private static function clampInt(mixed $value, array $field): int
    {
        $v = (int)$value;
        if (isset($field['min']) && is_numeric($field['min'])) {
            $v = max((int)$field['min'], $v);
        }
        if (isset($field['max']) && is_numeric($field['max'])) {
            $v = min((int)$field['max'], $v);
        }
        return $v;
    }

    /**
     * @param array<string,mixed> $field
     */
    private static function clampFloat(mixed $value, array $field): float
    {
        $v = (float)$value;
        if (isset($field['min']) && is_numeric($field['min'])) {
            $v = max((float)$field['min'], $v);
        }
        if (isset($field['max']) && is_numeric($field['max'])) {
            $v = min((float)$field['max'], $v);
        }
        return $v;
    }

    /**
     * @param array<string,mixed> $field
     */
    private static function selectValue(mixed $value, array $field): string
    {
        $allowed = array_values(array_map(static fn($v): string => (string)$v, (array)($field['allowed_values'] ?? [])));
        $value = (string)$value;
        if (in_array($value, $allowed, true)) {
            return $value;
        }
        return (string)($field['default'] ?? ($allowed[0] ?? ''));
    }
}
