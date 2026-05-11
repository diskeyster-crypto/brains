<?php

declare(strict_types=1);

namespace Modules\Context\CoinContext;

final class CoinContextService
{
    private static ?self $instance = null;
    private string $moduleDir;
    private string $repoRoot;

    public function __construct(?string $moduleDir = null)
    {
        $this->moduleDir = $moduleDir !== null
            ? rtrim($moduleDir, '/')
            : rtrim(dirname(__FILE__), '/');
        $this->repoRoot = rtrim(dirname($this->moduleDir, 3), '/');
    }

    public static function instance(?string $moduleDir = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($moduleDir);
        }
        return self::$instance;
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function buildContext(string $symbol, array $options = []): array
    {
        $config = $this->loadConfig();
        $normalizedSymbol = strtoupper(trim($symbol));
        $now = isset($options['now']) ? (int)$options['now'] : time();

        $ctx = [
            'context_available' => false,
            'context_generated_at' => date('c', $now),
            'context_source' => 'parser2_history',
            'symbol' => $normalizedSymbol,
            'context_error' => null,
            'trend_1h_direction' => 'unknown',
            'trend_2h_direction' => 'unknown',
            'trend_4h_direction' => 'unknown',
            'price_change_1h_pct' => null,
            'price_change_2h_pct' => null,
            'price_change_4h_pct' => null,
            'corridor_window_minutes' => (int)$config['corridor_window_minutes'],
            'corridor_low_price' => null,
            'corridor_high_price' => null,
            'corridor_low_ts' => null,
            'corridor_high_ts' => null,
            'corridor_range_pct' => null,
            'corridor_position_pct' => null,
            'room_to_corridor_high_pct' => null,
            'distance_from_corridor_low_pct' => null,
            'recent_high_price' => null,
            'recent_high_ts' => null,
            'recent_low_price' => null,
            'recent_low_ts' => null,
            'room_to_recent_high_pct' => null,
            'distance_from_recent_low_pct' => null,
            'context_phase' => 'unknown',
            'context_quality' => 'unknown',
            'context_reasons' => [],
        ];

        if ($normalizedSymbol === '' || !preg_match('/^[A-Z0-9]{2,30}$/', $normalizedSymbol)) {
            $ctx['context_error'] = 'invalid_symbol';
            return $ctx;
        }

        if (!(bool)$config['enabled']) {
            $ctx['context_error'] = 'context_disabled';
            return $ctx;
        }

        $maxTrendWindow = 0;
        foreach ((array)$config['trend_windows_minutes'] as $window) {
            $window = (int)$window;
            if ($window > $maxTrendWindow) {
                $maxTrendWindow = $window;
            }
        }
        $historyLookbackMinutes = max($maxTrendWindow, (int)$config['corridor_window_minutes'], (int)$config['min_history_minutes']) + 20;

        $rows = is_array($options['history_rows'] ?? null)
            ? array_values((array)$options['history_rows'])
            : $this->loadParser2Rows($normalizedSymbol, $historyLookbackMinutes);
        if ($rows === []) {
            $ctx['context_error'] = 'data_source_missing';
            return $ctx;
        }

        $candles = $this->buildMinuteCandlesFromRows($rows);
        if ($candles === []) {
            $ctx['context_error'] = 'insufficient_history';
            return $ctx;
        }

        $latest = $candles[count($candles) - 1];
        $latestPrice = isset($options['latest_price']) && is_numeric($options['latest_price'])
            ? (float)$options['latest_price']
            : (float)($latest['close'] ?? 0.0);
        $latestTs = (int)($latest['ts'] ?? 0);
        if ($latestPrice <= 0.0 || $latestTs <= 0) {
            $ctx['context_error'] = 'insufficient_history';
            return $ctx;
        }

        $oldestTs = (int)($candles[0]['ts'] ?? 0);
        $historyMinutes = (int)floor(max(0, $latestTs - $oldestTs) / 60);
        if ($historyMinutes < (int)$config['min_history_minutes']) {
            $ctx['context_error'] = 'insufficient_history';
            $ctx['context_reasons'][] = 'history_too_short';
            return $ctx;
        }

        $flatThreshold = (float)$config['flat_threshold_pct'];
        $trendThreshold = (float)$config['trend_threshold_pct'];
        $chaoticMaxFlips = (int)$config['chaotic_max_direction_flips'];
        $spikeThreshold10m = (float)$config['spike_threshold_pct_10m'];

        $trendByWindow = [];
        $flipByWindow = [];
        foreach ((array)$config['trend_windows_minutes'] as $windowMinutes) {
            $windowMinutes = (int)$windowMinutes;
            if ($windowMinutes <= 0) {
                continue;
            }
            $windowCandles = $this->sliceCandlesByWindow($candles, $latestTs, $windowMinutes);
            if (count($windowCandles) < 2) {
                $trendByWindow[$windowMinutes] = ['direction' => 'unknown', 'change_pct' => null, 'flips' => 0];
                continue;
            }
            $start = (float)($windowCandles[0]['close'] ?? 0.0);
            $end = $windowMinutes === 60 ? $latestPrice : (float)($windowCandles[count($windowCandles) - 1]['close'] ?? 0.0);
            if ($start <= 0.0 || $end <= 0.0) {
                $trendByWindow[$windowMinutes] = ['direction' => 'unknown', 'change_pct' => null, 'flips' => 0];
                continue;
            }
            $changePct = (($end - $start) / $start) * 100.0;
            $flips = $this->countDirectionFlips($windowCandles);
            $direction = $this->classifyDirection($changePct, $flatThreshold, $trendThreshold, $flips, $chaoticMaxFlips);
            $trendByWindow[$windowMinutes] = [
                'direction' => $direction,
                'change_pct' => round($changePct, 6),
                'flips' => $flips,
            ];
            $flipByWindow[$windowMinutes] = $flips;
        }

        $change10m = $this->computeWindowChangePct($candles, $latestPrice, $latestTs, 10);
        $corridorWindowMinutes = (int)$config['corridor_window_minutes'];
        $corridorCandles = $this->sliceCandlesByWindow($candles, $latestTs, $corridorWindowMinutes);
        if (count($corridorCandles) < 2) {
            $ctx['context_error'] = 'insufficient_history';
            $ctx['context_reasons'][] = 'corridor_window_insufficient';
            return $ctx;
        }

        $corridorLow = null;
        $corridorHigh = null;
        $corridorLowTs = null;
        $corridorHighTs = null;
        foreach ($corridorCandles as $c) {
            $low = (float)($c['low'] ?? $c['close'] ?? 0.0);
            $high = (float)($c['high'] ?? $c['close'] ?? 0.0);
            $ts = (int)($c['ts'] ?? 0);
            if ($low > 0.0 && ($corridorLow === null || $low < $corridorLow)) {
                $corridorLow = $low;
                $corridorLowTs = $ts;
            }
            if ($high > 0.0 && ($corridorHigh === null || $high > $corridorHigh)) {
                $corridorHigh = $high;
                $corridorHighTs = $ts;
            }
        }

        if ($corridorLow === null || $corridorHigh === null || $corridorLow <= 0.0 || $corridorHigh <= 0.0 || $corridorHigh < $corridorLow) {
            $ctx['context_error'] = 'insufficient_history';
            $ctx['context_reasons'][] = 'corridor_invalid';
            return $ctx;
        }

        $corridorRangePct = (($corridorHigh - $corridorLow) / $corridorLow) * 100.0;
        $corridorSpan = max(0.0000001, $corridorHigh - $corridorLow);
        $corridorPositionPct = (($latestPrice - $corridorLow) / $corridorSpan) * 100.0;
        $corridorPositionPct = max(0.0, min(100.0, $corridorPositionPct));

        $roomToCorridorHighPct = $latestPrice > 0.0
            ? (($corridorHigh - $latestPrice) / $latestPrice) * 100.0
            : null;
        $distanceFromCorridorLowPct = $corridorLow > 0.0
            ? (($latestPrice - $corridorLow) / $corridorLow) * 100.0
            : null;

        $trend1h = $trendByWindow[60]['direction'] ?? 'unknown';
        $trend2h = $trendByWindow[120]['direction'] ?? 'unknown';
        $trend4h = $trendByWindow[240]['direction'] ?? 'unknown';
        $change1h = $trendByWindow[60]['change_pct'] ?? null;
        $change2h = $trendByWindow[120]['change_pct'] ?? null;
        $change4h = $trendByWindow[240]['change_pct'] ?? null;
        $flips1h = (int)($flipByWindow[60] ?? 0);

        $phase = 'unknown';
        $phaseReasons = [];
        if ($change10m !== null && $change10m >= $spikeThreshold10m) {
            $phase = 'spike';
            $phaseReasons[] = 'spike_threshold_10m';
        } elseif ($flips1h > $chaoticMaxFlips) {
            $phase = 'chaotic';
            $phaseReasons[] = 'too_many_direction_flips';
        } elseif ($change1h !== null && $change1h <= (-2.0 * $trendThreshold) && $corridorPositionPct <= 25.0) {
            $phase = 'dump';
            $phaseReasons[] = 'strong_short_term_drop';
        } elseif ($change1h !== null && $change2h !== null && abs($change1h) <= $flatThreshold && abs($change2h) <= $flatThreshold) {
            $phase = 'flat';
            $phaseReasons[] = 'low_directional_change';
        } elseif ($change1h !== null && $change1h > $flatThreshold && $change4h !== null && $change4h < 0.0) {
            $phase = 'recovery';
            $phaseReasons[] = 'short_term_rebound_inside_longer_decline';
        } elseif ($change1h !== null && $change2h !== null && $change1h <= (-1.0 * $trendThreshold) && $change2h <= (-1.0 * $trendThreshold)) {
            $phase = 'downtrend';
            $phaseReasons[] = 'downward_trend_confirmed';
        } elseif ($change1h !== null && $change2h !== null && $change1h >= $trendThreshold && $change2h >= $trendThreshold) {
            $phase = 'uptrend';
            $phaseReasons[] = 'upward_trend_confirmed';
        }

        $quality = 'good';
        if ($trend1h === 'unknown' || $trend2h === 'unknown' || $trend4h === 'unknown') {
            $quality = 'medium';
            $phaseReasons[] = 'partial_trend_coverage';
        }
        if ($historyMinutes < max((int)$config['corridor_window_minutes'], $maxTrendWindow)) {
            $quality = 'medium';
            $phaseReasons[] = 'limited_history_coverage';
        }
        if ($phase === 'unknown') {
            $quality = $quality === 'good' ? 'medium' : $quality;
            $phaseReasons[] = 'phase_unclear';
        }
        if ($corridorRangePct <= 0.0) {
            $quality = 'bad';
            $phaseReasons[] = 'invalid_corridor_range';
        }

        $ctx['context_available'] = true;
        $ctx['context_error'] = null;
        $ctx['trend_1h_direction'] = $trend1h;
        $ctx['trend_2h_direction'] = $trend2h;
        $ctx['trend_4h_direction'] = $trend4h;
        $ctx['price_change_1h_pct'] = $change1h;
        $ctx['price_change_2h_pct'] = $change2h;
        $ctx['price_change_4h_pct'] = $change4h;
        $ctx['corridor_low_price'] = $corridorLow;
        $ctx['corridor_high_price'] = $corridorHigh;
        $ctx['corridor_low_ts'] = $corridorLowTs !== null ? date('c', $corridorLowTs) : null;
        $ctx['corridor_high_ts'] = $corridorHighTs !== null ? date('c', $corridorHighTs) : null;
        $ctx['corridor_range_pct'] = round($corridorRangePct, 6);
        $ctx['corridor_position_pct'] = round($corridorPositionPct, 6);
        $ctx['room_to_corridor_high_pct'] = $roomToCorridorHighPct !== null ? round($roomToCorridorHighPct, 6) : null;
        $ctx['distance_from_corridor_low_pct'] = $distanceFromCorridorLowPct !== null ? round($distanceFromCorridorLowPct, 6) : null;
        $ctx['recent_high_price'] = $corridorHigh;
        $ctx['recent_high_ts'] = $corridorHighTs !== null ? date('c', $corridorHighTs) : null;
        $ctx['recent_low_price'] = $corridorLow;
        $ctx['recent_low_ts'] = $corridorLowTs !== null ? date('c', $corridorLowTs) : null;
        $ctx['room_to_recent_high_pct'] = $roomToCorridorHighPct !== null ? round($roomToCorridorHighPct, 6) : null;
        $ctx['distance_from_recent_low_pct'] = $distanceFromCorridorLowPct !== null ? round($distanceFromCorridorLowPct, 6) : null;
        $ctx['context_phase'] = $phase;
        $ctx['context_quality'] = $quality;
        $ctx['context_reasons'] = array_values(array_unique($phaseReasons));

        return $ctx;
    }

