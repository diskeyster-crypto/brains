<?php
declare(strict_types=1);

namespace Modules\System\TradingBot\Lib;

/**
 * Bot Risk Engine
 * 
 * Risk validation and position sizing.
 * Risk = ONLY from Brain (risk-block).
 * NO hardcoded defaults.
 * 
 * Phase-1 additions:
 * - calculateStopLossFromLiq(): SL from liquidation price
 * - calculateTrailingParams(): Trailing stop activation/distance with leverage
 */
class BotRiskEngine
{
    private array $config;
    
    public function __construct(array $config)
    {
        $this->config = $config;
    }
    
    /**
     * Validate risk block
     * 
     * P1.2 FIX: Enhanced validation with proper numeric checks.
     * - take_profit is optional (Phase-1 doesn't require TP)
     * - trailing params validated when enabled
     * 
     * @param array $risk Risk block from signal
     * @return array Validation result
     */
    public function validateRisk(array $risk): array
    {
        $result = [
            'valid' => true,
            'reason' => null,
            'missing_fields' => [],
        ];
        
        // Check required fields (P1.2: take_profit is optional in Phase-1)
        $requiredFields = $this->config['validation']['required_risk_fields'] ?? [
            'budget_usdt_per_trade',
            'leverage',
            'stop_from_liq_range_pct',
            'slippage_bps',
            'fees_bps',
            'order_type',
            'limits',
            // 'take_profit', // P1.2: Optional in Phase-1
            'trailing',
        ];
        
        foreach ($requiredFields as $field) {
            if (!isset($risk[$field])) {
                $result['missing_fields'][] = $field;
            }
        }
        
        if (!empty($result['missing_fields'])) {
            $result['valid'] = false;
            $result['reason'] = 'missing_field:' . implode(',', $result['missing_fields']);
            return $result;
        }
        
        // Validate order_type is present (NO fallback - strict contract!)
        if (!isset($risk['order_type']) || $risk['order_type'] === '') {
            $result['valid'] = false;
            $result['reason'] = 'missing_field:order_type';
            return $result;
        }
        
        // Validate order type (only market allowed in v1)
        $allowedTypes = $this->config['validation']['allowed_order_types'] ?? ['market'];
        $orderType = $risk['order_type'];
        
        if (!in_array($orderType, $allowedTypes, true)) {
            $result['valid'] = false;
            $result['reason'] = "order_type_not_allowed:{$orderType}";
            return $result;
        }
        
        // Validate limits block
        if (!is_array($risk['limits'] ?? null)) {
            $result['valid'] = false;
            $result['reason'] = 'missing_field:limits';
            return $result;
        }
        
        // P1.2: max_open_trades >= 0 (0 = unlimited)
        $maxOpenTrades = $risk['limits']['max_open_trades'] ?? null;
        if ($maxOpenTrades === null) {
            $result['missing_fields'][] = 'limits.max_open_trades';
        } elseif ($maxOpenTrades < 0) {
            $result['valid'] = false;
            $result['reason'] = 'invalid_max_open_trades:' . $maxOpenTrades;
            return $result;
        }
        
        if (!isset($risk['limits']['one_trade_per_symbol'])) {
            $result['missing_fields'][] = 'limits.one_trade_per_symbol';
        }
        
        // P1.2: take_profit is optional in Phase-1
        // Only validate if present
        if (isset($risk['take_profit']) && !is_array($risk['take_profit'])) {
            $result['valid'] = false;
            $result['reason'] = 'invalid_take_profit_format';
            return $result;
        }
        
        // Validate trailing block exists
        if (!is_array($risk['trailing'] ?? null)) {
            $result['valid'] = false;
            $result['reason'] = 'missing_field:trailing';
            return $result;
        }
        
        // P1.2: If trailing is enabled, validate its params.
        // In Brain-controlled mode, these values come from normalizeBrainTrailingIntoRisk()
        // which provides drawdown_factor from: brain_trailing_contract, risk_block,
        // or documented_default (0.5). drawdown_factor is NOT the same as trailing_min_step.
        $trailing = $risk['trailing'];
        if ($trailing['enabled'] ?? false) {
            $activationRoiPct = (float)($trailing['activation_roi_pct'] ?? 0);
            $drawdownFactor = (float)($trailing['drawdown_factor'] ?? 0);
            
            if ($activationRoiPct <= 0) {
                $result['valid'] = false;
                $result['reason'] = 'invalid_trailing_activation_roi_pct:' . $activationRoiPct;
                return $result;
            }
            
            if ($drawdownFactor <= 0 || $drawdownFactor > 1) {
                $result['valid'] = false;
                $result['reason'] = 'invalid_trailing_drawdown_factor:' . $drawdownFactor;
                return $result;
            }
        }
        
        // Validate logical_stop block if present
        $logicalStop = $risk['logical_stop'] ?? null;
        if (is_array($logicalStop) && ($logicalStop['enabled'] ?? false)) {
            $logicalStopRoi = (float)($logicalStop['logical_stop_roi'] ?? 0);
            if ($logicalStopRoi <= 0 || $logicalStopRoi > 1.0) {
                // Don't reject — just warn. Logical stop is optional enhancement.
                $result['missing_fields'][] = 'logical_stop.logical_stop_roi_invalid:' . $logicalStopRoi;
            }
        }

        // P1.2: Validate numeric values with proper ranges
        $budget = (float)($risk['budget_usdt_per_trade'] ?? 0);
        if ($budget <= 0) {
            $result['valid'] = false;
            $result['reason'] = 'invalid_budget:' . $budget;
            return $result;
        }
        
        $leverage = (int)($risk['leverage'] ?? 0);
        if ($leverage < 1 || $leverage > 125) {
            $result['valid'] = false;
            $result['reason'] = 'invalid_leverage:' . $leverage;
            return $result;
        }
        
        // P1.2: stop_from_liq_range_pct must be > 0 and <= 100
        $stopFromLiqPct = (float)($risk['stop_from_liq_range_pct'] ?? 0);
        if ($stopFromLiqPct <= 0 || $stopFromLiqPct > 100) {
            $result['valid'] = false;
            $result['reason'] = 'invalid_stop_from_liq_range_pct:' . $stopFromLiqPct;
            return $result;
        }
        
        // P1.2: slippage_bps >= 0 (must be set and non-negative)
        if (!isset($risk['slippage_bps'])) {
            $result['valid'] = false;
            $result['reason'] = 'missing_field:slippage_bps';
            return $result;
        }
        $slippageBps = (int)$risk['slippage_bps'];
        if ($slippageBps < 0) {
            $result['valid'] = false;
            $result['reason'] = 'invalid_slippage_bps:' . $slippageBps;
            return $result;
        }
        
        // P1.2: fees_bps >= 0 (must be set and non-negative)
        if (!isset($risk['fees_bps'])) {
            $result['valid'] = false;
            $result['reason'] = 'missing_field:fees_bps';
            return $result;
        }
        $feesBps = (int)$risk['fees_bps'];
        if ($feesBps < 0) {
            $result['valid'] = false;
            $result['reason'] = 'invalid_fees_bps:' . $feesBps;
            return $result;
        }
        
        return $result;
    }
    
