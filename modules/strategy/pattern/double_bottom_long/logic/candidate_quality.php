<?php

declare(strict_types=1);

/**
 * Pattern Strategy — Candidate Quality Scorer
 *
 * Computes a transparent, bounded quality score for a confirmed pattern
 * candidate.  Every component maps to a real property already computed by
 * the upstream pipeline stages.  No AI, no opaque magic.
 *
 * Score components (each 0.0 – 1.0):
 *
 *   structure_score      — symmetry of the two lows / highs
 *                          (similarity_delta_pct vs allowed tolerance)
 *
 *   neckline_score       — neckline depth / separation
 *                          (height of the "W" or "M" relative to avg peak)
 *
 *   confirmation_score   — pattern formation compactness / trigger quality
 *                          (window_size: tighter formation = cleaner trigger)
 *
 *   context_score        — wave pivot freshness
 *                          (wave_freshness bars: lower = fresher = better)
 *
 *   pattern_score        — detector's own depth+symmetry blend
 *                          (candidate_score from double_bottom / double_top)
 *
 *   candidate_quality_score — weighted composite of the above
 *
 * Threshold:
 *   min_candidate_quality_score (config) — candidates below this are rejected
 *   with a truthful quality_reject_reason naming the weakest component.
 */

namespace Modules\Strategy\DoubleBottomLong\Logic;

final class PatternCandidateQuality
{
    // Weighted contributions to the composite score
    private const W_STRUCTURE    = 0.30;
    private const W_NECKLINE     = 0.30;
    private const W_CONFIRMATION = 0.20;
    private const W_CONTEXT      = 0.10;
    private const W_PATTERN      = 0.10;

    // Scaling constants
    private const DEPTH_SCALE         = 0.10; // 10% pattern depth → score 1.0
    private const COMPACTNESS_MIN_BARS = 4;    // minimum MIN_PIVOT_GAP — score = 1.0 here
    private const COMPACTNESS_MAX_BARS = 25;   // at this window size score → 0.0
    private const FRESHNESS_MAX_BARS   = 20;   // at this wave freshness score → 0.0

    /**
     * Score a confirmed pattern candidate.
     *
     * @param  array  $candidate  Output of PatternDoubleBottom::detect() or
     *                            PatternDoubleTop::detect() with candidate_found = true
     * @param  array  $wave       Output of PatternWave::analyse()
     * @param  array  $config     Effective strategy config
     * @param  string $side       'long' | 'short'
     * @return array {
     *   pattern_score, structure_score, neckline_score,
     *   confirmation_score, context_score,
     *   candidate_quality_score, quality_pass, quality_reject_reason
     * }
     */
    public function score(array $candidate, array $wave, array $config, string $side): array
    {
        $tolerance = $side === 'long'
            ? (float)($config['double_bottom_similarity_tolerance_pct']
                ?? $config['pattern_similarity_tolerance'] ?? 0.07)
            : (float)($config['double_top_similarity_tolerance_pct']
                ?? $config['pattern_similarity_tolerance'] ?? 0.07);

        $minQuality = (float)($config['min_candidate_quality_score'] ?? 0.30);

        // ── structure_score ── how symmetric the two lows / highs are
        $simDelta     = (float)($candidate['similarity_delta_pct'] ?? 0.0);
        $structScore  = $this->clamp(1.0 - ($simDelta / max(1e-8, $tolerance)));

        // ── neckline_score ── how deep the pattern is
        $neckline = (float)($candidate['neckline'] ?? 0.0);
        if ($side === 'long') {
            $p1 = (float)($candidate['low1_price']  ?? 0.0);
            $p2 = (float)($candidate['low2_price']  ?? 0.0);
        } else {
            $p1 = (float)($candidate['high1_price'] ?? 0.0);
            $p2 = (float)($candidate['high2_price'] ?? 0.0);
        }
        $avgLevel = ($p1 + $p2) / 2.0;

        if ($side === 'long') {
            $depthRatio = $neckline > 0.0
                ? abs($neckline - $avgLevel) / $neckline
                : 0.0;
        } else {
            $depthRatio = $avgLevel > 0.0
                ? abs($avgLevel - $neckline) / $avgLevel
                : 0.0;
        }
        $necklineScore = $this->clamp($depthRatio / self::DEPTH_SCALE);

        // ── confirmation_score ── pattern formation compactness (trigger quality)
        // Tighter patterns (fewer bars between peaks) produce cleaner trigger levels.
        $windowSize   = max(self::COMPACTNESS_MIN_BARS, (int)($candidate['window_size'] ?? self::COMPACTNESS_MAX_BARS));
        $confirmScore = $this->clamp(
            1.0 - (($windowSize - self::COMPACTNESS_MIN_BARS)
                / (float)(self::COMPACTNESS_MAX_BARS - self::COMPACTNESS_MIN_BARS))
        );

        // ── context_score ── wave pivot freshness
        // A freshly formed corrective wave context is better for a pattern setup.
        $waveFreshness = (int)($wave['wave_freshness'] ?? self::FRESHNESS_MAX_BARS);
        $contextScore  = $this->clamp(1.0 - ($waveFreshness / (float)self::FRESHNESS_MAX_BARS));

        // ── pattern_score ── detector's own depth + symmetry blend
        $patternScore = $this->clamp((float)($candidate['candidate_score'] ?? 0.0));

        // ── composite ──────────────────────────────────────────────────────────
        $composite = round(
            $structScore  * self::W_STRUCTURE
            + $necklineScore * self::W_NECKLINE
            + $confirmScore  * self::W_CONFIRMATION
            + $contextScore  * self::W_CONTEXT
            + $patternScore  * self::W_PATTERN,
            4
        );

        $qualityPass         = $composite >= $minQuality;
        $qualityRejectReason = null;

        if (!$qualityPass) {
            // Name the weakest component as the primary reject reason
            $components = [
                'structure'    => $structScore,
                'neckline'     => $necklineScore,
                'confirmation' => $confirmScore,
                'context'      => $contextScore,
                'pattern'      => $patternScore,
            ];
            $weakest             = (string)array_search(min($components), $components, true);
            $qualityRejectReason = 'quality_weak_' . $weakest;
        }

        return [
            'pattern_score'           => round($patternScore,  4),
            'structure_score'         => round($structScore,   4),
            'neckline_score'          => round($necklineScore, 4),
            'confirmation_score'      => round($confirmScore,  4),
            'context_score'           => round($contextScore,  4),
            'candidate_quality_score' => $composite,
            'quality_pass'            => $qualityPass,
            'quality_reject_reason'   => $qualityRejectReason,
        ];
    }

    // -------------------------------------------------------------------------

    private function clamp(float $v): float
    {
        return max(0.0, min(1.0, $v));
    }
}
