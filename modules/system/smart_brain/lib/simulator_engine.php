<?php
declare(strict_types=1);

/**
 * Simulator Engine — Smart Brain Phase 7 + Exit Policy Stabilization
 *
 * Real paper-trading engine with state transitions:
 *   WAITING → ACTIVE → CLOSED
 *
 * Tracks ROI, MAE, MFE per active trade.
 * Produces closed trades with duration and reason for Coin Passports.
 * Prevents duplicate waiting/active trades per symbol.
 *
 * Exit policy support:
 *   - Stop floor (user-defined minimum protection)
 *   - Trailing activation (lock profit after ROI threshold)
 *   - Break-even (move stop to entry after ROI threshold)
 */
final class SimulatorEngine
{
    /** @var array<string,mixed> */
    private array $cfg;
    private StateManager $state;

    /**
     * @param array<string,mixed> $cfg
     */
    public function __construct(array $cfg, StateManager $state)
    {
        $this->cfg = $cfg;
        $this->state = $state;
    }

    /**
     * Run one full simulator tick.
     *
     * @param array<int,array<string,mixed>> $signals   Signals from risk engine
     * @param array<string,float>            $prices    Symbol→current_price map
     */
    public function tick(array $signals, array $prices): void
    {
        if (($this->cfg['enabled'] ?? false) !== true) {
            return;
        }

        $waiting = $this->state->readJson('storage/simulator/waiting.json', []);
        $active  = $this->state->readJson('storage/simulator/active.json', []);
        $closed  = $this->state->readJson('storage/simulator/closed.json', []);

        // Build sets of symbols already in waiting/active to prevent duplicates
        $waitingSymbols = [];
        foreach ($waiting as $w) {
            $waitingSymbols[(string)($w['symbol'] ?? '')] = true;
        }
        $activeSymbols = [];
        foreach ($active as $a) {
            $activeSymbols[(string)($a['symbol'] ?? '')] = true;
        }

        // 1. Ingest new signals → WAITING (no duplicates)
        foreach ($signals as $signal) {
            $symbol = (string)($signal['symbol'] ?? '');
            if ($symbol === '') {
                continue;
            }
            // Reject signals without explicit side — no silent default to long.
            // RiskEngine already logs side-rejection details, so no additional logging needed here.
            $signalSide = (string)($signal['side'] ?? '');
            if ($signalSide !== 'long' && $signalSide !== 'short') {
                continue;
            }
            if (isset($waitingSymbols[$symbol]) || isset($activeSymbols[$symbol])) {
                continue;
            }
            $waiting[] = [
                'symbol'         => $symbol,
                'entry_zone_low' => $signal['entry_zone_low'] ?? null,
                'entry_zone_high'=> $signal['entry_zone_high'] ?? null,
                'budget'         => $signal['budget'] ?? null,
                'leverage'       => $signal['leverage'] ?? null,
                'stoploss'       => $signal['stop_loss'] ?? $signal['stoploss'] ?? null,
                'takeprofit'     => $signal['take_profit'] ?? $signal['takeprofit'] ?? null,
                'status'         => 'waiting',
                'side'           => $signalSide,
                'trend_bias'     => $signal['trend_bias'] ?? '',
                // Exit policy fields
                'exit_mode'                  => $signal['exit_mode'] ?? 'fixed_tp',
                'stop_floor_type'            => $signal['stop_floor_type'] ?? 'roi_percent',
                'stop_floor_value'           => $signal['stop_floor_value'] ?? 0.03,
                'trailing_enabled'           => $signal['trailing_enabled'] ?? false,
                'trailing_activation_roi'    => $signal['trailing_activation_roi'] ?? 0.02,
                'trailing_min_lock_roi'      => $signal['trailing_min_lock_roi'] ?? 0.005,
                'trailing_min_step'          => $signal['trailing_min_step'] ?? 0.005,
                'fixed_take_profit_roi'      => $signal['fixed_take_profit_roi'] ?? 0.05,
                'break_even_enabled'         => $signal['break_even_enabled'] ?? false,
                'break_even_activation_roi'  => $signal['break_even_activation_roi'] ?? 0.01,
                'corridor_width'             => $signal['corridor_width'] ?? null,
                // Stop Loss Engine V2
                'stop_mode'                    => $signal['stop_mode'] ?? 'brain_managed',
                'simple_stop_liq_factor'       => $signal['simple_stop_liq_factor'] ?? 0.15,
                'brain_stop_corridor_factor'   => $signal['brain_stop_corridor_factor'] ?? 0.25,
                'brain_stop_volatility_factor' => $signal['brain_stop_volatility_factor'] ?? 0.50,
                'brain_stop_liq_safety_factor' => $signal['brain_stop_liq_safety_factor'] ?? 0.30,
                // Early Failure Guard
                'early_failure_enabled'        => $signal['early_failure_enabled'] ?? false,
                'early_failure_window_minutes' => $signal['early_failure_window_minutes'] ?? 5,
                'early_failure_max_adverse_roi' => $signal['early_failure_max_adverse_roi'] ?? -0.008,
                // Pattern algorithm tracking
                'pattern_algorithm'          => $signal['pattern_algorithm'] ?? 'none',
                'pattern_confidence'         => $signal['pattern_confidence'] ?? 0.0,
                'trend_match_score'          => $signal['trend_match_score'] ?? 0.0,
                'corridor_fit_score'         => $signal['corridor_fit_score'] ?? 0.0,
                'entry_quality_score'        => $signal['entry_quality_score'] ?? 0.0,
                'analyzer_score'             => $signal['analyzer_score'] ?? 0.0,
                // Dynamic leverage V1
                'leverage_reason'            => $signal['leverage_reason'] ?? '',
                'leverage_mode'              => $signal['leverage_mode'] ?? 'auto',
                'stop_control_mode'          => $signal['stop_control_mode'] ?? 'auto',
                'manual_stop_loss_roi'       => $signal['manual_stop_loss_roi'] ?? 0.03,
                'stop_loss_from_entry_roi'   => $signal['stop_loss_from_entry_roi'] ?? 0.10,
            ];
            $waitingSymbols[$symbol] = true;
        }

        // 2. WAITING → ACTIVE when price enters entry zone
        $newWaiting = [];
        foreach ($waiting as $w) {
            $symbol = (string)($w['symbol'] ?? '');
            $price  = $prices[$symbol] ?? 0.0;
            $low    = (float)($w['entry_zone_low'] ?? 0.0);
            $high   = (float)($w['entry_zone_high'] ?? 0.0);

            if ($price > 0.0 && $low > 0.0 && $high > 0.0
                && $price >= $low && $price <= $high
                && !isset($activeSymbols[$symbol])
            ) {
                // Compute effective stop floor
                $stopFloor = $this->computeStopFloor($w);

                // Use the larger of signal stoploss and stop floor
                $effectiveSL = (float)($w['stoploss'] ?? 0.0);
                if ($stopFloor > 0.0 && ($effectiveSL <= 0.0 || $stopFloor > $effectiveSL)) {
                    $effectiveSL = $stopFloor;
                }

                // Apply stop mode to compute stop distance
                $stopMode = (string)($w['stop_mode'] ?? 'brain_managed');
                $stopModeDistance = $this->computeStopModeDistance($w, $stopMode);
                if ($stopModeDistance > 0.0 && ($effectiveSL <= 0.0 || $stopModeDistance > $effectiveSL)) {
                    $effectiveSL = $stopModeDistance;
                }

                // Manual stop control override
                $stopControlMode = (string)($w['stop_control_mode'] ?? 'auto');
                if ($stopControlMode === 'manual') {
                    $manualStopLossRoi = (float)($w['manual_stop_loss_roi'] ?? 0.03);
                    if ($manualStopLossRoi > 0.0) {
                        $effectiveSL = $manualStopLossRoi;
                    }
                } elseif ($stopControlMode === 'entry_roi') {
                    $entryRoi = (float)($w['stop_loss_from_entry_roi'] ?? 0.10);
                    if ($entryRoi > 0.0) {
                        $effectiveSL = $entryRoi;
                    }
                }

                // Activate
                $active[] = [
                    'symbol'        => $symbol,
                    'entry_price'   => $price,
                    'current_price' => $price,
                    'budget'        => $w['budget'] ?? null,
                    'leverage'      => $w['leverage'] ?? null,
                    'stoploss'      => $effectiveSL,
                    'takeprofit'    => $w['takeprofit'] ?? null,
                    'roi'           => 0.0,
                    'mae'           => 0.0,
                    'mfe'           => 0.0,
                    'opened_at'     => date('c'),
                    'status'        => 'active',
                    'side'          => (string)($w['side'] ?? ''),
                    'trend_bias'    => $w['trend_bias'] ?? '',
                    // Exit policy state
                    'exit_mode'                  => $w['exit_mode'] ?? 'fixed_tp',
                    'stop_floor'                 => $stopFloor,
                    'trailing_enabled'           => $w['trailing_enabled'] ?? false,
                    'trailing_activation_roi'    => $w['trailing_activation_roi'] ?? 0.02,
                    'trailing_min_lock_roi'      => $w['trailing_min_lock_roi'] ?? 0.005,
                    'trailing_min_step'          => $w['trailing_min_step'] ?? 0.005,
                    'trailing_active'            => false,
                    'trailing_high_roi'          => 0.0,
                    'trailing_stop_roi'          => 0.0,
                    'fixed_take_profit_roi'      => $w['fixed_take_profit_roi'] ?? 0.05,
                    'break_even_enabled'         => $w['break_even_enabled'] ?? false,
                    'break_even_activation_roi'  => $w['break_even_activation_roi'] ?? 0.01,
                    'break_even_active'          => false,
                    // Stop Loss Engine V2
                    'stop_mode'                    => $stopMode,
                    'simple_stop_liq_factor'       => $w['simple_stop_liq_factor'] ?? 0.15,
                    'brain_stop_corridor_factor'   => $w['brain_stop_corridor_factor'] ?? 0.25,
                    'brain_stop_volatility_factor' => $w['brain_stop_volatility_factor'] ?? 0.50,
                    'brain_stop_liq_safety_factor' => $w['brain_stop_liq_safety_factor'] ?? 0.30,
                    // Early Failure Guard
                    'early_failure_enabled'        => $w['early_failure_enabled'] ?? false,
                    'early_failure_window_minutes' => $w['early_failure_window_minutes'] ?? 5,
                    'early_failure_max_adverse_roi' => $w['early_failure_max_adverse_roi'] ?? -0.008,
                    // Pattern algorithm tracking
                    'pattern_algorithm'          => $w['pattern_algorithm'] ?? 'none',
                    'pattern_confidence'         => $w['pattern_confidence'] ?? 0.0,
                    'trend_match_score'          => $w['trend_match_score'] ?? 0.0,
                    'corridor_fit_score'         => $w['corridor_fit_score'] ?? 0.0,
                    'entry_quality_score'        => $w['entry_quality_score'] ?? 0.0,
                    'analyzer_score'             => $w['analyzer_score'] ?? 0.0,
                    // Dynamic leverage V1
                    'leverage_reason'            => $w['leverage_reason'] ?? '',
                    'leverage_mode'              => $w['leverage_mode'] ?? 'auto',
                    'stop_control_mode'          => $w['stop_control_mode'] ?? 'auto',
                    'manual_stop_loss_roi'       => $w['manual_stop_loss_roi'] ?? 0.03,
                    'stop_loss_from_entry_roi'   => $w['stop_loss_from_entry_roi'] ?? 0.10,
                ];
                $activeSymbols[$symbol] = true;
            } else {
                $newWaiting[] = $w;
            }
        }
        $waiting = $newWaiting;

        // 3. Update ACTIVE trades: ROI, MAE, MFE; apply exit policy → CLOSED
        $newActive = [];
        foreach ($active as $a) {
            $symbol     = (string)($a['symbol'] ?? '');
            $entryPrice = (float)($a['entry_price'] ?? 0.0);
            $price      = $prices[$symbol] ?? (float)($a['current_price'] ?? 0.0);
            $stoploss   = (float)($a['stoploss'] ?? 0.0);
            $takeprofit = (float)($a['takeprofit'] ?? 0.0);
            $side       = (string)($a['side'] ?? '');

            if ($entryPrice <= 0.0) {
                $newActive[] = $a;
                continue;
            }

            // Side-aware ROI calculation
            if ($side === 'short') {
                $roi = ($entryPrice - $price) / $entryPrice;
            } else {
                $roi = ($price - $entryPrice) / $entryPrice;
            }
            $oldMae = (float)($a['mae'] ?? 0.0);
            $oldMfe = (float)($a['mfe'] ?? 0.0);

            // MAE = worst drawdown (most negative ROI seen)
            $mae = ($roi < 0.0)
                ? max($oldMae, abs($roi))
                : $oldMae;

            // MFE = best unrealised gain
            $mfe = ($roi > 0.0)
                ? max($oldMfe, $roi)
                : $oldMfe;

            $a['current_price'] = $price;
            $a['roi']           = round($roi, 6);
            $a['mae']           = round($mae, 6);
            $a['mfe']           = round($mfe, 6);

            // ===== Exit Policy Logic =====
            $stopFloor = (float)($a['stop_floor'] ?? 0.0);

            // A. Break-even support
            $breakEvenEnabled = (bool)($a['break_even_enabled'] ?? false);
            $breakEvenActivationRoi = (float)($a['break_even_activation_roi'] ?? 0.01);
            $breakEvenActive = (bool)($a['break_even_active'] ?? false);

            if ($breakEvenEnabled && !$breakEvenActive && $roi >= $breakEvenActivationRoi) {
                $breakEvenActive = true;
                $a['break_even_active'] = true;
                // Move stop to break-even (roi <= 0 triggers close)
                // stoploss = 0 means close at roi <= 0
                // Never weaken below stop floor
                $breakEvenSL = 0.0;
                if ($breakEvenSL < $stoploss) {
                    $stoploss = $breakEvenSL;
                    $a['stoploss'] = $stoploss;
                }
            }

            // B. Trailing support
            $trailingEnabled = (bool)($a['trailing_enabled'] ?? false);
            $trailingActivationRoi = (float)($a['trailing_activation_roi'] ?? 0.02);
            $trailingMinLockRoi = (float)($a['trailing_min_lock_roi'] ?? 0.005);
            $trailingMinStep = (float)($a['trailing_min_step'] ?? 0.005);
            $trailingActive = (bool)($a['trailing_active'] ?? false);
            $trailingHighRoi = (float)($a['trailing_high_roi'] ?? 0.0);
            $trailingStopRoi = (float)($a['trailing_stop_roi'] ?? 0.0);

            if ($trailingEnabled && $roi >= $trailingActivationRoi) {
                if (!$trailingActive) {
                    // Activate trailing
                    $trailingActive = true;
                    $trailingHighRoi = $roi;
                    // Lock at least trailing_min_lock_roi
                    $trailingStopRoi = max($trailingMinLockRoi, $roi - $trailingMinStep);
                } else {
                    // Update trailing — only move up
                    if ($roi > $trailingHighRoi) {
                        $newStop = max($trailingMinLockRoi, $roi - $trailingMinStep);
                        if ($newStop > $trailingStopRoi + 0.0000001) {
                            $trailingStopRoi = $newStop;
                        }
                        $trailingHighRoi = $roi;
                    }
                }

                $a['trailing_active'] = $trailingActive;
                $a['trailing_high_roi'] = round($trailingHighRoi, 6);
                $a['trailing_stop_roi'] = round($trailingStopRoi, 6);
            }

            // ===== Determine close reason =====
            $closedReason = null;

            // Early Failure Guard: close bad entries quickly
            $earlyFailureEnabled = (bool)($a['early_failure_enabled'] ?? false);
            if ($closedReason === null && $earlyFailureEnabled) {
                $earlyFailureWindowMinutes = (int)($a['early_failure_window_minutes'] ?? 5);
                $earlyFailureMaxAdverseRoi = (float)($a['early_failure_max_adverse_roi'] ?? -0.008);
                $openedAt = (string)($a['opened_at'] ?? '');
                if ($openedAt !== '') {
                    $tsOpen = strtotime($openedAt);
                    if ($tsOpen !== false) {
                        $tradeAgeMinutes = (time() - $tsOpen) / 60.0;
                        // Only check inside the early failure window
                        if ($tradeAgeMinutes <= $earlyFailureWindowMinutes && $roi <= $earlyFailureMaxAdverseRoi) {
                            $closedReason = 'early_failure';
                        }
                    }
                }
            }

            // Check trailing stop hit (higher priority than SL/TP for trailing mode)
            if ($closedReason === null && $trailingActive && $trailingStopRoi > 0.0 && $roi <= $trailingStopRoi) {
                $closedReason = 'trailing_stop';
            }

            // Check break-even stop (stoploss=0 means close at roi <= 0)
            if ($closedReason === null && $breakEvenActive && $stoploss <= 0.0 && $roi <= 0.0) {
                $closedReason = 'break_even_stop';
            }

            // Check stop-loss
            if ($closedReason === null && $stoploss > 0.0 && $roi <= -$stoploss) {
                $closedReason = 'stop_loss';
            }

            // Check take-profit
            if ($closedReason === null && $takeprofit > 0.0 && $roi >= $takeprofit) {
                $closedReason = 'take_profit';
            }

            if ($closedReason !== null) {
                $openedAt = (string)($a['opened_at'] ?? '');
                $closedAt = date('c');
                $duration = 0;
                if ($openedAt !== '') {
                    $tsOpen = strtotime($openedAt);
                    $tsClose = strtotime($closedAt);
                    if ($tsOpen !== false && $tsClose !== false && $tsClose > $tsOpen) {
                        $duration = (int)round(($tsClose - $tsOpen) / 60); // minutes
                    }
                }
                $closed[] = [
                    'symbol'      => $symbol,
                    'entry_price' => $entryPrice,
                    'exit_price'  => $price,
                    'budget'      => $a['budget'] ?? null,
                    'leverage'    => $a['leverage'] ?? null,
                    'stoploss'    => $stoploss,
                    'takeprofit'  => $takeprofit,
                    'roi'         => round($roi, 6),
                    'mae'         => round($mae, 6),
                    'mfe'         => round($mfe, 6),
                    'duration'    => $duration,
                    'reason'      => $closedReason,
                    'exit_mode'   => $a['exit_mode'] ?? 'fixed_tp',
                    'stop_mode'   => $a['stop_mode'] ?? 'brain_managed',
                    'side'        => $side,
                    'trend_bias'  => $a['trend_bias'] ?? '',
                    'opened_at'   => $openedAt,
                    'closed_at'   => $closedAt,
                    'status'      => 'closed',
                    'trailing_active'    => $trailingActive,
                    'break_even_active'  => $breakEvenActive,
                    'pattern_algorithm'  => $a['pattern_algorithm'] ?? 'none',
                    'pattern_confidence' => $a['pattern_confidence'] ?? 0.0,
                    'trend_match_score'  => $a['trend_match_score'] ?? 0.0,
                    'corridor_fit_score' => $a['corridor_fit_score'] ?? 0.0,
                    'entry_quality_score' => $a['entry_quality_score'] ?? 0.0,
                    'analyzer_score'     => $a['analyzer_score'] ?? 0.0,
                    'leverage_reason'    => $a['leverage_reason'] ?? '',
                    'leverage_mode'              => $a['leverage_mode'] ?? 'auto',
                    'stop_control_mode'          => $a['stop_control_mode'] ?? 'auto',
                    'manual_stop_loss_roi'       => $a['manual_stop_loss_roi'] ?? 0.03,
                    'stop_loss_from_entry_roi'   => $a['stop_loss_from_entry_roi'] ?? 0.10,
                ];
                // Remove from activeSymbols so new signal can enter
                unset($activeSymbols[$symbol]);
            } else {
                $newActive[] = $a;
            }
        }

        $this->state->writeJson('storage/simulator/waiting.json', $waiting);
        $this->state->writeJson('storage/simulator/active.json', $newActive);
        $this->state->writeJson('storage/simulator/closed.json', $closed);
    }

