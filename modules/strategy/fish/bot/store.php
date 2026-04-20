<?php

declare(strict_types=1);

namespace Modules\Strategy\Fish\Bot;

/**
 * Fish Bot — Storage
 *
 * All Fish-bot-specific JSON files live in modules/strategy/fish/storage/.
 * This class provides atomic read/write helpers that are isolated from the
 * scanner storage (signals.json, last_run.json, etc.).
 *
 * Files managed here:
 *   bot_active_orders.json      — open Fish-owned limit orders
 *   bot_active_positions.json   — open Fish-owned positions
 *   bot_execution_queue.json    — signals awaiting order placement
 *   bot_stats.json              — cumulative bot execution counters
 *   bot_last_run.json           — summary of the most recent bot tick
 */
final class FishBotStore
{
    private string $storageDir;

    public function __construct(string $moduleDir)
    {
        $this->storageDir = rtrim($moduleDir, '/') . '/storage';
    }

    // -------------------------------------------------------------------------
    // Generic helpers
    // -------------------------------------------------------------------------

    public function read(string $file): array
    {
        $path = $this->storageDir . '/' . $file;
        if (!file_exists($path)) {
            return [];
        }
        $raw  = file_get_contents($path);
        $data = json_decode((string)$raw, true);
        return is_array($data) ? $data : [];
    }

    public function write(string $file, array $data): void
    {
        @file_put_contents(
            $this->storageDir . '/' . $file,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"
        );
    }

    // -------------------------------------------------------------------------
    // Typed accessors
    // -------------------------------------------------------------------------

    public function readActiveOrders(): array
    {
        return $this->read('bot_active_orders.json');
    }

    public function writeActiveOrders(array $orders): void
    {
        $this->write('bot_active_orders.json', $orders);
    }

    public function readActivePositions(): array
    {
        return $this->read('bot_active_positions.json');
    }

    public function writeActivePositions(array $positions): void
    {
        $this->write('bot_active_positions.json', $positions);
    }

    public function readExecutionQueue(): array
    {
        return $this->read('bot_execution_queue.json');
    }

    public function writeExecutionQueue(array $queue): void
    {
        $this->write('bot_execution_queue.json', $queue);
    }

    public function readStats(): array
    {
        $defaults = [
            'strategy_id'              => 'fish',
            'total_bot_ticks'          => 0,
            'signals_queued_total'     => 0,
            'orders_attempted_total'   => 0,
            'orders_accepted_total'    => 0,
            'orders_rejected_total'    => 0,
            'positions_opened_total'   => 0,
            'positions_closed_total'   => 0,
            'execution_errors_total'   => 0,
            'last_tick_at'             => null,
            'last_error'               => null,
        ];
        $stored = $this->read('bot_stats.json');
        return array_merge($defaults, $stored);
    }

    public function writeStats(array $stats): void
    {
        $this->write('bot_stats.json', $stats);
    }

    public function readLastRun(): array
    {
        return $this->read('bot_last_run.json');
    }

    public function writeLastRun(array $summary): void
    {
        $this->write('bot_last_run.json', $summary);
    }

    // -------------------------------------------------------------------------
    // Queue helpers
    // -------------------------------------------------------------------------

    /**
     * Append a signal intent to the execution queue (deduped by signal_id).
     */
    public function enqueue(array $intent): void
    {
        $queue = $this->readExecutionQueue();
        $existing = array_column($queue, 'signal_id');
        if (in_array($intent['signal_id'], $existing, true)) {
            return;
        }
        $queue[] = $intent;
        $this->writeExecutionQueue($queue);
    }

    /**
     * Remove a signal intent from the queue by signal_id.
     */
    public function dequeue(string $signalId): void
    {
        $queue = $this->readExecutionQueue();
        $queue = array_values(array_filter($queue, fn($i) => ($i['signal_id'] ?? '') !== $signalId));
        $this->writeExecutionQueue($queue);
    }

    // -------------------------------------------------------------------------
    // Order helpers
    // -------------------------------------------------------------------------

    /**
     * Upsert an active order record (keyed by fish_order_id).
     */
    public function upsertOrder(array $order): void
    {
        $orders = $this->readActiveOrders();
        $idx    = null;
        foreach ($orders as $i => $o) {
            if (($o['fish_order_id'] ?? '') === ($order['fish_order_id'] ?? '')) {
                $idx = $i;
                break;
            }
        }
        if ($idx !== null) {
            $orders[$idx] = $order;
        } else {
            $orders[] = $order;
        }
        $this->writeActiveOrders(array_values($orders));
    }

    /**
     * Remove an order record by fish_order_id.
     */
    public function removeOrder(string $fishOrderId): void
    {
        $orders = $this->readActiveOrders();
        $orders = array_values(array_filter($orders, fn($o) => ($o['fish_order_id'] ?? '') !== $fishOrderId));
        $this->writeActiveOrders($orders);
    }

    // -------------------------------------------------------------------------
    // Position helpers
    // -------------------------------------------------------------------------

    /**
     * Upsert an active position record (keyed by fish_position_id).
     */
    public function upsertPosition(array $position): void
    {
        $positions = $this->readActivePositions();
        $idx       = null;
        foreach ($positions as $i => $p) {
            if (($p['fish_position_id'] ?? '') === ($position['fish_position_id'] ?? '')) {
                $idx = $i;
                break;
            }
        }
        if ($idx !== null) {
            $positions[$idx] = $position;
        } else {
            $positions[] = $position;
        }
        $this->writeActivePositions(array_values($positions));
    }

    /**
     * Remove a position record by fish_position_id.
     */
    public function removePosition(string $fishPositionId): void
    {
        $positions = $this->readActivePositions();
        $positions = array_values(array_filter(
            $positions,
            fn($p) => ($p['fish_position_id'] ?? '') !== $fishPositionId
        ));
        $this->writeActivePositions($positions);
    }
}
