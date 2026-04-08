<?php
declare(strict_types=1);

namespace Modules\System\TradingBot\Lib;

/**
 * Bot Trailing Engine
 * 
 * Trailing stop logic for Trading Bot.
 * Uses trailing parameters from the normalized risk.trailing block.
 *
 * Supports three trailing modes:
 *   - roi_giveback (default): trailing distance = max_profit * drawdown_factor
 *   - price_distance: stop follows current best price at fixed pct distance
 *     LONG:  stop = best_price * (1 - trailing_price_distance_pct)
 *     SHORT: stop = best_price * (1 + trailing_price_distance_pct)
 *   - price_distance_floor: activates at floor_roi, locks minimum ROI, then
 *     follows best price at fixed pct distance with step corridor control
 *
 * Step modes for price_distance_floor:
 *   - fixed / auto_strength: price-based step corridor controls update frequency
 *   - fixed_roi_ladder: locked ROI grows in discrete ROI steps from floor_lock base
 *     Formula: locked = floor_lock + floor((peak_roi - activation_roi) / step_roi) * step_roi
 *     Peak ROI is monotonic (never decreases); protection only strengthens.
 *   - trend_reversal_soft_ladder_short: TEST MODE — SHORT V2/V3 only.
 *     Activates by peak ROI >= 10 alone — no mirrored long reversal signal required.
 *     Mirrored long signal lookup (double_bottom_contextual_v2/v3) is optional diagnostics only.
 *     Uses fixed overlay constants: activation=10, base_lock=5, main_step=3, lock_step=1.
 *     Overlay lock is injected via trade['reversal_overlay_active'] flag (set externally by executor).
 *     Formula: overlay_locked = 5 + floor((peak_roi - 10) / 3) * 1  (when peak >= 10).
 *
 * In Brain-controlled mode, risk.trailing is populated by
 * normalizeBrainTrailingIntoRisk() in bot_sources_trait.php.
 * Bot-local trailing toggles (enable_trailing_on_open, dumb_trailing_enabled)
 * do NOT affect this engine — they are gating logic in bot_executor_trait.php
 * that is bypassed when Brain-controlled mode is active.
 *
 * Key fields consumed from risk.trailing:
 *   enabled                    — whether trailing is active
 *   activation_roi_pct         — ROI % threshold to activate trailing
 *   trailing_mode              — 'roi_giveback' | 'price_distance' | 'price_distance_floor'
 *   drawdown_factor            — trailing distance multiplier (roi_giveback mode)
 *   trailing_price_distance_pct — fixed distance ratio (price_distance / price_distance_floor, 0.02 = 2%)
 *   trailing_activation_floor_roi — ROI threshold to activate floor trailing (price_distance_floor)
 *   trailing_floor_lock_roi      — minimum guaranteed ROI once floor trailing activates
 *   trailing_step_mode           — 'fixed' | 'auto_strength' | 'fixed_roi_ladder' | 'trend_reversal_soft_ladder_short'
 *   trailing_step_pct_min        — minimum step size for trailing updates (fixed/auto_strength)
 *   trailing_step_pct_max        — maximum step size for trailing updates (fixed/auto_strength)
 *   trailing_step_roi            — ROI step size for fixed_roi_ladder mode
 */
class BotTrailingEngine
{
    private array $config;
    
    public function __construct(array $config)
    {
        $this->config = $config;
    }
    
    /**
     * Check trailing stop for trade
     * 
     * Supports two trailing modes:
     *   - roi_giveback (default): trailing distance = max_profit * drawdown_factor
     *   - price_distance: trailing stop = current_price * (1 ± trailing_price_distance_pct)
     *   - price_distance_floor: activation + floor lock + price distance follow + step corridor
     * 
     * @param array $trade Trade data
     * @param float $currentPrice Current market price
     * @return array Check result with close_reason if triggered
     */
    public function checkTrailing(array $trade, float $currentPrice): array
    {
        $result = [
            'triggered' => false,
            'updated' => false,
            'changes' => [],
            'close_reason' => null,
        ];
        
        $risk = $trade['risk'] ?? [];
        $trailing = $risk['trailing'] ?? [];
        
        // Check if trailing is enabled
        if (!($trailing['enabled'] ?? false)) {
            return $result;
        }
        
        $side = $trade['side'];
        $entryPrice = $trade['entry_price'];
        $trailingMode = (string)($trailing['trailing_mode'] ?? 'roi_giveback');

        // Activation threshold: price_distance_floor uses its own floor-specific activation
        if ($trailingMode === 'price_distance_floor') {
            $activationRoiPct = (float)($trailing['trailing_activation_floor_roi'] ?? ($trailing['activation_roi_pct'] ?? 0));
        } else {
            $activationRoiPct = (float)($trailing['activation_roi_pct'] ?? 0);
        }
        
        // Calculate current ROI
        $roi = $this->calculateRoi($entryPrice, $currentPrice, $side);
        
        // Check if trailing is activated
        $trailingActivated = $trade['trailing_activated'] ?? false;
        
        if (!$trailingActivated && $roi >= $activationRoiPct) {
            // Activate trailing
            $result['updated'] = true;
            $result['changes']['trailing_activated'] = true;
            $result['changes']['trailing_activated_at'] = date('c');
            $result['changes']['trailing_activated_price'] = $currentPrice;
            $result['changes']['trailing_activation_roi_threshold'] = $activationRoiPct;
            $result['changes']['trailing_roi_at_activation'] = round($roi, 4);
            $result['changes']['trailing_mode'] = $trailingMode;
            if ($trailingMode === 'price_distance_floor') {
                $result['changes']['floor_lock_active'] = true;
                $result['changes']['floor_locked_roi'] = (float)($trailing['trailing_floor_lock_roi'] ?? 3.0);
            }
            $trailingActivated = true;
        }
        
        if (!$trailingActivated) {
            return $result;
        }
        
        // Dispatch to appropriate trailing mode
        if ($trailingMode === 'price_distance_floor') {
            return $this->checkPriceDistanceFloorTrailing($trade, $currentPrice, $result, $trailing, $side, $entryPrice);
        }
        if ($trailingMode === 'price_distance') {
            return $this->checkPriceDistanceTrailing($trade, $currentPrice, $result, $trailing, $side, $entryPrice);
        }

        return $this->checkRoiGivebackTrailing($trade, $currentPrice, $result, $trailing, $side, $entryPrice);
    }

