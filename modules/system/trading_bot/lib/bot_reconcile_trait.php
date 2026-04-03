<?php
declare(strict_types=1);

namespace Modules\System\TradingBot\Lib;

/**
 * Bot Reconcile Trait
 * 
 * Exchange reconciliation - sync positions/orders with exchange.
 * RECONCILE FIRST principle: before any action, sync state.
 */
trait BotReconcileTrait
{
    /**
     * Reconcile with exchange
     * 
     * Syncs local state with exchange positions/orders.
     * P3: Adds orphan_positions data to result.
     * 
     * @return array Reconcile result
     */
    protected function reconcileWithExchange(): array
    {
        $result = [
            'ok' => true,
            'positions_synced' => 0,
            'orders_synced' => 0,
            'positions_closed' => 0,
            'positions_orphan' => 0,
            'orphan_positions_count' => 0, // P3
            'orphan_positions' => [],      // P3
            'error' => null,
        ];
        
        // In paper/dry mode, just return success (no real exchange calls)
        if ($this->isPaperMode() || $this->isTestMode()) {
            return $result;
        }
        
        try {
            // Get exchange positions
            $exchangePositions = $this->fetchExchangePositions();
            $localTrades = $this->store->loadActiveTrades();
            
            // Sync positions
            $localSymbols = [];
            foreach ($localTrades as $tradeId => $trade) {
                $localSymbols[$trade['symbol']] = $tradeId;
            }
            
            // Check each exchange position
            foreach ($exchangePositions as $position) {
                $symbol = $position['symbol'] ?? '';
                $size = (float)($position['size'] ?? 0);
                
                if (abs($size) > 0) {
                    $result['positions_synced']++;
                    
                    if (!isset($localSymbols[$symbol])) {
                        // Position exists on exchange but not locally - orphan
                        $result['positions_orphan']++;
                        $result['orphan_positions_count']++;
                        
                        // P3: Add full orphan position data
                        $result['orphan_positions'][] = [
                            'symbol' => $symbol,
                            'side' => $position['side'] ?? 'unknown',
                            'size' => $size,
                            'avgPrice' => (float)($position['avgPrice'] ?? 0),
                            'liqPrice' => (float)($position['liqPrice'] ?? 0),
                            'stopLoss' => (float)($position['stopLoss'] ?? 0),
                            'trailingStop' => (float)($position['trailingStop'] ?? 0),
                            'positionIdx' => (int)($position['positionIdx'] ?? 0),
                        ];
                        
                        $this->warnings[] = "Orphan position found: {$symbol}";
                    }
                }
            }
            
            // Check local trades that might be closed on exchange
            foreach ($localTrades as $tradeId => $trade) {
                $symbol = $trade['symbol'];
                $found = false;
                
                foreach ($exchangePositions as $position) {
                    if ($position['symbol'] === $symbol && abs((float)($position['size'] ?? 0)) > 0) {
                        $found = true;
                        break;
                    }
                }
                
                if (!$found) {
                    // Local trade not found on exchange - might be closed
                    $result['positions_closed']++;
                    $this->handleClosedPosition($tradeId, $trade);
                }
            }
            

            // Best-effort backfill for recently closed trades missing exit info
            $this->backfillRecentClosedTradesMissingExit();
            // Get exchange orders
            $exchangeOrders = $this->fetchExchangeOrders();
            $result['orders_synced'] = count($exchangeOrders);
            
        } catch (\Throwable $e) {
            $result['ok'] = false;
            $result['error'] = $e->getMessage();
        }
        
        return $result;
    }
    
    /**
     * Fetch positions from exchange
     * 
     * @return array Positions
     */
    private function fetchExchangePositions(): array
    {
        // In paper/dry/test mode, return empty
        if (!$this->isRealExchangeMode()) {
            return [];
        }
        
        try {
            $gateway = $this->getGateway();
            if ($gateway === null) {
                return [];
            }
            
            return $gateway->getPositions();
        } catch (\Throwable $e) {
            $this->errors[] = 'Failed to fetch positions: ' . $e->getMessage();
            return [];
        }
    }
    
