<?php

declare(strict_types=1);

namespace Modules\Strategy\Fish\Bot;

/**
 * Fish Bot — Order Builder
 *
 * Converts a Fish signal object into a Bybit /v5/order/create parameter map.
 * All fields are derived from the signal and Fish config — nothing is hardcoded.
 *
 * Ownership fields injected on every order:
 *   orderLinkId   — 'fish_' + first 27 chars of signal_id (exchange-visible)
 *   tag           — 'fish_bot' (Bybit order-link tag, 16-char max)
 *
 * Only limit entry orders are built in v1.  Stop/TP are stored as metadata
 * and attached to the position by sl_manager / pm_manager after fill.
 */
final class FishOrderBuilder
{
    /**
     * Build Bybit order creation parameters from a Fish signal.
     *
     * @param  array  $signal  Signal from signals.json
     * @param  array  $config  Active Fish config
     * @return array  Parameter map ready for FishExchangeAdapter::placeOrder()
     */
    public function build(array $signal, array $config): array
    {
        $symbol     = (string)($signal['symbol']      ?? '');
        $side       = (string)($signal['side']        ?? 'long');
        $entryPrice = (float)($signal['entry_price']  ?? 0.0);
        $stopPrice  = (float)($signal['stop_price']   ?? 0.0);
        $tpPrice    = (float)($signal['take_profit_price'] ?? 0.0);
        $signalId   = (string)($signal['signal_id']   ?? '');

        $budget   = (float)($config['bot_budget']   ?? 0.0);
        $leverage = (int)($config['bot_leverage']   ?? 1);

        // Bybit side: "Buy" for long, "Sell" for short
        $bybitSide = ($side === 'long') ? 'Buy' : 'Sell';

        // Quantity: budget / entry_price (rounded to 3 dp — adjust per symbol if needed)
        $qty = ($entryPrice > 0 && $budget > 0)
            ? round($budget * $leverage / $entryPrice, 3)
            : 0.0;

        // orderLinkId: 'fish_' + up to 31 chars from signal_id (total max 36)
        $linkId = 'fish_' . substr(preg_replace('/[^a-z0-9_]/', '_', $signalId), 0, 31);

        return [
            'category'    => 'linear',
            'symbol'      => $symbol,
            'side'        => $bybitSide,
            'orderType'   => 'Limit',
            'qty'         => (string)$qty,
            'price'       => (string)$entryPrice,
            'timeInForce' => 'GoodTillCancel',
            'orderLinkId' => $linkId,
            // Ownership / traceability fields
            '_fish_meta'  => [
                'owner_strategy'      => 'fish',
                'owner_signal_id'     => $signalId,
                'owner_config_snapshot' => $signal['config_snapshot_id'] ?? '',
                'stop_price'          => $stopPrice,
                'take_profit_price'   => $tpPrice,
                'breakeven_trigger'   => (float)($signal['breakeven_trigger'] ?? 0.0),
                'rr_ratio'            => (float)($signal['rr_ratio'] ?? 0.0),
                'leverage'            => $leverage,
                'budget'              => $budget,
            ],
        ];
    }

    /**
     * Extract a stable Fish order ID from the signal_id.
     * Used as the primary key in bot_active_orders.json.
     */
    public function fishOrderId(string $signalId): string
    {
        return 'fo_' . substr(md5($signalId), 0, 16);
    }
}
