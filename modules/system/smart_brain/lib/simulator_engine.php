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
                'side'           => $signal['side'] ?? 'long',
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
                    'side'          => $w['side'] ?? 'long',
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
            $side       = (string)($a['side'] ?? 'long');

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
                    'side'        => $a['side'] ?? 'long',
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

        $totalSignals = count($signals);
        $enteredCount = count($active) + $totalClosed;
        $conversion   = ($totalSignals > 0)
            ? round($enteredCount / $totalSignals, 4)
            : 0.0;

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
            'updated_at'                 => date('c'),
        ];

        $this->state->writeJson('storage/simulator/stats.json', $stats);

        return $stats;
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
