<?php

declare(strict_types=1);

/**
 * Corridor Bottom Long — Validation Engine
 *
 * State machine:  waiting_validation → enter | reject
 *
 * Scoring criteria (max 6 points):
 *   A. Holding low       — price did NOT break corridor low    → +2 (immediate reject if broken)
 *   B. Seller exhaustion — lows stop updating, volatility ↓   → +1
 *   C. Micro reversal    — higher low or small upward drift    → +1
 *   D. No fast dump      — no sharp downward impulse           → +1
 *   E. Pattern confirm   — at least one enabled pattern fires  → +1
 *
 * Separate accumulation score (0–4):
 *   - Green candles volume ≥ red candles volume                → +1
 *   - Price stopped making new lows in the window             → +1
 *   - Small upward drift from corridor low                    → +1
 *   - Lower wicks present (reclaim behaviour)                 → +1
 *
 * If accumulation_score < min_accumulation_score → reject (no_accumulation_after_dump)
 *
 * Input price_history format (oldest → newest):
 *   [ ['ts' => int, 'price' => float], … ]
 *
 * Input candles format (oldest → newest):
 *   [ ['ts'=>int, 'open'=>float, 'high'=>float, 'low'=>float,
 *      'close'=>float, 'volume'=>float], … ]
 *
 * Input candidate format:
 *   [ 'symbol' => string, 'detected_at' => int, 'low_price' => float,
 *     'current_price' => float, 'state' => 'waiting_validation' ]
 */

namespace Modules\Strategy\CorridorBottomLong\Lib;

final class ValidationEngine
{
    // ── Public entry point ────────────────────────────────────────────────────

