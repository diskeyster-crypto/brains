<?php

declare(strict_types=1);

/**
 * Bot Module — Service
 *
 * Architecture split:
 *   strategy modules → find signals, each writes storage/bot_handoff_queue.json
 *   bot module       → autodiscovers strategies, reads operator overrides, owns
 *                      order_queue.json / active_orders.json
 *   profit manager   → separate, monitors profit locks
 *
 * Control layer (this step):
 *   - strategy autodiscovery via manifest.json scan
 *   - strategy_registry.json — persisted registry of all discovered strategies
 *   - operator_overrides.json — compact per-strategy operator controls
 *   - bot consumes only operator-enabled strategies via the registry
 *
 * Bot queue lifecycle states:
 *   queued    — signal ingested, awaiting validation
 *   ready     — validated, bot-owned, ready for execution
 *   submitted — promoted to active_orders (terminal for the queue)
 *   expired   — signal TTL elapsed before execution
 *   withdrawn — signal disappeared from handoff before TTL
 *
 * Bot execution modes:
 *   demo     — PRIMARY: real execution on Bybit Demo account (api-demo.bybit.com)
 *   disabled — ingest/runtime only; no local entries created
 *   passive  — ingest/queue/runtime only; no execution promotion
 *   paper    — full local execution simulation in storage; no exchange interaction (legacy)
 *   smoke    — legacy alias for paper
 *   active   — legacy alias for paper
 *
 * Order lifecycle states (demo mode):
 *   created          — order record built from ready queue item
 *   submitted_demo   — market order submitted to Bybit Demo
 *   confirmed_demo   — position verified open on Bybit Demo
 *   cancelled        — order cancelled before fill
 *   expired          — order TTL elapsed before fill
 *
 * Order lifecycle states (paper mode):
 *   created          — order record built from ready queue item
 *   submitted_paper  — paper-submitted (deterministic, no real exchange)
 *   filled_paper     — paper-filled; originating position is created
 *   cancelled        — order cancelled before fill
 *   expired          — order TTL elapsed before fill
 *
 * Position lifecycle states:
 *   open    — active position (demo or paper)
 *   closing — close in progress (reserved)
 *   closed  — position closed
 *
 * Storage files:
 *   active_orders.json      — open orders (demo or paper)
 *   active_positions.json   — open positions cache (demo: synced from Bybit Demo; paper: local)
 *   closed_positions.json   — historical closed positions
 *   execution_log.ndjson    — append-only execution event log
 */

namespace Modules\Bot;

final class BotService
{
    private static ?self $instance = null;
    private string $moduleDir;
    private string $repoRoot;

    public function __construct(?string $moduleDir = null)
    {
        if ($moduleDir !== null) {
            $this->moduleDir = rtrim($moduleDir, '/');
        } else {
            $paths = \Core\System\SystemPaths::instance();
            $this->moduleDir = rtrim(
                $paths->has('bot.bot') ? $paths->get('bot.bot') : $paths->get('bot'),
                '/'
            );
        }
        // Derive repo root: two levels above modules/bot (i.e. the repo root)
        $this->repoRoot = rtrim(dirname($this->moduleDir, 2), '/');
    }

    public static function instance(string $moduleDir): self
    {
        if (self::$instance === null) {
            self::$instance = new self($moduleDir);
        }
        return self::$instance;
    }

    // =========================================================================
    // Public API
    // =========================================================================

    public function getConfig(): array
    {
        try {
            $this->requireBootstrap();
            return BotBootstrap::instance($this->moduleDir)->load()['config'];
        } catch (\Throwable) {
            return [];
        }
    }

    public function getLastRun(): array
    {
        return $this->readJson('storage/last_run.json', []);
    }

    /**
     * Check connectivity to Bybit Demo account using configured credentials.
     *
     * Makes a lightweight authenticated call (/v5/account/wallet-balance) and
     * returns a structured result.  Never logs credentials.
     *
     * @return array{
     *   connected: bool,
     *   mode: string,
     *   account: string,
     *   error: string|null
     * }
     */
    public function checkDemoConnection(): array
    {
        $config    = $this->getConfig();
        $apiKey    = (string)($config['demo_api_key']      ?? '');
        $apiSecret = (string)($config['demo_api_secret']   ?? '');
        $baseUrl   = (string)($config['demo_api_base_url'] ?? 'https://api-demo.bybit.com');

        if ($apiKey === '' || $apiSecret === '') {
            return [
                'connected' => false,
                'mode'      => 'demo',
                'account'   => 'bybit_demo',
                'error'     => 'missing_credentials',
            ];
        }

        try {
            $gw = \Core\Gateway\Bybit::client('bybit_demo_check');
            $gw->setCredentials($apiKey, $apiSecret);
            $gw->setBaseUrl($baseUrl);

            $resp = $gw->request('/v5/account/wallet-balance', [
                'accountType' => 'UNIFIED',
            ], true);

            $retCode = $resp['ret_code'] ?? -1;
            $success = ($resp['success'] ?? false) && $retCode === 0;

            if ($success) {
                return [
                    'connected' => true,
                    'mode'      => 'demo',
                    'account'   => 'bybit_demo',
                    'error'     => null,
                ];
            }

            $errMsg = (string)($resp['ret_msg'] ?? ($resp['error_type'] ?? 'api_error'));
            return [
                'connected' => false,
                'mode'      => 'demo',
                'account'   => 'bybit_demo',
                'error'     => $errMsg,
            ];
        } catch (\Throwable $ex) {
            return [
                'connected' => false,
                'mode'      => 'demo',
                'account'   => 'bybit_demo',
                'error'     => $ex->getMessage(),
            ];
        }
    }

    public function getStats(): array
    {
        return $this->readJson('storage/stats.json', []);
    }

    public function getOrderQueue(): array
    {
        return $this->readJson('storage/order_queue.json', []);
    }

    public function getActiveOrders(): array
    {
        return $this->readJson('storage/active_orders.json', []);
    }

    public function getActivePositions(): array
    {
        return $this->readJson('storage/active_positions.json', []);
    }

    public function getStrategyRegistry(): array
    {
        return $this->readJson('storage/strategy_registry.json', []);
    }

    public function getOperatorOverrides(): array
    {
        return $this->readJson('storage/operator_overrides.json', []);
    }

    public function saveOperatorOverrides(array $overrides): void
    {
        $this->writeJson('storage/operator_overrides.json', $overrides);
    }

    public function getRuntimeSnapshot(): array
    {
        try {
            $snap = require $this->moduleDir . '/config/runtime_snapshot.php';
            return is_array($snap) ? $snap : [];
        } catch (\Throwable) {
            return [];
        }
    }

    // =========================================================================
    // Cron entry-point
    // =========================================================================

