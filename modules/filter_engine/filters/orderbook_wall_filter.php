<?php

declare(strict_types=1);

namespace Modules\FilterEngine\Filters;

use Modules\FilterEngine\FilterResult;

final class OrderbookWallFilter
{
    public function id(): string { return 'orderbook_wall_filter'; }

    public function metadata(): array
    {
        return [
            'filter_id' => $this->id(),
            'title' => 'Orderbook wall protection',
            'description' => 'Protects early-entry long signals from nearby strong ask walls and weak bid support.',
            'default_severity' => 'hard_block',
            'configurable_fields' => [
                ['key' => 'max_ask_wall_distance_pct', 'label' => 'Max ask wall distance (%)', 'type' => 'float', 'default' => 0.8, 'min' => 0.0, 'max' => 10.0],
                ['key' => 'min_ask_wall_notional', 'label' => 'Min ask wall notional (USDT)', 'type' => 'float', 'default' => 20000.0, 'min' => 0.0, 'max' => 1000000000.0],
                ['key' => 'min_ask_wall_strength_score', 'label' => 'Min ask wall strength score', 'type' => 'float', 'default' => 0.60, 'min' => 0.0, 'max' => 100.0],
                ['key' => 'require_bid_support', 'label' => 'Require bid support', 'type' => 'bool', 'default' => false],
                ['key' => 'min_bid_support_score', 'label' => 'Min bid support score', 'type' => 'float', 'default' => 0.35, 'min' => 0.0, 'max' => 100.0],
                ['key' => 'min_bid_ask_ratio', 'label' => 'Min bid/ask ratio', 'type' => 'float', 'default' => 0.65, 'min' => 0.0, 'max' => 100.0],
                ['key' => 'allow_missing_orderbook', 'label' => 'Allow missing orderbook', 'type' => 'bool', 'default' => true],
                ['key' => 'block_if_orderbook_missing', 'label' => 'Block if orderbook missing', 'type' => 'bool', 'default' => false],
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
        $entryTiming = strtolower(trim((string)($signalContext['entry_timing'] ?? '')));
        $recoveryPhase = strtolower(trim((string)($signalContext['recovery_phase'] ?? '')));
        if ($entryTiming !== 'early' || $recoveryPhase !== 'early_entry') {
            return new FilterResult($this->id(), true, true, $severity, 'no_wall_risk');
        }

        $context = is_array($signalContext['orderbook_context'] ?? null) ? (array)$signalContext['orderbook_context'] : [];
        $contextAvailable = (bool)($signalContext['orderbook_context_available'] ?? ($context['orderbook_context_available'] ?? false));

        $allowMissing = (bool)($filterConfig['allow_missing_orderbook'] ?? true);
        $blockIfMissing = (bool)($filterConfig['block_if_orderbook_missing'] ?? false);
        if (!$contextAvailable) {
            if ($blockIfMissing || !$allowMissing) {
                return new FilterResult(
                    $this->id(),
                    true,
                    false,
                    $severity,
                    'orderbook_missing',
                    ['orderbook_context_available' => false],
                    in_array($severity, ['soft_block', 'hard_block', 'fatal'], true),
                    $severity === 'fatal'
                );
            }
            return new FilterResult($this->id(), true, true, $severity, 'orderbook_missing_allowed');
        }

        $askWallDetected = (bool)($signalContext['ask_wall_detected'] ?? ($context['ask_wall_detected'] ?? false));
        $askDistance = $this->toNullableFloat($signalContext['nearest_ask_wall_distance_pct'] ?? ($context['nearest_ask_wall_distance_pct'] ?? null));
        $askNotional = $this->toNullableFloat($signalContext['nearest_ask_wall_notional'] ?? ($context['nearest_ask_wall_notional'] ?? null));
        $askStrength = $this->toNullableFloat($signalContext['ask_wall_strength_score'] ?? ($context['ask_wall_strength_score'] ?? null));
        $bidSupport = $this->toNullableFloat($signalContext['bid_support_score'] ?? ($context['bid_support_score'] ?? null));
        $ratio = $this->toNullableFloat($signalContext['bid_ask_notional_ratio'] ?? ($context['bid_ask_notional_ratio'] ?? null));
        $breakoutConfirmed = (bool)($signalContext['breakout_wall_confirmed'] ?? ($context['breakout_wall_confirmed'] ?? false));
        $priceAboveAskWall = (bool)($signalContext['price_above_nearest_ask_wall'] ?? ($context['price_above_nearest_ask_wall'] ?? false));

        $maxAskDist = (float)($filterConfig['max_ask_wall_distance_pct'] ?? 0.8);
        $minAskNotional = (float)($filterConfig['min_ask_wall_notional'] ?? 20000.0);
        $minAskStrength = (float)($filterConfig['min_ask_wall_strength_score'] ?? 0.60);
        $requireBidSupport = (bool)($filterConfig['require_bid_support'] ?? false);
        $minBidSupport = (float)($filterConfig['min_bid_support_score'] ?? 0.35);
        $minBidAskRatio = (float)($filterConfig['min_bid_ask_ratio'] ?? 0.65);

        $reasons = [];
        if (
            $askWallDetected
            && $askDistance !== null
            && $askDistance <= $maxAskDist
            && $askNotional !== null
            && $askNotional >= $minAskNotional
        ) {
            $reasons[] = 'ask_wall_too_close';
        }
        if (
            $askWallDetected
            && $askDistance !== null
            && $askDistance <= $maxAskDist
            && $askStrength !== null
            && $askStrength >= $minAskStrength
        ) {
            $reasons[] = 'ask_wall_too_strong';
        }
        if ($requireBidSupport && ($bidSupport === null || $bidSupport < $minBidSupport)) {
            $reasons[] = 'weak_bid_support';
        }
        if ($ratio !== null && $ratio < $minBidAskRatio) {
            $reasons[] = 'bad_bid_ask_ratio';
        }

        if ($reasons !== []) {
            $reason = $reasons[0];
            return new FilterResult(
                $this->id(),
                true,
                false,
                $severity,
                $reason,
                [
                    'reasons' => array_values(array_unique($reasons)),
                    'nearest_ask_wall_distance_pct' => $askDistance,
                    'nearest_ask_wall_notional' => $askNotional,
                    'ask_wall_strength_score' => $askStrength,
                    'bid_support_score' => $bidSupport,
                    'bid_ask_notional_ratio' => $ratio,
                ],
                in_array($severity, ['soft_block', 'hard_block', 'fatal'], true),
                $severity === 'fatal'
            );
        }

        if ($breakoutConfirmed || $priceAboveAskWall) {
            return new FilterResult($this->id(), true, true, $severity, 'breakout_confirmed_above_wall');
        }
        if ($bidSupport !== null && $bidSupport >= $minBidSupport) {
            return new FilterResult($this->id(), true, true, $severity, 'bid_support_confirmed');
        }

        return new FilterResult($this->id(), true, true, $severity, 'no_wall_risk');
    }

    private function toNullableFloat(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }
        return (float)$value;
    }
}
