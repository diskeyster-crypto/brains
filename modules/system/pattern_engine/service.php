<?php
declare(strict_types=1);

use PatternEngine\PatternDetectorRegistry;
use PatternEngine\UniversalSignalAdapter;
use PatternEngine\ScenarioEngine;

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lib/pattern_detector.php';
require_once __DIR__ . '/lib/signal_adapter.php';
require_once __DIR__ . '/lib/scenario_engine.php';

/**
 * PatternEngineService
 *
 * Orchestrates the full pipeline:
 *   1. Run detectors on market data
 *   2. Normalize detections into universal signals
 *   3. Apply scenario engine on normalized signals
 *   4. Persist candidates / signals / scenarios / runtime
 *
 * Downstream modules (Demo Execution, AI Shadow, Simulator) consume:
 *   - storage/signals/   — normalized signals
 *   - storage/scenarios/ — scenario decisions (with allowed_for_* flags)
 */
final class PatternEngineService
{
    private string $storageDir;
    private UniversalSignalAdapter $adapter;
    private ScenarioEngine $scenarioEngine;

    /** @var array<string,mixed> Runtime stats for the current run (populated by buildRealBatch / buildTestBatch) */
    private array $realRunStats = [];

    public function __construct()
    {
        $this->storageDir = __DIR__ . '/storage';
        $this->ensureDirs();

        $config         = PatternEngineConfig::load();
        $profiles       = (array)($config['scenario_profiles'] ?? []);
        $passportDir    = __DIR__ . '/../coin_passport/storage/passports';
        $liveEnabled    = (bool)($config['live_output_enabled'] ?? false);

        $this->adapter        = new UniversalSignalAdapter();
        $this->scenarioEngine = new ScenarioEngine($profiles, $passportDir, $liveEnabled);

        PatternDetectorRegistry::init();
    }

    // =========================================================================
    // Run pipeline
    // =========================================================================

    /**
     * Trigger a run from the UI or API.
     *
     * Default path: fetches real OHLCV klines from Bybit for active symbols.
     * Smoke-test path (debug only): generates synthetic flat candles.
     *
     * Priority:
     *   1. Explicit $batch provided → use it as-is
     *   2. $smokeTest = true        → buildTestBatch() (debug only)
     *   3. Default                  → buildRealBatch() (real Bybit klines)
     *      If real batch is empty (no symbols / API unreachable) → fall back to smoke
     *
     * @param  list<array<string,mixed>>  $batch      Optional pre-built market data batch
     * @param  bool                       $smokeTest  When true, forces synthetic smoke batch (debug)
     * @return array<string,mixed>
     */
    public function runNow(array $batch = [], bool $smokeTest = false): array
    {
        if (empty($batch)) {
            if ($smokeTest) {
                $batch = $this->buildTestBatch();
            } else {
                $batch = $this->buildRealBatch();
                // If real batch is empty (no symbols available / API unreachable), fall back to smoke
                if (empty($batch)) {
                    $batch = $this->buildTestBatch();
                    $this->realRunStats['run_source'] = 'synthetic_fallback';
                }
            }
        }
        return $this->run($batch);
    }

