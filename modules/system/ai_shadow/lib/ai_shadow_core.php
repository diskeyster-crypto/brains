<?php
declare(strict_types=1);

/**
 * AiShadowCore
 *
 * Main orchestrator for the AI shadow simulation module.
 * Reads live data (read-only) and manages virtual signal/trade lifecycle.
 */
final class AiShadowCore
{
    private string               $moduleBase;
    private AiShadowStateManager $state;
    /** @var array<string,mixed> */
    private array $config;
    /** @var array<string,mixed> */
    private array $paths;

    private AiShadowJournal          $journal;
    private AiProviderInterface      $provider;
    private AiShadowSignalMirror     $signalMirror;
    private AiShadowTradeMirror      $tradeMirror;
    private AiShadowVirtualLifecycle $lifecycle;
    private AiShadowStatsEngine      $statsEngine;

    public function __construct(string $moduleBase)
    {
        $this->moduleBase = rtrim($moduleBase, '/');
        $this->state      = new AiShadowStateManager($this->moduleBase);
        $this->paths      = require $this->moduleBase . '/config/config.php';

        $this->config = $this->loadAiShadowConfig();

        // Boot sub-components
        $this->journal      = new AiShadowJournal($this->state);
        $this->provider     = $this->resolveProvider();
        $this->lifecycle    = new AiShadowVirtualLifecycle($this->state, $this->journal);
        $this->signalMirror = new AiShadowSignalMirror($this->state, $this->provider, $this->journal, $this->config);
        $this->tradeMirror  = new AiShadowTradeMirror($this->state, $this->lifecycle, $this->config);
        $this->statsEngine  = new AiShadowStatsEngine($this->state);
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Run a full live mirror cycle.
     *
     * @return array<string,mixed>
     */
    public function runLiveMirror(): array
    {
        $result = [
            'ran_at'        => time(),
            'enabled'       => (bool)($this->config['enabled'] ?? false),
            'signal_counts' => [],
            'trade_counts'  => [],
            'stats'         => [],
        ];

        if (!($this->config['enabled'] ?? false)) {
            $result['skipped'] = 'module_disabled';
            return $result;
        }

        if ($this->config['simulate_on_live_signals'] ?? true) {
            $liveSignals           = $this->readLiveSignals();
            $result['signal_counts'] = $this->signalMirror->mirrorSignals($liveSignals);
        }

        if ($this->config['simulate_on_live_trades'] ?? true) {
            $liveTradesDir           = (string)($this->paths['live_trades_dir'] ?? '');
            $result['trade_counts']  = $this->tradeMirror->mirrorTrades($liveTradesDir);
            $closedCounts            = $this->tradeMirror->mirrorClosedLiveTrades($liveTradesDir);
            $result['closed_trade_counts'] = $closedCounts;
        }

        $liveClosedTrades      = $this->loadLiveClosedTrades();
        $result['stats']       = $this->statsEngine->compute($liveClosedTrades);
        return $result;
    }

    /**
     * Replay from historical signals array.
     *
     * @param  array<int,array<string,mixed>> $historicalSignals
     * @return array<string,mixed>
     */
    public function runReplay(array $historicalSignals = []): array
    {
        $counts = $this->signalMirror->mirrorSignals($historicalSignals);
        $stats  = $this->statsEngine->compute();

        return [
            'ran_at'        => time(),
            'signal_counts' => $counts,
            'stats'         => $stats,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getStats(): array
    {
        return $this->statsEngine->getStats();
    }

    /**
     * @param  array<string,mixed> $filters  (optional, currently unused)
     * @return array<int,array<string,mixed>>
     */
    public function getVirtualSignals(array $filters = []): array
    {
        $items = $this->listJsonDir($this->state->resolvePath('storage/virtual_signals'));

        if (empty($filters)) {
            return $items;
        }

        return array_values(array_filter($items, function (array $vs) use ($filters): bool {
            return $this->matchesFilters($vs, $filters);
        }));
    }

    /**
     * @param  array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function getVirtualActiveTrades(array $filters = []): array
    {
        $items = $this->lifecycle->getActiveTrades();

        if (empty($filters)) {
            return $items;
        }

        return array_values(array_filter($items, function (array $t) use ($filters): bool {
            return $this->matchesFilters($t, $filters);
        }));
    }

    /**
     * @param  array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function getVirtualClosedTrades(array $filters = []): array
    {
        $items = $this->lifecycle->getClosedTrades();

        if (empty($filters)) {
            return $items;
        }

        return array_values(array_filter($items, function (array $t) use ($filters): bool {
            return $this->matchesFilters($t, $filters);
        }));
    }

    /**
     * Clear all virtual storage (for testing only).
     */
    public function clearStorage(): void
    {
        $dirs = [
            'storage/virtual_signals',
            'storage/virtual_trades_active',
            'storage/virtual_trades_closed',
        ];

        foreach ($dirs as $relDir) {
            $dir = $this->state->resolvePath($relDir);
            if (!is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . '/*.json') ?: [] as $file) {
                unlink($file);
            }
        }

        $this->journal->clearAll();
        $this->state->writeJson('storage/stats.json', []);
    }

    /**
     * Get recent journal events.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getRecentJournalEvents(int $limit = 100): array
    {
        return $this->journal->getRecentEvents($limit);
    }

    /**
     * Get journal for a specific signal.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getSignalJournal(string $signalId): array
    {
        return $this->journal->getJournal($signalId);
    }

    /**
     * @return array<string,mixed>
     */
    public function getStatus(): array
    {
        $stats = $this->statsEngine->getStats();
        return [
            'module'             => 'ai_shadow',
            'enabled'            => (bool)($this->config['enabled'] ?? false),
            'mode'               => (string)($this->config['mode'] ?? 'shadow'),
            'provider'           => $this->provider->getName(),
            'provider_available' => $this->provider->isAvailable(),
            'model'              => (string)($this->config['model'] ?? ''),
            'credential_id'      => (string)($this->config['credential_id'] ?? ''),
            'allowed_patterns'   => (array)($this->config['allowed_patterns'] ?? []),
            'allowed_sides'      => (array)($this->config['allowed_sides']    ?? []),
            'journal_events'     => $this->journal->countEvents(),
            'stats_summary'      => $stats,
        ];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * @return array<string,mixed>
     */
    private function loadAiShadowConfig(): array
    {
        $path = $this->moduleBase . '/config/ai_shadow.json';
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    private function resolveProvider(): AiProviderInterface
    {
        $providerName = strtolower((string)($this->config['provider'] ?? 'mock'));

        if ($providerName === 'openai') {
            $provider = new AiProviderOpenAi($this->config);
            if ($provider->isAvailable()) {
                return $provider;
            }
            // Fall back to mock if credentials not configured
        }

        return new AiProviderMock();
    }

    /**
     * Read live signals from smart_brain storage (read-only).
     *
     * @return array<int,array<string,mixed>>
     */
    private function readLiveSignals(): array
    {
        $path = (string)($this->paths['live_signals_path'] ?? '');
        return AiShadowStateManager::readAbsolute($path, []);
    }

    /**
     * Load live closed trades from trading_bot/storage/trades/closed/ (read-only).
     *
     * @return array<int,array<string,mixed>>
     */
    private function loadLiveClosedTrades(): array
    {
        $liveTradesDir = (string)($this->paths['live_trades_dir'] ?? '');
        $closedDir     = rtrim($liveTradesDir, '/') . '/trades/closed';

        if (!is_dir($closedDir)) {
            return [];
        }

        $result = [];
        foreach (glob($closedDir . '/*.json') ?: [] as $file) {
            $data = json_decode((string)file_get_contents($file), true);
            if (is_array($data)) {
                $result[] = $data;
            }
        }
        return $result;
    }

    /**
     * @param  array<string,mixed> $item
     * @param  array<string,mixed> $filters
     */
    private function matchesFilters(array $item, array $filters): bool
    {
        foreach ($filters as $key => $value) {
            if (!array_key_exists($key, $item)) {
                continue;
            }
            if ((string)$item[$key] !== (string)$value) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function listJsonDir(string $absDir): array
    {
        $result = [];
        if (!is_dir($absDir)) {
            return $result;
        }
        foreach (glob($absDir . '/*.json') ?: [] as $file) {
            $data = json_decode((string)file_get_contents($file), true);
            if (is_array($data)) {
                $result[] = $data;
            }
        }
        return $result;
    }
}