    /**
     * @param list<array<string,mixed>> $candles
     * @return list<array<string,mixed>>
     */
    private function sliceCandlesByWindow(array $candles, int $latestTs, int $windowMinutes): array
    {
        $fromTs = $latestTs - ($windowMinutes * 60);
        $window = array_values(array_filter(
            $candles,
            static fn(array $c): bool => (int)($c['ts'] ?? 0) >= $fromTs && (int)($c['ts'] ?? 0) <= $latestTs
        ));
        if (count($window) >= 2) {
            return $window;
        }
        return $candles;
    }

    private function computeWindowChangePct(array $candles, float $latestPrice, int $latestTs, int $windowMinutes): ?float
    {
        $window = $this->sliceCandlesByWindow($candles, $latestTs, $windowMinutes);
        if (count($window) < 2) {
            return null;
        }
        $start = (float)($window[0]['close'] ?? 0.0);
        if ($start <= 0.0 || $latestPrice <= 0.0) {
            return null;
        }
        return round((($latestPrice - $start) / $start) * 100.0, 6);
    }

    private function classifyDirection(float $changePct, float $flatThreshold, float $trendThreshold, int $flips, int $chaoticMaxFlips): string
    {
        if ($flips > $chaoticMaxFlips) {
            return 'chaotic';
        }
        if (abs($changePct) <= $flatThreshold) {
            return 'flat';
        }
        if ($changePct >= $trendThreshold) {
            return 'up';
        }
        if ($changePct <= (-1.0 * $trendThreshold)) {
            return 'down';
        }
        return 'flat';
    }

