<?php

declare(strict_types=1);

/**
 * Corridor Bottom Long — Validation Engine
 *
 * State machine:  waiting_validation → enter | reject
 *
 * Scoring criteria (max 5 points):
 *   A. Holding low    — price has NOT broken the corridor low  → +2 (or immediate reject)
 *   B. Seller exhaust — lows stop updating, volatility falling → +1
 *   C. Micro reversal — higher low or small upward move        → +1
 *   D. No fast dump   — no strong downward impulse             → +1
 *
 * Input price_history format (newest-last):
 *   [ ['ts' => int, 'price' => float], … ]
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
     * Evaluate a candidate against recent price history.
     *
     * @param  array  $candidate     Candidate record (see header).
     * @param  array  $priceHistory  Tick/candle history for the symbol (oldest → newest).
     * @param  array  $config        Effective strategy config.
     * @param  int    $now           Current Unix timestamp.
     * @return array  {
     *   decision: 'waiting'|'enter'|'reject',
     *   score: int,
     *   reasons: string[],
     *   reject_reason: string|null,
     * }
     */
    public function evaluate(
        array $candidate,
        array $priceHistory,
        array $config,
        int   $now
    ): array {
        $detectedAt     = (int)($candidate['detected_at'] ?? $now);
        $lowPrice       = (float)($candidate['low_price'] ?? 0.0);
        $windowSeconds  = (int)($config['validation_window_seconds'] ?? 240);
        $minScore       = (int)($config['validation_min_score'] ?? 3);
        $allowNewLow    = (bool)($config['allow_new_low'] ?? false);
        $maxNewLowPct   = (float)($config['max_new_low_pct'] ?? 0.2);
        $elapsed        = $now - $detectedAt;

        // ── Still inside validation window → keep waiting ───────────────────
        if ($elapsed < $windowSeconds) {
            return $this->result('waiting', 0, ['inside_validation_window'], null);
        }

        // ── Collect price ticks that occurred after detection ────────────────
        $recentTicks = $this->ticksAfter($priceHistory, $detectedAt);

        if (empty($recentTicks)) {
            // No data yet — reject (cannot validate without evidence)
            return $this->result('reject', 0, ['no_price_data_after_detection'], 'no_data');
        }

        $prices = array_column($recentTicks, 'price');
        $minP   = min($prices);
        $maxP   = max($prices);
        $lastP  = (float)end($prices);
        $firstP = (float)reset($prices);
        $count  = count($prices);

        // ── A. Holding low ───────────────────────────────────────────────────
        $newLowThreshold = $lowPrice * (1 - $maxNewLowPct / 100);
        if ($minP < $newLowThreshold) {
            if (!$allowNewLow) {
                // Hard reject — new low was made
                return $this->result('reject', 0, ['new_low_broken'], 'new_low_broken');
            }
        }

        $score   = 0;
        $reasons = [];

        if ($minP >= $newLowThreshold) {
            // Price held above low → strong signal
            $score   += 2;
            $reasons[] = 'holding_low';
        } else {
            // New low occurred but allow_new_low is true
            $reasons[] = 'new_low_allowed';
        }

        // ── B. Seller exhaustion ─────────────────────────────────────────────
        // Lows stop updating → last third of ticks has higher lows than first third
        // Volatility decreasing → std-dev of last half < std-dev of first half
        if ($count >= 4) {
            $half    = (int)floor($count / 2);
            $firstHalf = array_slice($prices, 0, $half);
            $secondHalf = array_slice($prices, $half);

            $firstLows  = min($firstHalf);
            $secondLows = min($secondHalf);
            $lowsRising = $secondLows > $firstLows;

            $volFirst  = $this->stddev($firstHalf);
            $volSecond = $this->stddev($secondHalf);
            $volFalling = $volSecond < $volFirst;

            if ($lowsRising && $volFalling) {
                $score   += 1;
                $reasons[] = 'seller_exhaustion';
            }
        }

        // ── C. Micro reversal ────────────────────────────────────────────────
        // Higher low: last price > first price (upward drift)
        // OR last price at least 0.1% above the minimum observed
        $upliftPct = $lowPrice > 0 ? (($lastP - $minP) / $lowPrice) * 100 : 0;
        if ($lastP > $firstP || $upliftPct >= 0.1) {
            $score   += 1;
            $reasons[] = 'micro_reversal';
        }

        // ── D. No fast dump ──────────────────────────────────────────────────
        // Detect a strong downward impulse: any single step > 0.5% drop
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
        }

        // ── Decision ─────────────────────────────────────────────────────────
        if ($score >= $minScore) {
            return $this->result('enter', $score, $reasons, null);
        }

        return $this->result('reject', $score, $reasons, 'score_below_minimum');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Return only ticks whose 'ts' is >= $since.
     *
     * @param  array  $history  [ ['ts' => int, 'price' => float], … ]
     * @param  int    $since
     * @return array
     */
    private function ticksAfter(array $history, int $since): array
    {
        return array_values(
            array_filter($history, fn(array $t) => (int)($t['ts'] ?? 0) >= $since)
        );
    }

    /**
     * Population standard deviation of a flat array of floats.
     * Returns 0.0 when the array has fewer than 2 elements.
     */
    private function stddev(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }
        $mean = array_sum($values) / $n;
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
        int     $score,
        array   $reasons,
        ?string $rejectReason
    ): array {
        return [
            'decision'      => $decision,
            'score'         => $score,
            'reasons'       => $reasons,
            'reject_reason' => $rejectReason,
        ];
    }
}
