<?php
declare(strict_types=1);

namespace Modules\System\TradingBot\Lib;

/**
 * Bot Executor Trait - LIVE Phase-1
 * 
 * Order execution logic for Trading Bot.
 * Phase-1: SL-first with fail-safe close pattern.
 * 
 * Sequence:
 * 1. Validate intent + risk
 * 2. Check exchange guard (orphan positions)
 * 3. Check limits (union of local + exchange)
 * 4. Price check (late entry)
 * 5. Set leverage
 * 6. Submit market order
 * 7. Post-open reconcile (get position from exchange)
 * 8. Calculate protection (SL from liquidation, trailing if enabled)
 * 9. Set trading-stop (SL required, trailing optional)
 * 10. If SL fails -> fail-safe close + reject
 * 11. Only if SL set -> save trade as opened_protected
 */
trait BotExecutorTrait
{
    /** @var object|null Gateway instance */
    private $gateway = null;
    
    /** @var array P3: Exchange open positions cache */
    protected array $exchangeOpenPositionsCache = [];
    
    /** @var int P3: Cache timestamp */
    protected int $exchangeOpenPositionsCacheTs = 0;
    
    /** @var array|null P6: Balance cache */
    protected ?array $balanceCache = null;
    
    /** @var int P6: Balance cache timestamp */
    protected int $balanceCacheTs = 0;
    
    /**
     * Get the authoritative execution identity key for an intent.
     * For Brain-controlled intents: use intent_id (stable, deterministic).
     * For legacy signals: use signal_id or id.
     *
     * All execution branches (success, reject, fail-safe, emergency)
     * MUST use this helper so the same Brain intent is always marked
     * under the same dedupe key.
     *
     * @param array $intent Intent data
     * @return string Execution identity key
     */
    protected function getExecutionIdentityKey(array $intent): string
    {
        if (!empty($intent['brain_controlled'])) {
            // Brain intent: intent_id is authoritative — signal_id is informational only
            return (string)($intent['intent_id'] ?? $intent['id'] ?? $intent['signal_id'] ?? 'unknown');
        }
        // Legacy signal: signal_id / id
        return (string)($intent['signal_id'] ?? $intent['id'] ?? 'unknown');
    }