    /**
     * Calculate position size
     * 
     * @param array $risk Risk block
     * @param float $entryPrice Entry price
     * @param string $symbol Symbol
     * @return float Position size (quantity)
     */
    public function calculatePositionSize(array $risk, float $entryPrice, string $symbol): float
    {
        if ($entryPrice <= 0) {
            return 0.0;
        }
        
        $budget = (float)($risk['budget_usdt_per_trade'] ?? 0);
        $leverage = (int)($risk['leverage'] ?? 1);
        $slippageBps = (int)($risk['slippage_bps'] ?? 0);
        
        if ($budget <= 0 || $leverage < 1) {
            return 0.0;
        }
        
        // Calculate notional value
        $notional = $budget * $leverage;
        
        // Apply slippage buffer
        $slippageMultiplier = 1 - ($slippageBps / 10000);
        $adjustedNotional = $notional * $slippageMultiplier;
        
        // Calculate quantity
        $qty = $adjustedNotional / $entryPrice;
        
        // Round to reasonable precision (8 decimals max)
        return round($qty, 8);
    }
    
    /**
     * Calculate stop loss from liquidation price (Phase-1)
     * 
     * Formula (strict per stop_from_liq_range_pct):
     * - LONG: sl = liqPrice + (entryAvg - liqPrice) * pct/100
     * - SHORT: sl = liqPrice - (liqPrice - entryAvg) * pct/100
     * 
     * @param array $risk Risk block
     * @param float $entryAvg Average entry price from exchange
     * @param float $liqPrice Liquidation price from exchange
     * @param string $side Position side (long|short)
     * @return float|null Stop loss price or null if invalid (triggers fail-safe)
     */
    public function calculateStopLossFromLiq(array $risk, float $entryAvg, float $liqPrice, string $side): ?float
    {
        // Invalid liquidation price - cannot calculate SL
        if ($liqPrice <= 0) {
            return null;
        }
        
        // Invalid entry price
        if ($entryAvg <= 0) {
            return null;
        }
        
        $stopRangePct = (float)($risk['stop_from_liq_range_pct'] ?? 0);
        if ($stopRangePct <= 0 || $stopRangePct > 100) {
            return null;
        }
        
        $pctMultiplier = $stopRangePct / 100;
        $side = strtolower($side);
        
        if ($side === 'long') {
            // LONG: SL above liquidation
            // sl = liqPrice + (entryAvg - liqPrice) * pct/100
            $range = $entryAvg - $liqPrice;
            if ($range <= 0) {
                // INVALID STATE: Entry at or below liquidation price.
                // This indicates corrupted position data or extreme market conditions.
                // Returning null triggers fail-safe close to protect capital.
                // Operators should investigate the position data from exchange.
                return null;
            }
            $sl = $liqPrice + ($range * $pctMultiplier);
        } else {
            // SHORT: SL below liquidation
            // sl = liqPrice - (liqPrice - entryAvg) * pct/100
            $range = $liqPrice - $entryAvg;
            if ($range <= 0) {
                // INVALID STATE: Entry at or above liquidation price.
                // This indicates corrupted position data or extreme market conditions.
                // Returning null triggers fail-safe close to protect capital.
                // Operators should investigate the position data from exchange.
                return null;
            }
            $sl = $liqPrice - ($range * $pctMultiplier);
        }
        
        // Round to 8 decimals
        return round($sl, 8);
    }
    
