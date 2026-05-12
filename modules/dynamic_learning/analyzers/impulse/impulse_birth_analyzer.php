<?php

declare(strict_types=1);

namespace Modules\DynamicLearning\Analyzers\Impulse;

use Modules\DynamicLearning\Analyzers\DlHelpers;

/**
 * Impulse birth structure analyzer scaffold.
 *
 * Extracts impulse birth phase features (the initial recovery after dump).
 * Uses data from strategy_signal_context. Does not block signals.
 */
final class ImpulseBirthAnalyzer
{
    /**
     * Analyze impulse birth structure from context.
     *
     * @param array<string,mixed> $ctx strategy_signal_context
     * @return array<string,mixed>
     */
    public static function analyze(array $ctx): array
    {
        $smoothGrowthPct = DlHelpers::toFloat($ctx['smooth_growth_pct'] ?? null);
        $smoothGrowthDuration = DlHelpers::toFloat($ctx['smooth_growth_duration_minutes'] ?? null);
        $smoothGrowthHigherCloseCount = $ctx['smooth_growth_higher_close_count'] ?? null;
        $smoothGrowthHigherLowCount = $ctx['smooth_growth_higher_low_count'] ?? null;
        $dominancePct = DlHelpers::toFloat($ctx['smooth_growth_single_candle_dominance_pct'] ?? null);
        $recoveryPhase = $ctx['recovery_phase'] ?? null;
        $oiGrowthPct = DlHelpers::toFloat($ctx['open_interest_growth_pct'] ?? null);
        $oiConfirmed = $ctx['open_interest_confirmed'] ?? null;

        $available = ($smoothGrowthPct !== null || $recoveryPhase !== null);

        if (!$available) {
            return [
                'impulse_birth_available' => false,
                'impulse_growth_pct' => null,
                'impulse_duration_minutes' => null,
                'impulse_speed_pct_per_min' => null,
                'impulse_structure_score' => null,
                'impulse_oi_confirmed' => null,
            ];
        }

        $impulseSpeed = null;
        if ($smoothGrowthPct !== null && $smoothGrowthDuration !== null && $smoothGrowthDuration > 0) {
            $impulseSpeed = round($smoothGrowthPct / $smoothGrowthDuration, 6);
        }

        $structureScore = null;
        if ($smoothGrowthHigherCloseCount !== null && $smoothGrowthHigherLowCount !== null && $dominancePct !== null) {
            $hc = max(0, (int)$smoothGrowthHigherCloseCount);
            $hl = max(0, (int)$smoothGrowthHigherLowCount);
            $dominancePenalty = max(0.0, ($dominancePct - 50.0) / 50.0);
            $structureScore = round(max(0.0, min(100.0, ($hc + $hl) * 10.0 - $dominancePenalty * 50.0)), 2);
        }

        return [
            'impulse_birth_available' => true,
            'impulse_growth_pct' => $smoothGrowthPct,
            'impulse_duration_minutes' => $smoothGrowthDuration,
            'impulse_speed_pct_per_min' => $impulseSpeed,
            'impulse_higher_close_count' => $smoothGrowthHigherCloseCount,
            'impulse_higher_low_count' => $smoothGrowthHigherLowCount,
            'impulse_single_candle_dominance_pct' => $dominancePct,
            'impulse_structure_score' => $structureScore,
            'impulse_recovery_phase' => $recoveryPhase,
            'impulse_oi_growth_pct' => $oiGrowthPct,
            'impulse_oi_confirmed' => $oiConfirmed,
            'impulse_source' => 'strategy_signal_context',
        ];
    }
}
