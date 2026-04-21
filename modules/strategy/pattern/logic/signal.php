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

namespace Modules\Strategy\Pattern\Logic;

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
     * @return array
     */
    public function build(
        string $symbol,
        array  $candidate,
        array  $confirmation,
        array  $pipeline,
        string $detectedAt
    ): array {
        $side           = (string)($candidate['candidate_side']    ?? 'long');
        $trigger        = (float)($candidate['candidate_trigger']  ?? 0.0);
        $primaryPattern = $side === 'long' ? 'double_bottom' : 'double_top';

        // Deterministic signal ID
        $triggerInt = (int)round($trigger * 1e4);
        $signalId   = sprintf(
            'pattern_%s_%d_%s',
            strtolower($symbol),
            $triggerInt,
            $side
        );

        return [
            // Identity
            'strategy_id'     => 'pattern',
            'signal_id'       => $signalId,
            'symbol'          => $symbol,
            'side'            => $side,

            // Entry geometry
            'entry_type'      => 'breakout',
            'entry_price'     => $trigger,
            'primary_pattern' => $primaryPattern,
            'candidate_score' => (float)($candidate['candidate_score'] ?? 0.0),

            // Candidate quality scores (from PatternCandidateQuality scorer)
            'pattern_score'           => (float)($pipeline['pattern_score']           ?? 0.0),
            'structure_score'         => (float)($pipeline['structure_score']         ?? 0.0),
            'neckline_score'          => (float)($pipeline['neckline_score']          ?? 0.0),
            'confirmation_score'      => (float)($pipeline['confirmation_score']      ?? 0.0),
            'context_score'           => (float)($pipeline['context_score']           ?? 0.0),
            'candidate_quality_score' => (float)($pipeline['candidate_quality_score'] ?? 0.0),

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
}
