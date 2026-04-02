<?php
declare(strict_types=1);

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

    public function __construct()
    {
        $this->storageDir = __DIR__ . '/storage';
        $this->ensureDirs();

        $config         = PatternEngineConfig::load();
        $profiles       = (array)($config['scenario_profiles'] ?? []);
        $passportDir    = __DIR__ . '/../coin_passport/storage/passports';

        $this->adapter        = new UniversalSignalAdapter();
        $this->scenarioEngine = new ScenarioEngine($profiles, $passportDir);

        PatternDetectorRegistry::init();
    }

    // =========================================================================
    // Run pipeline
    // =========================================================================

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
            'generated_at'     => date('c'),
            'candidates_count' => count($candidates),
            'signals_count'    => count($signals),
            'scenarios_count'  => count($scenarios),
            'per_pattern'      => $perPattern,
            'per_symbol'       => $perSymbol,
            'status_counts'    => $statusCounts,
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
