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
     * positionIdx is read from the position record (stored at open time from config).
     * Default 0 = one-way mode (matches Bybit new-account default and old bot default).
     * tpslMode 'Full' is required by Bybit V5 /v5/position/trading-stop.
     *
     * Returns a structured result array:
     *   ['success' => bool, 'error' => string|null, 'error_code' => int|null, 'mode_mismatch' => bool]
     *
     * @param  array  $position  Fish position record from store
     * @return array  Structured result
     */
    public function attachInitialSlTp(array $position): array
    {
        $symbol      = (string)($position['symbol']              ?? '');
        $side        = (string)($position['side']                ?? 'long');
        $stopPrice   = (float)($position['stop_price']           ?? 0.0);
        $tpPrice     = (float)($position['take_profit_price']    ?? 0.0);
        $posId       = (string)($position['fish_position_id']    ?? '');
        // Read from position record; default 0 = one-way mode (mirrors old bot config default)
        $positionIdx = (int)($position['position_idx']           ?? 0);
        $tpslMode    = (string)($position['tpsl_mode']           ?? 'Full');

        if ($symbol === '' || $stopPrice <= 0.0) {
            return ['success' => false, 'error' => 'missing symbol or stop_price', 'error_code' => null, 'mode_mismatch' => false];
        }

        $params = [
            'symbol'        => $symbol,
            'category'      => 'linear',
            'positionIdx'   => $positionIdx,
            'tpslMode'      => $tpslMode,
            'stopLoss'      => (string)$stopPrice,
        ];
        if ($tpPrice > 0.0) {
            $params['takeProfit'] = (string)$tpPrice;
        }

        $response = $this->exchange->setTradingStop($params);

        if ($response['success'] ?? false) {
            $this->journal->slTpAttached($posId, $stopPrice, $tpPrice);
            return ['success' => true, 'error' => null, 'error_code' => null, 'mode_mismatch' => false,
                    'diagnostics' => [
                        'symbol'                 => $symbol,
                        'side'                   => $side,
                        'position_idx_used'      => $positionIdx,
                        'tpsl_mode_used'         => $tpslMode,
                        'sl_tp_attach_result'    => 'ok',
                    ]];
        }

        $retCode   = (int)($response['ret_code']   ?? -1);
        $retMsg    = (string)($response['ret_msg']  ?? 'unknown');
        $errMsg    = 'attachInitialSlTp failed: ' . $retMsg . ' (retCode=' . $retCode . ')';

        // Detect position-mode mismatch (e.g. "position idx(2) not match position mode(0)")
        // so the caller can refresh the mode before retrying instead of blind hammering.
        $isModeError = $retCode === 130101
            || (stripos($retMsg, 'position idx') !== false && stripos($retMsg, 'position mode') !== false)
            || stripos($retMsg, 'not match position mode') !== false;

        $this->journal->executionError(
            $position['owner_signal_id'] ?? '',
            $errMsg,
            [
                'fish_position_id'       => $posId,
                'position_idx_used'      => $positionIdx,
                'tpsl_mode_used'         => $tpslMode,
                'ret_code'               => $retCode,
                'ret_msg'                => $retMsg,
                'mode_mismatch_detected' => $isModeError,
            ]
        );

        return [
            'success'      => false,
            'error'        => $errMsg,
            'error_code'   => $retCode,
            'mode_mismatch' => $isModeError,
            'diagnostics'  => [
                'symbol'                 => $symbol,
                'side'                   => $side,
                'position_idx_used'      => $positionIdx,
                'tpsl_mode_used'         => $tpslMode,
                'sl_tp_attach_result'    => 'failed',
                'sl_tp_attach_error_code' => $retCode,
                'sl_tp_attach_error_message' => $retMsg,
                'sl_tp_mode_mismatch_detected' => $isModeError,
            ],
        ];
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
        $positionIdx = (int)($position['position_idx'] ?? 0);
        $tpslMode    = (string)($position['tpsl_mode'] ?? 'Full');

        $params = [
            'symbol'      => $symbol,
            'category'    => 'linear',
            'positionIdx' => $positionIdx,
            'tpslMode'    => $tpslMode,
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
