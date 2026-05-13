<?php

declare(strict_types=1);

namespace Modules\DynamicLearning\Analyzers;

final class DlMarketData
{
    private string $repoRoot;
    /** @var array<string,mixed> */
    private array $cfg;
    /** @var array<string,array<int,array<string,mixed>>> */
    private array $cache = [];
    /** @var array<string,mixed> */
    private array $stats = [
        'bybit_kline_requests_total' => 0,
        'bybit_kline_success_total' => 0,
        'bybit_kline_error_total' => 0,
        'bybit_kline_cache_hit_total' => 0,
        'parser2_fallback_used_total' => 0,
        'micro_data_missing_total' => 0,
    ];

    /** @param array<string,mixed> $cfg */
    public function __construct(string $repoRoot, array $cfg)
    {
        $this->repoRoot = rtrim($repoRoot, '/');
        $this->cfg = $cfg;
    }

    /** @return array<string,mixed> */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * @return array{candles:list<array<string,mixed>>,source:string,missing_reason:?string}
     */
    public function getCandlesBeforeEntry(string $symbol, int $entryTs, int $lookbackMinutes, int $minCandles = 3): array
    {
        $normalized = strtoupper(trim($symbol));
        if ($normalized === '' || $entryTs <= 0) {
            $this->stats['micro_data_missing_total']++;
            return ['candles' => [], 'source' => 'none', 'missing_reason' => 'parser2_history_missing'];
        }

        $cacheKey = implode('|', [$normalized, (string)$entryTs, (string)$lookbackMinutes]);
        if (isset($this->cache[$cacheKey])) {
            $this->stats['bybit_kline_cache_hit_total']++;
            return ['candles' => $this->cache[$cacheKey], 'source' => 'cache', 'missing_reason' => null];
        }

        $candles = [];
        $source = 'none';

        $primary = strtolower((string)($this->cfg['micro_data_source_primary'] ?? 'bybit'));
        $fallback = strtolower((string)($this->cfg['micro_data_source_fallback'] ?? 'parser2'));

        if ($primary === 'bybit' && (bool)($this->cfg['bybit_micro_fetch_enabled'] ?? true)) {
            $candles = $this->fetchBybitCandles($normalized, $entryTs);
            if (count($candles) >= $minCandles) {
                $source = 'bybit';
            }
        }

        if (count($candles) < $minCandles && $fallback === 'parser2') {
            $fallbackCandles = $this->loadParser2Candles($normalized, $entryTs, $lookbackMinutes);
            if (count($fallbackCandles) >= $minCandles) {
                $candles = $fallbackCandles;
                $source = 'parser2';
                $this->stats['parser2_fallback_used_total']++;
            }
        }

        if (count($candles) < $minCandles) {
            $this->stats['micro_data_missing_total']++;
            return ['candles' => [], 'source' => 'none', 'missing_reason' => 'parser2_history_missing'];
        }

        $startTs = $entryTs - max(1, $lookbackMinutes) * 60;
        $candles = array_values(array_filter($candles, static fn(array $c): bool => (int)($c['ts'] ?? 0) <= $entryTs && (int)($c['ts'] ?? 0) >= $startTs));
        usort($candles, static fn(array $a, array $b): int => ((int)$a['ts']) <=> ((int)$b['ts']));

        if ($candles === []) {
            $this->stats['micro_data_missing_total']++;
            return ['candles' => [], 'source' => 'none', 'missing_reason' => 'parser2_history_missing'];
        }

        $this->cache[$cacheKey] = $candles;
        return ['candles' => $candles, 'source' => $source, 'missing_reason' => null];
    }