    /**
     * CronManager entry-point: process one tick.
     *
     * 1. Refresh strategy registry via manifest scan.
     * 2. Load operator overrides to determine enabled/disabled strategies.
     * 3. Read handoff queues from all enabled strategies.
     * 4. Merge signals into bot-owned order queue (deduped by strategy_id:signal_id).
     * 5. Persist all state.
     */
    public function tick(): void
    {
        // Always ensure storage files exist with truthful empty-state values,
        // even when the bot is disabled. This guarantees the admin UI always
        // has real files to read rather than missing-file errors.
        $this->initializeStorage();

        $config = $this->getConfig();
        if (empty($config)) {
            return;
        }

        $tickAt = date('c');
        $tStart = microtime(true);

        // ── 1. Strategy discovery ─────────────────────────────────────────────
        // Registry must stay fresh even when the bot is disabled so dashboard
        // and runtime views do not show a stale strategy count after modules are
        // added, removed, or cleaned.
        $registry  = $this->refreshRegistry($config);
        $overrides = $this->readJson('storage/operator_overrides.json', []);

        $enabledStrategies  = [];
        $disabledStrategies = [];
        foreach ($registry as $rec) {
            $stratId   = (string)($rec['strategy_id'] ?? '');
            $opEnabled = (bool)($overrides[$stratId]['enabled'] ?? true);
            if ($opEnabled) {
                $enabledStrategies[] = $rec;
            } else {
                $disabledStrategies[] = $rec;
            }
        }

        // ── Demo credentials diagnostics ─────────────────────────────────────
        $botMode            = (string)($config['mode'] ?? 'passive');
        $demoApiKey         = (string)($config['demo_api_key']    ?? '');
        $demoApiSecret      = (string)($config['demo_api_secret'] ?? '');
        $demoCredsConfigured = ($demoApiKey !== '' && $demoApiSecret !== '');
        $demoConnected      = false;
        $demoConnError      = null;

        if ($botMode === 'demo') {
            $connResult        = $this->checkDemoConnection();
            $demoConnected     = (bool)($connResult['connected'] ?? false);
            $demoConnError     = $connResult['error'] ?? null;
        } elseif (!$demoCredsConfigured) {
            $demoConnError = 'missing_credentials';
        }

        $account = $botMode === 'demo' ? 'bybit_demo' : 'local';

        if (!(bool)($config['enabled'] ?? false)) {
            // Bot disabled: still write truthful last_run including fresh strategy
            // discovery totals so the dashboard can render the current registry.
            $stats = array_merge($this->zeroStats(), $this->getStats(), [
                'strategies_discovered_total' => count($registry),
                'strategies_enabled_total'    => count($enabledStrategies),
                'strategies_disabled_total'   => count($disabledStrategies),
            ]);
            $this->writeJson('storage/stats.json', $stats);
            $this->writeJson('storage/last_run.json', array_merge(
                $this->readJson('storage/last_run.json', []),
                [
                    'status'                       => 'disabled',
                    'tick_at'                      => $tickAt,
                    'elapsed_sec'                  => round(microtime(true) - $tStart, 6),
                    'bot_enabled'                  => false,
                    'bot_mode'                     => $botMode,
                    'mode'                         => $botMode,
                    'account'                      => $account,
                    'demo_credentials_configured'  => $demoCredsConfigured,
                    'demo_connected'               => $demoConnected,
                    'demo_connection_error'        => $demoConnError,
                    'strategies_discovered_total'  => count($registry),
                    'strategies_enabled_total'     => count($enabledStrategies),
                    'strategies_disabled_total'    => count($disabledStrategies),
                    'handoff_sources_active_total' => 0,
                    'handoff_signals_processed'    => 0,
                    'order_queue_total'            => count($this->readJson('storage/order_queue.json', [])),
                    'active_orders_count'          => count($this->readJson('storage/active_orders.json', [])),
                    'active_positions_count'       => count($this->readJson('storage/active_positions.json', [])),
                    'ticks_total'                  => (int)($stats['ticks_total'] ?? 0),
                    'handoff_signals_seen_total'   => (int)($stats['handoff_signals_seen_total'] ?? 0),
                ]
            ));
            $this->writeRuntimeSnapshot($config, $this->readJson('storage/last_run.json', []), count($registry), count($enabledStrategies));
            return;
        }

        $handoffSourcesActive = 0;
        foreach ($enabledStrategies as $rec) {
            if (($rec['status'] ?? '') === 'bot_ready') {
                $handoffSourcesActive++;
            }
        }

        // ── 2. Collect signals ────────────────────────────────────────────────
        $allSignals          = [];
        $ignoredSignalsCount = 0;

        foreach ($enabledStrategies as $rec) {
            $signals    = $this->readHandoffQueueForStrategy($rec);
            $allSignals = array_merge($allSignals, $signals);
        }
        // Count signals from disabled strategies (tracked but not processed)
        foreach ($disabledStrategies as $rec) {
            $ignoredSignalsCount += count($this->readHandoffQueueForStrategy($rec));
        }

        // ── 3. Load bot state ─────────────────────────────────────────────────
        $orderQueue       = $this->readJson('storage/order_queue.json', []);
        $activeOrders     = $this->readJson('storage/active_orders.json', []);
        $activePositions  = $this->readJson('storage/active_positions.json', []);
        $closedPositions  = $this->readJson('storage/closed_positions.json', []);
        $stats            = array_merge($this->zeroStats(), $this->getStats());

        // ── 4. Process handoff → order queue ──────────────────────────────────
        $result     = $this->processHandoff($allSignals, $orderQueue, $config, $overrides, $tickAt);
        $orderQueue = $result['order_queue'];

        // ── 5. Execution state machine ────────────────────────────────────────
        $execResult = $this->processExecution($orderQueue, $activeOrders, $activePositions, $closedPositions, $botMode, $config, $tickAt);
        $orderQueue      = $execResult['order_queue'];
        $activeOrders    = $execResult['active_orders'];
        $activePositions = $execResult['active_positions'];
        $closedPositions = $execResult['closed_positions'];

        // ── 6. Update stats ───────────────────────────────────────────────────
        $stats['ticks_total']               += 1;
        // Discovery counters are current-state snapshots, not cumulative
        $stats['strategies_discovered_total'] = count($registry);
        $stats['strategies_enabled_total']    = count($enabledStrategies);
        $stats['strategies_disabled_total']   = count($disabledStrategies);
        $stats['handoff_sources_active_total']= $handoffSourcesActive;
        // Signal counters are cumulative
        $stats['handoff_signals_seen_total']  += $result['signals_seen'];
        $stats['handoff_signals_ignored_disabled_strategy_total'] += $ignoredSignalsCount;
        $stats['order_queue_new_total']       += $result['new_total'];
        $stats['order_queue_refreshed_total'] += $result['refreshed_total'];
        $stats['order_queue_expired_total']   += $result['expired_total'];
        $stats['order_queue_withdrawn_total'] += $result['withdrawn_total'];
        // Execution counters are cumulative
        $stats['orders_created_total']                    += $execResult['orders_created'];
        $stats['orders_submitted_paper_total']            += $execResult['orders_submitted_paper'];
        $stats['orders_filled_paper_total']               += $execResult['orders_filled_paper'];
        $stats['orders_submitted_demo_total']             += $execResult['orders_submitted_demo'];
        $stats['orders_confirmed_demo_total']             += $execResult['orders_confirmed_demo'];
        $stats['positions_opened_total']                  += $execResult['positions_opened'];
        $stats['positions_closed_total']                  += $execResult['positions_closed'];
        $stats['positions_closed_expired_total']          += $execResult['positions_closed_expired'];
        $stats['positions_closed_withdrawn_total']        += $execResult['positions_closed_withdrawn'];
        $stats['positions_closed_reverse_pattern_total']  += $execResult['positions_closed_reverse_pattern'];
        $stats['queue_items_skipped_mode_disabled_total'] += $execResult['queue_items_skipped_mode_disabled'];
        $stats['queue_items_skipped_mode_passive_total']  += $execResult['queue_items_skipped_mode_passive'];
        $stats['execution_log_events_total']              += $execResult['execution_log_events'];
        // Derived counts
        $stats['order_queue_total']      = $this->countByStatus($orderQueue, ['queued', 'ready']);
        $stats['active_orders_total']    = count($activeOrders);
        $stats['active_positions_total'] = count($activePositions);

        // ── 7. Persist ────────────────────────────────────────────────────────
        $this->writeJson('storage/order_queue.json',       $orderQueue);
        $this->writeJson('storage/active_orders.json',     $activeOrders);
        $this->writeJson('storage/active_positions.json',  $activePositions);
        $this->writeJson('storage/closed_positions.json',  $closedPositions);
        $this->writeJson('storage/stats.json',             $stats);

        $elapsed = round(microtime(true) - $tStart, 4);

        $lastRun = [
            'status'      => 'ok',
            'tick_at'     => $tickAt,
            'elapsed_sec' => $elapsed,
            'bot_enabled' => true,
            'bot_mode'    => $botMode,
            'mode'        => $botMode,
            'account'     => $account,
            'demo_credentials_configured' => $demoCredsConfigured,
            'demo_connected'              => $demoConnected,
            'demo_connection_error'       => $demoConnError,

            // Discovery
            'strategies_discovered_total' => count($registry),
            'strategies_enabled_total'    => count($enabledStrategies),
            'strategies_disabled_total'   => count($disabledStrategies),
            'handoff_sources_active_total'=> $handoffSourcesActive,

            // Signals this tick
            'handoff_signals_processed'                  => $result['signals_seen'],
            'handoff_signals_ignored_disabled_strategy'  => $ignoredSignalsCount,
            'handoff_signals_ignored_invalid_payload'    => $result['ignored_invalid_signal_payload'],
            'handoff_signals_ignored_invalid_entry_mode' => $result['ignored_invalid_entry_mode'],

            // Queue changes this tick
            'order_queue_new_total'       => $result['new_total'],
            'order_queue_refreshed_total' => $result['refreshed_total'],
            'order_queue_expired_total'   => $result['expired_total'],
            'order_queue_withdrawn_total' => $result['withdrawn_total'],

            // Execution this tick
            'execution_mode'                         => $botMode,
            'orders_created'                         => $execResult['orders_created'],
            'orders_submitted_paper'                 => $execResult['orders_submitted_paper'],
            'orders_filled_paper'                    => $execResult['orders_filled_paper'],
            'orders_submitted_demo'                  => $execResult['orders_submitted_demo'],
            'orders_confirmed_demo'                  => $execResult['orders_confirmed_demo'],
            'positions_opened'                       => $execResult['positions_opened'],
            'positions_closed'                       => $execResult['positions_closed'],
            'positions_closed_expired'               => $execResult['positions_closed_expired'],
            'positions_closed_withdrawn'             => $execResult['positions_closed_withdrawn'],
            'positions_closed_reverse_pattern'       => $execResult['positions_closed_reverse_pattern'],
            'queue_items_skipped_mode_disabled'      => $execResult['queue_items_skipped_mode_disabled'],
            'queue_items_skipped_mode_passive'       => $execResult['queue_items_skipped_mode_passive'],
            'execution_log_events'                   => $execResult['execution_log_events'],

            // Demo execution diagnostics (this tick)
            'demo_orders_prepared'           => $execResult['demo_orders_prepared']           ?? 0,
            'demo_orders_rejected'           => $execResult['demo_orders_rejected']           ?? 0,
            'demo_orders_submitted'          => $execResult['orders_submitted_demo']           ?? 0,
            'demo_orders_confirmed'          => $execResult['orders_confirmed_demo']           ?? 0,
            'demo_last_error_code'           => $execResult['demo_last_error_code']           ?? null,
            'demo_last_error_msg'            => $execResult['demo_last_error_msg']            ?? null,
            'demo_last_rejected_symbol'      => $execResult['demo_last_rejected_symbol']      ?? null,
            'demo_qty_invalid_count'         => $execResult['demo_qty_invalid_count']         ?? 0,
            'demo_leverage_clamped_count'    => $execResult['demo_leverage_clamped_count']    ?? 0,
            'demo_set_leverage_failed_count' => $execResult['demo_set_leverage_failed_count'] ?? 0,

            // Current queue/execution state
            'order_queue_total'      => $stats['order_queue_total'],
            'active_orders_count'    => count($activeOrders),
            'active_positions_count' => count($activePositions),

            // Cumulative
            'ticks_total'                => (int)($stats['ticks_total'] ?? 0),
            'handoff_signals_seen_total' => (int)($stats['handoff_signals_seen_total'] ?? 0),
        ];

        $this->writeJson('storage/last_run.json', $lastRun);
        $this->writeRuntimeSnapshot($config, $lastRun, count($registry), count($enabledStrategies));
    }

    // =========================================================================
    // Strategy autodiscovery
    // =========================================================================

    /**
     * Scan modules/strategy/* (up to configured depth) for strategy modules.
     * Writes strategy_registry.json and returns the discovered records.
     * Always overwrites the file with a fresh scan — never merges stale contents.
     *
     * A directory is a strategy module when it has a manifest.json with
     * category == 'strategy'. Storage presence is NOT required for discovery.
     *
     * status: 'bot_ready'              — storage/bot_handoff_queue.json exists
     *         'discovered'             — storage dir present but no handoff queue yet
     *         'storage_not_initialized'— manifest found, storage dir absent
     */
    private function refreshRegistry(array $config): array
    {
        $discovered = $this->discoverStrategies($config);
        $this->writeJson('storage/strategy_registry.json', $discovered);
        return $discovered;
    }

    private function discoverStrategies(array $config): array
    {
        $scanRoots = (array)($config['strategy_scan_roots'] ?? ['modules/strategy']);
        $maxDepth  = max(1, min(5, (int)($config['strategy_scan_depth'] ?? 3)));

        $found = [];
        foreach ($scanRoots as $root) {
            $absRoot = str_starts_with($root, '/') ? $root : $this->repoRoot . '/' . $root;
            if (is_dir($absRoot)) {
                $this->scanDirForStrategies($absRoot, 0, $maxDepth, $found);
            }
        }
        return array_values($found);
    }

