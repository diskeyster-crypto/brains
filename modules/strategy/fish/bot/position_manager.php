<?php

declare(strict_types=1);

namespace Modules\Strategy\Fish\Bot;

/**
 * Fish Bot — Position Manager (v1)
 *
 * Handles Fish-owned position tracking and management.
 *
 * Responsibilities:
 *   1. Reconcile bot_active_positions with exchange state (or smoke state).
 *   2. Mark positions that have been filled from pending orders.
 *   3. Attach SL/TP on newly opened positions (delegates to FishSlManager).
 *   4. Evaluate breakeven condition on each open position.
 *   5. Close / archive Fish positions that are no longer active.
 *
 * In v1 the pm_profile = 'default' applies breakeven-only management.
 * No complex trailing, partial-close, or re-entry logic yet.
 */
final class FishPositionManager
{
    private FishExchangeAdapter $exchange;
    private FishBotStore        $store;
    private FishBotJournal      $journal;
    private FishSlManager       $slManager;
    private string              $pmProfile;

    public function __construct(
        FishExchangeAdapter $exchange,
        FishBotStore        $store,
        FishBotJournal      $journal,
        FishSlManager       $slManager,
        string              $pmProfile = 'default'
    ) {
        $this->exchange  = $exchange;
        $this->store     = $store;
        $this->journal   = $journal;
        $this->slManager = $slManager;
        $this->pmProfile = $pmProfile;
    }

    /**
     * Register a newly opened Fish position.
     *
     * Called after an order fills (confirmed by exchange or smoke mode).
     *
     * @param  array  $orderRecord  The Fish active-order record
     * @param  string $exchangePositionId  Exchange-assigned position ID (or smoke placeholder)
     * @return array  The created Fish position record
     */
    public function openPosition(array $orderRecord, string $exchangePositionId = ''): array
    {
        $signalId = (string)($orderRecord['owner_signal_id'] ?? '');
        $symbol   = (string)($orderRecord['symbol']          ?? '');
        $side     = (string)($orderRecord['side']            ?? 'long');

        $fishPositionId = 'fp_' . substr(md5($signalId . '_pos'), 0, 16);

        $position = [
            // Ownership
            'owner_strategy'        => 'fish',
            'owner_signal_id'       => $signalId,
            'owner_order_id'        => $orderRecord['fish_order_id']      ?? '',
            'owner_config_snapshot' => $orderRecord['owner_config_snapshot'] ?? '',
            'fish_position_id'      => $fishPositionId,
            'exchange_position_id'  => $exchangePositionId,

            // Trade details — prefer actual fill price when available
            'symbol'                => $symbol,
            'side'                  => $side,
            'entry_price'           => $orderRecord['fill_avg_price'] ?? $orderRecord['entry_price'] ?? 0.0,
            'stop_price'            => $orderRecord['stop_price']        ?? 0.0,
            'take_profit_price'     => $orderRecord['take_profit_price'] ?? 0.0,
            'breakeven_trigger'     => $orderRecord['breakeven_trigger'] ?? 0.0,
            'qty'                   => $orderRecord['qty']               ?? 0.0,
            'leverage'              => $orderRecord['leverage']          ?? 1,
            'rr_ratio'              => $orderRecord['rr_ratio']          ?? 0.0,
            'smoke'                 => $orderRecord['smoke']             ?? false,
            'execution_mode'        => $orderRecord['execution_mode']    ?? 'smoke',

            // State
            'status'                => 'open',
            'sl_tp_attached'        => false,
            'sl_tp_attach_attempts' => 0,
            'sl_tp_last_error'      => null,
            'breakeven_reached'     => false,
            'opened_at'             => date('c'),
            'updated_at'            => date('c'),
        ];

        $this->store->upsertPosition($position);
        $this->journal->positionOpened($fishPositionId, $symbol, $side, $signalId);

        // Immediately attempt SL/TP attach (attempt 1)
        $position['sl_tp_attach_attempts'] = 1;
        $attached = $this->slManager->attachInitialSlTp($position);
        if ($attached) {
            $position['sl_tp_attached']  = true;
            $position['sl_tp_last_error'] = null;
            $position['updated_at']      = date('c');
        } else {
            $position['sl_tp_last_error'] = 'attachInitialSlTp failed on open (attempt 1)';
            $position['updated_at']       = date('c');
            $this->journal->slTpAttachFailed($fishPositionId, 'attachInitialSlTp failed on open', 1);
        }
        $this->store->upsertPosition($position);

        return $position;
    }

