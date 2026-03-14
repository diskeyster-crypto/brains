<?php
declare(strict_types=1);

require_once __DIR__ . '/smart_brain_config.php';
require_once __DIR__ . '/smart_brain_logger.php';
require_once __DIR__ . '/state_manager.php';
require_once __DIR__ . '/parser4_analyzer.php';
require_once __DIR__ . '/corridor_monitor.php';
require_once __DIR__ . '/coin_passport_engine.php';
require_once __DIR__ . '/risk_engine.php';
require_once __DIR__ . '/signal_builder.php';
require_once __DIR__ . '/simulator_engine.php';
require_once __DIR__ . '/smart_brain_runtime.php';
require_once __DIR__ . '/price_feed.php';

final class SmartBrainCore
{
    private const LOCK_FILE = 'runtime/brain.lock';
    private const LOCK_STALE_SECONDS = 300;

    private string $moduleBase;
    private SmartBrainConfig $config;
    private SmartBrainLogger $logger;
    private StateManager $state;

    public function __construct(string $moduleBase)
    {
        $this->moduleBase = rtrim($moduleBase, '/');
        $this->config = new SmartBrainConfig($this->moduleBase);
        $this->logger = new SmartBrainLogger($this->moduleBase);
        $this->state = new StateManager($this->moduleBase);
    }

    /**
     * Run full Smart Brain cycle with lock, timing, and status tracking.
     *
     * @param string $source  'cron' or 'manual'
     * @return array<string,mixed>
     */
    public function run(string $source = 'cron'): array
    {
        $startTime = microtime(true);

        // Acquire lock — prevent overlapping runs
        if (!$this->acquireLock($source)) {
            $this->logger->log('warning', 'Smart Brain cycle skipped: lock held by another process');
            return [
                'ok' => false,
                'updated_at' => date('c'),
                'status' => 'skipped',
                'source' => $source,
                'error_message' => 'Lock held by another process',
            ];
        }

        $this->logger->log('info', 'Smart Brain cycle started (source=' . $source . ')');

        try {
            $result = $this->executePipeline($source, $startTime);
        } catch (\Throwable $e) {
            $durationMs = (int)round((microtime(true) - $startTime) * 1000);
            $this->logger->log('error', 'Smart Brain cycle FAILED: ' . $e->getMessage());

            $result = [
                'ok' => false,
                'updated_at' => date('c'),
                'status' => 'error',
                'source' => $source,
                'duration_ms' => $durationMs,
                'candidates' => 0,
                'monitors' => 0,
                'signals' => 0,
                'error_message' => $e->getMessage(),
            ];
            $this->state->writeJson('storage/last_run.json', $result);
        } finally {
            $this->releaseLock();
        }

        return $result;
    }

    /**
     * Execute the actual pipeline logic.
     *
     * @return array<string,mixed>
     */
    private function executePipeline(string $source, float $startTime): array
    {
        $parser4Cfg = $this->config->getEffective('parser4');
        $corridorCfg = $this->config->getEffective('corridor');
        $riskCfg = $this->config->getEffective('risk_engine');
        $profilesCfg = $this->config->getEffective('profiles');
        $simulatorCfg = $this->config->getEffective('simulator');

        // Parser4: structure analysis from Parser2 history
        $parser = new Parser4Analyzer($parser4Cfg, $this->state);
        $candidates = $parser->run();

        // Collect all symbols from candidates
        $symbols = [];
        foreach ($candidates as $c) {
            $sym = (string)($c['symbol'] ?? '');
            if ($sym !== '') {
                $symbols[] = $sym;
            }
        }

        // Fetch real current prices from Bybit (Phase 8.2)
        $priceFeed = new PriceFeed($this->logger);
        $livePrices = $priceFeed->getPrices(array_unique($symbols));

        // Build final price map: Bybit first, internal last_price as fallback
        $prices = $this->buildPriceMap($livePrices, $candidates);

        if ($livePrices !== []) {
            $this->logger->log('info', 'PriceFeed: fetched ' . count($livePrices) . ' live prices from Bybit');
        }

        // Corridor Monitor uses real prices for price_position / status
        $corridor = new CorridorMonitor($corridorCfg);
        $monitors = $corridor->buildMonitors($candidates, $prices);
        $this->state->writeJson('storage/monitors.json', $monitors);

        $passports = new CoinPassportEngine($this->state);
        $passports->update($monitors);

        $risk = new RiskEngine($riskCfg, $profilesCfg, $this->state);
        $signals = $risk->apply($monitors);
        $this->state->writeJson('storage/signals.json', $signals);

        // Simulator uses real prices for entry trigger / ROI / MAE / MFE / SL / TP
        $simulator = new SimulatorEngine($simulatorCfg, $this->state);
        $simulator->tick($signals, $prices);
        $stats = $simulator->computeStats();

        $runtime = new SmartBrainRuntime($this->state);
        $runtime->snapshot($this->config->all());

        $durationMs = (int)round((microtime(true) - $startTime) * 1000);

        $result = [
            'ok' => true,
            'updated_at' => date('c'),
            'status' => 'ok',
            'source' => $source,
            'duration_ms' => $durationMs,
            'candidates' => count($candidates),
            'monitors' => count($monitors),
            'signals' => count($signals),
            'error_message' => '',
        ];

        $this->state->writeJson('storage/last_run.json', $result);
        $this->logger->log('info', 'Smart Brain cycle finished: source=' . $source . ' signals=' . count($signals) . ' duration=' . $durationMs . 'ms');

        return $result;
    }

