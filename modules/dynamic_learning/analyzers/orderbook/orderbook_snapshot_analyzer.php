<?php

declare(strict_types=1);

namespace Modules\DynamicLearning\Analyzers\Orderbook;

use Modules\DynamicLearning\Analyzers\DlHelpers;

/**
 * Orderbook snapshot analyzer scaffold.
 *
 * Extracts orderbook features from strategy_signal_context / orderbook_context.
 * Does not block signals.
 */
final class OrderbookSnapshotAnalyzer
{
    /**
     * Analyze orderbook snapshot from context.
     *
     * @param array<string,mixed> $ctx strategy_signal_context
     * @return array<string,mixed>
     */
    public static function analyze(array $ctx): array
    {
        $ob = is_array($ctx['orderbook_context'] ?? null) ? (array)$ctx['orderbook_context'] : [];
        $f = static fn(string $k, mixed $d = null) => $ob[$k] ?? $ctx[$k] ?? $d;

        $askWallRisk = $f('ask_wall_risk');
        $askWallDistance = DlHelpers::toFloat($f('nearest_ask_wall_distance_pct'));
        $askWallNotional = DlHelpers::toFloat($f('nearest_ask_wall_notional'));
        $askWallStrength = DlHelpers::toFloat($f('ask_wall_strength_score'));
        $bidSupportQuality = $f('bid_support_quality');
        $bidSupportScore = DlHelpers::toFloat($f('bid_support_score'));
        $bidAskRatio = DlHelpers::toFloat($f('bid_ask_notional_ratio'));

        $available = ($askWallRisk !== null || $bidSupportQuality !== null);

        // Derived: risk level
        $riskLevel = 'unknown';
        if ($askWallRisk !== null && $bidSupportQuality !== null) {
            if ($askWallRisk === 'high' && in_array(strtolower((string)$bidSupportQuality), ['weak', 'none'], true)) {
                $riskLevel = 'high';
            } elseif ($askWallRisk === 'low' && in_array(strtolower((string)$bidSupportQuality), ['strong', 'medium'], true)) {
                $riskLevel = 'low';
            } else {
                $riskLevel = 'medium';
            }
        }

        return [
            'orderbook_context_available' => $available,
            'ask_wall_risk' => $askWallRisk,
            'nearest_ask_wall_distance_pct' => $askWallDistance,
            'nearest_ask_wall_notional' => $askWallNotional,
            'ask_wall_strength_score' => $askWallStrength,
            'bid_support_quality' => $bidSupportQuality,
            'bid_support_score' => $bidSupportScore,
            'bid_ask_notional_ratio' => $bidAskRatio,
            'derived_risk_level' => $riskLevel,
            'orderbook_source' => 'strategy_signal_context',
        ];
    }
}