    /**
     * @param list<array<string,mixed>> $candles
     */
    private function countDirectionFlips(array $candles): int
    {
        $flips = 0;
        $lastDir = 0;
        for ($i = 1; $i < count($candles); $i++) {
            $prev = (float)($candles[$i - 1]['close'] ?? 0.0);
            $curr = (float)($candles[$i]['close'] ?? 0.0);
            if ($prev <= 0.0 || $curr <= 0.0) {
                continue;
            }
            $dir = $curr > $prev ? 1 : ($curr < $prev ? -1 : 0);
            if ($dir === 0) {
                continue;
            }
            if ($lastDir !== 0 && $dir !== $lastDir) {
                $flips++;
            }
            $lastDir = $dir;
        }
        return $flips;
    }

    private function loadConfig(): array
    {
        $cfg = $this->readPhpArray($this->moduleDir . '/config/base.php');
        $cfg['enabled'] = (bool)($cfg['enabled'] ?? true);
        $cfg['trend_windows_minutes'] = array_values(array_map('intval', is_array($cfg['trend_windows_minutes'] ?? null) ? $cfg['trend_windows_minutes'] : [60, 120, 240]));
        if ($cfg['trend_windows_minutes'] === []) {
            $cfg['trend_windows_minutes'] = [60, 120, 240];
        }
        $cfg['corridor_window_minutes'] = max(30, min(1440, (int)($cfg['corridor_window_minutes'] ?? 240)));
        $cfg['flat_threshold_pct'] = max(0.0, min(10.0, (float)($cfg['flat_threshold_pct'] ?? 0.5)));
        $cfg['trend_threshold_pct'] = max(0.1, min(50.0, (float)($cfg['trend_threshold_pct'] ?? 1.0)));
        $cfg['spike_threshold_pct_10m'] = max(0.1, min(50.0, (float)($cfg['spike_threshold_pct_10m'] ?? 2.0)));
        $cfg['chaotic_max_direction_flips'] = max(1, min(100, (int)($cfg['chaotic_max_direction_flips'] ?? 8)));
        $cfg['min_history_minutes'] = max(30, min(1440, (int)($cfg['min_history_minutes'] ?? 60)));
        return $cfg;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadParser2Rows(string $symbol, int $lookbackMinutes): array
    {
        $storageRoot = $this->repoRoot . '/modules/parser/parser2_history_accumulator/storage/' . $symbol;
        $files = [
            $storageRoot . '/' . gmdate('Y-m-d') . '.ndjson',
            $storageRoot . '/' . gmdate('Y-m-d', time() - 86400) . '.ndjson',
        ];

        $rows = [];
        $minTs = time() - ($lookbackMinutes * 60);
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $fh = @fopen($file, 'r');
            if (!is_resource($fh)) {
                continue;
            }
            while (($line = fgets($fh)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $row = json_decode($line, true);
                if (!is_array($row)) {
                    continue;
                }
                $ts = 0;
                if (isset($row['ts_unix'])) {
                    $ts = (int)$row['ts_unix'];
                } elseif (isset($row['ts'])) {
                    $parsedTs = strtotime((string)$row['ts']);
                    $ts = $parsedTs === false ? 0 : (int)$parsedTs;
                }
                if ($ts <= 0 || $ts < $minTs) {
                    continue;
                }
                $price = (float)($row['last_price'] ?? ($row['data']['lastPrice'] ?? 0.0));
                if ($price <= 0.0) {
                    continue;
                }
                $rows[] = [
                    'ts' => $ts,
                    'price' => $price,
                    'open_interest_value' => isset($row['open_interest_value']) ? (float)$row['open_interest_value'] : null,
                    'open_interest' => isset($row['open_interest']) ? (float)$row['open_interest'] : null,
                ];
            }
            fclose($fh);
        }

        usort($rows, static fn(array $a, array $b): int => ((int)$a['ts']) <=> ((int)$b['ts']));
        $dedup = [];
        foreach ($rows as $row) {
            $dedup[(string)$row['ts']] = $row;
        }
        return array_values($dedup);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function buildMinuteCandlesFromRows(array $rows): array
    {
        $bars = [];
        foreach ($rows as $row) {
            $ts = (int)($row['ts'] ?? 0);
            $price = (float)($row['price'] ?? 0.0);
            if ($ts <= 0 || $price <= 0.0) {
                continue;
            }
            $bucket = (int)(floor($ts / 60) * 60);
            if (!isset($bars[$bucket])) {
                $bars[$bucket] = [
                    'ts' => $bucket,
                    'open' => $price,
                    'high' => $price,
                    'low' => $price,
                    'close' => $price,
                ];
            } else {
                $bars[$bucket]['high'] = max((float)$bars[$bucket]['high'], $price);
                $bars[$bucket]['low'] = min((float)$bars[$bucket]['low'], $price);
                $bars[$bucket]['close'] = $price;
            }
        }
        ksort($bars);
        return array_values($bars);
    }

    /**
     * @return array<string,mixed>
     */
    private function readPhpArray(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        try {
            $loaded = require $path;
            return is_array($loaded) ? $loaded : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
