<?php
declare(strict_types=1);

namespace Modules\System\ProfitManager\Lib;

/**
 * Risk Math
 * 
 * Formulas for ROI→price calculations and price normalization.
 * All calculations follow Bybit ROI formula: ROI = unrealisedPnl / positionIM * 100
 */
class RiskMath
{
    private array $config;
    
    public function __construct(array $config)
    {
        $this->config = $config;
    }
    
    // =========================================================================
    // ROI Calculations
    // =========================================================================
    
    /**
     * Calculate ROI percentage (Bybit formula)
     * 
     * @param float $unrealisedPnl Position unrealised PnL
     * @param float $positionIM Position initial margin
     * @return float|null ROI percentage, null if cannot calculate
     */
    public function calculateRoiPct(float $unrealisedPnl, float $positionIM): ?float
    {
        if ($positionIM <= 0.0) {
            return null;
        }
        
        return ($unrealisedPnl / $positionIM) * 100.0;
    }
    
    /**
     * Calculate price move percentage from ROI
     * 
     * @param float $roiPct ROI percentage
     * @param float $leverage Leverage
     * @return float|null Price move percentage, null if cannot calculate
     */
    public function roiToPriceMovePercent(float $roiPct, float $leverage): ?float
    {
        if ($leverage <= 0.0) {
            return null;
        }
        
        return $roiPct / $leverage;
    }
    
    // =========================================================================
    // Step Trailing Calculations
    // =========================================================================
    
    /**
     * Calculate step trailing target lock ROI
     * 
     * @param float $currentRoi Current ROI %
     * @param float $activationRoi Activation threshold ROI %
     * @param float $stepRoi Step size ROI %
     * @param float $lockBuffer Buffer to subtract from lock %
     * @param float $lockFloor Minimum lock ROI %
     * @return float|null Target lock ROI %, null if below activation
     */
    public function calculateStepTrailingLockRoi(
        float $currentRoi,
        float $activationRoi,
        float $stepRoi,
        float $lockBuffer,
        float $lockFloor
    ): ?float {
        // Activation threshold
        if ($currentRoi < $activationRoi) {
            return null;
        }

        // Step size may be 0 (treat as single-stage lock at activation)
        $stepRoi = (float) $stepRoi;

        if ($stepRoi <= 0.0) {
            $targetLockRoi = $activationRoi - $lockBuffer;
        } else {
            // Calculate excess ROI above activation (>= 0)
            $excess = max(0.0, $currentRoi - $activationRoi);

            // Completed steps (0..N)
            $steps = floor($excess / $stepRoi);

            // Lock ROI: activation + steps*step - buffer
            $targetLockRoi = $activationRoi + ($steps * $stepRoi) - $lockBuffer;
        }

        // Apply floor
        $targetLockRoi = max($targetLockRoi, $lockFloor);

        // Must be positive to be useful (also required for "profitable side" SL)
        if ($targetLockRoi <= 0.0) {
            return null;
        }

        return $targetLockRoi;

    }
    
    /**
     * Calculate new SL price for step trailing
     * 
     * @param string $side long|short
     * @param float $entryPrice Entry price
     * @param float $targetLockRoi Target lock ROI %
     * @param float $leverage Leverage
     * @return float|null New SL price, null if cannot calculate
     */
    public function calculateStepTrailingSL(
        string $side,
        float $entryPrice,
        float $targetLockRoi,
        float $leverage
    ): ?float {
        // Calculate price move percentage
        $priceMovePercent = $this->roiToPriceMovePercent($targetLockRoi, $leverage);
        if ($priceMovePercent === null) {
            return null;
        }
        
        // Calculate move factor
        $moveFactor = $priceMovePercent / 100.0;
        
        // Calculate candidate SL
        $side = strtolower($side);
        if ($side === 'long' || $side === 'buy') {
            // LONG: SL above entry (profitable side)
            return $entryPrice * (1.0 + $moveFactor);
        } elseif ($side === 'short' || $side === 'sell') {
            // SHORT: SL below entry (profitable side)
            return $entryPrice * (1.0 - $moveFactor);
        }
        
        return null;
    }
    
    // =========================================================================
    // Dumb Trailing Calculations
    // =========================================================================
    
    /**
     * Calculate trailing stop distance for dumb trailing
     * 
     * @param float $activationRoi Activation ROI %
     * @param float $leverage Leverage
     * @param float $minDistancePct Minimum distance %
     * @param float $drawdownFactor Drawdown factor
     * @return float Trailing distance as price amount
     */
    public function calculateDumbTrailingDistance(
        float $refPrice,
        float $activationRoi,
        float $leverage,
        float $minDistancePct,
        float $drawdownFactor
    ): float {
        // Calculate distance percentage
        $drawdownFactorSafe = ($drawdownFactor > 0.0) ? $drawdownFactor : 1.0;
        $leverageSafe = ($leverage > 0.0) ? $leverage : 1.0;
        // distance_pct = activation_roi / drawdown_factor / leverage
        $distancePct = $activationRoi / $drawdownFactorSafe / $leverageSafe;
        
        // Apply minimum
        $distancePct = max($distancePct, $minDistancePct);
        
        // Convert to price
        return $refPrice * ($distancePct / 100.0);
    }
    
