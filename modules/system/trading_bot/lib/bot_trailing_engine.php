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
 *   trailing_step_mode           — 'fixed' | 'auto_strength' step corridor mode
 *   trailing_step_pct_min        — minimum step size for trailing updates
 *   trailing_step_pct_max        — maximum step size for trailing updates
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
     * ROI-giveback trailing mode (original behavior).
     *
     * Trailing distance = max_profit * drawdown_factor.
     */
    private function checkRoiGivebackTrailing(
        array $trade,
        float $currentPrice,
        array $result,
        array $trailing,
        string $side,
        float $entryPrice
    ): array {
        // Documented engine default: 0.5 (normal mode) — used only if upstream
        // normalization did not provide an explicit drawdown_factor.
        $drawdownFactor = (float)($trailing['drawdown_factor'] ?? 0.5);

        // Update trailing stop level
        $trailingHighWatermark = $trade['trailing_high_watermark'] ?? $currentPrice;
        $trailingLowWatermark = $trade['trailing_low_watermark'] ?? $currentPrice;
        
        if ($side === 'long') {
            // For long: track high watermark, trigger on pullback
            if ($currentPrice > $trailingHighWatermark) {
                $result['updated'] = true;
                $result['changes']['trailing_high_watermark'] = $currentPrice;
                $trailingHighWatermark = $currentPrice;
            }
            
            // Calculate trailing stop level
            $maxProfit = ($trailingHighWatermark - $entryPrice) / $entryPrice * 100;
            $trailingStopRoi = $maxProfit * (1 - $drawdownFactor);
            $trailingStopPrice = $entryPrice * (1 + $trailingStopRoi / 100);
            
            $result['changes']['trailing_stop_price'] = $trailingStopPrice;
            $result['changes']['trailing_mode'] = 'roi_giveback';
            $result['changes']['best_roi_seen'] = round($maxProfit, 4);
            
            // Check if triggered
            if ($currentPrice <= $trailingStopPrice) {
                $result['triggered'] = true;
                $result['close_reason'] = 'closed_by_trailing';
                $result['changes']['trailing_triggered_at'] = date('c');
                $result['changes']['trailing_triggered_price'] = $currentPrice;
            }
            
        } else {
            // For short: track low watermark, trigger on rally
            if ($currentPrice < $trailingLowWatermark) {
                $result['updated'] = true;
                $result['changes']['trailing_low_watermark'] = $currentPrice;
                $trailingLowWatermark = $currentPrice;
            }
            
            // Calculate trailing stop level
            $maxProfit = ($entryPrice - $trailingLowWatermark) / $entryPrice * 100;
            $trailingStopRoi = $maxProfit * (1 - $drawdownFactor);
            $trailingStopPrice = $entryPrice * (1 - $trailingStopRoi / 100);
            
            $result['changes']['trailing_stop_price'] = $trailingStopPrice;
            $result['changes']['trailing_mode'] = 'roi_giveback';
            $result['changes']['best_roi_seen'] = round($maxProfit, 4);
            
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
- Phase-1: "Dumb" trailing - set once on exchange, don't track
- Trailing activation includes leverage in ROI calculation
- NO local price tracking - exchange handles trailing
- Break-even check is used by bot_executor_trait.php for SL→entry moves
- Close reasons: closed_by_trailing, closed_by_break_even, closed_by_logical_stop
- Unit system: activation_pct = percent (4.0 = 4%), drawdown_factor = ratio (0.5),
  trailing_price_distance_pct = ratio (0.02 = 2% from current price),
  trailing_activation_floor_roi = percent (4.0 = 4%), trailing_floor_lock_roi = percent (3.0 = 3%)
- Monotonic rule: stop never moves backward (down for long, up for short) in any mode
- Floor mode: floor_stop_price guarantees minimum locked profit, step corridor controls update frequency
*/