    /**
     * Price-distance trailing mode.
     *
     * Keeps stop at a fixed percentage distance from the current best price.
     * For LONG: stop = best_price * (1 - trailing_price_distance_pct)
     * For SHORT: stop = best_price * (1 + trailing_price_distance_pct)
     *
     * Monotonic: stop never moves backward (down for long, up for short).
     */
    private function checkPriceDistanceTrailing(
        array $trade,
        float $currentPrice,
        array $result,
        array $trailing,
        string $side,
        float $entryPrice
    ): array {
        $distancePct = (float)($trailing['trailing_price_distance_pct'] ?? 0.02);

        // Retrieve previous trailing stop (monotonic protection)
        $prevTrailingStop = (float)($trade['trailing_stop_price'] ?? 0.0);

        if ($side === 'long') {
            // Track high watermark
            $trailingHighWatermark = (float)($trade['trailing_high_watermark'] ?? $currentPrice);
            if ($currentPrice > $trailingHighWatermark) {
                $result['updated'] = true;
                $result['changes']['trailing_high_watermark'] = $currentPrice;
                $trailingHighWatermark = $currentPrice;
            }

            // Price-distance stop from best price
            $candidateStop = $trailingHighWatermark * (1.0 - $distancePct);

            // Monotonic: stop can only move up for long
            $trailingStopPrice = max($candidateStop, $prevTrailingStop);

            $result['changes']['trailing_stop_price'] = round($trailingStopPrice, 8);
            $result['changes']['trailing_mode'] = 'price_distance';
            $result['changes']['trailing_price_distance_pct'] = $distancePct;
            $result['changes']['best_roi_seen'] = round(
                $this->calculateRoi($entryPrice, $trailingHighWatermark, $side), 4
            );
            // V3 enhanced trailing fields
            $result['changes']['trailing_reference_price'] = round($trailingHighWatermark, 8);
            $result['changes']['exchange_trailing_distance'] = round($trailingHighWatermark * $distancePct, 8);
            $result['changes']['theoretical_current_stop_price'] = round($trailingStopPrice, 8);
            $result['changes']['current_effective_stop_price'] = round($trailingStopPrice, 8);
            $result['changes']['trailing_active'] = true;
            $result['changes']['stop_moved_from_initial'] = $prevTrailingStop > 0.0 && $trailingStopPrice !== $prevTrailingStop;

            // Check if triggered
            if ($currentPrice <= $trailingStopPrice) {
                $result['triggered'] = true;
                $result['close_reason'] = 'closed_by_trailing';
                $result['changes']['trailing_triggered_at'] = date('c');
                $result['changes']['trailing_triggered_price'] = $currentPrice;
            }

        } else {
            // Track low watermark
            $trailingLowWatermark = (float)($trade['trailing_low_watermark'] ?? $currentPrice);
            if ($currentPrice < $trailingLowWatermark) {
                $result['updated'] = true;
                $result['changes']['trailing_low_watermark'] = $currentPrice;
                $trailingLowWatermark = $currentPrice;
            }

            // Price-distance stop from best price
            $candidateStop = $trailingLowWatermark * (1.0 + $distancePct);

            // Monotonic: stop can only move down for short
            if ($prevTrailingStop > 0.0) {
                $trailingStopPrice = min($candidateStop, $prevTrailingStop);
            } else {
                $trailingStopPrice = $candidateStop;
            }

            $result['changes']['trailing_stop_price'] = round($trailingStopPrice, 8);
            $result['changes']['trailing_mode'] = 'price_distance';
            $result['changes']['trailing_price_distance_pct'] = $distancePct;
            $result['changes']['best_roi_seen'] = round(
                $this->calculateRoi($entryPrice, $trailingLowWatermark, $side), 4
            );
            // V3 enhanced trailing fields
            $result['changes']['trailing_reference_price'] = round($trailingLowWatermark, 8);
            $result['changes']['exchange_trailing_distance'] = round($trailingLowWatermark * $distancePct, 8);
            $result['changes']['theoretical_current_stop_price'] = round($trailingStopPrice, 8);
            $result['changes']['current_effective_stop_price'] = round($trailingStopPrice, 8);
            $result['changes']['trailing_active'] = true;
            $result['changes']['stop_moved_from_initial'] = $prevTrailingStop > 0.0 && $trailingStopPrice !== $prevTrailingStop;

            // Check if triggered
            if ($currentPrice >= $trailingStopPrice) {
                $result['triggered'] = true;
                $result['close_reason'] = 'closed_by_trailing';
                $result['changes']['trailing_triggered_at'] = date('c');
                $result['changes']['trailing_triggered_price'] = $currentPrice;
            }
        }

        return $result;
    }

