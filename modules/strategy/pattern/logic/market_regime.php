<?php

declare(strict_types=1);

/**
 * Pattern Strategy — Market Regime Engine
 *
 * Computes a deterministic market regime from the scanned symbol universe.
 * Each symbol contributes a directional vote (bullish / bearish / flat /
 * unknown) based on its H4 trend result.
 *
 * Final regime values:
 *   bullish    — bull side clearly dominates (ratio >= dominance_ratio)
 *   bearish    — bear side clearly dominates (ratio >= dominance_ratio)
 *   flat       — flat symbols dominate; directional signals are minor
 *   transition — previous regime was directional and that direction has
 *                materially dropped below the dominance threshold
 *   mixed      — both bull and bear are materially present, no dominance
 *   unknown    — insufficient data for classification
 *
 * IMPORTANT: `transition` is ONLY emitted when the previous regime was
 * strictly directional (bullish or bearish) and the current directional
 * composition has dropped significantly below dominance.
 * It is NOT a fallback for "flat and nothing special".
 *
 * Classification order (first match wins):
 *   1. unknown    — total samples < min_sample_count
 *   2. bullish    — bull_ratio >= dominance_ratio
 *   3. bearish    — bear_ratio >= dominance_ratio
 *   4. flat       — flat_ratio >= flat_dominance_ratio (before transition,
 *                   so a fully-flat market is not called "transitioning")
 *   5. transition — previous was bullish/bearish AND that direction's ratio
 *                   dropped below (dominance_ratio - transition_flip_threshold)
 *   6. mixed      — both bull_ratio and bear_ratio >= mixed_min_directional_ratio
 *   7. flat       — fallback when directional signals are too weak for "mixed"
 *
 * Diagnostics persisted to storage/market_regime.json and history ndjson:
 *   regime, previous_regime, regime_changed, regime_reason,
 *   bull_count, bear_count, flat_count, unknown_count,
 *   total, total_count_used, bull_ratio, bear_ratio, flat_ratio, ts
 */

namespace Modules\Strategy\Pattern\Logic;

final class PatternMarketRegime
{
    // ── Default thresholds (all overridable via $options in compute()) ────────

    /** Minimum symbol samples required for any non-unknown classification. */
    private const MIN_SAMPLE_COUNT = 5;

    /** Fraction of directional symbols required for bull/bear dominance. */
    private const DOMINANCE_RATIO = 0.55;

    /** Fraction of classified symbols required for flat dominance. */
    private const FLAT_DOMINANCE_RATIO = 0.65;

    /**
     * How far below DOMINANCE_RATIO the previously-dominant direction must drop
     * to trigger a transition signal.
     * transition_floor = dominance_ratio - transition_flip_threshold
     */
    private const TRANSITION_FLIP_THRESHOLD = 0.20;

    /** Minimum ratio for bull AND bear each to count as "materially present" (mixed). */
    private const MIXED_MIN_DIRECTIONAL_RATIO = 0.10;

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Compute regime from a universe of per-symbol trend summaries.
     *
     * @param  array  $symbolSummaries  [['symbol'=>..., 'trend'=>'bullish'|'bearish'|'flat'|'unknown'], ...]
     * @param  string $previousRegime   Previously persisted regime (for transition detection)
     * @param  array  $options          Optional threshold overrides from config:
     *                                    market_regime_min_sample_count       (int)
     *                                    market_regime_dominance_ratio        (float)
     *                                    market_regime_flat_dominance_ratio   (float)
     *                                    market_regime_transition_flip_threshold (float)
     * @return array
     */
    public function compute(array $symbolSummaries, string $previousRegime = 'unknown', array $options = []): array
    {
        $minSample      = (int)(  $options['market_regime_min_sample_count']        ?? self::MIN_SAMPLE_COUNT);
        $dominanceRatio = (float)($options['market_regime_dominance_ratio']         ?? self::DOMINANCE_RATIO);
        $flatDominance  = (float)($options['market_regime_flat_dominance_ratio']    ?? self::FLAT_DOMINANCE_RATIO);
        $transitionFlip = (float)($options['market_regime_transition_flip_threshold'] ?? self::TRANSITION_FLIP_THRESHOLD);

        $bull    = 0;
        $bear    = 0;
        $flat    = 0;
        $unknown = 0;

        foreach ($symbolSummaries as $s) {
            match ((string)($s['trend'] ?? 'unknown')) {
                'bullish' => $bull++,
                'bearish' => $bear++,
                'flat'    => $flat++,
                default   => $unknown++,
            };
        }

        // Ratios are computed over classified symbols only (unknowns excluded),
        // so a batch full of 'unknown' trends doesn't dilute the directional picture.
        $total     = $bull + $bear + $flat + $unknown;
        $countUsed = $bull + $bear + $flat;
        $ratioBase = max($countUsed, 1);

        $bullRatio = $bull / $ratioBase;
        $bearRatio = $bear / $ratioBase;
        $flatRatio = $flat / $ratioBase;

        [$regime, $reason] = $this->classify(
            $total, $bull, $bear, $flat, $unknown,
            $bullRatio, $bearRatio, $flatRatio,
            $previousRegime,
            $minSample, $dominanceRatio, $flatDominance, $transitionFlip
        );

        // No-data unknown states (no classified symbols / insufficient sample) are
        // temporary data gaps, not meaningful market regime flips.  Suppress the
        // regime_changed flag so callers don't treat each empty tick as a real
        // transition event.
        $noDataUnknown = ($regime === 'unknown' &&
            in_array($reason, ['no_classified_symbols', 'insufficient_data'], true));

        return [
            'regime'           => $regime,
            'previous_regime'  => $previousRegime,
            'regime_changed'   => !$noDataUnknown && ($regime !== $previousRegime),
            'regime_reason'    => $reason,
            'bull_count'       => $bull,
            'bear_count'       => $bear,
            'flat_count'       => $flat,
            'unknown_count'    => $unknown,
            'total'            => $total,
            'total_count_used' => $countUsed,
            'bull_ratio'       => round($bullRatio, 4),
            'bear_ratio'       => round($bearRatio, 4),
            'flat_ratio'       => round($flatRatio, 4),
            'ts'               => date('c'),
        ];
    }

