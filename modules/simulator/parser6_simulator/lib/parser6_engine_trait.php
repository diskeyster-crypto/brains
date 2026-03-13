<?php

declare(strict_types=1);

/**
 * Parser6EngineTrait - Trade update, close, and PnL calculation
 * 
 * Core trading engine methods for updating active trades and closing them.
 */
trait Parser6EngineTrait
{
    /**
     * Update active trade with LIVE Bybit price
     */
    private function updateActiveTrade(array $trade, int $nowTs): array
    {
        $tradeId = $trade['trade_id'] ?? '';
        $symbol = $trade['symbol'] ?? '';
        $side = $trade['side'] ?? 'long';
        $entry = $trade['entry'] ?? [];
        $targets = $trade['targets'] ?? [];
        $trailing = $trade['trailing'] ?? [];
        $policy = $this->config['policy'] ?? [];
        $executionConfig = $this->config['execution'] ?? [];

        $opened = (bool)($entry['opened'] ?? false);
        $entryPrice = (float)($entry['entry_price_signal'] ?? 0);
        $openedPrice = (float)($entry['opened_price'] ?? $entryPrice);
        $strictEntry = (bool)($entry['strict_entry'] ?? true);
        $createdTs = (int)($trade['created_ts'] ?? $nowTs);
        $expiresAt = (int)($trade['expires_at'] ?? 0);

        $tp = $targets['take_profit'] ?? null;
        $slCurrent = $targets['stop_loss_current'] ?? null;

        $livePrice = $this->fetchLivePriceFromBybit($symbol);
        
        if ($livePrice === null) {
            $livePrice = (float)($trade['market']['last_price'] ?? 0);
            if ($livePrice <= 0) {
                return ['status' => 'no_price'];
            }
        }
        
        $tickTs = $nowTs;
        $tickPrice = $livePrice;

        if (!$opened) {
            if ($expiresAt > 0 && $tickTs >= $expiresAt) {
                $trade['entry_action_applied'] = 'rejected_expired_signal';
                return [
                    'status' => 'rejected',
                    'close_reason' => 'reject_expired_signal',
                    'closed_at' => date('c', $tickTs),
                    'entry_action_applied' => 'rejected_expired_signal',
                ];
            }
            
            $entryTimeoutDeadlineTs = (int)($trade['entry_timeout_deadline_ts'] ?? 0);
            if ($entryTimeoutDeadlineTs <= 0) {
                $entryTimeout = (int)($executionConfig['entry_timeout_minutes'] ?? 10) * 60;
                $entryTimeoutDeadlineTs = $createdTs + $entryTimeout;
            }
            
            if ($strictEntry && $tickTs > $entryTimeoutDeadlineTs) {
                $trade['entry_action_applied'] = 'timeout_wait_entry';
                return [
                    'status' => 'rejected',
                    'close_reason' => 'reject_entry_not_reached',
                    'closed_at' => date('c', $tickTs),
                    'entry_action_applied' => 'timeout_wait_entry',
                    'entry_timeout_deadline_ts' => $entryTimeoutDeadlineTs,
                ];
            }
            
            $shouldOpen = false;
            if ($strictEntry) {
                if ($side === 'long' && $tickPrice <= $entryPrice) {
                    $shouldOpen = true;
                } elseif ($side === 'short' && $tickPrice >= $entryPrice) {
                    $shouldOpen = true;
                }
            }

            if ($shouldOpen) {
                $opened = true;
                $openedPrice = $tickPrice;
                $trade['entry']['opened'] = true;
                $trade['entry']['opened_at'] = date('c', $tickTs);
                $trade['entry']['opened_ts'] = $tickTs;
                $trade['entry']['opened_price'] = $openedPrice;
                $trade['entry_action_applied'] = 'opened_by_entry_touch';
                $trade['events'][] = [
                    'ts' => $tickTs,
                    'iso' => date('c', $tickTs),
                    'type' => 'open',
                    'price' => $openedPrice,
                    'note' => 'opened_by_entry_touch',
                    'entry_action_applied' => 'opened_by_entry_touch',
                ];
            } else {
                $trade['market']['last_price'] = $tickPrice;
                $trade['market']['last_ts'] = $tickTs;
                $trade['market']['last_iso'] = date('c', $tickTs);
                $this->saveActiveTrade($tradeId, $trade);
                return ['status' => 'pending_entry'];
            }
        }
        
        $pnlResult = $this->calculatePnL($trade, $tickPrice, $side);
        $trade['pnl'] = $pnlResult;
        $trade['market']['last_price'] = $tickPrice;
        $trade['market']['last_ts'] = $tickTs;
        $trade['market']['last_iso'] = date('c', $tickTs);

        if ($side === 'long') {
            if ($tickPrice > ($trade['pnl']['max_favorable_price'] ?? 0)) {
                $trade['pnl']['max_favorable_price'] = $tickPrice;
            }
            if ($tickPrice < ($trade['pnl']['max_adverse_price'] ?? PHP_FLOAT_MAX)) {
                $trade['pnl']['max_adverse_price'] = $tickPrice;
            }
        } else {
            if ($tickPrice < ($trade['pnl']['max_favorable_price'] ?? PHP_FLOAT_MAX)) {
                $trade['pnl']['max_favorable_price'] = $tickPrice;
            }
            if ($tickPrice > ($trade['pnl']['max_adverse_price'] ?? 0)) {
                $trade['pnl']['max_adverse_price'] = $tickPrice;
            }
        }

        if (($trailing['enabled'] ?? false) && !($trailing['active'] ?? false)) {
            $activationPct = (float)($trailing['activation_profit_pct'] ?? 0.02);
            $profitPct = $pnlResult['roi_unrealized'] ?? 0;

            if ($profitPct >= $activationPct) {
                $trade['trailing']['active'] = true;
                $trade['trailing']['peak_price'] = $side === 'long' ? $tickPrice : null;
                $trade['trailing']['trough_price'] = $side === 'short' ? $tickPrice : null;
                $trade['events'][] = [
                    'ts' => $tickTs,
                    'iso' => date('c', $tickTs),
                    'type' => 'trailing_activated',
                    'price' => $tickPrice,
                    'note' => 'profit_threshold_reached',
                ];
            }
        }

        if ($trade['trailing']['active'] ?? false) {
            $trailDist = (float)($trailing['trail_distance_pct'] ?? 0.01);

            if ($side === 'long') {
                $peak = max($trade['trailing']['peak_price'] ?? $tickPrice, $tickPrice);
                $trade['trailing']['peak_price'] = $peak;
                $newStop = $peak * (1 - $trailDist);
                $trade['trailing']['stop_price'] = $newStop;
                if ($trailing['replaces_sl'] ?? true) {
                    $trade['targets']['stop_loss_current'] = max($newStop, $slCurrent ?? 0);
                    $slCurrent = $trade['targets']['stop_loss_current'];
                }
            } else {
                $trough = min($trade['trailing']['trough_price'] ?? $tickPrice, $tickPrice);
                $trade['trailing']['trough_price'] = $trough;
                $newStop = $trough * (1 + $trailDist);
                $trade['trailing']['stop_price'] = $newStop;
                if ($trailing['replaces_sl'] ?? true) {
                    $trade['targets']['stop_loss_current'] = $slCurrent !== null
                        ? min($newStop, $slCurrent)
                        : $newStop;
                    $slCurrent = $trade['targets']['stop_loss_current'];
                }
            }
        }

        if (($policy['allow_tp'] ?? true) && $tp !== null) {
            if ($side === 'long' && $tickPrice >= $tp) {
                return $this->prepareCloseResult($trade, 'tp', $tickPrice, $tickTs, 'take_profit_hit');
            }
            if ($side === 'short' && $tickPrice <= $tp) {
                return $this->prepareCloseResult($trade, 'tp', $tickPrice, $tickTs, 'take_profit_hit');
            }
        }

        if (($policy['allow_sl'] ?? true) && $slCurrent !== null) {
            $isTrailingSl = $trade['trailing']['active'] ?? false;
            $closeReason = $isTrailingSl ? 'trailing_sl' : 'sl';

            if ($side === 'long' && $tickPrice <= $slCurrent) {
                return $this->prepareCloseResult($trade, $closeReason, $tickPrice, $tickTs, 'stop_loss_hit');
            }
            if ($side === 'short' && $tickPrice >= $slCurrent) {
                return $this->prepareCloseResult($trade, $closeReason, $tickPrice, $tickTs, 'stop_loss_hit');
            }
        }

        $maxDurationMinutes = (int)($executionConfig['max_duration_minutes'] ?? 0);
        if ($maxDurationMinutes > 0) {
            $openedTs = (int)($trade['entry']['opened_ts'] ?? $tickTs);
            $maxDurationSeconds = $maxDurationMinutes * 60;
            if ($tickTs - $openedTs >= $maxDurationSeconds) {
                return $this->prepareCloseResult($trade, 'closed_max_duration', $tickPrice, $tickTs, 'max_duration_exceeded');
            }
        }

        $this->writeDatasetRow($trade, $tickTs, $tickPrice);

        $trade['cursor']['last_processed_ts'] = $tickTs;
        $trade['cursor']['history_date'] = date('Y-m-d', $tickTs);

        $this->saveActiveTrade($tradeId, $trade);

        return ['status' => 'updated'];
    }