    /**
     * Build a real market-data batch by fetching OHLCV klines from Bybit's
     * public API for active symbols (sourced from Parser1 or Coin Passport).
     *
     * This is the default path for Run Now — produces real detections when
     * valid chart patterns exist in the current market.
     *
     * Config keys used (under real_run in pattern_engine.json):
     *   max_symbols_per_run   int    How many symbols to scan (default 30)
     *   lookback_candles      int    Klines to request per symbol (default 100)
     *   timeframe             string Bybit interval string: "1","5","15","60",… (default "15")
     *   min_candles_required  int    Skip symbol if fewer valid candles returned (default 30)
     *   bybit_base_url        string Bybit API base (default "https://api.bybit.com")
     *   bybit_timeout_sec     int    HTTP timeout (default 10)
     *
     * @return list<array<string,mixed>>
     */
    public function buildRealBatch(): array
    {
        $config  = PatternEngineConfig::load();
        $realRun = (array)($config['real_run'] ?? []);

        $maxSymbols = (int)($realRun['max_symbols_per_run']  ?? 30);
        $lookback   = (int)($realRun['lookback_candles']     ?? 100);
        $timeframe  = (string)($realRun['timeframe']         ?? '15');
        $minCandles = (int)($realRun['min_candles_required'] ?? 30);
        $bybitBase  = (string)($realRun['bybit_base_url']    ?? 'https://api.bybit.com');
        $timeoutSec = (int)($realRun['bybit_timeout_sec']    ?? 10);

        $this->realRunStats = [
            'run_source'                        => 'bybit_klines',
            'timeframe'                         => $timeframe,
            'lookback_candles'                  => $lookback,
            'symbols_total'                     => 0,
            'symbols_scanned'                   => 0,
            'symbols_skipped_insufficient_data' => 0,
            'symbols_skipped_api_error'         => 0,
        ];

        // 1. Load symbols from Parser1 active registry
        $symbols = $this->loadActiveSymbols();
        if (empty($symbols)) {
            // Fallback: use Coin Passport symbols
            $symbols = $this->loadPassportSymbols();
            $this->realRunStats['run_source'] = 'bybit_klines_passport_fallback';
        }

        if (empty($symbols)) {
            return [];
        }

        // Shuffle for variety across runs, then cap at max
        shuffle($symbols);
        $symbols = array_slice($symbols, 0, $maxSymbols);
        $this->realRunStats['symbols_total'] = count($symbols);

        $timeWindowMinutes = $this->timeframeToMinutes($timeframe);
        $batch = [];

        foreach ($symbols as $symbol) {
            $candles = $this->fetchBybitKlines($symbol, $timeframe, $lookback, $bybitBase, $timeoutSec);

            if ($candles === null) {
                $this->realRunStats['symbols_skipped_api_error']++;
                continue;
            }

            if (count($candles) < $minCandles) {
                $this->realRunStats['symbols_skipped_insufficient_data']++;
                continue;
            }

            $this->realRunStats['symbols_scanned']++;
            $batch[] = [
                'symbol'              => $symbol,
                'time_window_minutes' => $timeWindowMinutes,
                'candles'             => $candles,
            ];
        }

        return $batch;
    }

    /**
     * Build a minimal synthetic market-data batch — for smoke-testing only.
     *
     * Uses flat 2-candle entries so detectors traverse the pipeline without
     * crashing but produce no detections. Not for normal user runs.
     *
     * [INTERNAL DEBUG ASSET — not part of main user flow]
     *
     * @return list<array<string,mixed>>
     */
    public function buildTestBatch(): array
    {
        $config = PatternEngineConfig::load();
        $window = (int)($config['default_time_window_minutes'] ?? 15);

        $this->realRunStats = [
            'run_source'                        => 'synthetic_smoke',
            'timeframe'                         => (string)$window . 'm',
            'lookback_candles'                  => 2,
            'symbols_total'                     => 0,
            'symbols_scanned'                   => 0,
            'symbols_skipped_insufficient_data' => 0,
            'symbols_skipped_api_error'         => 0,
        ];

        $symbols = $this->loadPassportSymbols();
        $symbols = array_slice($symbols, 0, 20);
        $this->realRunStats['symbols_total'] = count($symbols);

        $batch = [];
        foreach ($symbols as $symbol) {
            $this->realRunStats['symbols_scanned']++;
            $batch[] = [
                'symbol'              => $symbol,
                'time_window_minutes' => $window,
                'candles'             => [
                    ['ts_unix' => time() - 60, 'open' => 1.0, 'high' => 1.01, 'low' => 0.99, 'close' => 1.0, 'volume' => 0],
                    ['ts_unix' => time(),       'open' => 1.0, 'high' => 1.01, 'low' => 0.99, 'close' => 1.0, 'volume' => 0],
                ],
            ];
        }

        return $batch;
    }

    // =========================================================================
    // Real data helpers
    // =========================================================================