    /**
     * Execute intent (open position) - LIVE Phase-1
     * 
     * @param array $intent Intent data
     * @param string $mode Execution mode (live|dry|test)
     * @return array Execution result
     */
    protected function executeIntent(array $intent, string $mode): array
    {
        $result = [
            'ok' => true,
            'opened' => false,
            'filled' => false,
            'order_id' => null,
            'trade_id' => null,
            'status' => 'pending',
            'error' => null,
            // P0.1: Execution stage audit — tracks exactly where the chain stopped
            'execution_stage' => 'source_loaded',
            // P0.3: Exchange submit visibility
            'exchange_submit_attempted' => false,
            'exchange_response_code' => null,
            'exchange_response_message' => null,
        ];        $signalId = $intent['signal_id'] ?? ($intent['id'] ?? 'unknown');
        $symbol = (string)($intent['symbol'] ?? '');
        $side = (string)($intent['side'] ?? '');

        // ============================================================
        // Brain-controlled intent check:
        // If intent comes from Brain live_intents (brain_controlled=true),
        // skip bot-local strategy toggles (reverse_side, force_side, symbol_overrides).
        // Brain has already applied all strategy decisions.
        // Bot only applies execution-layer logic.
        // ============================================================
        $isBrainControlled = !empty($intent['brain_controlled']);

        if (!$isBrainControlled) {
            // ============================================================
            // LEGACY: Per-symbol overrides (manual) — DEPRECATED when Brain-controlled.
            // config.symbol_overrides[SYMBOL]:
            // - enabled: bool (false → reject)
            // - reverse_side_enabled: bool (overrides global execution.reverse_side_enabled)
            // - force_side: 'long'|'short' (applies after reverse toggle)
            // WARNING: These are deprecated strategy controls. Brain should be the source of truth.
            // ============================================================
            $symbolOverrides = (array)($this->config['symbol_overrides'] ?? []);
            $symCfg = [];
            if ($symbol !== '' && isset($symbolOverrides[$symbol]) && is_array($symbolOverrides[$symbol])) {
                $symCfg = $symbolOverrides[$symbol];
            }

            if (!empty($symCfg) && array_key_exists('enabled', $symCfg) && $symCfg['enabled'] === false) {
                return $this->rejectIntent($intent, 'rejected_symbol_disabled', "symbol_disabled:{$symbol} (legacy bot override)", $result);
            }

            // DEPRECATED: Side inversion (LONG↔SHORT) — bot-local toggle
            // Brain now controls reverse_side via live_reverse_side_enabled.
            $reverseEnabled = (bool)($this->config['execution']['reverse_side_enabled'] ?? false);
            if (!empty($symCfg) && array_key_exists('reverse_side_enabled', $symCfg)) {
                $reverseEnabled = (bool)$symCfg['reverse_side_enabled'];
            }

            if ($reverseEnabled) {
                $origSide = (string)($intent['side_original'] ?? $intent['side'] ?? '');
                if ($origSide === 'long' || $origSide === 'short') {
                    $intent['side_original'] = $origSide;
                    $intent['side'] = ($origSide === 'long') ? 'short' : 'long';
                    $intent['side_effective_reason'] = 'reverse_side_enabled (legacy bot override)';
                    $side = (string)$intent['side'];
                }
            }

            // DEPRECATED: Optional force-side after reverse toggle
            $forceSide = (!empty($symCfg) && isset($symCfg['force_side'])) ? (string)$symCfg['force_side'] : '';
            if ($forceSide === 'long' || $forceSide === 'short') {
                if (!isset($intent['side_original'])) {
                    $intent['side_original'] = (string)($intent['side'] ?? $side);
                }
                $intent['side'] = $forceSide;
                $intent['side_effective_reason'] = 'force_side (legacy bot override)';
                $side = $forceSide;
            }
        }
        // Brain-controlled: side/symbol already decided by Brain, no bot overrides

        $risk = $intent['risk'] ?? [];
        
        try {
            // ============================================================
            // Step 1: Validate intent + risk
            // ============================================================
            $result['execution_stage'] = 'validation_started';
            $intentValidation = $this->validator->validateIntent($intent);
            if (!$intentValidation['valid']) {
                $result['execution_stage'] = 'validation_rejected';
                // P0.6: Include missing/invalid fields preview for debugging
                $result['validation_error_summary'] = $intentValidation['reason'] ?? 'unknown';
                $result['missing_fields_preview'] = $intentValidation['missing_fields'] ?? [];
                $result['invalid_fields_preview'] = $intentValidation['invalid_fields'] ?? [];
                return $this->rejectIntent($intent, 'rejected_validation', $intentValidation['reason'], $result);
            }
            
            $riskValidation = $this->riskEngine->validateRisk($risk);
            if (!$riskValidation['valid']) {
                $result['execution_stage'] = 'validation_rejected';
                $result['validation_error_summary'] = $riskValidation['reason'] ?? 'unknown';
                $result['missing_fields_preview'] = $riskValidation['missing_fields'] ?? [];
                return $this->rejectIntent($intent, 'rejected_validation', $riskValidation['reason'], $result);
            }
            
            // ============================================================
            // Step 2: P3 Exchange Guard - check for orphan positions
            // ============================================================
            $result['execution_stage'] = 'execution_guard_check';
            $activeTrades = $this->store->loadActiveTrades();
            $localSymbols = array_map(function($t) { return $t['symbol'] ?? ''; }, $activeTrades);
            
            if ($mode === 'live') {
                $exchangeOpen = $this->getExchangeOpenPositionsCached();
                
                // Check if symbol has orphan position on exchange
                foreach ($exchangeOpen as $exPos) {
                    $exSymbol = $exPos['symbol'] ?? '';
                    if ($exSymbol === $symbol) {
                        // Position exists on exchange for this symbol
                        if (!in_array($symbol, $localSymbols, true)) {
                            // Not in local trades - this is an ORPHAN position
                            $result['execution_stage'] = 'execution_guard_blocked';
                            return $this->rejectIntent($intent, 'skipped_exchange_position_exists', 
                                "Orphan position on exchange for {$symbol}", $result, [
                                    'blocked_symbol' => $symbol,
                                    'exchange_position' => [
                                        'symbol' => $exSymbol,
                                        'side' => $exPos['side'] ?? 'unknown',
                                        'size' => $exPos['size'] ?? 0,
                                        'avgPrice' => $exPos['avgPrice'] ?? 0,
                                        'liqPrice' => $exPos['liqPrice'] ?? 0,
                                        'positionIdx' => $exPos['positionIdx'] ?? 0,
                                    ],
                                    'intent_side' => $side,
                                ]);
                        } else {
                            // Symbol is already being tracked - reject as busy
                            $result['execution_stage'] = 'execution_guard_blocked';
                            // Find the active trade for linkage
                            $relatedTrade = null;
                            foreach ($activeTrades as $at) {
                                if (($at['symbol'] ?? '') === $symbol) {
                                    $relatedTrade = $at;
                                    break;
                                }
                            }
                            return $this->rejectIntent($intent, 'skipped_symbol_busy', 
                                "symbol_busy:{$symbol} — already has active exchange position and local trade", $result, [
                                    'blocked_symbol' => $symbol,
                                    'related_active_trade_id' => $relatedTrade['id'] ?? null,
                                    'related_position_symbol' => $symbol,
                                    'open_since' => $relatedTrade['opened_at'] ?? $relatedTrade['created_at'] ?? null,
                                ]);
                        }
                    }
                }
                
                // P3: Union local + exchange for effective open count
                $exchangeSymbols = array_column($exchangeOpen, 'symbol');
                
                // Create array of pseudo-trades for orphan symbols (for limit checks)
                // Using array_values to ensure purely numeric indices after merge
                $orphanTrades = [];
                foreach ($exchangeSymbols as $exSym) {
                    if (!in_array($exSym, $localSymbols, true)) {
                        $orphanTrades[] = ['symbol' => $exSym, 'orphan' => true];
                    }
                }
                $effectiveActiveTrades = array_values(array_merge($activeTrades, $orphanTrades));
            } else {
                $effectiveActiveTrades = array_values($activeTrades);
            }
            
            // ============================================================
            // Step 3: Check limits (with effective count)
            // ============================================================
            $effectiveOpenSymbols = array_map(function($t) { return $t['symbol'] ?? ''; }, $effectiveActiveTrades);
            
            // Brain-owned execution limits enforcement
            if ($isBrainControlled) {
                $brainLimits = $intent['execution_limits_snapshot'] ?? [];
                $brainMaxPositions = (int)($brainLimits['live_max_positions'] ?? 0);
                $brainOnePerSymbol = (bool)($brainLimits['live_one_trade_per_symbol'] ?? true);

                if ($brainMaxPositions > 0 && count($effectiveActiveTrades) >= $brainMaxPositions) {
                    $result['execution_stage'] = 'execution_guard_blocked';
                    return $this->rejectIntent($intent, 'skipped_max_positions_reached',
                        "Brain limit: max {$brainMaxPositions} positions reached (current: " . count($effectiveActiveTrades) . ")", $result, [
                            'effective_live_max_positions' => $brainMaxPositions,
                            'current_positions' => count($effectiveActiveTrades),
                            'limits_controlled_by_brain' => true,
                        ]);
                }

                if ($brainOnePerSymbol && in_array($symbol, $effectiveOpenSymbols, true)) {
                    $result['execution_stage'] = 'execution_guard_blocked';
                    // Find related active trade for linkage
                    $relatedTrade = null;
                    foreach ($activeTrades as $at) {
                        if (($at['symbol'] ?? '') === $symbol) {
                            $relatedTrade = $at;
                            break;
                        }
                    }
                    return $this->rejectIntent($intent, 'skipped_active_trade_exists',
                        "Brain limit: one trade per symbol — {$symbol} already open", $result, [
                            'effective_live_one_trade_per_symbol' => true,
                            'blocked_symbol' => $symbol,
                            'related_active_trade_id' => $relatedTrade['id'] ?? null,
                            'related_position_symbol' => $symbol,
                            'open_since' => $relatedTrade['opened_at'] ?? $relatedTrade['created_at'] ?? null,
                            'limits_controlled_by_brain' => true,
                        ]);
                }
            }

            $limitsCheck = $this->riskEngine->checkLimits($risk, count($effectiveActiveTrades), $effectiveOpenSymbols, $symbol);
            if (!$limitsCheck['allowed']) {
                $result['execution_stage'] = 'execution_guard_blocked';
                return $this->rejectIntent($intent, 'rejected_limits', $limitsCheck['reason'], $result);
            }
            
            
            // ============================================================
            // Step 3.2: Entry freshness (timeout) guard - ALL modes
            // Prevents late "tail chase" entries when intents sit in the queue.
            // Deadline = min(expires_at, created_ts + timeout_minutes)
            // Sources:
            // - intent.entry_timeout_minutes (from Brain) when present
            // - execution.enter_now_timeout_minutes fallback for enter_now
            // - execution.default_entry_timeout_minutes fallback for legacy
            // ============================================================
            $deadlineInfo = $this->computeEntryDeadline($intent);
            if (($deadlineInfo['exceeded'] ?? false) === true) {
                // P5.13: Late enter_now policy
                // If enter_now timed out, we can switch to wait_retrace instead of hard reject.
                $entryAction = (string)($intent['entry_action'] ?? '');
                $latePolicy = (string)($this->config['execution']['enter_now_late_policy'] ?? 'wait_retrace');

                if ($entryAction === 'enter_now' && $latePolicy === 'wait_retrace') {
                    // Convert to wait_retrace and re-evaluate deadline using wait_retrace rules.
                    $intent['entry_action_original'] = 'enter_now';
                    $intent['entry_action'] = 'wait_retrace';
                    $intent['entry_action_switched_reason'] = 'enter_now_timeout_exceeded';
                    $intent['entry_action_switched_at'] = date('c');

                    $deadlineInfo2 = $this->computeEntryDeadline($intent);
                    if (($deadlineInfo2['exceeded'] ?? false) === true) {
                        // Still exceeded (e.g., expires_at passed) → hard reject
                        $ctx = $deadlineInfo2['context'] ?? [];
                        if (!is_array($ctx)) { $ctx = []; }
                        $ctx['late_policy'] = 'wait_retrace';
                        $ctx['entry_action_original'] = 'enter_now';
                        return $this->rejectIntent($intent, 'rejected_entry_timeout', $deadlineInfo2['reason'] ?? 'entry_timeout_exceeded', $result, [
                            'context' => $ctx,
                        ]);
                    }

                    // Policy applied - continue to wait_retrace gate below.
                    $result['late_policy'] = [
                        'applied' => true,
                        'policy' => 'wait_retrace',
                        'reason' => 'enter_now_timeout_exceeded',
                        'original_deadline_context' => $deadlineInfo['context'] ?? null,
                    ];
                } else {
                    return $this->rejectIntent($intent, 'rejected_entry_timeout', $deadlineInfo['reason'] ?? 'entry_timeout_exceeded', $result, [
                        'context' => $deadlineInfo['context'] ?? null,
                    ]);
                }
            }

// ============================================================
            // Step 4: Price check (late entry) - LIVE only
            // ============================================================
            if ($mode === 'live' && ($this->config['execution']['require_price_check_live'] ?? true)) {
                if ($intent['entry_action'] === 'enter_now') {
                    $lateCheck = $this->checkLateEntry($intent);
                    if (!$lateCheck['ok']) {
                        return $this->rejectIntent($intent, 'rejected_late_entry', $lateCheck['reason'], $result);
                    }
                }
            }
            // ============================================================
            // Step 3.5 (P6.9): wait_retrace entry action gate - BEFORE balance/order
            // ============================================================
            if (($intent['entry_action'] ?? 'enter_now') === 'wait_retrace') {
                $retraceCheck = $this->checkWaitRetrace($intent, $mode);
                if ($retraceCheck['action'] === 'defer') {
                    // Return deferred status - NOT reject, NOT error
                    // Do NOT mark signal executed, do NOT write rejected file
                    $result['ok'] = true;
                    $result['status'] = 'deferred_wait_retrace';
                    $result['opened'] = false;
                    $result['error'] = null;
                    $result['deferred_reason'] = $retraceCheck['reason'] ?? 'wait_retrace_not_reached';
                    $result['deferred_context'] = $retraceCheck['context'] ?? null;
                    return $result;
                } elseif ($retraceCheck['action'] === 'reject') {
                    // Timeout or other hard reject
                    return $this->rejectIntent($intent, 'rejected_entry_timeout', $retraceCheck['reason'], $result, [
                        'context' => $retraceCheck['context'] ?? null,
                    ]);
                }
                // action === 'proceed' → continue to balance/order
            }
            
            // ============================================================
            // Step 4a: P6.6 Balance preflight check - LIVE only
            // ============================================================
            if ($mode === 'live') {
                $balanceCheck = $this->checkBalancePreflight($risk);
                if (!$balanceCheck['ok']) {
                    // P8: Differentiate "insufficient balance" from "balance unavailable".
                    // If gateway could not fetch balance (auth/config/network), we must NOT label it as insufficient.
                    $rejectStatus = 'rejected_insufficient_balance';
                    $reason = (string)($balanceCheck['reason'] ?? 'unknown');
                    if ($reason === 'balance_fetch_failed' || $reason === 'wallet_balance_failed' || $reason === 'wallet_balance_auth_missing' || $reason === 'gateway_not_ready' || $reason === 'client_not_initialized') {
                        $rejectStatus = 'rejected_balance_unavailable';
                    } elseif ($reason === 'balance_below_minimum_threshold') {
                        $rejectStatus = 'rejected_balance_below_minimum';
                    }

                    // P6.8.1: Pass full context with required/available/snapshot for debugging
                    $balanceCtx = [
                        'context' => [
                            'required_usdt' => (float)($balanceCheck['required'] ?? 0.0),
                            'available_usdt' => (float)($balanceCheck['available'] ?? 0.0),
                            'buffer_pct' => (int)($balanceCheck['buffer_pct'] ?? 5),
                            'budget_usdt_per_trade' => (float)($balanceCheck['budget'] ?? 0.0),
                            'account_type' => (string)($this->config['exchange']['account_type'] ?? 'UNIFIED'),
                            'balance_snapshot' => $balanceCheck['balance_snapshot'] ?? $this->balanceCache ?? null,
                            'coin' => $balanceCheck['coin'] ?? 'USDT',
                            'shortfall' => $balanceCheck['shortfall'] ?? null,
                        ],
                        'balance_check' => $balanceCheck, // Keep full result for backward compat
                    ];
                    return $this->rejectIntent($intent, $rejectStatus, $reason, $result, $balanceCtx);
                }
            }
            
            // ============================================================
            // Step 4b: Set leverage - LIVE only
            // ============================================================
            $result['execution_stage'] = 'exchange_prepare_started';
            if ($mode === 'live') {
                $leverage = (int)($risk['leverage'] ?? 1);
                $leverageResult = $this->setLeverageOnExchange($symbol, $leverage);
                if (!$leverageResult['success']) {
                    $result['execution_stage'] = 'exchange_prepare_failed';
                    // Provide full context for UI explainability (no SSH needed)
                    $ctx = [
                        'leverage_requested' => $leverage,
                        'leverage_error' => $leverageResult['error'] ?? 'unknown',
                    ];
                    if (isset($leverageResult['ret_code'])) {
                        $ctx['leverage_ret_code'] = $leverageResult['ret_code'];
                        $result['exchange_response_code'] = $leverageResult['ret_code'];
                    }
                    if (isset($leverageResult['ret_msg'])) {
                        $ctx['leverage_ret_msg'] = $leverageResult['ret_msg'];
                        $result['exchange_response_message'] = $leverageResult['ret_msg'];
                    }
                    if (isset($leverageResult['response'])) {
                        $ctx['leverage_response'] = $leverageResult['response'];
                    }

                    return $this->rejectIntent($intent, 'rejected_leverage_failed', $leverageResult['error'] ?? 'unknown', $result, $ctx);
                }

                // If gateway clamped leverage (instrument max / risk limit), use effective leverage for sizing.
                $effectiveLeverage = (int)($leverageResult['effective'] ?? $leverage);
                if ($effectiveLeverage < 1) {
                    $effectiveLeverage = 1;
                }

                if ($effectiveLeverage !== $leverage) {
                    $requestedLeverage = (int)($leverageResult['requested'] ?? $leverage);

                    $risk['leverage'] = $effectiveLeverage;
                    $intent['risk']['leverage'] = $effectiveLeverage;
                    $leverage = $effectiveLeverage;

                    // Surface as warning for observability in last_run.json
                    if (property_exists($this, 'warnings') && is_array($this->warnings)) {
                        $metaMax = $leverageResult['meta_max'] ?? null;
                        $note = (string)($leverageResult['note'] ?? 'leverage_clamped');

                        $msg = "Leverage clamped for {$symbol}: requested {$requestedLeverage}x -> effective {$effectiveLeverage}x ({$note})";
                        if ($metaMax !== null) {
                            $msg .= " meta_max={$metaMax}";
                        }
                        $this->warnings[] = $msg;
                    }
                }            // ============================================================
            // Step 4c: Calculate position size (AFTER leverage is resolved)
            // ============================================================
            $positionSize = $this->riskEngine->calculatePositionSize($risk, $intent['entry_price'], $symbol);
            if ($positionSize <= 0) {
                return $this->rejectIntent($intent, 'rejected_validation', 'position_size_zero', $result);
            }


            }
            
            // ============================================================
            // Step 5: Submit market order
            // ============================================================
            $result['execution_stage'] = 'exchange_submit_started';
            $orderLinkId = 'tb_' . substr($signalId, 0, 32);
            $order = $this->buildOrder($intent, $positionSize, $risk, $orderLinkId);
            
            if ($mode === 'live') {
                $result['exchange_submit_attempted'] = true;
                $orderResult = $this->submitOrder($order);
            } else {
                $result['exchange_submit_attempted'] = true;
                $orderResult = $this->simulateOrder($order);
            }
            
            if (!$orderResult['ok']) {
                $result['execution_stage'] = 'exchange_submit_failed';
                // P0.4: Capture exchange error details for runtime visibility
                $result['exchange_response_code'] = $orderResult['ret_code'] ?? ($orderResult['response']['retCode'] ?? null);
                $result['exchange_response_message'] = $orderResult['error'] ?? ($orderResult['response']['retMsg'] ?? null);
                return $this->rejectIntent($intent, 'rejected_order_failed', $orderResult['error'] ?? 'unknown', $result);
            }
            
            $result['order_id'] = $orderResult['order_id'] ?? null;
            $result['opened'] = true;
            $result['filled'] = $orderResult['filled'] ?? false;
            $result['execution_stage'] = 'order_submitted';
            
            // ============================================================
            // Step 6: Post-open reconcile (LIVE only)
            // ============================================================
            if ($mode === 'live') {
                $result['execution_stage'] = 'position_open_confirmed';
                $positionData = $this->fetchOpenPosition($symbol, $side);
                
                if ($positionData === null || !$this->isValidPositionData($positionData)) {
                    // Failed to get position data - fail-safe close
                    $this->performFailSafeClose($intent, $symbol, $side, $positionSize, 'reconcile_failed', [
                        'order_result' => $orderResult,
                        'position_data' => $positionData,
                    ], $result);
                    return $result;
                }
                
                $entryAvg = (float)($positionData['avgPrice'] ?? $positionData['entry_price'] ?? 0);
                $liqPrice = (float)($positionData['liqPrice'] ?? 0);
                $positionIdx = (int)($positionData['positionIdx'] ?? $this->config['exchange']['position_idx'] ?? 0);
                $actualQty = (float)($positionData['size'] ?? $positionData['qty'] ?? $positionSize);
                
                // ============================================================
                // Step 7: Calculate protection
                // ============================================================
                $result['execution_stage'] = 'protection_apply_started';
                
                // Determine stop control mode from risk block
                $stopControlMode = (string)($risk['stop_control']['stop_control_mode'] ?? ($risk['stop_control_mode'] ?? 'auto'));
                
                if ($stopControlMode === 'entry_roi') {
                    // Entry-based stop: SL = entry price ± stop_loss_from_entry_roi
                    $sl = $this->riskEngine->calculateStopLossFromEntry($risk, $entryAvg, $side);
                    $result['stop_control_mode_used'] = 'entry_roi';
                    $result['stop_loss_from_entry_roi'] = (float)($risk['stop_control']['stop_loss_from_entry_roi'] ?? 0);
                } else {
                    // Legacy/auto: SL from liquidation distance
                    $sl = $this->riskEngine->calculateStopLossFromLiq($risk, $entryAvg, $liqPrice, $side);
                    $result['stop_control_mode_used'] = $stopControlMode;
                }
                
                if ($sl === null) {
                    // Cannot calculate SL - fail-safe close
                    $result['execution_stage'] = 'protection_apply_failed';
                    $this->performFailSafeClose($intent, $symbol, $side, $actualQty, 'sl_calculation_failed', [
                        'order_result' => $orderResult,
                        'position_data' => $positionData,
                        'entry_avg' => $entryAvg,
                        'liq_price' => $liqPrice,
                    ], $result);
                    return $result;
                }
                
                $trailing = $this->riskEngine->calculateTrailingParams($risk, $entryAvg, $side);
                
                // ============================================================
                // Step 8: Set trading-stop (SL + trailing if enabled)
                // P6.6: Trailing only sent if enable_trailing_on_open=true
                // V2 FIX: In Brain-controlled mode, trailing decision comes from
                // the normalized Brain contract — bot-local toggles are overridden.
                // ============================================================
                $tradingStopOptions = [
                    'position_idx' => $positionIdx,
                    'stop_loss' => $sl,
                ];
                
                // V2 FIX: Brain-controlled trailing overrides bot-local enable_trailing_on_open
                if ($isBrainControlled) {
                    // Brain decides trailing behavior — bot-local toggle ignored
                    if ($trailing['enabled']) {
                        $tradingStopOptions['trailing_stop'] = $trailing['trailing_stop'];
                        $tradingStopOptions['active_price'] = $trailing['active_price'];
                    }
                } else {
                    // Legacy mode: P6.6 Phase-1 trailing toggle still applies
                    $enableTrailingOnOpen = (bool)($this->config['execution']['enable_trailing_on_open'] ?? false);
                    if ($trailing['enabled'] && $enableTrailingOnOpen) {
                        $tradingStopOptions['trailing_stop'] = $trailing['trailing_stop'];
                        $tradingStopOptions['active_price'] = $trailing['active_price'];
                    }
                }
                
                // P4: Pass side for proper price normalization
                $tradingStopResult = $this->gateway->setTradingStop($symbol, $side, $tradingStopOptions);
                
                if (!$tradingStopResult['success']) {
                    // P6: SL failed to set - fail-safe close
                    $result['execution_stage'] = 'protection_apply_failed';
                    $this->performFailSafeClose($intent, $symbol, $side, $actualQty, 'sl_set_failed', [
                        'order_result' => $orderResult,
                        'position_data' => $positionData,
                        'trading_stop_options' => $tradingStopOptions,
                        'trading_stop_result' => $tradingStopResult,
                    ], $result);
                    return $result;
                }
                
                // ============================================================
                // Step 9: Success - save trade as opened_protected
                // ============================================================
                $trade = $this->buildTradeLiveV1($intent, $order, $orderResult, $positionData, $sl, $trailing);
                $this->store->saveActiveTrade($trade);
                
                $result['trade_id'] = $trade['trade_id'];
                $result['status'] = 'opened_protected';
                $result['ok'] = true;
                $result['execution_stage'] = 'finished';
                
                $executionKey = $this->getExecutionIdentityKey($intent);
                $dedupeBasis = !empty($intent['brain_controlled']) ? 'intent_id' : 'legacy_signal_id';
                $this->markSignalExecuted($executionKey, $result, $dedupeBasis);
                $this->store->saveOrder($order, $orderResult);
                
            } else {
                // Dry/test mode - simpler flow
                $trade = $this->buildTrade($intent, $order, $orderResult);
                $this->store->saveActiveTrade($trade);
                
                $result['trade_id'] = $trade['trade_id'];
                $result['status'] = 'opened_dry';
                $result['execution_stage'] = 'finished';
                
                $executionKey = $this->getExecutionIdentityKey($intent);
                $dedupeBasis = !empty($intent['brain_controlled']) ? 'intent_id' : 'legacy_signal_id';
                $this->markSignalExecuted($executionKey, $result, $dedupeBasis);
                $this->store->saveOrder($order, $orderResult);
            }
            
        } catch (\Throwable $e) {
            $result['ok'] = false;
            $result['error'] = 'Exception: ' . $e->getMessage();
            $result['status'] = 'error';
        }
        
        return $result;
    }
    