    /**
     * Prepare close result
     */
    private function prepareCloseResult(array $trade, string $reason, float $closePrice, int $closeTs, string $note): array
    {
        $side = $trade['side'] ?? 'long';
        $openedPrice = (float)($trade['entry']['opened_price'] ?? 0);
        $qty = (float)($trade['entry']['qty'] ?? 0);
        $budget = (float)($trade['entry']['budget_usdt'] ?? 0);

        if ($side === 'long') {
            $pnlUsdt = ($closePrice - $openedPrice) * $qty;
        } else {
            $pnlUsdt = ($openedPrice - $closePrice) * $qty;
        }

        $roi = $budget > 0 ? $pnlUsdt / $budget : 0;

        $feePct = (float)($trade['fees']['fee_taker_pct'] ?? 0.0006);
        $feeUsdt = $budget * $feePct * 2;
        $pnlUsdt -= $feeUsdt;
        $roi = $budget > 0 ? $pnlUsdt / $budget : 0;

        return [
            'status' => 'closed',
            'close_reason' => $reason,
            'close_note' => $note,
            'close_price' => $closePrice,
            'close_ts' => $closeTs,
            'closed_at' => date('c', $closeTs),
            'pnl_realized_usdt' => round($pnlUsdt, 4),
            'roi' => round($roi, 6),
            'fee_paid_usdt' => round($feeUsdt, 4),
            'win' => $pnlUsdt > 0,
        ];
    }

