<?php

declare(strict_types=1);

namespace Modules\Strategy\Fish\Bot;

/**
 * Fish Bot — SL Manager (v1)
 *
 * Responsible for:
 *   1. Attaching initial stop-loss and take-profit to a newly opened position.
 *   2. Triggering breakeven: moving the stop to entry when price reaches
 *      the breakeven_trigger level.
 *
 * In v1 the sl_profile = 'default' implements a fixed initial stop derived
 * directly from the signal.  No trailing-stop or complex adjustment logic yet.
 */
final class FishSlManager
{
    private FishExchangeAdapter $exchange;
    private FishBotJournal      $journal;
    private string              $slProfile;

    public function __construct(
        FishExchangeAdapter $exchange,
        FishBotJournal      $journal,
        string              $slProfile = 'default'
    ) {
        $this->exchange  = $exchange;
        $this->journal   = $journal;
        $this->slProfile = $slProfile;
    }

    /**
     * Attach initial SL + TP to a position after it is confirmed open.
     *
     * @param  array  $position  Fish position record from store
     * @return bool   True on success (or smoke mode)
     */
    public function attachInitialSlTp(array $position): bool
    {
        $symbol    = (string)($position['symbol']              ?? '');
        $side      = (string)($position['side']                ?? 'long');
        $stopPrice = (float)($position['stop_price']           ?? 0.0);
        $tpPrice   = (float)($position['take_profit_price']    ?? 0.0);
        $posId     = (string)($position['fish_position_id']    ?? '');

        if ($symbol === '' || $stopPrice <= 0.0) {
            return false;
        }

        $bybitSide  = ($side === 'long') ? 'Buy' : 'Sell';
        $positionIdx = ($side === 'long') ? 1 : 2;

        $params = [
            'symbol'        => $symbol,
            'category'      => 'linear',
            'positionIdx'   => $positionIdx,
            'stopLoss'      => (string)$stopPrice,
        ];
        if ($tpPrice > 0.0) {
            $params['takeProfit'] = (string)$tpPrice;
        }

        $response = $this->exchange->setTradingStop($params);

        if ($response['success'] ?? false) {
            $this->journal->slTpAttached($posId, $stopPrice, $tpPrice);
            return true;
        }

        $this->journal->executionError(
            $position['owner_signal_id'] ?? '',
            'attachInitialSlTp failed: ' . ($response['ret_msg'] ?? 'unknown'),
            ['response' => $response, 'fish_position_id' => $posId]
        );

        return false;
    }

    /**
     * Evaluate breakeven condition for a position.
     * If the current mark price has reached or passed the breakeven trigger,
     * move the stop to entry price.
     *
     * @param  array  $position     Fish position record
     * @param  float  $currentPrice Current mark/last price
     * @return bool   True if breakeven was triggered and stop was moved
     */
    public function evaluateBreakeven(array $position, float $currentPrice): bool
    {
        $side              = (string)($position['side']               ?? 'long');
        $entryPrice        = (float)($position['entry_price']         ?? 0.0);
        $bevenTrigger      = (float)($position['breakeven_trigger']   ?? 0.0);
        $stopPrice         = (float)($position['stop_price']          ?? 0.0);
        $posId             = (string)($position['fish_position_id']   ?? '');

        // Already at breakeven (stop ≥ entry for long, stop ≤ entry for short)
        if ($side === 'long'  && $stopPrice >= $entryPrice) {
            return false;
        }
        if ($side === 'short' && $stopPrice <= $entryPrice) {
            return false;
        }

        // Check trigger
        $triggered = ($side === 'long')
            ? ($currentPrice >= $bevenTrigger && $bevenTrigger > 0.0)
            : ($currentPrice <= $bevenTrigger && $bevenTrigger > 0.0);

        if (!$triggered) {
            return false;
        }

        $symbol      = (string)($position['symbol']    ?? '');
        $positionIdx = ($side === 'long') ? 1 : 2;

        $params = [
            'symbol'      => $symbol,
            'category'    => 'linear',
            'positionIdx' => $positionIdx,
            'stopLoss'    => (string)$entryPrice,
        ];

        $response = $this->exchange->setTradingStop($params);

        if ($response['success'] ?? false) {
            $this->journal->breakevenTriggered($posId, $bevenTrigger, $entryPrice);
            return true;
        }

        $this->journal->executionError(
            $position['owner_signal_id'] ?? '',
            'breakevenTrigger failed: ' . ($response['ret_msg'] ?? 'unknown'),
            ['fish_position_id' => $posId]
        );

        return false;
    }
}
