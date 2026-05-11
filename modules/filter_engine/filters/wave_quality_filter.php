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
            'description' => 'Blocks chaotic, downtrend, spike, and fast-flip context before Bot handoff.',
            'default_severity' => 'hard_block',
            'configurable_fields' => [
                ['key' => 'block_context_phases', 'label' => 'Blocked context phases', 'type' => 'string', 'default' => 'chaotic,spike,downtrend'],
                ['key' => 'block_context_reasons', 'label' => 'Blocked context reasons', 'type' => 'string', 'default' => 'too_many_direction_flips,downward_trend_confirmed,spike_threshold_10m'],
                ['key' => 'block_trend_1h_directions', 'label' => 'Blocked 1h trend directions', 'type' => 'string', 'default' => 'chaotic,down'],
                ['key' => 'require_trend_2h_confirmation', 'label' => 'Require 2h confirmation', 'type' => 'bool', 'default' => true],
                ['key' => 'allow_unknown_context', 'label' => 'Allow unknown context', 'type' => 'bool', 'default' => true],
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

        if (!$contextAvailable) {
            if ($allowUnknownContext) {
                return new FilterResult($this->id(), true, true, $severity, 'context_missing_allowed');
            }
            return new FilterResult(
                $this->id(),
                true,
                false,
                $severity,
                'context_phase_blocked',
                ['coin_context_available' => false],
                in_array($severity, ['soft_block', 'hard_block', 'fatal'], true),
                $severity === 'fatal'
            );
        }

        $blockedPhases = $this->parseCsvList($filterConfig['block_context_phases'] ?? 'chaotic,spike,downtrend');
        $blockedReasons = $this->parseCsvList($filterConfig['block_context_reasons'] ?? 'too_many_direction_flips,downward_trend_confirmed,spike_threshold_10m');
        $blockedTrend1h = $this->parseCsvList($filterConfig['block_trend_1h_directions'] ?? 'chaotic,down');
        $requireTrend2hConfirmation = (bool)($filterConfig['require_trend_2h_confirmation'] ?? true);

        $contextPhase = strtolower(trim((string)($signalContext['coin_context_phase'] ?? ($context['context_phase'] ?? 'unknown'))));
        $contextQuality = strtolower(trim((string)($signalContext['coin_context_quality'] ?? ($context['context_quality'] ?? 'unknown'))));
        $contextReasons = array_values(array_filter(array_map(
            static fn($value): string => strtolower(trim((string)$value)),
            (array)($signalContext['coin_context_reasons'] ?? ($context['context_reasons'] ?? []))
        ), static fn(string $value): bool => $value !== ''));
        $trend1h = strtolower(trim((string)($signalContext['coin_context_trend_1h_direction'] ?? ($context['trend_1h_direction'] ?? 'unknown'))));
        $trend2h = strtolower(trim((string)($signalContext['coin_context_trend_2h_direction'] ?? ($context['trend_2h_direction'] ?? 'unknown'))));
        $waveContext = is_array($signalContext['wave_context'] ?? null) ? (array)$signalContext['wave_context'] : [];
        $waveRegime = strtolower(trim((string)($waveContext['wave_regime'] ?? ($signalContext['wave_regime'] ?? ''))));

        if (in_array($waveRegime, ['fast_flip_chop', 'narrow_chop', 'chaotic'], true)) {
            return $this->fail($severity, 'fast_flip_chop_detected', $contextPhase, $contextQuality, $contextReasons, $trend1h, $trend2h, $waveRegime);
        }

        if (in_array('too_many_direction_flips', $contextReasons, true)) {
            return $this->fail($severity, 'fast_flip_chop_detected', $contextPhase, $contextQuality, $contextReasons, $trend1h, $trend2h, $waveRegime);
        }

        if ($contextPhase === 'downtrend' && in_array('downtrend', $blockedPhases, true)) {
            return $this->fail($severity, 'downtrend_context_blocked', $contextPhase, $contextQuality, $contextReasons, $trend1h, $trend2h, $waveRegime);
        }

        if ($contextPhase === 'spike' && in_array('spike', $blockedPhases, true)) {
            return $this->fail($severity, 'spike_context_blocked', $contextPhase, $contextQuality, $contextReasons, $trend1h, $trend2h, $waveRegime);
        }

        if ($contextPhase !== '' && in_array($contextPhase, $blockedPhases, true)) {
            return $this->fail($severity, 'context_phase_blocked', $contextPhase, $contextQuality, $contextReasons, $trend1h, $trend2h, $waveRegime);
        }

        $matchedBlockedReasons = array_values(array_intersect($blockedReasons, $contextReasons));
        if ($matchedBlockedReasons !== []) {
            if (in_array('downward_trend_confirmed', $matchedBlockedReasons, true)) {
                return $this->fail($severity, 'downtrend_context_blocked', $contextPhase, $contextQuality, $contextReasons, $trend1h, $trend2h, $waveRegime);
            }
            if (in_array('spike_threshold_10m', $matchedBlockedReasons, true)) {
                return $this->fail($severity, 'spike_context_blocked', $contextPhase, $contextQuality, $contextReasons, $trend1h, $trend2h, $waveRegime);
            }
            return $this->fail($severity, 'context_reason_blocked', $contextPhase, $contextQuality, $contextReasons, $trend1h, $trend2h, $waveRegime);
        }

        $trend2hConfirmed = !$requireTrend2hConfirmation || in_array($trend2h, $blockedTrend1h, true);
        if ($contextQuality === 'bad') {
            if ($trend1h === 'down' && $trend2hConfirmed) {
                return $this->fail($severity, 'downtrend_context_blocked', $contextPhase, $contextQuality, $contextReasons, $trend1h, $trend2h, $waveRegime);
            }
            if ($trend1h === 'chaotic' && $trend2hConfirmed) {
                return $this->fail($severity, 'fast_flip_chop_detected', $contextPhase, $contextQuality, $contextReasons, $trend1h, $trend2h, $waveRegime);
            }
            return $this->fail($severity, 'context_phase_blocked', $contextPhase, $contextQuality, $contextReasons, $trend1h, $trend2h, $waveRegime);
        }

        if (in_array($trend1h, $blockedTrend1h, true) && $trend2hConfirmed) {
            if ($trend1h === 'down') {
                return $this->fail($severity, 'downtrend_context_blocked', $contextPhase, $contextQuality, $contextReasons, $trend1h, $trend2h, $waveRegime);
            }
            if ($trend1h === 'chaotic') {
                return $this->fail($severity, 'fast_flip_chop_detected', $contextPhase, $contextQuality, $contextReasons, $trend1h, $trend2h, $waveRegime);
            }
            return $this->fail($severity, 'context_phase_blocked', $contextPhase, $contextQuality, $contextReasons, $trend1h, $trend2h, $waveRegime);
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
        string $waveRegime
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
            ],
            in_array($severity, ['soft_block', 'hard_block', 'fatal'], true),
            $severity === 'fatal'
        );
    }
}
