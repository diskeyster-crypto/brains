<?php

declare(strict_types=1);

/**
 * Pattern Strategy — Control Confirmation Layer
 *
 * After a valid pattern candidate is found this layer runs mandatory
 * confirmation before a final signal is emitted.
 *
 * confirm_mode = candle_confirmation:
 *   Wait for a candle that closes beyond the candidate_trigger price.
 *   Long:  a candle must close ABOVE the trigger (neckline breakout).
 *   Short: a candle must close BELOW the trigger (neckline breakdown).
 *
 * Outputs one of:
 *   confirm_pass      — confirmation bar has appeared; signal may be emitted
 *   confirm_waiting   — within max bars window, no confirming bar yet
 *   confirm_failed    — max bars elapsed with no confirmation
 *   candidate_expired — the candidate itself is no longer valid (stale)
 */

namespace Modules\Strategy\DoubleBottomLong\Logic;

final class PatternControlCheck
{
    /**
     * Run confirmation check.
     *
     * @param  array  $candidate  Output of double_bottom/double_top detect()
     * @param  array  $candles    H4 candles oldest → newest
     * @param  int    $candidateBarIdx  Index in $candles where the candidate was first found
     * @param  array  $config     Effective strategy config
     * @return array  { confirm_status, confirm_pass, confirm_bars_waited,
     *                  candidate_expired, confirm_bar_close, reject_reason }
     */
    public function check(array $candidate, array $candles, int $candidateBarIdx, array $config): array
    {
        $maxBars  = (int)($config['confirm_max_bars'] ?? 2);
        $ttlBars  = (int)($config['signal_ttl_bars']  ?? 2);
        $mode     = (string)($config['confirm_mode']  ?? 'candle_confirmation');

        if (!(bool)($candidate['candidate_found'] ?? false)) {
            return $this->result('candidate_expired', false, 0, true, null, 'no_candidate');
        }

        $trigger  = (float)($candidate['candidate_trigger'] ?? 0.0);
        $side     = (string)($candidate['candidate_side']   ?? 'long');
        $n        = count($candles);

        // How many bars have passed since the candidate bar
        $barsWaited = max(0, ($n - 1) - $candidateBarIdx);

        // Candidate expired if beyond TTL
        if ($barsWaited > $ttlBars) {
            return $this->result('candidate_expired', false, $barsWaited, true, null, 'candidate_ttl_exceeded');
        }

        // Exceeded max confirmation window
        if ($barsWaited > $maxBars) {
            return $this->result('confirm_failed', false, $barsWaited, false, null, 'confirm_max_bars_exceeded');
        }

        if ($mode === 'candle_confirmation') {
            // Scan bars from candidateBarIdx+1 onward for a confirming close
            for ($i = $candidateBarIdx + 1; $i < $n; $i++) {
                $close = (float)($candles[$i]['close'] ?? 0.0);
                if ($side === 'long'  && $close > $trigger) {
                    return $this->result('confirm_pass', true, $barsWaited, false, $close, null);
                }
                if ($side === 'short' && $close < $trigger) {
                    return $this->result('confirm_pass', true, $barsWaited, false, $close, null);
                }
            }
            // Still within window but no confirming bar yet
            return $this->result('confirm_waiting', false, $barsWaited, false, null, 'waiting_for_confirm_bar');
        }

        // Unknown mode — fail safe
        return $this->result('confirm_failed', false, $barsWaited, false, null, "unknown_confirm_mode_{$mode}");
    }

    // -------------------------------------------------------------------------

    private function result(
        string $status,
        bool   $pass,
        int    $barsWaited,
        bool   $expired,
        ?float $confirmBarClose,
        ?string $rejectReason
    ): array {
        return [
            'confirm_status'    => $status,
            'confirm_pass'      => $pass,
            'confirm_bars_waited' => $barsWaited,
            'candidate_expired' => $expired,
            'confirm_bar_close' => $confirmBarClose,
            'reject_reason'     => $rejectReason,
        ];
    }
}