    private function scanDirForStrategies(string $dir, int $depth, int $maxDepth, array &$found): void
    {
        $manifestPath = $dir . '/manifest.json';
        if (file_exists($manifestPath)) {
            $raw      = @file_get_contents($manifestPath);
            $manifest = ($raw !== false) ? json_decode($raw, true) : null;
            if (is_array($manifest) && ($manifest['category'] ?? '') === 'strategy') {
                $record  = $this->buildStrategyRegistryRecord($dir, $manifest);
                if ($record !== null) {
                    $found[$record['strategy_id']] = $record;
                }
            }
        }
        if ($depth < $maxDepth) {
            $subdirs = glob($dir . '/*', GLOB_ONLYDIR) ?: [];
            foreach ($subdirs as $subdir) {
                $this->scanDirForStrategies($subdir, $depth + 1, $maxDepth, $found);
            }
        }
    }

    /**
     * Build a registry record for a discovered strategy module.
     */
    private function buildStrategyRegistryRecord(string $absDir, array $manifest): ?array
    {
        $stratId = (string)($manifest['name'] ?? '');
        if ($stratId === '') {
            return null;
        }

        $modulePath = ltrim(str_replace($this->repoRoot . '/', '', $absDir), '/');

        // Storage directory and handoff queue presence determine status
        $hasStorage     = is_dir($absDir . '/storage');
        $handoffRelPath = $modulePath . '/storage/bot_handoff_queue.json';
        $handoffAbsPath = $absDir . '/storage/bot_handoff_queue.json';
        $hasHandoff     = file_exists($handoffAbsPath);

        // Infer long/short support from strategy_id and description
        $supportsLong  = !str_ends_with($stratId, '_short');
        $supportsShort = !str_ends_with($stratId, '_long');
        $desc = strtolower((string)($manifest['description'] ?? ''));
        if (str_contains($desc, 'long') || str_contains($desc, 'лонг')) {
            $supportsLong  = true;
            $supportsShort = str_contains($desc, 'short') || str_contains($desc, 'шорт');
        }

        return [
            'strategy_id'        => $stratId,
            'module_path'        => $modulePath,
            'manifest_path'      => $modulePath . '/manifest.json',
            'title'              => (string)($manifest['title'] ?? $stratId),
            'category'           => (string)($manifest['category'] ?? 'strategy'),
            'enabled_by_default' => (bool)($manifest['enabled_by_default'] ?? true),
            'handoff_queue_path' => $hasHandoff ? $handoffRelPath : null,
            'supports_long'      => $supportsLong,
            'supports_short'     => $supportsShort,
            'status'             => $hasHandoff ? 'bot_ready' : ($hasStorage ? 'discovered' : 'storage_not_initialized'),
            'discovered_at'      => date('c'),
        ];
    }

    // =========================================================================
    // Handoff ingestion
    // =========================================================================

    /**
     * Read handoff queue for a single registry record.
     * Returns only signals with handoff_status in [new, refreshed].
     */
    private function readHandoffQueueForStrategy(array $record): array
    {
        $relPath = $record['handoff_queue_path'] ?? null;
        if ($relPath === null) {
            return [];
        }

        $absPath = str_starts_with($relPath, '/') ? $relPath : $this->repoRoot . '/' . $relPath;
        if (!file_exists($absPath)) {
            return [];
        }
        $raw = file_get_contents($absPath);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $ready = [];
        foreach ($decoded as $rec) {
            $status = (string)($rec['handoff_status'] ?? '');
            if (in_array($status, ['new', 'refreshed'], true)) {
                $ready[] = $rec;
            }
        }
        return $ready;
    }

    /**
     * Merge handoff signals from all enabled strategies into the bot order queue.
     *
     * Deduplication key: {strategy_id}:{signal_id}  (composite, cross-strategy safe)
     *
     * Rules:
     *   - new signal              → status = queued
     *   - same signal seen again  → status = ready, seen_count++
     *   - signal gone from handoff, TTL expired  → status = expired
     *   - signal gone from handoff, still valid  → status = withdrawn
     *   - terminal items (expired/withdrawn/…)   → kept unchanged
     *
     * Operator overrides applied per strategy:
     *   - entry_mode (if set and valid)
     *   - bot_budget (if > 0)
     *   - bot_leverage (if > 0)
     *
     * @return array{
     *   order_queue: array,
     *   signals_seen: int,
     *   new_total: int,
     *   refreshed_total: int,
     *   expired_total: int,
     *   withdrawn_total: int
     * }
     */
    private function processHandoff(
        array $handoffSignals,
        array $orderQueue,
        array $config,
        array $overrides,
        string $tickAt
    ): array {
        $allowedModes = (array)($config['allowed_entry_modes'] ?? ['limit', 'market']);
        $maxAgeSec    = (int)($config['max_signal_age_sec'] ?? 0);

        // Build queue lookup by composite key
        $queueMap = [];
        foreach ($orderQueue as $item) {
            $key = $this->queueKey($item);
            if ($key !== '') {
                $queueMap[$key] = $item;
            }
        }

        $newTotal                  = 0;
        $refreshedTotal            = 0;
        $expiredTotal              = 0;
        $withdrawnTotal            = 0;
        $ignoredInvalidPayload     = 0;
        $ignoredInvalidMode        = 0;
        $result                    = [];
        $activeKeys                = [];

        foreach ($handoffSignals as $signal) {
            $signalId = (string)($signal['signal_id'] ?? '');
            if ($signalId === '') {
                $ignoredInvalidPayload++;
                continue;
            }
            $stratId = (string)($signal['strategy_id'] ?? $signal['owner_strategy'] ?? '');
            $key     = ($stratId !== '' ? $stratId : 'unknown') . ':' . $signalId;

            // Resolve entry_mode: operator override > signal value
            $entryMode   = (string)($signal['entry_mode'] ?? 'limit');
            $opOverrides = (array)($overrides[$stratId] ?? []);
            $opMode      = $opOverrides['entry_mode'] ?? null;
            if ($opMode !== null && in_array($opMode, ['limit', 'market'], true)) {
                $entryMode = $opMode;
            }

            // Entry mode gate
            if (!in_array($entryMode, $allowedModes, true)) {
                $ignoredInvalidMode++;
                continue;
            }

            // Age gate
            if ($maxAgeSec > 0) {
                $detectedTs = strtotime((string)($signal['detected_at'] ?? ''));
                if ($detectedTs !== false && (time() - $detectedTs) > $maxAgeSec) {
                    continue;
                }
            }

            $activeKeys[$key] = true;

            if (isset($queueMap[$key])) {
                $prev       = $queueMap[$key];
                $prevStatus = (string)($prev['queue_status'] ?? 'queued');

                if (in_array($prevStatus, ['queued', 'ready'], true)) {
                    $item = $this->buildQueueItem($signal, $opOverrides, $config, $tickAt);
                    $item['entry_mode']            = $entryMode;
                    $item['queue_status']          = 'ready';
                    $item['first_queued_at']       = $prev['first_queued_at'] ?? $tickAt;
                    $item['seen_count']            = (int)($prev['seen_count'] ?? 1) + 1;
                    $item['last_refreshed_at']     = $tickAt;
                    $item['last_change_reason']    = 'refreshed_from_handoff';
                    $item['source_handoff_status'] = (string)($signal['handoff_status'] ?? 'refreshed');
                    $result[$key]                  = $item;
                    $refreshedTotal++;
                } else {
                    $result[$key] = $prev;
                }
            } else {
                $item = $this->buildQueueItem($signal, $opOverrides, $config, $tickAt);
                $item['entry_mode']            = $entryMode;
                $item['queue_status']          = 'queued';
                $item['first_queued_at']       = $tickAt;
                $item['seen_count']            = 1;
                $item['last_refreshed_at']     = $tickAt;
                $item['last_change_reason']    = 'new_from_handoff';
                $item['source_handoff_status'] = (string)($signal['handoff_status'] ?? 'new');
                $result[$key]                  = $item;
                $newTotal++;
            }
        }

        // Handle queue items no longer present in any enabled handoff
        foreach ($queueMap as $key => $prev) {
            if (isset($result[$key])) {
                continue;
            }
            $prevStatus = (string)($prev['queue_status'] ?? 'queued');
            if (in_array($prevStatus, [
                'expired', 'withdrawn', 'submitted',
                'active_order', 'active_position', 'rejected',
            ], true)) {
                $result[$key] = $prev;
                continue;
            }
            $expiresAt = $prev['expires_at'] ?? '';
            $isExpired = $expiresAt !== ''
                && strtotime($expiresAt) !== false
                && time() > strtotime($expiresAt);
            $prev['queue_status']       = $isExpired ? 'expired' : 'withdrawn';
            $prev['exit_at']            = $tickAt;
            $prev['last_change_reason'] = $isExpired ? 'expired_by_ttl' : 'withdrawn_missing_from_handoff';
            $result[$key]               = $prev;
            if ($isExpired) {
                $expiredTotal++;
            } else {
                $withdrawnTotal++;
            }
        }

        return [
            'order_queue'                    => array_values($result),
            'signals_seen'                   => count($handoffSignals),
            'new_total'                      => $newTotal,
            'refreshed_total'                => $refreshedTotal,
            'expired_total'                  => $expiredTotal,
            'withdrawn_total'                => $withdrawnTotal,
            'ignored_invalid_signal_payload' => $ignoredInvalidPayload,
            'ignored_invalid_entry_mode'     => $ignoredInvalidMode,
        ];
    }

    // =========================================================================
    // Execution state machine
    // =========================================================================

