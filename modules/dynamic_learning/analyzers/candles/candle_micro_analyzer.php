<?php

declare(strict_types=1);

namespace Modules\DynamicLearning\Analyzers\Candles;

use Modules\DynamicLearning\Analyzers\DlHelpers;

/**
 * Candle micro-structure analyzer scaffold.
 *
 * Extracts micro-window candle features from entry context.
 * Uses ONLY candles at or before entry time (no future leakage).
 *
 * Current state: scaffold — extracts what is available from strategy_signal_context.
 * Does not block signals. Does not require candle data to be present.
 */
final class CandleMicroAnalyzer
{
    /**
     * Analyze micro candle structure from the available context.
     *
     * @param array<string,mixed> $ctx strategy_signal_context
     * @param int $microWindowMinutes Candle window before entry to analyze
     * @return array<string,mixed> Micro candle feature set
     */
    public static function analyze(array $ctx, int $microWindowMinutes = 5): array
    {
        // Try to extract from pre-computed fields in strategy_signal_context
        $smoothGrowthDominance = DlHelpers::toFloat($ctx['smooth_growth_single_candle_dominance_pct'] ?? null);
        $smoothGrowthHigherCloseCount = $ctx['smooth_growth_higher_close_count'] ?? null;
        $smoothGrowthHigherLowCount = $ctx['smooth_growth_higher_low_count'] ?? null;
        $smoothGrowthPct = DlHelpers::toFloat($ctx['smooth_growth_pct'] ?? null);
        $smoothGrowthDuration = DlHelpers::toFloat($ctx['smooth_growth_duration_minutes'] ?? null);

        // Micro candle data is not currently injected into strategy_signal_context.
        // The micro-window candle array would need to be passed explicitly from EIGL.
        // For now: extract what we can from smooth_growth data as a proxy.
        $available = ($smoothGrowthDominance !== null || $smoothGrowthHigherCloseCount !== null);

        if (!$available) {
            return [
                'micro_context_available' => false,
                'micro_window_minutes' => $microWindowMinutes,
                'micro_candles_count' => null,
                'micro_growth_total_pct' => null,
                'micro_green_candle_count' => null,
                'micro_red_candle_count' => null,
                'micro_higher_close_count' => null,
                'micro_higher_low_count' => null,
                'micro_largest_candle_share_pct' => null,
                'micro_single_candle_dominance_pct' => null,
                'micro_pullback_max_pct' => null,
                'micro_smoothness_score' => null,
                'micro_impulse_birth_score' => null,
            ];
        }

        // Derive micro metrics from smooth_growth proxy data
        $smoothnessScore = null;
        if ($smoothGrowthHigherCloseCount !== null && $smoothGrowthHigherLowCount !== null) {
            $hc = (int)$smoothGrowthHigherCloseCount;
            $hl = (int)$smoothGrowthHigherLowCount;
            $total = max(1, $hc + $hl);
            $smoothnessScore = round(($hc + $hl) / ($total * 2) * 100, 2);
        }

        $impulseScore = null;
        if ($smoothGrowthPct !== null && $smoothGrowthDuration !== null && $smoothGrowthDuration > 0) {
            $speed = $smoothGrowthPct / $smoothGrowthDuration;
            $impulseScore = round(min(100.0, max(0.0, $speed * 10)), 2);
        }

        return [
            'micro_context_available' => true,
            'micro_window_minutes' => $microWindowMinutes,
            'micro_candles_count' => null,
            'micro_growth_total_pct' => $smoothGrowthPct,
            'micro_green_candle_count' => $smoothGrowthHigherCloseCount,
            'micro_red_candle_count' => null,
            'micro_higher_close_count' => $smoothGrowthHigherCloseCount,
            'micro_higher_low_count' => $smoothGrowthHigherLowCount,
            'micro_largest_candle_share_pct' => null,
            'micro_single_candle_dominance_pct' => $smoothGrowthDominance,
            'micro_pullback_max_pct' => null,
            'micro_smoothness_score' => $smoothnessScore,
            'micro_impulse_birth_score' => $impulseScore,
            'micro_source' => 'smooth_growth_proxy',
        ];
    }
}
