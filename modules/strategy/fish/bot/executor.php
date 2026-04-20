<?php

declare(strict_types=1);

namespace Modules\Strategy\Fish\Bot;

/**
 * Fish Bot — Executor (v1)
 *
 * Main bot tick: consumes the Fish execution queue and places limit orders on
 * Bybit through FishExchangeAdapter.
 *
 * Flow per tick:
 *   1. Load execution queue (bot_execution_queue.json)
 *   2. Enforce max_active_orders cap
 *   3. For each queued intent:
 *      a. Build Bybit order params (FishOrderBuilder)
 *      b. Attempt order placement via FishExchangeAdapter
 *      c. On acceptance — record in bot_active_orders.json, dequeue
 *      d. On rejection  — log + optionally remove from queue
 *   4. Run PM tick (FishPmManager) to manage open positions
 *   5. Update bot_stats.json and bot_last_run.json
 *
 * Ownership contract:
 *   Every order carries owner_strategy = 'fish'.
 *   Only Fish signals enter the queue.
 *   The executor never touches orders or positions it did not create.
 */
final class FishExecutor
{
    private FishExchangeAdapter $exchange;
    private FishBotStore        $store;
    private FishBotJournal      $journal;
    private FishOrderBuilder    $orderBuilder;
    private FishPmManager       $pmManager;
    private array               $config;

    public function __construct(
        FishExchangeAdapter $exchange,
        FishBotStore        $store,
        FishBotJournal      $journal,
        FishOrderBuilder    $orderBuilder,
        FishPmManager       $pmManager,
        array               $config
    ) {
        $this->exchange     = $exchange;
        $this->store        = $store;
        $this->journal      = $journal;
        $this->orderBuilder = $orderBuilder;
        $this->pmManager    = $pmManager;
        $this->config       = $config;
    }