    /**
     * Price-distance-floor trailing mode.
     *
     * Two-stage behavior:
     *   Stage 1 — Activation: trailing becomes active once ROI >= trailing_activation_floor_roi.
     *             At that point, a minimum profit floor is locked (trailing_floor_lock_roi).
     *   Stage 2 — Follow: stop trails best price at fixed trailing_price_distance_pct distance,
     *             but never drops below floor_stop_price, and never moves backward (monotonic).
     *
     * Step corridor: trailing reference updates only after meaningful progress
     *   (step_mode = fixed: uses step_pct_min; auto_strength: computes from move context).
     */
    private function checkPriceDistanceFloorTrailing(
        array $trade,
        float $currentPrice,
        array $result,
        array $trailing,
        string $side,
        float $entryPrice
    ): array {
        // Dispatch to ROI Ladder mode if configured
        $stepMode = (string)($trailing['trailing_step_mode'] ?? 'fixed');
        if ($stepMode === 'fixed_roi_ladder') {
            return $this->checkRoiLadderTrailing($trade, $currentPrice, $result, $trailing, $side, $entryPrice);
        }
        if ($stepMode === 'trend_reversal_soft_ladder_short') {
            return $this->checkReversalSoftLadderTrailing($trade, $currentPrice, $result, $trailing, $side, $entryPrice);
        }

        $leverage        = (int)($trailing['leverage'] ?? (int)($trade['risk']['leverage'] ?? 1));
        if ($leverage < 1) { $leverage = 1; }

        // ROI-based distance: convert distance_roi to price_distance_pct using leverage
        $distanceRoi     = isset($trailing['trailing_distance_roi']) ? (float)$trailing['trailing_distance_roi'] : null;
        $presetMode      = (string)($trailing['trailing_preset_mode'] ?? 'custom');
        if ($distanceRoi !== null && $distanceRoi > 0) {
            $distancePct = $distanceRoi / $leverage / 100;
        } else {
            $distancePct = (float)($trailing['trailing_price_distance_pct'] ?? 0.02);
        }

        $floorLockRoi    = (float)($trailing['trailing_floor_lock_roi'] ?? 3.0);
        $stepMode        = (string)($trailing['trailing_step_mode'] ?? 'fixed');
        $stepPctMin      = (float)($trailing['trailing_step_pct_min'] ?? 0.005);
        $stepPctMax      = (float)($trailing['trailing_step_pct_max'] ?? 0.02);

        // Retrieve persisted state
        $prevTrailingStop = (float)($trade['trailing_stop_price'] ?? 0.0);
        $floorLockActive  = (bool)($trade['floor_lock_active'] ?? false);

        // Compute floor stop price from entry + floor ROI
        // floorLockRoi is in percent (3.0 = 3%), price move = floorLockRoi / 100 / leverage
        $floorPriceMove = ($floorLockRoi / 100.0) / $leverage;
        if ($side === 'long') {
            $floorStopPrice = $entryPrice * (1.0 + $floorPriceMove);
        } else {
            $floorStopPrice = $entryPrice * (1.0 - $floorPriceMove);
        }

        // Compute current active step threshold
        $activeStep = $this->computeActiveStep($stepMode, $stepPctMin, $stepPctMax, $trade, $currentPrice, $entryPrice, $side);

        if ($side === 'long') {
            // Track high watermark
            $trailingHighWatermark = (float)($trade['trailing_high_watermark'] ?? $currentPrice);
            $trailingRefPrice      = (float)($trade['trailing_reference_price'] ?? $trailingHighWatermark);

            if ($currentPrice > $trailingHighWatermark) {
                $result['updated'] = true;
                $result['changes']['trailing_high_watermark'] = $currentPrice;
                $trailingHighWatermark = $currentPrice;
            }

            // Step corridor: only advance reference if price moved enough from last reference
            $moveSinceRef = ($trailingHighWatermark > 0 && $trailingRefPrice > 0)
                ? ($trailingHighWatermark - $trailingRefPrice) / $trailingRefPrice
                : 0.0;
            if ($moveSinceRef >= $activeStep || $trailingRefPrice <= 0) {
                $trailingRefPrice = $trailingHighWatermark;
                $result['changes']['trailing_reference_price'] = $trailingRefPrice;
            }

            // Price-distance candidate from reference price
            $candidateDistStop = $trailingRefPrice * (1.0 - $distancePct);

            // Floor enforcement: stop can never be below floor
            $candidateStop = max($candidateDistStop, $floorStopPrice);

            // Monotonic: stop can only move up
            $trailingStopPrice = max($candidateStop, $prevTrailingStop);
            $floorLockActive = true;

            // Populate result
            $result['changes']['trailing_stop_price']              = round($trailingStopPrice, 8);
            $result['changes']['trailing_mode']                     = 'price_distance_floor';
            $result['changes']['trailing_price_distance_pct']       = $distancePct;
            $result['changes']['trailing_distance_roi']             = $distanceRoi;
            $result['changes']['trailing_preset_mode']              = $presetMode;
            $result['changes']['floor_lock_active']                 = true;
            $result['changes']['floor_locked_roi']                  = $floorLockRoi;
            $result['changes']['floor_stop_price']                  = round($floorStopPrice, 8);
            $result['changes']['trailing_reference_price']          = round($trailingRefPrice, 8);
            $result['changes']['exchange_trailing_distance']        = round($trailingRefPrice * $distancePct, 8);
            $result['changes']['theoretical_current_stop_price']    = round($trailingStopPrice, 8);
            $result['changes']['current_effective_stop_price']      = round($trailingStopPrice, 8);
            $result['changes']['protection_source_of_truth']        = 'bot_trailing_engine';
            $result['changes']['trailing_active']                   = true;
            $result['changes']['stop_moved_from_initial']           = $prevTrailingStop > 0.0 && $trailingStopPrice !== $prevTrailingStop;
            $result['changes']['trailing_step_mode']                = $stepMode;
            $result['changes']['trailing_active_step']              = round($activeStep, 6);
            $result['changes']['best_roi_seen']                     = round(
                $this->calculateRoi($entryPrice, $trailingHighWatermark, $side), 4
            );

            // Check if triggered
            if ($currentPrice <= $trailingStopPrice) {
                $result['triggered'] = true;
                $result['close_reason'] = 'closed_by_trailing';
                $result['changes']['trailing_triggered_at'] = date('c');
                $result['changes']['trailing_triggered_price'] = $currentPrice;
            }

        } else {
            // SHORT side
            $trailingLowWatermark = (float)($trade['trailing_low_watermark'] ?? $currentPrice);
            $trailingRefPrice     = (float)($trade['trailing_reference_price'] ?? $trailingLowWatermark);

            if ($currentPrice < $trailingLowWatermark) {
                $result['updated'] = true;
                $result['changes']['trailing_low_watermark'] = $currentPrice;
                $trailingLowWatermark = $currentPrice;
            }

            // Step corridor: only advance reference if price moved enough
            $moveSinceRef = ($trailingLowWatermark > 0 && $trailingRefPrice > 0)
                ? ($trailingRefPrice - $trailingLowWatermark) / $trailingRefPrice
                : 0.0;
            if ($moveSinceRef >= $activeStep || $trailingRefPrice <= 0) {
                $trailingRefPrice = $trailingLowWatermark;
                $result['changes']['trailing_reference_price'] = $trailingRefPrice;
            }

            // Price-distance candidate from reference price
            $candidateDistStop = $trailingRefPrice * (1.0 + $distancePct);

            // Floor enforcement: stop can never be above floor (for short, floor is lower)
            $candidateStop = min($candidateDistStop, $floorStopPrice);

            // Monotonic: stop can only move down for short
            if ($prevTrailingStop > 0.0) {
                $trailingStopPrice = min($candidateStop, $prevTrailingStop);
            } else {
                $trailingStopPrice = $candidateStop;
            }
            $floorLockActive = true;

            // Populate result
            $result['changes']['trailing_stop_price']              = round($trailingStopPrice, 8);
            $result['changes']['trailing_mode']                     = 'price_distance_floor';
            $result['changes']['trailing_price_distance_pct']       = $distancePct;
            $result['changes']['trailing_distance_roi']             = $distanceRoi;
            $result['changes']['trailing_preset_mode']              = $presetMode;
            $result['changes']['floor_lock_active']                 = true;
            $result['changes']['floor_locked_roi']                  = $floorLockRoi;
            $result['changes']['floor_stop_price']                  = round($floorStopPrice, 8);
            $result['changes']['trailing_reference_price']          = round($trailingRefPrice, 8);
            $result['changes']['exchange_trailing_distance']        = round($trailingRefPrice * $distancePct, 8);
            $result['changes']['theoretical_current_stop_price']    = round($trailingStopPrice, 8);
            $result['changes']['current_effective_stop_price']      = round($trailingStopPrice, 8);
            $result['changes']['protection_source_of_truth']        = 'bot_trailing_engine';
            $result['changes']['trailing_active']                   = true;
            $result['changes']['stop_moved_from_initial']           = $prevTrailingStop > 0.0 && $trailingStopPrice !== $prevTrailingStop;
            $result['changes']['trailing_step_mode']                = $stepMode;
            $result['changes']['trailing_active_step']              = round($activeStep, 6);
            $result['changes']['best_roi_seen']                     = round(
                $this->calculateRoi($entryPrice, $trailingLowWatermark, $side), 4
            );

            // Check if triggered
            if ($currentPrice >= $trailingStopPrice) {
                $result['triggered'] = true;
                $result['close_reason'] = 'closed_by_trailing';
                $result['changes']['trailing_triggered_at'] = date('c');
                $result['changes']['trailing_triggered_price'] = $currentPrice;
            }
        }

        return $result;
    }