    /** @return list<array<string,mixed>> */
    private function fetchBybitCandles(string $symbol, int $entryTs): array
    {
        $baseUrl = rtrim((string)($this->cfg['bybit_base_url'] ?? 'https://api.bybit.com'), '/');
        $timeout = max(3, (int)($this->cfg['bybit_timeout_sec'] ?? 6));
        $limit = max(20, min(1000, (int)($this->cfg['bybit_kline_limit'] ?? 120)));
        $interval = (string)($this->cfg['bybit_kline_interval'] ?? '1');

        $this->stats['bybit_kline_requests_total']++;

        $url = $baseUrl . '/v5/market/kline?' . http_build_query([
            'category' => 'linear',
            'symbol' => $symbol,
            'interval' => $interval,
            'limit' => $limit,
            'end' => $entryTs * 1000,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if (!is_string($raw) || $raw === '' || $errno !== 0) {
            $this->stats['bybit_kline_error_total']++;
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['result']['list']) || !is_array($decoded['result']['list'])) {
            $this->stats['bybit_kline_error_total']++;
            return [];
        }

        $candles = [];
        foreach (array_reverse($decoded['result']['list']) as $row) {
            if (!is_array($row) || count($row) < 6) {
                continue;
            }
            $ts = (int)round(((int)$row[0]) / 1000);
            if ($ts <= 0 || $ts > $entryTs) {
                continue;
            }
            $candles[] = [
                'ts' => $ts,
                'open' => (float)$row[1],
                'high' => (float)$row[2],
                'low' => (float)$row[3],
                'close' => (float)$row[4],
                'volume' => (float)$row[5],
                'turnover' => isset($row[6]) ? (float)$row[6] : null,
            ];
        }

        if ($candles !== []) {
            $this->stats['bybit_kline_success_total']++;
        } else {
            $this->stats['bybit_kline_error_total']++;
        }

        return $candles;
    }

    /** @return list<array<string,mixed>> */
    private function loadParser2Candles(string $symbol, int $entryTs, int $lookbackMinutes): array
    {
        $storageRoot = $this->repoRoot . '/modules/parser/parser2_history_accumulator/storage/' . $symbol;
        if (!is_dir($storageRoot)) {
            return [];
        }

        $rows = [];
        $startTs = $entryTs - (max(60, $lookbackMinutes + 30) * 60);
        $daysBack = (int)ceil((max(60, $lookbackMinutes + 30)) / 1440) + 1;

        for ($i = 0; $i <= $daysBack; $i++) {
            $dayTs = $entryTs - ($i * 86400);
            $file = $storageRoot . '/' . gmdate('Y-m-d', $dayTs) . '.ndjson';
            if (!is_file($file)) {
                continue;
            }
            $fh = @fopen($file, 'rb');
            if (!is_resource($fh)) {
                continue;
            }
            while (($line = fgets($fh)) !== false) {
                $decoded = json_decode(trim($line), true);
                if (!is_array($decoded)) {
                    continue;
                }
                $ts = 0;
                foreach (['ts', 'timestamp', 'time', 'created_at'] as $k) {
                    if (!isset($decoded[$k])) {
                        continue;
                    }
                    $v = $decoded[$k];
                    if (is_numeric($v)) {
                        $num = (float)$v;
                        if ($num > 1000000000000) {
                            $num /= 1000.0;
                        }
                        $ts = (int)round($num);
                        break;
                    }
                    if (is_string($v)) {
                        $p = strtotime($v);
                        if ($p !== false) {
                            $ts = $p;
                            break;
                        }
                    }
                }
                if ($ts <= 0 || $ts < $startTs || $ts > $entryTs) {
                    continue;
                }
                $price = null;
                foreach (['last_price', 'price', 'close'] as $pk) {
                    if (isset($decoded[$pk]) && is_numeric($decoded[$pk])) {
                        $price = (float)$decoded[$pk];
                        break;
                    }
                }
                if ($price === null && isset($decoded['data']['lastPrice']) && is_numeric($decoded['data']['lastPrice'])) {
                    $price = (float)$decoded['data']['lastPrice'];
                }
                if ($price === null || $price <= 0.0) {
                    continue;
                }
                $volume = null;
                if (isset($decoded['volume']) && is_numeric($decoded['volume'])) {
                    $volume = (float)$decoded['volume'];
                }
                $turnover = null;
                if (isset($decoded['turnover']) && is_numeric($decoded['turnover'])) {
                    $turnover = (float)$decoded['turnover'];
                }
                $rows[] = ['ts' => $ts, 'price' => $price, 'volume' => $volume, 'turnover' => $turnover];
            }
            fclose($fh);
        }

        if ($rows === []) {
            return [];
        }

        usort($rows, static fn(array $a, array $b): int => ((int)$a['ts']) <=> ((int)$b['ts']));

        $bars = [];
        foreach ($rows as $row) {
            $bucket = (int)(floor(((int)$row['ts']) / 60) * 60);
            $price = (float)$row['price'];
            if (!isset($bars[$bucket])) {
                $bars[$bucket] = [
                    'ts' => $bucket,
                    'open' => $price,
                    'high' => $price,
                    'low' => $price,
                    'close' => $price,
                    'volume' => (float)($row['volume'] ?? 0.0),
                    'turnover' => isset($row['turnover']) ? (float)$row['turnover'] : null,
                ];
            } else {
                $bars[$bucket]['high'] = max((float)$bars[$bucket]['high'], $price);
                $bars[$bucket]['low'] = min((float)$bars[$bucket]['low'], $price);
                $bars[$bucket]['close'] = $price;
                $bars[$bucket]['volume'] = (float)$bars[$bucket]['volume'] + (float)($row['volume'] ?? 0.0);
                if (isset($row['turnover']) && is_numeric($row['turnover'])) {
                    $bars[$bucket]['turnover'] = (float)($bars[$bucket]['turnover'] ?? 0.0) + (float)$row['turnover'];
                }
            }
        }

        ksort($bars);
        return array_values($bars);
    }
}