    /**
     * Close trade
     */
    private function closeTrade(array $trade, array $closeResult): void
    {
        $tradeId = $trade['trade_id'] ?? '';
        
        if ($this->isTradeAlreadyClosed($tradeId)) {
            $this->errors[] = "idempotency_guard:already_closed:{$tradeId}";
            return;
        }
        
        $symbol = $trade['symbol'] ?? '';
        $side = $trade['side'] ?? 'long';
        $openedTs = (int)($trade['entry']['opened_ts'] ?? 0);
        $openedPrice = (float)($trade['entry']['opened_price'] ?? 0);
        $closeTs = (int)($closeResult['close_ts'] ?? time());
        $durationMinutes = $openedTs > 0 ? (int)round(($closeTs - $openedTs) / 60) : 0;

        $closedTrade = [
            'schema_version' => self::SCHEMA_VERSION,
            'trade_id' => $tradeId,
            'symbol' => $symbol,
            'side' => $side,
            'source' => 'parser5',
            'opened_at' => $trade['entry']['opened_at'] ?? null,
            'opened_ts' => $openedTs,
            'opened_price' => $openedPrice,
            'closed_at' => $closeResult['closed_at'] ?? date('c'),
            'closed_ts' => $closeTs,
            'closed_price' => $closeResult['close_price'] ?? 0,
            'close_reason' => $closeResult['close_reason'] ?? 'unknown',
            'close_note' => $closeResult['close_note'] ?? '',
            'qty' => $trade['entry']['qty'] ?? 0,
            'budget_usdt' => $trade['entry']['budget_usdt'] ?? 0,
            'leverage' => $trade['entry']['leverage'] ?? 1,
            'targets' => [
                'take_profit' => $trade['targets']['take_profit'] ?? null,
                'stop_loss_initial' => $trade['targets']['stop_loss_initial'] ?? null,
                'stop_loss_final' => $trade['targets']['stop_loss_current'] ?? null,
                'trailing_used' => $trade['trailing']['active'] ?? false,
                'trailing_stop_final' => $trade['trailing']['stop_price'] ?? null,
            ],
            'fees' => ['fee_paid_usdt' => $closeResult['fee_paid_usdt'] ?? 0],
            'result' => [
                'pnl_realized_usdt' => $closeResult['pnl_realized_usdt'] ?? 0,
                'roi_realized' => $closeResult['roi'] ?? 0,
                'duration_minutes' => $durationMinutes,
                'max_drawdown_roi' => $trade['pnl']['max_drawdown_roi'] ?? 0,
                'max_favorable_roi' => $this->calculateMaxFavorableRoi($trade),
            ],
            'labels' => [
                'win' => $closeResult['win'] ?? false,
                'class' => $closeResult['close_reason'] ?? 'unknown',
                'roi_bucket' => $this->getRoiBucket($closeResult['roi'] ?? 0),
            ],
            'closed_once' => true,
        ];

        $this->saveClosedTrade($tradeId, $closedTrade);
        $this->writeDatasetFinalRow($trade, $closeResult);
        $this->deleteActiveTrade($tradeId);
    }

