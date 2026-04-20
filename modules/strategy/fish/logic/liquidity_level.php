<?php

declare(strict_types=1);

/**
 * Fish Strategy — Liquidity Level Detector
 *
 * Detects 3–4 bar consolidation patterns ("liquidity levels") on H4.
 * A liquidity level is a tight cluster of bars where the market is
 * accumulating unfilled orders — a structural setup for Рыбалка.
 *
 * Consolidation rule:
 *   A group of consecutive bars (min_bars..max_bars) qualifies as a
 *   liquidity level when ALL bar bodies (open and close) fall within a
 *   price range that is no wider than (level_midpoint * tolerance).
 *   The wicks are allowed to extend outside — only bodies are measured.
 *
 * Confirming bar rule (optional, config-driven):
 *   If `confirm_bar_required = true`, the pattern is only valid when the
 *   bar immediately AFTER the consolidation closes OUTSIDE the body range.
 *   This confirms that the level was tested/swept and is now structural.
 *
 * Level age:
 *   A level is "live" for up to `level_max_age_bars` bars after its
 *   confirming bar (or its last consolidation bar when confirm is off).
 *   Older levels are returned with `status = 'expired'`.
 *
 * Output level fields:
 *   level_price   — midpoint of the consolidation body range
 *   range_high    — upper bound of the consolidation body range
 *   range_low     — lower bound of the consolidation body range
 *   range_size    — range_high - range_low (used for TP/BE calculation)
 *   bar_start_idx — first bar of the consolidation (candle array index)
 *   bar_end_idx   — last bar of the consolidation
 *   confirm_idx   — index of the confirming bar (or null)
 *   age_bars      — how many bars ago the level was confirmed
 *   status        — 'valid' | 'unconfirmed' | 'expired'
 *   bar_count     — number of bars in the consolidation
 */

namespace Modules\Strategy\Fish\Logic;

final class FishLiquidityLevel
{
    /**
     * Scan the candle series for all liquidity levels.
     * Levels are returned newest-first so callers can take the best one.
     *
     * @param  array  $candles         H4 candle series (oldest first)
     * @param  int    $minBars         Minimum consolidation bar count
     * @param  int    $maxBars         Maximum consolidation bar count
     * @param  float  $tolerance       Body range tolerance as a fraction of price
     * @param  bool   $confirmRequired Require a confirming bar after the pattern
     * @param  int    $maxAgeBars      Bars after which a level is expired
     * @return list<array>             Detected levels (newest first)
     */
    public function detect(
        array $candles,
        int   $minBars,
        int   $maxBars,
        float $tolerance,
        bool  $confirmRequired,
        int   $maxAgeBars
    ): array {
        $count  = count($candles);
        $levels = [];

        // Walk the candle series looking for consolidation windows.
        // We scan from oldest to newest and keep every valid pattern found.
        // Overlap is allowed — the service layer picks the best candidate.
        for ($i = 0; $i < $count - $minBars; $i++) {
            for ($len = $minBars; $len <= $maxBars && ($i + $len) <= $count; $len++) {
                $window = array_slice($candles, $i, $len);
                $range  = $this->bodyRange($window);

                if ($range === null) {
                    break;  // degenerate bar (zero price), skip
                }

                [$rangeHigh, $rangeLow] = $range;
                $midpoint  = ($rangeHigh + $rangeLow) / 2.0;

                if ($midpoint <= 0.0) {
                    break;
                }

                $rangeSize  = $rangeHigh - $rangeLow;
                $maxAllowed = $midpoint * $tolerance;

                if ($rangeSize > $maxAllowed) {
                    // Range too wide — no point trying longer windows from this start
                    break;
                }

                // Consolidation body range passes tolerance — check confirming bar
                $confirmIdx = $i + $len;  // index of the bar right after the pattern
                $confirmStatus = 'unconfirmed';
                $ageAnchorIdx  = $i + $len - 1;  // last bar of the pattern

                if ($confirmRequired) {
                    if ($confirmIdx >= $count) {
                        // No confirming bar exists yet — level is not valid
                        $confirmStatus = 'no_confirm_bar';
                    } else {
                        $confirmBar = $candles[$confirmIdx];
                        if ($this->isConfirmingBar($confirmBar, $rangeHigh, $rangeLow)) {
                            $confirmStatus = 'confirmed';
                            $ageAnchorIdx  = $confirmIdx;
                        } else {
                            $confirmStatus = 'confirm_failed';
                        }
                    }
                } else {
                    $confirmStatus = 'confirmed';
                }

                if ($confirmStatus !== 'confirmed') {
                    continue;
                }

                // Calculate level age (bars elapsed since the age anchor)
                $ageBars = ($count - 1) - $ageAnchorIdx;
                $status  = $ageBars <= $maxAgeBars ? 'valid' : 'expired';

                $levels[] = [
                    'level_price'    => round($midpoint, 8),
                    'range_high'     => round($rangeHigh, 8),
                    'range_low'      => round($rangeLow, 8),
                    'range_size'     => round($rangeSize, 8),
                    'bar_start_idx'  => $i,
                    'bar_end_idx'    => $i + $len - 1,
                    'confirm_idx'    => $confirmRequired ? $confirmIdx : null,
                    'age_bars'       => $ageBars,
                    'status'         => $status,
                    'bar_count'      => $len,
                ];
            }
        }

        // Sort newest first (lowest age first)
        usort($levels, static fn($a, $b) => $a['age_bars'] <=> $b['age_bars']);

        return $levels;
    }

    /**
     * Compute the body range (max open/close, min open/close) across a window.
     * Returns [rangeHigh, rangeLow] or null if any bar has degenerate prices.
     *
     * @param  array  $window  Slice of candle bars
     * @return array{float,float}|null
     */
    private function bodyRange(array $window): ?array
    {
        $high = -INF;
        $low  = INF;

        foreach ($window as $bar) {
            $open  = (float)($bar[1] ?? 0);
            $close = (float)($bar[4] ?? 0);

            if ($open <= 0.0 || $close <= 0.0) {
                return null;
            }

            $high = max($high, $open, $close);
            $low  = min($low,  $open, $close);
        }

        return [$high, $low];
    }

    /**
     * Check if a bar is a confirming bar for a given body range.
     * A confirming bar closes OUTSIDE the range (above rangeHigh or below rangeLow).
     *
     * @param  array  $bar        Candle bar
     * @param  float  $rangeHigh  Upper bound of the consolidation body range
     * @param  float  $rangeLow   Lower bound of the consolidation body range
     */
    private function isConfirmingBar(array $bar, float $rangeHigh, float $rangeLow): bool
    {
        $close = (float)($bar[4] ?? 0);
        return $close > $rangeHigh || $close < $rangeLow;
    }
}