    /**
     * ROI Ladder trailing mode (fixed_roi_ladder step mode within price_distance_floor).
     *
     * Locked ROI grows in discrete steps from the floor_lock base, driven by peak ROI.
     * Peak ROI is monotonic — it only increases, ensuring protection never weakens.
     *
     * Formula:
     *   if peak_roi < activation_roi:
     *     locked_roi = floor_lock_roi
     *   else:
     *     locked_roi = floor_lock_roi + floor((peak_roi - activation_roi) / step_roi) * step_roi
     *
     * Example (floor=2, activation=3, step=1.5):
     *   peak 3.0 → locked 2.0
     *   peak 4.4 → locked 2.0   (floor((4.4-3)/1.5)=0)
     *   peak 4.5 → locked 3.5   (floor((4.5-3)/1.5)=1)
     *   peak 5.9 → locked 3.5
     *   peak 6.0 → locked 5.0   (floor((6.0-3)/1.5)=2)
     */
    private function checkRoiLadderTrailing(
        array $trade,
        float $currentPrice,
        array $result,
        array $trailing,
        string $side,
        float $entryPrice
    ): array {
        $leverage       = (int)($trailing['leverage'] ?? (int)($trade['risk']['leverage'] ?? 1));
        if ($leverage < 1) { $leverage = 1; }

        $floorLockRoi    = (float)($trailing['trailing_floor_lock_roi'] ?? 3.0);
        $activationRoi   = (float)($trailing['trailing_activation_floor_roi'] ?? $floorLockRoi);
        $stepRoi         = max(0.01, (float)($trailing['trailing_step_roi'] ?? 1.5));

        // Price-distance layer (secondary — used for visualization / distance-based candidate)
        $distanceRoi     = isset($trailing['trailing_distance_roi']) ? (float)$trailing['trailing_distance_roi'] : null;
        $presetMode      = (string)($trailing['trailing_preset_mode'] ?? 'custom');
        if ($distanceRoi !== null && $distanceRoi > 0) {
            $distancePct = $distanceRoi / $leverage / 100;
        } else {
            $distancePct = (float)($trailing['trailing_price_distance_pct'] ?? 0.02);
        }

        // Retrieve persisted state
        $prevTrailingStop  = (float)($trade['trailing_stop_price'] ?? 0.0);
        $prevPeakRoi       = (float)($trade['trailing_peak_roi'] ?? 0.0);
        $prevLockedRoi     = (float)($trade['trailing_locked_roi_current'] ?? $floorLockRoi);
        $prevStepCount     = (int)($trade['trailing_ladder_step_count'] ?? 0);

        if ($side === 'long') {
            // Track high watermark (price) for distance layer
            $trailingHighWatermark = (float)($trade['trailing_high_watermark'] ?? $currentPrice);
            if ($currentPrice > $trailingHighWatermark) {
                $result['updated'] = true;
                $result['changes']['trailing_high_watermark'] = $currentPrice;
                $trailingHighWatermark = $currentPrice;
            }

            // Compute current raw price ROI (same unit as activation/floor values)
            $currentRoi = $this->calculateRoi($entryPrice, $currentPrice, $side);

            // Update monotonic peak ROI
            $peakRoi = max($prevPeakRoi, $currentRoi);
            if ($peakRoi > $prevPeakRoi) {
                $result['updated'] = true;
                $result['changes']['trailing_peak_roi'] = round($peakRoi, 4);
            }

            // Compute ladder locked ROI
            if ($peakRoi < $activationRoi || $stepRoi <= 0) {
                $lockedRoi = $floorLockRoi;
                $stepCount = 0;
            } else {
                $stepsEarned = (int)floor(($peakRoi - $activationRoi) / $stepRoi);
                $lockedRoi   = $floorLockRoi + $stepsEarned * $stepRoi;
                $stepCount   = $stepsEarned;
            }

            // Monotonic: locked ROI can only increase
            $lockedRoi = max($lockedRoi, $prevLockedRoi);
            $stepCount = max($stepCount, $prevStepCount);

            // Convert locked ROI to floor stop price
            $lockedPriceMove = ($lockedRoi / 100.0) / $leverage;
            $ladderStopPrice = $entryPrice * (1.0 + $lockedPriceMove);

            // Distance-layer candidate (secondary — for exchange visualization)
            $distCandidateStop = $trailingHighWatermark * (1.0 - $distancePct);

            // Canonical stop = stronger of ladder stop and distance candidate, monotonic
            $candidateStop     = max($ladderStopPrice, $distCandidateStop);
            $trailingStopPrice = max($candidateStop, $prevTrailingStop);

            // Next step target ROI (diagnostic)
            $nextStepTargetRoi = $activationRoi + ($stepCount + 1) * $stepRoi;

            // Populate result
            $result['changes']['trailing_stop_price']           = round($trailingStopPrice, 8);
            $result['changes']['trailing_mode']                  = 'price_distance_floor';
            $result['changes']['trailing_step_mode']             = 'fixed_roi_ladder';
            $result['changes']['trailing_price_distance_pct']    = $distancePct;
            $result['changes']['trailing_distance_roi']          = $distanceRoi;
            $result['changes']['trailing_preset_mode']           = $presetMode;
            $result['changes']['floor_lock_active']              = true;
            $result['changes']['floor_locked_roi']               = $floorLockRoi;
            $result['changes']['floor_stop_price']               = round($ladderStopPrice, 8);
            $result['changes']['trailing_reference_price']       = round($trailingHighWatermark, 8);
            $result['changes']['exchange_trailing_distance']     = round($trailingHighWatermark * $distancePct, 8);
            $result['changes']['theoretical_current_stop_price'] = round($trailingStopPrice, 8);
            $result['changes']['current_effective_stop_price']   = round($trailingStopPrice, 8);
            $result['changes']['protection_source_of_truth']     = 'bot_trailing_engine_roi_ladder';
            $result['changes']['trailing_active']                = true;
            $result['changes']['stop_moved_from_initial']        = $prevTrailingStop > 0.0 && $trailingStopPrice !== $prevTrailingStop;
            $result['changes']['trailing_peak_roi']              = round($peakRoi, 4);
            $result['changes']['trailing_locked_roi_current']    = round($lockedRoi, 4);
            $result['changes']['trailing_locked_roi_previous']   = round($prevLockedRoi, 4);
            $result['changes']['trailing_ladder_step_count']     = $stepCount;
            $result['changes']['trailing_next_step_target_roi']  = round($nextStepTargetRoi, 4);
            $result['changes']['trailing_step_roi']              = $stepRoi;
            $result['changes']['best_roi_seen']                  = round($peakRoi, 4);

            // Check if triggered
            if ($currentPrice <= $trailingStopPrice) {
                $result['triggered']  = true;
                $result['close_reason'] = 'closed_by_trailing';
                $result['changes']['trailing_triggered_at']    = date('c');
                $result['changes']['trailing_triggered_price'] = $currentPrice;
            }

        } else {
            // SHORT side
            $trailingLowWatermark = (float)($trade['trailing_low_watermark'] ?? $currentPrice);
            if ($currentPrice < $trailingLowWatermark) {
                $result['updated'] = true;
                $result['changes']['trailing_low_watermark'] = $currentPrice;
                $trailingLowWatermark = $currentPrice;
            }

            // Compute current raw price ROI
            $currentRoi = $this->calculateRoi($entryPrice, $currentPrice, $side);

            // Update monotonic peak ROI
            $peakRoi = max($prevPeakRoi, $currentRoi);
            if ($peakRoi > $prevPeakRoi) {
                $result['updated'] = true;
                $result['changes']['trailing_peak_roi'] = round($peakRoi, 4);
            }

            // Compute ladder locked ROI
            if ($peakRoi < $activationRoi || $stepRoi <= 0) {
                $lockedRoi = $floorLockRoi;
                $stepCount = 0;
            } else {
                $stepsEarned = (int)floor(($peakRoi - $activationRoi) / $stepRoi);
                $lockedRoi   = $floorLockRoi + $stepsEarned * $stepRoi;
                $stepCount   = $stepsEarned;
            }

            // Monotonic: locked ROI can only increase
            $lockedRoi = max($lockedRoi, $prevLockedRoi);
            $stepCount = max($stepCount, $prevStepCount);

            // Convert locked ROI to floor stop price (short: stop is below entry)
            $lockedPriceMove = ($lockedRoi / 100.0) / $leverage;
            $ladderStopPrice = $entryPrice * (1.0 - $lockedPriceMove);

            // Distance-layer candidate (short: stop trails above low watermark)
            $distCandidateStop = $trailingLowWatermark * (1.0 + $distancePct);

            // Canonical stop = weaker (higher) for short is less protective; use min
            if ($prevTrailingStop > 0.0) {
                $candidateStop     = min($distCandidateStop, $ladderStopPrice);
                $trailingStopPrice = min($candidateStop, $prevTrailingStop);
            } else {
                $trailingStopPrice = min($distCandidateStop, $ladderStopPrice);
            }

            // Next step target ROI (diagnostic)
            $nextStepTargetRoi = $activationRoi + ($stepCount + 1) * $stepRoi;

            // Populate result
            $result['changes']['trailing_stop_price']           = round($trailingStopPrice, 8);
            $result['changes']['trailing_mode']                  = 'price_distance_floor';
            $result['changes']['trailing_step_mode']             = 'fixed_roi_ladder';
            $result['changes']['trailing_price_distance_pct']    = $distancePct;
            $result['changes']['trailing_distance_roi']          = $distanceRoi;
            $result['changes']['trailing_preset_mode']           = $presetMode;
            $result['changes']['floor_lock_active']              = true;
            $result['changes']['floor_locked_roi']               = $floorLockRoi;
            $result['changes']['floor_stop_price']               = round($ladderStopPrice, 8);
            $result['changes']['trailing_reference_price']       = round($trailingLowWatermark, 8);
            $result['changes']['exchange_trailing_distance']     = round($trailingLowWatermark * $distancePct, 8);
            $result['changes']['theoretical_current_stop_price'] = round($trailingStopPrice, 8);
            $result['changes']['current_effective_stop_price']   = round($trailingStopPrice, 8);
            $result['changes']['protection_source_of_truth']     = 'bot_trailing_engine_roi_ladder';
            $result['changes']['trailing_active']                = true;
            $result['changes']['stop_moved_from_initial']        = $prevTrailingStop > 0.0 && $trailingStopPrice !== $prevTrailingStop;
            $result['changes']['trailing_peak_roi']              = round($peakRoi, 4);
            $result['changes']['trailing_locked_roi_current']    = round($lockedRoi, 4);
            $result['changes']['trailing_locked_roi_previous']   = round($prevLockedRoi, 4);
            $result['changes']['trailing_ladder_step_count']     = $stepCount;
            $result['changes']['trailing_next_step_target_roi']  = round($nextStepTargetRoi, 4);
            $result['changes']['trailing_step_roi']              = $stepRoi;
            $result['changes']['best_roi_seen']                  = round($peakRoi, 4);

            // Check if triggered
            if ($currentPrice >= $trailingStopPrice) {
                $result['triggered']  = true;
                $result['close_reason'] = 'closed_by_trailing';
                $result['changes']['trailing_triggered_at']    = date('c');
                $result['changes']['trailing_triggered_price'] = $currentPrice;
            }
        }

        return $result;
    }

