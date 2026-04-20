<?php

declare(strict_types=1);

/**
 * Fish Strategy — Service
 *
 * Orchestrates one full Рыбалка scanner cycle:
 *   1. Load + validate config via FishBootstrap
 *   2. Build symbol universe (registry or manual_list)
 *   3. For each symbol: fetch H4 candles from Bybit
 *   4. Analyse H4 trend structure (swing highs/lows)
 *   5. Detect liquidity levels (3–4 bar consolidation patterns)
 *   6. For each valid level + trend: calculate entry, stop, TP, BE
 *   7. Assemble signal objects and write signals.json
 *   8. Update stats.json, last_run.json, runtime_snapshot.php
 *
 * No order placement. No Trading Bot wiring. Scanner only.
 */

namespace Modules\Strategy\Fish;

final class FishService
{
    private static ?self $instance = null;

    private string $moduleDir;

    /** Bybit kline interval string for H4 */
    private const H4_INTERVAL = '240';

    private function __construct(string $moduleDir)
    {
        $this->moduleDir = rtrim($moduleDir, '/');
    }

    public static function instance(string $moduleDir): self
    {
        if (self::$instance === null) {
            self::$instance = new self($moduleDir);
        }
        return self::$instance;
    }

    // =========================================================================
    // Main run cycle
    // =========================================================================

    /**
     * Execute one scanner cycle.
     *
     * @return array  Run result with diagnostics
     */
    public function run(): array
    {
        $startMs = (int)round(microtime(true) * 1000);
        $runAt   = date('c');

        // Bootstrap: load + validate config
        require_once $this->moduleDir . '/bootstrap.php';
        $bootstrap = FishBootstrap::instance($this->moduleDir);

        try {
            $boot = $bootstrap->load();
        } catch (\Throwable $e) {
            $result = $this->failResult('Bootstrap failed: ' . $e->getMessage(), [], $startMs, $runAt);
            $this->persist($result, null, []);
            return $result;
        }

        if (!$boot['valid']) {
            $msg    = 'Config validation failed: ' . implode('; ', $boot['errors']);
            $result = $this->failResult($msg, $boot['errors'], $startMs, $runAt);
            $this->persist($result, $boot['config'], []);
            return $result;
        }

        $config = $boot['config'];

        // Module disabled or explicitly disabled mode — skip, report clean
        if (!($config['enabled'] ?? false) || ($config['mode'] ?? 'disabled') === 'disabled') {
            $result = $this->okResult(
                'Strategy loaded. Config valid. Module is disabled — scanner not run.',
                $config, $startMs, $runAt, []
            );
            $this->persist($result, $config, []);
            return $result;
        }

        // Load logic modules
        require_once $this->moduleDir . '/logic/universe.php';
        require_once $this->moduleDir . '/logic/structure.php';
        require_once $this->moduleDir . '/logic/liquidity_level.php';
        require_once $this->moduleDir . '/logic/entry.php';
        require_once $this->moduleDir . '/logic/risk.php';
        require_once $this->moduleDir . '/logic/signal.php';

        // Run scanner
        [$signals, $diagnostics] = $this->scan($config, $runAt);

        $result = $this->okResult(
            sprintf(
                'Scanner completed. Scanned: %d symbols. Valid signals: %d.',
                $diagnostics['symbols_scanned'],
                $diagnostics['signals_valid']
            ),
            $config, $startMs, $runAt, $diagnostics
        );
        $result['signals_found'] = $diagnostics['signals_valid'];

        $this->persist($result, $config, $signals);

        return $result;
    }

    // =========================================================================
    // Scanner pipeline
    // =========================================================================

