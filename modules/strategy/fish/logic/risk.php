<?php

declare(strict_types=1);

/**
 * Fish Strategy — Risk Calculator
 *
 * Computes stop, take profit, and breakeven trigger prices for a setup.
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
 *   - When price reaches the trigger, the execution layer moves stop to entry.
 *     (Execution is not wired in v1 — the field is stored for future use.)
 *
 * Validation:
 *   - Stop must be on the correct side of entry (below entry for LONG).
 *   - TP must be on the correct side of entry (above entry for LONG).
 *   - If the stop anchor is on the wrong side, the setup is rejected.
 */

namespace Modules\Strategy\Fish\Logic;

final class FishRisk
{
    /**
     * Calculate stop, TP, and breakeven trigger.
     *
     * @param  string  $side               'long' | 'short'
     * @param  float   $entryPrice         Limit entry price from FishEntry
     * @param  array   $level              Liquidity level array from FishLiquidityLevel
     * @param  array   $structure          Structure result from FishStructure::analyse()
     * @param  float   $tpMultiplier       From config
     * @param  float   $beMultiplier       From config (breakeven_trigger_multiplier)
     * @return array{
     *     valid:                bool,
     *     reject_reason:        string|null,
     *     stop_price:           float|null,
     *     stop_anchor_price:    float|null,
     *     take_profit_price:    float|null,
     *     breakeven_trigger:    float|null
     * }
     */
    public function calculate(
        string $side,
        float  $entryPrice,
        array  $level,
        array  $structure,
        float  $tpMultiplier,
        float  $beMultiplier
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

        // Validate stop is on the correct side of entry
        if ($side === 'long' && $stopAnchor >= $entryPrice) {
            return $this->invalid('stop_above_entry');
        }
        if ($side === 'short' && $stopAnchor <= $entryPrice) {
            return $this->invalid('stop_below_entry');
        }

        // Calculate TP and breakeven trigger
        $tpDistance = $rangeSize * $tpMultiplier;
        $beDistance = $rangeSize * $beMultiplier;

        if ($side === 'long') {
            $takeProfit        = $entryPrice + $tpDistance;
            $breakeven_trigger = $entryPrice + $beDistance;
        } else {
            $takeProfit        = $entryPrice - $tpDistance;
            $breakeven_trigger = $entryPrice - $beDistance;
        }

        // Sanity checks
        if ($side === 'long' && $takeProfit <= $entryPrice) {
            return $this->invalid('tp_below_entry');
        }
        if ($side === 'short' && $takeProfit >= $entryPrice) {
            return $this->invalid('tp_above_entry');
        }

        return [
            'valid'             => true,
            'reject_reason'     => null,
            'stop_price'        => round((float)$stopAnchor, 8),
            'stop_anchor_price' => round((float)$stopAnchor, 8),
            'take_profit_price' => round($takeProfit, 8),
            'breakeven_trigger' => round($breakeven_trigger, 8),
        ];
    }

    private function invalid(string $reason): array
    {
        return [
            'valid'             => false,
            'reject_reason'     => $reason,
            'stop_price'        => null,
            'stop_anchor_price' => null,
            'take_profit_price' => null,
            'breakeven_trigger' => null,
        ];
    }
}
