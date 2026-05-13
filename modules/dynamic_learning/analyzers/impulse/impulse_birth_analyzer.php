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
     * @param array<string,mixed> $candleMicro
     * @param array<string,mixed> $dumpMicro
     * @return array<string,mixed>
     */
    public static function analyze(array $ctx, array $candleMicro = [], array $dumpMicro = []): array
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

        $microShape = (string)($candleMicro['micro_impulse_shape'] ?? 'unknown');
        $microTiming = (string)($candleMicro['micro_entry_timing'] ?? 'unknown');
        $growthDist = (string)($candleMicro['micro_growth_distribution'] ?? 'unknown');
        $dumpShape = (string)($dumpMicro['dump_shape'] ?? 'unknown');
        $postDumpState = (string)($dumpMicro['post_dump_state'] ?? 'unknown');
        $dumpBounceRisk = DlHelpers::toFloat($dumpMicro['bounce_only_risk_score'] ?? null) ?? 0.0;
        $lateSpikeRisk = DlHelpers::toFloat($candleMicro['micro_late_spike_risk_score'] ?? null) ?? 0.0;
        $smoothness = DlHelpers::toFloat($candleMicro['micro_smoothness_score'] ?? null) ?? 0.0;
        $impulseBirth = DlHelpers::toFloat($candleMicro['micro_impulse_birth_score'] ?? null) ?? 0.0;

        $softGrowthAfterDumpScore = max(
            0.0,
            min(
                1.0,
                ($impulseBirth * 0.45)
                + ($smoothness * 0.3)
                + (($growthDist === 'distributed' ? 1.0 : 0.0) * 0.15)
                + (($postDumpState === 'stabilized' ? 1.0 : 0.0) * 0.1)
            )
        );
        $bounceOnlyRiskScore = max(0.0, min(1.0, max($dumpBounceRisk, $lateSpikeRisk)));
        $impulseBirthAfterDumpScore = max(0.0, min(1.0, ($softGrowthAfterDumpScore * 0.6) + ((1.0 - $bounceOnlyRiskScore) * 0.4)));
        $entryQualityMicroScore = max(0.0, min(1.0, ($impulseBirthAfterDumpScore * 0.65) + ((1.0 - $bounceOnlyRiskScore) * 0.35)));

        $postDumpImpulseType = 'unknown';
        if ($microShape === 'smooth_birth' && $dumpShape === 'controlled_dump' && $postDumpState === 'stabilized') {
            $postDumpImpulseType = 'real_impulse_birth';
        } elseif ($microShape === 'single_spike' || $microTiming === 'after_spike') {
            $postDumpImpulseType = 'late_spike';
        } elseif ($postDumpState === 'knife_bounce' || $bounceOnlyRiskScore >= 0.65) {
            $postDumpImpulseType = 'technical_bounce';
        } elseif ($microShape === 'choppy_birth' || $dumpShape === 'choppy_dump') {
            $postDumpImpulseType = 'choppy_rebound';
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
            'soft_growth_after_dump_score' => round($softGrowthAfterDumpScore, 6),
            'impulse_birth_after_dump_score' => round($impulseBirthAfterDumpScore, 6),
            'bounce_only_risk_score' => round($bounceOnlyRiskScore, 6),
            'entry_quality_micro_score' => round($entryQualityMicroScore, 6),
            'post_dump_impulse_type' => $postDumpImpulseType,
        ];
    }
}