    /**
     * Calculate trailing stop parameters (Phase-1)
     * 
     * B3 FIX: ROI calculation INCLUDES leverage.
     * 
     * If risk.trailing.enabled=false → ['enabled'=>false].
     * If enabled:
     * - activation_roi_pct = risk.trailing.activation_roi_pct (ROI including leverage)
     * - drawdown_factor = risk.trailing.drawdown_factor
     * - activePrice:
     *   - LONG: entry * (1 + activation_roi_pct/100/leverage)
     *   - SHORT: entry * (1 - activation_roi_pct/100/leverage)
     * - trailingStop (distance in price):
     *   - trail_dist_pct = (activation_roi_pct/100/leverage) * drawdown_factor
     *   - trailingStop = entry * trail_dist_pct
     * 
     * drawdown_factor semantics:
     *   trailing distance = price_move_pct * drawdown_factor
     *   This is a giveback ratio, NOT the same as trailing_min_step.
     *   In Brain-controlled mode, this value comes from the normalized
     *   Brain trailing contract (via normalizeBrainTrailingIntoRisk).
     * 
     * @param array $risk Risk block
     * @param float $entryAvg Average entry price
     * @param string $side Position side (long|short)
     * @return array Trailing parameters
     */
    public function calculateTrailingParams(array $risk, float $entryAvg, string $side): array
    {
        $trailing = $risk['trailing'] ?? [];
        
        // Check if trailing is enabled
        // In Brain-controlled mode, this reflects the Brain's trailing decision
        if (!($trailing['enabled'] ?? false)) {
            return ['enabled' => false];
        }
        
        // Get parameters — in Brain mode these come from normalized Brain contract
        $activationRoiPct = (float)($trailing['activation_roi_pct'] ?? 0);
        $drawdownFactor = (float)($trailing['drawdown_factor'] ?? 0);
        $leverage = (int)($risk['leverage'] ?? 1);
        
        // Validate parameters
        if ($activationRoiPct <= 0 || $drawdownFactor <= 0 || $leverage < 1 || $entryAvg <= 0) {
            return ['enabled' => false];
        }
        
        $side = strtolower($side);
        
        // B3 FIX: ROI includes leverage, so actual price move is smaller
        // If user wants 10% ROI at 10x leverage, price needs to move only 1%
        $priceMovePct = $activationRoiPct / 100 / $leverage;
        
        // Calculate activePrice (price at which trailing activates)
        if ($side === 'long') {
            // LONG: activates when price rises to target ROI
            $activePrice = $entryAvg * (1 + $priceMovePct);
        } else {
            // SHORT: activates when price falls to target ROI
            $activePrice = $entryAvg * (1 - $priceMovePct);
        }
        
        // Calculate trailingStop distance (in price units)
        // Trailing distance = price move * drawdown_factor
        $trailDistPct = $priceMovePct * $drawdownFactor;
        $trailingStop = $entryAvg * $trailDistPct;
        
        return [
            'enabled' => true,
            'activation_roi_pct' => $activationRoiPct,
            'drawdown_factor' => $drawdownFactor,
            'leverage' => $leverage,
            'active_price' => round($activePrice, 8),
            'trailing_stop' => round($trailingStop, 8),
        ];
    }
    