    /**
     * Compute the effective stop floor for a waiting trade.
     *
     * @param array<string,mixed> $trade
     * @return float
     */
    private function computeStopFloor(array $trade): float
    {
        $stopFloorType = (string)($trade['stop_floor_type'] ?? 'roi_percent');
        $stopFloorValue = (float)($trade['stop_floor_value'] ?? 0.0);

        if ($stopFloorType === 'corridor_percent') {
            $corridorWidth = (float)($trade['corridor_width'] ?? 0.0);
            return $corridorWidth * $stopFloorValue;
        }

        // roi_percent — direct value
        return $stopFloorValue;
    }

    /**
     * Compute stop distance based on stop mode (Stop Loss Engine V2).
     *
     * simple_liq_percent:
     *   stop_distance = distance_to_liq × simple_stop_liq_factor
     *   Liquidation distance estimated from leverage: 1/leverage (ROI at liquidation).
     *   If leverage is unavailable, returns 0 (fallback to existing stop).
     *
     * brain_managed:
     *   Uses conservative combination of corridor, volatility, and liquidation safety.
     *   Final stop = max(corridor_stop, volatility_stop, liq_stop)
     *   This ensures the brain stop is never weaker than any single component.
     *
     * @param array<string,mixed> $trade
     * @param string $stopMode
     * @return float  Stop distance as ROI fraction (e.g. 0.05 = 5%)
     */
    private function computeStopModeDistance(array $trade, string $stopMode): float
    {
        $leverage = (int)($trade['leverage'] ?? 0);
        // Estimate distance to liquidation as ROI fraction: roughly 1/leverage
        // e.g. leverage=5 → liq at ~20% adverse move → distance_to_liq = 0.20
        $distanceToLiq = ($leverage > 0) ? (1.0 / $leverage) : 0.0;

        if ($stopMode === 'simple_liq_percent') {
            // Simple mode: fraction of distance to liquidation
            $factor = (float)($trade['simple_stop_liq_factor'] ?? 0.15);
            if ($distanceToLiq <= 0.0) {
                // Fallback: liquidation info unavailable, use existing stop
                return 0.0;
            }
            return round($distanceToLiq * $factor, 6);
        }

        if ($stopMode === 'brain_managed') {
            $corridorWidth = (float)($trade['corridor_width'] ?? 0.0);
            $corridorFactor = (float)($trade['brain_stop_corridor_factor'] ?? 0.25);
            $volatilityFactor = (float)($trade['brain_stop_volatility_factor'] ?? 0.50);
            $liqSafetyFactor = (float)($trade['brain_stop_liq_safety_factor'] ?? 0.30);

            // 1. Corridor component: corridor_size × brain_stop_corridor_factor
            $corridorStop = $corridorWidth * $corridorFactor;

            // 2. Volatility component: use corridor_width as volatility proxy × factor
            //    (corridor width is the best available volatility measure in this context)
            $volatilityStop = $corridorWidth * $volatilityFactor;

            // 3. Liquidation safety component
            $liqStop = ($distanceToLiq > 0.0) ? ($distanceToLiq * $liqSafetyFactor) : 0.0;

            // Conservative rule: use the maximum of all three components
            // This ensures the brain stop is never weaker than any single safety measure
            $brainStop = max($corridorStop, $volatilityStop, $liqStop);

            return round($brainStop, 6);
        }

        return 0.0;
    }

