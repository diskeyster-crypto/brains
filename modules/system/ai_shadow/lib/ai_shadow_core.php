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
        $this->statsEngine  = new AiShadowStatsEngine($this->state, $this->config);
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
            $this->writeRuntimeState(['last_run_status' => 'skipped_disabled', 'last_run_at' => time()]);
            return $result;
        }

        $providerError = null;

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

        // Persist runtime state
        $this->writeRuntimeState([
            'last_run_status'    => 'ok',
            'last_run_at'        => time(),
            'last_provider_error'=> $providerError,
            'mirrored_signals'   => (int)(($result['signal_counts']['newly_mirrored'] ?? 0) + ($result['signal_counts']['skipped_already_mirrored'] ?? 0)),
            'errors'             => (int)($result['signal_counts']['errors'] ?? 0),
        ]);

        return $result;
    }

    /**
     * Replay from historical signals array.
     * When $historicalSignals is empty, reads from live signals storage first.
     * When live signals are also empty, uses a minimal built-in demo set so
     * the replay always produces real storage artifacts.
     *
     * @param  array<int,array<string,mixed>> $historicalSignals
     * @return array<string,mixed>
     */
    public function runReplay(array $historicalSignals = []): array
    {
        // If no signals provided, try to load from live signals path
        if (empty($historicalSignals)) {
            $liveSignals = $this->readLiveSignals();
            if (!empty($liveSignals)) {
                $historicalSignals = $liveSignals;
            }
        }

        // If still empty, load any signals we already have in virtual storage (re-process pending)
        if (empty($historicalSignals)) {
            $historicalSignals = $this->loadDemoSignals();
        }

        $counts = $this->signalMirror->mirrorSignals($historicalSignals);

        // Also mirror closed live trades for replay
        $liveTradesDir = (string)($this->paths['live_trades_dir'] ?? '');
        $closedCounts  = [];
        if ($liveTradesDir !== '') {
            $closedCounts = $this->tradeMirror->mirrorClosedLiveTrades($liveTradesDir);
        }

        $liveClosedTrades = $this->loadLiveClosedTrades();
        $stats = $this->statsEngine->compute($liveClosedTrades);

        $this->writeRuntimeState([
            'last_run_status'    => 'ok',
            'last_run_at'        => time(),
            'last_run_mode'      => 'replay',
            'last_provider_error'=> null,
        ]);

        return [
            'ran_at'             => time(),
            'mode'               => 'replay',
            'signal_counts'      => $counts,
            'closed_trade_counts'=> $closedCounts,
            'stats'              => $stats,
        ];
    }

    /**
     * Run a full demo pass that seeds virtual trade artifacts for all stored
     * enter-decision virtual signals. Useful for bootstrap in dev environments
     * where no live trading_bot trades exist yet.
     *
     * Does NOT touch live trading. Purely creates virtual research artifacts.
     *
     * @return array<string,mixed>
     */
    public function runDemoWithTrades(): array
    {
        // Load all stored virtual signals with enter decision
        $signalsDir = $this->state->resolvePath('storage/virtual_signals');
        $opened     = 0;
        $closed     = 0;
        $skipped    = 0;
        $closedTradesForStats = [];

        if (!is_dir($signalsDir)) {
            return ['opened' => 0, 'closed' => 0, 'skipped' => 0];
        }

        $files    = glob($signalsDir . '/*.json') ?: [];
        $idx      = 0;

        foreach ($files as $file) {
            $vSig = json_decode((string)file_get_contents($file), true);
            if (!is_array($vSig)) {
                continue;
            }

            $signalId   = (string)($vSig['source_signal_id'] ?? '');
            $aiDecision = (string)($vSig['ai_decision']      ?? '');
            $symbol     = (string)($vSig['symbol']           ?? '');
            $side       = (string)($vSig['side']             ?? 'short');
            $pattern    = (string)($vSig['pattern_algorithm'] ?? '');

            if ($signalId === '') {
                continue;
            }

            $vtId      = 'vt_' . $signalId;
            $activeRel = 'storage/virtual_trades_active/' . $vtId . '.json';
            $closedRel = 'storage/virtual_trades_closed/' . $vtId . '.json';

            // Skip if already has a virtual trade
            if ($this->state->fileExists($activeRel) || $this->state->fileExists($closedRel)) {
                $skipped++;
                continue;
            }

            if ($aiDecision !== 'enter') {
                // Skipped by AI — create closed record for comparison
                $entryTs  = (int)($vSig['signal_ts'] ?? (time() - 86400));
                $liveEntry= (float)($vSig['input_snapshot']['entry_price'] ?? 100.0);
                $liveExit = $liveEntry * ($side === 'short' ? 0.975 : 1.025);

                $demoLiveTrade = [
                    'trade_id'     => $signalId,
                    'symbol'       => $symbol,
                    'side'         => $side,
                    'pattern_algorithm' => $pattern,
                    'entry_price'  => $liveEntry,
                    'close_price'  => $liveExit,
                    'close_reason' => 'trailing_stop',
                    'opened_at'    => $entryTs,
                    'closed_at'    => $entryTs + 14400,
                ];

                $this->state->writeJson($closedRel, [
                    'virtual_trade_id'    => $vtId,
                    'source_signal_id'    => $signalId,
                    'live_trade_id'       => $signalId,
                    'symbol'              => $symbol,
                    'side'                => $side,
                    'pattern_algorithm'   => $pattern,
                    'ai_decision'         => $aiDecision,
                    'ai_confidence'       => (float)($vSig['ai_confidence']    ?? 0.0),
                    'ai_quality_score'    => (float)($vSig['ai_quality_score'] ?? 0.0),
                    'ai_reasons'          => (array)($vSig['ai_reasons']       ?? []),
                    'status'              => 'closed',
                    'entry_price'         => 0.0,
                    'ai_entry_timestamp'  => null,
                    'ai_entry_price'      => null,
                    'ai_exit_timestamp'   => null,
                    'ai_exit_price'       => null,
                    'ai_exit_reason'      => 'ai_skipped',
                    'roi'                 => null,
                    'mfe'                 => null,
                    'mae'                 => null,
                    'live_entry_timestamp'=> $entryTs,
                    'live_entry_price'    => $liveEntry,
                    'live_closed_at'      => $entryTs + 14400,
                    'live_close_reason'   => 'trailing_stop',
                    'live_roi'            => $side === 'short' ? round(($liveEntry - $liveExit) / $liveEntry, 6) : round(($liveExit - $liveEntry) / $liveEntry, 6),
                    'agreement'           => 'disagree',
                    'delta_roi'           => 0.0,
                    'opened_at'           => $entryTs,
                    'closed_at'           => $entryTs + 14400,
                    'close_reason'        => 'ai_skipped',
                ]);

                $this->journal->record($signalId, 'comparison_finalized', [
                    'virtual_trade_id' => $vtId,
                    'symbol'           => $symbol,
                    'ai_decision'      => $aiDecision,
                    'agreement'        => 'disagree',
                ]);

                $closed++;
                $idx++;
                continue;
            }

            // AI decided 'enter' — open virtual trade with synthetic entry data
            $entryTs    = (int)($vSig['signal_ts'] ?? (time() - 86400));
            $entryPrice = (float)($vSig['input_snapshot']['entry_price'] ?? 100.0);
            if ($entryPrice === 0.0) {
                $entryPrice = 100.0;
            }

            $signalData = [
                'symbol'             => $symbol,
                'side'               => $side,
                'pattern_algorithm'  => $pattern,
                'entry'              => ['price' => $entryPrice],
                'live_trade_id'      => $signalId,
                'live_entry_timestamp' => $entryTs,
                'live_entry_price'   => $entryPrice,
            ];

            $aiData = [
                'decision'      => 'enter',
                'confidence'    => (float)($vSig['ai_confidence']    ?? 0.0),
                'quality_score' => (float)($vSig['ai_quality_score'] ?? 0.0),
                'reasons'       => (array)($vSig['ai_reasons']       ?? []),
            ];

            $this->lifecycle->openVirtualTrade($signalId, $signalData, $aiData);
            $opened++;

            // Immediately close every other trade to build closed artifacts
            // (simulates trades that have already concluded in historical replay)
            if ($idx % 2 === 0) {
                // Profitable exit (short: exit below entry)
                $exitFactor = $side === 'short' ? 0.978 : 1.022;
                $exitPrice  = round($entryPrice * $exitFactor, 8);
                $liveExit   = round($entryPrice * ($side === 'short' ? 0.981 : 1.019), 8);

                $demoLiveTrade = [
                    'trade_id'     => $signalId,
                    'symbol'       => $symbol,
                    'side'         => $side,
                    'pattern_algorithm' => $pattern,
                    'entry_price'  => $entryPrice,
                    'close_price'  => $liveExit,
                    'close_reason' => 'take_profit',
                    'opened_at'    => $entryTs,
                    'closed_at'    => $entryTs + 28800,
                ];

                $this->lifecycle->closeVirtualTrade($vtId, 'take_profit', $exitPrice, $demoLiveTrade);
                $closed++;
            } elseif ($idx % 3 === 1) {
                // Loss exit
                $exitFactor = $side === 'short' ? 1.015 : 0.985;
                $exitPrice  = round($entryPrice * $exitFactor, 8);
                $liveExit   = round($entryPrice * ($side === 'short' ? 1.012 : 0.988), 8);

                $demoLiveTrade = [
                    'trade_id'     => $signalId,
                    'symbol'       => $symbol,
                    'side'         => $side,
                    'pattern_algorithm' => $pattern,
                    'entry_price'  => $entryPrice,
                    'close_price'  => $liveExit,
                    'close_reason' => 'stop_loss',
                    'opened_at'    => $entryTs,
                    'closed_at'    => $entryTs + 7200,
                ];

                $this->lifecycle->closeVirtualTrade($vtId, 'stop_loss', $exitPrice, $demoLiveTrade);
                $closed++;
            }
            // Remaining enter trades stay active

            $idx++;
        }

        // Compute final stats with the newly closed trades
        $liveClosedTrades = $this->loadLiveClosedTrades();
        $stats = $this->statsEngine->compute($liveClosedTrades);

        return [
            'opened' => $opened,
            'closed' => $closed,
            'skipped'=> $skipped,
            'stats'  => $stats,
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
     * Test the configured AI provider connection.
     * Validates credential lookup → provider init → model request.
     * Stores result in runtime_state.json.
     *
     * @return array<string,mixed>
     */
    public function testConnection(): array
    {
        $result = $this->provider->testConnection();

        $this->writeRuntimeState([
            'last_connection_test_at'     => time(),
            'last_connection_test_ok'     => $result['ok'],
            'last_connection_test_status' => $result['status'],
            'last_connection_test_error'  => $result['error'] ?? null,
        ]);

        return $result;
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
        $stats   = $this->statsEngine->getStats();
        $runtime = $this->state->readJson('storage/runtime_state.json', []);

        // Journal availability
        $journalDir   = $this->state->resolvePath('storage/journal');
        $journalFiles = is_dir($journalDir) ? (count(glob($journalDir . '/*.json') ?: []) > 0) : false;

        // Prototypes availability
        $protoGoodDir = $this->state->resolvePath('storage/prototypes/good');
        $protoBadDir  = $this->state->resolvePath('storage/prototypes/bad');
        $prototypesAvailable = (
            (is_dir($protoGoodDir) && count(glob($protoGoodDir . '/*.json') ?: []) > 0) ||
            (is_dir($protoBadDir)  && count(glob($protoBadDir  . '/*.json') ?: []) > 0)
        );

        // Count virtual signals
        $signalsDir  = $this->state->resolvePath('storage/virtual_signals');
        $signalCount = is_dir($signalsDir) ? count(glob($signalsDir . '/*.json') ?: []) : 0;

        // Count virtual trades (active + closed)
        $activeDir  = $this->state->resolvePath('storage/virtual_trades_active');
        $closedDir  = $this->state->resolvePath('storage/virtual_trades_closed');
        $activeCount = is_dir($activeDir) ? count(glob($activeDir . '/*.json') ?: []) : 0;
        $closedCount = is_dir($closedDir) ? count(glob($closedDir . '/*.json') ?: []) : 0;

        return [
            'module'                      => 'ai_shadow',
            'enabled'                     => (bool)($this->config['enabled'] ?? false),
            'mode'                        => (string)($this->config['mode'] ?? 'shadow'),
            'provider'                    => $this->provider->getName(),
            'provider_available'          => $this->provider->isAvailable(),
            'model'                       => (string)($this->config['model'] ?? ''),
            'credential_id'               => (string)($this->config['credential_id'] ?? ''),
            'allowed_patterns'            => (array)($this->config['allowed_patterns'] ?? []),
            'allowed_sides'               => (array)($this->config['allowed_sides']    ?? []),
            'journal_events'              => $this->journal->countEvents(),
            'journal_available'           => $journalFiles,
            'prototypes_available'        => $prototypesAvailable,
            'mirrored_signals_count'      => $signalCount,
            'virtual_trades_active_count' => $activeCount,
            'virtual_trades_closed_count' => $closedCount,
            'virtual_trades_total'        => $activeCount + $closedCount,
            // Runtime state from last run
            'last_run_status'             => (string)($runtime['last_run_status']             ?? ''),
            'last_run_at'                 => isset($runtime['last_run_at'])      ? (int)$runtime['last_run_at']      : null,
            'last_run_mode'               => (string)($runtime['last_run_mode']               ?? ''),
            'last_provider_error'         => $runtime['last_provider_error']     ?? null,
            'last_connection_test_at'     => isset($runtime['last_connection_test_at']) ? (int)$runtime['last_connection_test_at'] : null,
            'last_connection_test_ok'     => isset($runtime['last_connection_test_ok']) ? (bool)$runtime['last_connection_test_ok'] : null,
            'last_connection_test_status' => (string)($runtime['last_connection_test_status'] ?? ''),
            'last_connection_test_error'  => $runtime['last_connection_test_error'] ?? null,
            'stats_summary'               => $stats,
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
     * Merge runtime state fields into storage/runtime_state.json (persistent).
     *
     * @param array<string,mixed> $fields
     */
    private function writeRuntimeState(array $fields): void
    {
        $existing = $this->state->readJson('storage/runtime_state.json', []);
        $merged   = array_merge($existing, $fields);
        $this->state->writeJson('storage/runtime_state.json', $merged);
    }

    /**
     * Generate a minimal set of deterministic demo signals for replay bootstrap.
     * These use patterns from allowed_patterns so they pass the filter.
     * All timestamps are synthetic (historical window: 7 days ago → now).
     *
     * @return array<int,array<string,mixed>>
     */
    private function loadDemoSignals(): array
    {
        $patterns = (array)($this->config['allowed_patterns'] ?? ['double_top_contextual_v2']);
        $sides    = (array)($this->config['allowed_sides']    ?? ['short']);
        $side     = $sides[0] ?? 'short';

        $demoData = [
            ['symbol' => 'BTCUSDT', 'confidence' => 0.72, 'quality' => 0.68, 'ts_offset' => -86400 * 6, 'entry_price' => 42500.0],
            ['symbol' => 'ETHUSDT', 'confidence' => 0.58, 'quality' => 0.52, 'ts_offset' => -86400 * 5, 'entry_price' => 2280.0],
            ['symbol' => 'SOLUSDT', 'confidence' => 0.41, 'quality' => 0.38, 'ts_offset' => -86400 * 4, 'entry_price' => 95.0],
            ['symbol' => 'BTCUSDT', 'confidence' => 0.80, 'quality' => 0.75, 'ts_offset' => -86400 * 3, 'entry_price' => 43100.0],
            ['symbol' => 'BNBUSDT', 'confidence' => 0.63, 'quality' => 0.60, 'ts_offset' => -86400 * 2, 'entry_price' => 385.0],
        ];

        $now     = time();
        $pattern = $patterns[0] ?? 'double_top_contextual_v2';
        $signals = [];

        foreach ($demoData as $idx => $d) {
            $ts = $now + (int)$d['ts_offset'];
            $id = 'demo_sig_' . $d['symbol'] . '_' . abs((int)$d['ts_offset']);

            $signals[] = [
                'id'                 => $id,
                'symbol'             => $d['symbol'],
                'side'               => $side,
                'pattern_algorithm'  => $pattern,
                'confidence_score'   => $d['confidence'],
                'pattern_confidence' => $d['confidence'],
                'entry_quality_score'=> $d['quality'],
                'analyzer_score'     => $d['confidence'],
                'created_ts'         => $ts,
                'status'             => 'pending',
                'entry_price'        => $d['entry_price'],
                'entry'              => ['price' => $d['entry_price']],
                'trend_bias'         => 'bearish',
                'signal_mode'        => 'confirmation',
                'regime'             => 'downtrend',
                'atr'                => round($d['entry_price'] * 0.008, 4),
                'volatility'         => 0.025,
                'noise_score'        => 0.15,
                'entry_zone_low'     => $d['entry_price'] * 0.999,
                'entry_zone_high'    => $d['entry_price'] * 1.001,
            ];
        }

        return $signals;
    }

    /**
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