    /**
     * Fetch orders from exchange
     * 
     * @return array Orders
     */
    private function fetchExchangeOrders(): array
    {
        // In paper/dry/test mode, return empty
        if (!$this->isRealExchangeMode()) {
            return [];
        }
        
        try {
            $gateway = $this->getGateway();
            if ($gateway === null) {
                return [];
            }
            
            return $gateway->getOpenOrders();
        } catch (\Throwable $e) {
            $this->errors[] = 'Failed to fetch orders: ' . $e->getMessage();
            return [];
        }
    }
    
    /**
     * Handle position that was closed on exchange
     * 
     * @param string $tradeId Trade ID
     * @param array $trade Trade data
     */
    private function handleClosedPosition(string $tradeId, array $trade): void
{
    $closedAtTs = time();

    // Base close fields (always)
    $trade['status']    = 'closed';
    $trade['closed_at'] = date('c', $closedAtTs);
    $trade['closed_ts'] = $closedAtTs;
    $trade['close_ts']  = $closedAtTs;

    // ── Local finalization (immediate, no exchange call) ────────────────────
    // Compute local estimates from trade snapshot so closed file is never empty,
    // even if exchange enrichment is delayed or unavailable.
    $trade = $this->applyLocalCloseFinalize($trade, $closedAtTs);

    // ── Exchange enrichment (best-effort improvement) ────────────────────────
    $trade = $this->enrichClosedTradeFromExchange($trade, $closedAtTs);

    // Upgrade close_result_source if exchange matched
    if (!empty($trade['exchange_close']['matched'])) {
        $prevSource = (string)($trade['close_result_source'] ?? 'local_finalize');
        if ($prevSource === 'local_finalize') {
            $trade['close_result_source'] = 'mixed';
        } else {
            $trade['close_result_source'] = 'exchange_enriched';
        }
        $trade['exchange_enrichment_used'] = true;
    } else {
        $trade['close_finalize_warning'] = 'exchange_enrichment_skipped_or_no_match';
    }

    // ── Normalize close reason ────────────────────────────────────────────────
    $closeReason = $this->determineCloseReason($trade);
    $trade['close_reason']            = $closeReason['reason'];
    $trade['close_reason_normalized'] = $closeReason['reason'];
    $trade['close_reason_meta']       = $closeReason['meta'];

    // Ensure realized_pnl field exists (alias for pnl)
    if (!isset($trade['realized_pnl']) && isset($trade['pnl'])) {
        $trade['realized_pnl'] = $trade['pnl'];
    }

    $this->store->moveTradeToClosedDir($tradeId, $trade);

    // Demo mode: write AI-ready dataset record for this closed trade.
    if (($this->config['module']['mode'] ?? '') === 'demo') {
        $this->store->appendAiDatasetRecord($tradeId, $trade);
    }

    // Trigger immediate coin_passport rebuild for this symbol (best-effort, non-blocking).
    $symbol = (string)($trade['symbol'] ?? '');
    $this->triggerCoinPassportRebuildForSymbol($symbol);
}

/**
 * Apply local close finalization — compute close fields from local trade state
 * immediately at close time, without relying on the exchange.
 *
 * Fields set:
 *   close_price           — last known price or entry_price estimate
 *   pnl                   — estimated PnL based on local price (approximate)
 *   roi                   — estimated ROI %
 *   hold_minutes          — time held since open
 *   local_close_finalize_used  — true (diagnostic flag)
 *   close_result_source   — 'local_finalize'
 *
 * The exchange enrichment step may overwrite close_price/pnl with real data later.
 *
 * @param array<string,mixed> $trade
 * @param int $closedAtTs
 * @return array<string,mixed>
 */
private function applyLocalCloseFinalize(array $trade, int $closedAtTs): array
{
    $trade['local_close_finalize_used'] = true;

    // Hold minutes
    $openedTs = (int)(strtotime((string)($trade['opened_at'] ?? '')) ?: ($trade['open_ts'] ?? 0));
    if ($openedTs > 0 && $closedAtTs > $openedTs) {
        $trade['hold_minutes'] = (int)round(($closedAtTs - $openedTs) / 60);
    } elseif (!isset($trade['hold_minutes'])) {
        $trade['hold_minutes'] = 0;
    }

    // Estimate close_price from last known position data if not already set
    if (!isset($trade['close_price']) || (float)($trade['close_price'] ?? 0) <= 0) {
        // Use last known price from runtime or protection
        $rt = is_array($trade['runtime'] ?? null) ? $trade['runtime'] : [];
        $lastPrice = (float)($rt['last_price'] ?? $rt['last_mark_price'] ?? 0);
        if ($lastPrice > 0) {
            $trade['close_price'] = $lastPrice;
        }
    }

    // Estimate ROI/PnL locally from entry_price + close_price if not already set
    if (!isset($trade['pnl']) || !isset($trade['roi'])) {
        $entryPrice  = (float)($trade['entry_price'] ?? 0);
        $closePrice  = (float)($trade['close_price'] ?? 0);
        $side        = strtolower((string)($trade['side'] ?? 'long'));
        $qty         = (float)($trade['position_size'] ?? $trade['qty'] ?? 0);

        if ($entryPrice > 0 && $closePrice > 0 && $qty > 0) {
            if ($side === 'long') {
                $priceDiff = $closePrice - $entryPrice;
            } else {
                $priceDiff = $entryPrice - $closePrice;
            }
            $pnlEst  = round($priceDiff * $qty, 8);
            $roiEst  = round(($priceDiff / $entryPrice) * 100, 4);

            if (!isset($trade['pnl'])) {
                $trade['pnl'] = $pnlEst;
            }
            if (!isset($trade['roi'])) {
                $trade['roi'] = $roiEst;
            }
        } elseif (!isset($trade['pnl'])) {
            $trade['pnl'] = 0.0;
            $trade['roi'] = 0.0;
        }
    }

    $trade['close_result_source'] = 'local_finalize';

    return $trade;
}

/**
 * Determine close reason for a closed trade.
 *
 * Contract: close_reason MUST be one of:
 * - stop_loss
 * - trailing_stop
 * - manual_close (fallback, also covers liquidation/other)
 *
 * @param array<string,mixed> $trade
 * @return array{reason:string,meta:array<string,mixed>}
 */
private function determineCloseReason(array $trade): array
{
    $execCfg = is_array($this->config['execution'] ?? null) ? $this->config['execution'] : [];

    // 0) Manual close marker wins (highest confidence)
    if (($trade['manual_close'] ?? false) === true) {
        return [
            'reason' => 'manual_close',
            'meta' => [
                'schema_version' => 'close_reason_meta_v1',
                'method' => 'manual_close_marker',
                'confidence' => 0.95,
            ],
        ];
    }

    // 1) Stop-loss if close_price is near known SL
    $closePrice = (float)($trade['close_price'] ?? 0);
    $sl = (float)($trade['protection']['stop_loss_price'] ?? ($trade['stop_loss'] ?? 0));

    // Percent tolerance (default 0.30%)
    $tolPct = (float)($execCfg['close_reason_sl_tolerance_pct'] ?? 0.30);
    if ($tolPct < 0) {
        $tolPct = 0.0;
    }
    if ($tolPct > 5.0) {
        $tolPct = 5.0;
    }

    if ($closePrice > 0 && $sl > 0) {
        $distPct = (abs($closePrice - $sl) / max(1e-12, $sl)) * 100.0;
        if ($distPct <= $tolPct) {
            return [
                'reason' => 'stop_loss',
                'meta' => [
                    'schema_version' => 'close_reason_meta_v1',
                    'method' => 'close_price_near_sl',
                    'confidence' => 0.85,
                    'close_price' => $closePrice,
                    'stop_loss_price' => $sl,
                    'distance_pct' => $distPct,
                    'tolerance_pct' => $tolPct,
                ],
            ];
        }
    }

    // 2) Trailing stop if trailing was enabled and exchange trailing fields were present (best-effort)
    $trailingEnabled = (bool)($trade['protection']['trailing_enabled'] ?? ($trade['risk']['trailing']['enabled'] ?? false));
    $trailingStop = (float)($trade['protection']['trailing_stop'] ?? 0);
    $activePrice = (float)($trade['protection']['active_price'] ?? 0);

    if ($trailingEnabled && ($trailingStop > 0 || $activePrice > 0)) {
        return [
            'reason' => 'trailing_stop',
            'meta' => [
                'schema_version' => 'close_reason_meta_v1',
                'method' => 'trailing_enabled_and_present',
                'confidence' => 0.55,
                'trailing_stop' => $trailingStop,
                'active_price' => $activePrice,
            ],
        ];
    }

    // 3) Fallback (also covers liquidation / manual close without marker / unknown)
    return [
        'reason' => 'manual_close',
        'meta' => [
            'schema_version' => 'close_reason_meta_v1',
            'method' => 'fallback_manual_close',
            'confidence' => 0.20,
        ],
    ];
}
/**
 * Enrich closed trade with exchange data (best-effort).
 *
 * Why: reconcile closes local trade when exchange position disappears,
 * but Bybit position list does not provide realized PnL / exit price.
 * We query /v5/position/closed-pnl and attach the matched record.
 *
 * @param array<string,mixed> $trade
 * @param int $closedAtTs
 * @return array<string,mixed>
 */
/**
 * Enrich closed trade with exchange closed-pnl data.
 *
 * IMPORTANT:
 * - Bybit closed-pnl "side" is often the side of the CLOSING order:
 *   Sell closes LONG, Buy closes SHORT.
 * - We MUST enforce match window to avoid attaching wrong close row.
 *
 * @param array<string,mixed> $trade
 * @param int $closedAtTs Unix timestamp (seconds)
 * @return array<string,mixed>
 */
private function enrichClosedTradeFromExchange(array $trade, int $closedAtTs): array
{
    if (!$this->gateway || !$this->gateway->isInitialized()) {
        $trade['exchange_close'] = array_merge(is_array($trade['exchange_close'] ?? null) ? $trade['exchange_close'] : [], [
            'matched' => false,
            'error' => 'gateway_not_initialized',
            'last_attempt_ts' => date('c'),
        ]);
        return $trade;
    }

    $execCfg = is_array($this->config['execution'] ?? null) ? $this->config['execution'] : [];

    $lookbackMinutes = (int)($execCfg['reconcile_closed_pnl_lookup_minutes'] ?? 180);
    if ($lookbackMinutes < 10) {
        $lookbackMinutes = 10;
    }
    if ($lookbackMinutes > 24 * 60) {
        $lookbackMinutes = 24 * 60;
    }

    $limit = (int)($execCfg['reconcile_closed_pnl_limit'] ?? 200);
    if ($limit < 1) {
        $limit = 1;
    }
    if ($limit > 200) {
        $limit = 200;
    }

    $matchWindowSec = (int)($execCfg['reconcile_closed_pnl_match_window_sec'] ?? 900);
    if ($matchWindowSec < 60) {
        $matchWindowSec = 60;
    }
    if ($matchWindowSec > 6 * 3600) {
        $matchWindowSec = 6 * 3600;
    }

    $qtyTolerancePct = (float)($execCfg['reconcile_closed_pnl_qty_tolerance_pct'] ?? 5.0);
    if ($qtyTolerancePct < 0) {
        $qtyTolerancePct = 0.0;
    }
    if ($qtyTolerancePct > 20.0) {
        $qtyTolerancePct = 20.0;
    }

    $startMs = (int)(($closedAtTs - ($lookbackMinutes * 60)) * 1000);
    $endMs = (int)(($closedAtTs + ($lookbackMinutes * 60)) * 1000);

    $resp = $this->gateway->getClosedPnl($startMs, $endMs, $limit);
    if (!($resp['ok'] ?? false)) {
        $trade['exchange_close'] = array_merge(is_array($trade['exchange_close'] ?? null) ? $trade['exchange_close'] : [], [
            'matched' => false,
            'error' => $resp['error'] ?? 'closed_pnl_failed',
            'last_attempt_ts' => date('c'),
            'window' => [
                'start_ms' => $startMs,
                'end_ms' => $endMs,
                'lookback_minutes' => $lookbackMinutes,
                'match_window_sec' => $matchWindowSec,
                'limit' => $limit,
            ],
        ]);
        return $trade;
    }

    $items = $resp['items'] ?? [];
    if (!is_array($items) || empty($items)) {
        $trade['exchange_close'] = array_merge(is_array($trade['exchange_close'] ?? null) ? $trade['exchange_close'] : [], [
            'matched' => false,
            'error' => 'closed_pnl_empty',
            'last_attempt_ts' => date('c'),
        ]);
        return $trade;
    }

    $symbol = (string)($trade['symbol'] ?? '');
    $side = $this->normalizeSideLongShort((string)($trade['side'] ?? ''));
    $qty = (float)($trade['exchange']['qty'] ?? $trade['position_size'] ?? $trade['exchange']['size'] ?? 0);

    $closedAtMs = (int)($closedAtTs * 1000);

    $best = null;
    $bestDelta = null;
    $candidatesNoTime = [];

    foreach ($items as $row) {
        if (!is_array($row)) {
            continue;
        }

        if ((string)($row['symbol'] ?? '') !== $symbol) {
            continue;
        }

        $rowPosSide = $this->normalizeClosedPnlPositionSide($row);
        if ($rowPosSide !== '' && $rowPosSide !== $side) {
            continue;
        }

        // Qty match (best-effort)
        $rowQty = (float)($row['qty'] ?? 0);
        if ($qty > 0 && $rowQty > 0) {
            $diff = abs($qty - $rowQty);
            $diffPct = ($diff / max(1e-12, $qty)) * 100.0;
            if ($diffPct > $qtyTolerancePct) {
                continue;
            }
        }

        $rowTimeMs = (int)($row['updatedTime'] ?? $row['createdTime'] ?? 0);
        if ($rowTimeMs <= 0) {
            $candidatesNoTime[] = $row;
            continue;
        }

        $delta = abs($closedAtMs - $rowTimeMs);

        // Enforce match window STRICTLY
        if ($delta > ($matchWindowSec * 1000)) {
            continue;
        }

        if ($best === null || $bestDelta === null || $delta < $bestDelta) {
            $best = $row;
            $bestDelta = $delta;
        }
    }

    // If no timed match, allow a single no-time candidate ONLY if unambiguous.
    if ($best === null && count($candidatesNoTime) === 1) {
        $best = $candidatesNoTime[0];
        $bestDelta = null;
    }

    if ($best === null) {
        $trade['exchange_close'] = array_merge(is_array($trade['exchange_close'] ?? null) ? $trade['exchange_close'] : [], [
            'matched' => false,
            'error' => 'no_match',
            'last_attempt_ts' => date('c'),
            'match' => [
                'symbol' => $symbol,
                'side' => $side,
                'qty' => $qty,
                'closed_at_ts' => $closedAtTs,
                'match_window_sec' => $matchWindowSec,
                'qty_tolerance_pct' => $qtyTolerancePct,
                'items_count' => count($items),
                'no_time_candidates' => count($candidatesNoTime),
            ],
        ]);
        return $trade;
    }

    $closePrice = (float)($best['avgExitPrice'] ?? 0);
    $pnl = (float)($best['closedPnl'] ?? 0);

    if ($closePrice > 0) {
        $trade['close_price'] = $closePrice;
    }
    $trade['pnl'] = $pnl;

    $trade['close_inferred'] = $this->inferCloseCause($trade);

    $trade['exchange_close'] = array_merge(is_array($trade['exchange_close'] ?? null) ? $trade['exchange_close'] : [], [
        'matched' => true,
        'last_attempt_ts' => date('c'),
        'record' => $best,
        'record_delta_ms' => $bestDelta,
        'record_position_side' => $this->normalizeClosedPnlPositionSide($best),
    ]);

    return $trade;
}


/**
 * Best-effort backfill of exit price / realized PnL for recently closed trades.
 *
 * Motivation:
 * - closed-pnl rows can appear with a delay after close on exchange.
 * - We must keep close_reason within: stop_loss | trailing_stop | manual_close.
 *
 * @return void
 */
private function backfillRecentClosedTradesMissingExit(): void
{
    $execCfg = is_array($this->config['execution'] ?? null) ? $this->config['execution'] : [];

    $enabled = (bool)($execCfg['reconcile_closed_backfill_enabled'] ?? true);
    if (!$enabled) {
        return;
    }

    $lookbackMinutes = (int)($execCfg['reconcile_closed_backfill_lookback_minutes'] ?? 120);
    if ($lookbackMinutes < 5) {
        $lookbackMinutes = 5;
    }
    if ($lookbackMinutes > 24 * 60) {
        $lookbackMinutes = 24 * 60;
    }

    $maxItems = (int)($execCfg['reconcile_closed_backfill_max_items'] ?? 12);
    if ($maxItems < 1) {
        $maxItems = 1;
    }
    if ($maxItems > 50) {
        $maxItems = 50;
    }

    $maxAttempts = (int)($execCfg['reconcile_closed_backfill_max_attempts'] ?? 3);
    if ($maxAttempts < 1) {
        $maxAttempts = 1;
    }
    if ($maxAttempts > 10) {
        $maxAttempts = 10;
    }

    $cutoff = time() - ($lookbackMinutes * 60);

    $closedTrades = $this->store->loadClosedTrades(50);
    $processed = 0;

    foreach ($closedTrades as $trade) {
        if ($processed >= $maxItems) {
            break;
        }
        if (!is_array($trade)) {
            continue;
        }

        $tradeId = (string)($trade['trade_id'] ?? $trade['signal_id'] ?? '');
        if ($tradeId === '') {
            continue;
        }

        $closedTs = (int)($trade['closed_ts'] ?? strtotime((string)($trade['closed_at'] ?? '')) ?: 0);
        if ($closedTs <= 0 || $closedTs < $cutoff) {
            continue;
        }

        $closePrice = (float)($trade['close_price'] ?? 0);
        $hasPnl = array_key_exists('pnl', $trade);
        $reason = (string)($trade['close_reason'] ?? '');

        $needsExit = ($closePrice <= 0) || (!$hasPnl);
        $needsReasonFix = ($reason === '' || $reason === 'exchange_closed' || $reason === 'exchange_closed_unknown' || $reason === 'unknown');

        if (!$needsExit && !$needsReasonFix) {
            continue;
        }

        $exchangeClose = is_array($trade['exchange_close'] ?? null) ? $trade['exchange_close'] : [];
        $attempts = (int)($exchangeClose['attempts'] ?? 0);
        if ($attempts >= $maxAttempts) {
            continue;
        }

        $exchangeClose['attempts'] = $attempts + 1;
        $exchangeClose['last_attempt_ts'] = date('c');
        $trade['exchange_close'] = $exchangeClose;

        // Enrich
        $trade = $this->enrichClosedTradeFromExchange($trade, $closedTs);

        // Re-evaluate close reason to remove legacy reasons like "exchange_closed"
        $closeReason = $this->determineCloseReason($trade);
        $trade['close_reason']            = $closeReason['reason'];
        $trade['close_reason_normalized'] = $closeReason['reason'];
        $trade['close_reason_meta']       = $closeReason['meta'];

        // Update close_result_source based on enrichment outcome
        if (!empty($trade['exchange_close']['matched'])) {
            $prevSource = (string)($trade['close_result_source'] ?? 'local_finalize');
            $trade['close_result_source'] = ($prevSource === 'local_finalize') ? 'mixed' : 'exchange_enriched';
            $trade['exchange_enrichment_used'] = true;
            unset($trade['close_finalize_warning']);
        }

        $this->store->saveClosedTrade($tradeId, $trade);
        $processed++;
    }
}


private function normalizeSideLongShort(string $side): string
{
    $s = strtolower(trim($side));
    if ($s === 'buy') {
        return 'long';
    }
    if ($s === 'sell') {
        return 'short';
    }
    if ($s === 'long' || $s === 'short') {
        return $s;
    }
    return $s;
}

/**
 * Normalize position side for Bybit closed-pnl rows.
 *
 * Bybit commonly reports "side" as the side of the closing order:
 * - Sell closes LONG
 * - Buy closes SHORT
 *
 * Prefer positionIdx/positionSide when present.
 *
 * @param array<string,mixed> $row
 * @return string 'long'|'short'|'' (unknown)
 */
private function normalizeClosedPnlPositionSide(array $row): string
{
    $idx = (int)($row['positionIdx'] ?? $row['position_idx'] ?? 0);
    if ($idx === 1) {
        return 'long';
    }
    if ($idx === 2) {
        return 'short';
    }

    $posSide = strtolower(trim((string)($row['positionSide'] ?? $row['position_side'] ?? '')));
    if ($posSide === 'long' || $posSide === 'short') {
        return $posSide;
    }

    $side = strtolower(trim((string)($row['side'] ?? '')));
    if ($side === 'sell') {
        return 'long';
    }
    if ($side === 'buy') {
        return 'short';
    }

    return '';
}

/**
 * Infer close cause (best-effort) for UI / debugging.
 *
 * This is NOT a trading decision. It only helps label closed trades.
 *
 * @param array<string,mixed> $trade
 * @return array<string,mixed>
 */
private function inferCloseCause(array $trade): array
{
    $execCfg = is_array($this->config['execution'] ?? null) ? $this->config['execution'] : [];

    // Manual close marker
    if (($trade['manual_close'] ?? false) === true) {
        return [
            'likely' => 'manual_close',
            'confidence' => 0.95,
            'notes' => ['manual_close_marker' => true],
        ];
    }

    $closePrice = (float)($trade['close_price'] ?? 0);
    $sl = (float)($trade['protection']['stop_loss_price'] ?? ($trade['stop_loss'] ?? 0));

    $tolPct = (float)($execCfg['close_reason_sl_tolerance_pct'] ?? 0.30);
    if ($tolPct < 0) {
        $tolPct = 0.0;
    }
    if ($tolPct > 5.0) {
        $tolPct = 5.0;
    }

    if ($closePrice > 0 && $sl > 0) {
        $distPct = (abs($closePrice - $sl) / max(1e-12, $sl)) * 100.0;
        if ($distPct <= $tolPct) {
            return [
                'likely' => 'stop_loss',
                'confidence' => 0.85,
                'dist_pct' => $distPct,
                'tolerance_pct' => $tolPct,
            ];
        }
    }

    $trailingEnabled = (bool)($trade['protection']['trailing_enabled'] ?? ($trade['risk']['trailing']['enabled'] ?? false));
    $trailingStop = (float)($trade['protection']['trailing_stop'] ?? 0);
    $activePrice = (float)($trade['protection']['active_price'] ?? 0);

    if ($trailingEnabled && ($trailingStop > 0 || $activePrice > 0)) {
        return [
            'likely' => 'trailing_stop',
            'confidence' => 0.55,
            'trailing_stop' => $trailingStop,
            'active_price' => $activePrice,
        ];
    }

    return [
        'likely' => 'manual_close',
        'confidence' => 0.20,
        'notes' => ['fallback' => true],
    ];
}


/* RULES
- Reconcile keeps local trades in sync with exchange state (best-effort)
- Closed trades are enriched via Bybit /v5/position/closed-pnl:
  - "side" in closed-pnl is treated as closing-order side (Sell closes LONG, Buy closes SHORT)
  - match is STRICTLY constrained by reconcile_closed_pnl_match_window_sec
- close_reason is ALWAYS one of: stop_loss | trailing_stop | manual_close (fallback)
- backfillRecentClosedTradesMissingExit retries enrichment for recently closed trades to fill close_price/pnl and fix legacy reasons
*/

}

