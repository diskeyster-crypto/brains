<?php

declare(strict_types=1);

namespace Modules\ProfManager\Lib;

/**
 * CandleReader
 *
 * Reads recent price points for a symbol from the parser2_history_accumulator
 * NDJSON storage. Returns a time-ordered price series suitable for pattern
 * detection without requiring true OHLCV candle data.
 *
 * Supports both parser2 (full Bybit ticker snapshot) and parser15 backfill
 * (simplified last_price) record formats.
 *
 * Fails gracefully — returns empty array when storage is unavailable, unreadable,
 * or contains fewer points than the caller requires.
 *
 * Read-only — never writes to storage.
 */
class CandleReader
{
    /**
     * Read recent price points for a symbol from parser2 NDJSON storage.
     *
     * Returns a deduplicated, ascending time-sorted array of records.
     * Each record: ['ts_unix' => int, 'price' => float]
     *
     * @param string $symbol      Trading symbol, e.g. 'BTCUSDT'
     * @param string $storageDir  Absolute path to parser2 storage root
     * @param int    $maxPoints   Maximum number of recent points to return
     * @param int    $lookbackSec How far back to look (seconds); minimum 60
     * @return array<int,array{ts_unix:int,price:float}>
     */
    public function readRecentPrices(
        string $symbol,
        string $storageDir,
        int    $maxPoints  = 60,
        int    $lookbackSec = 3600
    ): array {
        $symbolDir = rtrim($storageDir, '/') . '/' . strtoupper($symbol);
        if (!is_dir($symbolDir)) {
            return [];
        }

        $nowTs   = time();
        $sinceTs = $nowTs - max($lookbackSec, 60);

        // Build list of candidate date strings spanning the lookback window
        $dates = [];
        $cursor = $sinceTs;
        while ($cursor <= $nowTs) {
            $d = date('Y-m-d', $cursor);
            if (!in_array($d, $dates, true)) {
                $dates[] = $d;
            }
            $cursor += 86400;
        }
        $todayDate = date('Y-m-d', $nowTs);
        if (!in_array($todayDate, $dates, true)) {
            $dates[] = $todayDate;
        }

        $points = [];

        foreach ($dates as $date) {
            $file = $symbolDir . '/' . $date . '.ndjson';
            if (!is_file($file) || !is_readable($file)) {
                continue;
            }

            $handle = @fopen($file, 'r');
            if ($handle === false) {
                continue;
            }

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $rec = json_decode($line, true);
                if (!is_array($rec)) {
                    continue;
                }

                $ts = isset($rec['ts_unix']) ? (int) $rec['ts_unix'] : 0;
                if ($ts < $sinceTs || $ts > ($nowTs + 60)) {
                    // Ignore future records and too-old records
                    continue;
                }

                $price = $this->extractPrice($rec);
                if ($price <= 0.0) {
                    continue;
                }

                $points[] = ['ts_unix' => $ts, 'price' => $price];
            }

            fclose($handle);
        }

        if (empty($points)) {
            return [];
        }

        // Sort ascending by timestamp
        usort($points, static fn(array $a, array $b): int => $a['ts_unix'] <=> $b['ts_unix']);

        // Deduplicate by ts_unix (keep last value at each timestamp)
        $deduped = [];
        foreach ($points as $p) {
            $deduped[$p['ts_unix']] = $p;
        }
        $deduped = array_values($deduped);

        // Return at most $maxPoints most-recent points
        if (count($deduped) > $maxPoints) {
            $deduped = array_slice($deduped, -$maxPoints);
        }

        return $deduped;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Extract a price from a parser2 or parser15 NDJSON record.
     *
     * Parser2 (full Bybit ticker snapshot):
     *   {"ts":..., "ts_unix":..., "category":"linear", "data":{lastPrice, markPrice, ...}}
     *
     * Parser15 backfill (simplified):
     *   {"ts":..., "ts_unix":..., "symbol":..., "last_price":..., "source":"parser1.5_backfill"}
     *
     * @param array<string,mixed> $rec
     * @return float  0.0 when price cannot be extracted
     */
    private function extractPrice(array $rec): float
    {
        // Parser2 format: nested data object with Bybit ticker fields
        if (isset($rec['data']) && is_array($rec['data'])) {
            foreach (['lastPrice', 'markPrice', 'indexPrice'] as $field) {
                $v = isset($rec['data'][$field]) ? (float) $rec['data'][$field] : 0.0;
                if ($v > 0.0) {
                    return $v;
                }
            }
        }

        // Parser15 backfill format
        if (isset($rec['last_price'])) {
            $v = (float) $rec['last_price'];
            if ($v > 0.0) {
                return $v;
            }
        }

        return 0.0;
    }
}