    /**
     * Trend-Reversal Soft Ladder trailing mode (TEST MODE).
     *
     * Short V2/V3 only. SHORT-ONLY — no long reversal signal required.
     * Two-stage profit protection (short_two_stage_peak_roi):
     *
     *   Stage 0 (peak < 5):
     *     No overlay lock. No aggressive distance trailing. Original exchange SL applies.
     *     trade['reversal_overlay_active'] is false; this method makes no stop change.
     *
     *   Stage 1 mini-ladder (5 <= peak < 10):
     *     Guaranteed floor lock that grows in steps. Distance-based trailing NOT active.
     *     trade['reversal_overlay_active'] = true.
     *       5 <= peak <  7  → locked ROI = 2
     *       7 <= peak <  9  → locked ROI = 3
     *       9 <= peak < 10  → locked ROI = 4
     *
     *   Stage 2 (peak >= 10):
     *     Existing soft ladder activates. Locked ROI follows:
     *       stage2_locked_roi = 5 + floor((peak_roi - 10) / 3) * 1
     *     Distance-based trailing candidate also becomes active (tighter trailing).
     *
     *   Final locked ROI = max(stage1_locked_roi, stage2_locked_roi, prev_locked_roi)
     *   Protection is monotonic — locked ROI never decreases.
     *
     * The overlay is signalled by trade['reversal_overlay_active'] being true — this flag is
     * set by bot_executor_trait.php before checkTrailing() is called.
     */
    private function checkReversalSoftLadderTrailing(
        array $trade,
        float $currentPrice,
        array $result,
        array $trailing,
        string $side,
        float $entryPrice
    ): array {
        $leverage = (int)($trailing['leverage'] ?? (int)($trade['risk']['leverage'] ?? 1));
        if ($leverage < 1) { $leverage = 1; }

        // Stage constants
        $stage1ActivationPeak  = BotReversalSignalHelper::STAGE1_ACTIVATION_PEAK_ROI;
        $stage1FloorLock       = BotReversalSignalHelper::STAGE1_FLOOR_LOCK_ROI;
        $stage2ActivationPeak  = BotReversalSignalHelper::OVERLAY_ACTIVATION_PEAK_ROI;
        $overlayBaseLock       = BotReversalSignalHelper::OVERLAY_BASE_LOCK_ROI;
        $overlayMainStep       = BotReversalSignalHelper::OVERLAY_MAIN_STEP_ROI;
        $overlayLockStep       = BotReversalSignalHelper::OVERLAY_LOCK_STEP_ROI;

        // Floor / distance parameters
        $distanceRoi  = isset($trailing['trailing_distance_roi']) ? (float)$trailing['trailing_distance_roi'] : null;
        $presetMode   = (string)($trailing['trailing_preset_mode'] ?? 'custom');
        if ($distanceRoi !== null && $distanceRoi > 0) {
            $distancePct = $distanceRoi / $leverage / 100;
        } else {
            $distancePct = (float)($trailing['trailing_price_distance_pct'] ?? 0.02);
        }

        // Retrieve persisted overlay state
        $prevTrailingStop        = (float)($trade['trailing_stop_price'] ?? 0.0);
        $prevPeakRoi             = (float)($trade['reversal_overlay_peak_roi'] ?? 0.0);
        $prevOverlayLockedRoi    = (float)($trade['reversal_overlay_locked_roi_current'] ?? 0.0);
        $prevStepCount           = (int)($trade['reversal_overlay_step_count'] ?? 0);
        $overlayActive           = (bool)($trade['reversal_overlay_active'] ?? false);

        // Only SHORT is supported for this mode
        if ($side !== 'short') {
            $result['changes']['reversal_overlay_active'] = false;
            $result['changes']['reversal_overlay_skip_reason'] = 'mode_not_applicable_to_long';
            $fallbackFloorLockRoi = (float)($trailing['trailing_floor_lock_roi'] ?? 3.0);
            return $this->checkPriceDistanceFloorTrailingFallback(
                $trade, $currentPrice, $result, $trailing, $side, $entryPrice, $fallbackFloorLockRoi, $distancePct, $distanceRoi, $presetMode, $leverage
            );
        }

        // Track short watermark
        $trailingLowWatermark = (float)($trade['trailing_low_watermark'] ?? $currentPrice);
        if ($currentPrice < $trailingLowWatermark) {
            $result['updated'] = true;
            $result['changes']['trailing_low_watermark'] = $currentPrice;
            $trailingLowWatermark = $currentPrice;
        }

        // Compute current ROI
        $currentRoi = $this->calculateRoi($entryPrice, $currentPrice, $side);

        // Update monotonic peak ROI
        $peakRoi = max($prevPeakRoi, $currentRoi);
        if ($peakRoi > $prevPeakRoi) {
            $result['updated'] = true;
            $result['changes']['reversal_overlay_peak_roi'] = round($peakRoi, 4);
        }

        // Determine stages from peak ROI
        $stage1Active = ($peakRoi >= $stage1ActivationPeak);
        $stage2Active = ($overlayActive && $peakRoi >= $stage2ActivationPeak);

        // Stage 0: no overlay active — do not apply any overlay trailing stop
        if (!$stage1Active) {
            $result['changes']['reversal_overlay_active']      = false;
            $result['changes']['reversal_overlay_stage1_active'] = false;
            $result['changes']['reversal_overlay_stage2_active'] = false;
            $result['changes']['reversal_overlay_peak_roi']    = round($peakRoi, 4);
            $result['changes']['reversal_overlay_skip_reason'] = 'peak_below_stage1';
            $result['changes']['reversal_overlay_next_step_target_roi'] = $stage1ActivationPeak;
            $result['changes']['best_roi_seen']                = round($peakRoi, 4);
            $result['changes']['trailing_step_mode']           = 'trend_reversal_soft_ladder_short';
            // No trailing stop update — original exchange SL remains
            return $result;
        }

        // Stage 1 or Stage 2 — compute locked ROIs
        $stage1LockedRoi = BotReversalSignalHelper::computeStage1LockedRoi($peakRoi); // mini-ladder: 2/3/4
        $stage2LockedRoi = 0.0;
        $stepCount       = 0;
        if ($stage2Active) {
            $stepsEarned  = (int)floor(($peakRoi - $stage2ActivationPeak) / $overlayMainStep);
            $stage2LockedRoi = $overlayBaseLock + (float)$stepsEarned * $overlayLockStep;
            $stepCount    = $stepsEarned;
        }

        // Final effective locked ROI = max of stages and previous (monotonic)
        $effectiveLockedRoi = max($stage1LockedRoi, $stage2LockedRoi, $prevOverlayLockedRoi);
        $stepCount          = max($stepCount, $prevStepCount);

        // Convert effective locked ROI to floor stop price
        $lockedPriceMove = ($effectiveLockedRoi / 100.0) / $leverage;
        $ladderStopPrice = $entryPrice * (1.0 - $lockedPriceMove);

        // In Stage 2 only: also compute distance-based trailing candidate
        if ($stage2Active) {
            $distCandidateStop = $trailingLowWatermark * (1.0 + $distancePct);
            // Most restrictive (lowest for short) of distance and ladder
            $candidateStop = min($distCandidateStop, $ladderStopPrice);
            if ($prevTrailingStop > 0.0) {
                $trailingStopPrice = min($candidateStop, $prevTrailingStop);
            } else {
                $trailingStopPrice = $candidateStop;
            }
        } else {
            // Stage 1: floor lock only — no distance trailing
            $trailingStopPrice = $ladderStopPrice;
            // Monotonic: stop can only tighten (move lower for short)
            if ($prevTrailingStop > 0.0) {
                $trailingStopPrice = min($trailingStopPrice, $prevTrailingStop);
            }
        }

        // Next step target ROI (diagnostics)
        $nextStepTargetRoi = BotReversalSignalHelper::computeNextStepTargetRoi($peakRoi);

        // Populate result changes
        $result['changes']['trailing_stop_price']                       = round($trailingStopPrice, 8);
        $result['changes']['trailing_mode']                              = 'price_distance_floor';
        $result['changes']['trailing_step_mode']                         = 'trend_reversal_soft_ladder_short';
        $result['changes']['trailing_price_distance_pct']                = $distancePct;
        $result['changes']['trailing_distance_roi']                      = $distanceRoi;
        $result['changes']['trailing_preset_mode']                       = $presetMode;
        $result['changes']['floor_lock_active']                          = true;
        $result['changes']['floor_locked_roi']                           = $effectiveLockedRoi;
        $result['changes']['floor_stop_price']                           = round($ladderStopPrice, 8);
        $result['changes']['trailing_reference_price']                   = round($trailingLowWatermark, 8);
        $result['changes']['theoretical_current_stop_price']             = round($trailingStopPrice, 8);
        $result['changes']['current_effective_stop_price']               = round($trailingStopPrice, 8);
        $result['changes']['protection_source_of_truth']                 = 'bot_trailing_engine_reversal_soft_ladder';
        $result['changes']['trailing_active']                            = true;
        $result['changes']['stop_moved_from_initial']                    = $prevTrailingStop > 0.0 && $trailingStopPrice !== $prevTrailingStop;
        $result['changes']['best_roi_seen']                              = round($peakRoi, 4);

        // Reversal overlay specific fields
        $result['changes']['reversal_overlay_active']                    = $overlayActive;
        $result['changes']['reversal_overlay_stage1_active']             = $stage1Active;
        $result['changes']['reversal_overlay_stage2_active']             = $stage2Active;
        $result['changes']['reversal_overlay_peak_roi']                  = round($peakRoi, 4);
        $result['changes']['reversal_overlay_locked_roi_current']        = round($effectiveLockedRoi, 4);
        $result['changes']['reversal_overlay_step_count']                = $stepCount;
        $result['changes']['reversal_overlay_next_step_target_roi']      = round($nextStepTargetRoi, 4);
        $result['changes']['reversal_overlay_stage1_peak_roi']           = $stage1ActivationPeak;
        $result['changes']['reversal_overlay_stage1_lock_roi']           = $stage1LockedRoi;
        $result['changes']['reversal_overlay_base_lock_roi']             = $overlayBaseLock;
        $result['changes']['reversal_overlay_main_step_roi']             = $overlayMainStep;
        $result['changes']['reversal_overlay_lock_step_roi']             = $overlayLockStep;
        $result['changes']['reversal_overlay_activation_peak_roi']       = $stage2ActivationPeak;

        // Check if triggered (short: close when price rises to or above stop)
        if ($currentPrice >= $trailingStopPrice) {
            $result['triggered']  = true;
            $result['close_reason'] = 'closed_by_trailing';
            $result['changes']['trailing_triggered_at']    = date('c');
            $result['changes']['trailing_triggered_price'] = $currentPrice;
        }

        return $result;
    }