    // =========================================================================
    // Lock mechanism
    // =========================================================================

    /**
     * Acquire run lock. Returns true if lock acquired, false if another run is active.
     * Checks for stale locks (older than LOCK_STALE_SECONDS) and recovers automatically.
     *
     * @return bool true if lock acquired or stale lock recovered; false if fresh lock held
     */
    private function acquireLock(string $source): bool
    {
        $lockPath = $this->moduleBase . '/' . self::LOCK_FILE;
        $lockDir = dirname($lockPath);
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0755, true);
        }

        // Check for existing lock
        if (is_file($lockPath)) {
            $raw = @file_get_contents($lockPath);
            if ($raw !== false && trim($raw) !== '') {
                $lockData = json_decode($raw, true);
                if (is_array($lockData)) {
                    $lockedAt = (int)($lockData['timestamp'] ?? 0);
                    // If lock is fresh (not stale), reject
                    if ($lockedAt > 0 && (time() - $lockedAt) < self::LOCK_STALE_SECONDS) {
                        return false;
                    }
                    // Stale lock — log and recover
                    $this->logger->log('warning', 'Stale lock detected (age=' . (time() - $lockedAt) . 's), recovering');
                }
            }
        }

        // Write lock
        $lockData = [
            'pid' => getmypid(),
            'source' => $source,
            'timestamp' => time(),
            'acquired_at' => date('c'),
        ];

        $written = @file_put_contents($lockPath, json_encode($lockData, JSON_UNESCAPED_SLASHES), LOCK_EX);
        if ($written === false) {
            $this->logger->log('warning', 'Failed to write lock file');
            return false;
        }

        return true;
    }

    /**
     * Release run lock.
     */
    private function releaseLock(): void
    {
        $lockPath = $this->moduleBase . '/' . self::LOCK_FILE;
        if (is_file($lockPath)) {
            @unlink($lockPath);
        }
    }

    /**
     * Build symbol→price map.
     * Primary: Bybit live prices.
     * Fallback: internal last_price from candidates.
     *
     * @param array<string,float>            $livePrices   Bybit prices
     * @param array<int,array<string,mixed>> $candidates   Parser4 candidates
     * @return array<string,float>
     */
    private function buildPriceMap(array $livePrices, array $candidates): array
    {
        $prices = $livePrices;

        // Fallback: use internal last_price for symbols not in livePrices
        foreach ($candidates as $c) {
            $sym = (string)($c['symbol'] ?? '');
            $p   = (float)($c['last_price'] ?? 0.0);
            if ($sym !== '' && $p > 0.0 && !isset($prices[$sym])) {
                $prices[$sym] = $p;
            }
        }

        return $prices;
    }

    /**
     * @return array<string,mixed>
     */
    public function getDashboardData(): array
    {
        $uiSettings = $this->config->getEffective('ui');
        return [
            'title' => (string)($uiSettings['title'] ?? 'Smart Brain'),
            'last_run' => $this->state->readJson('storage/last_run.json', []),
            'signals' => $this->state->readJson('storage/signals.json', []),
            'monitors' => $this->state->readJson('storage/monitors.json', []),
            'waiting' => $this->state->readJson('storage/simulator/waiting.json', []),
            'active' => $this->state->readJson('storage/simulator/active.json', []),
            'closed' => $this->state->readJson('storage/simulator/closed.json', []),
            'stats' => $this->state->readJson('storage/simulator/stats.json', []),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getRuntimeData(): array
    {
        return [
            'config' => $this->config->all(),
            'snapshot' => $this->state->readJson('runtime/config.snapshot.json', []),
            'last_run' => $this->state->readJson('storage/last_run.json', []),
            'stats' => $this->state->readJson('storage/simulator/stats.json', []),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getConfigData(): array
    {
        return $this->config->all();
    }

    /**
     * @return array<string,mixed>
     */
    public function getAnalizatorData(): array
    {
        return [
            'candidates' => $this->state->readJson('storage/candidates.json', []),
            'signals' => $this->state->readJson('storage/signals.json', []),
            'monitors' => $this->state->readJson('storage/monitors.json', []),
            'last_run' => $this->state->readJson('storage/last_run.json', []),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getSimulatorData(): array
    {
        return [
            'waiting' => $this->state->readJson('storage/simulator/waiting.json', []),
            'active' => $this->state->readJson('storage/simulator/active.json', []),
            'closed' => $this->state->readJson('storage/simulator/closed.json', []),
            'stats' => $this->state->readJson('storage/simulator/stats.json', []),
            'last_run' => $this->state->readJson('storage/last_run.json', []),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getPassportsData(): array
    {
        $passportsDir = $this->moduleBase . '/storage/passports';
        $passports = [];

        if (is_dir($passportsDir)) {
            $files = glob($passportsDir . '/*.json');
            if ($files) {
                foreach ($files as $file) {
                    $data = json_decode((string)file_get_contents($file), true);
                    if (is_array($data)) {
                        $passports[] = $data;
                    }
                }
            }
        }

        return [
            'passports' => $passports,
            'count' => count($passports),
        ];
    }
}