    /**
     * Compute simulator statistics (Phase 8).
     *
     * @return array<string,mixed>
     */
    public function computeStats(): array
    {
        $active  = $this->state->readJson('storage/simulator/active.json', []);
        $closed  = $this->state->readJson('storage/simulator/closed.json', []);
        $signals = $this->state->readJson('storage/signals.json', []);
        $waiting = $this->state->readJson('storage/simulator/waiting.json', []);

        $totalClosed = count($closed);
        $wins = 0;
        $rois = [];
        $maes = [];
        $mfes = [];
        $durations = [];

        foreach ($closed as $trade) {
            $roi = (float)($trade['roi'] ?? 0.0);
            $rois[] = $roi;
            if ($roi >= 0) {
                $wins++;
            }
            $maes[]      = (float)($trade['mae'] ?? 0.0);
            $mfes[]      = (float)($trade['mfe'] ?? 0.0);
            $durations[] = (float)($trade['duration'] ?? 0.0);
        }

        $winrate    = ($totalClosed > 0) ? round($wins / $totalClosed, 4) : 0.0;
        $averageRoi = ($totalClosed > 0) ? round(array_sum($rois) / $totalClosed, 6) : 0.0;
        $medianMae  = $this->median($maes);
        $medianMfe  = $this->median($mfes);
        $medianDur  = $this->median($durations);

        // signal_to_entry_conversion: use cumulative counts to avoid > 1.0 bug.
        // All trades that ever entered = waiting + active + closed.
        // signals.json only holds current cycle, so use total entries as denominator proxy.
        $totalEntries = count($waiting) + count($active) + $totalClosed;
        $enteredCount = count($active) + $totalClosed;
        $conversion   = ($totalEntries > 0)
            ? round($enteredCount / $totalEntries, 4)
            : 0.0;

        // Per-pattern statistics
        $patternStats = [];
        foreach ($closed as $trade) {
            $pattern = (string)($trade['pattern_algorithm'] ?? 'none');
            if (!isset($patternStats[$pattern])) {
                $patternStats[$pattern] = [
                    'trades_total' => 0, 'wins' => 0, 'losses' => 0,
                    'roi_sum' => 0.0, 'mae_sum' => 0.0, 'mfe_sum' => 0.0, 'duration_sum' => 0.0,
                    'long_count' => 0, 'short_count' => 0,
                    'stop_loss_count' => 0, 'early_failure_count' => 0,
                    'trailing_stop_count' => 0, 'break_even_stop_count' => 0,
                    'take_profit_count' => 0, 'leverage_sum' => 0.0,
                ];
            }
            $p = &$patternStats[$pattern];
            $tradeRoi = (float)($trade['roi'] ?? 0.0);
            $p['trades_total']++;
            if ($tradeRoi >= 0) { $p['wins']++; } else { $p['losses']++; }
            $p['roi_sum'] += $tradeRoi;
            $p['mae_sum'] += (float)($trade['mae'] ?? 0.0);
            $p['mfe_sum'] += (float)($trade['mfe'] ?? 0.0);
            $p['duration_sum'] += (float)($trade['duration'] ?? 0.0);
            $side = (string)($trade['side'] ?? '');
            if ($side === 'long') { $p['long_count']++; }
            if ($side === 'short') { $p['short_count']++; }
            $reason = (string)($trade['reason'] ?? '');
            match ($reason) {
                'stop_loss' => $p['stop_loss_count']++,
                'early_failure' => $p['early_failure_count']++,
                'trailing_stop' => $p['trailing_stop_count']++,
                'break_even_stop' => $p['break_even_stop_count']++,
                'take_profit' => $p['take_profit_count']++,
                default => null,
            };
            $p['leverage_sum'] += (float)($trade['leverage'] ?? 0.0);
            unset($p);
        }

        // Finalize per-pattern stats with derived metrics
        $patternStatsResult = [];
        foreach ($patternStats as $pattern => $p) {
            $t = $p['trades_total'];
            $patternStatsResult[$pattern] = [
                'trades_total' => $t,
                'wins' => $p['wins'],
                'losses' => $p['losses'],
                'winrate' => $t > 0 ? round($p['wins'] / $t, 4) : 0.0,
                'average_roi' => $t > 0 ? round($p['roi_sum'] / $t, 6) : 0.0,
                'average_mae' => $t > 0 ? round($p['mae_sum'] / $t, 6) : 0.0,
                'average_mfe' => $t > 0 ? round($p['mfe_sum'] / $t, 6) : 0.0,
                'average_duration' => $t > 0 ? round($p['duration_sum'] / $t, 1) : 0.0,
                'long_count' => $p['long_count'],
                'short_count' => $p['short_count'],
                'stop_loss_count' => $p['stop_loss_count'],
                'early_failure_count' => $p['early_failure_count'],
                'trailing_stop_count' => $p['trailing_stop_count'],
                'break_even_stop_count' => $p['break_even_stop_count'],
                'take_profit_count' => $p['take_profit_count'],
                'average_leverage' => $t > 0 ? round($p['leverage_sum'] / $t, 2) : 0.0,
            ];
        }

        // Leverage mode stats
        $leverageModeStats = ['manual' => ['count' => 0, 'wins' => 0, 'roi_sum' => 0.0], 'auto' => ['count' => 0, 'wins' => 0, 'roi_sum' => 0.0]];
        foreach ($closed as $trade) {
            $lm = (string)($trade['leverage_mode'] ?? 'auto');
            $key = ($lm === 'manual') ? 'manual' : 'auto';
            $leverageModeStats[$key]['count']++;
            $tradeRoi = (float)($trade['roi'] ?? 0.0);
            $leverageModeStats[$key]['roi_sum'] += $tradeRoi;
            if ($tradeRoi >= 0) { $leverageModeStats[$key]['wins']++; }
        }

        // Stop control mode stats
        $stopControlStats = ['manual' => ['count' => 0, 'wins' => 0, 'mae_sum' => 0.0], 'auto' => ['count' => 0, 'wins' => 0, 'mae_sum' => 0.0], 'entry_roi' => ['count' => 0, 'wins' => 0, 'mae_sum' => 0.0]];
        foreach ($closed as $trade) {
            $scm = (string)($trade['stop_control_mode'] ?? 'auto');
            $key = in_array($scm, ['manual', 'entry_roi'], true) ? $scm : 'auto';
            $stopControlStats[$key]['count']++;
            $tradeRoi = (float)($trade['roi'] ?? 0.0);
            if ($tradeRoi >= 0) { $stopControlStats[$key]['wins']++; }
            $stopControlStats[$key]['mae_sum'] += (float)($trade['mae'] ?? 0.0);
        }

        // Reversal V1 vs V2 comparison
        $reversalComparison = $this->computeReversalComparison($closed, $patternStatsResult);

        // Regression audit: short-side collapse analysis
        $regressionAudit = $this->computeRegressionAudit($closed);

        $stats = [
            'total_trades'               => $totalClosed,
            'winrate'                    => $winrate,
            'average_roi'                => $averageRoi,
            'median_mae'                 => $medianMae,
            'median_mfe'                 => $medianMfe,
            'median_duration'            => $medianDur,
            'signal_to_entry_conversion' => $conversion,
            'waiting_count'              => count($waiting),
            'active_count'               => count($active),
            'closed_count'               => $totalClosed,
            'pattern_stats'              => $patternStatsResult,
            'reversal_comparison'        => $reversalComparison,
            'regression_audit'           => $regressionAudit,
            'leverage_mode_stats'        => $leverageModeStats,
            'stop_control_stats'         => $stopControlStats,
            'updated_at'                 => date('c'),
        ];

        $this->state->writeJson('storage/simulator/stats.json', $stats);

        return $stats;
    }