    /**
     * Internal fallback: standard short-side price_distance_floor logic.
     *
     * Used when trend_reversal_soft_ladder_short is configured but conditions
     * are not met (e.g. non-short position). Ensures protection is never dropped.
     */
    private function checkPriceDistanceFloorTrailingFallback(
        array $trade,
        float $currentPrice,
        array $result,
        array $trailing,
        string $side,
        float $entryPrice,
        float $floorLockRoi,
        float $distancePct,
        ?float $distanceRoi,
        string $presetMode,
        int $leverage
    ): array {
        $prevTrailingStop = (float)($trade['trailing_stop_price'] ?? 0.0);
        $stepMode  = (string)($trailing['trailing_step_mode'] ?? 'fixed');
        $stepPctMin = (float)($trailing['trailing_step_pct_min'] ?? 0.005);
        $stepPctMax = (float)($trailing['trailing_step_pct_max'] ?? 0.02);

        $floorPriceMove = ($floorLockRoi / 100.0) / $leverage;
        if ($side === 'long') {
            $floorStopPrice = $entryPrice * (1.0 + $floorPriceMove);
        } else {
            $floorStopPrice = $entryPrice * (1.0 - $floorPriceMove);
        }

        $activeStep = $this->computeActiveStep($stepMode, $stepPctMin, $stepPctMax, $trade, $currentPrice, $entryPrice, $side);

        if ($side === 'long') {
            $hwm = (float)($trade['trailing_high_watermark'] ?? $currentPrice);
            if ($currentPrice > $hwm) {
                $hwm = $currentPrice;
                $result['changes']['trailing_high_watermark'] = $hwm;
                $result['updated'] = true;
            }
            $refPrice = (float)($trade['trailing_reference_price'] ?? $hwm);
            $moveSinceRef = ($hwm > 0 && $refPrice > 0) ? (($hwm - $refPrice) / $refPrice) : 0.0;
            if ($moveSinceRef >= $activeStep || $refPrice <= 0) {
                $refPrice = $hwm;
                $result['changes']['trailing_reference_price'] = $refPrice;
            }
            $candidateStop = max($refPrice * (1.0 - $distancePct), $floorStopPrice);
            $trailingStopPrice = max($candidateStop, $prevTrailingStop);
            $triggered = ($currentPrice <= $trailingStopPrice);
        } else {
            $lwm = (float)($trade['trailing_low_watermark'] ?? $currentPrice);
            if ($currentPrice < $lwm) {
                $lwm = $currentPrice;
                $result['changes']['trailing_low_watermark'] = $lwm;
                $result['updated'] = true;
            }
            $refPrice = (float)($trade['trailing_reference_price'] ?? $lwm);
            $moveSinceRef = ($lwm > 0 && $refPrice > 0) ? (($refPrice - $lwm) / $refPrice) : 0.0;
            if ($moveSinceRef >= $activeStep || $refPrice <= 0) {
                $refPrice = $lwm;
                $result['changes']['trailing_reference_price'] = $refPrice;
            }
            $candidateStop = min($refPrice * (1.0 + $distancePct), $floorStopPrice);
            $trailingStopPrice = ($prevTrailingStop > 0.0) ? min($candidateStop, $prevTrailingStop) : $candidateStop;
            $triggered = ($currentPrice >= $trailingStopPrice);
        }

        $result['changes']['trailing_stop_price']           = round($trailingStopPrice, 8);
        $result['changes']['trailing_mode']                  = 'price_distance_floor';
        $result['changes']['trailing_step_mode']             = $stepMode;
        $result['changes']['trailing_price_distance_pct']    = $distancePct;
        $result['changes']['trailing_distance_roi']          = $distanceRoi;
        $result['changes']['trailing_preset_mode']           = $presetMode;
        $result['changes']['floor_lock_active']              = true;
        $result['changes']['floor_locked_roi']               = $floorLockRoi;
        $result['changes']['floor_stop_price']               = round($floorStopPrice, 8);
        $result['changes']['current_effective_stop_price']   = round($trailingStopPrice, 8);
        $result['changes']['protection_source_of_truth']     = 'bot_trailing_engine_pdf_fallback';
        $result['changes']['trailing_active']                = true;
        $result['changes']['stop_moved_from_initial']        = $prevTrailingStop > 0.0 && $trailingStopPrice !== $prevTrailingStop;

        if ($triggered) {
            $result['triggered']  = true;
            $result['close_reason'] = 'closed_by_trailing';
            $result['changes']['trailing_triggered_at']    = date('c');
            $result['changes']['trailing_triggered_price'] = $currentPrice;
        }
        return $result;
    }

