<?php

declare(strict_types=1);

namespace Modules\Strategy\Fish\Bot;

/**
 * Fish Bot — Order Builder
 *
 * Converts a Fish signal object into an internal order contract that mirrors
 * the old trading_bot buildOrder() format.  This internal contract is then
 * normalized into Bybit API params by FishExchangeAdapter::submitOrder(),
 * exactly as trading_bot/gateway.php::submitOrder() does.
 *
 * Internal contract fields (snake_case, matches old bot):
 *   symbol          — exchange symbol
 *   side            — 'Buy' | 'Sell'  (Bybit-style, already mapped)
 *   order_type      — 'limit' (lowercase; normalizer applies ucfirst)
 *   qty             — float (normalizer applies qtyStep floor)
 *   price           — float (limit price)
 *   time_in_force   — 'GTC'
 *   reduce_only     — false
 *   order_link_id   — 'fish_' + up to 31 chars of signal_id (max 36 total)
 *   signal_id       — Fish signal_id (internal, stripped before Bybit submit)
 *   created_at      — ISO8601 timestamp (internal, stripped before Bybit submit)
 *   _fish_meta      — Fish ownership/SL/TP metadata (internal, stripped before submit)
 *
 * Only limit entry orders are built in v1.  SL/TP are stored in _fish_meta
 * and attached to the position by sl_manager / pm_manager after fill.
 */
final class FishOrderBuilder
{
    /**
     * Build the internal Fish order contract from a Fish signal.
     *
     * @param  array  $signal  Signal from signals.json
     * @param  array  $config  Active Fish config
     * @return array  Internal order contract ready for FishExchangeAdapter::submitOrder()
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

        // Bybit side: "Buy" for long, "Sell" for short (same as old buildOrder())
        $bybitSide = ($side === 'long') ? 'Buy' : 'Sell';

        // Raw quantity before normalizeQty (adapter will floor to qtyStep)
        $qty = ($entryPrice > 0 && $budget > 0)
            ? ($budget * $leverage / $entryPrice)
            : 0.0;

        // order_link_id: 'fish_' + up to 31 safe chars of signal_id (total max 36)
        $linkId = 'fish_' . substr(preg_replace('/[^a-z0-9_]/', '_', $signalId), 0, 31);

        // Internal contract — snake_case, matches trading_bot buildOrder() shape
        return [
            'symbol'         => $symbol,
            'side'           => $bybitSide,
            'order_type'     => 'limit',      // lowercase; normalizer does ucfirst
            'qty'            => $qty,          // float; normalizer floors to qtyStep
            'price'          => $entryPrice,   // float
            'time_in_force'  => 'GTC',         // same as old bot
            'reduce_only'    => false,
            'order_link_id'  => $linkId,       // snake_case; normalizer maps to orderLinkId
            'signal_id'      => $signalId,     // internal only, stripped before Bybit submit
            'created_at'     => date('c'),     // internal only, stripped before Bybit submit
            // Fish-specific metadata — internal, stripped before Bybit submit
            '_fish_meta'     => [
                'owner_strategy'        => 'fish',
                'owner_signal_id'       => $signalId,
                'owner_config_snapshot' => $signal['config_snapshot_id'] ?? '',
                'stop_price'            => $stopPrice,
                'take_profit_price'     => $tpPrice,
                'breakeven_trigger'     => (float)($signal['breakeven_trigger'] ?? 0.0),
                'rr_ratio'              => (float)($signal['rr_ratio'] ?? 0.0),
                'leverage'              => $leverage,
                'budget'                => $budget,
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
