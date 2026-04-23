<?php

declare(strict_types=1);

/**
 * Bot Module — Service
 *
 * Separate Bot module.  Reads the strategy handoff queue produced by
 * double_bottom_long and converts ready signals into a bot-owned order queue.
 *
 * Architecture split:
 *   strategy module  → finds signals, writes bot_handoff_queue.json
 *   bot module       → reads handoff queue, owns order_queue.json / active_orders.json
 *   profit manager   → separate, not implemented yet
 *
 * This step only covers:
 *   - handoff ingestion
 *   - queue/state preparation (no exchange execution)
 *   - lifecycle state tracking
 *   - explicit counters
 *
 * Bot queue lifecycle states used in this step:
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
        // Derive repo root as two levels above modules/bot
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
     * Reads the strategy handoff queue, updates the bot order queue,
     * and persists runtime state.
     */
    public function tick(): void
    {
        $config = $this->getConfig();
        if (empty($config)) {
            return;
        }
        if (!(bool)($config['enabled'] ?? false)) {
            return;
        }

        $tickAt  = date('c');
        $tStart  = microtime(true);

        // Load current state
        $handoffSignals  = $this->readHandoffQueue($config);
        $orderQueue      = $this->readJson('storage/order_queue.json', []);
        $activeOrders    = $this->readJson('storage/active_orders.json', []);
        $activePositions = $this->readJson('storage/active_positions.json', []);
        $stats           = array_merge($this->zeroStats(), $this->getStats());

        // Process handoff → order queue
        $result = $this->processHandoff($handoffSignals, $orderQueue, $config, $tickAt);

        $orderQueue = $result['order_queue'];

        // Update cumulative stats
        $stats['ticks_total']                += 1;
        $stats['handoff_signals_seen_total'] += $result['signals_seen'];
        $stats['order_queue_new_total']      += $result['new_total'];
        $stats['order_queue_refreshed_total']+= $result['refreshed_total'];
        $stats['order_queue_expired_total']  += $result['expired_total'];
        $stats['order_queue_withdrawn_total']+= $result['withdrawn_total'];

        // Derived counts
        $stats['order_queue_total']   = $this->countByStatus($orderQueue, ['queued', 'ready']);
        $stats['active_orders_total'] = count($activeOrders);
        $stats['active_positions_total'] = count($activePositions);

        // Persist
        $this->writeJson('storage/order_queue.json',    $orderQueue);
        $this->writeJson('storage/active_orders.json',  $activeOrders);
        $this->writeJson('storage/active_positions.json', $activePositions);
        $this->writeJson('storage/stats.json', $stats);

        $elapsed = round(microtime(true) - $tStart, 4);

        $lastRun = [
            'status'           => 'ok',
            'tick_at'          => $tickAt,
            'elapsed_sec'      => $elapsed,
            'bot_enabled'      => true,
            'bot_mode'         => $config['mode'] ?? 'passive',

            // Handoff source
            'handoff_source_strategy' => $config['handoff_source_strategy'] ?? 'double_bottom_long',
            'handoff_source_path'     => $config['handoff_source_path'] ?? '',
            'handoff_signals_processed' => $result['signals_seen'],

            // Tick result
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
        $this->writeRuntimeSnapshot($config, $lastRun);
    }

    // =========================================================================
    // Handoff ingestion
    // =========================================================================

    /**
     * Read the strategy handoff queue from the absolute or repo-relative path
     * stored in config.  Returns only signals with status 'new' or 'refreshed'.
     */
    private function readHandoffQueue(array $config): array
    {
        $relPath = (string)($config['handoff_source_path'] ?? '');
        if ($relPath === '') {
            return [];
        }

        // Support absolute paths and repo-relative paths
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

        // Only process signals that the strategy considers active/handoff-ready
        $ready = [];
        foreach ($decoded as $record) {
            $status = (string)($record['handoff_status'] ?? '');
            if (in_array($status, ['new', 'refreshed'], true)) {
                $ready[] = $record;
            }
        }
        return $ready;
    }

    /**
     * Merge handoff signals into the bot-owned order queue.
     *
     * Rules:
     *   - dedupe by signal_id
     *   - if signal already in queue as queued/ready: refresh it
     *   - if signal is new to the queue: add as queued
     *   - existing queue entries whose signal_id is no longer in handoff:
     *       mark expired (if past expires_at) or withdrawn
     *   - skip signals whose entry_mode is not in allowed_entry_modes
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
        string $tickAt
    ): array {
        $allowedModes = (array)($config['allowed_entry_modes'] ?? ['limit', 'market']);
        $maxAgeSec    = (int)($config['max_signal_age_sec'] ?? 0);

        // Build lookup of current queue by signal_id
        $queueMap = [];
        foreach ($orderQueue as $item) {
            $id = (string)($item['signal_id'] ?? '');
            if ($id !== '') {
                $queueMap[$id] = $item;
            }
        }

        $activeIds       = [];
        $newTotal        = 0;
        $refreshedTotal  = 0;
        $expiredTotal    = 0;
        $withdrawnTotal  = 0;
        $result          = [];

        // Process incoming handoff signals
        foreach ($handoffSignals as $signal) {
            $id = (string)($signal['signal_id'] ?? '');
            if ($id === '') {
                continue;
            }

            // Entry mode gate
            $entryMode = (string)($signal['entry_mode'] ?? 'limit');
            if (!in_array($entryMode, $allowedModes, true)) {
                continue;
            }

            // Age gate
            if ($maxAgeSec > 0) {
                $detectedTs = strtotime((string)($signal['detected_at'] ?? ''));
                if ($detectedTs !== false && (time() - $detectedTs) > $maxAgeSec) {
                    continue;
                }
            }

            $activeIds[$id] = true;

            if (isset($queueMap[$id])) {
                // Refresh existing queue item
                $prev = $queueMap[$id];
                $prevStatus = (string)($prev['queue_status'] ?? 'queued');

                // Only refresh active items; leave terminal items alone
                if (in_array($prevStatus, ['queued', 'ready'], true)) {
                    $item = $this->buildQueueItem($signal, $config, $tickAt);
                    $item['queue_status']   = 'ready';
                    $item['first_queued_at']= $prev['first_queued_at'] ?? $tickAt;
                    $item['seen_count']     = (int)($prev['seen_count'] ?? 1) + 1;
                    $item['last_refreshed_at'] = $tickAt;
                    $result[$id] = $item;
                    $refreshedTotal++;
                } else {
                    // Terminal item — keep as-is
                    $result[$id] = $prev;
                }
            } else {
                // New signal
                $item = $this->buildQueueItem($signal, $config, $tickAt);
                $item['queue_status']    = 'queued';
                $item['first_queued_at'] = $tickAt;
                $item['seen_count']      = 1;
                $item['last_refreshed_at'] = $tickAt;
                $result[$id] = $item;
                $newTotal++;
            }
        }

        // Handle queue items no longer in the handoff
        foreach ($queueMap as $id => $prev) {
            if (isset($result[$id])) {
                continue;
            }
            $prevStatus = (string)($prev['queue_status'] ?? 'queued');
            if (in_array($prevStatus, ['expired', 'withdrawn', 'submitted', 'active_order', 'active_position', 'rejected'], true)) {
                // Keep terminal/advanced items unchanged
                $result[$id] = $prev;
                continue;
            }
            // Determine exit cause
            $expiresAt = $prev['expires_at'] ?? '';
            $isExpired = $expiresAt !== '' && strtotime($expiresAt) !== false && time() > strtotime($expiresAt);
            $exitStatus = $isExpired ? 'expired' : 'withdrawn';
            $prev['queue_status']  = $exitStatus;
            $prev['exit_at']       = $tickAt;
            $result[$id] = $prev;
            if ($isExpired) {
                $expiredTotal++;
            } else {
                $withdrawnTotal++;
            }
        }

        return [
            'order_queue'     => array_values($result),
            'signals_seen'    => count($handoffSignals),
            'new_total'       => $newTotal,
            'refreshed_total' => $refreshedTotal,
            'expired_total'   => $expiredTotal,
            'withdrawn_total' => $withdrawnTotal,
        ];
    }

    /**
     * Build a bot-owned queue item from a strategy handoff signal.
     *
     * Carries the full strategy contract (read-only ownership fields) plus
     * bot-level queue state.  No exchange-order fields yet.
     */
    private function buildQueueItem(array $signal, array $config, string $tickAt): array
    {
        return [
            // Strategy ownership — read from handoff contract, never overridden
            'owner_strategy'  => (string)($signal['owner_strategy'] ?? 'double_bottom_long'),
            'strategy_id'     => (string)($signal['strategy_id']    ?? 'double_bottom_long'),
            'signal_id'       => (string)($signal['signal_id']      ?? ''),

            // Signal identity
            'symbol'    => (string)($signal['symbol']    ?? ''),
            'side'      => (string)($signal['side']      ?? 'long'),
            'timeframe' => (string)($signal['timeframe'] ?? 'H4'),

            // Entry geometry
            'entry_mode'  => (string)($signal['entry_mode']  ?? 'limit'),
            'entry_type'  => (string)($signal['entry_type']  ?? 'breakout'),
            'entry_price' => (float)($signal['entry_price']  ?? 0.0),

            // Freshness
            'detected_at' => (string)($signal['detected_at'] ?? $tickAt),
            'expires_at'  => (string)($signal['expires_at']  ?? ''),

            // Execution parameters (strategy-provided, bot reads)
            'stop_mode'                     => (string)($signal['stop_mode']                     ?? 'fixed_from_liq_zone'),
            'stop_from_liq_buffer_value'    => (float)($signal['stop_from_liq_buffer_value']    ?? 0.002),
            'stop_from_liq_buffer_type'     => (string)($signal['stop_from_liq_buffer_type']    ?? 'percent'),
            'bot_budget'                    => (float)($signal['bot_budget']                    ?? 0.0),
            'bot_leverage'                  => (int)($signal['bot_leverage']                    ?? 1),
            'tp_enabled'                    => (bool)($signal['tp_enabled']                     ?? false),
            'tp_mode'                       => (string)($signal['tp_mode']                      ?? 'fixed_r'),
            'tp_value'                      => (float)($signal['tp_value']                      ?? 2.0),
            'reverse_pattern_close_enabled' => (bool)($signal['reverse_pattern_close_enabled']  ?? false),

            // Bot lifecycle state (overwritten by caller)
            'queue_status' => 'queued',
        ];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

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
            'handoff_signals_seen_total'  => 0,
            'order_queue_total'           => 0,
            'order_queue_new_total'       => 0,
            'order_queue_refreshed_total' => 0,
            'order_queue_expired_total'   => 0,
            'order_queue_withdrawn_total' => 0,
            'active_orders_total'         => 0,
            'active_positions_total'      => 0,
        ];
    }

    private function writeRuntimeSnapshot(array $config, array $lastRun): void
    {
        $snap = [
            'snapshot_at'      => date('c'),
            'bot_id'           => 'bot',
            'mode'             => $config['mode']    ?? 'passive',
            'enabled'          => $config['enabled'] ?? false,
            'tick_at'          => $lastRun['tick_at'] ?? null,
            'last_tick_result' => $lastRun['status']  ?? 'ok',
            // Handoff source
            'handoff_source_strategy' => $config['handoff_source_strategy'] ?? 'double_bottom_long',
            'handoff_source_path'     => $config['handoff_source_path']     ?? '',
            // Entry
            'allowed_entry_modes' => $config['allowed_entry_modes'] ?? ['limit', 'market'],
            // Execution caps
            'max_bot_budget'   => $config['max_bot_budget']   ?? 0.0,
            'max_bot_leverage' => $config['max_bot_leverage'] ?? 0,
            // Runtime truth
            'order_queue_total'      => $lastRun['order_queue_total']      ?? 0,
            'active_orders_count'    => $lastRun['active_orders_count']    ?? 0,
            'active_positions_count' => $lastRun['active_positions_count'] ?? 0,
            // Brain-compatible keys for discoverStrategyModules()
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
            " * Auto-written after each tick.\n",
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
