<?php

declare(strict_types=1);

namespace Modules\ProfManager\Lib;

/**
 * RiskMath
 *
 * Pure math helpers for Profit Manager ROI and price-lock calculations.
 * Based on the legacy PM step-trailing logic (positive-profit domain only).
 *
 * ROI formula (Bybit-compatible):
 *   long:  ROI = (current_price - entry_price) / entry_price * 100 * leverage
 *   short: ROI = (entry_price - current_price) / entry_price * 100 * leverage
 *
 * All methods are stateless and accept explicit arguments — no config injection.
 */
class RiskMath
{
    // =========================================================================
    // ROI helpers
    // =========================================================================

    /**
     * Calculate ROI % from position data.
     * Accepts array with keys: side, entry_price|avg_price, current_price|mark_price, leverage.
     *
     * @param array $position
     * @return float|null
     */
    public function calculateRoiPct(array $position): ?float
    {
        $side       = strtolower(trim($position['side'] ?? ''));
        $entryPrice = (float) ($position['entry_price'] ?? $position['avg_price'] ?? 0.0);
        $markPrice  = (float) ($position['current_price'] ?? $position['mark_price'] ?? 0.0);
        $leverage   = (float) ($position['leverage'] ?? 0.0);

        if ($entryPrice <= 0.0 || $markPrice <= 0.0 || $leverage <= 0.0) {
            return null;
        }

        if ($side === 'long' || $side === 'buy') {
            return ($markPrice - $entryPrice) / $entryPrice * 100.0 * $leverage;
        }

        if ($side === 'short' || $side === 'sell') {
            return ($entryPrice - $markPrice) / $entryPrice * 100.0 * $leverage;
        }

        return null;
    }

    /**
     * Convert ROI % to price-move % (de-leveraged).
     */
    public function roiToPriceMovePercent(float $roiPct, float $leverage): ?float
    {
        if ($leverage <= 0.0) {
            return null;
        }
        return $roiPct / $leverage;
    }

    // =========================================================================
    // Step-trailing lock ROI
    // =========================================================================

    /**
     * Calculate the target lock ROI using the legacy step-trailing algorithm.
     *
     * Algorithm:
     *   if peak_roi < activation_roi → return null (no lock)
     *   excess = peak_roi - activation_roi
     *   steps  = floor(excess / step_roi)
     *   target = activation_roi + steps * step_roi - lock_buffer_roi
     *   target = max(target, lock_floor_roi)
     *   if target <= 0 → return null
     *
     * @param float $peakRoi       Highest observed ROI % for this position
     * @param array $profileConfig Profile config slice
     * @return float|null
     */
    public function calculateStepTrailingLockRoi(float $peakRoi, array $profileConfig): ?float
    {
        $activationRoi = (float) ($profileConfig['activation_roi'] ?? 10.0);
        $stepRoi       = (float) ($profileConfig['step_roi']       ?? 3.0);
        $lockBufferRoi = (float) ($profileConfig['lock_buffer_roi']?? 2.0);
        $lockFloorRoi  = (float) ($profileConfig['lock_floor_roi'] ?? 5.0);

        if ($peakRoi < $activationRoi) {
            return null;
        }

        $excess = max(0.0, $peakRoi - $activationRoi);

        if ($stepRoi <= 0.0) {
            $targetLockRoi = $activationRoi - $lockBufferRoi;
        } else {
            $steps         = floor($excess / $stepRoi);
            $targetLockRoi = $activationRoi + ($steps * $stepRoi) - $lockBufferRoi;
        }

        $targetLockRoi = max($targetLockRoi, $lockFloorRoi);

        if ($targetLockRoi <= 0.0) {
            return null;
        }

        return $targetLockRoi;
    }

    /**
     * Calculate the lock price from a lock ROI %.
     *
     * @param array $position
     * @param float $lockRoi  Target lock ROI %
     * @return float|null
     */
    public function calculateProfitLockPrice(array $position, float $lockRoi): ?float
    {
        $side       = strtolower(trim($position['side'] ?? ''));
        $entryPrice = (float) ($position['entry_price'] ?? $position['avg_price'] ?? 0.0);
        $leverage   = (float) ($position['leverage'] ?? 0.0);

        if ($entryPrice <= 0.0 || $leverage <= 0.0) {
            return null;
        }

        $priceMovePercent = $this->roiToPriceMovePercent($lockRoi, $leverage);
        if ($priceMovePercent === null) {
            return null;
        }

        $factor = $priceMovePercent / 100.0;

        if ($side === 'long' || $side === 'buy') {
            return $entryPrice * (1.0 + $factor);
        }

        if ($side === 'short' || $side === 'sell') {
            return $entryPrice * (1.0 - $factor);
        }

        return null;
    }