    /**
     * Check if trade has already been closed
     */
    private function isTradeAlreadyClosed(string $tradeId): bool
    {
        $closedPath = $this->storageDir . '/trades/closed/' . $tradeId . '.json';
        $rejectedPath = $this->storageDir . '/trades/rejected/' . $tradeId . '.json';
        return is_file($closedPath) || is_file($rejectedPath);
    }

    /**
     * Close expired entry
     */
    private function closeExpiredEntry(array $trade, array $closeResult): void
    {
        $tradeId = $trade['trade_id'] ?? '';
        
        if ($this->isTradeAlreadyClosed($tradeId)) {
            $this->errors[] = "idempotency_guard:already_closed_expired:{$tradeId}";
            return;
        }

        $closedTrade = [
            'schema_version' => self::SCHEMA_VERSION,
            'trade_id' => $tradeId,
            'symbol' => $trade['symbol'] ?? '',
            'side' => $trade['side'] ?? 'long',
            'source' => 'parser5',
            'opened_at' => null,
            'opened_ts' => null,
            'opened_price' => null,
            'closed_at' => $closeResult['closed_at'] ?? date('c'),
            'closed_ts' => time(),
            'closed_price' => null,
            'close_reason' => 'expired_entry_timeout',
            'close_note' => 'entry_price_not_reached',
            'qty' => 0,
            'budget_usdt' => $trade['entry']['budget_usdt'] ?? 0,
            'leverage' => $trade['entry']['leverage'] ?? 1,
            'targets' => $trade['targets'] ?? [],
            'fees' => ['fee_paid_usdt' => 0],
            'result' => [
                'pnl_realized_usdt' => 0,
                'roi_realized' => 0,
                'duration_minutes' => 0,
                'max_drawdown_roi' => 0,
                'max_favorable_roi' => 0,
            ],
            'labels' => [
                'win' => false,
                'class' => 'expired_entry_timeout',
                'roi_bucket' => '0',
            ],
            'closed_once' => true,
        ];

        $this->saveClosedTrade($tradeId, $closedTrade);
        $this->deleteActiveTrade($tradeId);
    }

    /**
     * Calculate PnL
     */
    private function calculatePnL(array $trade, float $currentPrice, string $side): array
    {
        $openedPrice = (float)($trade['entry']['opened_price'] ?? 0);
        $qty = (float)($trade['entry']['qty'] ?? 0);
        $budget = (float)($trade['entry']['budget_usdt'] ?? 0);

        if ($side === 'long') {
            $pnlUsdt = ($currentPrice - $openedPrice) * $qty;
        } else {
            $pnlUsdt = ($openedPrice - $currentPrice) * $qty;
        }

        $roi = $budget > 0 ? $pnlUsdt / $budget : 0;

        $prevDrawdown = $trade['pnl']['max_drawdown_roi'] ?? 0;
        $maxDrawdown = min($prevDrawdown, $roi);

        $prevFavorable = $trade['pnl']['max_favorable_roi'] ?? 0;
        $maxFavorable = max($prevFavorable, $roi);

        $prevFavorablePrice = (float)($trade['pnl']['max_favorable_price'] ?? $openedPrice);
        $prevAdversePrice = (float)($trade['pnl']['max_adverse_price'] ?? $openedPrice);
        
        if ($side === 'long') {
            $maxFavorablePrice = max($prevFavorablePrice, $currentPrice);
            $maxAdversePrice = min($prevAdversePrice, $currentPrice);
        } else {
            $maxFavorablePrice = min($prevFavorablePrice, $currentPrice);
            $maxAdversePrice = max($prevAdversePrice, $currentPrice);
        }

        return [
            'pnl_unrealized_usdt' => round($pnlUsdt, 4),
            'pnl_realized_usdt' => 0.0,
            'roi_unrealized' => round($roi, 6),
            'roi_realized' => 0.0,
            'max_favorable_price' => $maxFavorablePrice,
            'max_adverse_price' => $maxAdversePrice,
            'max_drawdown_roi' => round($maxDrawdown, 6),
            'max_favorable_roi' => round($maxFavorable, 6),
        ];
    }