    /**
     * Evaluate a candidate.
     *
     * @param  array  $candidate      Candidate record (see header).
     * @param  array  $priceHistory   Tick history for the symbol (oldest → newest).
     * @param  array  $candles        OHLCV candles (oldest → newest).
     * @param  array  $patternResult  Output of PatternDetector::detect() ['best'=>…,'detected'=>bool].
     * @param  array  $config         Effective strategy config.
     * @param  int    $now            Current Unix timestamp (seconds).
     * @return array  {
     *   decision:          'waiting'|'enter'|'reject',
     *   validation_score:  int,
     *   accumulation_score:int,
     *   reasons:           string[],
     *   reject_reason:     string|null,
     * }
     */
    public function evaluate(
        array $candidate,
        array $priceHistory,
        array $candles,
        array $patternResult,
        array $config,
        int   $now
    ): array {
        $detectedAt          = (int)($candidate['detected_at']  ?? $now);
        $lowPrice            = (float)($candidate['low_price']  ?? 0.0);
        $legacyWindow        = (int)($config['validation_window_seconds']  ?? 240);
        $minAgeSeconds       = (int)($config['validation_min_age_seconds'] ?? $legacyWindow);
        $maxAgeSeconds       = (int)($config['validation_max_age_seconds'] ?? ($legacyWindow + 120));
        $minScore            = (int)($config['validation_min_score']       ?? 3);
        $allowNewLow         = (bool)($config['allow_new_low']             ?? false);
        $requireNoNewLow     = (bool)($config['require_no_new_low']        ?? true);
        $requireMicroReversal= (bool)($config['require_micro_reversal']    ?? true);
        $rejectFastDump      = (bool)($config['reject_fast_dump']          ?? true);
        $maxNewLowPct        = (float)($config['max_new_low_pct']          ?? 0.2);
        $minAccumScore       = (int)($config['min_accumulation_score']     ?? 2);
        $elapsed             = $now - $detectedAt;

        // ── Too fresh — still inside minimum age window → keep waiting ────────
        if ($elapsed < $minAgeSeconds) {
            return $this->result('waiting', 0, 0, ['inside_validation_window'], null);
        }

        // ── Too stale — candidate age exceeded maximum → reject ───────────────
        if ($elapsed > $maxAgeSeconds) {
            return $this->result('reject', 0, 0, ['candidate_too_stale'], 'candidate_too_stale');
        }

        // ── Collect price ticks that occurred after detection ────────────────
        $recentTicks = $this->ticksAfter($priceHistory, $detectedAt);

        if (empty($recentTicks)) {
            return $this->result('reject', 0, 0, ['no_price_data_after_detection'], 'no_data');
        }

        $prices = array_column($recentTicks, 'price');
        $minP   = min($prices);
        $lastP  = (float)end($prices);
        $firstP = (float)reset($prices);
        $count  = count($prices);

        // ── A. Holding low ───────────────────────────────────────────────────
        $newLowThreshold = $lowPrice * (1 - $maxNewLowPct / 100);
        $hadNewLow       = $minP < $newLowThreshold;

        if ($hadNewLow) {
            if ($requireNoNewLow || !$allowNewLow) {
                return $this->result('reject', 0, 0, ['new_low_broken'], 'new_low_broken');
            }
        }

        $score   = 0;
        $reasons = [];

        if ($minP >= $newLowThreshold) {
            $score   += 2;
            $reasons[] = 'holding_low';
        } else {
            $reasons[] = 'new_low_allowed';
        }

        // ── B. Seller exhaustion ─────────────────────────────────────────────
        // Lows stop updating + volatility decreasing over the window
        if ($count >= 4) {
            $half       = (int)floor($count / 2);
            $firstHalf  = array_slice($prices, 0, $half);
            $secondHalf = array_slice($prices, $half);

            $lowsRising = min($secondHalf) > min($firstHalf);
            $volFalling = $this->stddev($secondHalf) < $this->stddev($firstHalf);

            if ($lowsRising && $volFalling) {
                $score   += 1;
                $reasons[] = 'seller_exhaustion';
            }
        }

        // ── C. Micro reversal ────────────────────────────────────────────────
        // Higher low: last price > first price OR ≥ 0.1% above observed minimum
        $upliftPct      = $lowPrice > 0 ? (($lastP - $minP) / $lowPrice) * 100 : 0.0;
        $microReversal  = ($lastP > $firstP || $upliftPct >= 0.1);
        if ($microReversal) {
            $score   += 1;
            $reasons[] = 'micro_reversal';
        } elseif ($requireMicroReversal) {
            return $this->result('reject', $score, 0, $reasons, 'no_micro_reversal');
        }

        // ── D. No fast dump ──────────────────────────────────────────────────
        // Any single step drop > 0.5% counts as a fast dump
        $fastDump = false;
        for ($i = 1; $i < $count; $i++) {
            $prev = $prices[$i - 1];
            $curr = $prices[$i];
            if ($prev > 0 && (($prev - $curr) / $prev) * 100 > 0.5) {
                $fastDump  = true;
                $reasons[] = 'fast_dump_detected';
                break;
            }
        }
        if (!$fastDump) {
            $score   += 1;
            $reasons[] = 'no_fast_dump';
        } elseif ($rejectFastDump) {
            return $this->result('reject', $score, 0, $reasons, 'fast_dump');
        }

        // ── E. Pattern confirmation ──────────────────────────────────────────
        if (!empty($patternResult['detected']) && !empty($patternResult['best']['detected'])) {
            $score   += 1;
            $reasons[] = 'pattern_confirmed:' . ($patternResult['best']['pattern_type'] ?? 'unknown');
        }

        // ── Accumulation score ────────────────────────────────────────────────
        $accumScore   = 0;
        $accumReasons = [];

        // Use recent candles (last half of the lookback window) for accumulation checks
        $recentCandles = $this->candlesAfter($candles, $detectedAt);
        if (empty($recentCandles)) {
            // Fall back to the last 10 candles
            $recentCandles = array_slice($candles, -10);
        }

        if (!empty($recentCandles)) {
            $greenCandles = array_filter($recentCandles, fn($c) => (float)($c['close'] ?? 0) >= (float)($c['open'] ?? 0));
            $redCandles   = array_filter($recentCandles, fn($c) => (float)($c['close'] ?? 0) < (float)($c['open'] ?? 0));

            // A1. Green candle volume ≥ red candle volume (buying pressure)
            $hasVolume = array_sum(array_column($recentCandles, 'volume')) > 0;
            if ($hasVolume) {
                $greenVol = array_sum(array_map(fn($c) => (float)($c['volume'] ?? 0), $greenCandles));
                $redVol   = array_sum(array_map(fn($c) => (float)($c['volume'] ?? 0), $redCandles));
                if ($greenVol >= $redVol) {
                    $accumScore   += 1;
                    $accumReasons[] = 'green_volume_dominates';
                }
            }

            // A2. Price stopped making new lows (last third of candles has higher lows)
            $cCount = count($recentCandles);
            if ($cCount >= 4) {
                $cHalf       = (int)floor($cCount / 2);
                $cFirstLows  = array_map(fn($c) => (float)($c['low'] ?? 0), array_slice($recentCandles, 0, $cHalf));
                $cSecondLows = array_map(fn($c) => (float)($c['low'] ?? 0), array_slice($recentCandles, $cHalf));
                if (min($cSecondLows) > min($cFirstLows)) {
                    $accumScore   += 1;
                    $accumReasons[] = 'lows_stopped_falling';
                }
            }

            // A3. Small upward drift: last candle close > average close of window
            $avgClose = count($recentCandles) > 0
                ? array_sum(array_map(fn($c) => (float)($c['close'] ?? 0), $recentCandles)) / count($recentCandles)
                : 0.0;
            $lastClose = (float)(end($recentCandles)['close'] ?? 0.0);
            if ($avgClose > 0 && $lastClose > $avgClose) {
                $accumScore   += 1;
                $accumReasons[] = 'price_drifting_up';
            }

            // A4. Lower wicks present: lower wick on last candle ≥ 0.1% of close
            $lastCandle    = end($recentCandles);
            $lastCandleClose = (float)($lastCandle['close'] ?? 0.0);
            $lowerWick     = (float)($lastCandle['low'] ?? 0.0) > 0
                ? $lastCandleClose - (float)($lastCandle['low'] ?? 0.0)
                : 0.0;
            if ($lastCandleClose > 0 && ($lowerWick / $lastCandleClose) * 100 >= 0.1) {
                $accumScore   += 1;
                $accumReasons[] = 'lower_wick_reclaim';
            }
        }

        $reasons = array_merge($reasons, $accumReasons);

        // Accumulation gate
        if ($accumScore < $minAccumScore) {
            return $this->result('reject', $score, $accumScore, $reasons, 'no_accumulation_after_dump');
        }

        // ── Final decision ────────────────────────────────────────────────────
        if ($score >= $minScore) {
            return $this->result('enter', $score, $accumScore, $reasons, null);
        }

        return $this->result('reject', $score, $accumScore, $reasons, 'score_below_minimum');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Return only ticks whose 'ts' (seconds) is >= $since.
     */
    private function ticksAfter(array $history, int $since): array
    {
        return array_values(
            array_filter($history, fn(array $t) => (int)($t['ts'] ?? 0) >= $since)
        );
    }

    /**
     * Return only candles whose 'ts' (seconds) is >= $since.
     */
    private function candlesAfter(array $candles, int $since): array
    {
        return array_values(
            array_filter($candles, fn(array $c) => (int)($c['ts'] ?? 0) >= $since)
        );
    }

    /**
     * Population standard deviation. Returns 0.0 for fewer than 2 elements.
     */
    private function stddev(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }
        $mean  = array_sum($values) / $n;
        $sumSq = 0.0;
        foreach ($values as $v) {
            $sumSq += ($v - $mean) ** 2;
        }
        return sqrt($sumSq / $n);
    }

    /**
     * Build a normalised result array.
     */
    private function result(
        string  $decision,
        int     $validationScore,
        int     $accumScore,
        array   $reasons,
        ?string $rejectReason
    ): array {
        return [
            'decision'           => $decision,
            'validation_score'   => $validationScore,
            'accumulation_score' => $accumScore,
            'reasons'            => $reasons,
            'reject_reason'      => $rejectReason,
        ];
    }
}

