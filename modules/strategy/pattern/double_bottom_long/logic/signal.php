<?php

declare(strict_types=1);

/**
 * Pattern Strategy — Final Signal Builder
 *
 * Assembles a fully-shaped signal ONLY after every pipeline gate has passed:
 *   market_regime → trend → corridor → bucket → wave → pattern candidate
 *   → control confirmation
 *
 * signal_id is deterministic: pattern_{symbol}_{candidate_trigger_int}_{side}
 *
 * This builder does NOT emit signals on its own — it is called by PatternService
 * only when all upstream gates have confirmed.
 */

namespace Modules\Strategy\DoubleBottomLong\Logic;

final class PatternSignal
{
    /**
     * Build the final signal record.
     *
     * @param  string $symbol
     * @param  array  $candidate     Output of double_bottom/double_top detect()
     * @param  array  $confirmation  Output of PatternControlCheck::check()
     * @param  array  $pipeline      Aggregated pipeline diagnostics
     * @param  string $detectedAt    ISO-8601 timestamp
     * @param  array  $config        Effective strategy config (for SL/TP params)
     * @return array
     */
    public function build(
        string $symbol,
        array  $candidate,
        array  $confirmation,
        array  $pipeline,
        string $detectedAt,
        array  $config = []
    ): array {
        $side           = (string)($candidate['candidate_side']    ?? 'long');
        $trigger        = (float)($candidate['candidate_trigger']  ?? 0.0);
        $primaryPattern = $side === 'long' ? 'double_bottom' : 'double_top';

        // Deterministic signal ID
        $triggerInt = (int)round($trigger * 1e4);
        $signalId   = sprintf(
            'dbl_%s_%d_%s',
            strtolower($symbol),
            $triggerInt,
            $side
        );

        // ── Stop-loss geometry ────────────────────────────────────────────────
        // For double_bottom: SL just below the lower of the two lows.
        // For double_top:    SL just above the higher of the two highs.
        [$slPrice, $slPct] = $this->computeStopLoss($candidate, $trigger, $side, $config);

        // ── Take-profit geometry ──────────────────────────────────────────────
        $tpEnabled = (bool)($config['tp_enabled'] ?? true);
        $tpValue   = (float)($config['tp_value']   ?? 2.5);  // R multiple
        $tpPrice   = null;
        if ($tpEnabled && $slPrice !== null && $slPrice > 0.0) {
            $riskDistance = abs($trigger - $slPrice);
            $tpPrice = $side === 'long'
                ? round($trigger + $riskDistance * $tpValue, 8)
                : round($trigger - $riskDistance * $tpValue, 8);
        }

        return [
            // Identity
            'strategy_id'     => 'double_bottom_long',
            'signal_id'       => $signalId,
            'symbol'          => $symbol,
            'side'            => $side,

            // Entry geometry
            'entry_type'      => 'breakout',
            'entry_price'     => $trigger,
            'primary_pattern' => $primaryPattern,
            'candidate_score' => (float)($candidate['candidate_score'] ?? 0.0),

            // Stop-loss (pattern-based)
            'stop_loss_price' => $slPrice,
            'stop_loss_pct'   => $slPct !== null ? round($slPct, 6) : null,
            'stop_basis'      => 'pattern_lows',

            // Take-profit
            'tp_enabled'  => $tpEnabled,
            'tp_mode'     => (string)($config['tp_mode']  ?? 'fixed_r'),
            'tp_value'    => $tpValue,
            'tp_price'    => $tpPrice,

            // Candidate quality scores (from PatternCandidateQuality scorer)
            'pattern_score'           => (float)($pipeline['pattern_score']           ?? 0.0),
            'structure_score'         => (float)($pipeline['structure_score']         ?? 0.0),
            'neckline_score'          => (float)($pipeline['neckline_score']          ?? 0.0),
            'confirmation_score'      => (float)($pipeline['confirmation_score']      ?? 0.0),
            'context_score'           => (float)($pipeline['context_score']           ?? 0.0),
            'candidate_quality_score' => (float)($pipeline['candidate_quality_score'] ?? 0.0),
            // Quality gate outcome — always true/null here: signals are only built after quality passes
            'quality_pass'            => true,
            'quality_reject_reason'   => null,

            // Confirmation
            'confirm_status'      => $confirmation['confirm_status']      ?? 'confirm_pass',
            'confirm_bar_close'   => $confirmation['confirm_bar_close']   ?? null,
            'confirm_bars_waited' => $confirmation['confirm_bars_waited'] ?? 0,

            // Pipeline diagnostics snapshot
            'market_regime'    => $pipeline['market_regime']    ?? 'unknown',
            'trend_direction'  => $pipeline['trend_direction']  ?? 'unknown',
            'corridor_low'     => $pipeline['corridor_low']     ?? 0.0,
            'corridor_high'    => $pipeline['corridor_high']    ?? 0.0,
            'corridor_bucket'  => $pipeline['current_bucket']   ?? 0,
            'wave_direction'   => $pipeline['wave_direction']   ?? 'unknown',
            'wave_state'       => $pipeline['wave_state']       ?? 'unknown',

            // Lifecycle
            'detected_at'     => $detectedAt,
            'status'          => 'active',
            'final_signal_status' => 'emitted',
        ];
    }

    // -------------------------------------------------------------------------

    /**
     * Compute pattern-based stop-loss price and percentage from entry.
     *
     * Long:  SL = min(low1, low2) * (1 - buffer_pct)
     * Short: SL = max(high1, high2) * (1 + buffer_pct)
     *
     * @return array{0: float|null, 1: float|null}  [stop_price, stop_pct_from_entry]
     */
    private function computeStopLoss(
        array  $candidate,
        float  $entryPrice,
        string $side,
        array  $config
    ): array {
        $bufferPct = (float)($config['stop_buffer_pct_below_lows'] ?? 0.005);
        $maxSlPct  = (float)($config['max_stop_loss_pct']           ?? 0.05);

        if ($side === 'long') {
            $p1 = (float)($candidate['low1_price']  ?? 0.0);
            $p2 = (float)($candidate['low2_price']  ?? 0.0);
            if ($p1 <= 0.0 || $p2 <= 0.0 || $entryPrice <= 0.0) {
                return [null, null];
            }
            $stopRef  = min($p1, $p2);
            $slPrice  = round($stopRef * (1.0 - $bufferPct), 8);
            $slPct    = ($entryPrice - $slPrice) / $entryPrice;
        } else {
            $p1 = (float)($candidate['high1_price'] ?? 0.0);
            $p2 = (float)($candidate['high2_price'] ?? 0.0);
            if ($p1 <= 0.0 || $p2 <= 0.0 || $entryPrice <= 0.0) {
                return [null, null];
            }
            $stopRef = max($p1, $p2);
            $slPrice = round($stopRef * (1.0 + $bufferPct), 8);
            $slPct   = ($slPrice - $entryPrice) / $entryPrice;
        }

        // If the natural SL distance exceeds the configured max, return null
        // (the signal_filter in service.php will later use max_stop_loss_pct to reject)
        if ($maxSlPct > 0.0 && $slPct > $maxSlPct) {
            return [null, null];
        }

        return [$slPrice, $slPct];
    }
}
