<?php

declare(strict_types=1);

/**
 * Corridor Bottom Long — Strategy
 *
 * 24h corridor bottom reversal strategy (validation-based).
 * Demo-only draft. NOT connected to cron, bot queue, or trading.
 *
 * Pipeline per symbol:
 *   1. LOAD SYMBOL DATA     — fetch 1m candles from Bybit
 *   2. CALCULATE 24h HIGH / LOW
 *   3. ZONE CHECK           — skip if price is not near the corridor low
 *   4. CANDIDATE DETECTION  — create / update a candidate record
 *   5. VALIDATION ENGINE    — state machine: waiting → enter | reject
 *   6. SIGNAL OUTPUT        — write to storage/signals.json (enter only)
 *   7. LAST RUN DEBUG       — write stats to storage/last_run.json
 *
 * Entry point: CorridorBottomLongStrategy::run()
 *
 * IMPORTANT RULES:
 *   - Does NOT send signals to the bot queue
 *   - Does NOT connect to any execution module
 *   - Does NOT modify other modules
 *   - auto-enable is permanently disabled
 */

namespace Modules\Strategy\CorridorBottomLong;

require_once __DIR__ . '/lib/validation_engine.php';

use Modules\Strategy\CorridorBottomLong\Lib\ValidationEngine;

final class CorridorBottomLongStrategy
{
    // ── Internal constants ────────────────────────────────────────────────────

    private const STRATEGY_ID  = 'corridor_bottom_long';
    private const KLINE_LIMIT  = 1440; // 1440 × 1 min = 24 h

    // ── Properties ───────────────────────────────────────────────────────────

    private string $moduleDir;
    private array  $config;

    // ── Constructor ───────────────────────────────────────────────────────────

