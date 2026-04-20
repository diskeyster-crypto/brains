<?php

declare(strict_types=1);

namespace Modules\Strategy\Fish\Bot;

/**
 * Fish Bot — Journal
 *
 * Append-only, compact execution log.  Each entry is one JSON line.
 * The journal is purely for human operators / debugging; nothing reads it
 * at runtime.
 *
 * Log path: modules/strategy/fish/logs/bot_journal.log
 * Each line format:
 *   {"ts":"<ISO-8601>","event":"<name>","data":{...}}
 */
final class FishBotJournal
{
    private string $logPath;

    public function __construct(string $moduleDir)
    {
        $logsDir = rtrim($moduleDir, '/') . '/logs';
        if (!is_dir($logsDir)) {
            @mkdir($logsDir, 0755, true);
        }
        $this->logPath = $logsDir . '/bot_journal.log';
    }

    // -------------------------------------------------------------------------
    // Named event helpers
    // -------------------------------------------------------------------------

    public function signalConsumed(string $signalId, string $symbol, string $side): void
    {
        $this->append('signal_consumed', [
            'signal_id' => $signalId,
            'symbol'    => $symbol,
            'side'      => $side,
        ]);
    }

    public function intentCreated(string $signalId, string $symbol, float $entryPrice, string $mode): void
    {
        $this->append('intent_created', [
            'signal_id'   => $signalId,
            'symbol'      => $symbol,
            'entry_price' => $entryPrice,
            'mode'        => $mode,
        ]);
    }

    public function orderAttempt(string $signalId, string $symbol, array $params): void
    {
        $this->append('order_attempt', [
            'signal_id' => $signalId,
            'symbol'    => $symbol,
            'params'    => $params,
        ]);
    }

    public function orderAccepted(string $signalId, string $fishOrderId, string $exchangeOrderId, bool $smoke): void
    {
        $this->append('order_accepted', [
            'signal_id'         => $signalId,
            'fish_order_id'     => $fishOrderId,
            'exchange_order_id' => $exchangeOrderId,
            'smoke'             => $smoke,
        ]);
    }

    public function orderRejected(string $signalId, string $reason, array $response = []): void
    {
        $this->append('order_rejected', [
            'signal_id' => $signalId,
            'reason'    => $reason,
            'ret_code'  => $response['ret_code']  ?? null,
            'ret_msg'   => $response['ret_msg']   ?? null,
        ]);
    }

    public function orderFilled(string $signalId, string $fishOrderId, string $exchangeOrderId, float $avgPrice, bool $smoke): void
    {
        $this->append('order_filled', [
            'signal_id'         => $signalId,
            'fish_order_id'     => $fishOrderId,
            'exchange_order_id' => $exchangeOrderId,
            'avg_price'         => $avgPrice,
            'smoke'             => $smoke,
        ]);
    }

    public function orderCancelled(string $signalId, string $fishOrderId, string $finalStatus): void
    {
        $this->append('order_cancelled', [
            'signal_id'      => $signalId,
            'fish_order_id'  => $fishOrderId,
            'final_status'   => $finalStatus,
        ]);
    }

    public function slTpAttachFailed(string $fishPositionId, string $reason, int $attempts): void
    {
        $this->append('sl_tp_attach_failed', [
            'fish_position_id' => $fishPositionId,
            'reason'           => $reason,
            'attempts'         => $attempts,
        ]);
    }

    public function positionOpened(string $fishPositionId, string $symbol, string $side, string $ownerSignalId): void
    {
        $this->append('position_opened', [
            'fish_position_id' => $fishPositionId,
            'symbol'           => $symbol,
            'side'             => $side,
            'owner_signal_id'  => $ownerSignalId,
            'owner_strategy'   => 'fish',
        ]);
    }

    public function slTpAttached(string $fishPositionId, float $stopPrice, float $tpPrice): void
    {
        $this->append('sl_tp_attached', [
            'fish_position_id' => $fishPositionId,
            'stop_price'       => $stopPrice,
            'take_profit_price' => $tpPrice,
        ]);
    }

    public function breakevenTriggered(string $fishPositionId, float $triggerPrice, float $newStop): void
    {
        $this->append('breakeven_triggered', [
            'fish_position_id' => $fishPositionId,
            'trigger_price'    => $triggerPrice,
            'new_stop'         => $newStop,
        ]);
    }

    public function executionError(string $signalId, string $message, array $context = []): void
    {
        $this->append('execution_error', [
            'signal_id' => $signalId,
            'message'   => $message,
            'context'   => $context,
        ]);
    }

    public function botTickStarted(string $mode, int $queueDepth, int $activeOrders, int $activePositions): void
    {
        $this->append('bot_tick_started', [
            'mode'             => $mode,
            'queue_depth'      => $queueDepth,
            'active_orders'    => $activeOrders,
            'active_positions' => $activePositions,
        ]);
    }

    public function botTickFinished(array $summary): void
    {
        $this->append('bot_tick_finished', $summary);
    }

    // -------------------------------------------------------------------------
    // Low-level writer
    // -------------------------------------------------------------------------

    public function append(string $event, array $data): void
    {
        $line = json_encode([
            'ts'    => date('c'),
            'event' => $event,
            'data'  => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

        @file_put_contents($this->logPath, $line, FILE_APPEND | LOCK_EX);
    }
}