    /**
     * Inspect Fish-owned open orders and detect fills.
     *
     * For each Fish-owned order with status 'open' (current execution mode only):
     *   - Smoke mode: auto-fills — smoke orders have no exchange lifecycle.
     *   - Live mode: queries /v5/order/history for the exchange status.
     *
     * On Filled:
     *   - Updates order status to 'filled', records avg_price and filled_at.
     *   - Calls openPosition() → creates position + attaches SL/TP immediately.
     *
     * On Cancelled / Rejected / Expired / PartiallyFilledCanceled / Deactivated:
     *   - Updates order status to the lower-cased terminal state.
     *   - Does NOT create a position.
     *
     * On still-open statuses (New, PartiallyFilled, Untouched):
     *   - Records last_known_status; leaves status as 'open' for next tick.
     *
     * @return array {orders_checked, orders_filled, orders_cancelled, positions_opened}
     */
    public function detectAndProcessFills(): array
    {
        $mode    = $this->exchange->getExecutionMode();
        $orders  = $this->store->readActiveOrders();
        $changed = false;

        $summary = [
            'orders_checked'   => 0,
            'orders_filled'    => 0,
            'orders_cancelled' => 0,
            'positions_opened' => 0,
        ];

        // Only inspect orders that belong to the current execution mode (smoke vs live isolation)
        $isCurrentMode = ($mode === 'live')
            ? fn($o) => !(bool)($o['smoke'] ?? false)
            : fn($o) => (bool)($o['smoke'] ?? false);

        foreach ($orders as $i => $order) {
            if (($order['owner_strategy'] ?? '') !== 'fish') {
                continue;
            }
            if (($order['status'] ?? '') !== 'open') {
                continue;
            }
            if (!$isCurrentMode($order)) {
                continue;
            }

            $summary['orders_checked']++;

            $symbol          = (string)($order['symbol']            ?? '');
            $exchangeOrderId = (string)($order['exchange_order_id'] ?? '');
            $fishOrderId     = (string)($order['fish_order_id']     ?? '');
            $signalId        = (string)($order['owner_signal_id']   ?? '');

            $statusResult = $this->exchange->getOrderStatus($symbol, $exchangeOrderId);

            if (!($statusResult['success'] ?? false)) {
                // Could not query status — leave open, will retry next tick
                $orders[$i]['status_check_error'] = $statusResult['error'] ?? 'unknown';
                $orders[$i]['updated_at']         = date('c');
                $changed = true;
                continue;
            }

            $exchangeStatus = $statusResult['status']    ?? 'Unknown';
            $avgPrice       = (float)($statusResult['avg_price'] ?? 0.0);
            $isSmoke        = (bool)($statusResult['smoke']      ?? false);

            if ($exchangeStatus === 'Filled') {
                // Transition: filled → open position
                $orders[$i]['status']         = 'filled';
                $orders[$i]['fill_avg_price'] = $avgPrice;
                $orders[$i]['filled_at']      = date('c');
                $orders[$i]['updated_at']     = date('c');
                $changed = true;

                $this->journal->orderFilled($signalId, $fishOrderId, $exchangeOrderId, $avgPrice, $isSmoke);
                $this->openPosition($orders[$i], '');

                $summary['orders_filled']++;
                $summary['positions_opened']++;

            } elseif (in_array($exchangeStatus, [
                'Cancelled', 'Rejected', 'Expired', 'PartiallyFilledCanceled', 'Deactivated',
            ], true)) {
                $orders[$i]['status']     = strtolower($exchangeStatus);
                $orders[$i]['updated_at'] = date('c');
                $changed = true;

                $this->journal->orderCancelled($signalId, $fishOrderId, $exchangeStatus);
                $summary['orders_cancelled']++;

            } else {
                // New, PartiallyFilled, Untouched — still open; record for diagnostics
                $orders[$i]['last_known_status'] = $exchangeStatus;
                $orders[$i]['updated_at']        = date('c');
                $changed = true;
            }
        }

        if ($changed) {
            $this->store->writeActiveOrders(array_values($orders));
        }

        return $summary;
    }

