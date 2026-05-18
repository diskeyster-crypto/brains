<?php

declare(strict_types=1);

namespace Modules\FilterEngine\Filters;

use Modules\FilterEngine\FilterResult;

final class WaveQualityFilter
{
    public function id(): string { return 'wave_quality_filter'; }

    public function metadata(): array
    {
        return [
            'filter_id' => $this->id(),
            'title' => 'Wave quality filter',
            'description' => 'Blocks only confirmed bad wave regimes before Bot handoff.',
            'default_severity' => 'hard_block',
            'configurable_fields' => [
                ['key' => 'block_regimes', 'label' => 'Blocked wave regimes', 'type' => 'string', 'default' => 'fast_flip_chop,narrow_chop,chaotic'],
                ['key' => 'min_avg_time_between_flips_minutes', 'label' => 'Min avg minutes between flips', 'type' => 'float', 'default' => 45],
                ['key' => 'max_trend_flip_count_2h', 'label' => 'Max trend flips in 2h before block', 'type' => 'int', 'default' => 4],
                ['key' => 'min_trend_persistence_score', 'label' => 'Min trend persistence score', 'type' => 'float', 'default' => 0.45],
                ['key' => 'allow_unknown_context', 'label' => 'Allow unknown context', 'type' => 'bool', 'default' => true],
                ['key' => 'block_generic_chaotic_without_wave_context', 'label' => 'Block generic chaotic without wave context', 'type' => 'bool', 'default' => false],
            ],
        ];
    }

    public function evaluate(array $signalContext, array $filterConfig): FilterResult
    {
        $enabled = (bool)($filterConfig['enabled'] ?? true);
        if (!$enabled) {
            return new FilterResult($this->id(), false, true);
        }

        $severity = (string)($filterConfig['severity'] ?? 'hard_block');
        $context = is_array($signalContext['coin_context'] ?? null) ? (array)$signalContext['coin_context'] : [];
        $contextAvailable = (bool)($signalContext['coin_context_available'] ?? ($context['context_available'] ?? false));
        $allowUnknownContext = (bool)($filterConfig['allow_unknown_context'] ?? true);
        $blockGenericChaoticWithoutWaveContext = (bool)($filterConfig['block_generic_chaotic_without_wave_context'] ?? false);
        $blockedRegimes = $this->parseCsvList($filterConfig['block_regimes'] ?? 'fast_flip_chop,narrow_chop,chaotic');
        $minAvgFlipMinutes = (float)($filterConfig['min_avg_time_between_flips_minutes'] ?? 45);
        $maxFlips2h = (int)($filterConfig['max_trend_flip_count_2h'] ?? 4);
        $minPersistence = (float)($filterConfig['min_trend_persistence_score'] ?? 0.45);

        if (!$contextAvailable) {
            if ($allowUnknownContext) {
                return new FilterResult($this->id(), true, true, 'warning', 'context_missing_allowed', [
                    'coin_context_available' => false,
                    'wave_context_available' => false,
                ]);
            }
            return $this->fail($severity, 'context_missing_blocked', 'unknown', 'unknown', [], 'unknown', 'unknown', '', null, null, null, null);
        }

        $contextPhase = strtolower(trim((string)($signalContext['coin_context_phase'] ?? ($context['context_phase'] ?? 'unknown'))));
        $contextQuality = strtolower(trim((string)($signalContext['coin_context_quality'] ?? ($context['context_quality'] ?? 'unknown'))));
        $contextReasons = array_values(array_filter(array_map(
            static fn($value): string => strtolower(trim((string)$value)),
            (array)($signalContext['coin_context_reasons'] ?? ($context['context_reasons'] ?? []))
        ), static fn(string $value): bool => $value !== ''));
        $trend1h = strtolower(trim((string)($signalContext['coin_context_trend_1h_direction'] ?? ($context['trend_1h_direction'] ?? 'unknown'))));
        $trend2h = strtolower(trim((string)($signalContext['coin_context_trend_2h_direction'] ?? ($context['trend_2h_direction'] ?? 'unknown'))));
        $waveContext = is_array($signalContext['wave_context'] ?? null)
            ? (array)$signalContext['wave_context']
            : (is_array($context['wave_context'] ?? null) ? (array)$context['wave_context'] : []);
        $waveContextAvailable = (bool)($waveContext['wave_context_available'] ?? ($context['wave_context_available'] ?? false));
        $waveRegime = strtolower(trim((string)($waveContext['wave_regime'] ?? ($signalContext['wave_regime'] ?? ''))));
        $trendFlipCount2h = $this->toIntOrNull($waveContext['trend_flip_count_2h'] ?? ($context['trend_flip_count_2h'] ?? null));
        $avgFlipMinutes = $this->toFloatOrNull($waveContext['avg_time_between_flips_minutes'] ?? ($context['avg_time_between_flips_minutes'] ?? null));
        $waveAmplitudeAvgPct = $this->toFloatOrNull($waveContext['wave_amplitude_avg_pct'] ?? ($context['wave_amplitude_avg_pct'] ?? null));
        $trendPersistenceScore = $this->toFloatOrNull($waveContext['trend_persistence_score'] ?? ($context['trend_persistence_score'] ?? null));

        if (!$waveContextAvailable || $waveRegime === '' || $waveRegime === 'unknown') {
            if ($contextPhase === 'chaotic' && $blockGenericChaoticWithoutWaveContext) {
                return $this->fail(
                    $severity,
                    'generic_chaotic_without_wave_context',
                    $contextPhase,
                    $contextQuality,
                    $contextReasons,
                    $trend1h,
                    $trend2h,
                    $waveRegime,
                    $trendFlipCount2h,
                    $avgFlipMinutes,
                    $waveAmplitudeAvgPct,
                    $trendPersistenceScore
                );
            }
            if ($allowUnknownContext) {
                return new FilterResult($this->id(), true, true, 'warning', 'generic_chaotic_without_wave_context_allowed', [
                    'context_phase' => $contextPhase,
                    'trend_1h_direction' => $trend1h,
                    'trend_2h_direction' => $trend2h,
                    'wave_context_available' => $waveContextAvailable,
                    'wave_regime' => $waveRegime !== '' ? $waveRegime : null,
                ]);
            }
            return $this->fail(
                $severity,
                'context_missing_blocked',
                $contextPhase,
                $contextQuality,
                $contextReasons,
                $trend1h,
                $trend2h,
                $waveRegime,
                $trendFlipCount2h,
                $avgFlipMinutes,
                $waveAmplitudeAvgPct,
                $trendPersistenceScore
            );
        }

        if (in_array($waveRegime, $blockedRegimes, true)) {
            return $this->fail(
                $severity,
                'wave_regime_blocked',
                $contextPhase,
                $contextQuality,
                $contextReasons,
                $trend1h,
                $trend2h,
                $waveRegime,
                $trendFlipCount2h,
                $avgFlipMinutes,
                $waveAmplitudeAvgPct,
                $trendPersistenceScore
            );
        }

        if (
            $contextPhase === 'chaotic'
            && $trend1h === 'chaotic'
            && $trend2h === 'chaotic'
            && $trendPersistenceScore !== null
            && $trendPersistenceScore < $minPersistence
        ) {
            return $this->fail(
                $severity,
                'chaotic_confirmed_low_persistence',
                $contextPhase,
                $contextQuality,
                $contextReasons,
                $trend1h,
                $trend2h,
                $waveRegime,
                $trendFlipCount2h,
                $avgFlipMinutes,
                $waveAmplitudeAvgPct,
                $trendPersistenceScore
            );
        }

        if (
            in_array('too_many_direction_flips', $contextReasons, true)
            && $trendFlipCount2h !== null
            && $trendFlipCount2h >= $maxFlips2h
            && $avgFlipMinutes !== null
            && $avgFlipMinutes <= $minAvgFlipMinutes
        ) {
            return $this->fail(
                $severity,
                'too_many_direction_flips_fast_cycle',
                $contextPhase,
                $contextQuality,
                $contextReasons,
                $trend1h,
                $trend2h,
                $waveRegime,
                $trendFlipCount2h,
                $avgFlipMinutes,
                $waveAmplitudeAvgPct,
                $trendPersistenceScore
            );
        }

        return new FilterResult($this->id(), true, true, $severity);
    }

