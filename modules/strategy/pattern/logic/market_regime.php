<?php

declare(strict_types=1);

/**
 * Pattern Strategy — Market Regime Engine
 *
 * Computes a simple deterministic market regime from the scanned symbol
 * universe.  Each symbol contributes a directional vote (bullish / bearish /
 * flat) based on its H4 close-vs-open ratio over the lookback window.
 *
 * Outputs one of: bullish | bearish | mixed | transition
 *
 * History records (written to market_regime_history.ndjson):
 *   { ts, previous_regime, current_regime, bull_count, bear_count,
 *     flat_count, regime_changed }
 */

namespace Modules\Strategy\Pattern\Logic;

final class PatternMarketRegime
{
    /**
     * Thresholds (as fractions of total symbols).
     * Majority = > MAJORITY_THRESHOLD of total.
     * Transition = neither majority bullish nor majority bearish but bull/bear spread is small.
     */
    private const MAJORITY_THRESHOLD  = 0.55;  // 55% needed for a directional majority
    private const TRANSITION_SPREAD   = 0.10;  // bull / bear spread < 10% → transition

    /**
     * Compute regime from a universe of candle summaries.
     *
     * @param  array  $symbolSummaries   [ ['symbol' => ..., 'trend' => 'bullish'|'bearish'|'flat'], ... ]
     * @param  string $previousRegime    Previous regime string (for change detection)
     * @return array  { regime, bull_count, bear_count, flat_count, total,
     *                  regime_changed, previous_regime, ts }
     */
    public function compute(array $symbolSummaries, string $previousRegime = 'unknown'): array
    {
        $total = count($symbolSummaries);
        $bull  = 0;
        $bear  = 0;
        $flat  = 0;

        foreach ($symbolSummaries as $s) {
            $trend = (string)($s['trend'] ?? 'flat');
            if ($trend === 'bullish') {
                $bull++;
            } elseif ($trend === 'bearish') {
                $bear++;
            } else {
                $flat++;
            }
        }

        $regime = $this->classify($total, $bull, $bear);

        return [
            'regime'          => $regime,
            'bull_count'      => $bull,
            'bear_count'      => $bear,
            'flat_count'      => $flat,
            'total'           => $total,
            'regime_changed'  => ($regime !== $previousRegime),
            'previous_regime' => $previousRegime,
            'ts'              => date('c'),
        ];
    }

    /**
     * Classify regime from vote counts.
     */
    private function classify(int $total, int $bull, int $bear): string
    {
        if ($total === 0) {
            return 'mixed';
        }

        $bullRatio = $bull / $total;
        $bearRatio = $bear / $total;

        if ($bullRatio >= self::MAJORITY_THRESHOLD) {
            return 'bullish';
        }
        if ($bearRatio >= self::MAJORITY_THRESHOLD) {
            return 'bearish';
        }

        // Neither side has a majority — is it a tight battle (transition) or genuinely mixed?
        $spread = abs($bullRatio - $bearRatio);
        if ($spread < self::TRANSITION_SPREAD) {
            return 'transition';
        }

        return 'mixed';
    }

    /**
     * Decide whether the regime passes the gate for a given side and gate_mode.
     *
     * @param  string $regime     Current regime
     * @param  string $side       'long' | 'short'
     * @param  string $gateMode   'soft' | 'hard'
     * @return array  { pass: bool, reason: string }
     */
    public function gate(string $regime, string $side, string $gateMode): array
    {
        // soft mode: always passes but records a warning
        if ($gateMode === 'soft') {
            return ['pass' => true, 'reason' => 'soft_gate_always_pass'];
        }

        // hard mode: require regime alignment
        if ($side === 'long' && $regime === 'bullish') {
            return ['pass' => true,  'reason' => 'regime_bullish_long_ok'];
        }
        if ($side === 'short' && $regime === 'bearish') {
            return ['pass' => true,  'reason' => 'regime_bearish_short_ok'];
        }
        if ($regime === 'mixed' || $regime === 'transition') {
            return ['pass' => false, 'reason' => "regime_{$regime}_hard_block"];
        }
        return ['pass' => false, 'reason' => "regime_mismatch_{$regime}_{$side}"];
    }
}
