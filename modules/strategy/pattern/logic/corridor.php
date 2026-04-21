<?php

declare(strict_types=1);

/**
 * Pattern Strategy — Corridor Engine
 *
 * Computes a price corridor (low/high) from recent candles,
 * divides it into N buckets (1 = lowest, N = highest),
 * and determines which bucket the current price sits in.
 *
 * Config inputs used:
 *   corridor_lookback_hours  → how many hours of H4 bars to look back
 *   corridor_bucket_count    → N buckets
 *   allowed_long_buckets     → e.g. [1, 2]  (low buckets = buy-zone)
 *   allowed_short_buckets    → e.g. [9, 10] (high buckets = sell-zone)
 */

namespace Modules\Strategy\Pattern\Logic;

final class PatternCorridor
{
    /**
     * Compute corridor state.
     *
     * @param  array  $candles   Ordered oldest → newest; each has 'high', 'low', 'close'.
     * @param  float  $currentPrice
     * @param  array  $config    Effective strategy config.
     * @return array  { corridor_low, corridor_high, corridor_range, bucket_count,
     *                  current_bucket, bucket_allowed_long, bucket_allowed_short }
     */
    public function compute(array $candles, float $currentPrice, array $config): array
    {
        $lookbackHours  = (int)($config['corridor_lookback_hours'] ?? 24);
        $bucketCount    = (int)($config['corridor_bucket_count']   ?? 10);
        $allowedLong    = (array)($config['allowed_long_buckets']  ?? [1, 2]);
        $allowedShort   = (array)($config['allowed_short_buckets'] ?? [9, 10]);

        // H4 = 4-hour bars; lookback_hours / 4 = number of bars
        $barsNeeded = max(1, (int)ceil($lookbackHours / 4));
        $window     = array_slice($candles, -$barsNeeded);

        if (empty($window)) {
            return $this->emptyResult($currentPrice, $bucketCount, $allowedLong, $allowedShort);
        }

        $highs = array_map(fn($c) => (float)($c['high'] ?? 0.0), $window);
        $lows  = array_map(fn($c) => (float)($c['low']  ?? 0.0), $window);

        $corridorHigh = max($highs);
        $corridorLow  = min($lows);
        $range        = $corridorHigh - $corridorLow;

        if ($range <= 0.0) {
            return $this->emptyResult($currentPrice, $bucketCount, $allowedLong, $allowedShort);
        }

        // Bucket: 1 = lowest slice … N = highest slice
        $normalized = ($currentPrice - $corridorLow) / $range;
        $normalized = max(0.0, min(1.0, $normalized));
        $bucket     = (int)ceil($normalized * $bucketCount);
        $bucket     = max(1, min($bucketCount, $bucket));

        return [
            'corridor_low'          => round($corridorLow,  6),
            'corridor_high'         => round($corridorHigh, 6),
            'corridor_range'        => round($range,        6),
            'bucket_count'          => $bucketCount,
            'current_bucket'        => $bucket,
            'bucket_allowed_long'   => in_array($bucket, $allowedLong,  true),
            'bucket_allowed_short'  => in_array($bucket, $allowedShort, true),
        ];
    }

    /**
     * Gate check: is the bucket allowed for the given side?
     *
     * @param  array  $corridorResult  Output of compute()
     * @param  string $side            'long' | 'short'
     * @return array  { pass: bool, reason: string }
     */
    public function gate(array $corridorResult, string $side): array
    {
        if ($side === 'long') {
            $pass = (bool)($corridorResult['bucket_allowed_long'] ?? false);
            return $pass
                ? ['pass' => true,  'reason' => 'bucket_allowed_long']
                : ['pass' => false, 'reason' => 'bucket_rejected_long_bucket_' . ($corridorResult['current_bucket'] ?? '?')];
        }
        if ($side === 'short') {
            $pass = (bool)($corridorResult['bucket_allowed_short'] ?? false);
            return $pass
                ? ['pass' => true,  'reason' => 'bucket_allowed_short']
                : ['pass' => false, 'reason' => 'bucket_rejected_short_bucket_' . ($corridorResult['current_bucket'] ?? '?')];
        }
        return ['pass' => false, 'reason' => 'unknown_side'];
    }

    // -------------------------------------------------------------------------

    private function emptyResult(float $price, int $bucketCount, array $allowedLong, array $allowedShort): array
    {
        return [
            'corridor_low'         => $price,
            'corridor_high'        => $price,
            'corridor_range'       => 0.0,
            'bucket_count'         => $bucketCount,
            'current_bucket'       => 0,
            'bucket_allowed_long'  => false,
            'bucket_allowed_short' => false,
        ];
    }
}
