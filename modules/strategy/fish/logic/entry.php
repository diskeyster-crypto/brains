<?php

declare(strict_types=1);

/**
 * Fish Strategy — Entry Calculator
 *
 * Determines the limit entry price for a Рыбалка setup.
 *
 * Entry rule:
 *   - Entry is always a limit order placed AT the liquidity level price.
 *   - For LONG setups the entry is at or just inside the lower edge of the
 *     consolidation body range (level_price = midpoint, but we enter at
 *     the range_low to improve fill probability).
 *   - For SHORT setups the entry is at or just inside the upper edge
 *     (range_high).
 *   - This keeps the entry tightly anchored to the structural level and
 *     avoids chasing price.
 *
 * The `entry_type` is always 'limit' — no market entries in v1.
 */

namespace Modules\Strategy\Fish\Logic;

final class FishEntry
{
    /**
     * Calculate the limit entry price for a setup.
     *
     * @param  string  $side       'long' | 'short'
     * @param  array   $level      Liquidity level array from FishLiquidityLevel
     * @return array{entry_price: float, entry_type: string}
     */
    public function calculate(string $side, array $level): array
    {
        $rangeHigh = (float)($level['range_high'] ?? 0.0);
        $rangeLow  = (float)($level['range_low']  ?? 0.0);

        if ($side === 'long') {
            // Enter at the bottom of the level body range (buying into the support)
            $entryPrice = $rangeLow;
        } else {
            // Enter at the top of the level body range (selling into the resistance)
            $entryPrice = $rangeHigh;
        }

        return [
            'entry_price' => round($entryPrice, 8),
            'entry_type'  => 'limit',
        ];
    }
}