    /**
     * Process execution for the current tick.
     *
     * Modes:
     *   demo     → real execution on Bybit Demo account; submit market orders, sync positions from Bybit Demo.
     *   disabled → skip execution; count skipped ready items as mode_disabled.
     *   passive  → skip execution; count skipped ready items as mode_passive.
     *   paper    → deterministic local execution in storage/state; no real exchange.
     *   smoke    → legacy alias for paper.
     *   active   → legacy alias for paper.
     *
     * Demo tick flow:
     *   queue ready  → check symbol on Bybit Demo (dedup)
     *                → submit market order to Bybit Demo
     *                → sync all Bybit Demo positions into active_positions.json
     *
     * Paper tick flow (all in one pass):
     *   queue ready  → create order (created)
     *                → submit order (submitted_paper)
     *                → fill order   (filled_paper)
     *                → open position (open)
     *
     * Position close conditions checked each tick (paper mode):
     *   expired_by_signal_ttl — position expires_at has passed
     *
     * @return array{
     *   order_queue: array,
     *   active_orders: array,
     *   active_positions: array,
     *   closed_positions: array,
     *   orders_created: int,
     *   orders_submitted_paper: int,
     *   orders_filled_paper: int,
     *   orders_submitted_demo: int,
     *   orders_confirmed_demo: int,
     *   positions_opened: int,
     *   positions_closed: int,
     *   positions_closed_expired: int,
     *   positions_closed_withdrawn: int,
     *   positions_closed_reverse_pattern: int,
     *   queue_items_skipped_mode_disabled: int,
     *   queue_items_skipped_mode_passive: int,
     *   execution_log_events: int,
     * }
     */
    private function processExecution(
        array $orderQueue,
        array $activeOrders,
        array $activePositions,
        array $closedPositions,
        string $mode,
        array $config,
        string $tickAt
    ): array {
        $ordersCreated                  = 0;
        $ordersSubmittedPaper           = 0;
        $ordersFilledPaper              = 0;
        $ordersSubmittedDemo            = 0;
        $ordersConfirmedDemo            = 0;
        $positionsOpened                = 0;
        $positionsClosed                = 0;
        $positionsClosedExpired         = 0;
        $positionsClosedWithdrawn       = 0;
        $positionsClosedReversePattern  = 0;
        $queueSkippedModeDisabled       = 0;
        $queueSkippedModePassive        = 0;
        $logEvents                      = 0;

        // demo mode: real execution on Bybit Demo account
        $isDemoMode     = ($mode === 'demo');
        // paper, smoke, and active all mean local paper execution
        $isPaperMode    = in_array($mode, ['paper', 'smoke', 'active'], true);
        $isPassiveMode  = ($mode === 'passive');
        $isDisabledMode = ($mode === 'disabled');

        // ── Demo mode execution path ──────────────────────────────────────────
        if ($isDemoMode) {
            $demoResult = $this->processDemoExecution(
                $orderQueue, $activeOrders, $activePositions, $closedPositions, $config, $tickAt
            );
            return array_merge($demoResult, [
                'orders_submitted_paper'           => 0,
                'orders_filled_paper'              => 0,
                'positions_closed_expired'         => $demoResult['positions_closed_expired'] ?? 0,
                'positions_closed_withdrawn'       => $demoResult['positions_closed_withdrawn'] ?? 0,
                'positions_closed_reverse_pattern' => 0,
                'queue_items_skipped_mode_disabled'=> 0,
                'queue_items_skipped_mode_passive' => 0,
            ]);
        }

        // Build order lookup by composite key {strategy_id}:{signal_id}
        $orderMap = [];
        foreach ($activeOrders as $order) {
            $k = $this->executionKey($order);
            if ($k !== '') {
                $orderMap[$k] = $order;
            }
        }

        // Build position lookup by composite key
        $positionMap = [];
        foreach ($activePositions as $pos) {
            $k = $this->executionKey($pos);
            if ($k !== '') {
                $positionMap[$k] = $pos;
            }
        }

        // ── Step 1: promote ready queue items ────────────────────────────────
        foreach ($orderQueue as &$qItem) {
            $qStatus = (string)($qItem['queue_status'] ?? '');
            if ($qStatus !== 'ready') {
                continue;
            }

            $key = $this->executionKey($qItem);
            if ($key === '') {
                continue;
            }

            if (!$isPaperMode) {
                // Not in an execution mode — count and label truthfully
                if ($isPassiveMode) {
                    $queueSkippedModePassive++;
                    $qItem['skip_reason'] = 'ignored_mode_passive';
                } else {
                    // disabled or any unknown mode
                    $queueSkippedModeDisabled++;
                    $qItem['skip_reason'] = 'ignored_mode_disabled';
                }
                continue;
            }

            // Already has an order record from a previous tick
            if (isset($orderMap[$key])) {
                continue;
            }

            // Create order record
            $order = $this->buildOrderFromQueueItem($qItem, $tickAt);
            $orderMap[$key] = $order;

            // Mark queue item as submitted (terminal)
            $qItem['queue_status']       = 'submitted';
            $qItem['submitted_at']       = $tickAt;
            $qItem['last_change_reason'] = 'submitted_to_execution';

            $ordersCreated++;
            $this->appendExecutionLog([
                'timestamp'      => $tickAt,
                'event_type'     => 'order_created',
                'strategy_id'    => $order['strategy_id'],
                'owner_strategy' => $order['owner_strategy'],
                'signal_id'      => $order['signal_id'],
                'symbol'         => $order['symbol'],
                'side'           => $order['side'],
                'entry_mode'     => $order['entry_mode'],
                'entry_price'    => $order['entry_price'],
                'execution_mode' => $order['execution_mode'],
                'reason'         => 'created_from_ready_queue',
            ]);
            $logEvents++;
        }
        unset($qItem);

        // ── Step 2: paper submit + fill in one pass ───────────────────────────
        if ($isPaperMode) {
            foreach ($orderMap as $key => &$order) {
                $oStatus = (string)($order['order_status'] ?? '');

                if ($oStatus === 'created') {
                    $order['order_status']      = 'submitted_paper';
                    $order['submitted_at']      = $tickAt;
                    $order['last_updated_at']   = $tickAt;
                    $order['transition_reason'] = 'submitted_in_paper_mode';
                    $oStatus = 'submitted_paper';
                    $ordersSubmittedPaper++;
                }

                if ($oStatus === 'submitted_paper') {
                    $order['order_status']      = 'filled_paper';
                    $order['filled_at']         = $tickAt;
                    $order['last_updated_at']   = $tickAt;
                    $order['transition_reason'] = 'filled_in_paper_mode';
                    $ordersFilledPaper++;

                    // Open position if not already present
                    if (!isset($positionMap[$key])) {
                        // Max active positions guard
                        $maxPos = (int)($config['max_active_positions'] ?? 10);
                        if ($maxPos > 0 && count($positionMap) >= $maxPos) {
                            $this->appendExecutionLog([
                                'timestamp'     => $tickAt,
                                'event_type'    => 'position_skipped',
                                'symbol'        => $order['symbol'] ?? '',
                                'reason'        => 'max_active_positions_reached',
                                'max_positions' => $maxPos,
                            ]);
                            $logEvents++;
                        } else {
                            // Validate critical fields before creating position
                            $epCheck  = (float)($order['entry_price'] ?? 0.0);
                            $budCheck = (float)($order['bot_budget']  ?? 0.0);
                            $levCheck = (int)($order['bot_leverage']  ?? 0);
                            if ($epCheck <= 0.0 || $budCheck <= 0.0 || $levCheck <= 0) {
                                $this->appendExecutionLog([
                                    'timestamp'    => $tickAt,
                                    'event_type'   => 'position_skipped',
                                    'symbol'       => $order['symbol'] ?? '',
                                    'entry_price'  => $epCheck,
                                    'bot_budget'   => $budCheck,
                                    'bot_leverage' => $levCheck,
                                    'reason'       => 'invalid_entry_price_budget_or_leverage',
                                ]);
                                $logEvents++;
                            } else {
                                $position = $this->buildPositionFromOrder($order, $tickAt);
                                $positionMap[$key] = $position;
                                $positionsOpened++;
                                $this->appendExecutionLog([
                                    'timestamp'      => $tickAt,
                                    'event_type'     => 'position_opened',
                                    'strategy_id'    => $position['strategy_id'],
                                    'owner_strategy' => $position['owner_strategy'],
                                    'signal_id'      => $position['signal_id'],
                                    'symbol'         => $position['symbol'],
                                    'side'           => $position['side'],
                                    'entry_mode'     => $position['entry_mode'],
                                    'entry_price'    => $position['entry_price'],
                                    'bot_leverage'   => $position['bot_leverage'],
                                    'bot_budget'     => $position['bot_budget'],
                                    'size'           => $position['size'],
                                    'budget_source'  => $position['budget_source'],
                                    'execution_mode' => $position['execution_mode'],
                                    'reason'         => 'filled_in_paper_mode',
                                ]);
                                $logEvents++;
                            }
                        }
                    }
                }
            }
            unset($order);
        }

        // ── Step 3: check open positions for close conditions ─────────────────
        if ($isPaperMode && !empty($positionMap)) {
            // Collect keys to close (avoid mutating map during iteration)
            $keysToClose = [];
            foreach ($positionMap as $key => $pos) {
                $expiresAt = (string)($pos['expires_at'] ?? '');
                if ($expiresAt !== ''
                    && ($ts = strtotime($expiresAt)) !== false
                    && time() > $ts
                ) {
                    $keysToClose[$key] = 'expired_by_signal_ttl';
                }
            }

            foreach ($keysToClose as $key => $closeReason) {
                $pos                      = $positionMap[$key];
                $pos['position_status']   = 'closed';
                $pos['closed_at']         = $tickAt;
                $pos['close_reason']      = $closeReason;
                $pos['transition_reason'] = $closeReason;
                $pos['last_updated_at']   = $tickAt;
                $closedPositions[]        = $pos;
                $positionsClosed++;

                if ($closeReason === 'expired_by_signal_ttl') {
                    $positionsClosedExpired++;
                } elseif ($closeReason === 'withdrawn_by_strategy') {
                    $positionsClosedWithdrawn++;
                } elseif ($closeReason === 'reverse_pattern_close_requested') {
                    $positionsClosedReversePattern++;
                }

                unset($positionMap[$key]);

                $this->appendExecutionLog([
                    'timestamp'      => $tickAt,
                    'event_type'     => 'position_closed',
                    'strategy_id'    => $pos['strategy_id'],
                    'owner_strategy' => $pos['owner_strategy'],
                    'signal_id'      => $pos['signal_id'],
                    'symbol'         => $pos['symbol'],
                    'side'           => $pos['side'],
                    'entry_mode'     => $pos['entry_mode'],
                    'entry_price'    => $pos['entry_price'],
                    'execution_mode' => $pos['execution_mode'],
                    'reason'         => $closeReason,
                ]);
                $logEvents++;
            }
        }

        return [
            'order_queue'                      => array_values($orderQueue),
            'active_orders'                    => array_values($orderMap),
            'active_positions'                 => array_values($positionMap),
            'closed_positions'                 => $closedPositions,
            'orders_created'                   => $ordersCreated,
            'orders_submitted_paper'           => $ordersSubmittedPaper,
            'orders_filled_paper'              => $ordersFilledPaper,
            'positions_opened'                 => $positionsOpened,
            'positions_closed'                 => $positionsClosed,
            'positions_closed_expired'         => $positionsClosedExpired,
            'positions_closed_withdrawn'       => $positionsClosedWithdrawn,
            'positions_closed_reverse_pattern' => $positionsClosedReversePattern,
            'queue_items_skipped_mode_disabled' => $queueSkippedModeDisabled,
            'queue_items_skipped_mode_passive'  => $queueSkippedModePassive,
            'execution_log_events'             => $logEvents,
            'orders_submitted_demo'            => 0,
            'orders_confirmed_demo'            => 0,
        ];
    }