    /**
     * Run one PM tick across all open Fish positions.
     * In smoke mode this only evaluates state — no exchange calls beyond
     * what the exchange_adapter intercepts.
     *
     * @param  float  $defaultMarkPrice  Fallback price when live data unavailable
     * @return array  Summary of actions taken
     */
    public function tick(float $defaultMarkPrice = 0.0): array
    {
        $positions = $this->store->readActivePositions();
        $summary   = [
            'checked'             => 0,
            'breakeven_triggered' => 0,
            'closed'              => 0,
            'sltp_attached'       => 0,
            'sltp_attach_failed'  => 0,
            'missing_sltp'        => 0,
        ];

        foreach ($positions as $i => $position) {
            if (($position['owner_strategy'] ?? '') !== 'fish') {
                continue;   // Never touch non-Fish positions
            }
            if (($position['status'] ?? '') !== 'open') {
                continue;
            }

            $summary['checked']++;

            // Re-attach SL/TP if not yet attached (controlled retry — tracked per position)
            if (!($position['sl_tp_attached'] ?? false)) {
                $summary['missing_sltp']++;
                $attempts = (int)($position['sl_tp_attach_attempts'] ?? 0) + 1;
                $attached = $this->slManager->attachInitialSlTp($position);
                if ($attached) {
                    $positions[$i]['sl_tp_attached']        = true;
                    $positions[$i]['sl_tp_attach_attempts'] = $attempts;
                    $positions[$i]['sl_tp_last_error']      = null;
                    $positions[$i]['updated_at']            = date('c');
                    $summary['sltp_attached']++;
                } else {
                    $positions[$i]['sl_tp_attach_attempts'] = $attempts;
                    $positions[$i]['sl_tp_last_error']      = 'attachInitialSlTp retry #' . $attempts . ' failed';
                    $positions[$i]['updated_at']            = date('c');
                    $this->journal->slTpAttachFailed(
                        $position['fish_position_id'] ?? '',
                        'retry #' . $attempts . ' failed',
                        $attempts
                    );
                    $summary['sltp_attach_failed']++;
                }
            }

            // Breakeven evaluation
            if (!($position['breakeven_reached'] ?? false)) {
                $currentPrice = $defaultMarkPrice > 0.0 ? $defaultMarkPrice : (float)($position['entry_price'] ?? 0.0);
                $triggered    = $this->slManager->evaluateBreakeven($position, $currentPrice);
                if ($triggered) {
                    $positions[$i]['breakeven_reached'] = true;
                    $positions[$i]['stop_price']        = $position['entry_price'] ?? 0.0;
                    $positions[$i]['updated_at']        = date('c');
                    $summary['breakeven_triggered']++;
                }
            }
        }

        $this->store->writeActivePositions(array_values($positions));

        return $summary;
    }

    /**
     * Mark a Fish position as closed.
     */
    public function closePosition(string $fishPositionId, string $reason = 'manual'): void
    {
        $positions = $this->store->readActivePositions();
        foreach ($positions as $i => $p) {
            if (($p['fish_position_id'] ?? '') === $fishPositionId) {
                $positions[$i]['status']     = 'closed';
                $positions[$i]['close_reason'] = $reason;
                $positions[$i]['closed_at']  = date('c');
                $positions[$i]['updated_at'] = date('c');
                break;
            }
        }
        $this->store->writeActivePositions(array_values($positions));
    }
}