    /**
     * Compute reversal V1 vs V2 comparison metrics.
     *
     * Includes: family aggregation, false reversal proxy, expectancy,
     * median ROI, and promotion criteria evaluation.
     *
     * False reversal proxy:
     *   A trade is considered a "false reversal" if it was closed by
     *   stop_loss or early_failure — meaning the reversal hypothesis
     *   was invalidated quickly without meaningful favorable excursion.
     *
     * @param array<int,array<string,mixed>> $closed
     * @param array<string,array<string,mixed>> $patternStatsResult
     * @return array<string,mixed>
     */
    private function computeReversalComparison(array $closed, array $patternStatsResult): array
    {
        $v1Patterns = ['double_bottom', 'double_top'];
        $v2Patterns = ['double_bottom_confirm_v2', 'double_top_confirm_v2'];

        $v1Aggregate = $this->computeFamilyAggregate($closed, $v1Patterns);
        $v2Aggregate = $this->computeFamilyAggregate($closed, $v2Patterns);

        // Per-type comparison: bottom V1 vs V2, top V1 vs V2
        $bottomComparison = [
            'v1' => $patternStatsResult['double_bottom'] ?? [],
            'v2' => $patternStatsResult['double_bottom_confirm_v2'] ?? [],
        ];
        $topComparison = [
            'v1' => $patternStatsResult['double_top'] ?? [],
            'v2' => $patternStatsResult['double_top_confirm_v2'] ?? [],
        ];

        // Promotion criteria evaluation
        $promotion = $this->evaluatePromotionCriteria($v1Aggregate, $v2Aggregate);

        // V2 stage counters (setup → confirm funnel)
        $v2StageCounters = $this->loadV2StageCounters();

        return [
            'v1_aggregate' => $v1Aggregate,
            'v2_aggregate' => $v2Aggregate,
            'bottom_patterns' => $bottomComparison,
            'top_patterns' => $topComparison,
            'promotion_criteria' => $promotion,
            'v2_stage_counters' => $v2StageCounters,
            'compare_mode_active' => true,
            'evaluation_note' => 'V2 is under shadow evaluation. Do not promote without statistical evidence.',
        ];
    }