    // =========================================================================
    // Profit-side safety checks
    // =========================================================================

    /**
     * Verify that the proposed lock price is on the profitable side of entry.
     * Long:  lock_price > entry_price
     * Short: lock_price < entry_price
     *
     * @param array $position
     * @param float $lockPrice
     * @return bool
     */
    public function isProfitSide(array $position, float $lockPrice): bool
    {
        $side       = strtolower(trim($position['side'] ?? ''));
        $entryPrice = (float) ($position['entry_price'] ?? $position['avg_price'] ?? 0.0);

        if ($entryPrice <= 0.0 || $lockPrice <= 0.0) {
            return false;
        }

        if ($side === 'long' || $side === 'buy') {
            return $lockPrice > $entryPrice;
        }

        if ($side === 'short' || $side === 'sell') {
            return $lockPrice < $entryPrice;
        }

        return false;
    }

    /**
     * Ratchet check — new lock price must improve over the existing one.
     * Long:  new > old (move up)
     * Short: new < old (move down)
     *
     * @param array $position
     * @param float $oldLockPrice  0 = no prior lock
     * @param float $newLockPrice
     * @return bool
     */
    public function isImprovingLock(array $position, float $oldLockPrice, float $newLockPrice): bool
    {
        if ($oldLockPrice <= 0.0) {
            return true;
        }

        $side = strtolower(trim($position['side'] ?? ''));

        if ($side === 'long' || $side === 'buy') {
            return $newLockPrice > $oldLockPrice;
        }

        if ($side === 'short' || $side === 'sell') {
            return $newLockPrice < $oldLockPrice;
        }

        return false;
    }

    /**
     * Anti-spam: ensure the lock price is far enough from current price
     * to avoid being trivially hit immediately.
     *
     * min_price_distance_pct is expressed as a percentage.
     *
     * Long:  lock_price <= current * (1 - min_distance)
     * Short: lock_price >= current * (1 + min_distance)
     *
     * @param array $position
     * @param float $lockPrice
     * @param array $profileConfig
     * @return bool
     */
    public function isSafeDistance(array $position, float $lockPrice, array $profileConfig): bool
    {
        $minDistancePct = (float) ($profileConfig['min_price_distance_pct'] ?? 0.15);
        $side           = strtolower(trim($position['side'] ?? ''));
        $currentPrice   = (float) ($position['current_price'] ?? $position['mark_price'] ?? 0.0);

        if ($currentPrice <= 0.0 || $lockPrice <= 0.0) {
            return false;
        }

        $factor = $minDistancePct / 100.0;

        if ($side === 'long' || $side === 'buy') {
            return $lockPrice <= $currentPrice * (1.0 - $factor);
        }

        if ($side === 'short' || $side === 'sell') {
            return $lockPrice >= $currentPrice * (1.0 + $factor);
        }

        return false;
    }

    // =========================================================================
    // Price normalization
    // =========================================================================

    /**
     * Round price to nearest tick (round-half-up).
     */
    public function roundToTick(float $price, float $tickSize): float
    {
        if ($tickSize <= 0.0) {
            return $price;
        }
        return round($price / $tickSize) * $tickSize;
    }

    /**
     * Floor price to tick (conservative for long lock prices — keeps lock below market).
     */
    public function floorToTick(float $price, float $tickSize): float
    {
        if ($tickSize <= 0.0) {
            return $price;
        }
        return floor($price / $tickSize) * $tickSize;
    }

    /**
     * Ceil price to tick (conservative for short lock prices — keeps lock above market).
     */
    public function ceilToTick(float $price, float $tickSize): float
    {
        if ($tickSize <= 0.0) {
            return $price;
        }
        return ceil($price / $tickSize) * $tickSize;
    }
}