    /**
     * Build a bot-owned active order record from a ready queue item.
     */
    private function buildOrderFromQueueItem(array $qItem, string $tickAt): array
    {
        return [
            // Ownership — carried forward unchanged from queue item
            'owner_strategy' => (string)($qItem['owner_strategy'] ?? ''),
            'strategy_id'    => (string)($qItem['strategy_id']    ?? ''),
            'signal_id'      => (string)($qItem['signal_id']      ?? ''),

            // Signal geometry
            'symbol'      => (string)($qItem['symbol']      ?? ''),
            'side'        => (string)($qItem['side']        ?? 'long'),
            'timeframe'   => (string)($qItem['timeframe']   ?? 'H4'),
            'entry_mode'  => (string)($qItem['entry_mode']  ?? 'limit'),
            'entry_type'  => (string)($qItem['entry_type']  ?? 'breakout'),
            'entry_price' => (float)($qItem['entry_price']  ?? 0.0),

            // Execution parameters
            'bot_budget'                    => (float)($qItem['bot_budget']                    ?? 0.0),
            'bot_leverage'                  => (int)($qItem['bot_leverage']                    ?? 1),
            'budget_source'                 => (string)($qItem['budget_source']                ?? 'unknown'),
            'leverage_source'               => (string)($qItem['leverage_source']              ?? 'unknown'),
            'stop_mode'                     => (string)($qItem['stop_mode']                    ?? 'fixed_from_liq_zone'),
            'stop_from_liq_buffer_value'    => (float)($qItem['stop_from_liq_buffer_value']   ?? 0.002),
            'stop_from_liq_buffer_type'     => (string)($qItem['stop_from_liq_buffer_type']   ?? 'percent'),
            'tp_enabled'                    => (bool)($qItem['tp_enabled']                    ?? false),
            'tp_mode'                       => (string)($qItem['tp_mode']                     ?? 'fixed_r'),
            'tp_value'                      => (float)($qItem['tp_value']                     ?? 2.0),
            'reverse_pattern_close_enabled' => (bool)($qItem['reverse_pattern_close_enabled'] ?? false),

            // Order lifecycle
            'order_status'      => 'created',
            'execution_mode'    => 'paper',
            'transition_reason' => 'created_from_ready_queue',
            'expires_at'        => (string)($qItem['expires_at'] ?? ''),
            'created_at'        => $tickAt,
            'last_updated_at'   => $tickAt,
        ];
    }

    /**
     * Build a bot-owned active position record from a filled Bybit Demo position response.
     *
     * Called in demo mode after syncing positions from Bybit Demo.
     * Merges queue item context (strategy_id, signal_id, budget, leverage) with
     * live position data (entry_price, mark_price, unrealised_pnl, liq_price).
     *
     * @param array $bybitPos  Single position from Bybit Demo /v5/position/list
     * @param array $qItem     Matching queue item (for strategy context); may be empty
     * @param string $tickAt   Current tick timestamp
     */
    private function buildPositionFromDemoData(array $bybitPos, array $qItem, string $tickAt): array
    {
        $symbol     = (string)($bybitPos['symbol']        ?? $qItem['symbol'] ?? '');
        $bybitSide  = (string)($bybitPos['side']          ?? 'Buy');
        $side       = (strtolower($bybitSide) === 'sell') ? 'short' : 'long';
        $entryPrice = (float)($bybitPos['avgPrice']       ?? $bybitPos['entryPrice'] ?? $qItem['entry_price'] ?? 0.0);
        $markPrice  = (float)($bybitPos['markPrice']      ?? 0.0);
        $leverage   = (int)($bybitPos['leverage']         ?? $qItem['bot_leverage'] ?? 5);
        if ($leverage <= 0) {
            $leverage = 5;
        }
        $budget     = (float)($qItem['bot_budget']        ?? 0.0);
        if ($budget <= 0.0) {
            $budget = 6.0;
        }
        $size       = (float)($bybitPos['size']           ?? 0.0);
        $unrealisedPnl = (float)($bybitPos['unrealisedPnl'] ?? 0.0);
        $liqPrice   = (float)($bybitPos['liqPrice']       ?? 0.0);
        $openedAt   = isset($bybitPos['createdTime'])
            ? date('c', (int)($bybitPos['createdTime'] / 1000))
            : $tickAt;

        return [
            // Ownership — from queue item when available
            'owner_strategy'  => (string)($qItem['owner_strategy'] ?? ''),
            'strategy_id'     => (string)($qItem['strategy_id']    ?? ''),
            'signal_id'       => (string)($qItem['signal_id']      ?? ''),

            // Signal geometry
            'symbol'      => $symbol,
            'side'        => $side,
            'timeframe'   => (string)($qItem['timeframe']  ?? 'H4'),
            'entry_mode'  => (string)($qItem['entry_mode'] ?? 'market'),
            'entry_type'  => (string)($qItem['entry_type'] ?? 'breakout'),
            'entry_price' => $entryPrice,

            // Execution parameters
            'bot_budget'     => $budget,
            'bot_leverage'   => $leverage,
            'budget'         => $budget,
            'leverage'       => (float)$leverage,
            'size'           => $size,
            'budget_source'  => (string)($qItem['budget_source']   ?? 'config'),
            'leverage_source'=> (string)($qItem['leverage_source'] ?? 'config'),

            // Live price / PnL from Bybit Demo
            'mark_price'     => $markPrice > 0.0 ? $markPrice : null,
            'current_price'  => $markPrice > 0.0 ? $markPrice : null,
            'unrealised_pnl' => $unrealisedPnl,
            'liq_price'      => $liqPrice > 0.0 ? $liqPrice : null,

            // Position lifecycle
            'position_status'   => 'open',
            'execution_mode'    => 'demo',
            'account'           => 'bybit_demo',
            'transition_reason' => 'synced_from_bybit_demo',
            'opened_at'         => $openedAt,
            'entered_at'        => $openedAt,
            'last_updated_at'   => $tickAt,
        ];
    }

