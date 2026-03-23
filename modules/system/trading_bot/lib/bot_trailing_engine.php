<?php
declare(strict_types=1);

namespace Modules\System\TradingBot\Lib;

/**
 * Bot Trailing Engine
 * 
 * Trailing stop logic for Trading Bot.
 * Uses trailing parameters from the normalized risk.trailing block.
 *
 * Supports two trailing modes:
 *   - roi_giveback (default): trailing distance = max_profit * drawdown_factor
 *   - price_distance: stop follows current best price at fixed pct distance
 *     LONG:  stop = best_price * (1 - trailing_price_distance_pct)
 *     SHORT: stop = best_price * (1 + trailing_price_distance_pct)
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
 *   trailing_mode              — 'roi_giveback' | 'price_distance'
 *   drawdown_factor            — trailing distance multiplier (roi_giveback mode)
 *   trailing_price_distance_pct — fixed distance ratio (price_distance mode, 0.02 = 2%)
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
        $activationRoiPct = (float)($trailing['activation_roi_pct'] ?? 0);
        $trailingMode = (string)($trailing['trailing_mode'] ?? 'roi_giveback');
        
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
            $trailingActivated = true;
        }
        
        if (!$trailingActivated) {
            return $result;
        }
        
        // Dispatch to appropriate trailing mode
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
- Two trailing modes: roi_giveback (default), price_distance (fixed % from current price)
- Phase-1: "Dumb" trailing - set once on exchange, don't track
- Trailing activation includes leverage in ROI calculation
- NO local price tracking - exchange handles trailing
- Break-even check is used by bot_executor_trait.php for SL→entry moves
- Close reasons: closed_by_trailing, closed_by_break_even, closed_by_logical_stop
- Unit system: activation_roi_pct = percent (4.0 = 4%), drawdown_factor = ratio (0.5),
  trailing_price_distance_pct = ratio (0.02 = 2% from current price)
- Monotonic rule: stop never moves backward (down for long, up for short) in either mode
*/