    /**
     * Calculate max favorable ROI
     */
    private function calculateMaxFavorableRoi(array $trade): float
    {
        $side = $trade['side'] ?? 'long';
        $openedPrice = (float)($trade['entry']['opened_price'] ?? 0);
        $maxFavorable = (float)($trade['pnl']['max_favorable_price'] ?? $openedPrice);
        $budget = (float)($trade['entry']['budget_usdt'] ?? 0);
        $qty = (float)($trade['entry']['qty'] ?? 0);

        if ($openedPrice <= 0 || $budget <= 0) {
            return 0.0;
        }

        if ($side === 'long') {
            $pnl = ($maxFavorable - $openedPrice) * $qty;
        } else {
            $pnl = ($openedPrice - $maxFavorable) * $qty;
        }

        return round($pnl / $budget, 6);
    }

    /**
     * Get ROI bucket
     */
    private function getRoiBucket(float $roi): string
    {
        if ($roi >= 0.03) {
            return '3pct_plus';
        }
        if ($roi >= 0) {
            return '0_3pct';
        }
        if ($roi >= -0.03) {
            return 'neg_0_3pct';
        }
        return 'neg_3pct_plus';
    }

    /**
     * Get effective fees bps
     */
    private function getEffectiveFeesBps(array $result, array $trade): int
    {
        if (isset($result['fees_bps'])) {
            return (int)$result['fees_bps'];
        }
        if (isset($trade['risk']['fees_bps'])) {
            return (int)$trade['risk']['fees_bps'];
        }
        if (isset($trade['entry']['fees_bps'])) {
            return (int)$trade['entry']['fees_bps'];
        }
        $tradeId = $trade['trade_id'] ?? 'unknown';
        $this->errors[] = "legacy_trade_missing_fees_bps:{$tradeId}_using_default_6bps";
        return 6;
    }

    /**
     * Calculate dynamic SL offset
     */
    private function calculateDynamicSlOffset(float $liqPrice): float
    {
        return max(
            self::SL_OFFSET_MIN_ABSOLUTE,
            abs($liqPrice) * self::SL_OFFSET_COEFFICIENT
        );
    }

    /**
     * Clamp SL price to valid range
     */
    private function clampSlPriceToValidRange(
        float $slPrice, 
        float $entryPrice, 
        float $liqPrice, 
        string $side,
        array &$events
    ): float {
        $originalSl = $slPrice;
        $offset = $this->calculateDynamicSlOffset($liqPrice);
        $clamped = false;
        
        if ($side === 'long') {
            $slMin = $liqPrice + $offset;
            $slMax = $entryPrice - $offset;
            
            if ($slPrice <= $slMin) {
                $slPrice = $slMin;
                $clamped = true;
            }
            if ($slPrice >= $slMax) {
                $slPrice = $slMax;
                $clamped = true;
            }
        } else {
            $slMin = $entryPrice + $offset;
            $slMax = $liqPrice - $offset;
            
            if ($slPrice <= $slMin) {
                $slPrice = $slMin;
                $clamped = true;
            }
            if ($slPrice >= $slMax) {
                $slPrice = $slMax;
                $clamped = true;
            }
        }
        
        if ($clamped) {
            $events[] = [
                'ts' => date('c'),
                'type' => 'sl_clamped',
                'original_sl' => $originalSl,
                'clamped_sl' => $slPrice,
                'entry_price' => $entryPrice,
                'liq_price' => $liqPrice,
                'offset' => $offset,
                'side' => $side,
                'reason' => 'sl_outside_valid_range',
            ];
        }
        
        return $slPrice;
    }
}

/* RULES
 * NO HARDCODE. CONFIG FIRST. SystemPaths ONLY.
 * Core trading engine methods for active trade management.
 * All close conditions checked: TP, SL, trailing, max duration.
 * Idempotency guards prevent double-closing trades.
 */