    /**
     * Reject intent and mark as executed
     * 
     * @param array $intent Intent data
     * @param string $status Rejection status
     * @param string $reason Rejection reason
     * @param array &$result Result reference
     * @param array $context Optional additional context for P3 orphan positions
     * @return array Result
     */
    private function rejectIntent(array $intent, string $status, string $reason, array &$result, array $context = []): array
    {
        $result['ok'] = false;
        $result['status'] = $status;
        $result['error'] = $reason;
        
        $this->store->saveRejectedIntent($intent, array_merge([
            'reason' => $reason,
            'status' => $status,
        ], $context));
        
        $dedupeBasis = !empty($intent['brain_controlled']) ? 'intent_id' : 'legacy_signal_id';
        $this->markSignalExecuted($this->getExecutionIdentityKey($intent), $result, $dedupeBasis);
        
        return $result;
    }
    
    /**
     * P6 + P0.3: Perform fail-safe close and mark as rejected
     * 
     * P0.3 FIX: Verify close success and write CRITICAL if failed.
     */
    private function performFailSafeClose(
        array $intent,
        string $symbol,
        string $side,
        float $qty,
        string $reason,
        array $context,
        array &$result
    ): void {
        $closeResult = null;
        $closeOk = false;
        // V2 FIX: Use unified execution identity key for all paths (Brain intent_id or legacy signal_id)
        $intentId = $this->getExecutionIdentityKey($intent);
        
        // Attempt to close position
        if ($this->gateway && $this->gateway->isInitialized() && $qty > 0) {
            $closeResult = $this->gateway->closePosition($symbol, $side, $qty);
            $closeOk = $closeResult['success'] ?? false;
            
            // P0.3: If close failed - this is CRITICAL! Position is UNPROTECTED!
            if (!$closeOk) {
                // Log CRITICAL error to errors.log
                $this->store->appendErrorLog([
                    'level' => 'CRITICAL',
                    'ts' => date('c'),
                    'event' => 'fail_safe_close_failed',
                    'message' => 'CRITICAL: Fail-safe close FAILED - position may be UNPROTECTED!',
                    'intent_id' => $intentId,
                    'symbol' => $symbol,
                    'side' => $side,
                    'qty' => $qty,
                    'reason' => $reason,
                    'order_id' => $context['order_result']['order_id'] ?? null,
                    'order_link_id' => $context['order_result']['response']['result']['orderLinkId'] ?? null,
                    'trading_stop_response' => $context['trading_stop_result'] ?? null,
                    'close_response' => $closeResult,
                ]);
                
                // Also log to PHP error log for immediate visibility
                // Sanitize values to prevent log injection
                $safeIntentId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$intentId);
                $safeSymbol = preg_replace('/[^a-zA-Z0-9]/', '', (string)$symbol);
                $safeSide = preg_replace('/[^a-zA-Z]/', '', (string)$side);
                error_log("CRITICAL: TradingBot fail-safe close FAILED for {$safeIntentId} ({$safeSymbol} {$safeSide}) - position may be UNPROTECTED!");
            }
        }
        
        // P0.3: Include close_ok in context for debugging
        $rejectionContext = array_merge($context, [
            'close_result' => $closeResult,
            'close_ok' => $closeOk,
            'qty' => $qty,
        ]);
        
        // Save rejected execution with full context
        $this->store->saveRejectedExecution($intent, $reason, $rejectionContext);
        
        // Update result
        $result['ok'] = false;
        
        // P0.3: Use specific status if close failed
        if (!$closeOk && $qty > 0) {
            $result['status'] = 'critical_unprotected_position_close_failed';
            $result['error'] = "fail_safe_close_failed:{$reason}";
        } else {
            $result['status'] = 'rejected_' . $reason;
            $result['error'] = $reason;
        }
        