    /**
     * Deterministic 7-step regime classifier.
     *
     * @return array{0: string, 1: string}  [regime, reason]
     */
    private function classify(
        int    $total,
        int    $bull,
        int    $bear,
        int    $flat,
        int    $unknown,
        float  $bullRatio,
        float  $bearRatio,
        float  $flatRatio,
        string $previousRegime,
        int    $minSample,
        float  $dominanceRatio,
        float  $flatDominance,
        float  $transitionFlip
    ): array {
        // 1. Not enough data
        if ($total < $minSample) {
            return ['unknown', 'insufficient_data'];
        }

        // 1b. All symbols are unclassified (unknown) — nothing to classify against
        $countUsedLocal = $bull + $bear + $flat;
        if ($countUsedLocal === 0) {
            return ['unknown', 'no_classified_symbols'];
        }

        // 2. Bull clearly dominates
        if ($bullRatio >= $dominanceRatio) {
            return ['bullish', 'bull_dominance'];
        }

        // 3. Bear clearly dominates
        if ($bearRatio >= $dominanceRatio) {
            return ['bearish', 'bear_dominance'];
        }

        // 4. Flat clearly dominates — checked BEFORE transition so that a market
        //    that is simply flat (no directional force) is never called "transitioning".
        if ($flatRatio >= $flatDominance) {
            return ['flat', 'flat_dominance'];
        }

        // 5. Transition — only valid when the previous regime was strictly
        //    directional AND the directional side has materially lost strength.
        //    e.g. was bullish, now bull_ratio dropped below (0.55 - 0.20 = 0.35).
        $transitionFloor = $dominanceRatio - $transitionFlip;
        if ($previousRegime === 'bullish' && $bullRatio < $transitionFloor) {
            return ['transition', 'transition_from_bullish'];
        }
        if ($previousRegime === 'bearish' && $bearRatio < $transitionFloor) {
            return ['transition', 'transition_from_bearish'];
        }

        // 6. Mixed — both directions are meaningfully present
        if ($bullRatio >= self::MIXED_MIN_DIRECTIONAL_RATIO && $bearRatio >= self::MIXED_MIN_DIRECTIONAL_RATIO) {
            return ['mixed', 'mixed_distribution'];
        }

        // 7. Flat fallback — directional signals exist but are too weak for "mixed"
        return ['flat', 'flat_fallback'];
    }

    /**
     * Decide whether the regime passes the gate for a given side and gate_mode.
     *
     * @param  string $regime    Current regime
     * @param  string $side      'long' | 'short'
     * @param  string $gateMode  'soft' | 'hard'
     * @return array  { pass: bool, reason: string }
     */
    public function gate(string $regime, string $side, string $gateMode): array
    {
        if ($gateMode === 'soft') {
            return ['pass' => true, 'reason' => 'soft_gate_always_pass'];
        }

        if ($side === 'long' && $regime === 'bullish') {
            return ['pass' => true,  'reason' => 'regime_bullish_long_ok'];
        }
        if ($side === 'short' && $regime === 'bearish') {
            return ['pass' => true,  'reason' => 'regime_bearish_short_ok'];
        }
        if (in_array($regime, ['mixed', 'transition', 'flat'], true)) {
            return ['pass' => false, 'reason' => "regime_{$regime}_hard_block"];
        }
        return ['pass' => false, 'reason' => "regime_mismatch_{$regime}_{$side}"];
    }
}
