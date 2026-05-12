<?php

declare(strict_types=1);

namespace Modules\DynamicLearning\Analyzers;

/**
 * Shared static helper utilities for all dynamic_learning analyzers.
 */
final class DlHelpers
{
    public static function toFloat(mixed $v): ?float
    {
        return is_numeric($v) ? (float)$v : null;
    }

    public static function avg(array $xs): ?float
    {
        return $xs === [] ? null : round(array_sum($xs) / count($xs), 6);
    }

    public static function normalizeTimestamp(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim((string)$value)))) {
            $num = (float)$value;
            if ($num > 1000000000000) {
                $num /= 1000.0;
            }
            if ($num > 0) {
                return gmdate('c', (int)round($num));
            }
        }
        $s = trim((string)$value);
        if ($s === '') {
            return '';
        }
        $ts = strtotime($s);
        if ($ts !== false) {
            return gmdate('c', $ts);
        }
        return $s;
    }

    public static function extractOpenedAt(array $row): string
    {
        return self::normalizeTimestamp($row['opened_at'] ?? $row['entry_time'] ?? $row['created_at'] ?? '');
    }

    public static function extractClosedAt(array $row): string
    {
        return self::normalizeTimestamp($row['closed_at'] ?? $row['close_time'] ?? $row['closed_time'] ?? '');
    }

    public static function extractSnapshotOpenedAt(array $snapshot): string
    {
        return self::normalizeTimestamp($snapshot['opened_at'] ?? '');
    }

    public static function extractSnapshotDetectedAt(array $snapshot): string
    {
        return self::normalizeTimestamp($snapshot['detected_at'] ?? '');
    }

    public static function extractSnapshotFeatureAvailableAt(array $snapshot): string
    {
        return self::extractSnapshotOpenedAt($snapshot)
            ?: self::extractSnapshotDetectedAt($snapshot)
            ?: self::normalizeTimestamp($snapshot['created_at'] ?? '');
    }

    public static function signedTimestampDiffSeconds(string $a, string $b): ?int
    {
        $ta = strtotime($a);
        $tb = strtotime($b);
        if ($ta === false || $tb === false) {
            return null;
        }
        return $tb - $ta;
    }

    public static function timestampDiffSeconds(string $a, string $b): ?int
    {
        $signed = self::signedTimestampDiffSeconds($a, $b);
        return $signed === null ? null : abs($signed);
    }

    public static function smallestTimestampDiffSeconds(string $base, array $candidates): ?int
    {
        $best = null;
        foreach ($candidates as $candidate) {
            $diff = self::timestampDiffSeconds($base, (string)$candidate);
            if ($diff === null) {
                continue;
            }
            if ($best === null || $diff < $best) {
                $best = $diff;
            }
        }
        return $best;
    }

    public static function cond(mixed $actual, string $op, mixed $value): bool
    {
        return match ($op) {
            'eq' => $actual === $value || strtolower((string)$actual) === strtolower((string)$value),
            'in' => is_array($value) && in_array(strtolower((string)$actual), array_map(static fn($v): string => strtolower((string)$v), $value), true),
            'gte' => is_numeric($actual) && is_numeric($value) && (float)$actual >= (float)$value,
            'gt' => is_numeric($actual) && is_numeric($value) && (float)$actual > (float)$value,
            'lte' => is_numeric($actual) && is_numeric($value) && (float)$actual <= (float)$value,
            'lt' => is_numeric($actual) && is_numeric($value) && (float)$actual < (float)$value,
            default => false,
        };
    }

    public static function matchCombo(array $features, array $conds): bool
    {
        foreach ($conds as $c) {
            if (!self::cond($features[(string)($c['f'] ?? '')] ?? null, (string)($c['op'] ?? 'eq'), $c['v'] ?? null)) {
                return false;
            }
        }
        return true;
    }
}