        // V2 FIX: Use unified execution identity key (same as used everywhere else)
        $dedupeBasis = !empty($intent['brain_controlled']) ? 'intent_id' : 'legacy_signal_id';
        $this->markSignalExecuted($this->getExecutionIdentityKey($intent), $result, $dedupeBasis);
    }
    
    /**
     * P3: Get exchange open positions with caching
     * 
     * Caches positions to avoid hitting API on every intent check.
     * 
     * @param int|null $ttlSec Cache TTL in seconds (null = use config)
     * @return array Open positions with size > 0
     */
    private function getExchangeOpenPositionsCached(?int $ttlSec = null): array
    {
        // Only for LIVE mode
        if (!$this->isLiveMode()) {
            return [];
        }
        
        if (!$this->gateway || !$this->gateway->isInitialized()) {
            return [];
        }
        
        $ttl = $ttlSec ?? (int)($this->config['execution']['exchange_positions_cache_ttl_sec'] ?? 2);
        $now = time();
        
        // Return from cache if fresh
        if (!empty($this->exchangeOpenPositionsCache) && ($now - $this->exchangeOpenPositionsCacheTs) < $ttl) {
            return $this->exchangeOpenPositionsCache;
        }
        
        try {
            $allPositions = $this->gateway->getPositions();
            
            // Filter to only open positions (size > 0)
            $openPositions = [];
            foreach ($allPositions as $position) {
                $size = (float)($position['size'] ?? 0);
                if (abs($size) > 0) {
                    $openPositions[] = $position;
                }
            }
            
            // Update cache
            $this->exchangeOpenPositionsCache = $openPositions;
            $this->exchangeOpenPositionsCacheTs = $now;
            
            return $openPositions;
            
        } catch (\Throwable $e) {
            error_log("TradingBot: getExchangeOpenPositionsCached error: " . $e->getMessage());
            return $this->exchangeOpenPositionsCache; // Return stale cache on error
        }
    }
    
    /**
     * P6: Get available margin with caching
     * 
     * @return float|null Available margin in USDT (or null on error)
     */
        private function getAvailableMarginCached(): ?float
    {
        // Only for LIVE mode
        if (!$this->isLiveMode()) {
            return null;
        }

        if (!$this->gateway || !$this->gateway->isInitialized()) {
            return null;
        }

        $ttl = (int)($this->config['execution']['balance_cache_ttl_sec'] ?? 2);
        $now = time();

        // Return from cache if fresh
        if ($this->balanceCache !== null && ($now - $this->balanceCacheTs) < $ttl) {
            // If cached snapshot is an error, treat as unavailable but keep snapshot for UI.
            if (isset($this->balanceCache['error'])) {
                return null;
            }

            // P6.6: Use available ?? available_usd fallback
            return $this->balanceCache['available'] ?? $this->balanceCache['available_usd'] ?? null;
        }

        try {
            $balanceData = $this->gateway->getWalletBalance();

            // Always cache snapshot (including error payload) for UI diagnostics.
            if (is_array($balanceData)) {
                $this->balanceCache = $balanceData;
                $this->balanceCacheTs = $now;
            }

            if ($balanceData === null) {
                return null;
            }

            // P6.6: Check for error response from getWalletBalance
            if (is_array($balanceData) && isset($balanceData['error'])) {
                error_log("TradingBot: getWalletBalance error: " . (string)($balanceData['error'] ?? 'unknown'));
                return null;
            }

            // P6.6: Use available ?? available_usd fallback
            if (is_array($balanceData)) {
                return $balanceData['available'] ?? $balanceData['available_usd'] ?? null;
            }

            return null;

        } catch (\Throwable $e) {
            error_log("TradingBot: getAvailableMarginCached error: " . $e->getMessage());

            // Return stale cache on error (if it exists and is not an error payload)
            if (is_array($this->balanceCache) && !isset($this->balanceCache['error'])) {
                return $this->balanceCache['available'] ?? $this->balanceCache['available_usd'] ?? null;
            }

            return null;
        }
    }
    
    /**
     * P6.6: Preflight balance check
     * 
     * Verifies there is enough available margin before opening a position.
     * 
     * @param array $risk Risk parameters
     * @return array ['ok' => bool, 'reason' => string|null, 'available' => float, 'required' => float, 'balance_snapshot' => array]
     */
    private function checkBalancePreflight(array $risk): array
    {
        $budget = (float)($risk['budget_usdt_per_trade'] ?? 0.0);
        $bufferPct = (float)($this->config['execution']['balance_required_buffer_pct'] ?? 5.0);
        $rejectBelow = (float)($this->config['execution']['balance_reject_below_usdt'] ?? 0.0);
        
        // Required margin = budget * (1 + buffer%)
        $required = $budget * (1.0 + $bufferPct / 100.0);
        
        $available = $this->getAvailableMarginCached();
        
        // P6.6: Include balance_snapshot in all responses
        $balanceSnapshot = $this->balanceCache ?? null;
        
                // If we couldn't get balance, reject for safety
        if ($available === null) {
            // Prefer a specific error code (if gateway returned one) for UI visibility.
            $reason = 'balance_fetch_failed';
            if (is_array($balanceSnapshot) && isset($balanceSnapshot['error'])) {
                $reason = (string)$balanceSnapshot['error'];
            }

            return [
                'ok' => false,
                'reason' => $reason,
                'available' => 0.0,
                'required' => $required,
                'budget' => $budget,
                'buffer_pct' => $bufferPct,
                'coin' => $this->config['exchange']['balance_coin'] ?? 'USDT',
                'balance_snapshot' => $balanceSnapshot,
            ];
        }// Check minimum threshold
        if ($rejectBelow > 0.0 && $available < $rejectBelow) {
            return [
                'ok' => false,
                'reason' => 'balance_below_minimum_threshold',
                'available' => $available,
                'required' => $required,
                'budget' => $budget,
                'buffer_pct' => $bufferPct,
                'reject_below_usdt' => $rejectBelow,
                'coin' => $this->config['exchange']['balance_coin'] ?? 'USDT',
                'balance_snapshot' => $balanceSnapshot,
            ];
        }
        
        // Check if enough margin
        if ($available < $required) {
            return [
                'ok' => false,
                'reason' => 'insufficient_margin',
                'available' => $available,
                'required' => $required,
                'budget' => $budget,
                'buffer_pct' => $bufferPct,
                'shortfall' => $required - $available,
                'coin' => $this->config['exchange']['balance_coin'] ?? 'USDT',
                'balance_snapshot' => $balanceSnapshot,
            ];
        }
        
        return [
            'ok' => true,
            'reason' => null,
            'available' => $available,
            'required' => $required,
            'budget' => $budget,
            'buffer_pct' => $bufferPct,
            'coin' => $this->config['exchange']['balance_coin'] ?? 'USDT',
            'balance_snapshot' => $balanceSnapshot,
        ];
    }
    
    /**
     * P3: Fetch open position from exchange with retries and SIDE MATCH
     * 
     * Side matching is CRITICAL for correct SL calculation:
     * - long → position.side == 'Buy'
     * - short → position.side == 'Sell'
     */
    private function fetchOpenPosition(string $symbol, string $side): ?array
    {
        if (!$this->gateway || !$this->gateway->isInitialized()) {
            return null;
        }
        
        $retries = (int)($this->config['execution']['post_open_reconcile_retries'] ?? 8);
        $delayMs = (int)($this->config['execution']['post_open_reconcile_delay_ms'] ?? 250);
        $normalizedSide = strtolower($side);
        
        // P3: Map side to Bybit side for matching
        $expectedExchangeSide = ($normalizedSide === 'long') ? 'Buy' : 'Sell';
        
        for ($i = 0; $i < $retries; $i++) {
            try {
                $positions = $this->gateway->getPositions();
                
                foreach ($positions as $position) {
                    $posSymbol = $position['symbol'] ?? '';
                    $posSize = (float)($position['size'] ?? 0);
                    $posSide = $position['side'] ?? '';
                    
                    // Match symbol and check for non-zero position
                    if ($posSymbol === $symbol && abs($posSize) > 0) {
                        // P3 FIX: Must also match SIDE
                        if ($posSide === $expectedExchangeSide) {
                            return $position;
                        }
                        // Wrong side - log but continue searching
                        error_log("TradingBot: fetchOpenPosition found {$symbol} but side mismatch: expected {$expectedExchangeSide}, got {$posSide}");
                    }
                }
            } catch (\Throwable $e) {
                // Log retry attempt for debugging
                error_log("TradingBot: fetchOpenPosition retry {$i}/{$retries} for {$symbol}: " . $e->getMessage());
            }
            
            // Exponential backoff: delay increases with each retry
            if ($i < $retries - 1) {
                $backoffDelay = $delayMs * pow(1.5, $i);
                usleep((int)($backoffDelay * 1000));
            }
        }
        
        return null;
    }
    
    /**
     * Check if position data is valid for SL calculation
     */
    private function isValidPositionData(?array $data): bool
    {
        if ($data === null) {
            return false;
        }
        
        $size = (float)($data['size'] ?? $data['qty'] ?? 0);
        $avgPrice = (float)($data['avgPrice'] ?? $data['entry_price'] ?? 0);
        $liqPrice = (float)($data['liqPrice'] ?? 0);
        
        return $size > 0 && $avgPrice > 0 && $liqPrice > 0;
    }
    
    /**
     * Derive contract generation label from exit mode string.
     */
    private function deriveContractGeneration(string $exitMode): string
    {
        return match ($exitMode) {
            'hybrid_tp' => 'v3_hybrid',
            'trailing_tp' => 'v2_trailing',
            'fixed_tp' => 'v1_fixed',
            default => 'v1_fixed',
        };
    }

    /**
     * Build trade for LIVE Phase-1 (trade_live_v1 schema)
     */
    private function buildTradeLiveV1(
        array $intent,
        array $order,
        array $orderResult,
        array $positionData,
        float $sl,
        array $trailing
    ): array {
        $entryAvg = (float)($positionData['avgPrice'] ?? $positionData['entry_price'] ?? $intent['entry_price']);

        $riskTrailing = $intent['risk']['trailing'] ?? [];
        $trailingEnabled = (bool)($riskTrailing['enabled'] ?? false);
        $exitMode = (string)($riskTrailing['exit_mode'] ?? 'unknown');
        $beEnabled = (bool)($riskTrailing['break_even_enabled'] ?? false);
        $initialProtectionState = ($sl > 0) ? 'opened_protected' : 'opened_unprotected';
        $contractGeneration = $this->deriveContractGeneration($exitMode);
        $effectiveSource = !empty($riskTrailing['brain_trailing_applied'])
            ? 'brain_trailing_contract'
            : (!empty($riskTrailing['effective_trailing_contract_source'])
                ? $riskTrailing['effective_trailing_contract_source']
                : 'bot_local_config');

        return [
            'schema_version' => 'trade_live_v2',
            'trade_id' => $intent['signal_id'] ?? $intent['id'],
            'signal_id' => $intent['signal_id'] ?? $intent['id'],
            'symbol' => $intent['symbol'],
            'side' => $intent['side'],
            'mode' => 'live',
            'opened_at' => date('c'),
            'risk' => $intent['risk'],
            'exchange' => [
                'order_id' => $orderResult['order_id'] ?? null,
                'order_link_id' => $order['order_link_id'] ?? null,
                'position_idx' => (int)($positionData['positionIdx'] ?? $this->config['exchange']['position_idx'] ?? 0),
                'qty' => (float)($positionData['size'] ?? $order['qty']),
                'entry_avg_price' => $entryAvg,
                'liq_price' => (float)($positionData['liqPrice'] ?? 0),
            ],
            'protection' => [
                'stop_loss_price' => $sl,
                'sl_trigger_by' => $this->config['exchange']['sl_trigger_by'] ?? 'IndexPrice',
                'tpsl_mode' => $this->config['exchange']['tpsl_mode'] ?? 'Full',
                'trailing_enabled' => $trailing['enabled'] ?? false,
                'trailing_stop' => $trailing['trailing_stop'] ?? null,
                'active_price' => $trailing['active_price'] ?? null,
                'stop_control_mode' => (string)($intent['risk']['stop_control']['stop_control_mode'] ?? ($intent['risk']['stop_control_mode'] ?? 'auto')),
                'stop_loss_from_entry_roi' => ($intent['risk']['stop_control']['stop_control_mode'] ?? 'auto') === 'entry_roi'
                    ? (float)($intent['risk']['stop_control']['stop_loss_from_entry_roi'] ?? 0)
                    : null,
            ],
            // Top-level effective post-entry contract (mirrors runtime, consistent from creation)
            'protection_state' => $initialProtectionState,
            'trailing_enabled' => $trailingEnabled,
            'trailing_active' => false,
            'break_even_enabled' => $beEnabled,
            'break_even_armed' => false,
            'break_even_applied' => false,
            'effective_exit_mode' => $exitMode,
            'effective_trailing_activation' => (float)($riskTrailing['activation_roi_pct'] ?? 0),
            'effective_break_even_activation' => $beEnabled ? (float)($riskTrailing['break_even_activation_roi'] ?? 0) : null,
            'effective_drawdown_factor' => (float)($riskTrailing['drawdown_factor'] ?? 0),
            'effective_hybrid_tp_share' => $exitMode === 'hybrid_tp'
                ? (float)($riskTrailing['hybrid_tp_share'] ?? 0)
                : null,
            'effective_fixed_take_profit_roi' => (float)($riskTrailing['fixed_take_profit_roi'] ?? 0),
            'effective_trailing_contract_source' => $effectiveSource,
            // Stop mode truth (top-level for operator observability)
            'effective_stop_control_mode' => (string)($intent['risk']['stop_control']['stop_control_mode'] ?? ($intent['risk']['stop_control_mode'] ?? 'auto')),
            'effective_stop_loss_from_entry_roi' => ($intent['risk']['stop_control']['stop_control_mode'] ?? 'auto') === 'entry_roi'
                ? (float)($intent['risk']['stop_control']['stop_loss_from_entry_roi'] ?? 0)
                : null,
            'effective_stop_price' => ($sl > 0) ? round($sl, 8) : null,
            // Contract generation tracking (Part 1-3: opened_with vs current)
            'opened_with_exit_mode' => $exitMode,
            'opened_with_contract_generation' => $contractGeneration,
            'current_effective_contract_generation' => $contractGeneration,
            'contract_migrated' => false,
            // Legacy fields for compatibility
            'entry_price' => $entryAvg,
            'position_size' => (float)($positionData['size'] ?? $order['qty']),
            'leverage' => $intent['risk']['leverage'] ?? 1,
            'order_id' => $orderResult['order_id'] ?? null,
            'status' => 'active',
            'opened_ts' => time(),
            'high_watermark' => $entryAvg,
            'low_watermark' => $entryAvg,
            'last_price' => $entryAvg,
            'last_update' => date('c'),
        ];
    }
    
    /**
     * Update active positions - Phase-1 safety reconcile only
     * 
     * Phase-1: Bot does NOT manage profit, only safety:
     * - If position closed on exchange -> move to closed
     * - If SL missing on exchange -> try to set, if fails -> fail-safe close
     * - NO takeProfit, NO trailing updates, NO local closes
     * 
     * @param string $mode Execution mode
     * @return array Update result
     */
    protected function updateActivePositions(string $mode): array
    {
        $result = [
            'updated' => 0,
            'closed' => 0,
            'closed_by_logical_stop' => 0,
            'closed_by_exchange' => 0,
            'errors' => [],
            'warnings' => [],
            'trailing_applied' => 0,
            'trailing_failed' => 0,
            'trailing_skipped' => 0,
            'step_trailing_applied' => 0,
            'step_trailing_failed' => 0,
            'step_trailing_skipped' => 0,
            'break_even_applied' => 0,
            'hybrid_partial_applied' => 0,
        ];
        
        if ($mode !== 'live') {
            return $result;
        }
        
        $trades = $this->store->loadActiveTrades();
        
        foreach ($trades as $tradeId => $trade) {
            try {
                // Get position from exchange
                $position = $this->fetchOpenPosition($trade['symbol'], $trade['side']);
                
                if ($position === null || (float)($position['size'] ?? 0) <= 0) {
                    // Position closed on exchange — determine close reason
                    $rt = is_array($trade['runtime'] ?? null) ? $trade['runtime'] : [];
                    $closeReason = 'exchange_closed';
                    if (!empty($rt['dumb_trailing_applied'])) {
                        $closeReason = 'closed_by_trailing';
                    } elseif (!empty($rt['break_even_applied'])) {
                        $closeReason = 'closed_by_break_even';
                    } elseif (!empty($rt['close_trigger']) && $rt['close_trigger'] === 'logical_stop') {
                        $closeReason = 'closed_by_logical_stop';
                    }
                    $result['closed']++;
                    $result['closed_by_exchange']++;
                    $this->store->moveTradeToClosedDir($tradeId, array_merge($trade, [
                        'closed_at' => date('c'),
                        'close_reason' => $closeReason,
                        'close_protection_state' => (string)($rt['protection_state'] ?? 'unknown'),
                    ]));
                    continue;
                }

                // Compute protection state for this trade
                $runtime = is_array($trade['runtime'] ?? null) ? $trade['runtime'] : [];
                $prot = is_array($trade['protection'] ?? null) ? $trade['protection'] : [];
                $riskTrailing = $trade['risk']['trailing'] ?? [];

                $protectionState = 'opened_unprotected';
                if ((float)($prot['stop_loss_price'] ?? 0) > 0) {
                    $protectionState = 'opened_protected';
                }
                if (!empty($runtime['break_even_armed'])) {
                    $protectionState = 'break_even_armed';
                }
                if (!empty($runtime['break_even_applied'])) {
                    $protectionState = 'break_even_applied';
                }
                if (!empty($runtime['dumb_trailing_applied']) || !empty($prot['trailing_stop'])) {
                    $protectionState = 'trailing_active';
                }

                $runtime['protection_state'] = $protectionState;
                $runtime['effective_trailing_contract_source'] = !empty($riskTrailing['brain_trailing_applied'])
                    ? 'brain_trailing_contract'
                    : (!empty($riskTrailing['effective_trailing_contract_source'])
                        ? $riskTrailing['effective_trailing_contract_source']
                        : 'bot_local_config');

                // Persist effective post-entry contract fields from risk.trailing into trade runtime.
                // These evolve each cycle so the active trade snapshot always reflects real state.
                $runtime['trailing_enabled'] = (bool)($riskTrailing['enabled'] ?? false);
                $runtime['trailing_active'] = ($protectionState === 'trailing_active');
                $runtime['break_even_enabled'] = (bool)($riskTrailing['break_even_enabled'] ?? false);
                $runtime['effective_exit_mode'] = (string)($riskTrailing['exit_mode'] ?? 'unknown');
                $runtime['effective_trailing_activation'] = (float)($riskTrailing['activation_roi_pct'] ?? 0);
                $runtime['effective_break_even_activation'] = (float)($riskTrailing['break_even_activation_roi'] ?? 0);
                $runtime['effective_drawdown_factor'] = (float)($riskTrailing['drawdown_factor'] ?? 0);
                $runtime['effective_hybrid_tp_share'] = ($riskTrailing['exit_mode'] ?? '') === 'hybrid_tp'
                    ? (float)($riskTrailing['hybrid_tp_share'] ?? 0)
                    : null;
                $runtime['effective_fixed_take_profit_roi'] = (float)($riskTrailing['fixed_take_profit_roi'] ?? 0);

                // Stop mode truth: persist into runtime for observability
                $tradeStopControl = is_array($trade['risk']['stop_control'] ?? null) ? $trade['risk']['stop_control'] : [];
                $tradeStopMode = (string)($prot['stop_control_mode'] ?? ($tradeStopControl['stop_control_mode'] ?? 'auto'));
                $runtime['effective_stop_control_mode'] = $tradeStopMode;
                $runtime['effective_stop_loss_from_entry_roi'] = $tradeStopMode === 'entry_roi'
                    ? (float)($prot['stop_loss_from_entry_roi'] ?? ($tradeStopControl['stop_loss_from_entry_roi'] ?? 0))
                    : null;
                $runtime['effective_stop_price'] = (float)($prot['stop_loss_price'] ?? 0) > 0
                    ? round((float)$prot['stop_loss_price'], 8)
                    : null;

                $trade['runtime'] = $runtime;

                // Mirror runtime truth into top-level fields (Option A: no conflicting nulls)
                $trade['protection_state'] = $runtime['protection_state'];
                $trade['trailing_enabled'] = $runtime['trailing_enabled'];
                $trade['trailing_active'] = $runtime['trailing_active'];
                $trade['break_even_enabled'] = $runtime['break_even_enabled'];
                $trade['break_even_armed'] = !empty($runtime['break_even_armed']);
                $trade['break_even_applied'] = !empty($runtime['break_even_applied']);
                $trade['effective_exit_mode'] = $runtime['effective_exit_mode'];
                $trade['effective_trailing_activation'] = $runtime['effective_trailing_activation'];
                $trade['effective_break_even_activation'] = $runtime['break_even_enabled']
                    ? $runtime['effective_break_even_activation']
                    : null;
                $trade['effective_drawdown_factor'] = $runtime['effective_drawdown_factor'];
                $trade['effective_hybrid_tp_share'] = $runtime['effective_hybrid_tp_share'];
                $trade['effective_fixed_take_profit_roi'] = $runtime['effective_fixed_take_profit_roi'];
                $trade['effective_trailing_contract_source'] = $runtime['effective_trailing_contract_source'];
                // Stop mode truth: mirror into top-level
                $trade['effective_stop_control_mode'] = $runtime['effective_stop_control_mode'];
                $trade['effective_stop_loss_from_entry_roi'] = $runtime['effective_stop_loss_from_entry_roi'];
                $trade['effective_stop_price'] = $runtime['effective_stop_price'];
                if (($trade['schema_version'] ?? '') === 'trade_live_v1') {
                    $trade['schema_version'] = 'trade_live_v2';
                }

                // Contract generation tracking (Part 1-4: distinguish "opened with" from "currently managed as")
                $currentExitMode = $runtime['effective_exit_mode'];
                $currentGeneration = $this->deriveContractGeneration($currentExitMode);
                $trade['current_effective_contract_generation'] = $currentGeneration;

                // Backfill opened_with_* for legacy trades that predate contract generation tracking
                if (!isset($trade['opened_with_exit_mode'])) {
                    // Legacy trade: record its original exit_mode from the trade's own risk block as "opened_with"
                    $originalExitMode = (string)($trade['risk']['trailing']['exit_mode'] ?? 'unknown');
                    $trade['opened_with_exit_mode'] = $originalExitMode;
                    $trade['opened_with_contract_generation'] = $this->deriveContractGeneration($originalExitMode);
                }

                // Detect if the trade's currently applied contract differs from what it was opened with
                $openedGeneration = (string)($trade['opened_with_contract_generation'] ?? 'unknown');
                $trade['contract_migrated'] = ($openedGeneration !== $currentGeneration);

                // Logical stop check: strategy invalidation exit
                $logicalStop = $trade['risk']['logical_stop'] ?? [];
                if (($logicalStop['enabled'] ?? false)) {
                    $logicalStopRoi = (float)($logicalStop['logical_stop_roi'] ?? 0);
                    if ($logicalStopRoi > 0) {
                        $entryPriceLS = (float)($trade['entry_price'] ?? 0);
                        $sideLS = strtolower($trade['side'] ?? '');
                        $markPriceLS = (float)($position['markPrice'] ?? $position['lastPrice'] ?? 0);

                        if ($entryPriceLS > 0 && $markPriceLS > 0) {
                            $currentRoiLS = 0;
                            if ($sideLS === 'long') {
                                $currentRoiLS = ($markPriceLS - $entryPriceLS) / $entryPriceLS;
                            } else {
                                $currentRoiLS = ($entryPriceLS - $markPriceLS) / $entryPriceLS;
                            }

                            // Logical stop: close if ROI drops below negative threshold
                            if ($currentRoiLS <= -$logicalStopRoi) {
                                $runtime['close_trigger'] = 'logical_stop';
                                $runtime['close_roi_at_trigger'] = round($currentRoiLS * 100, 4);
                                $runtime['logical_stop_roi_threshold'] = $logicalStopRoi;

                                $result['closed']++;
                                $this->store->moveTradeToClosedDir($tradeId, array_merge($trade, [
                                    'closed_at' => date('c'),
                                    'close_reason' => 'closed_by_logical_stop',
                                    'close_roi' => round($currentRoiLS * 100, 4),
                                    'runtime' => $runtime,
                                ]));
                                continue;
                            }
                        }
                    }
                }

                // Phase-1 safety: Check if SL is set on exchange
                $exchangeSL = (float)($position['stopLoss'] ?? 0);
                
                if ($exchangeSL <= 0) {
                    // SL missing on exchange - try to set exactly once
                    $runtime = is_array($trade['runtime'] ?? null) ? $trade['runtime'] : [];
                    $slRepairAttempted = (bool)($runtime['sl_repair_attempted'] ?? false);
                    
                    if ($slRepairAttempted) {
                        // Already attempted once - just warn, do NOT retry or close
                        $result['warnings'][] = "SL repair already_attempted for {$trade['symbol']} - skipping retry";
                    } else {
                        // First attempt - try to set SL
                        $tradeSL = (float)($trade['protection']['stop_loss_price'] ?? $trade['stop_loss'] ?? 0);
                        
                        if ($tradeSL > 0) {
                            $positionIdx = (int)($trade['exchange']['position_idx'] ?? $this->config['exchange']['position_idx'] ?? 0);
                            // P4: Pass side for proper price normalization
                            $slResult = $this->gateway->setTradingStop($trade['symbol'], $trade['side'], [
                                'position_idx' => $positionIdx,
                                'stop_loss' => $tradeSL,
                            ]);
                            
                            // Mark as attempted (regardless of success)
                            $runtime['sl_repair_attempted'] = true;
                            $runtime['sl_repair_attempted_at'] = date('c');
                            $runtime['sl_repair_result'] = [
                                'ok' => $slResult['success'] ?? false,
                                'error' => $slResult['error'] ?? null,
                            ];
                            $trade['runtime'] = $runtime;
                            
                            // Save updated trade with attempt flag
                            $this->store->updateActiveTrade($tradeId, $trade);
                            
                            if (!$slResult['success']) {
                                // Failed to set SL - WARNING ONLY, do NOT close position
                                $result['warnings'][] = "SL repair failed for {$trade['symbol']}: " . ($slResult['error'] ?? 'unknown') . " - position left open (single_sl_repair)";
                                // Continue to next trade without closing
                            } else {
                                // SL set successfully
                                $result['warnings'][] = "SL repaired for {$trade['symbol']} (was missing on exchange)";
                            }
                        } else {
                            // No SL price in trade data - warn only
                            $result['warnings'][] = "SL missing on exchange for {$trade['symbol']} but no SL price in trade data";
                        }
                    }
                }
                
        
                // ============================================================
                // P8: Phase-1 "Dumb" Trailing (exchange-managed)
                // Activate trailing "now" once ROI (Bybit) reaches activation threshold.
                // V2 FIX: In Brain-controlled mode, trailing decision comes from
                // the normalized Brain contract in risk.trailing — bot-local
                // dumb_trailing_enabled toggle is overridden.
                // ============================================================

                $tradeBrainControlled = !empty($trade['brain_controlled']);
                $dumbTrailingGateOpen = $tradeBrainControlled
                    ? true  // Brain-controlled: trailing gated by risk.trailing.enabled only
                    : (($this->config['execution']['dumb_trailing_enabled'] ?? false) === true);

                if ($dumbTrailingGateOpen) {
                    $risk = $trade['risk'] ?? [];
                    $trailingCfg = $risk['trailing'] ?? [];

                    if (($trailingCfg['enabled'] ?? false) === true) {
                        // Safety: apply only once per trade.
                        // If exchange already has trailing set but it is NOT armed (activation price on the wrong side
                        // of current price), we allow a single "rearm" attempt to fix a non-activating trailing.
                        $runtime = is_array($trade['runtime'] ?? null) ? $trade['runtime'] : [];
                        $alreadyApplied = (bool)($runtime['dumb_trailing_applied'] ?? false);
                        $alreadyRearmed = (bool)($runtime['dumb_trailing_rearmed'] ?? false);

                        $exchangeTrailing = (float)($position['trailingStop'] ?? 0);
                        $exchangeActive = (float)($position['activePrice'] ?? 0);

                        $side = strtolower((string)($trade['side'] ?? 'long'));
if ($side === 'buy') {
    $side = 'long';
} elseif ($side === 'sell') {
    $side = 'short';
}

$markPrice = (float)($position['markPrice'] ?? 0);
$lastPrice = (float)($position['lastPrice'] ?? 0);
$currentPrice = $this->pickTrailingReferencePrice($side, $markPrice, $lastPrice);

                        $canRearm = (bool)($this->config['execution']['dumb_trailing_rearm_if_not_armed'] ?? true);
                        $hasExchangeTrailing = ($exchangeTrailing > 0 || $exchangeActive > 0);

                        $isArmed = true;
                        if ($hasExchangeTrailing && $exchangeActive > 0 && $currentPrice > 0) {
                            if (strtolower($side) === 'long') {
                                // LONG activation: should be <= current to be armed/active
                                $isArmed = ($exchangeActive <= $currentPrice);
                            } else {
                                // SHORT activation: should be >= current to be armed/active
                                $isArmed = ($exchangeActive >= $currentPrice);
                            }
                        }

                        $shouldSkip = false;
                        if ($hasExchangeTrailing) {
                            // Exchange already has trailing: skip unless we can do a single rearm attempt.
                            if (!($canRearm && !$alreadyRearmed && !$isArmed)) {
                                $shouldSkip = true;
                            }
                        }
                        // Local guard: if we already applied and no rearm is needed - skip.
                        if ($alreadyApplied && !($canRearm && !$alreadyRearmed && $hasExchangeTrailing && !$isArmed)) {
                            $shouldSkip = true;
                        }

                        if ($shouldSkip) {
                            $result['trailing_skipped']++;
                        } else {
                            $activationRoiPct = (float)($trailingCfg['activation_roi_pct'] ?? 0);

                            // ROI "as on Bybit": unrealisedPnl / positionIM
                            $positionIM = (float)($position['positionIM'] ?? 0);
                            $unrealisedPnl = (float)($position['unrealisedPnl'] ?? 0);
                            $roiBybit = 0.0;
                            if ($positionIM > 0) {
                                $roiBybit = ($unrealisedPnl / $positionIM) * 100.0;
                            }

                            if ($activationRoiPct > 0 && $roiBybit >= $activationRoiPct) {
                                $entryAvg = (float)($position['avgPrice'] ?? ($trade['entry_price'] ?? 0));

                                // Calculate trailing distance (trailingStop)
                                $trailCalc = $this->riskEngine->calculateTrailingParams($risk, $entryAvg, $side);
                                $trailDist = (float)($trailCalc['trailing_stop'] ?? 0);

                                // Activate "now": activePrice around current mark/last price
                                $epsPct = (float)($this->config['execution']['dumb_trailing_activation_epsilon_pct'] ?? 0.02);
                                $activePrice = $currentPrice;

                                if ($currentPrice > 0 && $trailDist > 0) {
                                    if ($epsPct > 0) {
                                        if (strtolower($side) === 'long') {
                                            $activePrice = $currentPrice * (1 - ($epsPct / 100));
                                        } else {
                                            $activePrice = $currentPrice * (1 + ($epsPct / 100));
                                        }
                                    }

                                    // Keep existing SL on exchange (do not clear)
                                    $slToKeep = $exchangeSL;
                                    if ($slToKeep <= 0) {
                                        $slToKeep = (float)($trade['protection']['stop_loss_price'] ?? ($trade['stop_loss'] ?? 0));
                                    }

                                    $opts = [
                                        'position_idx' => (int)($position['positionIdx'] ?? ($trade['exchange']['position_idx'] ?? ($this->config['exchange']['position_idx'] ?? 0))),
                                        'trailing_stop' => $trailDist,
                                        'active_price' => $activePrice,
                                    ];
                                    if ($slToKeep > 0) {
                                        $opts['stop_loss'] = $slToKeep;
                                    }

                                    $trailRes = $this->gateway->setTradingStop($trade['symbol'], $side, $opts);

                                    if (($trailRes['success'] ?? false) === true) {
                                        $runtime['dumb_trailing_applied'] = true;
                                        $runtime['protection_state'] = 'trailing_active';
                                        if ($hasExchangeTrailing) {
                                            $runtime['dumb_trailing_rearmed'] = true;
                                        }
                                        $runtime['dumb_trailing_applied_at'] = date('c');
                                        $runtime['dumb_trailing_roi_bybit_pct'] = round($roiBybit, 2);
                                        $runtime['dumb_trailing_active_price'] = $activePrice;
                                        $runtime['dumb_trailing_trailing_stop'] = $trailDist;

                                        $trade['runtime'] = $runtime;

                                        $result['trailing_applied']++;
                                        if ($hasExchangeTrailing) {
                                            $result['warnings'][] = "Trailing re-armed now for {$trade['symbol']} (ROI " . round($roiBybit, 2) . "%)";
                                        } else {
                                            $result['warnings'][] = "Trailing activated now for {$trade['symbol']} (ROI " . round($roiBybit, 2) . "%)";
                                        }
                                    } else {
                                        $runtime['dumb_trailing_last_error'] = $trailRes;
                                        $trade['runtime'] = $runtime;

                                        $result['trailing_failed']++;
                                        $result['warnings'][] = "Trailing activation failed for {$trade['symbol']}: " . ($trailRes['error'] ?? 'unknown');
                                    }
                                } else {
                                    $result['trailing_skipped']++;
                                }
                            } else {
                                $result['trailing_skipped']++;
                            }
                        }
                    } else {
                        $result['trailing_skipped']++;
                    }
                }

                // ============================================================
                // P9: Phase-1 "Step Trailing" (SL ratchet - visible profit lock)
                //
                // Problem:
                // - Exchange trailingStop is separate from SL and is not always obvious in UI.
                // - We also want deterministic "lock profit in steps" (e.g. every +2% ROI).
                //
                // Logic (Bybit ROI = unrealisedPnl / positionIM * 100):
                // - Wait until ROI >= activation_roi_pct + step_roi_pct
                // - Each full step increases the locked ROI; we convert locked ROI to price move by dividing by leverage.
                // - We only MOVE SL in the profit direction (ratchet), never loosen.
                //
                // This does NOT replace exchange trailingStop; it complements it by moving SL.
                // ============================================================

                if (($this->config['execution']['step_trailing_enabled'] ?? false) === true) {
                    $risk = $trade['risk'] ?? [];
                    $trailingCfg = $risk['trailing'] ?? [];

                    if (($trailingCfg['enabled'] ?? false) === true) {

                        $activationRoiPct = (float)($trailingCfg['activation_roi_pct'] ?? 0);
                        $overrideActivation = (float)($this->config['execution']['step_trailing_activation_override_roi_pct'] ?? 0);
                        if ($overrideActivation > 0) {
                            $activationRoiPct = $overrideActivation;
                        }

                        $stepRoi = (float)($this->config['execution']['step_trailing_step_roi_pct'] ?? 2.0);
                        $bufferRoi = (float)($this->config['execution']['step_trailing_lock_buffer_roi_pct'] ?? 0.4);
                        $lockFloorRoi = (float)($this->config['execution']['step_trailing_lock_floor_roi_pct'] ?? 0.0);
                        $minDistancePct = (float)($this->config['execution']['step_trailing_min_distance_to_price_pct'] ?? 0.05);

                        // ROI "as on Bybit": unrealisedPnl / positionIM
                        $positionIM = (float)($position['positionIM'] ?? 0);
                        $unrealisedPnl = (float)($position['unrealisedPnl'] ?? 0);
                        $roiBybit = ($positionIM > 0) ? (($unrealisedPnl / $positionIM) * 100.0) : 0.0;

                        if ($activationRoiPct > 0 && $stepRoi > 0 && $roiBybit >= $activationRoiPct) {

                            // We start moving SL only AFTER the first full step beyond activation.
                            $stepIndex = (int)floor(($roiBybit - $activationRoiPct) / $stepRoi);
                            if ($stepIndex > 0) {
                                $lockedRoi = ($stepIndex * $stepRoi) - $bufferRoi;

                                if ($lockedRoi < $lockFloorRoi) {
                                    $lockedRoi = $lockFloorRoi;
                                }

                                $runtime = is_array($trade['runtime'] ?? null) ? $trade['runtime'] : [];
                                $lastLockedRoi = (float)($runtime['step_trailing_locked_roi_pct'] ?? 0);

                                // Only update when locked ROI increases
                                if ($lockedRoi > 0 && $lockedRoi > ($lastLockedRoi + 0.0001)) {

                                    $entryAvg = (float)($position['avgPrice'] ?? ($trade['entry_price'] ?? 0));
                                    $leverage = (float)($risk['leverage'] ?? ($trade['leverage'] ?? 1));
                                    if ($leverage <= 0) {
                                        $leverage = 1.0;
                                    }

                                    // ROI includes leverage; convert to underlying price move
                                    $priceMovePct = $lockedRoi / $leverage;

                                    $side = strtolower((string)($trade['side'] ?? 'long'));
                                    if ($side === 'buy') {
                                        $side = 'long';
                                    } elseif ($side === 'sell') {
                                        $side = 'short';
                                    }

                                    $markPrice = (float)($position['markPrice'] ?? 0);
                                    $lastPrice = (float)($position['lastPrice'] ?? 0);
                                    $currentPrice = $this->pickTrailingReferencePrice($side, $markPrice, $lastPrice);

                                    if ($entryAvg > 0 && $currentPrice > 0) {

                                        $desiredSL = 0.0;

                                        if ($side === 'long') {

                                            $desiredSL = $entryAvg * (1 + ($priceMovePct / 100));
                                            // Safety: keep SL below current price by a small margin to avoid instant trigger due to rounding
                                            $maxAllowed = $currentPrice * (1 - ($minDistancePct / 100));
                                            if ($maxAllowed > 0 && $desiredSL > $maxAllowed) {
                                                $desiredSL = $maxAllowed;
                                            }

                                            // Ratchet: only increase SL
                                            if ($desiredSL > 0 && $desiredSL > $exchangeSL) {

                                                $opts = [
                                                    'position_idx' => (int)($position['positionIdx'] ?? ($trade['exchange']['position_idx'] ?? ($this->config['exchange']['position_idx'] ?? 0))),
                                                    'tpsl_mode' => $this->config['exchange']['tpsl_mode'] ?? 'Full',
                                                    'sl_trigger_by' => $this->config['exchange']['sl_trigger_by'] ?? 'IndexPrice',
                                                    'stop_loss' => $desiredSL,
                                                ];

                                                $slRes = $this->gateway->setTradingStop($trade['symbol'], $side, $opts);

                                                if (($slRes['success'] ?? false) === true) {
                                                    $exchangeSL = $desiredSL;

                                                    $trade['protection']['stop_loss_price'] = $desiredSL;

                                                    $runtime['step_trailing_locked_roi_pct'] = $lockedRoi;
                                                    $runtime['step_trailing_last_roi_bybit_pct'] = round($roiBybit, 2);
                                                    $runtime['step_trailing_last_sl'] = $desiredSL;
                                                    $runtime['step_trailing_updates'] = (int)($runtime['step_trailing_updates'] ?? 0) + 1;
                                                    $runtime['step_trailing_last_update_at'] = date('c');
                                                    $trade['runtime'] = $runtime;

                                                    $result['step_trailing_applied']++;
                                                    $result['warnings'][] = "Step trailing SL updated for {$trade['symbol']} (ROI " . round($roiBybit, 2) . "%, lock " . round($lockedRoi, 2) . "%)";
                                                } else {
                                                    $runtime['step_trailing_last_error'] = $slRes;
                                                    $trade['runtime'] = $runtime;

                                                    $result['step_trailing_failed']++;
                                                    $result['warnings'][] = "Step trailing SL update failed for {$trade['symbol']}: " . ($slRes['error'] ?? 'unknown');
                                                }
                                            } else {
                                                $result['step_trailing_skipped']++;
                                            }

                                        } else {

                                            $desiredSL = $entryAvg * (1 - ($priceMovePct / 100));
                                            // Safety: keep SL above current price by a small margin to avoid instant trigger due to rounding
                                            $minAllowed = $currentPrice * (1 + ($minDistancePct / 100));
                                            if ($minAllowed > 0 && $desiredSL < $minAllowed) {
                                                $desiredSL = $minAllowed;
                                            }

                                            // Ratchet: only DECREASE SL value for short (move closer to current)
                                            if ($desiredSL > 0 && ($exchangeSL <= 0 || $desiredSL < $exchangeSL)) {

                                                $opts = [
                                                    'position_idx' => (int)($position['positionIdx'] ?? ($trade['exchange']['position_idx'] ?? ($this->config['exchange']['position_idx'] ?? 0))),
                                                    'tpsl_mode' => $this->config['exchange']['tpsl_mode'] ?? 'Full',
                                                    'sl_trigger_by' => $this->config['exchange']['sl_trigger_by'] ?? 'IndexPrice',
                                                    'stop_loss' => $desiredSL,
                                                ];

                                                $slRes = $this->gateway->setTradingStop($trade['symbol'], $side, $opts);

                                                if (($slRes['success'] ?? false) === true) {
                                                    $exchangeSL = $desiredSL;

                                                    $trade['protection']['stop_loss_price'] = $desiredSL;

                                                    $runtime['step_trailing_locked_roi_pct'] = $lockedRoi;
                                                    $runtime['step_trailing_last_roi_bybit_pct'] = round($roiBybit, 2);
                                                    $runtime['step_trailing_last_sl'] = $desiredSL;
                                                    $runtime['step_trailing_updates'] = (int)($runtime['step_trailing_updates'] ?? 0) + 1;
                                                    $runtime['step_trailing_last_update_at'] = date('c');
                                                    $trade['runtime'] = $runtime;

                                                    $result['step_trailing_applied']++;
                                                    $result['warnings'][] = "Step trailing SL updated for {$trade['symbol']} (ROI " . round($roiBybit, 2) . "%, lock " . round($lockedRoi, 2) . "%)";
                                                } else {
                                                    $runtime['step_trailing_last_error'] = $slRes;
                                                    $trade['runtime'] = $runtime;

                                                    $result['step_trailing_failed']++;
                                                    $result['warnings'][] = "Step trailing SL update failed for {$trade['symbol']}: " . ($slRes['error'] ?? 'unknown');
                                                }
                                            } else {
                                                $result['step_trailing_skipped']++;
                                            }
                                        }
                                    } else {
                                        $result['step_trailing_skipped']++;
                                    }
                                } else {
                                    $result['step_trailing_skipped']++;
                                }
                            } else {
                                $result['step_trailing_skipped']++;
                            }
                        } else {
                            $result['step_trailing_skipped']++;
                        }
                    } else {
                        $result['step_trailing_skipped']++;
                    }
                }

                // ============================================================
                // Break-Even Execution
                // When ROI reaches break_even_activation_roi, move SL to entry price.
                // This is a one-time operation per trade.
                // ============================================================
                {
                    $risk = $trade['risk'] ?? [];
                    $trailingCfg = $risk['trailing'] ?? [];
                    $runtime = is_array($trade['runtime'] ?? null) ? $trade['runtime'] : [];
                    $beEnabled = (bool)($trailingCfg['break_even_enabled'] ?? false);
                    $beApplied = (bool)($runtime['break_even_applied'] ?? false);

                    if ($beEnabled && !$beApplied) {
                        $beActivationRoi = (float)($trailingCfg['break_even_activation_roi'] ?? 0);
                        if ($beActivationRoi > 0) {
                            $positionIM = (float)($position['positionIM'] ?? 0);
                            $unrealisedPnl = (float)($position['unrealisedPnl'] ?? 0);
                            $roiBybit = ($positionIM > 0) ? (($unrealisedPnl / $positionIM) * 100.0) : 0.0;

                            if ($roiBybit >= $beActivationRoi) {
                                $entryAvg = (float)($position['avgPrice'] ?? ($trade['entry_price'] ?? 0));
                                $side = strtolower((string)($trade['side'] ?? 'long'));
                                if ($side === 'buy') $side = 'long';
                                if ($side === 'sell') $side = 'short';

                                if ($entryAvg > 0) {
                                    // Move SL to entry price (break-even)
                                    $beSL = $entryAvg;
                                    $currentExchangeSL = (float)($position['stopLoss'] ?? 0);

                                    // Only move SL if it would be an improvement (ratchet logic)
                                    $shouldApply = false;
                                    if ($side === 'long') {
                                        $shouldApply = ($currentExchangeSL <= 0 || $beSL > $currentExchangeSL);
                                    } else {
                                        $shouldApply = ($currentExchangeSL <= 0 || $beSL < $currentExchangeSL);
                                    }

                                    if ($shouldApply) {
                                        $positionIdx = (int)($position['positionIdx'] ?? ($trade['exchange']['position_idx'] ?? ($this->config['exchange']['position_idx'] ?? 0)));
                                        $beOpts = [
                                            'position_idx' => $positionIdx,
                                            'stop_loss' => $beSL,
                                        ];
                                        $beResult = $this->gateway->setTradingStop($trade['symbol'], $side, $beOpts);

                                        if (($beResult['success'] ?? false) === true) {
                                            $runtime['break_even_armed'] = true;
                                            $runtime['break_even_applied'] = true;
                                            $runtime['break_even_applied_at'] = date('c');
                                            $runtime['break_even_sl_price'] = $beSL;
                                            $runtime['break_even_roi_at_trigger'] = round($roiBybit, 2);
                                            $runtime['protection_state'] = 'break_even_applied';
                                            $trade['protection']['stop_loss_price'] = $beSL;
                                            $trade['runtime'] = $runtime;
                                            $result['warnings'][] = "Break-even applied for {$trade['symbol']} (ROI " . round($roiBybit, 2) . "%, SL→{$beSL})";
                                        } else {
                                            $runtime['break_even_last_error'] = $beResult;
                                            $trade['runtime'] = $runtime;
                                            $result['warnings'][] = "Break-even failed for {$trade['symbol']}: " . ($beResult['error'] ?? 'unknown');
                                        }
                                    } else {
                                        // SL already better than entry — mark as applied
                                        $runtime['break_even_armed'] = true;
                                        $runtime['break_even_applied'] = true;
                                        $runtime['break_even_applied_at'] = date('c');
                                        $runtime['break_even_note'] = 'sl_already_beyond_entry';
                                        $trade['runtime'] = $runtime;
                                    }
                                }
                            } else {
                                // ROI not yet at threshold — arm if approaching
                                if ($roiBybit > 0 && $roiBybit >= ($beActivationRoi * 0.5)) {
                                    $runtime['break_even_armed'] = true;
                                    $trade['runtime'] = $runtime;
                                }
                            }
                        }
                    }
                }

                // ============================================================
                // Hybrid Exit: Partial Take Profit + Runner
                // When exit_mode=hybrid_tp and ROI reaches fixed_take_profit_roi,
                // close hybrid_tp_share portion and let the rest trail.
                // One-time operation per trade.
                // ============================================================
                {
                    $risk = $trade['risk'] ?? [];
                    $trailingCfg = $risk['trailing'] ?? [];
                    $runtime = is_array($trade['runtime'] ?? null) ? $trade['runtime'] : [];
                    $exitMode = (string)($trailingCfg['exit_mode'] ?? '');
                    $hybridApplied = (bool)($runtime['hybrid_partial_applied'] ?? false);

                    if ($exitMode === 'hybrid_tp' && !$hybridApplied) {
                        $fixedTpRoi = (float)($trailingCfg['fixed_take_profit_roi'] ?? 0);
                        $hybridShare = (float)($trailingCfg['hybrid_tp_share'] ?? 0.4);

                        if ($fixedTpRoi > 0 && $hybridShare > 0 && $hybridShare < 1.0) {
                            $positionIM = (float)($position['positionIM'] ?? 0);
                            $unrealisedPnl = (float)($position['unrealisedPnl'] ?? 0);
                            $roiBybit = ($positionIM > 0) ? (($unrealisedPnl / $positionIM) * 100.0) : 0.0;

                            // Convert fixedTpRoi from ratio to percent for comparison
                            $fixedTpRoiPct = ($fixedTpRoi < 1.0) ? $fixedTpRoi * 100 : $fixedTpRoi;

                            if ($roiBybit >= $fixedTpRoiPct) {
                                $side = strtolower((string)($trade['side'] ?? 'long'));
                                if ($side === 'buy') $side = 'long';
                                if ($side === 'sell') $side = 'short';

                                $totalQty = (float)($position['size'] ?? $trade['position_size'] ?? $trade['qty'] ?? 0);
                                $closeQty = round($totalQty * $hybridShare, 8);

                                if ($closeQty > 0 && $this->gateway && $this->gateway->isInitialized()) {
                                    $closeResult = $this->gateway->closePosition($trade['symbol'], $side, $closeQty);

                                    if (($closeResult['success'] ?? false) === true) {
                                        $runtime['hybrid_partial_applied'] = true;
                                        $runtime['hybrid_partial_applied_at'] = date('c');
                                        $runtime['hybrid_partial_qty_closed'] = $closeQty;
                                        $runtime['hybrid_partial_roi_at_trigger'] = round($roiBybit, 2);
                                        $runtime['hybrid_remaining_qty'] = round($totalQty - $closeQty, 8);
                                        $trade['runtime'] = $runtime;
                                        $result['warnings'][] = "Hybrid partial TP applied for {$trade['symbol']} (closed {$closeQty}/{$totalQty} at ROI " . round($roiBybit, 2) . "%)";
                                    } else {
                                        $runtime['hybrid_partial_last_error'] = $closeResult;
                                        $trade['runtime'] = $runtime;
                                        $result['warnings'][] = "Hybrid partial TP failed for {$trade['symbol']}: " . ($closeResult['error'] ?? 'unknown');
                                    }
                                }
                            }
                        }
                    }
                }

                        // Update last_update timestamp
                $trade['last_update'] = date('c');
                $trade['last_price'] = (float)($position['markPrice'] ?? $position['lastPrice'] ?? $trade['last_price']);
                $this->store->updateActiveTrade($tradeId, $trade);
                $result['updated']++;
                
            } catch (\Throwable $e) {
                $result['errors'][] = "Error updating {$tradeId}: " . $e->getMessage();
            }
        }
        
        return $result;
    }
    
    /**
     * Close position on exchange (LIVE mode only)
     */
    private function closePositionOnExchange(array $trade): array
    {
        if (!$this->gateway || !$this->gateway->isInitialized()) {
            return ['success' => false, 'error' => 'gateway_not_initialized'];
        }
        
        $qty = $trade['position_size'] ?? $trade['qty'] ?? 0;
        if ($qty <= 0) {
            return ['success' => false, 'error' => 'invalid_position_size'];
        }
        
        return $this->gateway->closePosition(
            $trade['symbol'],
            $trade['side'],
            $qty
        );
    }
    
    /**
     * B2: Update trailing on exchange (LIVE mode only)
     * Phase-1: This is a "dumb" trailing - set once, don't track
     */
    private function updateTrailingOnExchange(array $trade, array $trailingUpdate): array
    {
        if (!$this->gateway || !$this->gateway->isInitialized()) {
            return ['success' => false, 'error' => 'gateway_not_initialized'];
        }
        
        $options = [
            'position_idx' => (int)($trade['exchange']['position_idx'] ?? $this->config['exchange']['position_idx'] ?? 0),
        ];
        
        if (isset($trailingUpdate['stop_loss'])) {
            $options['stop_loss'] = $trailingUpdate['stop_loss'];
        }
        if (isset($trailingUpdate['trailing_stop'])) {
            $options['trailing_stop'] = $trailingUpdate['trailing_stop'];
        }
        if (isset($trailingUpdate['active_price'])) {
            $options['active_price'] = $trailingUpdate['active_price'];
        }
        
        if (count($options) <= 1) {
            return ['success' => true, 'message' => 'no_update_needed'];
        }
        
        // P4: Pass side for proper price normalization
        return $this->gateway->setTradingStop($trade['symbol'], $trade['side'], $options);
    }
    
    /**
     * Set leverage on exchange
     */
    private function setLeverageOnExchange(string $symbol, int $leverage): array
    {
        if (!$this->gateway || !$this->gateway->isInitialized()) {
            return ['success' => false, 'error' => 'gateway_not_initialized'];
        }
        
        try {
            return $this->gateway->setLeverage($symbol, $leverage);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Check for late entry
     */
    

    /**
     * Pick conservative reference price for trailing activation/arming and SL distance checks.
     *
     * Bybit UI often displays lastPrice, while some API fields use markPrice.
     * To avoid a trailing "not armed" state due to reference mismatch:
     * - LONG: min(markPrice, lastPrice)
     * - SHORT: max(markPrice, lastPrice)
     *
     * @param string $side long|short
     * @param float $markPrice markPrice from position
     * @param float $lastPrice lastPrice from position
     * @return float reference price (0 if unavailable)
     */
    private function pickTrailingReferencePrice(string $side, float $markPrice, float $lastPrice): float
    {
        $side = strtolower($side);
        if ($side === 'buy') {
            $side = 'long';
        } elseif ($side === 'sell') {
            $side = 'short';
        }

        $mark = ($markPrice > 0) ? $markPrice : 0.0;
        $last = ($lastPrice > 0) ? $lastPrice : 0.0;

        if ($mark <= 0 && $last <= 0) {
            return 0.0;
        }
        if ($mark <= 0) {
            return $last;
        }
        if ($last <= 0) {
            return $mark;
        }

        if ($side === 'long') {
            return min($mark, $last);
        }

        return max($mark, $last);
    }
private function checkLateEntry(array $intent): array
    {
        $result = ['ok' => true];
        
        $currentPrice = $this->getCurrentPrice($intent['symbol']);
        if ($currentPrice === null) {
            return $result; // Can't check, assume ok
        }
        
        $entryPrice = $intent['entry_price'];
        $threshold = $intent['late_threshold_pct'] ?? 0.5;
        $side = $intent['side'];
        
        $priceDiff = abs($currentPrice - $entryPrice) / $entryPrice * 100;
        
        if ($side === 'long' && $currentPrice > $entryPrice) {
            if ($priceDiff > $threshold) {
                $result['ok'] = false;
                $result['reason'] = "Price moved up {$priceDiff}% > threshold {$threshold}%";
            }
        } elseif ($side === 'short' && $currentPrice < $entryPrice) {
            if ($priceDiff > $threshold) {
                $result['ok'] = false;
                $result['reason'] = "Price moved down {$priceDiff}% > threshold {$threshold}%";
            }
        }
        
        return $result;
    }
    
    /**
     * P6.9: Check wait_retrace entry action
     * 
     * Returns action: 'defer', 'reject', or 'proceed'
     * - defer: retrace condition not met, try again next run
     * - reject: timeout reached, reject the intent
     * - proceed: retrace condition met, continue to balance/order
     * 
     * @param array $intent Intent data
     * @param string $mode Execution mode
     * @return array ['action' => string, 'reason' => string|null, 'context' => array|null]
     */

    /**
     * Compute effective entry deadline for an intent.
     *
     * Deadline = min(expires_at, created_ts + timeout_minutes).
     *
     * timeout_minutes sources:
     * - intent.entry_timeout_minutes (from Brain) if provided (>0)
     * - config.execution.enter_now_timeout_minutes fallback for enter_now
     * - config.execution.default_entry_timeout_minutes fallback for other/legacy
     *
     * @param array<string,mixed> $intent
     * @return array<string,mixed>
     */
private function computeEntryDeadline(array $intent): array
{
    $now = time();

    $createdTs = (int)($intent['created_ts'] ?? 0);
    if ($createdTs <= 0) {
        $createdTs = $now;
    }

    $expiresAt = (int)($intent['expires_at'] ?? 0);

    $entryAction = (string)($intent['entry_action'] ?? '');
    $timeoutFromIntent = $intent['entry_timeout_minutes'] ?? null;

    $timeoutMinutes = 0;
    $timeoutSource = '';

    // 1) Prefer explicit timeout from Brain intent when valid (>0)
    if (is_numeric($timeoutFromIntent) && (int)$timeoutFromIntent > 0) {
        $timeoutMinutes = (int)$timeoutFromIntent;
        $timeoutSource = 'intent.entry_timeout_minutes';
    } else {
        // 2) Action-specific fallbacks (config-first)
        if ($entryAction === 'enter_now') {
            $timeoutMinutes = (int)($this->config['execution']['enter_now_timeout_minutes'] ?? 2);
            $timeoutSource = 'config.execution.enter_now_timeout_minutes';
        } elseif ($entryAction === 'wait_retrace') {
            // For wait_retrace:
            // - If Brain didn't specify an explicit timeout, we should NOT cap by created_ts + default timeout.
            // - Default: allow until expires_at (if present).
            // - Optional cap: execution.wait_retrace_timeout_minutes (>0).
            $timeoutMinutes = (int)($this->config['execution']['wait_retrace_timeout_minutes'] ?? 0);
            $timeoutSource = ($timeoutMinutes > 0) ? 'config.execution.wait_retrace_timeout_minutes' : 'expires_at';
        } else {
            $timeoutMinutes = (int)($this->config['execution']['default_entry_timeout_minutes'] ?? 10);
            $timeoutSource = 'config.execution.default_entry_timeout_minutes';
        }
    }

    // Compute deadline:
    // - If timeoutMinutes > 0: created_deadline = created_ts + timeout, then clamp by expires_at if exists
    // - If timeoutMinutes <= 0 (wait_retrace default): deadline = expires_at if exists, else fallback to created_ts + default_entry_timeout_minutes
    $createdDeadline = 0;
    $deadline = 0;

    if ($timeoutMinutes > 0) {
        $createdDeadline = $createdTs + ($timeoutMinutes * 60);
        $deadline = $createdDeadline;

        if ($expiresAt > 0) {
            $deadline = min($expiresAt, $createdDeadline);
        }
    } else {
        // No timeout cap (default for wait_retrace) → use expires_at if provided
        if ($expiresAt > 0) {
            $deadline = $expiresAt;
        } else {
            // Fallback (should be rare): no expires_at and no cap → use default timeout
            $fallbackMinutes = (int)($this->config['execution']['default_entry_timeout_minutes'] ?? 10);
            if ($fallbackMinutes <= 0) {
                $fallbackMinutes = 10;
            }
            $createdDeadline = $createdTs + ($fallbackMinutes * 60);
            $deadline = $createdDeadline;
            $timeoutMinutes = $fallbackMinutes;
            $timeoutSource = 'fallback_default_entry_timeout_minutes';
        }
    }

    $exceeded = ($deadline > 0) ? ($now > $deadline) : false;

    return [
        'deadline' => $deadline,
        'exceeded' => $exceeded,
        'reason' => $exceeded ? 'entry_timeout_exceeded' : '',
        'context' => [
            'now' => $now,
            'created_ts' => $createdTs,
            'expires_at' => $expiresAt > 0 ? $expiresAt : null,
            'timeout_minutes' => $timeoutMinutes > 0 ? $timeoutMinutes : null,
            'timeout_source' => $timeoutSource,
            'timeout_from_intent_raw' => $timeoutFromIntent,
            'timeout_from_intent_valid' => (is_numeric($timeoutFromIntent) && (int)$timeoutFromIntent > 0),
            'created_deadline' => $createdDeadline > 0 ? $createdDeadline : null,
            'effective_deadline' => $deadline > 0 ? $deadline : null,
            'age_sec' => max(0, $now - $createdTs),
            'time_remaining_sec' => ($deadline > 0) ? max(0, $deadline - $now) : 0,
            'entry_action' => $entryAction,
        ],
    ];
}



    private function checkWaitRetrace(array $intent, string $mode): array
    {
        $now = time();
        $symbol = $intent['symbol'];
        $side = $intent['side'];
        $entryPrice = (float)($intent['entry_price'] ?? 0);

        // Effective deadline (min(expires_at, created_ts + timeout_minutes))
        $deadlineInfo = $this->computeEntryDeadline($intent);
        $deadline = (int)($deadlineInfo['deadline'] ?? 0);

        // Check if deadline exceeded
        if (($deadlineInfo['exceeded'] ?? false) === true) {
            return [
                'action' => 'reject',
                'reason' => 'entry_timeout_exceeded',
                'context' => $deadlineInfo['context'] ?? [],
            ];
        }

        // Get current price
        $currentPrice = $this->getCurrentPrice($symbol);
        if ($currentPrice === null) {
            // Can't get price - defer (don't reject, don't proceed)
            return [
                'action' => 'defer',
                'reason' => 'price_unavailable',
                'context' => array_merge($deadlineInfo['context'] ?? [], [
                    'symbol' => $symbol,
                ]),
            ];
        }
        
        // Check retrace condition
        $slackPct = (float)($this->config['execution']['retrace_slack_pct'] ?? 0.05);
        
        // LONG: currentPrice <= entry_price * (1 + slack_pct/100)
        // SHORT: currentPrice >= entry_price * (1 - slack_pct/100)
        $retraceMet = false;
        if ($side === 'long') {
            $threshold = $entryPrice * (1 + $slackPct / 100);
            $retraceMet = $currentPrice <= $threshold;
        } elseif ($side === 'short') {
            $threshold = $entryPrice * (1 - $slackPct / 100);
            $retraceMet = $currentPrice >= $threshold;
        }
        
        if (!$retraceMet) {
            // Retrace not met - defer
            return [
                'action' => 'defer',
                'reason' => 'wait_retrace_not_reached',
                'context' => array_merge($deadlineInfo['context'] ?? [], [
                    'symbol' => $symbol,
                    'side' => $side,
                    'entry_price' => $entryPrice,
                    'current_price' => $currentPrice,
                    'slack_pct' => $slackPct,
                    'threshold' => $threshold ?? null,
                    'slack_ratio' => $slackPct / 100.0,
                    'diff_pct' => $entryPrice > 0 ? (($currentPrice - $entryPrice) / $entryPrice) * 100.0 : null,
                    'threshold_rule' => ($side === 'long') ? 'current_price <= entry_price * (1 + slack_pct/100)' : 'current_price >= entry_price * (1 - slack_pct/100)',
                    'deadline' => $deadline,
                    'time_remaining_sec' => $deadline - $now,
                ]),
            ];
        }
        
        // Retrace condition met - proceed to balance/order
        return [
            'action' => 'proceed',
            'reason' => null,
            'context' => array_merge($deadlineInfo['context'] ?? [], [
                'symbol' => $symbol,
                'side' => $side,
                'entry_price' => $entryPrice,
                'current_price' => $currentPrice,
                'slack_pct' => $slackPct,
                    'slack_ratio' => $slackPct / 100.0,
                    'diff_pct' => $entryPrice > 0 ? (($currentPrice - $entryPrice) / $entryPrice) * 100.0 : null,
                    'threshold_rule' => ($side === 'long') ? 'current_price <= entry_price * (1 + slack_pct/100)' : 'current_price >= entry_price * (1 - slack_pct/100)',
            ]),
        ];
    }
    
    /**
     * Build order from intent
     */
    private function buildOrder(array $intent, float $positionSize, array $risk, string $orderLinkId = null): array
    {
        if (!isset($risk['order_type']) || $risk['order_type'] === '') {
            throw new \RuntimeException('order_type is required in risk block');
        }
        
        return [
            'symbol' => $intent['symbol'],
            'side' => $intent['side'] === 'long' ? 'Buy' : 'Sell',
            'order_type' => $risk['order_type'],
            'qty' => $positionSize,
            'price' => $intent['entry_price'],
            'time_in_force' => 'GTC',
            'reduce_only' => false,
            'close_on_trigger' => false,
            'order_link_id' => $orderLinkId,
            'signal_id' => $intent['signal_id'] ?? $intent['id'],
            'created_at' => date('c'),
        ];
    }
    
    /**
     * Build trade from intent and order result (legacy/dry mode)
     */
    private function buildTrade(array $intent, array $order, array $orderResult): array
    {
        return [
            'trade_id' => $intent['signal_id'] ?? $intent['id'],
            'signal_id' => $intent['signal_id'] ?? $intent['id'],
            'symbol' => $intent['symbol'],
            'side' => $intent['side'],
            'entry_price' => $intent['entry_price'],
            'position_size' => $order['qty'],
            'leverage' => $intent['risk']['leverage'] ?? 1,
            'risk' => $intent['risk'],
            'order_id' => $orderResult['order_id'] ?? null,
            'status' => 'active',
            'opened_at' => date('c'),
            'opened_ts' => time(),
            'high_watermark' => $intent['entry_price'],
            'low_watermark' => $intent['entry_price'],
            'last_price' => $intent['entry_price'],
            'last_update' => date('c'),
        ];
    }
    
    /**
     * Submit order to exchange
     */
    private function submitOrder(array $order): array
    {
        if (!$this->isLiveMode()) {
            return $this->simulateOrder($order);
        }
        
        if ($this->gateway === null || !$this->gateway->isInitialized()) {
            return [
                'ok' => false,
                'error' => 'gateway_not_initialized',
                'mode' => 'live',
            ];
        }
        
        try {
            $gatewayResult = $this->gateway->submitOrder($order);
            
            if (!($gatewayResult['success'] ?? false)) {
                return [
                    'ok' => false,
                    'error' => $gatewayResult['error'] ?? 'order_submission_failed',
                    'mode' => 'live',
                ];
            }
            
            $fillPrice = $gatewayResult['fill_price'] ?? $gatewayResult['avg_price'] ?? $order['price'];
            
            return [
                'ok' => true,
                'filled' => $gatewayResult['filled'] ?? true,
                'order_id' => $gatewayResult['order_id'] ?? null,
                'fill_price' => $fillPrice,
                'fill_qty' => $gatewayResult['fill_qty'] ?? $order['qty'],
                'status' => $gatewayResult['status'] ?? 'filled',
                'mode' => 'live',
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => 'Exception: ' . $e->getMessage(),
                'mode' => 'live',
            ];
        }
    }
    
    /**
     * Simulate order (dry mode)
     */
    private function simulateOrder(array $order): array
    {
        return [
            'ok' => true,
            'filled' => true,
            'order_id' => 'dry_' . uniqid(),
            'fill_price' => $order['price'],
            'fill_qty' => $order['qty'],
            'status' => 'filled',
            'mode' => 'dry',
        ];
    }
    
    /**
     * Get current price for symbol
     */
    private function getCurrentPrice(string $symbol): ?float
    {
        if (!$this->isLiveMode()) {
            return null;
        }
        
        try {
            $gateway = $this->getGateway();
            if ($gateway === null) {
                return null;
            }
            
            return $gateway->getLastPrice($symbol);
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    /**
     * Calculate PnL for trade
     */
    private function calculatePnL(array $trade, float $closePrice): float
    {
        $side = $trade['side'];
        $entryPrice = $trade['entry_price'];
        $positionSize = $trade['position_size'] ?? 1;
        
        $priceDiff = $closePrice - $entryPrice;
        if ($side === 'short') {
            $priceDiff = -$priceDiff;
        }
        
        return $priceDiff * $positionSize;
    }
    
    /**
     * Perform safety checks
     */
    protected function performSafetyChecks(): array
    {
        $result = [
            'ok' => true,
            'checks_passed' => 0,
            'checks_failed' => 0,
        ];
        
        $maxErrors = $this->config['module']['safety_stop_on_errors'] ?? 5;
        if (count($this->errors) >= $maxErrors) {
            $result['checks_failed']++;
            $this->warnings[] = "Error count ({$maxErrors}) exceeded - consider stopping bot";
        } else {
            $result['checks_passed']++;
        }
        
        $maxPositions = $this->config['module']['max_concurrent_positions'] ?? 10;
        $activeCount = count($this->store->loadActiveTrades());
        if ($activeCount >= $maxPositions) {
            $result['checks_failed']++;
            $this->warnings[] = "Max positions ({$maxPositions}) reached";
        } else {
            $result['checks_passed']++;
        }
        
        $result['ok'] = $result['checks_failed'] === 0;
        
        return $result;
    }
}

/* RULES
- Executor handles order submission and position management
- Phase-1: SL-first policy (SL required; if missing on exchange -> repair once then unprotected; TP optional)
- If SL is missing or fails to set -> ONE repair attempt, then WARNING + unprotected (NO auto-close)
- Risk comes ONLY from intent (which comes from Brain)
- Uses gateway for ALL exchange operations
- Writes rejected executions with full context for debugging
*/
