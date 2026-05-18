<?php

declare(strict_types=1);

namespace Modules\DynamicLearning\Analyzers\Trend;

use Modules\DynamicLearning\Analyzers\DlHelpers;

/**
 * Trend context analyzer scaffold.
 *
 * Extracts trend and wave regime features from strategy_signal_context / coin_context.
 * Does not block signals.
 */
final class TrendContextAnalyzer
{
    /**
     * Analyze trend context from strategy signal context.
     *
     * @param array<string,mixed> $ctx strategy_signal_context
     * @return array<string,mixed>
     */
    public static function analyze(array $ctx): array
    {
        $coin = is_array($ctx['coin_context'] ?? null) ? (array)$ctx['coin_context'] : [];
        $wave = is_array($ctx['wave_context'] ?? null) ? (array)$ctx['wave_context'] : [];
        $f = static fn(string $k, mixed $d = null) => $ctx[$k] ?? $coin[$k] ?? $wave[$k] ?? $d;

        $trend1h = $f('trend_1h_direction');
        $trend2h = $f('trend_2h_direction');
        $trend4h = $f('trend_4h_direction');
        $waveRegime = $f('wave_regime');
        $flipCount2h = $f('trend_flip_count_2h');
        $avgFlipMinutes = DlHelpers::toFloat($f('avg_time_between_flips_minutes'));
        $persistenceScore = DlHelpers::toFloat($f('trend_persistence_score'));
        $contextPhase = $f('context_phase');
        $contextQuality = $f('context_quality');

        $available = ($trend1h !== null || $waveRegime !== null || $contextPhase !== null);

        // Derived: flip chop detection
        $fastFlipChopDetected = $waveRegime === 'fast_flip_chop'
            || ($flipCount2h !== null && (int)$flipCount2h >= 5
                && $avgFlipMinutes !== null && $avgFlipMinutes < 25.0);

        // Derived: trend alignment
        $trendsAligned = null;
        if ($trend1h !== null && $trend2h !== null) {
            $trendsAligned = (strtolower((string)$trend1h) === strtolower((string)$trend2h));
        }

        return [
            'trend_context_available' => $available,
            'trend_1h_direction' => $trend1h,
            'trend_2h_direction' => $trend2h,
            'trend_4h_direction' => $trend4h,
            'wave_regime' => $waveRegime,
            'trend_flip_count_2h' => $flipCount2h,
            'avg_time_between_flips_minutes' => $avgFlipMinutes,
            'trend_persistence_score' => $persistenceScore,
            'trends_aligned_1h_2h' => $trendsAligned,
            'fast_flip_chop_detected' => $fastFlipChopDetected,
            'context_phase' => $contextPhase,
            'context_quality' => $contextQuality,
            'context_reasons' => is_array($f('context_reasons', [])) ? $f('context_reasons', []) : [],
            'wave_amplitude_avg_pct' => DlHelpers::toFloat($f('wave_amplitude_avg_pct')),
            'wave_noise_score' => DlHelpers::toFloat($f('wave_noise_score')),
            'trend_source' => 'strategy_signal_context',
        ];
    }
}
