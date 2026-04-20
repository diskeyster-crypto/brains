<?php

declare(strict_types=1);

/**
 * Fish Strategy — Risk Calculator
 *
 * Computes stop, take profit, and breakeven trigger prices for a setup.
 * Also validates the full TP/SL geometry and computes reward/risk ratio.
 *
 * Stop rule:
 *   - Anchored to the nearest confirmed structural extremum.
 *   - LONG:  stop = last confirmed swing LOW from the structure analysis.
 *   - SHORT: stop = last confirmed swing HIGH from the structure analysis.
 *   - If no swing is available for the side, the setup is rejected.
 *
 * Take profit rule:
 *   - Distance from entry = liquidity pattern range_size × tp_multiplier.
 *   - LONG:  take_profit = entry_price + (range_size × tp_multiplier)
 *   - SHORT: take_profit = entry_price - (range_size × tp_multiplier)
 *
 * Breakeven trigger rule:
 *   - Distance from entry = range_size × breakeven_trigger_multiplier.
 *   - LONG:  breakeven_trigger = entry_price + (range_size × be_multiplier)
 *   - SHORT: breakeven_trigger = entry_price - (range_size × be_multiplier)
 *
 * Geometry validation:
 *   - stop must be on the correct side of entry
 *   - TP must be on the correct side of entry
 *   - risk_distance_abs and reward_distance_abs must each be > 0
 *   - rr_ratio = reward / risk must be >= min_rr_ratio
 *   - Any failure sets valid = false with a specific reject_reason
 *   - Geometry fields are returned on failure too (for diagnostics / stats)
 */

namespace Modules\Strategy\Fish\Logic;

final class FishRisk
{
    /**
     * Calculate stop, TP, breakeven trigger, and validate geometry.
     *
     * @param  string  $side               'long' | 'short'
     * @param  float   $entryPrice         Limit entry price from FishEntry
     * @param  array   $level              Liquidity level array from FishLiquidityLevel
     * @param  array   $structure          Structure result from FishStructure::analyse()
     * @param  float   $tpMultiplier       From config (tp_multiplier)
     * @param  float   $beMultiplier       From config (breakeven_trigger_multiplier)
     * @param  float   $minRrRatio         From config (min_rr_ratio); 0 = disabled
     * @return array{
     *     valid:                    bool,
     *     reject_reason:            string|null,
     *     stop_price:               float|null,
     *     stop_anchor_price:        float|null,
     *     take_profit_price:        float|null,
     *     breakeven_trigger:        float|null,
     *     risk_distance_abs:        float|null,
     *     reward_distance_abs:      float|null,
     *     rr_ratio:                 float|null,
     *     stop_side_valid:          bool,
     *     tp_side_valid:            bool,
     *     geometry_valid:           bool,
     *     geometry_reject_reason:   string|null
     * }
     */
    public function calculate(
        string $side,
        float  $entryPrice,
        array  $level,
        array  $structure,
        float  $tpMultiplier,
        float  $beMultiplier,
        float  $minRrRatio = 2.0
    ): array {
        $rangeSize = (float)($level['range_size'] ?? 0.0);

        // Pick stop anchor from the structural extremum
        if ($side === 'long') {
            $stopAnchor = $structure['last_swing_low'] ?? null;
        } else {
            $stopAnchor = $structure['last_swing_high'] ?? null;
        }

        if ($stopAnchor === null) {
            return $this->invalid('no_stop_anchor');
        }

        $stopAnchorF = (float)$stopAnchor;

        // Check stop is on the correct side of entry
        if ($side === 'long' && $stopAnchorF >= $entryPrice) {
            return $this->invalid('stop_above_entry', false, true);
        }
        if ($side === 'short' && $stopAnchorF <= $entryPrice) {
            return $this->invalid('stop_below_entry', false, true);
        }

        // Calculate TP and breakeven trigger
        $tpDistance = $rangeSize * $tpMultiplier;
        $beDistance = $rangeSize * $beMultiplier;

        if ($side === 'long') {
            $takeProfit       = $entryPrice + $tpDistance;
            $breakevenTrigger = $entryPrice + $beDistance;
        } else {
            $takeProfit       = $entryPrice - $tpDistance;
            $breakevenTrigger = $entryPrice - $beDistance;
        }

        // Check TP is on the correct side of entry
        if ($side === 'long' && $takeProfit <= $entryPrice) {
            return $this->invalid('tp_below_entry', true, false);
        }
        if ($side === 'short' && $takeProfit >= $entryPrice) {
            return $this->invalid('tp_above_entry', true, false);
        }

        // Compute risk/reward distances
        $riskDist   = abs($entryPrice - $stopAnchorF);
        $rewardDist = abs($entryPrice - $takeProfit);

        if ($riskDist <= 0.0) {
            return $this->invalid('zero_risk_distance', true, true, $riskDist, $rewardDist);
        }
        if ($rewardDist <= 0.0) {
            return $this->invalid('zero_reward_distance', true, true, $riskDist, $rewardDist);
        }

        $rrRatio = $rewardDist / $riskDist;

        // Enforce minimum RR threshold
        if ($minRrRatio > 0.0 && $rrRatio < $minRrRatio) {
            return $this->invalid(
                'rr_below_min',
                true, true,
                $riskDist, $rewardDist, $rrRatio,
                round($stopAnchorF, 8),
                round($takeProfit, 8),
                round($breakevenTrigger, 8)
            );
        }

        return [
            'valid'                  => true,
            'reject_reason'          => null,
            'stop_price'             => round($stopAnchorF, 8),
            'stop_anchor_price'      => round($stopAnchorF, 8),
            'take_profit_price'      => round($takeProfit, 8),
            'breakeven_trigger'      => round($breakevenTrigger, 8),
            'risk_distance_abs'      => round($riskDist, 8),
            'reward_distance_abs'    => round($rewardDist, 8),
            'rr_ratio'               => round($rrRatio, 4),
            'stop_side_valid'        => true,
            'tp_side_valid'          => true,
            'geometry_valid'         => true,
            'geometry_reject_reason' => null,
        ];
    }

    /**
     * Build an invalid result, populating geometry diagnostics where available.
     * All optional fields default to null so callers don't need to pass them
     * when the values haven't been computed yet (e.g. early rejection).
     */
    private function invalid(
        string $reason,
        bool   $stopSideValid = false,
        bool   $tpSideValid   = false,
        ?float $riskDist      = null,
        ?float $rewardDist    = null,
        ?float $rrRatio       = null,
        ?float $stopPrice     = null,
        ?float $tpPrice       = null,
        ?float $beTrigger     = null
    ): array {
        return [
            'valid'                  => false,
            'reject_reason'          => $reason,
            'stop_price'             => $stopPrice,
            'stop_anchor_price'      => $stopPrice,
            'take_profit_price'      => $tpPrice,
            'breakeven_trigger'      => $beTrigger,
            'risk_distance_abs'      => $riskDist  !== null ? round($riskDist, 8)  : null,
            'reward_distance_abs'    => $rewardDist !== null ? round($rewardDist, 8) : null,
            'rr_ratio'               => $rrRatio   !== null ? round($rrRatio, 4)   : null,
            'stop_side_valid'        => $stopSideValid,
            'tp_side_valid'          => $tpSideValid,
            'geometry_valid'         => false,
            'geometry_reject_reason' => $reason,
        ];
    }
}
