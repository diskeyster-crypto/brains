<?php
declare(strict_types=1);

use PatternEngine\PatternDetectorRegistry;
use PatternEngine\UniversalSignalAdapter;
use PatternEngine\ScenarioEngine;
use PatternEngine\SymbolNormalizer;

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lib/pattern_detector.php';
require_once __DIR__ . '/lib/signal_adapter.php';
require_once __DIR__ . '/lib/scenario_engine.php';
require_once __DIR__ . '/lib/symbol_normalizer.php';

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
    private SymbolNormalizer $symbolNormalizer;

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
        $downstreamPolicy = (array)($config['downstream_policy'] ?? []);

        $this->adapter          = new UniversalSignalAdapter();
        $this->scenarioEngine   = new ScenarioEngine($profiles, $passportDir, $liveEnabled, $downstreamPolicy);
        $this->symbolNormalizer = new SymbolNormalizer($passportDir);

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

        $useInternalFirst = (bool)($realRun['use_internal_source_first'] ?? true);

        $this->realRunStats = [
            'run_source'                        => 'bybit_klines',
            'primary_data_source'               => $useInternalFirst ? 'parser2_internal' : 'bybit_klines',
            'fallback_data_source_used'         => false,
            'timeframe'                         => $timeframe,
            'lookback_candles'                  => $lookback,
            'symbols_total'                     => 0,
            'symbols_scanned'                   => 0,
            'symbols_skipped_insufficient_data' => 0,
            'symbols_skipped_api_error'         => 0,
            'symbols_used_internal'             => 0,
            'symbols_used_bybit_fallback'       => 0,
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

        // --- Universe overlap analysis + policy ---
        $passportSymbolsFull = $this->loadPassportSymbols();
        $passportSet         = array_flip(array_map('strtoupper', $passportSymbolsFull));

        $symbolsWithPassport    = [];
        $symbolsWithoutPassport = [];
        foreach ($symbols as $sym) {
            if (isset($passportSet[strtoupper($sym)])) {
                $symbolsWithPassport[] = $sym;
            } else {
                $symbolsWithoutPassport[] = $sym;
            }
        }

        $patternTotal  = count($symbols);
        $overlapCount  = count($symbolsWithPassport);
        $passportTotal = count($passportSymbolsFull);
        $overlapRate   = $patternTotal > 0 ? round($overlapCount / $patternTotal, 4) : 0.0;

        $policyBlock        = (array)($realRun['symbol_universe_policy'] ?? []);
        $policyMode         = (string)($policyBlock['mode']                        ?? 'passport_preferred');
        $maxWithoutPassport = (int)($policyBlock['max_symbols_without_passport']   ?? 10);

        $this->realRunStats['pattern_symbols_total']                  = $patternTotal;
        $this->realRunStats['passport_symbols_total']                 = $passportTotal;
        $this->realRunStats['symbol_universe_overlap_count']          = $overlapCount;
        $this->realRunStats['symbol_universe_overlap_rate']           = $overlapRate;
        $this->realRunStats['pattern_symbols_with_passport_count']    = $overlapCount;
        $this->realRunStats['pattern_symbols_without_passport_count'] = count($symbolsWithoutPassport);
        $this->realRunStats['symbols_with_passport']                  = array_slice($symbolsWithPassport, 0, 100);
        $this->realRunStats['symbols_without_passport']               = array_slice($symbolsWithoutPassport, 0, 100);
        $this->realRunStats['active_universe_policy']                 = $policyMode;

        // Apply policy to build the final symbol list
        shuffle($symbolsWithPassport);
        shuffle($symbolsWithoutPassport);
        if ($policyMode === 'passport_only') {
            $symbols = array_slice($symbolsWithPassport, 0, $maxSymbols);
        } elseif ($policyMode === 'passport_preferred') {
            $withPass    = array_slice($symbolsWithPassport, 0, $maxSymbols);
            $allowedNoPP = max(0, $maxSymbols - count($withPass));
            $withoutPass = array_slice($symbolsWithoutPassport, 0, min($maxWithoutPassport, $allowedNoPP));
            $symbols = array_merge($withPass, $withoutPass);
            shuffle($symbols);
            $symbols = array_slice($symbols, 0, $maxSymbols);
        } else {
            // all_active: original behaviour
            shuffle($symbols);
            $symbols = array_slice($symbols, 0, $maxSymbols);
        }

        $this->realRunStats['symbols_total'] = count($symbols);

        $timeWindowMinutes = $this->timeframeToMinutes($timeframe);
        $batch = [];

        foreach ($symbols as $symbol) {
            $candles      = null;
            $usedInternal = false;

            // 1. Try internal Parser2 history first (if configured)
            if ($useInternalFirst) {
                $candles = $this->loadInternalCandlesForSymbol($symbol, $timeframe, $minCandles);
                if ($candles !== null && count($candles) >= $minCandles) {
                    $usedInternal = true;
                } else {
                    $candles = null; // insufficient internal data — fall through to Bybit
                }
            }

            // 2. Fall back to direct Bybit klines if internal source unavailable
            if ($candles === null) {
                $candles = $this->fetchBybitKlines($symbol, $timeframe, $lookback, $bybitBase, $timeoutSec);
                if ($candles !== null) {
                    $this->realRunStats['symbols_used_bybit_fallback']++;
                    if ($useInternalFirst) {
                        $this->realRunStats['fallback_data_source_used'] = true;
                    }
                }
            } else {
                $this->realRunStats['symbols_used_internal']++;
            }

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
                'data_source'         => $usedInternal ? 'parser2_internal' : 'bybit_klines',
            ];
        }

        // Update run_source to reflect actual data sources used
        $internal = (int)($this->realRunStats['symbols_used_internal']      ?? 0);
        $bybit    = (int)($this->realRunStats['symbols_used_bybit_fallback'] ?? 0);
        if ($internal > 0 && $bybit > 0) {
            $this->realRunStats['run_source'] = 'mixed_internal_bybit';
        } elseif ($internal > 0) {
            $this->realRunStats['run_source'] = 'parser2_internal';
        }
        // else remains 'bybit_klines'

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

    public function run(array $marketDataBatch): array
    {
        $config      = PatternEngineConfig::load();
        $detectorCfg = (array)($config['detector_config'] ?? []);
        $antiFlood   = (array)($config['anti_flood']      ?? []);

        // Collect all detections as aligned triplets {raw, signal, scenario}
        $allCombined = [];

        // Symbol normalization tracking — normalize once per unique symbol across the entire run
        $normalizedSymbolMap              = [];
        $symbolsNormalizedCount           = 0;
        $symbolsNormalizationFailedCount  = 0;

        foreach ($marketDataBatch as $marketData) {
            $symbol    = (string)($marketData['symbol'] ?? '');

            // Normalize symbol once per unique symbol in this run
            if (!isset($normalizedSymbolMap[$symbol])) {
                $normInfo = $this->symbolNormalizer->normalize($symbol);
                $normalizedSymbolMap[$symbol] = $normInfo;

                $normStatus = $normInfo['symbol_normalization_status'];
                if ($normStatus === 'failed') {
                    $symbolsNormalizationFailedCount++;
                } elseif ($normStatus !== 'unchanged') {
                    $symbolsNormalizedCount++;
                }
            } else {
                $normInfo = $normalizedSymbolMap[$symbol];
            }

            $marketCtx = [
                'symbol'               => $symbol,
                'time_window'          => $marketData['time_window_minutes'] ?? 15,
                'last_price'           => $marketData['candles'][array_key_last($marketData['candles'] ?? [])]['close'] ?? null,
                'data_source'          => $marketData['data_source'] ?? 'unknown',
                'symbol_normalization' => $normInfo,
            ];

            foreach (PatternDetectorRegistry::all() as $algo => $detector) {
                $cfg        = (array)($detectorCfg[$algo] ?? []);
                $detections = $detector->detect($marketData, $cfg);
                foreach ($detections as $raw) {
                    $signal   = $this->adapter->normalize($raw, $marketCtx);
                    $scenario = $this->scenarioEngine->evaluate($signal);
                    $allCombined[] = ['raw' => $raw, 'signal' => $signal, 'scenario' => $scenario];
                }
            }
        }

        // Track raw totals before any dedup / cap
        $rawTotal = count($allCombined);
        $this->realRunStats['raw_candidates_total'] = $rawTotal;
        $this->realRunStats['raw_signals_total']    = $rawTotal;
        $this->realRunStats['raw_scenarios_total']  = $rawTotal;

        // Store normalization counters (set once, after all symbols processed)
        $this->realRunStats['symbols_normalized_count']          = $symbolsNormalizedCount;
        $this->realRunStats['symbols_normalization_failed_count'] = $symbolsNormalizationFailedCount;

        // Track symbols that were normalized but still had no passport match
        $normalizedButUnmatched = [];
        foreach ($normalizedSymbolMap as $normInfo) {
            if (($normInfo['symbol_normalization_status'] ?? '') === 'normalized_no_passport') {
                $form = (string)($normInfo['symbol_canonical'] !== '' ? $normInfo['symbol_canonical'] : $normInfo['symbol_normalized']);
                if ($form !== '') {
                    $normalizedButUnmatched[] = $form;
                }
            }
        }
        $this->realRunStats['symbols_normalized_but_unmatched'] = $normalizedButUnmatched;

        // Deduplicate: cap per (symbol × side × pattern_algorithm) and per symbol
        $allCombined = $this->deduplicateCombined($allCombined, $antiFlood);
        $afterDedup  = count($allCombined);
        $this->realRunStats['after_dedup_candidates'] = $afterDedup;
        $this->realRunStats['after_dedup_signals']    = $afterDedup;
        $this->realRunStats['after_dedup_scenarios']  = $afterDedup;

        // Rank: best-quality first so global cap keeps the most valuable signals
        usort($allCombined, function (array $a, array $b): int {
            // 1. Scenario status priority DESC
            $pa = $this->scenarioStatusPriority($a['scenario']['final_scenario_status'] ?? $a['scenario']['scenario_status'] ?? '');
            $pb = $this->scenarioStatusPriority($b['scenario']['final_scenario_status'] ?? $b['scenario']['scenario_status'] ?? '');
            if ($pa !== $pb) {
                return $pb - $pa;
            }
            // 2. signal_strength DESC
            $sd = ($b['signal']['signal_strength'] ?? 0.0) - ($a['signal']['signal_strength'] ?? 0.0);
            if (abs($sd) > 1e-9) {
                return $sd > 0 ? 1 : -1;
            }
            // 3. quality_score DESC
            return ($b['signal']['quality_score'] ?? 0.0) <=> ($a['signal']['quality_score'] ?? 0.0);
        });

        // Apply global storage caps (best N survive)
        $maxCand = (int)($config['storage']['max_candidates_per_run'] ?? 200);
        $maxSig  = (int)($config['storage']['max_signals_stored']     ?? 500);
        $maxScen = (int)($config['storage']['max_scenarios_stored']   ?? 500);
        $cap     = min($maxCand, $maxSig, $maxScen);
        $allCombined = array_slice($allCombined, 0, $cap);

        $stored = count($allCombined);
        $this->realRunStats['candidates_truncated'] = $stored < $afterDedup;
        $this->realRunStats['signals_truncated']    = $stored < $afterDedup;
        $this->realRunStats['scenarios_truncated']  = $stored < $afterDedup;

        $allRaw       = array_column($allCombined, 'raw');
        $allSignals   = array_column($allCombined, 'signal');
        $allScenarios = array_column($allCombined, 'scenario');

        // Count passport lookup outcomes from scenario diagnostics
        $passportLookupSuccessCount = 0;
        $passportLookupFailedCount  = 0;
        foreach ($allScenarios as $sc) {
            $ls = $sc['diagnostics']['passport_lookup_status'] ?? null;
            if ($ls === 'found') {
                $passportLookupSuccessCount++;
            } elseif ($ls === 'not_found') {
                $passportLookupFailedCount++;
            }
        }
        $this->realRunStats['passport_lookup_success_count'] = $passportLookupSuccessCount;
        $this->realRunStats['passport_lookup_failed_count']  = $passportLookupFailedCount;

        // Coverage candidates: symbols that produced detections but had no passport found
        $coverageFreq = [];
        foreach ($allScenarios as $sc) {
            if (($sc['diagnostics']['passport_lookup_status'] ?? '') === 'not_found') {
                $sym = (string)($sc['symbol'] ?? '');
                if ($sym !== '') {
                    $coverageFreq[$sym] = ($coverageFreq[$sym] ?? 0) + 1;
                }
            }
        }
        arsort($coverageFreq);
        $coverageCandidates = [];
        foreach ($coverageFreq as $sym => $cnt) {
            $coverageCandidates[] = ['symbol' => $sym, 'detection_count' => $cnt];
        }
        $this->realRunStats['passport_coverage_candidates'] = array_slice($coverageCandidates, 0, 20);

        // Downstream bucket counters (from downstream graduation policy decisions)
        $allowDemoCount              = 0;
        $allowDemoLowConfidenceCount = 0;
        $allowSimCount        = 0;
        $shadowOnlyCount      = 0;
        $rejectCount          = 0;
        $demoNearMissCount    = 0;
        $demoCandidateCount   = 0;
        $demoLowConfidenceBlockCount   = 0;
        $demoLowConfidenceBlockReasons = [];
        $demoBlockCounts = [
            'demo_blocked_no_passport'                  => 0,
            'demo_blocked_low_quality'                  => 0,
            'demo_blocked_low_strength'                 => 0,
            'demo_blocked_low_corridor'                 => 0,
            'demo_blocked_low_runner'                   => 0,
            'demo_blocked_high_noise'                   => 0,
            'demo_blocked_low_confidence'               => 0,
            'demo_blocked_insufficient_data'            => 0,
            'demo_blocked_passport_eligibility_rejected'=> 0,
            'allow_demo_disabled_by_policy'             => 0,
        ];
        foreach ($allScenarios as $sc) {
            $bucket = $sc['diagnostics']['final_downstream_bucket'] ?? $sc['scenario_status'] ?? '';
            if ($bucket === 'allow_demo') {
                $allowDemoCount++;
                if (!empty($sc['diagnostics']['demo_low_confidence_policy_used'])) {
                    $allowDemoLowConfidenceCount++;
                }
            } elseif ($bucket === 'allow_sim' || $bucket === 'sim_only') {
                $allowSimCount++;
            } elseif ($bucket === 'shadow_only' || $bucket === 'allow_shadow') {
                $shadowOnlyCount++;
            } elseif ($bucket === 'reject') {
                $rejectCount++;
            } else {
                // Fallback: use allowed_for flags
                if (!empty($sc['allowed_for_demo'])) {
                    $allowDemoCount++;
                } elseif (!empty($sc['allowed_for_sim'])) {
                    $allowSimCount++;
                } elseif (!empty($sc['allowed_for_shadow'])) {
                    $shadowOnlyCount++;
                } else {
                    $rejectCount++;
                }
            }
            $blockReason = $sc['diagnostics']['demo_block_reason'] ?? null;
            if ($blockReason !== null && array_key_exists($blockReason, $demoBlockCounts)) {
                $demoBlockCounts[$blockReason]++;
            }
            // Near-miss counter
            if (!empty($sc['diagnostics']['demo_near_miss'])) {
                $demoNearMissCount++;
            }
            // Demo-candidate: signal has passport and reached sim (or shadow) — was "considered" for demo
            $hasPassport = (bool)($sc['diagnostics']['passport_available'] ?? false);
            if ($hasPassport && in_array($bucket, ['allow_sim', 'sim_only', 'shadow_only', 'allow_shadow'], true)) {
                $demoCandidateCount++;
            }
            // Low-confidence policy block counter
            $lcBlockReason = $sc['diagnostics']['demo_low_confidence_block_reason'] ?? null;
            if ($lcBlockReason !== null && $bucket !== 'allow_demo') {
                $demoLowConfidenceBlockCount++;
                $demoLowConfidenceBlockReasons[$lcBlockReason] = ($demoLowConfidenceBlockReasons[$lcBlockReason] ?? 0) + 1;
            }
        }
        // Build top_demo_block_reasons (sorted, non-zero only)
        $activeBlockReasons = array_filter($demoBlockCounts, fn($v) => $v > 0);
        arsort($activeBlockReasons);
        $topDemoBlockReasons = [];
        foreach ($activeBlockReasons as $reason => $cnt) {
            $topDemoBlockReasons[] = ['reason' => $reason, 'count' => $cnt];
        }
        arsort($demoLowConfidenceBlockReasons);

        $this->realRunStats['allow_demo_count']                     = $allowDemoCount;
        $this->realRunStats['allow_demo_low_confidence_count']      = $allowDemoLowConfidenceCount;
        $this->realRunStats['allow_sim_count']                      = $allowSimCount;
        $this->realRunStats['shadow_only_count']                    = $shadowOnlyCount;
        $this->realRunStats['reject_count']                         = $rejectCount;
        $this->realRunStats['demo_block_counts']                    = $demoBlockCounts;
        $this->realRunStats['demo_near_miss_count']                 = $demoNearMissCount;
        $this->realRunStats['demo_candidate_signals_count']         = $demoCandidateCount;
        $this->realRunStats['top_demo_block_reasons']               = $topDemoBlockReasons;
        $this->realRunStats['demo_low_confidence_block_count']      = $demoLowConfidenceBlockCount;
        $this->realRunStats['demo_low_confidence_block_reasons']    = $demoLowConfidenceBlockReasons;

        // Build downstream-safe filtered signal sets — exclusive by final_downstream_bucket
        $demoSignals   = $this->buildDownstreamSet($allCombined, ['allow_demo']);
        $shadowSignals = $this->buildDownstreamSet($allCombined, ['shadow_only', 'allow_shadow']);
        $simSignals    = $this->buildDownstreamSet($allCombined, ['allow_sim', 'sim_only']);

        $this->realRunStats['demo_signals_count']   = count($demoSignals);
        $this->realRunStats['shadow_signals_count'] = count($shadowSignals);
        $this->realRunStats['sim_signals_count']    = count($simSignals);

        $stats = $this->computeStats($allRaw, $allSignals, $allScenarios);

        // Persist
        $this->saveCandidates($allRaw);
        $this->saveSignals($allSignals);
        $this->saveScenarios($allScenarios);
        $this->saveDownstreamSnapshots($demoSignals, $shadowSignals, $simSignals);
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
     * Return signals allowed for demo execution (final_downstream_bucket = allow_demo).
     *
     * @return list<array<string,mixed>>
     */
    public function getDemoSignals(): array
    {
        return $this->filterScenariosByMode(['allow_demo']);
    }

    /**
     * Return signals allowed for AI Shadow (final_downstream_bucket = shadow_only|allow_shadow).
     *
     * @return list<array<string,mixed>>
     */
    public function getShadowSignals(): array
    {
        return $this->filterScenariosByMode(['shadow_only', 'allow_shadow']);
    }

    /**
     * Return signals allowed for Simulator (final_downstream_bucket = allow_sim|sim_only).
     *
     * @return list<array<string,mixed>>
     */
    public function getSimSignals(): array
    {
        return $this->filterScenariosByMode(['allow_sim', 'sim_only']);
    }

    /**
     * Clear all storage (for maintenance / UI reset).
     */
    public function clearStorage(): void
    {
        foreach ([
            'candidates/candidates.json',
            'signals/signals.json',
            'scenarios/scenarios.json',
            'downstream/demo_signals.json',
            'downstream/shadow_signals.json',
            'downstream/sim_signals.json',
        ] as $f) {
            $path = $this->storageDir . '/' . $f;
            if (file_exists($path)) {
                @unlink($path);
            }
        }
    }

    // =========================================================================
    // Downstream snapshot helpers
    // =========================================================================

    /**
     * Build a downstream-safe signal set from finalized combined triplets.
     *
     * Each entry is a clean, self-contained record for Demo / Shadow / Sim
     * consumption — symbol normalized, scenario metadata, passport summary, TTL.
     *
     * Filtering is exclusive: uses final_downstream_bucket from diagnostics.
     *
     * @param  list<array{raw:array,signal:array,scenario:array}>  $combined
     * @param  list<string>  $buckets  e.g. ['allow_demo'] or ['shadow_only','allow_shadow']
     * @return list<array<string,mixed>>
     */
    private function buildDownstreamSet(array $combined, array $buckets): array
    {
        $result = [];
        foreach ($combined as $item) {
            $sc  = $item['scenario'] ?? [];
            $sig = $item['signal']   ?? [];

            $finalBucket = $sc['diagnostics']['final_downstream_bucket'] ?? $sc['scenario_status'] ?? '';
            if (!in_array($finalBucket, $buckets, true)) {
                continue;
            }

            $diag = (array)($sc['diagnostics'] ?? []);

            $result[] = [
                'signal_id'                  => $sig['signal_id']                   ?? '',
                'symbol'                     => $sig['symbol']                      ?? '',
                'symbol_raw'                 => $sig['symbol_raw']                  ?? ($sig['symbol'] ?? ''),
                'symbol_normalized'          => $sig['symbol_normalized']           ?? strtoupper($sig['symbol'] ?? ''),
                'symbol_canonical'           => $sig['symbol_canonical']            ?? strtoupper($sig['symbol'] ?? ''),
                'symbol_normalization_status'=> $sig['symbol_normalization_status'] ?? 'unchanged',
                'side'                       => $sig['side']                        ?? '',
                'pattern_algorithm'          => $sig['pattern_algorithm']           ?? '',
                'pattern_version'            => $sig['pattern_version']             ?? '',
                'signal_strength'            => $sig['signal_strength']             ?? 0.0,
                'quality_score'              => $sig['quality_score']               ?? 0.0,
                'ttl_seconds'                => $sig['ttl_seconds']                 ?? 0,
                'detected_at'                => $sig['detected_at']                 ?? '',
                'scenario_id'                => $sc['scenario_id']                  ?? '',
                'scenario_status'            => $sc['scenario_status']              ?? '',
                'scenario_reason'            => $sc['scenario_reason']              ?? '',
                'scenario_score'             => $sc['scenario_score']               ?? 0.0,
                'execution_mode_hint'        => $sc['execution_mode_hint']          ?? '',
                'allowed_for_demo'           => (bool)($sc['allowed_for_demo']      ?? false),
                'allowed_for_shadow'         => (bool)($sc['allowed_for_shadow']    ?? false),
                'allowed_for_sim'            => (bool)($sc['allowed_for_sim']       ?? false),
                'allowed_for_live'           => (bool)($sc['allowed_for_live']      ?? false),
                'passport_lookup_symbol'     => $diag['passport_lookup_symbol']     ?? null,
                'passport_lookup_status'     => $diag['passport_lookup_status']     ?? null,
                'passport_lookup_reason'     => $diag['passport_lookup_reason']     ?? null,
                'passport_available'         => (bool)($diag['passport_available']  ?? false),
                'passport_data_confidence'   => $diag['passport_data_confidence']   ?? null,
                'passport_runner_probability'=> $diag['passport_runner_probability']?? null,
                'passport_noise_score'       => $diag['passport_noise_score']       ?? null,
                'entry_hint'                 => $sig['entry_hint']                  ?? null,
                'invalidation_hint'          => $sig['invalidation_hint']           ?? null,
                'source_module'              => 'pattern_engine',
                'snapshot_at'               => date('c'),
            ];
        }
        return $result;
    }

    /**
     * Write demo / shadow / sim downstream snapshots.
     *
     * @param list<array<string,mixed>> $demo
     * @param list<array<string,mixed>> $shadow
     * @param list<array<string,mixed>> $sim
     */
    private function saveDownstreamSnapshots(array $demo, array $shadow, array $sim): void
    {
        $base = $this->storageDir . '/downstream';
        $this->writeJson($base . '/demo_signals.json',   $demo);
        $this->writeJson($base . '/shadow_signals.json', $shadow);
        $this->writeJson($base . '/sim_signals.json',    $sim);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /** @param list<array<string,mixed>> $scenarios */
    private function filterScenariosByMode(array $buckets): array
    {
        $scenarios = $this->getScenarios();
        $signals   = $this->getSignals();
        $sigMap    = [];
        foreach ($signals as $s) {
            $sigMap[$s['signal_id'] ?? ''] = $s;
        }

        $result = [];
        foreach ($scenarios as $sc) {
            $finalBucket = $sc['diagnostics']['final_downstream_bucket'] ?? $sc['scenario_status'] ?? '';
            if (in_array($finalBucket, $buckets, true)) {
                $sid = $sc['signal_id'] ?? '';
                $result[] = array_merge($sc, ['signal' => $sigMap[$sid] ?? null]);
            }
        }
        return $result;
    }

    // =========================================================================
    // Anti-flood / deduplication / ranking helpers
    // =========================================================================

    /**
     * Deduplicate combined detection triplets.
     *
     * Groups by (symbol × side × pattern_algorithm).
     * Keeps the best N per group (by signal_strength) and caps total per symbol.
     *
     * @param  list<array{raw:array,signal:array,scenario:array}>  $combined
     * @param  array<string,mixed>                                  $config   anti_flood config block
     * @return list<array{raw:array,signal:array,scenario:array}>
     */
    private function deduplicateCombined(array $combined, array $config): array
    {
        $maxPerPattern = max(1, (int)($config['max_signals_per_pattern_per_symbol'] ?? 2));
        $maxPerSymbol  = max(1, (int)($config['max_signals_per_symbol_per_run']     ?? 10));

        // Sort by signal_strength DESC so we keep the strongest within each group
        usort($combined, fn($a, $b) =>
            ($b['signal']['signal_strength'] ?? 0.0) <=> ($a['signal']['signal_strength'] ?? 0.0));

        $patternCount = [];
        $symbolCount  = [];
        $result       = [];

        foreach ($combined as $item) {
            $sym  = $item['signal']['symbol']            ?? '';
            $side = $item['signal']['side']              ?? '';
            $algo = $item['signal']['pattern_algorithm'] ?? '';
            $pKey = $sym . '|' . $side . '|' . $algo;

            $pc = $patternCount[$pKey] ?? 0;
            $sc = $symbolCount[$sym]   ?? 0;

            if ($pc >= $maxPerPattern || $sc >= $maxPerSymbol) {
                continue;
            }

            $result[]            = $item;
            $patternCount[$pKey] = $pc + 1;
            $symbolCount[$sym]   = $sc + 1;
        }

        return $result;
    }

    /**
     * Map a scenario status string to a numeric sort priority (higher = better).
     */
    private function scenarioStatusPriority(string $status): int
    {
        return match ($status) {
            'allow_live'  => 5,
            'allow_demo'  => 4,
            'shadow_only' => 3,
            'sim_only'    => 2,
            'rejected'    => 1,
            default       => 0,
        };
    }

    /**
     * Try to load recent candles from Parser2 NDJSON history for a symbol.
     *
     * Parser2 stores per-minute ticker snapshots (last_price) in per-symbol NDJSON
     * files. This method aggregates them into synthetic OHLCV bars for the requested
     * timeframe. Returns null if no local data is available or data is insufficient.
     *
     * @return list<array{ts_unix:int,open:float,high:float,low:float,close:float,volume:float}>|null
     */
    private function loadInternalCandlesForSymbol(string $symbol, string $timeframe, int $minCandles): ?array
    {
        $bucketSec = $this->timeframeToMinutes($timeframe) * 60;
        if ($bucketSec <= 0) {
            return null;
        }

        $parser2Dir = __DIR__ . '/../../parser/parser2_history_accumulator/storage/' . $symbol;
        if (!is_dir($parser2Dir)) {
            return null;
        }

        $files = glob($parser2Dir . '/*.ndjson') ?: [];
        if (empty($files)) {
            return null;
        }

        // Load last 3 days of NDJSON files to cover sufficient lookback
        sort($files);
        $files = array_slice($files, -3);

        $ticks = [];
        foreach ($files as $f) {
            $handle = @fopen($f, 'r');
            if (!$handle) {
                continue;
            }
            while (($line = fgets($handle)) !== false) {
                $row    = json_decode(trim($line), true);
                $tsUnix = (int)($row['ts_unix'] ?? 0);
                $price  = (float)($row['last_price'] ?? 0.0);
                if (!is_array($row) || $tsUnix <= 0 || $price <= 0.0) {
                    continue;
                }
                $ticks[] = ['ts_unix' => $tsUnix, 'price' => $price];
            }
            fclose($handle);
        }

        if (empty($ticks)) {
            return null;
        }

        // Sort chronologically
        usort($ticks, fn($a, $b) => $a['ts_unix'] <=> $b['ts_unix']);

        // Aggregate into OHLCV buckets for the requested timeframe
        $buckets = [];
        foreach ($ticks as $tick) {
            $bucketTs = (int)(floor($tick['ts_unix'] / $bucketSec) * $bucketSec);
            $buckets[$bucketTs][] = $tick['price'];
        }
        ksort($buckets);

        $candles = [];
        foreach ($buckets as $bucketTs => $prices) {
            $candles[] = [
                'ts_unix' => $bucketTs,
                'open'    => $prices[0],
                'high'    => max($prices),
                'low'     => min($prices),
                'close'   => $prices[count($prices) - 1],
                'volume'  => 0.0, // volume24h is cumulative; delta not reliable here
            ];
        }

        if (count($candles) < $minCandles) {
            return null;
        }

        return $candles;
    }

    /** @return array<string,mixed> */
    private function computeStats(array $candidates, array $signals, array $scenarios): array
    {
        $perPattern   = [];
        $perSymbol    = [];
        $statusCounts = [];

        foreach ($candidates as $c) {
            $algo = $c['pattern_algorithm'] ?? 'unknown';
            $sym  = $c['symbol'] ?? 'unknown';
            $perPattern[$algo] = ($perPattern[$algo] ?? 0) + 1;
            $perSymbol[$sym]   = ($perSymbol[$sym]   ?? 0) + 1;
        }

        foreach ($scenarios as $sc) {
            $status = $sc['final_scenario_status'] ?? $sc['scenario_status'] ?? 'unknown';
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
        }

        $symScanned = (int)($this->realRunStats['symbols_scanned'] ?? 0);
        $stored     = count($signals);

        return [
            'generated_at'                      => date('c'),
            'run_source'                        => $this->realRunStats['run_source']                        ?? 'unknown',
            'primary_data_source'               => $this->realRunStats['primary_data_source']               ?? 'bybit_klines',
            'fallback_data_source_used'         => (bool)($this->realRunStats['fallback_data_source_used']  ?? false),
            'symbols_used_internal'             => (int)($this->realRunStats['symbols_used_internal']       ?? 0),
            'symbols_used_bybit_fallback'       => (int)($this->realRunStats['symbols_used_bybit_fallback'] ?? 0),
            'timeframe'                         => $this->realRunStats['timeframe']                         ?? '',
            'lookback_candles'                  => $this->realRunStats['lookback_candles']                  ?? 0,
            'symbols_total'                     => (int)($this->realRunStats['symbols_total']               ?? 0),
            'symbols_scanned'                   => $symScanned,
            'symbols_skipped_insufficient_data' => (int)($this->realRunStats['symbols_skipped_insufficient_data'] ?? 0),
            'symbols_skipped_api_error'         => (int)($this->realRunStats['symbols_skipped_api_error']   ?? 0),
            // Raw totals (before anti-flood dedup)
            'raw_candidates_total'              => (int)($this->realRunStats['raw_candidates_total']        ?? count($candidates)),
            'raw_signals_total'                 => (int)($this->realRunStats['raw_signals_total']           ?? count($signals)),
            'raw_scenarios_total'               => (int)($this->realRunStats['raw_scenarios_total']         ?? count($scenarios)),
            // After-dedup totals (before global cap)
            'after_dedup_candidates'            => (int)($this->realRunStats['after_dedup_candidates']      ?? count($candidates)),
            'after_dedup_signals'               => (int)($this->realRunStats['after_dedup_signals']         ?? count($signals)),
            'after_dedup_scenarios'             => (int)($this->realRunStats['after_dedup_scenarios']       ?? count($scenarios)),
            // Stored (final written)
            'candidates_count'                  => count($candidates),
            'signals_count'                     => $stored,
            'scenarios_count'                   => count($scenarios),
            // Truncation flags
            'candidates_truncated'              => (bool)($this->realRunStats['candidates_truncated']       ?? false),
            'signals_truncated'                 => (bool)($this->realRunStats['signals_truncated']          ?? false),
            'scenarios_truncated'               => (bool)($this->realRunStats['scenarios_truncated']        ?? false),
            // Per-pattern / per-status breakdowns
            'per_pattern'                       => $perPattern,
            'per_symbol'                        => $perSymbol,
            'status_counts'                     => $statusCounts,
            // Derived
            'avg_signals_per_symbol'            => $symScanned > 0 ? round($stored / $symScanned, 2) : 0,
            // Symbol normalization counters
            'symbols_normalized_count'           => (int)($this->realRunStats['symbols_normalized_count']           ?? 0),
            'symbols_normalization_failed_count' => (int)($this->realRunStats['symbols_normalization_failed_count'] ?? 0),
            // Passport lookup counters
            'passport_lookup_success_count'      => (int)($this->realRunStats['passport_lookup_success_count']      ?? 0),
            'passport_lookup_failed_count'       => (int)($this->realRunStats['passport_lookup_failed_count']       ?? 0),
            // Downstream signal counts
            'demo_signals_count'                 => (int)($this->realRunStats['demo_signals_count']                 ?? 0),
            'shadow_signals_count'               => (int)($this->realRunStats['shadow_signals_count']               ?? 0),
            'sim_signals_count'                  => (int)($this->realRunStats['sim_signals_count']                  ?? 0),
            // Downstream graduation policy bucket counts
            'allow_demo_count'                   => (int)($this->realRunStats['allow_demo_count']                   ?? 0),
            'allow_demo_low_confidence_count'    => (int)($this->realRunStats['allow_demo_low_confidence_count']    ?? 0),
            'allow_sim_count'                    => (int)($this->realRunStats['allow_sim_count']                    ?? 0),
            'shadow_only_count'                  => (int)($this->realRunStats['shadow_only_count']                  ?? 0),
            'reject_count'                       => (int)($this->realRunStats['reject_count']                       ?? 0),
            'demo_block_counts'                  => (array)($this->realRunStats['demo_block_counts']                ?? []),
            'demo_near_miss_count'               => (int)($this->realRunStats['demo_near_miss_count']               ?? 0),
            'demo_candidate_signals_count'       => (int)($this->realRunStats['demo_candidate_signals_count']       ?? 0),
            'top_demo_block_reasons'             => (array)($this->realRunStats['top_demo_block_reasons']           ?? []),
            'demo_low_confidence_block_count'    => (int)($this->realRunStats['demo_low_confidence_block_count']    ?? 0),
            'demo_low_confidence_block_reasons'  => (array)($this->realRunStats['demo_low_confidence_block_reasons'] ?? []),
            // Universe overlap diagnostics
            'pattern_symbols_total'                  => (int)($this->realRunStats['pattern_symbols_total']                  ?? 0),
            'passport_symbols_total'                 => (int)($this->realRunStats['passport_symbols_total']                 ?? 0),
            'symbol_universe_overlap_count'          => (int)($this->realRunStats['symbol_universe_overlap_count']          ?? 0),
            'symbol_universe_overlap_rate'           => (float)($this->realRunStats['symbol_universe_overlap_rate']         ?? 0.0),
            'pattern_symbols_with_passport_count'    => (int)($this->realRunStats['pattern_symbols_with_passport_count']    ?? 0),
            'pattern_symbols_without_passport_count' => (int)($this->realRunStats['pattern_symbols_without_passport_count'] ?? 0),
            'symbols_with_passport'                  => (array)($this->realRunStats['symbols_with_passport']                ?? []),
            'symbols_without_passport'               => (array)($this->realRunStats['symbols_without_passport']             ?? []),
            'symbols_normalized_but_unmatched'       => (array)($this->realRunStats['symbols_normalized_but_unmatched']     ?? []),
            'active_universe_policy'                 => (string)($this->realRunStats['active_universe_policy']              ?? 'all_active'),
            'passport_coverage_candidates'           => (array)($this->realRunStats['passport_coverage_candidates']         ?? []),
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
        foreach (['candidates', 'signals', 'scenarios', 'runtime', 'logs', 'downstream'] as $dir) {
            $full = $this->storageDir . '/' . $dir;
            if (!is_dir($full)) {
                @mkdir($full, 0755, true);
            }
        }
    }
}