    /**
     * Load active symbols from Parser1 Market Registry (active.json).
     *
     * Falls back gracefully to an empty array if Parser1 data is unavailable.
     *
     * @return list<string>
     */
    private function loadActiveSymbols(): array
    {
        $path = __DIR__ . '/../../parser/parser1_market_registry/storage/active.json';
        if (!file_exists($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }
        // active.json is an associative map {SYMBOL: {...}} or a plain list
        if (array_is_list($data)) {
            return array_values(array_filter(array_map('strval', $data)));
        }
        return array_keys($data);
    }

    /**
     * Load symbols from local Coin Passport storage as a fallback symbol source.
     *
     * @return list<string>
     */
    private function loadPassportSymbols(): array
    {
        $passportDir = __DIR__ . '/../coin_passport/storage/passports';
        if (!is_dir($passportDir)) {
            return [];
        }
        $files = glob($passportDir . '/*.json') ?: [];
        return array_map(
            static fn(string $f): string => strtoupper(basename($f, '.json')),
            $files
        );
    }

    /**
     * Fetch OHLCV klines from Bybit's public market API.
     *
     * Returns null on network/API error so the caller can count skipped symbols.
     * Returns an empty array when the symbol exists but has no candle history.
     *
     * Bybit kline response (result.list):
     *   Each entry: [startTimeMs, open, high, low, close, volume, turnover]
     *   Sorted newest-first; reversed here for chronological order.
     *
     * @return list<array{ts_unix:int,open:float,high:float,low:float,close:float,volume:float}>|null
     */
    private function fetchBybitKlines(
        string $symbol,
        string $interval,
        int    $limit,
        string $bybitBase,
        int    $timeoutSec
    ): ?array {
        $url = rtrim($bybitBase, '/') . '/v5/market/kline?' . http_build_query([
            'category' => 'linear',
            'symbol'   => $symbol,
            'interval' => $interval,
            'limit'    => min($limit, 1000),
        ]);

        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => $timeoutSec,
                'header'  => "Accept: application/json\r\nUser-Agent: pattern-engine/1.0\r\n",
            ],
        ]);

        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) {
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || (int)($data['retCode'] ?? -1) !== 0) {
            return null;
        }

        $list = (array)($data['result']['list'] ?? []);
        if (empty($list)) {
            return [];
        }

        // Bybit returns newest-first — reverse for chronological order
        $list = array_reverse($list);

        $candles = [];
        foreach ($list as $kline) {
            if (!is_array($kline) || count($kline) < 6) {
                continue;
            }
            $open  = (float)($kline[1] ?? 0);
            $high  = (float)($kline[2] ?? 0);
            $low   = (float)($kline[3] ?? 0);
            $close = (float)($kline[4] ?? 0);
            if ($open <= 0.0 || $close <= 0.0) {
                continue;
            }
            $candles[] = [
                'ts_unix' => (int)((int)($kline[0] ?? 0) / 1000),
                'open'    => $open,
                'high'    => $high,
                'low'     => $low,
                'close'   => $close,
                'volume'  => (float)($kline[5] ?? 0),
            ];
        }

        return $candles;
    }

    /**
     * Convert a Bybit interval string to minutes.
     */
    private function timeframeToMinutes(string $timeframe): int
    {
        return match ($timeframe) {
            '1'   => 1,
            '3'   => 3,
            '5'   => 5,
            '15'  => 15,
            '30'  => 30,
            '60'  => 60,
            '120' => 120,
            '240' => 240,
            '360' => 360,
            '720' => 720,
            'D'   => 1440,
            'W'   => 10080,
            'M'   => 43200,
            default => 15,
        };
    }

    /**
     * Run the full pipeline on a batch of market data slices.
     *
     * @param  list<array<string,mixed>>  $marketDataBatch  One entry per symbol/timeframe
     * @return array<string,mixed>  {candidates, signals, scenarios, stats}
     */
    public function run(array $marketDataBatch): array
    {
        $config         = PatternEngineConfig::load();
        $detectorCfg    = (array)($config['detector_config'] ?? []);
        $allRaw         = [];
        $allSignals     = [];
        $allScenarios   = [];

        foreach ($marketDataBatch as $marketData) {
            $symbol     = (string)($marketData['symbol'] ?? '');
            $marketCtx  = [
                'symbol'       => $symbol,
                'time_window'  => $marketData['time_window_minutes'] ?? 15,
                'last_price'   => $marketData['candles'][array_key_last($marketData['candles'] ?? [])]['close'] ?? null,
            ];

            foreach (PatternDetectorRegistry::all() as $algo => $detector) {
                $cfg        = (array)($detectorCfg[$algo] ?? []);
                $detections = $detector->detect($marketData, $cfg);
                foreach ($detections as $raw) {
                    $allRaw[] = $raw;
                    $signal   = $this->adapter->normalize($raw, $marketCtx);
                    $allSignals[]   = $signal;
                    $allScenarios[] = $this->scenarioEngine->evaluate($signal);
                }
            }
        }

        // Trim to storage limits
        $maxCand = (int)($config['storage']['max_candidates_per_run'] ?? 200);
        $maxSig  = (int)($config['storage']['max_signals_stored'] ?? 500);
        $maxScen = (int)($config['storage']['max_scenarios_stored'] ?? 500);

        $allRaw       = array_slice($allRaw, 0, $maxCand);
        $allSignals   = array_slice($allSignals, 0, $maxSig);
        $allScenarios = array_slice($allScenarios, 0, $maxScen);

        $stats = $this->computeStats($allRaw, $allSignals, $allScenarios);

        // Persist
        $this->saveCandidates($allRaw);
        $this->saveSignals($allSignals);
        $this->saveScenarios($allScenarios);
        $this->saveLastRun($stats);

        return [
            'candidates' => $allRaw,
            'signals'    => $allSignals,
            'scenarios'  => $allScenarios,
            'stats'      => $stats,
        ];
    }

    // =========================================================================
    // Read helpers (for UI / downstream consumers)
    // =========================================================================

    /**
     * Load all stored candidates.
     *
     * @return list<array<string,mixed>>
     */
    public function getCandidates(): array
    {
        return $this->loadJsonList($this->storageDir . '/candidates/candidates.json');
    }

    /**
     * Load all stored normalized signals.
     *
     * @return list<array<string,mixed>>
     */
    public function getSignals(): array
    {
        return $this->loadJsonList($this->storageDir . '/signals/signals.json');
    }

    /**
     * Load all stored scenario decisions.
     *
     * @return list<array<string,mixed>>
     */
    public function getScenarios(): array
    {
        return $this->loadJsonList($this->storageDir . '/scenarios/scenarios.json');
    }

    /**
     * Load the most recent run stats.
     *
     * @return array<string,mixed>
     */
    public function getLastRun(): array
    {
        $path = $this->storageDir . '/runtime/last_run.json';
        if (!file_exists($path)) {
            return [];
        }
        $d = json_decode((string)file_get_contents($path), true);
        return is_array($d) ? $d : [];
    }

    /**
     * Load the runtime stats.
     *
     * @return array<string,mixed>
     */
    public function getStats(): array
    {
        $path = $this->storageDir . '/runtime/stats.json';
        if (!file_exists($path)) {
            return [];
        }
        $d = json_decode((string)file_get_contents($path), true);
        return is_array($d) ? $d : [];
    }

    /**
     * Return the current config.
     *
     * @return array<string,mixed>
     */
    public function getConfig(): array
    {
        return PatternEngineConfig::load();
    }

    /**
     * Save updated config.
     *
     * @param array<string,mixed> $config
     */
    public function saveConfig(array $config): bool
    {
        return PatternEngineConfig::save($config);
    }

    /**
     * Return signals allowed for demo execution (allowed_for_demo = true in scenario).
     *
     * @return list<array<string,mixed>>
     */
    public function getDemoSignals(): array
    {
        return $this->filterScenariosByMode('allowed_for_demo');
    }

    /**
     * Return signals allowed for AI Shadow (allowed_for_shadow = true in scenario).
     *
     * @return list<array<string,mixed>>
     */
    public function getShadowSignals(): array
    {
        return $this->filterScenariosByMode('allowed_for_shadow');
    }

    /**
     * Return signals allowed for Simulator (allowed_for_sim = true in scenario).
     *
     * @return list<array<string,mixed>>
     */
    public function getSimSignals(): array
    {
        return $this->filterScenariosByMode('allowed_for_sim');
    }

    /**
     * Clear all storage (for maintenance / UI reset).
     */
    public function clearStorage(): void
    {
        foreach (['candidates/candidates.json', 'signals/signals.json', 'scenarios/scenarios.json'] as $f) {
            $path = $this->storageDir . '/' . $f;
            if (file_exists($path)) {
                @unlink($path);
            }
        }
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /** @param list<array<string,mixed>> $scenarios */
    private function filterScenariosByMode(string $flag): array
    {
        $scenarios = $this->getScenarios();
        $signals   = $this->getSignals();
        $sigMap    = [];
        foreach ($signals as $s) {
            $sigMap[$s['signal_id'] ?? ''] = $s;
        }

        $result = [];
        foreach ($scenarios as $sc) {
            if (!empty($sc[$flag])) {
                $sid = $sc['signal_id'] ?? '';
                $result[] = array_merge($sc, ['signal' => $sigMap[$sid] ?? null]);
            }
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function computeStats(array $candidates, array $signals, array $scenarios): array
    {
        $perPattern  = [];
        $perSymbol   = [];
        $statusCounts = [];

        foreach ($candidates as $c) {
            $algo = $c['pattern_algorithm'] ?? 'unknown';
            $sym  = $c['symbol'] ?? 'unknown';
            $perPattern[$algo] = ($perPattern[$algo] ?? 0) + 1;
            $perSymbol[$sym]   = ($perSymbol[$sym] ?? 0) + 1;
        }

        foreach ($scenarios as $sc) {
            $status = $sc['scenario_status'] ?? 'unknown';
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
        }

        return [
            'generated_at'                      => date('c'),
            'run_source'                        => $this->realRunStats['run_source'] ?? 'unknown',
            'timeframe'                         => $this->realRunStats['timeframe'] ?? '',
            'lookback_candles'                  => $this->realRunStats['lookback_candles'] ?? 0,
            'symbols_total'                     => $this->realRunStats['symbols_total'] ?? 0,
            'symbols_scanned'                   => $this->realRunStats['symbols_scanned'] ?? 0,
            'symbols_skipped_insufficient_data' => $this->realRunStats['symbols_skipped_insufficient_data'] ?? 0,
            'symbols_skipped_api_error'         => $this->realRunStats['symbols_skipped_api_error'] ?? 0,
            'candidates_count'                  => count($candidates),
            'signals_count'                     => count($signals),
            'scenarios_count'                   => count($scenarios),
            'per_pattern'                       => $perPattern,
            'per_symbol'                        => $perSymbol,
            'status_counts'                     => $statusCounts,
        ];
    }

    /** @param list<array<string,mixed>> $candidates */
    private function saveCandidates(array $candidates): void
    {
        $this->writeJson($this->storageDir . '/candidates/candidates.json', $candidates);
    }

    /** @param list<array<string,mixed>> $signals */
    private function saveSignals(array $signals): void
    {
        $this->writeJson($this->storageDir . '/signals/signals.json', $signals);
    }

    /** @param list<array<string,mixed>> $scenarios */
    private function saveScenarios(array $scenarios): void
    {
        $this->writeJson($this->storageDir . '/scenarios/scenarios.json', $scenarios);
    }

    /** @param array<string,mixed> $stats */
    private function saveLastRun(array $stats): void
    {
        $this->writeJson($this->storageDir . '/runtime/last_run.json', $stats);

        // Accumulate into stats.json (append run history, keep last 100 runs)
        $statsPath   = $this->storageDir . '/runtime/stats.json';
        $existing    = [];
        if (file_exists($statsPath)) {
            $d = json_decode((string)file_get_contents($statsPath), true);
            $existing = is_array($d) ? $d : [];
        }
        $history = (array)($existing['runs'] ?? []);
        array_unshift($history, $stats);
        $history = array_slice($history, 0, 100);

        $this->writeJson($statsPath, [
            'last_run'         => $stats,
            'total_runs'       => count($history),
            'runs'             => $history,
        ]);
    }

    /** @param list<array<string,mixed>> $data */
    private function loadJsonList(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }
        $d = json_decode((string)file_get_contents($path), true);
        return is_array($d) ? $d : [];
    }

    private function writeJson(string $path, mixed $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            $tmp = $path . '.tmp';
            file_put_contents($tmp, $json, LOCK_EX);
            rename($tmp, $path);
        }
    }

    private function ensureDirs(): void
    {
        foreach (['candidates', 'signals', 'scenarios', 'runtime', 'logs'] as $dir) {
            $full = $this->storageDir . '/' . $dir;
            if (!is_dir($full)) {
                @mkdir($full, 0755, true);
            }
        }
    }
}