    /**
     * Main scanner: universe → candles → structure → levels → signals.
     *
     * @return array{0: list<array>, 1: array}  [signals, diagnostics]
     */
    private function scan(array $config, string $runAt): array
    {
        $diag = [
            'symbols_total'           => 0,
            'symbols_scanned'         => 0,
            'symbols_skipped_no_data' => 0,
            'symbols_skipped_api_err' => 0,
            'structures_valid'        => 0,
            'structures_invalid'      => 0,
            'levels_found'            => 0,
            'levels_expired'          => 0,
            'candidates_valid'        => 0,
            'candidates_rejected'     => 0,
            'signals_valid'           => 0,
            'reject_reasons'          => [],
        ];

        // 1. Build universe
        $universe = new \Modules\Strategy\Fish\Logic\FishUniverse($this->moduleDir);
        $univResult = $universe->build($config);
        $symbols    = $univResult['symbols'];
        $diag['symbols_total'] = count($symbols);

        if (empty($symbols)) {
            return [[], $diag];
        }

        $structure      = new \Modules\Strategy\Fish\Logic\FishStructure();
        $levelDetector  = new \Modules\Strategy\Fish\Logic\FishLiquidityLevel();
        $entryCalc      = new \Modules\Strategy\Fish\Logic\FishEntry();
        $riskCalc       = new \Modules\Strategy\Fish\Logic\FishRisk();
        $signalBuilder  = new \Modules\Strategy\Fish\Logic\FishSignal();

        $pivotWindow    = (int)($config['structure_pivot_window']       ?? 3);
        $minBars        = (int)($config['liquidity_pattern_min_bars']   ?? 3);
        $maxBars        = (int)($config['liquidity_pattern_max_bars']   ?? 4);
        $tolerance      = (float)($config['liquidity_level_tolerance']  ?? 0.003);
        $confirmReq     = (bool)($config['confirm_bar_required']        ?? true);
        $maxAgeBars     = (int)($config['level_max_age_bars']           ?? 20);
        $tpMult         = (float)($config['tp_multiplier']              ?? 2.0);
        $beMult         = (float)($config['breakeven_trigger_multiplier'] ?? 1.0);
        $lookback       = (int)($config['lookback_candles']             ?? 100);
        $bybitBase      = (string)($config['bybit_base_url']            ?? 'https://api.bybit.com');
        $timeoutSec     = (int)($config['bybit_timeout_sec']            ?? 10);

        $allSignals = [];
        // Deduplicate signals by signal_id
        $seenSignalIds = [];

        foreach ($symbols as $symbol) {
            // 2. Fetch H4 candles
            $candles = $this->fetchKlines($symbol, self::H4_INTERVAL, $lookback, $bybitBase, $timeoutSec);

            if ($candles === null) {
                $diag['symbols_skipped_api_err']++;
                $this->bumpRejectReason($diag['reject_reasons'], 'api_error');
                continue;
            }

            if (count($candles) < ($pivotWindow * 2 + $minBars + 2)) {
                $diag['symbols_skipped_no_data']++;
                $this->bumpRejectReason($diag['reject_reasons'], 'insufficient_candles');
                continue;
            }

            $diag['symbols_scanned']++;

            // 3. Analyse trend structure
            $structResult = $structure->analyse($candles, $pivotWindow);

            if (!$structResult['valid']) {
                $diag['structures_invalid']++;
                $this->bumpRejectReason($diag['reject_reasons'], $structResult['reject_reason'] ?? 'invalid_structure');
                continue;
            }

            $trend = $structResult['trend_direction'];

            if ($trend === 'ranging' || $trend === 'unknown') {
                $diag['structures_invalid']++;
                $this->bumpRejectReason($diag['reject_reasons'], 'ranging_or_unknown_trend');
                continue;
            }

            $diag['structures_valid']++;

            // Map trend to trade side
            $side = ($trend === 'bullish') ? 'long' : 'short';

            // 4. Detect liquidity levels
            $levels = $levelDetector->detect(
                $candles, $minBars, $maxBars, $tolerance, $confirmReq, $maxAgeBars
            );

            if (empty($levels)) {
                $this->bumpRejectReason($diag['reject_reasons'], 'no_levels_found');
                continue;
            }

            foreach ($levels as $level) {
                if ($level['status'] === 'expired') {
                    $diag['levels_expired']++;
                    $this->bumpRejectReason($diag['reject_reasons'], 'level_expired');
                    continue;
                }

                $diag['levels_found']++;

                // 5. Calculate entry
                $entry = $entryCalc->calculate($side, $level);

                // 6. Calculate risk
                $risk = $riskCalc->calculate(
                    $side,
                    $entry['entry_price'],
                    $level,
                    $structResult,
                    $tpMult,
                    $beMult
                );

                if (!$risk['valid']) {
                    $diag['candidates_rejected']++;
                    $this->bumpRejectReason($diag['reject_reasons'], $risk['reject_reason'] ?? 'risk_invalid');
                    continue;
                }

                $diag['candidates_valid']++;

                // 7. Build signal
                $signal = $signalBuilder->build(
                    $symbol, $side, $entry, $risk, $level, $structResult, $config, $runAt
                );

                $sid = $signal['signal_id'];
                if (isset($seenSignalIds[$sid])) {
                    continue;  // skip duplicate
                }
                $seenSignalIds[$sid] = true;

                $allSignals[] = $signal;
                $diag['signals_valid']++;
            }
        }

        return [$allSignals, $diag];
    }

    // =========================================================================
    // Bybit H4 candle fetcher
    // =========================================================================