    /**
     * Load V2 stage counters persisted by parser4_analyzer.
     *
     * Returns per-algorithm and family-aggregate setup/confirm/reject
     * counts that explain V2's internal two-stage filtering behavior.
     *
     * @return array<string,mixed>
     */
    private function loadV2StageCounters(): array
    {
        $data = $this->state->readJson('storage/v2_stage_counters.json', []);
        if ($data === []) {
            return [
                'by_algorithm' => [],
                'reversal_v2_aggregate' => [
                    'setup_candidates_count' => 0,
                    'confirmed_signals_count' => 0,
                    'confirm_rejected_count' => 0,
                    'confirmation_rate' => 0.0,
                    'rejection_rate' => 0.0,
                ],
                'available' => false,
            ];
        }
        $data['available'] = true;
        return $data;
    }

    /**
     * Aggregate metrics for a reversal family (V1 or V2).
     *
     * Computes: trades, winrate, avg/median ROI, expectancy,
     * false reversal count/rate, stop hit rate, avg MAE/MFE, avg duration.
     *
     * @param array<int,array<string,mixed>> $closed
     * @param array<int,string> $patterns
     * @return array<string,mixed>
     */
    private function computeFamilyAggregate(array $closed, array $patterns): array
    {
        $trades = array_filter($closed, fn($t) => in_array((string)($t['pattern_algorithm'] ?? ''), $patterns, true));
        $trades = array_values($trades);
        $count = count($trades);

        $result = [
            'patterns' => $patterns,
            'trades_total' => 0,
            'wins' => 0,
            'losses' => 0,
            'winrate' => 0.0,
            'avg_roi' => 0.0,
            'median_roi' => 0.0,
            'avg_win' => 0.0,
            'avg_loss' => 0.0,
            'expectancy' => 0.0,
            'false_reversal_count' => 0,
            'false_reversal_rate' => 0.0,
            'stop_hit_count' => 0,
            'stop_hit_rate' => 0.0,
            'avg_mae' => 0.0,
            'avg_mfe' => 0.0,
            'avg_duration' => 0.0,
            'long_count' => 0,
            'short_count' => 0,
        ];

        if ($count === 0) {
            return $result;
        }

        $rois = [];
        $winRois = [];
        $lossRois = [];
        $maes = [];
        $mfes = [];
        $durations = [];
        $wins = 0;
        $losses = 0;
        $falseReversals = 0;
        $stopHits = 0;
        $longCount = 0;
        $shortCount = 0;

        foreach ($trades as $t) {
            $roi = (float)($t['roi'] ?? 0.0);
            $rois[] = $roi;
            $maes[] = (float)($t['mae'] ?? 0.0);
            $mfes[] = (float)($t['mfe'] ?? 0.0);
            $durations[] = (float)($t['duration'] ?? 0.0);

            if ($roi >= 0) {
                $wins++;
                $winRois[] = $roi;
            } else {
                $losses++;
                $lossRois[] = $roi;
            }

            $side = (string)($t['side'] ?? '');
            if ($side === 'long') { $longCount++; }
            if ($side === 'short') { $shortCount++; }

            $reason = (string)($t['reason'] ?? '');

            // False reversal proxy: stop_loss or early_failure
            // These indicate the reversal hypothesis was immediately invalidated
            if ($reason === 'stop_loss' || $reason === 'early_failure') {
                $falseReversals++;
            }

            // Stop hit: stop_loss specifically
            if ($reason === 'stop_loss') {
                $stopHits++;
            }
        }

        $avgWin = count($winRois) > 0 ? round(array_sum($winRois) / count($winRois), 6) : 0.0;
        $avgLoss = count($lossRois) > 0 ? round(array_sum($lossRois) / count($lossRois), 6) : 0.0;
        $winrate = round($wins / $count, 4);

        // Expectancy = (winrate × avg_win) + ((1 - winrate) × avg_loss)
        // avg_loss is negative, so this naturally subtracts
        $expectancy = round(($winrate * $avgWin) + ((1 - $winrate) * $avgLoss), 6);

        $result['trades_total'] = $count;
        $result['wins'] = $wins;
        $result['losses'] = $losses;
        $result['winrate'] = $winrate;
        $result['avg_roi'] = round(array_sum($rois) / $count, 6);
        $result['median_roi'] = $this->median($rois);
        $result['avg_win'] = $avgWin;
        $result['avg_loss'] = $avgLoss;
        $result['expectancy'] = $expectancy;
        $result['false_reversal_count'] = $falseReversals;
        $result['false_reversal_rate'] = round($falseReversals / $count, 4);
        $result['stop_hit_count'] = $stopHits;
        $result['stop_hit_rate'] = round($stopHits / $count, 4);
        $result['avg_mae'] = round(array_sum($maes) / $count, 6);
        $result['avg_mfe'] = round(array_sum($mfes) / $count, 6);
        $result['avg_duration'] = round(array_sum($durations) / $count, 2);
        $result['long_count'] = $longCount;
        $result['short_count'] = $shortCount;

        return $result;
    }