    /**
     * Calculate stop loss price (legacy - from entry price percentage)
     * 
     * @param array $risk Risk block
     * @param float $entryPrice Entry price
     * @param string $side Position side (long|short)
     * @return float|null Stop loss price
     */
    public function calculateStopLoss(array $risk, float $entryPrice, string $side): ?float
    {
        $stopRange = (float)($risk['stop_from_liq_range_pct'] ?? 0);
        if ($stopRange <= 0) {
            return null;
        }
        
        $multiplier = $stopRange / 100;
        
        if ($side === 'long') {
            return $entryPrice * (1 - $multiplier);
        } else {
            return $entryPrice * (1 + $multiplier);
        }
    }
    
    /**
     * Calculate take profit price
     * 
     * @param array $risk Risk block
     * @param float $entryPrice Entry price
     * @param string $side Position side (long|short)
     * @return float|null Take profit price
     */
    public function calculateTakeProfit(array $risk, float $entryPrice, string $side): ?float
    {
        $tp = $risk['take_profit'] ?? [];
        
        if (!($tp['enabled'] ?? false)) {
            return null;
        }
        
        $roiPct = (float)($tp['roi_pct'] ?? 0);
        if ($roiPct <= 0) {
            return null;
        }
        
        $multiplier = $roiPct / 100;
        
        if ($side === 'long') {
            return $entryPrice * (1 + $multiplier);
        } else {
            return $entryPrice * (1 - $multiplier);
        }
    }
    
    /**
     * Check limits
     * 
     * @param array $risk Risk block
     * @param int $currentPositions Current open positions count
     * @param array $openSymbols List of open position symbols
     * @param string $newSymbol New symbol to open
     * @return array Check result
     */
    public function checkLimits(array $risk, int $currentPositions, array $openSymbols, string $newSymbol): array
    {
        $result = [
            'allowed' => true,
            'reason' => null,
        ];
        
        $limits = $risk['limits'] ?? [];
        
        // Check max open trades
        $maxTrades = (int)($limits['max_open_trades'] ?? 0);
        if ($maxTrades > 0 && $currentPositions >= $maxTrades) {
            $result['allowed'] = false;
            $result['reason'] = 'max_open_trades_reached:' . $maxTrades;
            return $result;
        }
        
        // Check one trade per symbol
        $onePerSymbol = (bool)($limits['one_trade_per_symbol'] ?? true);
        if ($onePerSymbol && in_array($newSymbol, $openSymbols, true)) {
            $result['allowed'] = false;
            $result['reason'] = 'symbol_already_open:' . $newSymbol;
            return $result;
        }
        
        return $result;
    }
}

/* RULES
- RiskEngine validates and calculates from risk block
- Risk = ONLY from Brain - NO hardcoded defaults
- SL calculated from liquidation price (stop_from_liq_range_pct)
- Trailing params include leverage in ROI calculation
- take_profit is optional in Phase-1
- Validates numeric ranges strictly
*/