    /**
     * Execute one bot tick.
     *
     * @return array  Summary: intents_processed, orders_placed, orders_rejected, pm_summary, mode, tick_at
     */
    public function tick(): array
    {
        $tickAt     = date('c');
        $mode       = $this->exchange->getExecutionMode();
        $isLive     = ($mode === 'live');
        $maxOrders  = (int)($this->config['max_active_orders']    ?? 5);
        $maxPos     = (int)($this->config['max_active_positions'] ?? 3);

        $queue           = $this->store->readExecutionQueue();
        $activeOrders    = $this->store->readActiveOrders();
        $activePositions = $this->store->readActivePositions();

        // Mode-aware filtering: smoke orders must never count toward live caps and vice versa.
        // An order is "current-mode" if its smoke flag matches the current execution context.
        $isSmokeOrder    = fn($o) => (bool)($o['smoke'] ?? false);
        $isLiveOrder     = fn($o) => !(bool)($o['smoke'] ?? false);
        $isSmokePosition = fn($p) => (bool)($p['smoke'] ?? false);
        $isLivePosition  = fn($p) => !(bool)($p['smoke'] ?? false);

        $relevantOrder = $isLive ? $isLiveOrder : $isSmokeOrder;
        $relevantPos   = $isLive ? $isLivePosition : $isSmokePosition;

        $openOrdersTotal    = count(array_filter($activeOrders,    fn($o) => ($o['status'] ?? '') === 'open'));
        $openOrdersCurrent  = count(array_filter($activeOrders,    fn($o) => ($o['status'] ?? '') === 'open' && $relevantOrder($o)));
        $openOrdersSmoke    = count(array_filter($activeOrders,    fn($o) => ($o['status'] ?? '') === 'open' && $isSmokeOrder($o)));
        $openOrdersLive     = count(array_filter($activeOrders,    fn($o) => ($o['status'] ?? '') === 'open' && $isLiveOrder($o)));

        $openPosTotal       = count(array_filter($activePositions, fn($p) => ($p['status'] ?? '') === 'open' && ($p['owner_strategy'] ?? '') === 'fish'));
        $openPosCurrent     = count(array_filter($activePositions, fn($p) => ($p['status'] ?? '') === 'open' && ($p['owner_strategy'] ?? '') === 'fish' && $relevantPos($p)));
        $openPosSmoke       = count(array_filter($activePositions, fn($p) => ($p['status'] ?? '') === 'open' && ($p['owner_strategy'] ?? '') === 'fish' && $isSmokePosition($p)));
        $openPosLive        = count(array_filter($activePositions, fn($p) => ($p['status'] ?? '') === 'open' && ($p['owner_strategy'] ?? '') === 'fish' && $isLivePosition($p)));

        // Stale cross-mode orders: smoke orders present while running live (and vice versa)
        $staleSmokeOrders = $isLive ? $openOrdersSmoke : 0;
        $staleSmokePosns  = $isLive ? $openPosSmoke    : 0;

        // Effective caps apply only to current-mode orders
        $openOrderCount    = $openOrdersCurrent;
        $openPositionCount = $openPosCurrent;

        $this->journal->botTickStarted($mode, count($queue), $openOrderCount, $openPositionCount);

        $intentsProcessed = 0;
        $ordersPlaced     = 0;
        $ordersRejected   = 0;
        $errors           = 0;

        foreach ($queue as $intent) {
            // Hard caps
            if ($openOrderCount >= $maxOrders) {
                break;
            }
            if ($openPositionCount >= $maxPos) {
                break;
            }

            $signalId = (string)($intent['signal_id'] ?? '');
            $symbol   = (string)($intent['symbol']    ?? '');
            $side     = (string)($intent['side']      ?? 'long');

            $intentsProcessed++;

            try {
                // Build order params
                $params = $this->orderBuilder->build($intent, $this->config);
                $fishMeta   = $params['_fish_meta'];
                unset($params['_fish_meta']);   // _fish_meta must not be sent to exchange

                $fishOrderId = $this->orderBuilder->fishOrderId($signalId);

                $this->journal->orderAttempt($signalId, $symbol, $params);

                // Submit order
                $response = $this->exchange->placeOrder($params);

                if ($response['success'] ?? false) {
                    $exchangeOrderId = (string)($response['result']['orderId'] ?? 'smoke_unknown');
                    $isSmoke         = (bool)($response['smoke'] ?? false);

                    // Record active order — tag with execution_mode for future mode-aware filtering
                    $orderRecord = [
                        'fish_order_id'         => $fishOrderId,
                        'exchange_order_id'     => $exchangeOrderId,
                        'owner_strategy'        => 'fish',
                        'owner_signal_id'       => $signalId,
                        'owner_config_snapshot' => $fishMeta['owner_config_snapshot'] ?? '',
                        'symbol'                => $symbol,
                        'side'                  => $side,
                        'entry_price'           => (float)($intent['entry_price']     ?? 0.0),
                        'stop_price'            => $fishMeta['stop_price']           ?? 0.0,
                        'take_profit_price'     => $fishMeta['take_profit_price']    ?? 0.0,
                        'breakeven_trigger'     => $fishMeta['breakeven_trigger']    ?? 0.0,
                        'rr_ratio'              => $fishMeta['rr_ratio']             ?? 0.0,
                        'leverage'              => $fishMeta['leverage']             ?? 1,
                        'budget'                => $fishMeta['budget']               ?? 0.0,
                        'qty'                   => (float)($params['qty']            ?? 0.0),
                        'status'                => 'open',
                        'smoke'                 => $isSmoke,
                        'execution_mode'        => $mode,
                        'placed_at'             => date('c'),
                        'updated_at'            => date('c'),
                    ];

                    $this->store->upsertOrder($orderRecord);
                    $this->store->dequeue($signalId);
                    $this->journal->orderAccepted($signalId, $fishOrderId, $exchangeOrderId, $isSmoke);

                    $openOrderCount++;
                    $ordersPlaced++;
                } else {
                    $reason = ($response['ret_msg'] ?? '') ?: ($response['error_type'] ?? 'api_rejected');
                    $this->journal->orderRejected($signalId, $reason, $response);
                    $this->store->dequeue($signalId);  // remove permanently-rejected intent
                    $ordersRejected++;
                }
            } catch (\Throwable $e) {
                $this->journal->executionError($signalId, $e->getMessage());
                $errors++;
            }
        }

        // PM tick (fill detection + position management)
        $pmSummary = $this->pmManager->tick();

        // Stats
        $stats = $this->store->readStats();
        $stats['total_bot_ticks']++;
        $stats['orders_attempted_total']    += $intentsProcessed;
        $stats['orders_accepted_total']     += $ordersPlaced;
        $stats['orders_rejected_total']     += $ordersRejected;
        $stats['orders_filled_total']       += (int)($pmSummary['orders_filled']     ?? 0);
        $stats['orders_cancelled_total']    += (int)($pmSummary['orders_cancelled']  ?? 0);
        $stats['positions_opened_total']    += (int)($pmSummary['positions_opened']  ?? 0);
        $stats['sltp_attach_success_total'] += (int)($pmSummary['sltp_attached']     ?? 0);
        $stats['sltp_attach_failed_total']  += (int)($pmSummary['sltp_attach_failed'] ?? 0);
        $stats['execution_errors_total']    += $errors;
        $stats['last_tick_at']               = $tickAt;
        if ($errors > 0) {
            $stats['last_error'] = 'Errors on tick ' . $tickAt;
        }
        $this->store->writeStats($stats);

        $summary = [
            'intents_processed'                  => $intentsProcessed,
            'orders_placed'                      => $ordersPlaced,
            'orders_rejected'                    => $ordersRejected,
            'execution_errors'                   => $errors,
            'pm_summary'                         => $pmSummary,
            'mode'                               => $mode,
            'tick_at'                            => $tickAt,
            // Mode-isolation diagnostics
            'active_orders_total'                => $openOrdersTotal,
            'active_orders_current_mode'         => $openOrdersCurrent,
            'active_orders_smoke'                => $openOrdersSmoke,
            'active_orders_live'                 => $openOrdersLive,
            'active_positions_total'             => $openPosTotal,
            'active_positions_current_mode'      => $openPosCurrent,
            'active_positions_smoke'             => $openPosSmoke,
            'active_positions_live'              => $openPosLive,
            'mode_isolated_caps_applied'         => true,
            'stale_smoke_orders_ignored'         => $staleSmokeOrders,
            'stale_smoke_positions_ignored'      => $staleSmokePosns,
            // Fill-detection / position / SL-TP diagnostics
            'active_orders_filled_this_tick'     => (int)($pmSummary['orders_filled']     ?? 0),
            'active_orders_cancelled_this_tick'  => (int)($pmSummary['orders_cancelled']  ?? 0),
            'positions_opened_this_tick'         => (int)($pmSummary['positions_opened']  ?? 0),
            'positions_missing_sltp_total'       => (int)($pmSummary['missing_sltp']      ?? 0),
            'sltp_attach_attempts_this_tick'     => (int)($pmSummary['sltp_attached']     ?? 0)
                                                  + (int)($pmSummary['sltp_attach_failed'] ?? 0),
            'sltp_attach_success_total'          => $stats['sltp_attach_success_total'],
            'sltp_attach_failed_total'           => $stats['sltp_attach_failed_total'],
            'last_fill_detect_result'            => [
                'orders_checked'   => (int)($pmSummary['orders_checked']   ?? 0),
                'orders_filled'    => (int)($pmSummary['orders_filled']    ?? 0),
                'orders_cancelled' => (int)($pmSummary['orders_cancelled'] ?? 0),
                'positions_opened' => (int)($pmSummary['positions_opened'] ?? 0),
            ],
        ];

        $this->store->writeLastRun($summary);
        $this->journal->botTickFinished($summary);

        return $summary;
    }
}
