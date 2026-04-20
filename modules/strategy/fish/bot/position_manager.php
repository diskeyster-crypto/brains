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
            'owner_strategy'      => 'fish',
            'owner_signal_id'     => $signalId,
            'owner_order_id'      => $orderRecord['fish_order_id'] ?? '',
            'owner_config_snapshot' => $orderRecord['owner_config_snapshot'] ?? '',
            'fish_position_id'    => $fishPositionId,
            'exchange_position_id' => $exchangePositionId,

            // Trade details
            'symbol'              => $symbol,
            'side'                => $side,
            'entry_price'         => $orderRecord['entry_price']       ?? 0.0,
            'stop_price'          => $orderRecord['stop_price']        ?? 0.0,
            'take_profit_price'   => $orderRecord['take_profit_price'] ?? 0.0,
            'breakeven_trigger'   => $orderRecord['breakeven_trigger'] ?? 0.0,
            'qty'                 => $orderRecord['qty']               ?? 0.0,
            'leverage'            => $orderRecord['leverage']          ?? 1,
            'rr_ratio'            => $orderRecord['rr_ratio']          ?? 0.0,

            // State
            'status'              => 'open',
            'sl_tp_attached'      => false,
            'breakeven_reached'   => false,
            'opened_at'           => date('c'),
            'updated_at'          => date('c'),
        ];

        $this->store->upsertPosition($position);
        $this->journal->positionOpened($fishPositionId, $symbol, $side, $signalId);

        // Immediately attach SL/TP
        $attached = $this->slManager->attachInitialSlTp($position);
        if ($attached) {
            $position['sl_tp_attached'] = true;
            $position['updated_at']     = date('c');
            $this->store->upsertPosition($position);
        }

        return $position;
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
        $summary   = ['checked' => 0, 'breakeven_triggered' => 0, 'closed' => 0];

        foreach ($positions as $i => $position) {
            if (($position['owner_strategy'] ?? '') !== 'fish') {
                continue;   // Never touch non-Fish positions
            }
            if (($position['status'] ?? '') !== 'open') {
                continue;
            }

            $summary['checked']++;

            // Re-attach SL/TP if not yet attached
            if (!($position['sl_tp_attached'] ?? false)) {
                $attached = $this->slManager->attachInitialSlTp($position);
                if ($attached) {
                    $positions[$i]['sl_tp_attached'] = true;
                    $positions[$i]['updated_at']     = date('c');
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
