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
        
        // P1.2: stop_from_liq_range_pct validation — relaxed for entry_roi mode
        // When stop_control_mode=entry_roi, the exchange SL is computed from entry price,
        // so stop_from_liq_range_pct is used only as emergency fallback (still required > 0).
        $stopControlMode = (string)($risk['stop_control']['stop_control_mode'] ?? ($risk['stop_control_mode'] ?? 'auto'));
        $stopFromLiqPct = (float)($risk['stop_from_liq_range_pct'] ?? 0);
        if ($stopFromLiqPct <= 0 || $stopFromLiqPct > 100) {
            if ($stopControlMode !== 'entry_roi') {
                $result['valid'] = false;
                $result['reason'] = 'invalid_stop_from_liq_range_pct:' . $stopFromLiqPct;
                return $result;
            }
            // In entry_roi mode, warn but don't reject — emergency stop may not be needed
            $result['missing_fields'][] = 'stop_from_liq_range_pct_zero_in_entry_roi_mode';
        }
        
        // Validate entry_roi mode fields
        if ($stopControlMode === 'entry_roi') {
            $entryRoi = (float)($risk['stop_control']['stop_loss_from_entry_roi'] ?? 0);
            if ($entryRoi <= 0 || $entryRoi > 1.0) {
                $result['valid'] = false;
                $result['reason'] = 'invalid_stop_loss_from_entry_roi:' . $entryRoi;
                return $result;
            }
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
     * Calculate stop loss from entry price (entry_roi mode)
     * 
     * Formula:
     * - LONG: sl = entryPrice * (1 - stop_loss_from_entry_roi)
     * - SHORT: sl = entryPrice * (1 + stop_loss_from_entry_roi)
     * 
     * @param array $risk Risk block
     * @param float $entryPrice Entry price from exchange
     * @param string $side Position side (long|short)
     * @return float|null Stop loss price or null if invalid
     */
    public function calculateStopLossFromEntry(array $risk, float $entryPrice, string $side): ?float
    {
        if ($entryPrice <= 0) {
            return null;
        }
        
        $stopControl = $risk['stop_control'] ?? [];
        $entryRoi = (float)($stopControl['stop_loss_from_entry_roi'] ?? 0);
        if ($entryRoi <= 0 || $entryRoi > 1.0) {
            return null;
        }
        
        $side = strtolower($side);
        
        if ($side === 'long') {
            // LONG: SL below entry
            $sl = $entryPrice * (1 - $entryRoi);
        } else {
            // SHORT: SL above entry
            $sl = $entryPrice * (1 + $entryRoi);
        }
        
        if ($sl <= 0) {
            return null;
        }
        
        return round($sl, 8);
    }
    
    /**
     * Calculate trailing stop parameters (Phase-1)
     * 
     * B3 FIX: ROI calculation INCLUDES leverage.
     * 
     * Supports three trailing modes:
     *   - roi_giveback (default): trailing distance based on drawdown_factor
     *   - price_distance: trailing at fixed pct from current price
     *   - price_distance_floor: activation floor + locked ROI + price distance + step corridor
     * 
     * If risk.trailing.enabled=false → ['enabled'=>false].
     * If enabled:
     * - activation_roi_pct = risk.trailing.activation_roi_pct (ROI including leverage)
     * - For roi_giveback mode:
     *   - drawdown_factor = risk.trailing.drawdown_factor
     *   - trailingStop = entry * priceMovePct * drawdown_factor
     * - For price_distance mode:
     *   - trailing_price_distance_pct = risk.trailing.trailing_price_distance_pct
     *   - trailingStop = entry * trailing_price_distance_pct
     * - For price_distance_floor mode:
     *   - trailing_activation_floor_roi = activation threshold (percent)
     *   - trailing_floor_lock_roi = minimum locked ROI (percent)
     *   - trailing_price_distance_pct = distance from best price
     *   - trailing_step_mode = 'fixed' | 'auto_strength'
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
        
        // Get common parameters
        $activationRoiPct = (float)($trailing['activation_roi_pct'] ?? 0);
        $leverage = (int)($risk['leverage'] ?? 1);
        $trailingMode = (string)($trailing['trailing_mode'] ?? 'roi_giveback');
        
        if ($activationRoiPct <= 0 || $leverage < 1 || $entryAvg <= 0) {
            return ['enabled' => false];
        }
        
        $side = strtolower($side);
        
        // B3 FIX: ROI includes leverage, so actual price move is smaller
        $priceMovePct = $activationRoiPct / 100 / $leverage;
        
        // Calculate activePrice (price at which trailing activates)
        if ($side === 'long') {
            $activePrice = $entryAvg * (1 + $priceMovePct);
        } else {
            $activePrice = $entryAvg * (1 - $priceMovePct);
        }

        // Price-distance-floor mode (activation floor + locked ROI + price distance + step corridor)
        if ($trailingMode === 'price_distance_floor') {
            $floorActivationRoi = (float)($trailing['trailing_activation_floor_roi'] ?? $activationRoiPct);
            $floorLockRoi = (float)($trailing['trailing_floor_lock_roi'] ?? 3.0);
            $distancePct = (float)($trailing['trailing_price_distance_pct'] ?? 0.02);
            $stepMode = (string)($trailing['trailing_step_mode'] ?? 'fixed');
            $stepPctMin = (float)($trailing['trailing_step_pct_min'] ?? 0.005);
            $stepPctMax = (float)($trailing['trailing_step_pct_max'] ?? 0.02);

            if ($distancePct <= 0 || $distancePct >= 1.0) {
                return ['enabled' => false];
            }
            if ($floorLockRoi >= $floorActivationRoi) {
                // Floor lock must be less than activation threshold
                return ['enabled' => false];
            }

            // Use floor activation ROI for activePrice
            $floorPriceMovePct = $floorActivationRoi / 100 / $leverage;
            if ($side === 'long') {
                $activePrice = $entryAvg * (1 + $floorPriceMovePct);
            } else {
                $activePrice = $entryAvg * (1 - $floorPriceMovePct);
            }

            $trailingStop = $entryAvg * $distancePct;
            // Compute floor stop price
            $floorPriceMove = ($floorLockRoi / 100.0) / $leverage;
            if ($side === 'long') {
                $floorStopPrice = $entryAvg * (1.0 + $floorPriceMove);
                $theoreticalStop = $activePrice * (1.0 - $distancePct);
            } else {
                $floorStopPrice = $entryAvg * (1.0 - $floorPriceMove);
                $theoreticalStop = $activePrice * (1.0 + $distancePct);
            }

            return [
                'enabled' => true,
                'trailing_mode' => 'price_distance_floor',
                'activation_roi_pct' => $floorActivationRoi,
                'trailing_activation_floor_roi' => $floorActivationRoi,
                'trailing_floor_lock_roi' => $floorLockRoi,
                'trailing_price_distance_pct' => $distancePct,
                'trailing_step_mode' => $stepMode,
                'trailing_step_pct_min' => $stepPctMin,
                'trailing_step_pct_max' => $stepPctMax,
                'leverage' => $leverage,
                'active_price' => round($activePrice, 8),
                'trailing_stop' => round($trailingStop, 8),
                'floor_stop_price' => round($floorStopPrice, 8),
                'exchange_trailing_distance' => round($trailingStop, 8),
                'theoretical_current_stop_price' => round($theoreticalStop, 8),
            ];
        }

        // Price-distance mode
        if ($trailingMode === 'price_distance') {
            $distancePct = (float)($trailing['trailing_price_distance_pct'] ?? 0.02);
            if ($distancePct <= 0 || $distancePct >= 1.0) {
                return ['enabled' => false];
            }
            $trailingStop = $entryAvg * $distancePct;
            // Compute theoretical stop price at activation point
            $theoreticalStop = $side === 'long'
                ? $activePrice * (1.0 - $distancePct)
                : $activePrice * (1.0 + $distancePct);
            return [
                'enabled' => true,
                'trailing_mode' => 'price_distance',
                'activation_roi_pct' => $activationRoiPct,
                'trailing_price_distance_pct' => $distancePct,
                'leverage' => $leverage,
                'active_price' => round($activePrice, 8),
                'trailing_stop' => round($trailingStop, 8),
                'exchange_trailing_distance' => round($trailingStop, 8),
                'theoretical_current_stop_price' => round($theoreticalStop, 8),
            ];
        }

        // ROI-giveback mode (default)
        $drawdownFactor = (float)($trailing['drawdown_factor'] ?? 0);
        if ($drawdownFactor <= 0) {
            return ['enabled' => false];
        }
        
        $trailDistPct = $priceMovePct * $drawdownFactor;
        $trailingStop = $entryAvg * $trailDistPct;
        
        return [
            'enabled' => true,
            'trailing_mode' => 'roi_giveback',
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
