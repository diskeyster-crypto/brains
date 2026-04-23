<?php

declare(strict_types=1);

/**
 * Bot Module — Service
 *
 * Architecture split:
 *   strategy modules → find signals, each writes storage/bot_handoff_queue.json
 *   bot module       → autodiscovers strategies, reads operator overrides, owns
 *                      order_queue.json / active_orders.json
 *   profit manager   → separate, not implemented yet
 *
 * Control layer (this step):
 *   - strategy autodiscovery via manifest.json scan
 *   - strategy_registry.json — persisted registry of all discovered strategies
 *   - operator_overrides.json — compact per-strategy operator controls
 *   - bot consumes only operator-enabled strategies via the registry
 *
 * Bot queue lifecycle states:
 *   queued    — signal ingested, awaiting validation
 *   ready     — validated, bot-owned, ready for future execution
 *   expired   — signal TTL elapsed before execution
 *   withdrawn — signal disappeared from handoff before TTL
 *
 * Future states (not implemented here):
 *   submitted, active_order, active_position, rejected
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
            $this->moduleDir = rtrim(
                \Core\System\SystemPaths::instance()->get('bot'),
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
        if (!(bool)($config['enabled'] ?? false)) {
            // Bot disabled: write a truthful disabled last_run so the UI
            // can show the correct state without running any processing.
            $this->writeJson('storage/last_run.json', array_merge(
                $this->readJson('storage/last_run.json', []),
                [
                    'status'      => 'disabled',
                    'tick_at'     => date('c'),
                    'bot_enabled' => false,
                    'bot_mode'    => $config['mode'] ?? 'passive',
                ]
            ));
            return;
        }

        $tickAt = date('c');
        $tStart = microtime(true);

        // ── 1. Strategy discovery ─────────────────────────────────────────────
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
        $orderQueue      = $this->readJson('storage/order_queue.json', []);
        $activeOrders    = $this->readJson('storage/active_orders.json', []);
        $activePositions = $this->readJson('storage/active_positions.json', []);
        $stats           = array_merge($this->zeroStats(), $this->getStats());

        // ── 4. Process handoff → order queue ──────────────────────────────────
        $result     = $this->processHandoff($allSignals, $orderQueue, $config, $overrides, $tickAt);
        $orderQueue = $result['order_queue'];

        // ── 5. Update stats ───────────────────────────────────────────────────
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
        // Derived counts
        $stats['order_queue_total']      = $this->countByStatus($orderQueue, ['queued', 'ready']);
        $stats['active_orders_total']    = count($activeOrders);
        $stats['active_positions_total'] = count($activePositions);

        // ── 6. Persist ────────────────────────────────────────────────────────
        $this->writeJson('storage/order_queue.json',      $orderQueue);
        $this->writeJson('storage/active_orders.json',    $activeOrders);
        $this->writeJson('storage/active_positions.json', $activePositions);
        $this->writeJson('storage/stats.json',            $stats);

        $elapsed = round(microtime(true) - $tStart, 4);

        $lastRun = [
            'status'      => 'ok',
            'tick_at'     => $tickAt,
            'elapsed_sec' => $elapsed,
            'bot_enabled' => true,
            'bot_mode'    => $config['mode'] ?? 'passive',

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

            // Current queue state
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
     *
     * A directory is a bot-consumable strategy module when:
     *   - it has a manifest.json with category == 'strategy'
     *   - it has a storage/ subdirectory
     *
     * status: 'bot_ready'   — storage/bot_handoff_queue.json exists
     *         'discovered'  — valid module but no handoff queue file yet
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
        if (file_exists($manifestPath) && is_dir($dir . '/storage')) {
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

        // Handoff queue presence determines bot_ready status
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
            'status'             => $hasHandoff ? 'bot_ready' : 'discovered',
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
                    $item = $this->buildQueueItem($signal, $opOverrides, $tickAt);
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
                $item = $this->buildQueueItem($signal, $opOverrides, $tickAt);
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

    /**
     * Build a bot-owned queue item from a strategy handoff signal.
     *
     * Operator overrides (per-strategy) are applied here:
     *   - bot_budget   (> 0 overrides signal value)
     *   - bot_leverage (> 0 overrides signal value)
     *   - entry_mode   (applied by processHandoff before this call)
     *
     * Deep strategy internals (pattern thresholds, TTL, corridor config, etc.)
     * are NOT exposed here — they stay inside each strategy module.
     */
    private function buildQueueItem(array $signal, array $opOverrides, string $tickAt): array
    {
        $botBudget   = (float)($signal['bot_budget']   ?? 0.0);
        $botLeverage = (int)($signal['bot_leverage']   ?? 1);

        $opBudget   = (float)($opOverrides['bot_budget']   ?? 0.0);
        $opLeverage = (int)($opOverrides['bot_leverage']   ?? 0);
        if ($opBudget > 0.0) {
            $botBudget = $opBudget;
        }
        if ($opLeverage > 0) {
            $botLeverage = $opLeverage;
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
            'storage/last_run.json'           => [
                'status'                                   => 'never_run',
                'tick_at'                                  => null,
                'elapsed_sec'                              => 0,
                'bot_enabled'                              => false,
                'bot_mode'                                 => 'passive',
                'strategies_discovered_total'              => 0,
                'strategies_enabled_total'                 => 0,
                'strategies_disabled_total'                => 0,
                'handoff_sources_active_total'             => 0,
                'handoff_signals_processed'                => 0,
                'handoff_signals_ignored_disabled_strategy'=> 0,
                'order_queue_new_total'                    => 0,
                'order_queue_refreshed_total'              => 0,
                'order_queue_expired_total'                => 0,
                'order_queue_withdrawn_total'              => 0,
                'order_queue_total'                        => 0,
                'active_orders_count'                      => 0,
                'active_positions_count'                   => 0,
                'ticks_total'                              => 0,
                'handoff_signals_seen_total'               => 0,
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