    /**
     * Fetch klines from the Bybit public API.
     * Returns null on any HTTP/parse error.
     * Returns candle array ordered oldest → newest.
     *
     * Bybit kline row format: [startTime, open, high, low, close, volume, turnover]
     *
     * @return list<array>|null
     */
    private function fetchKlines(
        string $symbol,
        string $interval,
        int    $limit,
        string $baseUrl,
        int    $timeoutSec
    ): ?array {
        $url = rtrim($baseUrl, '/') . '/v5/market/kline'
            . '?category=linear'
            . '&symbol=' . urlencode($symbol)
            . '&interval=' . urlencode($interval)
            . '&limit=' . $limit;

        $ctx = stream_context_create([
            'http' => [
                'timeout' => $timeoutSec,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || ($decoded['retCode'] ?? -1) !== 0) {
            return null;
        }

        $list = $decoded['result']['list'] ?? [];
        if (!is_array($list) || empty($list)) {
            return null;
        }

        // Bybit returns newest first — reverse to get oldest first
        return array_reverse($list);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function bumpRejectReason(array &$reasons, string $key): void
    {
        $reasons[$key] = ($reasons[$key] ?? 0) + 1;
    }

    private function failResult(string $message, array $errors, int $startMs, string $runAt): array
    {
        return $this->buildResult('error', false, $errors, $message, $startMs, $runAt, []);
    }

    private function okResult(
        string $message,
        array  $config,
        int    $startMs,
        string $runAt,
        array  $diagnostics
    ): array {
        return $this->buildResult('ok', true, [], $message, $startMs, $runAt, $diagnostics);
    }

    private function buildResult(
        string $status,
        bool   $configValid,
        array  $configErrors,
        string $message,
        int    $startMs,
        string $runAt,
        array  $diagnostics
    ): array {
        $durationMs = (int)round(microtime(true) * 1000) - $startMs;

        return [
            'strategy_id'   => 'fish',
            'status'        => $status,
            'config_valid'  => $configValid,
            'config_errors' => $configErrors,
            'message'       => $message,
            'run_at'        => $runAt,
            'duration_ms'   => $durationMs,
            'signals_found' => $diagnostics['signals_valid'] ?? 0,
            'orders_placed' => 0,
            'errors_count'  => $configValid ? 0 : count($configErrors),
            'diagnostics'   => $diagnostics,
        ];
    }

    // =========================================================================
    // Persistence
    // =========================================================================

    private function persist(array $result, ?array $config, array $signals): void
    {
        $this->writeRuntimeSnapshot($result, $config);
        $this->writeLastRun($result);
        $this->writeSignals($signals);
        $this->updateStats($result);
    }

    private function writeRuntimeSnapshot(array $result, ?array $config): void
    {
        $snapshot = [
            'strategy_id'      => 'fish',
            'snapshot_version' => '0.1.0',
            'generated_at'     => $result['run_at'],
            'effective_config' => $config ?? [],
            'config_valid'     => $result['config_valid'],
            'config_errors'    => $result['config_errors'],
            'status'           => $result['status'],
        ];

        $export = '<?php' . "\n\n"
            . "declare(strict_types=1);\n\n"
            . "/**\n"
            . " * Fish Strategy — Runtime Snapshot\n"
            . " *\n"
            . " * Auto-generated by service.php. Do NOT edit manually.\n"
            . " * Generated: " . $result['run_at'] . "\n"
            . " */\n\n"
            . 'return ' . var_export($snapshot, true) . ";\n";

        @file_put_contents($this->moduleDir . '/config/runtime_snapshot.php', $export);
    }

    private function writeLastRun(array $result): void
    {
        @file_put_contents(
            $this->moduleDir . '/storage/last_run.json',
            json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"
        );
    }

    private function writeSignals(array $signals): void
    {
        @file_put_contents(
            $this->moduleDir . '/storage/signals.json',
            json_encode($signals, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"
        );
    }

    private function updateStats(array $result): void
    {
        $path = $this->moduleDir . '/storage/stats.json';
        $stats = $this->loadStorage('stats.json');

        if (empty($stats)) {
            $stats = [
                'strategy_id'         => 'fish',
                'total_runs'          => 0,
                'successful_runs'     => 0,
                'failed_runs'         => 0,
                'signals_found_total' => 0,
                'orders_placed_total' => 0,
                'errors_count'        => 0,
                'last_updated'        => null,
            ];
        }

        $stats['total_runs']++;
        if ($result['status'] === 'ok') {
            $stats['successful_runs']++;
        } else {
            $stats['failed_runs']++;
        }
        $stats['signals_found_total'] += ($result['signals_found'] ?? 0);
        $stats['errors_count']        += ($result['errors_count'] ?? 0);
        $stats['last_updated']         = $result['run_at'];

        @file_put_contents(
            $path,
            json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"
        );
    }

    // =========================================================================
    // Read-only accessors for admin pages
    // =========================================================================

    public function getConfig(): array
    {
        try {
            require_once $this->moduleDir . '/bootstrap.php';
            $boot = FishBootstrap::instance($this->moduleDir)->load();
            return $boot['config'];
        } catch (\Throwable) {
            return [];
        }
    }

    public function getRuntimeSnapshot(): array
    {
        $path = $this->moduleDir . '/config/runtime_snapshot.php';
        if (!file_exists($path)) {
            return [];
        }
        try {
            $data = require $path;
            return is_array($data) ? $data : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function getLastRun(): array
    {
        $path = $this->moduleDir . '/storage/last_run.json';
        if (!file_exists($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public function loadStorage(string $filename): array
    {
        $path = $this->moduleDir . '/storage/' . $filename;
        if (!file_exists($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public function getStats(): array
    {
        return $this->loadStorage('stats.json');
    }
}