    /**
     * Compute active step threshold for trailing updates.
     *
     * Fixed mode: returns step_pct_min as the fixed threshold.
     * Auto-strength mode: estimates step from current move strength,
     *   clamped into [step_pct_min, step_pct_max].
     *
     * Does NOT use historical/ML data — only current trade context.
     */
    private function computeActiveStep(
        string $stepMode,
        float $stepPctMin,
        float $stepPctMax,
        array $trade,
        float $currentPrice,
        float $entryPrice,
        string $side
    ): float {
        if ($stepMode !== 'auto_strength') {
            // Fixed mode
            return max($stepPctMin, 0.001);
        }

        // Auto-strength: estimate step from current move persistence
        $roi = $this->calculateRoi($entryPrice, $currentPrice, $side);
        $bestRoi = (float)($trade['best_roi_seen'] ?? $roi);

        // Move strength proxy: how strong is the current favorable move
        // Larger ROI = stronger move = allow larger steps (less frequent updates)
        // Small ROI near activation = tighter steps (protect gains)
        $moveStrength = 0.0;
        if ($bestRoi > 0) {
            // Normalize: 0-10% ROI maps to 0-1 strength
            $moveStrength = min(1.0, max(0.0, $bestRoi / 10.0));
        }

        // Interpolate step within corridor based on strength
        $step = $stepPctMin + ($stepPctMax - $stepPctMin) * $moveStrength;

        // Clamp
        return max($stepPctMin, min($stepPctMax, $step));
    }

    /**
     * ROI-giveback trailing mode — peak-based buffered runner protection.
     *
     * Converted from noise-sensitive immediate lock to a buffered peak-based model:
     *
     *   1. Activation only ARMS trailing; stop is not placed near current price.
     *   2. Stop is computed from PEAK ROI (monotonic watermark), not current noisy ROI.
     *   3. First-lock floor (min_lock_roi ratio) ensures stop is never trivially low.
     *   4. Minimum gap buffer (min_step ratio) keeps stop at least min_step away from
     *      current price, preventing normal noise from instantly closing the trade.
     *      If applying the gap would push the stop below entry, it is floored at entry.
     *   5. Monotonic: stop can only tighten (up for long, down for short).
     *
     * Parameters consumed from risk.trailing:
     *   drawdown_factor  — giveback ratio (0.5 = 50% of peak profit locked)
     *   min_lock_roi     — price ratio for first-lock floor above/below entry (0.012 = 1.2%)
     *   min_step         — price ratio for minimum gap from current price (0.01 = 1%)
     */
    private function checkRoiGivebackTrailing(
        array $trade,
        float $currentPrice,
        array $result,
        array $trailing,
        string $side,
        float $entryPrice
    ): array {
        // Documented engine default: 0.5 (normal mode)
        $drawdownFactor = (float)($trailing['drawdown_factor'] ?? 0.5);

        // First-lock floor: minimum stop above/below entry once trailing is armed.
        // min_lock_roi is a price ratio (0.012 = 1.2% of entry). Default 0 = at entry.
        $minLockRoi = max(0.0, (float)($trailing['min_lock_roi'] ?? 0.0));

        // Minimum gap from current price: stop must be at least min_step away.
        // min_step is a price ratio (0.01 = 1%). Prevents noise-triggered closes.
        $minStep = max(0.0, (float)($trailing['min_step'] ?? 0.0));

        // Retrieve previous trailing state for monotonic protection.
        $prevTrailingStop = (float)($trade['trailing_stop_price'] ?? 0.0);
        $prevPeakRoi      = (float)($trade['trailing_peak_roi'] ?? 0.0);

        if ($side === 'long') {
            // Track peak high watermark (monotonic — only moves up).
            $hwm = (float)($trade['trailing_high_watermark'] ?? $currentPrice);
            if ($currentPrice > $hwm) {
                $hwm = $currentPrice;
                $result['updated'] = true;
                $result['changes']['trailing_high_watermark'] = $hwm;
            }

            // Compute peak ROI from watermark (raw price %).
            $peakRoi = ($entryPrice > 0) ? (($hwm - $entryPrice) / $entryPrice * 100) : 0.0;

            // Monotonic peak: never decreases.
            $peakRoi = max($peakRoi, $prevPeakRoi);
            if ($peakRoi > $prevPeakRoi) {
                $result['updated'] = true;
                $result['changes']['trailing_peak_roi'] = round($peakRoi, 4);
            }

            // Stop from peak with drawdown giveback (never negative).
            $stopRoiFromPeak  = max(0.0, $peakRoi * (1.0 - $drawdownFactor));
            $candidateStop    = $entryPrice * (1.0 + $stopRoiFromPeak / 100.0);

            // Apply first-lock floor: stop must be at least minLockRoi above entry.
            if ($minLockRoi > 0.0) {
                $firstLockPrice = $entryPrice * (1.0 + $minLockRoi);
                $candidateStop  = max($candidateStop, $firstLockPrice);
            }

            // Apply minimum gap buffer: stop must be at least minStep below current price.
            // If the safe ceiling would fall below entry, floor at entry (break-even protection).
            if ($minStep > 0.0 && $currentPrice > 0.0) {
                $maxSafeStop = $currentPrice * (1.0 - $minStep);
                $gapFloor    = ($maxSafeStop >= $entryPrice) ? $maxSafeStop : $entryPrice;
                $candidateStop = min($candidateStop, $gapFloor);
            }

            // Monotonic: stop can only move up for long.
            $trailingStopPrice = max($candidateStop, $prevTrailingStop);

            $result['changes']['trailing_stop_price']    = round($trailingStopPrice, 8);
            $result['changes']['trailing_mode']           = 'roi_giveback';
            $result['changes']['best_roi_seen']           = round($peakRoi, 4);
            $result['changes']['trailing_peak_roi']       = round($peakRoi, 4);
            $result['changes']['trailing_active']         = true;
            $result['changes']['stop_moved_from_initial'] = $prevTrailingStop > 0.0 && $trailingStopPrice !== $prevTrailingStop;

            if ($currentPrice <= $trailingStopPrice) {
                $result['triggered']  = true;
                $result['close_reason'] = 'closed_by_trailing';
                $result['changes']['trailing_triggered_at']    = date('c');
                $result['changes']['trailing_triggered_price'] = $currentPrice;
            }

        } else {
            // SHORT side.
            $lwm = (float)($trade['trailing_low_watermark'] ?? $currentPrice);
            if ($currentPrice < $lwm) {
                $lwm = $currentPrice;
                $result['updated'] = true;
                $result['changes']['trailing_low_watermark'] = $lwm;
            }

            // Compute peak ROI for short (monotonic).
            $peakRoi = ($entryPrice > 0) ? (($entryPrice - $lwm) / $entryPrice * 100) : 0.0;
            $peakRoi = max($peakRoi, $prevPeakRoi);
            if ($peakRoi > $prevPeakRoi) {
                $result['updated'] = true;
                $result['changes']['trailing_peak_roi'] = round($peakRoi, 4);
            }

            // Stop from peak with drawdown giveback.
            $stopRoiFromPeak  = max(0.0, $peakRoi * (1.0 - $drawdownFactor));
            $candidateStop    = $entryPrice * (1.0 - $stopRoiFromPeak / 100.0);

            // Apply first-lock floor (short: stop must be at least minLockRoi below entry).
            if ($minLockRoi > 0.0) {
                $firstLockPrice = $entryPrice * (1.0 - $minLockRoi);
                $candidateStop  = min($candidateStop, $firstLockPrice);
            }

            // Apply minimum gap buffer (short: stop must be at least minStep above current).
            if ($minStep > 0.0 && $currentPrice > 0.0) {
                $minSafeStop = $currentPrice * (1.0 + $minStep);
                $gapCeil     = ($minSafeStop <= $entryPrice) ? $minSafeStop : $entryPrice;
                $candidateStop = max($candidateStop, $gapCeil);
            }

            // Monotonic: stop can only move down for short.
            if ($prevTrailingStop > 0.0) {
                $trailingStopPrice = min($candidateStop, $prevTrailingStop);
            } else {
                $trailingStopPrice = $candidateStop;
            }

            $result['changes']['trailing_stop_price']    = round($trailingStopPrice, 8);
            $result['changes']['trailing_mode']           = 'roi_giveback';
            $result['changes']['best_roi_seen']           = round($peakRoi, 4);
            $result['changes']['trailing_peak_roi']       = round($peakRoi, 4);
            $result['changes']['trailing_active']         = true;
            $result['changes']['stop_moved_from_initial'] = $prevTrailingStop > 0.0 && $trailingStopPrice !== $prevTrailingStop;

            if ($currentPrice >= $trailingStopPrice) {
                $result['triggered']  = true;
                $result['close_reason'] = 'closed_by_trailing';
                $result['changes']['trailing_triggered_at']    = date('c');
                $result['changes']['trailing_triggered_price'] = $currentPrice;
            }
        }

        return $result;
    }
    