    /**
     * Calculate activePrice for dumb trailing
     * 
     * @param string $side long|short
     * @param float $refPrice Reference price (mark/last)
     * @param float $epsilonPct Epsilon shift %
     * @param float $tickSize Tick size for rounding
     * @return float Active price
     */
    public function calculateDumbTrailingActivePrice(
        string $side,
        float $refPrice,
        float $epsilonPct,
        float $tickSize
    ): float {
        $epsilonFactor = $epsilonPct / 100.0;
        
        $side = strtolower($side);
        if ($side === 'long' || $side === 'buy') {
            // LONG: activePrice slightly below current to activate immediately
            $price = $refPrice * (1.0 - $epsilonFactor);
            return $this->floorToTick($price, $tickSize);
        } else {
            // SHORT: activePrice slightly above current to activate immediately
            $price = $refPrice * (1.0 + $epsilonFactor);
            return $this->ceilToTick($price, $tickSize);
        }
    }
    
    // =========================================================================
    // Price Normalization
    // =========================================================================
    
    /**
     * Normalize SL price by tick size
     * 
     * @param string $side long|short
     * @param float $price Price to normalize
     * @param float $tickSize Tick size
     * @return float Normalized price
     */
    public function normalizeSLPrice(string $side, float $price, float $tickSize): float
    {
        $side = strtolower($side);
        
        if ($side === 'long' || $side === 'buy') {
            // LONG: floor to keep SL conservative
            return $this->floorToTick($price, $tickSize);
        } else {
            // SHORT: ceil to keep SL conservative
            return $this->ceilToTick($price, $tickSize);
        }
    }
    
    /**
     * Floor price to tick size
     */
    public function floorToTick(float $price, float $tickSize): float
    {
        if ($tickSize <= 0) {
            return $price;
        }
        return floor($price / $tickSize) * $tickSize;
    }
    
    /**
     * Ceil price to tick size
     */
    public function ceilToTick(float $price, float $tickSize): float
    {
        if ($tickSize <= 0) {
            return $price;
        }
        return ceil($price / $tickSize) * $tickSize;
    }
    
    /**
     * Round price to tick size
     */
    public function roundToTick(float $price, float $tickSize): float
    {
        if ($tickSize <= 0) {
            return $price;
        }
        return round($price / $tickSize) * $tickSize;
    }
    
    // =========================================================================
    // Validation Helpers
    // =========================================================================
    
    /**
     * Check if SL is on profitable side of entry
     * 
     * @param string $side long|short
     * @param float $slPrice SL price
     * @param float $entryPrice Entry price
     * @return bool True if SL is on profitable side
     */
    public function isSLOnProfitableSide(string $side, float $slPrice, float $entryPrice): bool
    {
        $side = strtolower($side);
        
        if ($side === 'long' || $side === 'buy') {
            // LONG: SL must be above entry
            return $slPrice > $entryPrice;
        } elseif ($side === 'short' || $side === 'sell') {
            // SHORT: SL must be below entry
            return $slPrice < $entryPrice;
        }
        
        return false;
    }
    
    /**
     * Check if SL is safe distance from current price
     * 
     * @param string $side long|short
     * @param float $slPrice SL price
     * @param float $refPrice Reference price (min/max of mark/last)
     * @param float $minDistancePct Minimum distance %
     * @return bool True if SL is safe distance
     */
    public function isSLSafeDistance(string $side, float $slPrice, float $refPrice, float $minDistancePct): bool
    {
        $minDistanceFactor = $minDistancePct / 100.0;
        
        $side = strtolower($side);
        if ($side === 'long' || $side === 'buy') {
            // LONG: SL must be below ref * (1 - min_distance)
            return $slPrice <= $refPrice * (1.0 - $minDistanceFactor);
        } elseif ($side === 'short' || $side === 'sell') {
            // SHORT: SL must be above ref * (1 + min_distance)
            return $slPrice >= $refPrice * (1.0 + $minDistanceFactor);
        }
        
        return false;
    }
    
    /**
     * Check if new SL improves over old SL (ratchet only)
     * 
     * @param string $side long|short
     * @param float $newSL New SL price
     * @param float $oldSL Old SL price (0 = none)
     * @return bool True if new SL improves
     */
    public function isSLImproving(string $side, float $newSL, float $oldSL): bool
    {
        // If no old SL, any new SL is an improvement
        if ($oldSL <= 0.0) {
            return true;
        }
        
        $side = strtolower($side);
        if ($side === 'long' || $side === 'buy') {
            // LONG: new SL must be higher than old
            return $newSL > $oldSL;
        } elseif ($side === 'short' || $side === 'sell') {
            // SHORT: new SL must be lower than old
            return $newSL < $oldSL;
        }
        
        return false;
    }
    
    /**
     * Calculate price difference in ticks
     * 
     * @param float $price1 First price
     * @param float $price2 Second price
     * @param float $tickSize Tick size
     * @return int Difference in ticks (absolute)
     */
    public function priceDifferenceInTicks(float $price1, float $price2, float $tickSize): int
    {
        if ($tickSize <= 0) {
            return 0;
        }
        return (int) abs(round(($price1 - $price2) / $tickSize));
    }
    
    /**
     * Get reference price for SL validation
     * LONG: use min(markPrice, lastPrice) — conservative
     * SHORT: use max(markPrice, lastPrice) — conservative
     * 
     * @param string $side long|short
     * @param float $markPrice Mark price
     * @param float $lastPrice Last price
     * @return float Reference price
     */
    public function getReferencePrice(string $side, float $markPrice, float $lastPrice): float
    {
        $side = strtolower($side);
        
        if ($side === 'long' || $side === 'buy') {
            return min($markPrice, $lastPrice);
        } else {
            return max($markPrice, $lastPrice);
        }
    }
}

/* RULES
- All formulas follow Bybit ROI calculation
- price_move_pct = roi_pct / leverage
- LONG: SL moves UP to lock profit
- SHORT: SL moves DOWN to lock profit
- Normalization uses floor (LONG) or ceil (SHORT) to be conservative
*/
