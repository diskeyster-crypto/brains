<?php

declare(strict_types=1);

/**
 * Fish Strategy — Structure Analysis (H4)
 *
 * Detects swing highs and swing lows on an H4 candle series.
 * Determines the current trend direction from the most recent confirmed swing pair.
 *
 * True extremum rule:
 *   - A swing HIGH at index i is confirmed if the HIGH[i] is greater than
 *     the HIGH of every bar within [i - pivot_window .. i - 1] and [i + 1 .. i + pivot_window].
 *   - A swing LOW at index i is confirmed symmetrically using LOW values.
 *   - `pivot_window` is configurable via `structure_pivot_window`.
 *
 * Trend direction:
 *   - BULLISH:  the most recent confirmed swing low is higher than the previous swing low
 *               AND the most recent confirmed swing high is higher than the previous swing high.
 *   - BEARISH:  the most recent confirmed swing high is lower than the previous swing high
 *               AND the most recent confirmed swing low is lower than the previous swing low.
 *   - RANGING / UNKNOWN:  mixed or insufficient swings.
 *
 * Candle format (each bar is an indexed array):
 *   [0] open_time  (Unix ms)
 *   [1] open       (string/float)
 *   [2] high       (string/float)
 *   [3] low        (string/float)
 *   [4] close      (string/float)
 *   [5] volume     (string/float)
 *   [6] turnover   (string/float, optional)
 *
 * The candle array is expected to be ordered oldest → newest.
 */

namespace Modules\Strategy\Fish\Logic;

final class FishStructure
{
    /**
     * Analyse the candle series and return the trend structure result.
     *
     * @param  array  $candles      Ordered array of H4 candles (oldest first)
     * @param  int    $pivotWindow  Bars on each side required for a confirmed swing
     * @return array{
     *     trend_direction:    string,
     *     swing_highs:        list<array{index:int,price:float,time:int}>,
     *     swing_lows:         list<array{index:int,price:float,time:int}>,
     *     last_swing_high:    float|null,
     *     last_swing_low:     float|null,
     *     prev_swing_high:    float|null,
     *     prev_swing_low:     float|null,
     *     candle_count:       int,
     *     valid:              bool,
     *     reject_reason:      string|null
     * }
     */
    public function analyse(array $candles, int $pivotWindow): array
    {
        $count = count($candles);

        // Need enough bars to have at least one confirmed swing on each side
        $minBars = $pivotWindow * 2 + 1;
        if ($count < $minBars) {
            return $this->invalid('insufficient_candles', $count);
        }

        $swingHighs = $this->findSwings($candles, $pivotWindow, 'high');
        $swingLows  = $this->findSwings($candles, $pivotWindow, 'low');

        $trend = $this->determineTrend($swingHighs, $swingLows);

        $lastHigh = count($swingHighs) >= 1 ? $swingHighs[count($swingHighs) - 1]['price'] : null;
        $prevHigh = count($swingHighs) >= 2 ? $swingHighs[count($swingHighs) - 2]['price'] : null;
        $lastLow  = count($swingLows)  >= 1 ? $swingLows[count($swingLows)  - 1]['price'] : null;
        $prevLow  = count($swingLows)  >= 2 ? $swingLows[count($swingLows)  - 2]['price'] : null;

        return [
            'trend_direction' => $trend,
            'swing_highs'     => $swingHighs,
            'swing_lows'      => $swingLows,
            'last_swing_high' => $lastHigh,
            'last_swing_low'  => $lastLow,
            'prev_swing_high' => $prevHigh,
            'prev_swing_low'  => $prevLow,
            'candle_count'    => $count,
            'valid'           => $trend !== 'unknown',
            'reject_reason'   => $trend === 'unknown' ? 'no_clear_trend' : null,
        ];
    }

    /**
     * Find confirmed swing extrema (highs or lows) in the candle series.
     * Only bars that have enough confirmed bars on BOTH sides are tested.
     * The last `pivot_window` bars cannot be confirmed yet (no right-side bars).
     *
     * @param  array   $candles     Candle series (oldest first)
     * @param  int     $window      Pivot window size (bars on each side)
     * @param  string  $type        'high' or 'low'
     * @return list<array{index:int,price:float,time:int}>
     */
    private function findSwings(array $candles, int $window, string $type): array
    {
        $count   = count($candles);
        $swings  = [];
        $colIdx  = ($type === 'high') ? 2 : 3;  // Bybit kline: [0]=time [1]=open [2]=high [3]=low [4]=close

        for ($i = $window; $i < $count - $window; $i++) {
            $price = (float)$candles[$i][$colIdx];

            $isExtremum = true;
            for ($j = $i - $window; $j < $i; $j++) {
                $compare = (float)$candles[$j][$colIdx];
                if ($type === 'high' && $compare >= $price) {
                    $isExtremum = false;
                    break;
                }
                if ($type === 'low' && $compare <= $price) {
                    $isExtremum = false;
                    break;
                }
            }

            if (!$isExtremum) {
                continue;
            }

            for ($j = $i + 1; $j <= $i + $window; $j++) {
                $compare = (float)$candles[$j][$colIdx];
                if ($type === 'high' && $compare >= $price) {
                    $isExtremum = false;
                    break;
                }
                if ($type === 'low' && $compare <= $price) {
                    $isExtremum = false;
                    break;
                }
            }

            if ($isExtremum) {
                $swings[] = [
                    'index' => $i,
                    'price' => $price,
                    'time'  => (int)($candles[$i][0] ?? 0),
                ];
            }
        }

        return $swings;
    }

    /**
     * Determine trend direction from confirmed swing highs and lows.
     *
     * @param  list<array>  $swingHighs
     * @param  list<array>  $swingLows
     * @return string  'bullish' | 'bearish' | 'ranging' | 'unknown'
     */
    private function determineTrend(array $swingHighs, array $swingLows): string
    {
        if (count($swingHighs) < 2 || count($swingLows) < 2) {
            return 'unknown';
        }

        $highCount = count($swingHighs);
        $lowCount  = count($swingLows);

        $lastHigh = $swingHighs[$highCount - 1]['price'];
        $prevHigh = $swingHighs[$highCount - 2]['price'];
        $lastLow  = $swingLows[$lowCount  - 1]['price'];
        $prevLow  = $swingLows[$lowCount  - 2]['price'];

        $higherHighs = $lastHigh > $prevHigh;
        $higherLows  = $lastLow  > $prevLow;
        $lowerHighs  = $lastHigh < $prevHigh;
        $lowerLows   = $lastLow  < $prevLow;

        if ($higherHighs && $higherLows) {
            return 'bullish';
        }
        if ($lowerHighs && $lowerLows) {
            return 'bearish';
        }
        return 'ranging';
    }

    /**
     * Return a "not valid" result with a reject reason.
     */
    private function invalid(string $reason, int $count = 0): array
    {
        return [
            'trend_direction' => 'unknown',
            'swing_highs'     => [],
            'swing_lows'      => [],
            'last_swing_high' => null,
            'last_swing_low'  => null,
            'prev_swing_high' => null,
            'prev_swing_low'  => null,
            'candle_count'    => $count,
            'valid'           => false,
            'reject_reason'   => $reason,
        ];
    }
}