    /**
     * Check break-even conditions for trade
     * 
     * @param array $trade Trade data
     * @param float $currentRoiPct Current ROI in percent
     * @return array Break-even check result
     */
    public function checkBreakEven(array $trade, float $currentRoiPct): array
    {
        $result = [
            'should_apply' => false,
            'armed' => false,
            'reason' => null,
        ];
        
        $risk = $trade['risk'] ?? [];
        $trailing = $risk['trailing'] ?? [];
        $runtime = $trade['runtime'] ?? [];
        
        $beEnabled = (bool)($trailing['break_even_enabled'] ?? false);
        if (!$beEnabled) {
            return $result;
        }
        
        $beActivationRoi = (float)($trailing['break_even_activation_roi'] ?? 0);
        if ($beActivationRoi <= 0) {
            return $result;
        }
        
        // Already applied?
        if (!empty($runtime['break_even_applied'])) {
            $result['armed'] = true;
            $result['reason'] = 'already_applied';
            return $result;
        }
        
        // Arm when approaching threshold (50% of activation)
        if ($currentRoiPct >= $beActivationRoi * 0.5) {
            $result['armed'] = true;
        }
        
        // Apply when threshold is reached
        if ($currentRoiPct >= $beActivationRoi) {
            $result['should_apply'] = true;
            $result['armed'] = true;
            $result['reason'] = 'threshold_reached';
        }
        
        return $result;
    }
    
    /**
     * Calculate ROI percentage
     * 
     * @param float $entryPrice Entry price
     * @param float $currentPrice Current price
     * @param string $side Position side (long|short)
     * @return float ROI percentage
     */
    private function calculateRoi(float $entryPrice, float $currentPrice, string $side): float
    {
        if ($entryPrice <= 0) {
            return 0.0;
        }
        
        $diff = $currentPrice - $entryPrice;
        if ($side === 'short') {
            $diff = -$diff;
        }
        
        return ($diff / $entryPrice) * 100;
    }
    
    /**
     * Get trailing mode multiplier
     * 
     * @param string $mode Trailing mode (tight|normal|loose)
     * @return float Drawdown factor multiplier
     */
    public function getModeMultiplier(string $mode): float
    {
        $modes = [
            'tight' => 0.3,
            'normal' => 0.5,
            'loose' => 0.7,
        ];
        
        return $modes[$mode] ?? 0.5;
    }
}

/* RULES
- TrailingEngine handles trailing stop calculations and break-even checks
- Three trailing modes: roi_giveback (default), price_distance (fixed % from current price),
  price_distance_floor (activation floor + locked ROI + price distance follow + step corridor)
- Step modes for price_distance_floor: fixed, auto_strength, fixed_roi_ladder, trend_reversal_soft_ladder_short
- roi_giveback mode — peak-based buffered runner protection:
  activation only ARMS trailing; stop is derived from PEAK ROI (monotonic watermark).
  Parameters: drawdown_factor (giveback ratio), min_lock_roi (first-lock floor ratio, e.g. 0.012=1.2%),
  min_step (minimum gap from current price ratio, e.g. 0.01=1%).
  Formula: candidateStop = entry * (1 + peakRoi * (1 - drawdownFactor) / 100), then:
    floor at entry * (1 + min_lock_roi), cap at currentPrice * (1 - min_step) [floored at entry],
    monotonic: stop = max(candidateStop, prevTrailingStop).
  The min_step gap prevents noise from instantly triggering the stop at activation.
- fixed_roi_ladder: locked ROI grows in discrete ROI steps using peak ROI (monotonic);
  formula: locked = floor_lock + floor((peak_roi - activation_roi) / step_roi) * step_roi;
  peak_roi is tracked separately and never decreases; protection only strengthens.
- trend_reversal_soft_ladder_short: TEST MODE; SHORT V2/V3 only; two-stage profit protection (short_two_stage_peak_roi);
  stage0 (peak<5): no lock, no distance trailing, original exchange SL only;
  stage1 mini-ladder (peak>=5, <10): guaranteed floor lock grows in steps, no distance trailing:
    5<=peak<7 → lock=2, 7<=peak<9 → lock=3, 9<=peak<10 → lock=4;
  stage2 (peak>=10): soft ladder lock + distance trailing;
  overlay formula: locked = 5 + floor((peak_roi - 10) / 3) * 1 for peak >= 10;
  final_locked_roi = max(stage1_locked_roi, stage2_locked_roi, prev_locked_roi); monotonic protection.
- Phase-1: "Dumb" trailing - set once on exchange, don't track
- Trailing activation includes leverage in ROI calculation
- NO local price tracking - exchange handles trailing
- Break-even check is used by bot_executor_trait.php for SL→entry moves
- Close reasons: closed_by_trailing, closed_by_break_even, closed_by_logical_stop
- Unit system: activation_pct = percent (4.0 = 4%), drawdown_factor = ratio (0.5),
  trailing_price_distance_pct = ratio (0.02 = 2% from current price),
  trailing_activation_floor_roi = percent (4.0 = 4%), trailing_floor_lock_roi = percent (3.0 = 3%),
  trailing_step_roi = percent (1.5 = 1.5 ROI units),
  min_lock_roi = price ratio (0.012 = 1.2%), min_step = price ratio (0.01 = 1%)
- Monotonic rule: stop never moves backward (down for long, up for short) in any mode
- Floor mode: floor_stop_price guarantees minimum locked profit, step corridor controls update frequency
- ROI ladder mode: ladder stop derived from locked_roi, always >= floor_stop_price
*/
