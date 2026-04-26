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

    /**
     * Test live connection via KeyCenter.
     *
     * Uses account_id from config (single source of truth).
     * Makes a lightweight signed API call to verify credentials.
     * Never uses demo credentials.
     */
    public function checkLiveConnection(): array
    {
        $config    = $this->getConfig();
        $accountId = trim((string)($config['account_id'] ?? ''));

        if ($accountId === '') {
            return [
                'connected'   => false,
                'mode'        => 'live',
                'account'     => '',
                'error'       => 'account_id_missing',
                'skip_reason' => 'live_not_ready',
            ];
        }

        try {
            $gw   = \Core\Gateway\Bybit::client($accountId);
            $resp = $gw->request('/v5/account/wallet-balance', [
                'accountType' => 'UNIFIED',
            ], true);

            $retCode = $resp['ret_code'] ?? -1;
            $success = ($resp['success'] ?? false) && $retCode === 0;

            if ($success) {
                return [
                    'connected'   => true,
                    'mode'        => 'live',
                    'account'     => $accountId,
                    'error'       => null,
                    'skip_reason' => null,
                ];
            }

            $errMsg     = (string)($resp['ret_msg'] ?? ($resp['error_type'] ?? 'api_error'));
            $skipReason = in_array($retCode, [10003, 10004, -1], true)
                ? 'live_keycenter_credentials_missing'
                : 'live_not_ready';

            return [
                'connected'   => false,
                'mode'        => 'live',
                'account'     => $accountId,
                'error'       => $errMsg,
                'skip_reason' => $skipReason,
            ];
        } catch (\Throwable $ex) {
            return [
                'connected'   => false,
                'mode'        => 'live',
                'account'     => $accountId,
                'error'       => $ex->getMessage(),
                'skip_reason' => 'live_not_ready',
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
        $botMode            = (string)($config['mode'] ?? 'demo');
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

        // ── Live credentials diagnostics ─────────────────────────────────────
        $liveAccountId       = trim((string)($config['account_id'] ?? ''));
        $liveEnabled         = (bool)($config['live_enabled'] ?? false);
        $liveConnected       = false;
        $liveConnError       = null;
        $liveCredsMissing    = false;

        if ($botMode === 'live') {
            $liveConnResult  = $this->checkLiveConnection();
            $liveConnected   = (bool)($liveConnResult['connected'] ?? false);
            $liveConnError   = $liveConnResult['error'] ?? null;
            $liveCredsMissing = ($liveConnResult['skip_reason'] ?? '') === 'live_keycenter_credentials_missing';
        }

        $account = match ($botMode) {
            'demo'  => 'bybit_demo',
            'live'  => $liveAccountId !== '' ? $liveAccountId : 'live',
            default => 'local',
        };

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
                    'live_account_id'              => $liveAccountId,
                    'live_enabled'                 => $liveEnabled,
                    'live_connected'               => $liveConnected,
                    'live_connection_error'        => $liveConnError,
                    'live_credentials_missing'     => $liveCredsMissing,
                    'strategies_discovered_total'  => count($registry),
                    'strategies_enabled_total'     => count($enabledStrategies),
                    'strategies_disabled_total'    => count($disabledStrategies),
                    'handoff_sources_active_total' => 0,
                    'handoff_signals_processed'    => 0,
                    'order_queue_total'            => $this->countByStatus($this->readJson('storage/order_queue.json', []), ['queued', 'ready']),
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
        $result     = $this->processHandoff($allSignals, $orderQueue, $config, $overrides, $tickAt, $botMode);
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

        // ── 7b. Update position runtime age tracker ───────────────────────────
        $this->updatePositionRuntimeAge($activePositions, $tickAt, $config);

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
            'live_account_id'             => $liveAccountId,
            'live_enabled'                => $liveEnabled,
            'live_connected'              => $liveConnected,
            'live_connection_error'       => $liveConnError,
            'live_credentials_missing'    => $liveCredsMissing,

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
            // Per-tick leverage diagnostics
            'demo_last_req_leverage'         => $execResult['demo_last_req_leverage']         ?? null,
            'demo_last_eff_leverage'         => $execResult['demo_last_eff_leverage']         ?? null,
            'demo_last_leverage_source'      => $execResult['demo_last_leverage_source']      ?? null,
            'demo_last_budget_source'        => $execResult['demo_last_budget_source']        ?? null,
            'demo_last_set_lev_note'         => $execResult['demo_last_set_lev_note']         ?? null,
            'demo_last_set_lev_code'         => $execResult['demo_last_set_lev_code']         ?? null,
            'demo_last_set_lev_msg'          => $execResult['demo_last_set_lev_msg']          ?? null,
            'demo_leverage_mismatch_count'   => $execResult['demo_leverage_mismatch_count']   ?? 0,

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
        string $tickAt,
        string $botMode = 'demo'
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
                // backward-compat: items created before execution_mode field assume current mode
                $prevMode   = (string)($prev['execution_mode'] ?? $botMode);

                // If an existing submitted item belongs to a different mode, do not treat it
                // as a blocking duplicate — create a fresh queue item for the current mode.
                $isModeSwitchedTerminal = ($prevStatus === 'submitted' && $prevMode !== $botMode);

                if (in_array($prevStatus, ['queued', 'ready'], true) || $isModeSwitchedTerminal) {
                    $item = $this->buildQueueItem($signal, $opOverrides, $config, $tickAt, $botMode);
                    $item['entry_mode'] = $entryMode;

                    if ($isModeSwitchedTerminal) {
                        // Fresh item for the new mode; start back at queued
                        $item['queue_status']          = 'queued';
                        $item['first_queued_at']       = $tickAt;
                        $item['seen_count']            = 1;
                        $item['last_refreshed_at']     = $tickAt;
                        $item['last_change_reason']    = 'new_from_handoff_after_mode_switch';
                        $item['source_handoff_status'] = (string)($signal['handoff_status'] ?? 'new');
                        $result[$key]                  = $item;
                        $newTotal++;
                    } else {
                        $item['queue_status']          = 'ready';
                        $item['first_queued_at']       = $prev['first_queued_at'] ?? $tickAt;
                        $item['seen_count']            = (int)($prev['seen_count'] ?? 1) + 1;
                        $item['last_refreshed_at']     = $tickAt;
                        $item['last_change_reason']    = 'refreshed_from_handoff';
                        $item['source_handoff_status'] = (string)($signal['handoff_status'] ?? 'refreshed');
                        $result[$key]                  = $item;
                        $refreshedTotal++;
                    }
                } else {
                    $result[$key] = $prev;
                }
            } else {
                $item = $this->buildQueueItem($signal, $opOverrides, $config, $tickAt, $botMode);
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
        $logEvents                      = 0;

        // demo mode: real execution on Bybit Demo account
        $isDemoMode = ($mode === 'demo');
        // live mode: real execution on Bybit Live account via KeyCenter
        $isLiveMode = ($mode === 'live');

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

        // ── Live mode execution path ──────────────────────────────────────────
        if ($isLiveMode) {
            $liveResult = $this->processLiveExecution(
                $orderQueue, $activeOrders, $activePositions, $closedPositions, $config, $tickAt
            );
            return array_merge($liveResult, [
                'orders_submitted_paper'           => 0,
                'orders_filled_paper'              => 0,
                'positions_closed_expired'         => $liveResult['positions_closed_expired'] ?? 0,
                'positions_closed_withdrawn'       => $liveResult['positions_closed_withdrawn'] ?? 0,
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

            // Unknown mode (not demo or live): skip item
            $queueSkippedModeDisabled++;
            $qItem['skip_reason'] = 'ignored_unknown_mode';
            continue;
        }
        unset($qItem);

        return [
            'order_queue'                      => array_values($orderQueue),
            'active_orders'                    => array_values($orderMap),
            'active_positions'                 => array_values($positionMap),
            'closed_positions'                 => $closedPositions,
            'orders_created'                   => $ordersCreated,
            'orders_submitted_paper'           => 0,
            'orders_filled_paper'              => 0,
            'positions_opened'                 => $positionsOpened,
            'positions_closed'                 => $positionsClosed,
            'positions_closed_expired'         => $positionsClosedExpired,
            'positions_closed_withdrawn'       => $positionsClosedWithdrawn,
            'positions_closed_reverse_pattern' => $positionsClosedReversePattern,
            'queue_items_skipped_mode_disabled' => $queueSkippedModeDisabled,
            'queue_items_skipped_mode_passive'  => 0,
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
            'execution_mode'    => 'demo',
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

        // Opened-at with diagnostics
        $rawCreatedTime     = $bybitPos['createdTime'] ?? null;
        $rawCreatedTimeUnit = 'unknown';
        $openedAtSource     = 'local_cache';
        $openedAt           = $tickAt;

        if ($rawCreatedTime !== null && is_numeric($rawCreatedTime) && (int)$rawCreatedTime > 0) {
            $ct = (int)$rawCreatedTime;
            if ($ct > 1_000_000_000_000) {
                // milliseconds
                $rawCreatedTimeUnit = 'ms';
                $openedAt           = date('c', (int)($ct / 1000));
                $openedAtSource     = 'bybit_createdTime_ms';
            } elseif ($ct > 1_000_000_000) {
                // seconds
                $rawCreatedTimeUnit = 'sec';
                $openedAt           = date('c', $ct);
                $openedAtSource     = 'bybit_createdTime_sec';
            }
            // else: value too small to be a valid Unix timestamp, fall back to local
        }

        $synced_at   = $tickAt;
        $durationSec = (int)(time() - @strtotime($openedAt));
        if ($durationSec < 0) {
            $durationSec = 0;
        }

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
            'mode'              => 'demo',
            'account'           => 'bybit_demo',
            'transition_reason' => 'synced_from_bybit_demo',
            'opened_at'             => $openedAt,
            'opened_at_source'      => $openedAtSource,
            'raw_created_time'      => $rawCreatedTime,
            'raw_created_time_unit' => $rawCreatedTimeUnit,
            'synced_at'             => $synced_at,
            'duration_sec'          => $durationSec,
            'entered_at'            => $openedAt,
            'last_updated_at'       => $tickAt,
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
     * Set isolated leverage for a symbol on Bybit Demo (old-bot algorithm).
     *
     * Algorithm:
     *  1. Pre-clamp requested leverage to $metaMax if known.
     *  2. Call /v5/position/set-leverage.
     *  3. Treat retCode 0 and 110043 (or "not modified" in retMsg) as success.
     *  4. If Bybit rejects with a max-leverage error, parse the max from retMsg
     *     and retry once with the parsed value.
     *
     * Never logs API credentials.
     *
     * @param int|null $metaMax  Bybit maxLeverage from instruments-info (may be null)
     * @return array{
     *   ok: bool,
     *   requested: int,
     *   effective: int,
     *   meta_max: int|null,
     *   parsed_max: int|null,
     *   note: string,
     *   ret_code: int,
     *   ret_msg: string
     * }
     */
    private function setDemoLeverage(
        \Core\Gateway\Bybit $gw,
        string $symbol,
        int $requested,
        ?int $metaMax = null
    ): array {
        // Pre-clamp to known Bybit max
        $effective = $requested;
        if ($metaMax !== null && $metaMax > 0 && $requested > $metaMax) {
            $effective = $metaMax;
        }

        $attempt = function(int $lev) use ($gw, $symbol): array {
            try {
                return $gw->request('/v5/position/set-leverage', [
                    'category'     => 'linear',
                    'symbol'       => $symbol,
                    'buyLeverage'  => (string)$lev,
                    'sellLeverage' => (string)$lev,
                ], true);
            } catch (\Throwable $ex) {
                return ['ret_code' => -1, 'ret_msg' => $ex->getMessage(), 'success' => false];
            }
        };

        $isOk = static function(int $retCode, string $retMsg): bool {
            return $retCode === 0
                || $retCode === 110043
                || stripos($retMsg, 'not modified') !== false
                || stripos($retMsg, 'not been modified') !== false;
        };

        $resp    = $attempt($effective);
        $retCode = (int)($resp['ret_code'] ?? -1);
        $retMsg  = (string)($resp['ret_msg'] ?? '');

        if ($isOk($retCode, $retMsg)) {
            $note = ($retCode === 110043
                     || stripos($retMsg, 'not modified') !== false
                     || stripos($retMsg, 'not been modified') !== false)
                ? 'already_set'
                : 'set_ok';
            return [
                'ok'         => true,
                'requested'  => $requested,
                'effective'  => $effective,
                'meta_max'   => $metaMax,
                'parsed_max' => null,
                'note'       => $note,
                'ret_code'   => $retCode,
                'ret_msg'    => $retMsg,
            ];
        }

        // Try to parse a smaller max-leverage value from the error message and retry
        $parsedMax = $this->parseMaxLeverageFromMsg($retMsg);
        if ($parsedMax !== null && $parsedMax > 0 && $parsedMax < $effective) {
            $resp2    = $attempt($parsedMax);
            $retCode2 = (int)($resp2['ret_code'] ?? -1);
            $retMsg2  = (string)($resp2['ret_msg'] ?? '');
            $ok2      = $isOk($retCode2, $retMsg2);
            return [
                'ok'         => $ok2,
                'requested'  => $requested,
                'effective'  => $ok2 ? $parsedMax : $effective,
                'meta_max'   => $metaMax,
                'parsed_max' => $parsedMax,
                'note'       => $ok2 ? 'set_ok_after_parse_retry' : 'retry_failed',
                'ret_code'   => $retCode2,
                'ret_msg'    => $retMsg2,
            ];
        }

        return [
            'ok'         => false,
            'requested'  => $requested,
            'effective'  => $effective,
            'meta_max'   => $metaMax,
            'parsed_max' => null,
            'note'       => 'rejected',
            'ret_code'   => $retCode,
            'ret_msg'    => $retMsg,
        ];
    }

    /**
     * Attempt to parse the maximum leverage from a Bybit error message.
     *
     * Handles messages like:
     *  "The maximum leverage is 75"
     *  "Leverage should not exceed 75"
     *  "Cross Margin/Isolated Margin leverage ... maximum is 75"
     */
    private function parseMaxLeverageFromMsg(string $msg): ?int
    {
        if (preg_match(
            '/(?:maximum\s+leverage\s+(?:is\s+)?|leverage\s+(?:should\s+not\s+exceed|cannot\s+exceed|exceeds?)\s+)(\d+)/i',
            $msg,
            $m
        )) {
            return (int)$m[1];
        }
        return null;
    }

    /**
     * Resolve execution budget from config → signal priority chain.
     *
     * Priority:
     *  1. config max_bot_budget if > 0
     *  2. config budget_per_trade if > 0
     *  3. qItem bot_budget if > 0
     *  4. fallback 6.0
     *
     * @return array{budget: float, source: string}
     */
    private function resolveExecutionBudget(array $qItem, array $config): array
    {
        $maxBotBudget   = (float)($config['max_bot_budget']   ?? 0.0);
        $budgetPerTrade = (float)($config['budget_per_trade'] ?? 0.0);
        $qItemBudget    = (float)($qItem['bot_budget']        ?? 0.0);

        if ($maxBotBudget > 0.0) {
            return ['budget' => $maxBotBudget, 'source' => 'config.max_bot_budget'];
        }
        if ($budgetPerTrade > 0.0) {
            return ['budget' => $budgetPerTrade, 'source' => 'config.budget_per_trade'];
        }
        if ($qItemBudget > 0.0) {
            return ['budget' => $qItemBudget, 'source' => 'signal.bot_budget'];
        }
        return ['budget' => 6.0, 'source' => 'fallback'];
    }

    /**
     * Resolve execution leverage from config → signal priority chain.
     *
     * Priority:
     *  1. config max_bot_leverage if > 0
     *  2. config leverage if > 0
     *  3. qItem bot_leverage ONLY if > 1 (value=1 treated as "not set")
     *  4. fallback 5
     *
     * @return array{leverage: int, source: string}
     */
    private function resolveExecutionLeverage(array $qItem, array $config): array
    {
        $maxBotLeverage = (int)($config['max_bot_leverage'] ?? 0);
        $configLeverage = (int)($config['leverage']         ?? 0);
        $qItemLeverage  = (int)($qItem['bot_leverage']      ?? 0);

        if ($maxBotLeverage > 0) {
            return ['leverage' => $maxBotLeverage, 'source' => 'config.max_bot_leverage'];
        }
        if ($configLeverage > 0) {
            return ['leverage' => $configLeverage, 'source' => 'config.leverage'];
        }
        // bot_leverage=1 is treated as unset/default placeholder, not as intentional 1x
        if ($qItemLeverage > 1) {
            return ['leverage' => $qItemLeverage, 'source' => 'signal.bot_leverage'];
        }
        return ['leverage' => 5, 'source' => 'fallback'];
    }

    /**
     * Resolve max active positions from config.
     *
     * Priority:
     *  1. config default_max_active_positions if > 0
     *  2. config max_active_positions if > 0
     *  3. fallback 10
     */
    private function resolveMaxActivePositions(array $config): int
    {
        $defaultMax = (int)($config['default_max_active_positions'] ?? 0);
        $maxActive  = (int)($config['max_active_positions']         ?? 0);

        if ($defaultMax > 0) {
            return $defaultMax;
        }
        if ($maxActive > 0) {
            return $maxActive;
        }
        return 10;
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
     * Get a Bybit gateway client for live trading via KeyCenter.
     *
     * Uses account_id from bot config. KeyCenter handles credential injection.
     * Never uses demo credentials, never falls back to demo.
     * Returns null when account_id is not configured or client creation fails.
     */
    private function getLiveGateway(array $config): ?\Core\Gateway\Bybit
    {
        $accountId = trim((string)($config['account_id'] ?? ''));
        if ($accountId === '') {
            return null;
        }

        try {
            return \Core\Gateway\Bybit::client($accountId);
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
        // Last-tick leverage diagnostics (updated per submitted order)
        $demoLastReqLeverage      = null;
        $demoLastEffLeverage      = null;
        $demoLastLeverageSrc      = null;
        $demoLastBudgetSrc        = null;
        $demoLastSetLevNote       = null;
        $demoLastSetLevCode       = null;
        $demoLastSetLevMsg        = null;
        $demoLeverageMismatchCount= 0;

        $gw = $this->getDemoGateway($config);

        // Tracks effective leverage per symbol for post-submit verify (Part 5)
        $submittedEffective = [];

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

        $maxPos = $this->resolveMaxActivePositions($config);

        // Process ready queue items
        foreach ($orderQueue as &$qItem) {
            if (($qItem['queue_status'] ?? '') !== 'ready') {
                continue;
            }

            // Only process items stamped for demo mode (or legacy items without execution_mode)
            $itemMode = $qItem['execution_mode'] ?? null;
            if ($itemMode !== null && $itemMode !== 'demo') {
                $qItem['skip_reason'] = 'wrong_execution_mode';
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

            $entryPrice = (float)($qItem['entry_price'] ?? 0.0);

            if ($entryPrice <= 0.0) {
                $qItem['skip_reason'] = 'invalid_entry_price_or_budget';
                $demoOrdersRejected++;
                continue;
            }

            // ── Resolve execution budget & leverage from config (Part 1 / Part 2) ──
            $budgetResolved   = $this->resolveExecutionBudget($qItem, $config);
            $leverageResolved = $this->resolveExecutionLeverage($qItem, $config);

            $budget            = $budgetResolved['budget'];
            $budgetSource      = $budgetResolved['source'];
            $requestedLeverage = $leverageResolved['leverage'];
            $leverageSource    = $leverageResolved['source'];

            // Overwrite queue item so logs reflect actual execution values
            $qItem['bot_budget']     = $budget;
            $qItem['bot_leverage']   = $requestedLeverage;
            $qItem['budget_source']  = $budgetSource;
            $qItem['leverage_source']= $leverageSource;

            $demoOrdersPrepared++;

            // ── Step 1: Fetch symbol instrument info ──────────────────────────
            $symbolInfo  = $this->fetchSymbolInstrumentInfo($gw, $symbol);
            $bybitMaxLev = $symbolInfo['bybit_max_leverage'] ?? null;
            $minOrderQty = $symbolInfo['min_order_qty']      ?? null;
            $qtyStep     = $symbolInfo['qty_step']           ?? null;
            $maxOrderQty = $symbolInfo['max_order_qty']      ?? null;
            $minNotional = $symbolInfo['min_notional_value'] ?? null;

            // Store instrument meta in queue item
            $qItem['requested_leverage']  = $requestedLeverage;
            $qItem['bybit_max_leverage']  = $bybitMaxLev;
            $qItem['qty_step']            = $qtyStep;
            $qItem['min_order_qty']       = $minOrderQty;
            $qItem['min_notional_value']  = $minNotional;

            // ── Step 2: Set leverage on Bybit Demo (old-bot algorithm) ─────────
            // setDemoLeverage clamps, calls API, retries if Bybit rejects with max
            $levResult = $this->setDemoLeverage($gw, $symbol, $requestedLeverage, $bybitMaxLev);

            $effectiveLeverage   = $levResult['effective'];
            $leverageWasClamped  = ($levResult['effective'] !== $levResult['requested']);

            if ($leverageWasClamped) {
                $demoLeverageClampedCount++;
            }

            $qItem['set_leverage_attempted'] = true;
            $qItem['set_leverage_ok']        = $levResult['ok'];
            $qItem['set_leverage_ret_code']  = $levResult['ret_code'];
            $qItem['set_leverage_ret_msg']   = $levResult['ret_msg'];
            $qItem['set_leverage_note']      = $levResult['note'];
            $qItem['effective_leverage']     = $effectiveLeverage;
            $qItem['leverage_was_clamped']   = $leverageWasClamped;

            // Track for dashboard last-tick display
            $demoLastReqLeverage  = $requestedLeverage;
            $demoLastEffLeverage  = $effectiveLeverage;
            $demoLastLeverageSrc  = $leverageSource;
            $demoLastBudgetSrc    = $budgetSource;
            $demoLastSetLevNote   = $levResult['note'];
            $demoLastSetLevCode   = $levResult['ret_code'];
            $demoLastSetLevMsg    = $levResult['ret_msg'];

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

            // ── Step 3: Calculate qty using effective leverage (Part 4) ────────
            $rawQty = ($budget * $effectiveLeverage) / $entryPrice;
            $qItem['raw_qty'] = $rawQty;

            // ── Step 4: Normalize qty ─────────────────────────────────────────
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

                // Track effective leverage for post-submit verify (Part 5)
                $submittedEffective[$symbol] = $effectiveLeverage;

                $this->appendExecutionLog([
                    'timestamp'             => $tickAt,
                    'event_type'            => 'demo_order_submitted',
                    'strategy_id'           => $qItem['strategy_id'] ?? '',
                    'owner_strategy'        => $qItem['owner_strategy'] ?? '',
                    'signal_id'             => $qItem['signal_id'] ?? '',
                    'symbol'                => $symbol,
                    'side'                  => $side,
                    'entry_price'           => $entryPrice,
                    'budget'                => $budget,
                    'budget_source'         => $budgetSource,
                    'requested_leverage'    => $requestedLeverage,
                    'leverage_source'       => $leverageSource,
                    'meta_max_leverage'     => $bybitMaxLev,
                    'effective_leverage'    => $effectiveLeverage,
                    'set_leverage_note'     => $levResult['note'],
                    'set_leverage_ret_code' => $levResult['ret_code'],
                    'set_leverage_ret_msg'  => $levResult['ret_msg'],
                    'leverage_was_clamped'  => $leverageWasClamped,
                    'raw_qty'               => $rawQty,
                    'normalized_qty'        => $normalizedQty,
                    'qty_step'              => $qtyStep,
                    'min_order_qty'         => $minOrderQty,
                    'min_notional_value'    => $minNotional,
                    'order_type'            => 'Market',
                    'execution_mode'        => 'demo',
                    'bybit_side'            => $bybitSide,
                    'demo_order_id'         => $qItem['demo_order_id'],
                    'reason'                => 'submitted_market_order_to_bybit_demo',
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

            // Part 5: verify Bybit-reported leverage matches what we set
            $bybitPosLev  = (int)($pos['leverage'] ?? 0);
            $expectedLev  = $submittedEffective[$sym] ?? null;
            if ($expectedLev !== null && $bybitPosLev > 0 && $bybitPosLev !== $expectedLev) {
                $record['leverage_warning']                      = 'leverage_not_applied_on_exchange';
                $record['requested_leverage']                    = $expectedLev;
                $record['bybit_position_leverage_after_submit']  = $bybitPosLev;
                $demoLeverageMismatchCount++;
                $this->appendExecutionLog([
                    'timestamp'                             => $tickAt,
                    'event_type'                            => 'demo_leverage_mismatch',
                    'symbol'                                => $sym,
                    'expected_leverage'                     => $expectedLev,
                    'bybit_position_leverage_after_submit'  => $bybitPosLev,
                    'reason'                                => 'leverage_not_applied_on_exchange',
                ]);
                $logEvents++;
            }

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
                $pos['position_status'] = 'closed';
                $pos['closed_at']       = $tickAt;
                $pos['close_reason']    = 'position_gone_from_bybit_demo';
                $pos['last_updated_at'] = $tickAt;
                $pos['mode']            = 'demo';
                $closedPositions[]      = $pos;
                $positionsClosed++;
                $this->recordClosedTrade($pos, $tickAt);
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
            // Per-tick leverage diagnostics (last order attempt)
            'demo_last_req_leverage'           => $demoLastReqLeverage,
            'demo_last_eff_leverage'           => $demoLastEffLeverage,
            'demo_last_leverage_source'        => $demoLastLeverageSrc,
            'demo_last_budget_source'          => $demoLastBudgetSrc,
            'demo_last_set_lev_note'           => $demoLastSetLevNote,
            'demo_last_set_lev_code'           => $demoLastSetLevCode,
            'demo_last_set_lev_msg'            => $demoLastSetLevMsg,
            'demo_leverage_mismatch_count'     => $demoLeverageMismatchCount,
        ];
    }

    /**
     * Live mode execution: real orders on Bybit Live via KeyCenter.
     *
     * Safety guards (executed before ANY order submission):
     *   1. live_enabled flag must be true in config
     *   2. account_id must exist in config
     *   3. KeyCenter must return a valid live gateway
     *   4. Lightweight API test call must succeed
     *
     * No fallback to demo. No fallback from live to demo.
     * Mirrors processDemoExecution() in structure; no logic change to stop/math.
     */
    private function processLiveExecution(
        array $orderQueue,
        array $activeOrders,
        array $activePositions,
        array $closedPositions,
        array $config,
        string $tickAt
    ): array {
        $ordersCreated       = 0;
        $ordersSubmittedLive = 0;
        $ordersConfirmedLive = 0;
        $positionsOpened     = 0;
        $positionsClosed     = 0;
        $positionsClosedExp  = 0;
        $positionsClosedWith = 0;
        $logEvents           = 0;

        // Live execution diagnostics
        $liveOrdersPrepared       = 0;
        $liveOrdersRejected       = 0;
        $liveLeverageClampedCount = 0;
        $liveSetLevFailedCount    = 0;
        $liveQtyInvalidCount      = 0;
        $liveLastErrorCode        = null;
        $liveLastErrorMsg         = null;
        $liveLastRejectedSymbol   = null;
        $liveLastReqLeverage      = null;
        $liveLastEffLeverage      = null;
        $liveLastLeverageSrc      = null;
        $liveLastBudgetSrc        = null;
        $liveLastSetLevNote       = null;
        $liveLastSetLevCode       = null;
        $liveLastSetLevMsg        = null;
        $liveLeverageMismatchCount= 0;

        $skipAllReason = null;

        // ── Safety guard 1: live_enabled flag ─────────────────────────────────
        if (!(bool)($config['live_enabled'] ?? false)) {
            $skipAllReason = 'live_not_enabled';
        }

        // ── Safety guard 2: account_id ────────────────────────────────────────
        $accountId = trim((string)($config['account_id'] ?? ''));
        if ($skipAllReason === null && $accountId === '') {
            $skipAllReason = 'live_not_ready';
        }

        // ── Safety guard 3 + 4: KeyCenter gateway + API test ──────────────────
        $gw = null;
        if ($skipAllReason === null) {
            $gw = $this->getLiveGateway($config);
            if ($gw === null) {
                $skipAllReason = 'live_not_ready';
            } else {
                // Lightweight API test
                try {
                    $testResp = $gw->request('/v5/account/wallet-balance', [
                        'accountType' => 'UNIFIED',
                    ], true);
                    $testOk = ($testResp['success'] ?? false) && ($testResp['ret_code'] ?? -1) === 0;
                    if (!$testOk) {
                        $testCode    = $testResp['ret_code'] ?? -1;
                        $skipAllReason = in_array($testCode, [10003, 10004], true)
                            ? 'live_keycenter_credentials_missing'
                            : 'live_not_ready';
                        $gw = null;
                    }
                } catch (\Throwable) {
                    $skipAllReason = 'live_not_ready';
                    $gw = null;
                }
            }
        }

        // ── If any guard failed: skip all ready queue items ────────────────────
        if ($gw === null) {
            foreach ($orderQueue as &$qItem) {
                if (($qItem['queue_status'] ?? '') === 'ready') {
                    $qItem['skip_reason'] = $skipAllReason ?? 'live_not_ready';
                }
            }
            unset($qItem);

            return [
                'order_queue'            => array_values($orderQueue),
                'active_orders'          => $activeOrders,
                'active_positions'       => $activePositions,
                'closed_positions'       => $closedPositions,
                'orders_created'         => 0,
                'orders_submitted_demo'  => 0,
                'orders_confirmed_demo'  => 0,
                'positions_opened'       => 0,
                'positions_closed'       => 0,
                'positions_closed_expired'   => 0,
                'positions_closed_withdrawn' => 0,
                'execution_log_events'   => 0,
                'live_orders_prepared'   => 0,
                'live_orders_rejected'   => 0,
                'live_skip_reason'       => $skipAllReason,
            ];
        }

        // Fetch current positions from Bybit Live
        $livePositions = $this->fetchDemoPositions($gw); // same API endpoint, different gateway

        // Build symbol → Bybit position map for dedup
        $symbolMap = [];
        foreach ($livePositions as $pos) {
            $sym = (string)($pos['symbol'] ?? '');
            if ($sym !== '') {
                $symbolMap[$sym] = $pos;
            }
        }

        // Build queue key → queue item map
        $queueMap = [];
        foreach ($orderQueue as $q) {
            $k = $this->queueKey($q);
            if ($k !== '') {
                $queueMap[$k] = $q;
            }
        }

        $maxPos = $this->resolveMaxActivePositions($config);
        $submittedEffective = [];

        // Process ready queue items
        foreach ($orderQueue as &$qItem) {
            if (($qItem['queue_status'] ?? '') !== 'ready') {
                continue;
            }

            // Only process items stamped for live mode (or legacy items without execution_mode)
            $itemMode = $qItem['execution_mode'] ?? null;
            if ($itemMode !== null && $itemMode !== 'live') {
                $qItem['skip_reason'] = 'wrong_execution_mode';
                continue;
            }

            $key    = $this->queueKey($qItem);
            $symbol = (string)($qItem['symbol'] ?? '');
            $side   = (string)($qItem['side']   ?? 'long');

            if ($symbol === '') {
                continue;
            }

            if (isset($symbolMap[$symbol])) {
                $qItem['skip_reason'] = 'symbol_already_active_on_live';
                continue;
            }

            if ($maxPos > 0 && count($symbolMap) >= $maxPos) {
                $qItem['skip_reason'] = 'max_active_positions_reached';
                continue;
            }

            $entryPrice = (float)($qItem['entry_price'] ?? 0.0);
            if ($entryPrice <= 0.0) {
                $qItem['skip_reason'] = 'invalid_entry_price_or_budget';
                $liveOrdersRejected++;
                continue;
            }

            $budgetResolved   = $this->resolveExecutionBudget($qItem, $config);
            $leverageResolved = $this->resolveExecutionLeverage($qItem, $config);

            $budget            = $budgetResolved['budget'];
            $budgetSource      = $budgetResolved['source'];
            $requestedLeverage = $leverageResolved['leverage'];
            $leverageSource    = $leverageResolved['source'];

            $qItem['bot_budget']      = $budget;
            $qItem['bot_leverage']    = $requestedLeverage;
            $qItem['budget_source']   = $budgetSource;
            $qItem['leverage_source'] = $leverageSource;

            $liveOrdersPrepared++;

            $symbolInfo  = $this->fetchSymbolInstrumentInfo($gw, $symbol);
            $bybitMaxLev = $symbolInfo['bybit_max_leverage'] ?? null;
            $minOrderQty = $symbolInfo['min_order_qty']      ?? null;
            $qtyStep     = $symbolInfo['qty_step']           ?? null;
            $maxOrderQty = $symbolInfo['max_order_qty']      ?? null;
            $minNotional = $symbolInfo['min_notional_value'] ?? null;

            $qItem['requested_leverage'] = $requestedLeverage;
            $qItem['bybit_max_leverage'] = $bybitMaxLev;
            $qItem['qty_step']           = $qtyStep;
            $qItem['min_order_qty']      = $minOrderQty;
            $qItem['min_notional_value'] = $minNotional;

            $levResult = $this->setDemoLeverage($gw, $symbol, $requestedLeverage, $bybitMaxLev);

            $effectiveLeverage  = $levResult['effective'];
            $leverageWasClamped = ($levResult['effective'] !== $levResult['requested']);
            if ($leverageWasClamped) {
                $liveLeverageClampedCount++;
            }

            $qItem['set_leverage_attempted'] = true;
            $qItem['set_leverage_ok']        = $levResult['ok'];
            $qItem['set_leverage_ret_code']  = $levResult['ret_code'];
            $qItem['set_leverage_ret_msg']   = $levResult['ret_msg'];
            $qItem['set_leverage_note']      = $levResult['note'];
            $qItem['effective_leverage']     = $effectiveLeverage;
            $qItem['leverage_was_clamped']   = $leverageWasClamped;

            $liveLastReqLeverage = $requestedLeverage;
            $liveLastEffLeverage = $effectiveLeverage;
            $liveLastLeverageSrc = $leverageSource;
            $liveLastBudgetSrc   = $budgetSource;
            $liveLastSetLevNote  = $levResult['note'];
            $liveLastSetLevCode  = $levResult['ret_code'];
            $liveLastSetLevMsg   = $levResult['ret_msg'];

            if (!$levResult['ok']) {
                $qItem['skip_reason']     = 'set_leverage_failed';
                $qItem['live_error_code'] = $levResult['ret_code'];
                $qItem['live_error_msg']  = $levResult['ret_msg'];
                $liveSetLevFailedCount++;
                $liveOrdersRejected++;
                $liveLastErrorCode      = $levResult['ret_code'];
                $liveLastErrorMsg       = $levResult['ret_msg'];
                $liveLastRejectedSymbol = $symbol;
                continue;
            }

            $rawQty        = ($budget * $effectiveLeverage) / $entryPrice;
            $qItem['raw_qty'] = $rawQty;

            $normalizedQty = $rawQty;
            if ($qtyStep !== null && $qtyStep > 0.0) {
                $decPlaces     = max(0, (int)ceil(-log10($qtyStep)));
                $normalizedQty = floor($rawQty / $qtyStep) * $qtyStep;
                $normalizedQty = round($normalizedQty, $decPlaces);
            }
            $qItem['normalized_qty'] = $normalizedQty;

            if ($normalizedQty <= 0.0) {
                $qItem['skip_reason'] = 'qty_invalid_after_normalization';
                $liveQtyInvalidCount++;
                $liveOrdersRejected++;
                $liveLastRejectedSymbol = $symbol;
                continue;
            }
            if ($minOrderQty !== null && $normalizedQty < $minOrderQty) {
                $qItem['skip_reason'] = 'qty_below_min_order_qty';
                $liveQtyInvalidCount++;
                $liveOrdersRejected++;
                $liveLastRejectedSymbol = $symbol;
                continue;
            }
            if ($maxOrderQty !== null && $maxOrderQty > 0.0 && $normalizedQty > $maxOrderQty) {
                $qItem['skip_reason'] = 'qty_above_max_order_qty';
                $liveQtyInvalidCount++;
                $liveOrdersRejected++;
                $liveLastRejectedSymbol = $symbol;
                continue;
            }
            if ($minNotional !== null && $minNotional > 0.0
                && ($normalizedQty * $entryPrice) < $minNotional
            ) {
                $qItem['skip_reason'] = 'qty_below_min_notional';
                $liveQtyInvalidCount++;
                $liveOrdersRejected++;
                $liveLastRejectedSymbol = $symbol;
                continue;
            }

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
                $qItem['skip_reason']   = 'live_submit_exception';
                $liveOrdersRejected++;
                $liveLastRejectedSymbol = $symbol;
                continue;
            }

            $ordersCreated++;

            if (($orderResp['success'] ?? false) && ($orderResp['ret_code'] ?? -1) === 0) {
                $ordersSubmittedLive++;
                $qItem['queue_status']       = 'submitted';
                $qItem['submitted_at']       = $tickAt;
                $qItem['last_change_reason'] = 'submitted_to_live';
                $qItem['live_order_id']      = $orderResp['result']['orderId'] ?? null;

                $symbolMap[$symbol] = ['symbol' => $symbol, 'size' => $normalizedQty, '_pending' => true];
                $positionsOpened++;

                $submittedEffective[$symbol] = $effectiveLeverage;

                $this->appendExecutionLog([
                    'timestamp'             => $tickAt,
                    'event_type'            => 'live_order_submitted',
                    'strategy_id'           => $qItem['strategy_id'] ?? '',
                    'owner_strategy'        => $qItem['owner_strategy'] ?? '',
                    'signal_id'             => $qItem['signal_id'] ?? '',
                    'symbol'                => $symbol,
                    'side'                  => $side,
                    'entry_price'           => $entryPrice,
                    'budget'                => $budget,
                    'budget_source'         => $budgetSource,
                    'requested_leverage'    => $requestedLeverage,
                    'leverage_source'       => $leverageSource,
                    'meta_max_leverage'     => $bybitMaxLev,
                    'effective_leverage'    => $effectiveLeverage,
                    'set_leverage_note'     => $levResult['note'],
                    'set_leverage_ret_code' => $levResult['ret_code'],
                    'set_leverage_ret_msg'  => $levResult['ret_msg'],
                    'leverage_was_clamped'  => $leverageWasClamped,
                    'raw_qty'               => $rawQty,
                    'normalized_qty'        => $normalizedQty,
                    'qty_step'              => $qtyStep,
                    'min_order_qty'         => $minOrderQty,
                    'min_notional_value'    => $minNotional,
                    'order_type'            => 'Market',
                    'execution_mode'        => 'live',
                    'bybit_side'            => $bybitSide,
                    'live_order_id'         => $qItem['live_order_id'],
                    'reason'                => 'submitted_market_order_to_bybit_live',
                ]);
                $logEvents++;
            } else {
                $retCode = $orderResp['ret_code'] ?? null;
                $retMsg  = $orderResp['ret_msg']  ?? null;
                $qItem['skip_reason']     = 'live_order_rejected';
                $qItem['live_error_code'] = $retCode;
                $qItem['live_error_msg']  = $retMsg;
                $liveOrdersRejected++;
                $liveLastErrorCode      = $retCode;
                $liveLastErrorMsg       = $retMsg;
                $liveLastRejectedSymbol = $symbol;
            }
        }
        unset($qItem);

        // Re-sync positions from Bybit Live after submitting orders
        $freshLivePositions = $this->fetchDemoPositions($gw);

        $symbolQueueContext = [];
        foreach ($orderQueue as $q) {
            $sym = (string)($q['symbol'] ?? '');
            if ($sym !== '' && !isset($symbolQueueContext[$sym])) {
                $symbolQueueContext[$sym] = $q;
            }
        }

        $newActivePositions = [];
        foreach ($freshLivePositions as $pos) {
            $sym    = (string)($pos['symbol'] ?? '');
            $qCtx   = $symbolQueueContext[$sym] ?? [];
            $record = $this->buildPositionFromDemoData($pos, $qCtx, $tickAt);

            // Override demo fields with live fields
            $record['execution_mode']    = 'live';
            $record['mode']              = 'live';
            $record['account']           = $accountId;
            $record['transition_reason'] = 'synced_from_bybit_live';

            // Verify leverage
            $bybitPosLev = (int)($pos['leverage'] ?? 0);
            $expectedLev = $submittedEffective[$sym] ?? null;
            if ($expectedLev !== null && $bybitPosLev > 0 && $bybitPosLev !== $expectedLev) {
                $record['leverage_warning']                     = 'leverage_not_applied_on_exchange';
                $record['requested_leverage']                   = $expectedLev;
                $record['bybit_position_leverage_after_submit'] = $bybitPosLev;
                $liveLeverageMismatchCount++;
                $this->appendExecutionLog([
                    'timestamp'                            => $tickAt,
                    'event_type'                           => 'live_leverage_mismatch',
                    'symbol'                               => $sym,
                    'expected_leverage'                    => $expectedLev,
                    'bybit_position_leverage_after_submit' => $bybitPosLev,
                    'reason'                               => 'leverage_not_applied_on_exchange',
                ]);
                $logEvents++;
            }

            $newActivePositions[] = $record;
            $ordersConfirmedLive++;
        }

        // Detect positions gone from live exchange
        $freshSymbols = array_flip(array_map(
            fn($p) => (string)($p['symbol'] ?? ''),
            $freshLivePositions
        ));
        foreach ($activePositions as $pos) {
            $sym = (string)($pos['symbol'] ?? '');
            if ($sym !== '' && !isset($freshSymbols[$sym]) && ($pos['execution_mode'] ?? '') === 'live') {
                $pos['position_status'] = 'closed';
                $pos['closed_at']       = $tickAt;
                $pos['close_reason']    = 'position_gone_from_bybit_live';
                $pos['last_updated_at'] = $tickAt;
                $pos['mode']            = 'live';
                $closedPositions[]      = $pos;
                $positionsClosed++;
                $this->recordClosedTrade($pos, $tickAt);
            }
        }

        return [
            'order_queue'              => array_values($orderQueue),
            'active_orders'            => $activeOrders,
            'active_positions'         => array_values($newActivePositions),
            'closed_positions'         => $closedPositions,
            'orders_created'           => $ordersCreated,
            'orders_submitted_demo'    => 0,
            'orders_confirmed_demo'    => 0,
            'positions_opened'         => $positionsOpened,
            'positions_closed'         => $positionsClosed,
            'positions_closed_expired'   => $positionsClosedExp,
            'positions_closed_withdrawn' => $positionsClosedWith,
            'execution_log_events'     => $logEvents,
            // Live execution diagnostics (reuse demo_* keys for last_run.json compatibility)
            'demo_orders_prepared'           => $liveOrdersPrepared,
            'demo_orders_rejected'           => $liveOrdersRejected,
            'demo_leverage_clamped_count'    => $liveLeverageClampedCount,
            'demo_set_leverage_failed_count' => $liveSetLevFailedCount,
            'demo_qty_invalid_count'         => $liveQtyInvalidCount,
            'demo_last_error_code'           => $liveLastErrorCode,
            'demo_last_error_msg'            => $liveLastErrorMsg,
            'demo_last_rejected_symbol'      => $liveLastRejectedSymbol,
            'demo_last_req_leverage'         => $liveLastReqLeverage,
            'demo_last_eff_leverage'         => $liveLastEffLeverage,
            'demo_last_leverage_source'      => $liveLastLeverageSrc,
            'demo_last_budget_source'        => $liveLastBudgetSrc,
            'demo_last_set_lev_note'         => $liveLastSetLevNote,
            'demo_last_set_lev_code'         => $liveLastSetLevCode,
            'demo_last_set_lev_msg'          => $liveLastSetLevMsg,
            'demo_leverage_mismatch_count'   => $liveLeverageMismatchCount,
            'live_orders_prepared'           => $liveOrdersPrepared,
            'live_orders_rejected'           => $liveOrdersRejected,
            'live_skip_reason'               => null,
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

            // Liquidation context (local estimate; no real exchange liq without exchange call)
            'liq_price'           => null,
            'estimated_liq_price' => $estimatedLiqPrice,

            // Position lifecycle
            'position_status'   => 'open',
            'execution_mode'    => 'demo',
            'transition_reason' => 'filled_locally',
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
    private function buildQueueItem(array $signal, array $opOverrides, array $config, string $tickAt, string $executionMode = 'demo'): array
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
            'queue_status'   => 'queued',
            // Mode this item was created for — prevents cross-mode execution
            'execution_mode' => $executionMode,
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
                'bot_mode'                                  => 'demo',
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
                'execution_mode'                            => 'demo',
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
            'mode'             => $config['mode']    ?? 'demo',
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
                'mode'    => $config['mode']    ?? 'demo',
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

    /**
     * Maintain a local position runtime age counter.
     *
     * Key: symbol + '_' + side + '_' + account_or_execution_mode
     *   - First time a position appears: runtime_age_minutes = 1, first_seen_at = now
     *   - On every subsequent tick:      runtime_age_minutes += tick_interval_minutes
     *   - If position disappears:        entry removed from map
     *
     * Stored in: storage/position_runtime_age.json
     */
    private function updatePositionRuntimeAge(array $activePositions, string $tickAt, array $config): void
    {
        $relPath         = 'storage/position_runtime_age.json';
        $ageMap          = $this->readJson($relPath, []);
        $tickIntervalSec = max(30, (int)($config['tick_interval_sec'] ?? 60));
        $tickMinutes     = max(1, (int)round($tickIntervalSec / 60));

        $currentKeys = [];
        foreach ($activePositions as $pos) {
            $sym  = (string)($pos['symbol'] ?? '');
            $side = (string)($pos['side']   ?? '');
            if ($sym === '' || $side === '') {
                continue;
            }
            $account = (string)($pos['account'] ?? $pos['execution_mode'] ?? 'local');
            $key     = $sym . '_' . $side . '_' . $account;
            $currentKeys[$key] = true;

            if (isset($ageMap[$key])) {
                $ageMap[$key]['runtime_age_minutes'] += $tickMinutes;
                $ageMap[$key]['last_seen_at']         = $tickAt;
            } else {
                $ageMap[$key] = [
                    'symbol'              => $sym,
                    'side'                => $side,
                    'account'             => $account,
                    'execution_mode'      => (string)($pos['execution_mode'] ?? 'demo'),
                    'runtime_age_minutes' => 1,
                    'first_seen_at'       => $tickAt,
                    'last_seen_at'        => $tickAt,
                ];
            }
        }

        // Remove positions no longer active
        foreach (array_keys($ageMap) as $k) {
            if (!isset($currentKeys[$k])) {
                unset($ageMap[$k]);
            }
        }

        $this->writeJson($relPath, $ageMap);
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

    /**
     * Record a real closed position into the unified closed trades journal.
     *
     * Writes to:
     *   storage/trades/closed_trades.json    — aggregated list (max 1 000 entries)
     *   storage/trades/closed/{id}.json      — individual per-trade file
     *
     * Never modifies active_positions, never places orders, observation-only.
     *
     * @param array  $pos     Closed position record (already has closed_at, close_reason, mode)
     * @param string $tickAt  Tick timestamp
     */
    private function recordClosedTrade(array $pos, string $tickAt): void
    {
        try {
            $symbol    = (string)($pos['symbol']         ?? '');
            $side      = (string)($pos['side']           ?? '');
            $openedAt  = (string)($pos['opened_at']      ?? $pos['entered_at'] ?? '');
            $closedAt  = (string)($pos['closed_at']      ?? $tickAt);
            $mode      = (string)($pos['mode']           ?? $pos['execution_mode'] ?? 'demo');
            $account   = (string)($pos['account']        ?? ($mode === 'live' ? 'bybit_live' : 'bybit_demo'));
            $stratId   = (string)($pos['strategy_id']    ?? $pos['owner_strategy'] ?? '');
            $closeReason = (string)($pos['close_reason'] ?? 'unknown');

            if ($symbol === '' || $side === '') {
                return;
            }

            // ── Trade ID: deterministic, dedup-safe ───────────────────────────
            $idBase = $symbol . '_' . $side . '_' . ($openedAt !== '' ? $openedAt : $closedAt);
            $tradeId = 'ct_' . substr(md5($idBase), 0, 12);

            // ── Exit price: last known mark/current price from position ───────
            $entryPrice     = (float)($pos['entry_price']  ?? 0.0);
            $rawMarkPrice   = (float)($pos['mark_price']   ?? 0.0);
            $rawCurrentPrice= (float)($pos['current_price']?? 0.0);

            if ($rawMarkPrice > 0.0) {
                $exitPrice       = $rawMarkPrice;
                $exitPriceSource = 'last_cached_mark_price';
            } elseif ($rawCurrentPrice > 0.0) {
                $exitPrice       = $rawCurrentPrice;
                $exitPriceSource = 'last_cached_current_price';
            } else {
                // Fallback: use entry as exit (ROI = 0, estimate)
                $exitPrice       = $entryPrice;
                $exitPriceSource = 'fallback_entry_price';
            }

            // ── Leverage & budget ─────────────────────────────────────────────
            $leverage = max(1, (int)($pos['bot_leverage'] ?? $pos['leverage'] ?? 1));
            $budget   = (float)($pos['bot_budget']        ?? $pos['budget']   ?? 0.0);
            $size     = (float)($pos['size']              ?? 0.0);

            // ── ROI & PnL ─────────────────────────────────────────────────────
            $roi = null;
            $pnl = null;
            if ($entryPrice > 0.0 && $exitPrice > 0.0 && $leverage > 0) {
                if ($side === 'short') {
                    $roi = ($entryPrice - $exitPrice) / $entryPrice * $leverage * 100.0;
                } else {
                    $roi = ($exitPrice - $entryPrice) / $entryPrice * $leverage * 100.0;
                }
                $roi = round($roi, 4);
            }
            if ($roi !== null && $budget > 0.0) {
                $pnl = round($budget * $roi / 100.0, 6);
            }

            // ── Duration ─────────────────────────────────────────────────────
            $durationSec = null;
            if ($openedAt !== '' && $closedAt !== '') {
                $oTs = is_numeric($openedAt) ? (int)$openedAt : (int)@strtotime($openedAt);
                $cTs = is_numeric($closedAt) ? (int)$closedAt : (int)@strtotime($closedAt);
                if ($oTs > 0 && $cTs >= $oTs) {
                    $durationSec = $cTs - $oTs;
                }
            }

            // ── Close source detection ────────────────────────────────────────
            // Only set stop_manager if the position record carries an explicit
            // stop-trigger field.  Never infer it from stops.json presence alone.
            $closeSource = 'unknown';
            $closeReason = 'position_gone_from_exchange';

            $explicitCloseSource = (string)($pos['close_source'] ?? '');
            $explicitCloseReason = (string)($pos['close_reason'] ?? '');

            if ($explicitCloseSource !== '') {
                $closeSource = $explicitCloseSource;
            }
            if ($explicitCloseReason !== '' && $explicitCloseReason !== 'position_gone_from_bybit_demo' && $explicitCloseReason !== 'position_gone_from_bybit_live') {
                $closeReason = $explicitCloseReason;
            }

            // ── PM close registry lookup ──────────────────────────────────────
            // If Profit Manager closed this position it will have left an entry
            // in the registry file.  Consume it once, then remove it.
            $closeOrderId   = null;
            $executionType  = 'inferred_close';
            $pmRegistryPath = $this->moduleDir . '/storage/runtime/pm_close_registry.json';
            $pmRegistryKey  = $symbol . '_' . $side;
            $pmRegistryTtl  = 300; // seconds

            try {
                if (is_file($pmRegistryPath)) {
                    $regRaw = @file_get_contents($pmRegistryPath);
                    if ($regRaw !== false && $regRaw !== '') {
                        $registry = @json_decode($regRaw, true);
                        if (is_array($registry) && isset($registry[$pmRegistryKey])) {
                            $entry = $registry[$pmRegistryKey];
                            $entryTs = (int)($entry['ts'] ?? 0);
                            if ($entryTs > 0 && (time() - $entryTs) <= $pmRegistryTtl) {
                                // Registry hit — override close attribution
                                $closeSource   = (string)($entry['close_source']   ?? 'profit_manager');
                                $closeReason   = (string)($entry['close_reason']   ?? $closeReason);
                                $closeOrderId  = ($entry['close_order_id'] ?? null) !== null
                                    ? (string)$entry['close_order_id']
                                    : null;
                                $executionType = 'pm_market_close';
                            }
                            // Remove entry (consumed or stale)
                            unset($registry[$pmRegistryKey]);
                            @file_put_contents(
                                $pmRegistryPath,
                                json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
                                LOCK_EX
                            );
                        }
                    }
                }
            } catch (\Throwable) {
                // Never crash over registry read/write failures
            }

            // ── Build trade record ────────────────────────────────────────────
            $trade = [
                'id'               => $tradeId,
                'symbol'           => $symbol,
                'side'             => $side,
                'strategy_id'      => $stratId,
                'entry_price'      => $entryPrice > 0.0 ? $entryPrice : null,
                'exit_price'       => $exitPrice  > 0.0 ? $exitPrice  : null,
                'exit_price_source'=> $exitPriceSource,
                'roi'              => $roi,
                'pnl'              => $pnl,
                'leverage'         => $leverage,
                'budget'           => $budget > 0.0 ? $budget : null,
                'size'             => $size  > 0.0 ? $size   : null,
                'opened_at'        => $openedAt  !== '' ? $openedAt  : null,
                'closed_at'        => $closedAt  !== '' ? $closedAt  : null,
                'duration_sec'     => $durationSec,
                'mode'             => $mode,
                'account'          => $account,
                'close_source'     => $closeSource,
                'close_reason'     => $closeReason,
                'close_order_id'   => $closeOrderId,
                'execution_type'   => $executionType,
            ];

            // ── Write individual per-trade file ───────────────────────────────
            $indivPath = 'storage/trades/closed/' . $tradeId . '.json';
            $this->writeJson($indivPath, $trade);

            // ── Append to aggregated closed_trades.json ───────────────────────
            $aggRelPath = 'storage/trades/closed_trades.json';
            $aggAbsPath = $this->moduleDir . '/' . $aggRelPath;

            $existing = [];
            if (is_file($aggAbsPath)) {
                $raw = @file_get_contents($aggAbsPath);
                if ($raw !== false && $raw !== '') {
                    $dec = @json_decode($raw, true);
                    if (is_array($dec)) {
                        $existing = $dec;
                    }
                }
            }

            // Dedup by id
            foreach ($existing as $entry) {
                if (($entry['id'] ?? '') === $tradeId) {
                    return; // already recorded
                }
            }

            $existing[] = $trade;

            // Trim to last 1 000 entries (oldest first, keep newest)
            if (count($existing) > 1000) {
                $existing = array_slice($existing, -1000);
            }

            $this->writeJson($aggRelPath, $existing);

        } catch (\Throwable) {
            // Never crash the bot tick over journal write failures
        }
    }
}