    /**
     * Fetch symbol instrument info from Bybit Demo (market endpoint, no auth).
     *
     * Returns leverage and lot-size constraints for the given linear symbol.
     * Returns an empty array on error or when symbol is not found.
     *
     * @return array{
     *   bybit_max_leverage: int|null,
     *   min_order_qty: float|null,
     *   qty_step: float|null,
     *   max_order_qty: float|null,
     *   min_notional_value: float|null
     * }
     */
    private function fetchSymbolInstrumentInfo(\Core\Gateway\Bybit $gw, string $symbol): array
    {
        try {
            $resp = $gw->request('/v5/market/instruments-info', [
                'category' => 'linear',
                'symbol'   => $symbol,
            ], false);

            if (!($resp['success'] ?? false) || ($resp['ret_code'] ?? -1) !== 0) {
                return [];
            }

            $list = $resp['result']['list'] ?? [];
            if (!is_array($list) || empty($list)) {
                return [];
            }

            $info           = $list[0];
            $leverageFilter = (array)($info['leverageFilter'] ?? []);
            $lotSizeFilter  = (array)($info['lotSizeFilter']  ?? []);

            $maxLeverage = (float)($leverageFilter['maxLeverage'] ?? 0.0);
            $minOrderQty = (float)($lotSizeFilter['minOrderQty']  ?? 0.0);
            $qtyStep     = (float)($lotSizeFilter['qtyStep']      ?? 0.0);
            $maxOrderQty = (float)($lotSizeFilter['maxOrderQty']  ?? 0.0);
            $minNotional = (float)($info['minNotionalValue']      ?? 0.0);

            return [
                'bybit_max_leverage'  => $maxLeverage > 0.0 ? (int)floor($maxLeverage) : null,
                'min_order_qty'       => $minOrderQty > 0.0 ? $minOrderQty : null,
                'qty_step'            => $qtyStep     > 0.0 ? $qtyStep     : null,
                'max_order_qty'       => $maxOrderQty > 0.0 ? $maxOrderQty : null,
                'min_notional_value'  => $minNotional > 0.0 ? $minNotional : null,
            ];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Set isolated leverage for a symbol on Bybit Demo.
     *
     * Treats retCode 110043 ("leverage not modified") as success.
     * Never logs credentials.
     *
     * @return array{ok: bool, ret_code: int, ret_msg: string}
     */
    private function setDemoLeverage(\Core\Gateway\Bybit $gw, string $symbol, int $leverage): array
    {
        try {
            $resp = $gw->request('/v5/position/set-leverage', [
                'category'     => 'linear',
                'symbol'       => $symbol,
                'buyLeverage'  => (string)$leverage,
                'sellLeverage' => (string)$leverage,
            ], true);

            $retCode = (int)($resp['ret_code'] ?? -1);
            $retMsg  = (string)($resp['ret_msg'] ?? '');

            // 0 = success; 110043 = "Leverage not modified" (already set) → treat as OK
            $ok = ($resp['success'] ?? false) && ($retCode === 0 || $retCode === 110043);

            return ['ok' => $ok, 'ret_code' => $retCode, 'ret_msg' => $retMsg];
        } catch (\Throwable $ex) {
            return ['ok' => false, 'ret_code' => -1, 'ret_msg' => $ex->getMessage()];
        }
    }

    /**
     * Get a Bybit gateway client configured for Bybit Demo account.
     *
     * Uses demo_api_key / demo_api_secret / demo_api_base_url from bot config.
     * Returns null when credentials are not set.
     */
    private function getDemoGateway(array $config): ?\Core\Gateway\Bybit
    {
        $apiKey    = (string)($config['demo_api_key']     ?? '');
        $apiSecret = (string)($config['demo_api_secret']  ?? '');
        $baseUrl   = (string)($config['demo_api_base_url']?? 'https://api-demo.bybit.com');

        if ($apiKey === '' || $apiSecret === '') {
            return null;
        }

        try {
            $gw = \Core\Gateway\Bybit::client('bybit_demo');
            $gw->setCredentials($apiKey, $apiSecret);
            $gw->setBaseUrl($baseUrl);
            return $gw;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Fetch all open linear USDT-settled positions from Bybit Demo.
     *
     * Returns a list of raw Bybit position records (from result.list).
     * Returns an empty array on error.
     */
    private function fetchDemoPositions(\Core\Gateway\Bybit $gw): array
    {
        try {
            $resp = $gw->request('/v5/position/list', [
                'category'   => 'linear',
                'settleCoin' => 'USDT',
                'limit'      => 200,
            ], true);

            if (!($resp['success'] ?? false) || ($resp['ret_code'] ?? -1) !== 0) {
                return [];
            }

            $list = $resp['result']['list'] ?? [];
            if (!is_array($list)) {
                return [];
            }

            // Only include positions with non-zero size
            $open = [];
            foreach ($list as $pos) {
                if (is_array($pos) && (float)($pos['size'] ?? 0) > 0) {
                    $open[] = $pos;
                }
            }
            return $open;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Demo mode execution:
     *   1. Fetch current open positions from Bybit Demo.
     *   2. For each ready queue item, check dedup by symbol, submit market order.
     *   3. Re-sync positions from Bybit Demo after submitting.
     *   4. Build active_positions cache from Bybit Demo data.
     */
    private function processDemoExecution(
        array $orderQueue,
        array $activeOrders,
        array $activePositions,
        array $closedPositions,
        array $config,
        string $tickAt
    ): array {
        $ordersCreated       = 0;
        $ordersSubmittedDemo = 0;
        $ordersConfirmedDemo = 0;
        $positionsOpened     = 0;
        $positionsClosed     = 0;
        $positionsClosedExp  = 0;
        $positionsClosedWith = 0;
        $logEvents           = 0;

        // Demo execution diagnostics
        $demoOrdersPrepared       = 0;
        $demoOrdersRejected       = 0;
        $demoLeverageClampedCount = 0;
        $demoSetLevFailedCount    = 0;
        $demoQtyInvalidCount      = 0;
        $demoLastErrorCode        = null;
        $demoLastErrorMsg         = null;
        $demoLastRejectedSymbol   = null;

        $gw = $this->getDemoGateway($config);

        // No credentials → skip all ready items, keep positions cache unchanged
        if ($gw === null) {
            foreach ($orderQueue as &$qItem) {
                if (($qItem['queue_status'] ?? '') === 'ready') {
                    $qItem['skip_reason'] = 'demo_credentials_missing';
                }
            }
            unset($qItem);

            return [
                'order_queue'        => array_values($orderQueue),
                'active_orders'      => $activeOrders,
                'active_positions'   => $activePositions,
                'closed_positions'   => $closedPositions,
                'orders_created'     => 0,
                'orders_submitted_demo' => 0,
                'orders_confirmed_demo' => 0,
                'positions_opened'   => 0,
                'positions_closed'   => 0,
                'positions_closed_expired'   => 0,
                'positions_closed_withdrawn' => 0,
                'execution_log_events' => 0,
            ];
        }

        // Fetch current positions from Bybit Demo
        $demoPositions = $this->fetchDemoPositions($gw);

        // Build symbol → Bybit position map for dedup
        $symbolMap = [];
        foreach ($demoPositions as $pos) {
            $sym = (string)($pos['symbol'] ?? '');
            if ($sym !== '') {
                $symbolMap[$sym] = $pos;
            }
        }

        // Build queue key → queue item map for signal context lookup
        $queueMap = [];
        foreach ($orderQueue as $q) {
            $k = $this->queueKey($q);
            if ($k !== '') {
                $queueMap[$k] = $q;
            }
        }

        $maxPos = max(0, (int)($config['max_active_positions'] ?? $config['default_max_active_positions'] ?? 10));

        // Process ready queue items
        foreach ($orderQueue as &$qItem) {
            if (($qItem['queue_status'] ?? '') !== 'ready') {
                continue;
            }

            $key    = $this->queueKey($qItem);
            $symbol = (string)($qItem['symbol'] ?? '');
            $side   = (string)($qItem['side']   ?? 'long');

            if ($symbol === '') {
                continue;
            }

            // Skip if symbol already open on Bybit Demo
            if (isset($symbolMap[$symbol])) {
                $qItem['skip_reason'] = 'symbol_already_active_on_demo';
                continue;
            }

            // Skip if max positions reached
            if ($maxPos > 0 && count($symbolMap) >= $maxPos) {
                $qItem['skip_reason'] = 'max_active_positions_reached';
                continue;
            }

            $entryPrice        = (float)($qItem['entry_price'] ?? 0.0);
            $budget            = (float)($qItem['bot_budget']  ?? 6.0);
            $requestedLeverage = max(1, (int)($qItem['bot_leverage'] ?? 5));

            if ($entryPrice <= 0.0 || $budget <= 0.0) {
                $qItem['skip_reason'] = 'invalid_entry_price_or_budget';
                $demoOrdersRejected++;
                continue;
            }

            $demoOrdersPrepared++;

            // ── Step 1: Fetch symbol instrument info ──────────────────────────
            $symbolInfo  = $this->fetchSymbolInstrumentInfo($gw, $symbol);
            $bybitMaxLev = $symbolInfo['bybit_max_leverage'] ?? null;
            $minOrderQty = $symbolInfo['min_order_qty']      ?? null;
            $qtyStep     = $symbolInfo['qty_step']           ?? null;
            $maxOrderQty = $symbolInfo['max_order_qty']      ?? null;
            $minNotional = $symbolInfo['min_notional_value'] ?? null;

            // ── Step 2: Clamp leverage ────────────────────────────────────────
            $effectiveLeverage  = $requestedLeverage;
            $leverageWasClamped = false;

            if ($bybitMaxLev !== null && $bybitMaxLev > 0 && $requestedLeverage > $bybitMaxLev) {
                $effectiveLeverage  = $bybitMaxLev;
                $leverageWasClamped = true;
                $demoLeverageClampedCount++;
            } elseif ($bybitMaxLev === null) {
                $this->appendExecutionLog([
                    'timestamp'          => $tickAt,
                    'event_type'         => 'leverage_limit_unknown',
                    'symbol'             => $symbol,
                    'requested_leverage' => $requestedLeverage,
                    'reason'             => 'bybit_max_leverage_unknown',
                ]);
                $logEvents++;
            }

            // Store leverage diagnostics in queue item
            $qItem['requested_leverage']  = $requestedLeverage;
            $qItem['bybit_max_leverage']  = $bybitMaxLev;
            $qItem['effective_leverage']  = $effectiveLeverage;
            $qItem['leverage_was_clamped']= $leverageWasClamped;
            $qItem['qty_step']            = $qtyStep;
            $qItem['min_order_qty']       = $minOrderQty;
            $qItem['min_notional_value']  = $minNotional;

            // ── Step 3: Set leverage on Bybit Demo ────────────────────────────
            $levResult = $this->setDemoLeverage($gw, $symbol, $effectiveLeverage);
            $qItem['set_leverage_attempted'] = true;
            $qItem['set_leverage_ok']        = $levResult['ok'];
            $qItem['set_leverage_ret_code']  = $levResult['ret_code'];
            $qItem['set_leverage_ret_msg']   = $levResult['ret_msg'];

            if (!$levResult['ok']) {
                $qItem['skip_reason']     = 'set_leverage_failed';
                $qItem['demo_error_code'] = $levResult['ret_code'];
                $qItem['demo_error_msg']  = $levResult['ret_msg'];
                $demoSetLevFailedCount++;
                $demoOrdersRejected++;
                $demoLastErrorCode      = $levResult['ret_code'];
                $demoLastErrorMsg       = $levResult['ret_msg'];
                $demoLastRejectedSymbol = $symbol;
                continue;
            }

            // ── Step 4: Calculate qty using effective leverage ────────────────
            $rawQty = ($budget * $effectiveLeverage) / $entryPrice;
            $qItem['raw_qty'] = $rawQty;

            // ── Step 5: Normalize qty ─────────────────────────────────────────
            $normalizedQty = $rawQty;
            if ($qtyStep !== null && $qtyStep > 0.0) {
                // Determine decimal places from qtyStep (e.g. 0.001 → 3 places)
                $decPlaces     = max(0, (int)ceil(-log10($qtyStep)));
                $normalizedQty = floor($rawQty / $qtyStep) * $qtyStep;
                $normalizedQty = round($normalizedQty, $decPlaces);
            }
            $qItem['normalized_qty'] = $normalizedQty;

            // Validate normalized qty
            if ($normalizedQty <= 0.0) {
                $qItem['skip_reason'] = 'qty_invalid_after_normalization';
                $demoQtyInvalidCount++;
                $demoOrdersRejected++;
                $demoLastRejectedSymbol = $symbol;
                continue;
            }
            if ($minOrderQty !== null && $normalizedQty < $minOrderQty) {
                $qItem['skip_reason'] = 'qty_below_min_order_qty';
                $demoQtyInvalidCount++;
                $demoOrdersRejected++;
                $demoLastRejectedSymbol = $symbol;
                continue;
            }
            if ($maxOrderQty !== null && $maxOrderQty > 0.0 && $normalizedQty > $maxOrderQty) {
                $qItem['skip_reason'] = 'qty_above_max_order_qty';
                $demoQtyInvalidCount++;
                $demoOrdersRejected++;
                $demoLastRejectedSymbol = $symbol;
                continue;
            }
            if ($minNotional !== null && $minNotional > 0.0
                && ($normalizedQty * $entryPrice) < $minNotional
            ) {
                $qItem['skip_reason'] = 'qty_below_min_notional';
                $demoQtyInvalidCount++;
                $demoOrdersRejected++;
                $demoLastRejectedSymbol = $symbol;
                continue;
            }

            // Format qty string without trailing zeros
            $qtyStr    = rtrim(rtrim(number_format($normalizedQty, 8, '.', ''), '0'), '.');
            $bybitSide = ($side === 'short') ? 'Sell' : 'Buy';

            try {
                $orderResp = $gw->request('/v5/order/create', [
                    'category'    => 'linear',
                    'symbol'      => $symbol,
                    'side'        => $bybitSide,
                    'orderType'   => 'Market',
                    'qty'         => $qtyStr,
                    'timeInForce' => 'IOC',
                    'positionIdx' => 0,
                ], true);
            } catch (\Throwable) {
                $qItem['skip_reason']     = 'demo_submit_exception';
                $demoOrdersRejected++;
                $demoLastRejectedSymbol   = $symbol;
                continue;
            }

            $ordersCreated++;

            if (($orderResp['success'] ?? false) && ($orderResp['ret_code'] ?? -1) === 0) {
                $ordersSubmittedDemo++;
                $qItem['queue_status']       = 'submitted';
                $qItem['submitted_at']       = $tickAt;
                $qItem['last_change_reason'] = 'submitted_to_demo';
                $qItem['demo_order_id']      = $orderResp['result']['orderId'] ?? null;

                // Optimistically add to symbolMap so subsequent items see correct count
                $symbolMap[$symbol] = ['symbol' => $symbol, 'size' => $normalizedQty, '_pending' => true];
                $positionsOpened++;

                $this->appendExecutionLog([
                    'timestamp'            => $tickAt,
                    'event_type'           => 'demo_order_submitted',
                    'strategy_id'          => $qItem['strategy_id'] ?? '',
                    'owner_strategy'       => $qItem['owner_strategy'] ?? '',
                    'signal_id'            => $qItem['signal_id'] ?? '',
                    'symbol'               => $symbol,
                    'side'                 => $side,
                    'entry_price'          => $entryPrice,
                    'budget'               => $budget,
                    'requested_leverage'   => $requestedLeverage,
                    'effective_leverage'   => $effectiveLeverage,
                    'leverage_was_clamped' => $leverageWasClamped,
                    'raw_qty'              => $rawQty,
                    'normalized_qty'       => $normalizedQty,
                    'qty_step'             => $qtyStep,
                    'min_order_qty'        => $minOrderQty,
                    'min_notional_value'   => $minNotional,
                    'order_type'           => 'Market',
                    'execution_mode'       => 'demo',
                    'bybit_side'           => $bybitSide,
                    'demo_order_id'        => $qItem['demo_order_id'],
                    'reason'               => 'submitted_market_order_to_bybit_demo',
                ]);
                $logEvents++;
            } else {
                $retCode = $orderResp['ret_code'] ?? null;
                $retMsg  = $orderResp['ret_msg']  ?? null;
                $qItem['skip_reason']     = 'demo_order_rejected';
                $qItem['demo_error_code'] = $retCode;
                $qItem['demo_error_msg']  = $retMsg;
                $demoOrdersRejected++;
                $demoLastErrorCode      = $retCode;
                $demoLastErrorMsg       = $retMsg;
                $demoLastRejectedSymbol = $symbol;
            }
        }
        unset($qItem);

        // Re-sync positions from Bybit Demo after submitting orders
        $freshDemoPositions = $this->fetchDemoPositions($gw);

        // Build a queue-item context map: symbol → first matching queue item
        $symbolQueueContext = [];
        foreach ($orderQueue as $q) {
            $sym = (string)($q['symbol'] ?? '');
            if ($sym !== '' && !isset($symbolQueueContext[$sym])) {
                $symbolQueueContext[$sym] = $q;
            }
        }

        // Build new active_positions from Bybit Demo data
        $newActivePositions = [];
        foreach ($freshDemoPositions as $pos) {
            $sym     = (string)($pos['symbol'] ?? '');
            $qCtx    = $symbolQueueContext[$sym] ?? [];
            $record  = $this->buildPositionFromDemoData($pos, $qCtx, $tickAt);
            $newActivePositions[] = $record;
            $ordersConfirmedDemo++;
        }

        // Detect positions that were in cache but are no longer open on Bybit Demo
        $freshSymbols = array_flip(array_map(
            fn($p) => (string)($p['symbol'] ?? ''),
            $freshDemoPositions
        ));
        foreach ($activePositions as $pos) {
            $sym = (string)($pos['symbol'] ?? '');
            if ($sym !== '' && !isset($freshSymbols[$sym]) && ($pos['execution_mode'] ?? '') === 'demo') {
                // Position gone from Bybit Demo — move to closed
                $pos['position_status']   = 'closed';
                $pos['closed_at']         = $tickAt;
                $pos['close_reason']      = 'position_gone_from_bybit_demo';
                $pos['last_updated_at']   = $tickAt;
                $closedPositions[]        = $pos;
                $positionsClosed++;
            }
        }

        return [
            'order_queue'                      => array_values($orderQueue),
            'active_orders'                    => $activeOrders,
            'active_positions'                 => array_values($newActivePositions),
            'closed_positions'                 => $closedPositions,
            'orders_created'                   => $ordersCreated,
            'orders_submitted_demo'            => $ordersSubmittedDemo,
            'orders_confirmed_demo'            => $ordersConfirmedDemo,
            'positions_opened'                 => $positionsOpened,
            'positions_closed'                 => $positionsClosed,
            'positions_closed_expired'         => $positionsClosedExp,
            'positions_closed_withdrawn'       => $positionsClosedWith,
            'execution_log_events'             => $logEvents,
            // Demo execution diagnostics
            'demo_orders_prepared'             => $demoOrdersPrepared,
            'demo_orders_rejected'             => $demoOrdersRejected,
            'demo_leverage_clamped_count'      => $demoLeverageClampedCount,
            'demo_set_leverage_failed_count'   => $demoSetLevFailedCount,
            'demo_qty_invalid_count'           => $demoQtyInvalidCount,
            'demo_last_error_code'             => $demoLastErrorCode,
            'demo_last_error_msg'              => $demoLastErrorMsg,
            'demo_last_rejected_symbol'        => $demoLastRejectedSymbol,
        ];
    }

    /**
     * Build a bot-owned active position record from a filled paper order.
     *
     * Liquidation context for stop_manager:
     *   liq_price           — null in paper mode (no real exchange liq available)
     *   estimated_liq_price — isolated-margin local estimate; positive float or null
     *                         long:  entry × (1 − 1 / leverage)
     *                         short: entry × (1 + 1 / leverage)
     *                         null when entry_price ≤ 0, leverage ≤ 1, or result ≤ 0
     *
     * stop_manager reads estimated_liq_price as liq_source = estimated.
     * Only an exchange-provided liq_price > 0 may be treated as real.
     */
    private function buildPositionFromOrder(array $order, string $tickAt): array
    {
        $entryPrice = (float)($order['entry_price'] ?? 0.0);
        $leverage   = max(1, (int)($order['bot_leverage'] ?? 5));
        $budget     = (float)($order['bot_budget'] ?? 0.0);
        $side       = (string)($order['side'] ?? 'long');

        // size = (budget × leverage) / entry_price
        $size = 0.0;
        if ($entryPrice > 0.0 && $budget > 0.0 && $leverage > 0) {
            $size = round(($budget * $leverage) / $entryPrice, 6);
        }

        $estimatedLiqPrice = $this->computeEstimatedLiqPrice($entryPrice, $leverage, $side);

        return [
            // Ownership — carried forward unchanged
            'owner_strategy' => (string)($order['owner_strategy'] ?? ''),
            'strategy_id'    => (string)($order['strategy_id']    ?? ''),
            'signal_id'      => (string)($order['signal_id']      ?? ''),

            // Signal geometry
            'symbol'      => (string)($order['symbol']      ?? ''),
            'side'        => $side,
            'timeframe'   => (string)($order['timeframe']   ?? 'H4'),
            'entry_mode'  => (string)($order['entry_mode']  ?? 'limit'),
            'entry_type'  => (string)($order['entry_type']  ?? 'breakout'),
            'entry_price' => $entryPrice,

            // Execution parameters
            'bot_budget'                    => $budget,
            'bot_leverage'                  => $leverage,
            // Canonical PM fields (mirrors bot_budget / bot_leverage for live-like paper format)
            'budget'                        => $budget,
            'leverage'                      => (float)$leverage,
            'size'                          => $size,
            'budget_source'                 => (string)($order['budget_source']   ?? 'unknown'),
            'leverage_source'               => (string)($order['leverage_source'] ?? 'unknown'),
            'stop_mode'                     => (string)($order['stop_mode']                    ?? 'fixed_from_liq_zone'),
            'stop_from_liq_buffer_value'    => (float)($order['stop_from_liq_buffer_value']   ?? 0.002),
            'stop_from_liq_buffer_type'     => (string)($order['stop_from_liq_buffer_type']   ?? 'percent'),
            'tp_enabled'                    => (bool)($order['tp_enabled']                    ?? false),
            'tp_mode'                       => (string)($order['tp_mode']                     ?? 'fixed_r'),
            'tp_value'                      => (float)($order['tp_value']                     ?? 2.0),
            'reverse_pattern_close_enabled' => (bool)($order['reverse_pattern_close_enabled'] ?? false),

            // Liquidation context (paper-local; no real exchange liq in paper mode)
            'liq_price'           => null,
            'estimated_liq_price' => $estimatedLiqPrice,

            // Position lifecycle
            'position_status'   => 'open',
            'execution_mode'    => 'paper',
            'transition_reason' => 'filled_in_paper_mode',
            'expires_at'        => (string)($order['expires_at'] ?? ''),
            'opened_at'         => $tickAt,
            'entered_at'        => $tickAt,
            'last_updated_at'   => $tickAt,
        ];
    }

    /**
     * Compute isolated-margin estimated liquidation price for paper positions.
     *
     * Long:  liq_estimate = entry × (1 − 1 / leverage)
     * Short: liq_estimate = entry × (1 + 1 / leverage)
     *
     * Returns null when result would be ≤ 0 (e.g. leverage = 1 long) or inputs are unusable.
     */
    private function computeEstimatedLiqPrice(float $entryPrice, int $leverage, string $side): ?float
    {
        if ($entryPrice <= 0.0 || $leverage <= 0) {
            return null;
        }

        $estimate = $side === 'long'
            ? $entryPrice * (1.0 - 1.0 / $leverage)
            : $entryPrice * (1.0 + 1.0 / $leverage);

        return $estimate > 0.0 ? $estimate : null;
    }

    /**
     * Build a composite execution key: strategy_id:signal_id
     * Used for deduplication across active_orders and active_positions.
     */
    private function executionKey(array $item): string
    {
        $signalId = (string)($item['signal_id'] ?? '');
        if ($signalId === '') {
            return '';
        }
        $stratId = (string)($item['strategy_id'] ?? $item['owner_strategy'] ?? 'unknown');
        return $stratId . ':' . $signalId;
    }

    /**
     *
     * Operator overrides (per-strategy) are applied here:
     *   - bot_budget   (> 0 overrides signal value)
     *   - bot_leverage (> 0 overrides signal value)
     *   - entry_mode   (applied by processHandoff before this call)
     *
     * Deep strategy internals (pattern thresholds, TTL, corridor config, etc.)
     * are NOT exposed here — they stay inside each strategy module.
     */
    private function buildQueueItem(array $signal, array $opOverrides, array $config, string $tickAt): array
    {
        // Resolve budget: signal → operator override → config → hard fallback
        $botBudget      = (float)($signal['bot_budget']  ?? 0.0);
        $botLeverage    = (int)($signal['bot_leverage']  ?? 0);
        $budgetSource   = ($botBudget  > 0.0) ? 'signal' : '';
        $leverageSource = ($botLeverage > 0)  ? 'signal' : '';

        $opBudget   = (float)($opOverrides['bot_budget']   ?? 0.0);
        $opLeverage = (int)($opOverrides['bot_leverage']   ?? 0);
        if ($opBudget > 0.0) {
            $botBudget    = $opBudget;
            $budgetSource = 'operator';
        }
        if ($opLeverage > 0) {
            $botLeverage    = $opLeverage;
            $leverageSource = 'operator';
        }

        if ($botBudget <= 0.0) {
            $cfgBudget = (float)($config['budget_per_trade'] ?? 0.0);
            if ($cfgBudget <= 0.0) {
                $cfgBudget = (float)($config['max_bot_budget'] ?? 0.0);
            }
            if ($cfgBudget > 0.0) {
                $botBudget    = $cfgBudget;
                $budgetSource = 'config';
            } else {
                $botBudget    = 6.0;
                $budgetSource = 'default';
            }
        }
        if ($botLeverage <= 0) {
            $cfgLeverage = (int)($config['leverage'] ?? 0);
            if ($cfgLeverage <= 0) {
                $cfgLeverage = (int)($config['max_bot_leverage'] ?? 0);
            }
            if ($cfgLeverage > 0) {
                $botLeverage    = $cfgLeverage;
                $leverageSource = 'config';
            } else {
                $botLeverage    = 5;
                $leverageSource = 'default';
            }
        }

        return [
            // Strategy ownership — read from handoff contract, never overridden by bot
            'owner_strategy' => (string)($signal['owner_strategy'] ?? ''),
            'strategy_id'    => (string)($signal['strategy_id']    ?? ''),
            'signal_id'      => (string)($signal['signal_id']      ?? ''),

            // Signal identity
            'symbol'    => (string)($signal['symbol']    ?? ''),
            'side'      => (string)($signal['side']      ?? 'long'),
            'timeframe' => (string)($signal['timeframe'] ?? 'H4'),

            // Entry geometry (entry_mode may be overridden by processHandoff)
            'entry_mode'  => (string)($signal['entry_mode']  ?? 'limit'),
            'entry_type'  => (string)($signal['entry_type']  ?? 'breakout'),
            'entry_price' => (float)($signal['entry_price']  ?? 0.0),

            // Freshness
            'detected_at' => (string)($signal['detected_at'] ?? $tickAt),
            'expires_at'  => (string)($signal['expires_at']  ?? ''),

            // Execution parameters — strategy defaults, possibly overridden by operator
            'stop_mode'                     => (string)($signal['stop_mode']                    ?? 'fixed_from_liq_zone'),
            'stop_from_liq_buffer_value'    => (float)($signal['stop_from_liq_buffer_value']   ?? 0.002),
            'stop_from_liq_buffer_type'     => (string)($signal['stop_from_liq_buffer_type']   ?? 'percent'),
            'bot_budget'                    => $botBudget,
            'bot_leverage'                  => $botLeverage,
            'budget_source'                 => $budgetSource,
            'leverage_source'               => $leverageSource,
            'tp_enabled'                    => (bool)($signal['tp_enabled']                    ?? false),
            'tp_mode'                       => (string)($signal['tp_mode']                     ?? 'fixed_r'),
            'tp_value'                      => (float)($signal['tp_value']                     ?? 2.0),
            'reverse_pattern_close_enabled' => (bool)($signal['reverse_pattern_close_enabled'] ?? false),

            // Bot lifecycle state (overwritten by caller)
            'queue_status' => 'queued',
        ];
    }

    // =========================================================================
    // Storage initialization
    // =========================================================================

    /**
     * Ensure all 7 bot storage files exist with truthful empty-state values.
     * Called at the start of every tick regardless of enabled/disabled state.
     * Never overwrites a file that already contains real data.
     */
    private function initializeStorage(): void
    {
        $storageDir = $this->moduleDir . '/storage';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }

        // Each entry: [relative path, default value (written only if file missing)]
        $defaults = [
            'storage/strategy_registry.json'  => [],
            'storage/operator_overrides.json' => (object)[],
            'storage/order_queue.json'        => [],
            'storage/active_orders.json'      => [],
            'storage/active_positions.json'   => [],
            'storage/closed_positions.json'   => [],
            'storage/last_run.json'           => [
                'status'                                    => 'never_run',
                'tick_at'                                   => null,
                'elapsed_sec'                               => 0,
                'bot_enabled'                               => false,
                'bot_mode'                                  => 'passive',
                'strategies_discovered_total'               => 0,
                'strategies_enabled_total'                  => 0,
                'strategies_disabled_total'                 => 0,
                'handoff_sources_active_total'              => 0,
                'handoff_signals_processed'                 => 0,
                'handoff_signals_ignored_disabled_strategy' => 0,
                'order_queue_new_total'                     => 0,
                'order_queue_refreshed_total'               => 0,
                'order_queue_expired_total'                 => 0,
                'order_queue_withdrawn_total'               => 0,
                'execution_mode'                            => 'passive',
                'orders_created'                            => 0,
                'orders_submitted_paper'                    => 0,
                'orders_filled_paper'                       => 0,
                'orders_submitted_demo'                     => 0,
                'orders_confirmed_demo'                     => 0,
                'positions_opened'                          => 0,
                'positions_closed'                          => 0,
                'positions_closed_expired'                  => 0,
                'positions_closed_withdrawn'                => 0,
                'positions_closed_reverse_pattern'          => 0,
                'queue_items_skipped_mode_disabled'         => 0,
                'queue_items_skipped_mode_passive'          => 0,
                'execution_log_events'                      => 0,
                'order_queue_total'                         => 0,
                'active_orders_count'                       => 0,
                'active_positions_count'                    => 0,
                'ticks_total'                               => 0,
                'handoff_signals_seen_total'                => 0,
            ],
            'storage/stats.json' => $this->zeroStats(),
        ];

        foreach ($defaults as $relPath => $default) {
            $absPath = $this->moduleDir . '/' . $relPath;
            if (!file_exists($absPath)) {
                $this->writeJson($relPath, $default);
            }
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Composite deduplication key: strategy_id:signal_id
     * Safe across multiple strategy sources.
     */
    private function queueKey(array $item): string
    {
        $signalId = (string)($item['signal_id'] ?? '');
        if ($signalId === '') {
            return '';
        }
        $stratId = (string)($item['strategy_id'] ?? $item['owner_strategy'] ?? 'unknown');
        return $stratId . ':' . $signalId;
    }

    private function countByStatus(array $queue, array $statuses): int
    {
        $count = 0;
        foreach ($queue as $item) {
            if (in_array($item['queue_status'] ?? '', $statuses, true)) {
                $count++;
            }
        }
        return $count;
    }

    private function zeroStats(): array
    {
        return [
            'ticks_total'                 => 0,
            // Discovery (current-state)
            'strategies_discovered_total' => 0,
            'strategies_enabled_total'    => 0,
            'strategies_disabled_total'   => 0,
            'handoff_sources_active_total'=> 0,
            // Signals (cumulative)
            'handoff_signals_seen_total'  => 0,
            'handoff_signals_ignored_disabled_strategy_total' => 0,
            // Queue (cumulative)
            'order_queue_total'           => 0,
            'order_queue_new_total'       => 0,
            'order_queue_refreshed_total' => 0,
            'order_queue_expired_total'   => 0,
            'order_queue_withdrawn_total' => 0,
            // Execution (cumulative)
            'orders_created_total'                    => 0,
            'orders_submitted_paper_total'            => 0,
            'orders_filled_paper_total'               => 0,
            'orders_submitted_demo_total'             => 0,
            'orders_confirmed_demo_total'             => 0,
            'positions_opened_total'                  => 0,
            'positions_closed_total'                  => 0,
            'positions_closed_expired_total'          => 0,
            'positions_closed_withdrawn_total'        => 0,
            'positions_closed_reverse_pattern_total'  => 0,
            'queue_items_skipped_mode_disabled_total' => 0,
            'queue_items_skipped_mode_passive_total'  => 0,
            'execution_log_events_total'              => 0,
            // Live counts
            'active_orders_total'         => 0,
            'active_positions_total'      => 0,
        ];
    }

    private function writeRuntimeSnapshot(
        array $config,
        array $lastRun,
        int $discoveredTotal,
        int $enabledTotal
    ): void {
        $snap = [
            'snapshot_at'      => date('c'),
            'bot_id'           => 'bot',
            'mode'             => $config['mode']    ?? 'passive',
            'enabled'          => $config['enabled'] ?? false,
            'tick_at'          => $lastRun['tick_at']    ?? null,
            'last_tick_result' => $lastRun['status']     ?? 'ok',

            // Discovery
            'strategies_discovered_total'  => $discoveredTotal,
            'strategies_enabled_total'     => $enabledTotal,
            'strategies_disabled_total'    => $discoveredTotal - $enabledTotal,
            'handoff_sources_active_total' => $lastRun['handoff_sources_active_total'] ?? 0,

            // Signals this tick
            'handoff_signals_processed' => $lastRun['handoff_signals_processed'] ?? 0,

            // Entry
            'allowed_entry_modes' => $config['allowed_entry_modes'] ?? ['limit', 'market'],

            // Execution caps
            'max_bot_budget'   => $config['max_bot_budget']   ?? 0.0,
            'max_bot_leverage' => $config['max_bot_leverage'] ?? 0,

            // Runtime truth
            'order_queue_total'      => $lastRun['order_queue_total']      ?? 0,
            'active_orders_count'    => $lastRun['active_orders_count']    ?? 0,
            'active_positions_count' => $lastRun['active_positions_count'] ?? 0,

            // Brain-compatible keys
            'config_valid'     => true,
            'effective_config' => [
                'mode'    => $config['mode']    ?? 'passive',
                'enabled' => $config['enabled'] ?? false,
            ],
        ];

        $path  = $this->moduleDir . '/config/runtime_snapshot.php';
        $lines = [
            "<?php\n\ndeclare(strict_types=1);\n\n",
            "/**\n * Bot Module — Runtime Snapshot\n",
            " * Auto-written after each tick. Do not edit manually.\n",
            " * snapshot_at: " . $snap['snapshot_at'] . "\n */\n\n",
            "return " . var_export($snap, true) . ";\n",
        ];
        @file_put_contents($path, implode('', $lines));
    }

    private function appendExecutionLog(array $event): void
    {
        $path = $this->moduleDir . '/storage/execution_log.ndjson';
        $line = json_encode($event, JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    private function requireBootstrap(): void
    {
        require_once $this->moduleDir . '/bootstrap.php';
    }

    private function readJson(string $relPath, mixed $default = null): mixed
    {
        $path = $this->moduleDir . '/' . $relPath;
        if (!file_exists($path)) {
            return $default;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return $default;
        }
        $decoded = json_decode($raw, true);
        return ($decoded !== null) ? $decoded : $default;
    }

    private function writeJson(string $relPath, mixed $data): void
    {
        $path = $this->moduleDir . '/' . $relPath;
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
