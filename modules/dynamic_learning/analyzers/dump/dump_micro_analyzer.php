<?php

declare(strict_types=1);

namespace Modules\DynamicLearning\Analyzers\Dump;

use Modules\DynamicLearning\Analyzers\DlHelpers;

/**
 * Dump micro-structure analyzer scaffold.
 *
 * Extracts dump phase features from entry context.
 * Does not block signals. Does not require dump data to be present.
 * Current state: scaffold — extracts what is available from strategy_signal_context.
 */
final class DumpMicroAnalyzer
{
    /**
     * Analyze dump structure from strategy_signal_context.
     *
     * @param array<string,mixed> $ctx strategy_signal_context
     * @return array<string,mixed> Dump micro feature set
     */
    public static function analyze(array $ctx): array
    {
        $dumpPct = DlHelpers::toFloat($ctx['dump_pct'] ?? null);
        $stabilizationDuration = DlHelpers::toFloat($ctx['stabilization_duration_minutes'] ?? null);
        $stabilizationRange = DlHelpers::toFloat($ctx['stabilization_range_pct'] ?? null);
        $recoveryPhase = $ctx['recovery_phase'] ?? null;

        $available = ($dumpPct !== null);

        if (!$available) {
            return [
                'dump_micro_available' => false,
                'dump_micro_proxy_available' => false,
                'dump_micro_real' => false,
                'dump_source' => 'none',
                'dump_depth_pct' => null,
                'dump_duration_minutes' => null,
                'dump_speed_pct_per_min' => null,
                'dump_red_candle_count' => null,
                'dump_single_candle_dominance_pct' => null,
                'dump_verticality_score' => null,
                'dump_rebound_risk_score' => null,
                'stabilization_after_dump_score' => null,
            ];
        }

        // Estimate derived metrics from available context
        $dumpSpeed = null;
        if ($dumpPct !== null && $stabilizationDuration !== null && $stabilizationDuration > 0) {
            // Heuristic: dump happened before stabilization; estimate speed from depth/window
            $dumpSpeed = round(abs($dumpPct) / max(1.0, $stabilizationDuration * 0.5), 4);
        }

        $verticalityScore = null;
        if ($dumpPct !== null) {
            // Simple heuristic: deeper dumps tend to be more vertical
            $verticalityScore = round(min(100.0, abs($dumpPct) * 3.0), 2);
        }

        $reboundRiskScore = null;
        if ($recoveryPhase !== null) {
            $reboundRiskScore = match ((string)$recoveryPhase) {
                'too_early' => 80.0,
                'valid_recovery' => 30.0,
                'late_spike' => 90.0,
                'failed' => 50.0,
                default => null,
            };
        }

        $stabilizationScore = null;
        if ($stabilizationDuration !== null && $stabilizationRange !== null) {
            $stabilizationScore = round(
                min(100.0, max(0.0, ($stabilizationDuration / max(1.0, $stabilizationDuration)) * 50.0
                    + (1.0 - min(1.0, $stabilizationRange / 5.0)) * 50.0)),
                2
            );
        }

        return [
            'dump_micro_available' => false,
            'dump_micro_proxy_available' => true,
            'dump_micro_real' => false,
            'dump_depth_pct' => $dumpPct,
            'dump_duration_minutes' => null,
            'dump_speed_pct_per_min' => $dumpSpeed,
            'dump_red_candle_count' => null,
            'dump_single_candle_dominance_pct' => null,
            'dump_verticality_score' => $verticalityScore,
            'dump_rebound_risk_score' => $reboundRiskScore,
            'stabilization_after_dump_score' => $stabilizationScore,
            'dump_source' => 'strategy_signal_context_proxy',
        ];
    }
}