    /**
     * Evaluate V2 promotion criteria against V1 baseline.
     *
     * V2 can be considered better only if on meaningful sample it shows:
     * - expectancy >= V1
     * - false reversal rate lower than V1
     * - stop hit rate lower or healthier than V1
     * - signal count not catastrophically low (>= 25% of V1)
     * - median ROI not materially worse
     *
     * @param array<string,mixed> $v1
     * @param array<string,mixed> $v2
     * @return array<string,mixed>
     */
    private function evaluatePromotionCriteria(array $v1, array $v2): array
    {
        $v1Trades = (int)($v1['trades_total'] ?? 0);
        $v2Trades = (int)($v2['trades_total'] ?? 0);
        $minSample = 10;

        $sufficient_sample = ($v1Trades >= $minSample && $v2Trades >= $minSample);

        $v1Expectancy = (float)($v1['expectancy'] ?? 0);
        $v2Expectancy = (float)($v2['expectancy'] ?? 0);
        $v1FalseRate = (float)($v1['false_reversal_rate'] ?? 0);
        $v2FalseRate = (float)($v2['false_reversal_rate'] ?? 0);
        $v1StopRate = (float)($v1['stop_hit_rate'] ?? 0);
        $v2StopRate = (float)($v2['stop_hit_rate'] ?? 0);
        $v1MedianRoi = (float)($v1['median_roi'] ?? 0);
        $v2MedianRoi = (float)($v2['median_roi'] ?? 0);

        // Signal count not catastrophically low: V2 >= 25% of V1 trades
        $signalCountOk = ($v1Trades === 0) || ($v2Trades >= $v1Trades * 0.25);

        $criteria = [
            'sufficient_sample' => $sufficient_sample,
            'min_sample_required' => $minSample,
            'expectancy_pass' => $sufficient_sample && $v2Expectancy >= $v1Expectancy,
            'false_reversal_pass' => $sufficient_sample && $v2FalseRate <= $v1FalseRate,
            'stop_hit_pass' => $sufficient_sample && $v2StopRate <= $v1StopRate,
            'signal_count_ok' => $signalCountOk,
            'median_roi_pass' => $sufficient_sample && $v2MedianRoi >= $v1MedianRoi * 0.8,
            'v1_trades' => $v1Trades,
            'v2_trades' => $v2Trades,
        ];

        // Overall verdict
        if (!$sufficient_sample) {
            $criteria['verdict'] = 'insufficient_data';
            $criteria['verdict_label'] = 'Недостаточно данных для оценки';
        } elseif ($criteria['expectancy_pass'] && $criteria['false_reversal_pass'] && $criteria['stop_hit_pass'] && $criteria['signal_count_ok']) {
            $criteria['verdict'] = 'v2_promoted';
            $criteria['verdict_label'] = 'V2 превосходит V1 — рекомендуется повышение';
        } elseif ($criteria['expectancy_pass'] && $criteria['false_reversal_pass']) {
            $criteria['verdict'] = 'v2_promising';
            $criteria['verdict_label'] = 'V2 перспективен — продолжить наблюдение';
        } else {
            $criteria['verdict'] = 'v1_baseline';
            $criteria['verdict_label'] = 'V1 остаётся базовым — V2 не доказал преимущество';
        }

        return $criteria;
    }