    /**
     * @return list<string>
     */
    private function parseCsvList(mixed $value): array
    {
        if (is_array($value)) {
            $items = $value;
        } else {
            $items = explode(',', (string)$value);
        }

        return array_values(array_filter(array_map(
            static fn($item): string => strtolower(trim((string)$item)),
            $items
        ), static fn(string $item): bool => $item !== ''));
    }

    /**
     * @param list<string> $contextReasons
     */
    private function fail(
        string $severity,
        string $reason,
        string $contextPhase,
        string $contextQuality,
        array $contextReasons,
        string $trend1h,
        string $trend2h,
        string $waveRegime,
        ?int $trendFlipCount2h,
        ?float $avgFlipMinutes,
        ?float $waveAmplitudeAvgPct,
        ?float $trendPersistenceScore
    ): FilterResult {
        return new FilterResult(
            $this->id(),
            true,
            false,
            $severity,
            $reason,
            [
                'context_phase' => $contextPhase,
                'context_quality' => $contextQuality,
                'context_reasons' => $contextReasons,
                'trend_1h_direction' => $trend1h,
                'trend_2h_direction' => $trend2h,
                'wave_regime' => $waveRegime !== '' ? $waveRegime : null,
                'trend_flip_count_2h' => $trendFlipCount2h,
                'avg_time_between_flips_minutes' => $avgFlipMinutes,
                'wave_amplitude_avg_pct' => $waveAmplitudeAvgPct,
                'trend_persistence_score' => $trendPersistenceScore,
            ],
            in_array($severity, ['soft_block', 'hard_block', 'fatal'], true),
            $severity === 'fatal'
        );
    }

    private function toIntOrNull(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }
        return (int)$value;
    }

    private function toFloatOrNull(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }
        return (float)$value;
    }
}