    public function __construct(?string $moduleDir = null)
    {
        $this->moduleDir = $moduleDir !== null
            ? rtrim($moduleDir, '/')
            : rtrim(__DIR__, '/');

        $this->config = $this->loadConfig();
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Run a full symbol scan.
     *
     * @param  array|null $symbols  Optional explicit symbol list (overrides universe build).
     * @return array  Summary of the run.
     */
    public function run(?array $symbols = null): array
    {
        $config = $this->config;

        // Guard: strategy must be explicitly enabled
        if (!(bool)($config['enabled'] ?? false)) {
            return ['ok' => false, 'error' => 'strategy_disabled'];
        }

        $now     = time();
        $symbols = $symbols ?? $this->buildUniverse($config);

        $stats = [
            'started_at'          => date('c', $now),
            'symbols_checked'     => 0,
            'candidates_found'    => 0,
            'candidates_validated'=> 0,
            'rejected'            => 0,
            'reasons'             => [],
        ];

        // Load persisted candidates (state machine continuations)
        $candidates = $this->readJson('storage/candidates.json', []);
        $signals    = $this->readJson('storage/signals.json',    []);

        foreach ($symbols as $symbol) {
            $stats['symbols_checked']++;

            // ── 1 + 2. Fetch candles, compute 24h high/low ───────────────────
            $candles = $this->fetchCandles((string)$symbol, $config);
            if (count($candles) < 2) {
                $stats['reasons'][] = $symbol . ':no_candle_data';
                continue;
            }

            $corridorLow  = min(array_column($candles, 'low'));
            $corridorHigh = max(array_column($candles, 'high'));
            $lastClose    = (float)(end($candles)['close'] ?? 0.0);

            if ($corridorLow <= 0.0) {
                $stats['reasons'][] = $symbol . ':corridor_low_zero';
                continue;
            }

            // ── 3. Zone check ────────────────────────────────────────────────
            $distancePct      = (($lastClose - $corridorLow) / $corridorLow) * 100;
            $maxDistancePct   = (float)($config['max_distance_from_low_pct'] ?? 15);

            if ($distancePct > $maxDistancePct) {
                // Price is too far above the corridor low — skip
                continue;
            }

            $stats['candidates_found']++;

            // ── 4. Candidate detection / update ─────────────────────────────
            $candidateKey = strtolower($symbol) . '_' . self::STRATEGY_ID;
            $existing     = $this->findCandidate($candidates, $candidateKey);

            if ($existing === null) {
                // New candidate
                $candidate = [
                    'key'           => $candidateKey,
                    'symbol'        => $symbol,
                    'state'         => 'waiting_validation',
                    'detected_at'   => $now,
                    'low_price'     => $corridorLow,
                    'high_price'    => $corridorHigh,
                    'current_price' => $lastClose,
                    'distance_pct'  => round($distancePct, 4),
                ];
            } else {
                // Update price snapshot; keep original detected_at
                $candidate                  = $existing;
                $candidate['current_price'] = $lastClose;
                $candidate['distance_pct']  = round($distancePct, 4);
                // If the corridor shifted significantly, refresh low/high
                $candidate['low_price']     = $corridorLow;
                $candidate['high_price']    = $corridorHigh;
            }

            // ── 5. Validation ────────────────────────────────────────────────
            $priceHistory = $this->loadPriceHistory($symbol, $candles);
            $engine       = new ValidationEngine();
            $result       = $engine->evaluate($candidate, $priceHistory, $config, $now);

            $decision = $result['decision'];

            if ($decision === 'waiting') {
                // Keep candidate active, nothing to emit yet
                $candidate['state'] = 'waiting_validation';
                $candidates         = $this->upsertCandidate($candidates, $candidateKey, $candidate);
                continue;
            }

            if ($decision === 'reject') {
                // Remove from candidates
                $stats['rejected']++;
                $stats['reasons'][] = $symbol . ':' . ($result['reject_reason'] ?? 'rejected');
                $candidates         = $this->removeCandidate($candidates, $candidateKey);
                continue;
            }

            // ── 6. Signal output (enter) ─────────────────────────────────────
            $stats['candidates_validated']++;

            $signal = [
                'signal_id'   => $this->makeSignalId($symbol, $now),
                'symbol'      => $symbol,
                'side'        => 'long',
                'strategy_id' => self::STRATEGY_ID,
                'reason'      => 'validated_bottom_reversal',
                'score'       => $result['score'],
                'score_reasons' => $result['reasons'],
                'low_price'   => $corridorLow,
                'high_price'  => $corridorHigh,
                'entry_price' => $lastClose,
                'distance_pct'=> round($distancePct, 4),
                'emitted_at'  => date('c', $now),
            ];

            // Deduplicate: one active signal per symbol+strategy
            $signals    = $this->upsertSignal($signals, $symbol, $signal);
            $candidate['state'] = 'emitted';
            $candidates = $this->removeCandidate($candidates, $candidateKey);
        }

        // Persist updated state
        $this->writeJson('storage/candidates.json', $candidates);
        $this->writeJson('storage/signals.json',    $signals);

        // ── 7. Last-run debug ─────────────────────────────────────────────────
        $stats['finished_at'] = date('c');
        $this->writeJson('storage/last_run.json', $stats);

        return ['ok' => true, 'stats' => $stats];
    }

    // ── Config ────────────────────────────────────────────────────────────────

    private function loadConfig(): array
    {
        $base   = $this->requireConfig('base.php');
        $active = $this->requireConfig('active.php');
        return array_merge($base, $active);
    }

    private function requireConfig(string $file): array
    {
        $path = $this->moduleDir . '/config/' . $file;
        if (!file_exists($path)) {
            return [];
        }
        $data = require $path;
        return is_array($data) ? $data : [];
    }

    // ── Universe build ────────────────────────────────────────────────────────

    /**
     * Build the symbol list from the market registry (same source as double_bottom_long).
     * Falls back to an empty list on any error.
     */
    private function buildUniverse(array $config): array
    {
        $excluded = (array)($config['excluded_symbols']  ?? []);
        $maxCount = (int)($config['max_symbols_per_run'] ?? 0);

        if ((string)($config['universe_mode'] ?? 'all') === 'manual_list') {
            $symbols = (array)($config['allowed_symbols'] ?? []);
        } else {
            try {
                // Resolve market registry path the same way as double_bottom_long
                if (class_exists(\Core\System\SystemPaths::class, false)) {
                    $registryDir = \Core\System\SystemPaths::instance()
                        ->get('parser.parser1_market_registry');
                } else {
                    $registryDir = dirname(__DIR__, 3)
                        . '/parser/parser1_market_registry';
                }
                $activePath = rtrim($registryDir, '/') . '/storage/active.json';
                $raw        = file_exists($activePath) ? @file_get_contents($activePath) : false;
                $active     = $raw ? json_decode($raw, true) : null;
                if (!is_array($active)) {
                    throw new \RuntimeException('registry parse failed');
                }
                $symbols = array_is_list($active)
                    ? array_values(array_filter(array_column($active, 'symbol')))
                    : array_keys($active);
            } catch (\Throwable) {
                $symbols = [];
            }
        }

        $symbols = array_values(
            array_filter($symbols, fn($s) => !in_array($s, $excluded, true))
        );

        if ($maxCount > 0 && count($symbols) > $maxCount) {
            $symbols = array_slice($symbols, 0, $maxCount);
        }

        return $symbols;
    }

    // ── Candle fetch ──────────────────────────────────────────────────────────

    /**
     * Fetch 1-minute klines from Bybit for the last 24 h.
     * Returns an array of OHLCV records ordered oldest → newest.
     *
     * @return array  [ ['ts'=>int, 'open'=>float, 'high'=>float, 'low'=>float,
     *                   'close'=>float, 'volume'=>float], … ]
     */
    private function fetchCandles(string $symbol, array $config): array
    {
        $interval   = (string)($config['kline_interval']    ?? '1');
        $limit      = (int)($config['lookback_candles']     ?? self::KLINE_LIMIT);
        $baseUrl    = (string)($config['bybit_base_url']    ?? 'https://api.bybit.com');
        $timeoutSec = (int)($config['bybit_timeout_sec']    ?? 10);

        $url = sprintf(
            '%s/v5/market/kline?category=linear&symbol=%s&interval=%s&limit=%d',
            rtrim($baseUrl, '/'),
            urlencode($symbol),
            $interval,
            $limit
        );

        $ctx  = stream_context_create(['http' => ['timeout' => $timeoutSec]]);
        $raw  = @file_get_contents($url, false, $ctx);
        $json = $raw ? json_decode($raw, true) : null;

        if (!$json || ($json['retCode'] ?? -1) !== 0) {
            return [];
        }

        $list    = $json['result']['list'] ?? [];
        $list    = array_reverse($list); // oldest → newest

        $candles = [];
        foreach ($list as $bar) {
            $candles[] = [
                'ts'     => (int)($bar[0] ?? 0),
                'open'   => (float)($bar[1] ?? 0),
                'high'   => (float)($bar[2] ?? 0),
                'low'    => (float)($bar[3] ?? 0),
                'close'  => (float)($bar[4] ?? 0),
                'volume' => (float)($bar[5] ?? 0),
            ];
        }

        return $candles;
    }

    // ── Price history ─────────────────────────────────────────────────────────

    /**
     * Build a tick-level price history for validation.
     *
     * Primary source: PM price_history.json (keyed by symbol).
     * Fallback: derive ticks from the candle close prices.
     *
     * @return array  [ ['ts' => int, 'price' => float], … ]  oldest → newest
     */
    private function loadPriceHistory(string $symbol, array $candles): array
    {
        // Try PM price history first
        $pmPath = dirname(__DIR__, 3)
            . '/prof_manager/profiles/long/storage/price_history.json';

        if (file_exists($pmPath)) {
            $raw  = @file_get_contents($pmPath);
            $data = $raw ? json_decode($raw, true) : null;
            if (is_array($data) && isset($data[$symbol]) && is_array($data[$symbol])) {
                // Normalise to [ ['ts'=>int, 'price'=>float] ]
                $ticks = [];
                foreach ($data[$symbol] as $entry) {
                    if (isset($entry['ts'], $entry['price'])) {
                        $ticks[] = [
                            'ts'    => (int)$entry['ts'],
                            'price' => (float)$entry['price'],
                        ];
                    }
                }
                if (!empty($ticks)) {
                    usort($ticks, fn($a, $b) => $a['ts'] <=> $b['ts']);
                    return $ticks;
                }
            }
        }

        // Fallback: build ticks from candle close prices
        $ticks = [];
        foreach ($candles as $c) {
            if (isset($c['ts'], $c['close'])) {
                $ticks[] = [
                    'ts'    => (int)$c['ts'],
                    'price' => (float)$c['close'],
                ];
            }
        }
        return $ticks;
    }

    // ── Candidate helpers ─────────────────────────────────────────────────────

    /**
     * Find a candidate by its unique key. Returns null when not found.
     */
    private function findCandidate(array $candidates, string $key): ?array
    {
        foreach ($candidates as $c) {
            if (($c['key'] ?? '') === $key) {
                return $c;
            }
        }
        return null;
    }

    /**
     * Insert or replace a candidate by key.
     */
    private function upsertCandidate(array $candidates, string $key, array $record): array
    {
        $replaced = false;
        foreach ($candidates as &$c) {
            if (($c['key'] ?? '') === $key) {
                $c        = $record;
                $replaced = true;
                break;
            }
        }
        unset($c);

        if (!$replaced) {
            $candidates[] = $record;
        }

        return $candidates;
    }

    /**
     * Remove a candidate by key. Cleans up rejected candidates.
     */
    private function removeCandidate(array $candidates, string $key): array
    {
        return array_values(
            array_filter($candidates, fn($c) => ($c['key'] ?? '') !== $key)
        );
    }

    // ── Signal helpers ────────────────────────────────────────────────────────

    /**
     * Upsert a signal for the given symbol (one active signal per symbol+strategy).
     * Deduplicates by symbol to avoid old signals persisting after reset.
     */
    private function upsertSignal(array $signals, string $symbol, array $signal): array
    {
        $replaced = false;
        foreach ($signals as &$s) {
            if (($s['symbol'] ?? '') === $symbol
                && ($s['strategy_id'] ?? '') === self::STRATEGY_ID
            ) {
                $s        = $signal;
                $replaced = true;
                break;
            }
        }
        unset($s);

        if (!$replaced) {
            $signals[] = $signal;
        }

        return $signals;
    }

    /**
     * Generate a unique signal ID.
     */
    private function makeSignalId(string $symbol, int $ts): string
    {
        return sprintf(
            '%s_%s_%d',
            self::STRATEGY_ID,
            strtolower($symbol),
            $ts
        );
    }

    // ── Storage helpers ───────────────────────────────────────────────────────

    private function readJson(string $relPath, mixed $default = null): mixed
    {
        $path = $this->moduleDir . '/' . $relPath;
        if (!file_exists($path)) {
            return $default;
        }
        $raw     = @file_get_contents($path);
        $decoded = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
        return ($decoded !== null) ? $decoded : $default;
    }

    private function writeJson(string $relPath, mixed $data): void
    {
        $path = $this->moduleDir . '/' . $relPath;
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }
}
