<?php
declare(strict_types=1);

namespace Modules\System\TradingBot\Lib;

/**
 * Bot Trailing Engine
 * 
 * Trailing stop logic for Trading Bot.
 * Uses trailing parameters from the normalized risk.trailing block.
 *
 * In Brain-controlled mode, risk.trailing is populated by
 * normalizeBrainTrailingIntoRisk() in bot_sources_trait.php.
 * Bot-local trailing toggles (enable_trailing_on_open, dumb_trailing_enabled)
 * do NOT affect this engine — they are gating logic in bot_executor_trait.php
 * that is bypassed when Brain-controlled mode is active.
 *
 * Key fields consumed from risk.trailing:
 *   enabled            — whether trailing is active (Brain-owned in Brain mode)
 *   activation_roi_pct — ROI % threshold to activate trailing
 *   drawdown_factor    — trailing distance multiplier (NOT the same as trailing_min_step)
 *
 * drawdown_factor semantics:
 *   Trailing distance = price_move * drawdown_factor
 *   This is a giveback ratio: 0.5 means trail gives back 50% of the max profit move.
 *   Source is tracked via drawdown_factor_source in the trailing block:
 *     - brain_trailing_contract: from Brain intent trailing contract
 *     - risk_block: from Brain signal risk block
 *     - documented_default: engine default 0.5 (normal mode)
 *     - legacy_non_brain_mode: bot-local config in non-Brain mode
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
        // Documented engine default: 0.5 (normal mode) — used only if upstream
        // normalization did not provide an explicit drawdown_factor.
        // In Brain-controlled mode, normalizeBrainTrailingIntoRisk() always provides
        // this value with explicit source tracking via drawdown_factor_source.
        $drawdownFactor = (float)($trailing['drawdown_factor'] ?? 0.5);
        
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
            $trailingActivated = true;
        }
        
        if (!$trailingActivated) {
            return $result;
        }
        
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
- Phase-1: "Dumb" trailing - set once on exchange, don't track
- Trailing activation includes leverage in ROI calculation
- NO local price tracking - exchange handles trailing
- Break-even check is used by bot_executor_trait.php for SL→entry moves
- Close reasons: closed_by_trailing, closed_by_break_even, closed_by_logical_stop
- Unit system: activation_roi_pct = percent (4.0 = 4%), drawdown_factor = ratio (0.5)
*/