    /**
     * Compute regression audit: per-pattern per-side breakdown + what-if scenarios.
     *
     * Helps localize short-side collapse by producing:
     * - Per-pattern × per-side matrix (trades, winrate, ROI, false reversal rate, stop rate)
     * - What-if exclusion scenarios (disable problematic patterns)
     * - Ranked severity list identifying worst contributors
     *
     * @param array<int,array<string,mixed>> $closed
     * @return array<string,mixed>
     */
    private function computeRegressionAudit(array $closed): array
    {
        $allPatterns = ['double_bottom', 'double_top', 'pullback_trend_continue', 'double_bottom_confirm_v2', 'double_top_confirm_v2'];
        $sides = ['long', 'short'];

        // ── Per-pattern × per-side matrix ──
        $matrix = [];
        foreach ($allPatterns as $pattern) {
            foreach ($sides as $side) {
                $key = $pattern . '/' . $side;
                $matrix[$key] = $this->computeCellStats($closed, $pattern, $side);
            }
        }

        // ── Per-pattern totals (both sides) ──
        $patternTotals = [];
        foreach ($allPatterns as $pattern) {
            $patternTotals[$pattern] = $this->computeCellStats($closed, $pattern, null);
        }

        // ── Per-side totals (all patterns) ──
        $sideTotals = [];
        foreach ($sides as $side) {
            $sideTotals[$side] = $this->computeCellStats($closed, null, $side);
        }

        // ── Overall total ──
        $overallTotal = $this->computeCellStats($closed, null, null);

        // ── What-if exclusion scenarios ──
        $scenarios = $this->computeWhatIfScenarios($closed);

        // ── Severity ranking: identify worst contributors ──
        $severity = $this->computeSeverityRanking($matrix, $patternTotals, $overallTotal);

        return [
            'matrix' => $matrix,
            'pattern_totals' => $patternTotals,
            'side_totals' => $sideTotals,
            'overall' => $overallTotal,
            'what_if_scenarios' => $scenarios,
            'severity_ranking' => $severity,
            'has_data' => $overallTotal['trades'] > 0,
        ];
    }

    /**
     * Compute stats for a specific pattern/side cell in the regression matrix.
     *
     * @param array<int,array<string,mixed>> $closed
     * @param string|null $pattern  Pattern filter, null = all patterns
     * @param string|null $side     Side filter, null = both sides
     * @return array<string,mixed>
     */
    private function computeCellStats(array $closed, ?string $pattern, ?string $side): array
    {
        $trades = array_filter($closed, function ($t) use ($pattern, $side) {
            if ($pattern !== null && (string)($t['pattern_algorithm'] ?? '') !== $pattern) {
                return false;
            }
            if ($side !== null && (string)($t['side'] ?? '') !== $side) {
                return false;
            }
            return true;
        });
        $trades = array_values($trades);
        $count = count($trades);

        $result = [
            'trades' => 0,
            'wins' => 0,
            'losses' => 0,
            'winrate' => 0.0,
            'avg_roi' => 0.0,
            'median_roi' => 0.0,
            'false_reversal_count' => 0,
            'false_reversal_rate' => 0.0,
            'stop_hit_count' => 0,
            'stop_hit_rate' => 0.0,
            'early_failure_count' => 0,
            'early_failure_rate' => 0.0,
            'avg_mae' => 0.0,
            'avg_mfe' => 0.0,
            'avg_duration' => 0.0,
        ];

        if ($count === 0) {
            return $result;
        }

        $rois = [];
        $maes = [];
        $mfes = [];
        $durations = [];
        $wins = 0;
        $falseReversals = 0;
        $stopHits = 0;
        $earlyFailures = 0;

        foreach ($trades as $t) {
            $roi = (float)($t['roi'] ?? 0.0);
            $rois[] = $roi;
            $maes[] = (float)($t['mae'] ?? 0.0);
            $mfes[] = (float)($t['mfe'] ?? 0.0);
            $durations[] = (float)($t['duration'] ?? 0.0);

            if ($roi >= 0) { $wins++; }

            $reason = (string)($t['reason'] ?? '');
            if ($reason === 'stop_loss' || $reason === 'early_failure') {
                $falseReversals++;
            }
            if ($reason === 'stop_loss') { $stopHits++; }
            if ($reason === 'early_failure') { $earlyFailures++; }
        }

        $result['trades'] = $count;
        $result['wins'] = $wins;
        $result['losses'] = $count - $wins;
        $result['winrate'] = round($wins / $count, 4);
        $result['avg_roi'] = round(array_sum($rois) / $count, 6);
        $result['median_roi'] = $this->median($rois);
        $result['false_reversal_count'] = $falseReversals;
        $result['false_reversal_rate'] = round($falseReversals / $count, 4);
        $result['stop_hit_count'] = $stopHits;
        $result['stop_hit_rate'] = round($stopHits / $count, 4);
        $result['early_failure_count'] = $earlyFailures;
        $result['early_failure_rate'] = round($earlyFailures / $count, 4);
        $result['avg_mae'] = round(array_sum($maes) / $count, 6);
        $result['avg_mfe'] = round(array_sum($mfes) / $count, 6);
        $result['avg_duration'] = round(array_sum($durations) / $count, 2);

        return $result;
    }

    /**
     * Compute what-if exclusion scenarios to identify minimal safe rollback.
     *
     * Tests: disable double_top only, pullback only, both,
     * V1 long only + V2 short, V2 only.
     *
     * @param array<int,array<string,mixed>> $closed
     * @return array<string,array<string,mixed>>
     */
    private function computeWhatIfScenarios(array $closed): array
    {
        $scenarios = [];

        // Scenario 1: disable double_top only
        $scenarios['disable_double_top'] = $this->computeCellStats(
            array_values(array_filter($closed, fn($t) => (string)($t['pattern_algorithm'] ?? '') !== 'double_top')),
            null, null
        );
        $scenarios['disable_double_top']['description'] = 'Без double_top (V1 short reversal)';

        // Scenario 2: disable pullback_trend_continue only
        $scenarios['disable_pullback'] = $this->computeCellStats(
            array_values(array_filter($closed, fn($t) => (string)($t['pattern_algorithm'] ?? '') !== 'pullback_trend_continue')),
            null, null
        );
        $scenarios['disable_pullback']['description'] = 'Без pullback_trend_continue';

        // Scenario 3: disable both double_top and pullback
        $excludeBoth = ['double_top', 'pullback_trend_continue'];
        $scenarios['disable_top_and_pullback'] = $this->computeCellStats(
            array_values(array_filter($closed, fn($t) => !in_array((string)($t['pattern_algorithm'] ?? ''), $excludeBoth, true))),
            null, null
        );
        $scenarios['disable_top_and_pullback']['description'] = 'Без double_top и pullback';

        // Scenario 4: only double_bottom + V2 patterns
        $keepOnly = ['double_bottom', 'double_bottom_confirm_v2', 'double_top_confirm_v2'];
        $scenarios['bottom_plus_v2_only'] = $this->computeCellStats(
            array_values(array_filter($closed, fn($t) => in_array((string)($t['pattern_algorithm'] ?? ''), $keepOnly, true))),
            null, null
        );
        $scenarios['bottom_plus_v2_only']['description'] = 'Только double_bottom + V2';

        // Scenario 5: V1 long only + V2 any side
        $v2Patterns = ['double_bottom_confirm_v2', 'double_top_confirm_v2'];
        $scenarios['v1_long_v2_any'] = $this->computeCellStats(
            array_values(array_filter($closed, function ($t) use ($v2Patterns) {
                $algo = (string)($t['pattern_algorithm'] ?? '');
                $side = (string)($t['side'] ?? '');
                if (in_array($algo, $v2Patterns, true)) {
                    return true; // keep all V2
                }
                return $side === 'long'; // V1 long only
            })),
            null, null
        );
        $scenarios['v1_long_v2_any']['description'] = 'V1 только long + V2 любой';

        return $scenarios;
    }

    /**
     * Rank pattern/side cells by severity of damage to overall stats.
     *
     * Identifies which pattern/side combinations are most responsible
     * for poor performance, based on loss contribution.
     *
     * @param array<string,array<string,mixed>> $matrix
     * @param array<string,array<string,mixed>> $patternTotals
     * @param array<string,mixed> $overall
     * @return array<int,array<string,mixed>>
     */
    private function computeSeverityRanking(array $matrix, array $patternTotals, array $overall): array
    {
        $overallTrades = (int)($overall['trades'] ?? 0);
        if ($overallTrades === 0) {
            return [];
        }

        $overallWinrate = (float)($overall['winrate'] ?? 0);

        $ranking = [];
        foreach ($matrix as $key => $cell) {
            $cellTrades = (int)($cell['trades'] ?? 0);
            if ($cellTrades === 0) {
                continue;
            }

            $cellWinrate = (float)($cell['winrate'] ?? 0);
            $cellAvgRoi = (float)($cell['avg_roi'] ?? 0);
            $cellFalseRate = (float)($cell['false_reversal_rate'] ?? 0);

            // Damage score: negative ROI contribution + below-average winrate penalty
            $roiContribution = $cellAvgRoi * $cellTrades;
            $winrateDelta = $cellWinrate - $overallWinrate;

            // Higher damage score = more responsible for poor performance
            $damageScore = 0.0;
            if ($cellAvgRoi < 0) {
                $damageScore += abs($roiContribution) * 100;
            }
            if ($cellWinrate < $overallWinrate && $overallWinrate > 0) {
                $damageScore += (1 - $cellWinrate / max(0.01, $overallWinrate)) * $cellTrades;
            }
            $damageScore += $cellFalseRate * $cellTrades;

            $ranking[] = [
                'cell' => $key,
                'trades' => $cellTrades,
                'winrate' => $cellWinrate,
                'avg_roi' => $cellAvgRoi,
                'false_reversal_rate' => $cellFalseRate,
                'roi_contribution' => round($roiContribution, 6),
                'winrate_delta' => round($winrateDelta, 4),
                'damage_score' => round($damageScore, 4),
            ];
        }

        // Sort by damage score descending (worst first)
        usort($ranking, fn($a, $b) => $b['damage_score'] <=> $a['damage_score']);

        return $ranking;
    }

    /**
     * @param array<int,float> $values
     */
    private function median(array $values): float
    {
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        sort($values);
        $mid = intdiv($n, 2);
        if ($n % 2 === 0) {
            return round(($values[$mid - 1] + $values[$mid]) / 2.0, 6);
        }
        return round($values[$mid], 6);
    }
}
