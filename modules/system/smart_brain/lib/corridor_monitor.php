<?php
declare(strict_types=1);

/**
 * Corridor Monitor — Smart Brain Phase 4
 *
 * Transforms candidates into monitored trade opportunities.
 * Calculates entry zones, price position, and status.
 *
 * Side-aware entry zone logic:
 *   LONG  → entry zone = lower slice of corridor (near corridor_low)
 *   SHORT → entry zone = upper slice of corridor (near corridor_high)
 *
 * V2 contextual patterns use progressive zone widening based on
 * pattern_confidence — confirmed reversals with high confidence get
 * wider entry zones because price has already bounced from lows.
 *
 * Does NOT create final trade signals.
 */
final class CorridorMonitor
{
    /** @var array<string,mixed> */
    private array $cfg;

    /**
     * @param array<string,mixed> $cfg  Effective settings from config/corridor.php
     */
    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    /**
     * Build real monitors from candidates (side-aware).
     *
     * @param array<int,array<string,mixed>> $candidates
     * @param array<string,float>            $prices  Optional real-time prices (symbol→price).
     * @return array<int,array<string,mixed>>
     */
    public function buildMonitors(array $candidates, array $prices = []): array
    {
        $entryZonePercent = (float)($this->cfg['entry_zone_percent'] ?? 0.20);
        $monitors = [];

        foreach ($candidates as $candidate) {
            $symbol = (string)($candidate['symbol'] ?? '');
            $low = (float)($candidate['corridor_low'] ?? 0.0);
            $high = (float)($candidate['corridor_high'] ?? 0.0);
            $corridorWidth = (float)($candidate['corridor_width'] ?? 0.0);
            $side = strtolower(trim((string)($candidate['side'] ?? '')));
            $patternAlgo = (string)($candidate['pattern_algorithm'] ?? '');
            $patternConfidence = (float)($candidate['pattern_confidence'] ?? 0.0);
            $confirmationScore = (float)($candidate['confirmation_score'] ?? 0.0);

            // Use real price if available, otherwise fallback to candidate last_price
            $currentPrice = (isset($prices[$symbol]) && $prices[$symbol] > 0.0)
                ? $prices[$symbol]
                : (float)($candidate['last_price'] ?? 0.0);

            // Compute effective entry zone percent with pattern-specific widening
            $effectiveEntryZonePercent = $this->computeEffectiveEntryZonePercent(
                $entryZonePercent, $patternAlgo, $patternConfidence, $confirmationScore
            );

            $range = $high - $low;

            // Side-aware entry zone boundaries
            if ($side === 'short') {
                $entryZoneLow = ($range > 0.0) ? $high - ($range * $effectiveEntryZonePercent) : $high;
                $entryZoneHigh = $high;
            } else {
                $entryZoneLow = $low;
                $entryZoneHigh = ($range > 0.0) ? $low + ($range * $effectiveEntryZonePercent) : $low;
            }

            // Price position: 0..1 inside corridor, <0 below, >1 above
            $pricePosition = ($range > 0.0 && $currentPrice > 0.0)
                ? ($currentPrice - $low) / $range
                : 0.5;

            // Side-aware status determination
            $status = $this->determineStatus($pricePosition, $effectiveEntryZonePercent, $side);

            // Specific rejection detail for diagnostics
            $rejectDetail = $this->computeRejectDetail($status, $pricePosition, $effectiveEntryZonePercent, $side, $currentPrice, $entryZoneHigh, $entryZoneLow);

            // What-if: would this monitor be entry_zone with full corridor (1.0)?
            $whatifEnterNowStatus = $this->determineStatus($pricePosition, 1.0, $side);
            // What-if: would this monitor be entry_zone with wider zone (0.85)?
            $whatifWiderZoneStatus = $this->determineStatus($pricePosition, 0.85, $side);

            $zoneWidthPct = ($range > 0.0 && $low > 0.0) ? round(($entryZoneHigh - $entryZoneLow) / $low, 6) : 0.0;
            $zoneDistanceFromPrice = ($currentPrice > 0.0 && $entryZoneHigh > 0.0) ? round(($currentPrice - $entryZoneHigh) / $currentPrice, 6) : 0.0;

            $monitors[] = [
                'symbol' => $symbol,
                'corridor_low' => $low,
                'corridor_high' => $high,
                'corridor_width' => $corridorWidth,
                'entry_zone_low' => round($entryZoneLow, 8),
                'entry_zone_high' => round($entryZoneHigh, 8),
                'price_position' => round($pricePosition, 4),
                'status' => $status,
                'side' => $side !== '' ? $side : (string)($candidate['side'] ?? ''),
                'entry_zone_percent' => $effectiveEntryZonePercent,
                'entry_zone_widened' => ($effectiveEntryZonePercent !== $entryZonePercent),
                'pattern_algorithm' => $patternAlgo !== '' ? $patternAlgo : 'none',
                'pattern_confidence' => $patternConfidence,
                'confirmation_score' => $confirmationScore,
                'trend_match_score' => (float)($candidate['trend_match_score'] ?? 0.0),
                'corridor_fit_score' => (float)($candidate['corridor_fit_score'] ?? 0.0),
                'entry_quality_score' => (float)($candidate['entry_quality_score'] ?? 0.0),
                'analyzer_score' => (float)($candidate['analyzer_score'] ?? 0.0),
                'volatility' => (float)($candidate['volatility'] ?? 0.0),
                'zone_width_pct' => $zoneWidthPct,
                'zone_distance_from_price' => $zoneDistanceFromPrice,
                'reject_detail' => $rejectDetail,
                'whatif_enter_now_status' => $whatifEnterNowStatus,
                'whatif_wider_zone_status' => $whatifWiderZoneStatus,
                'current_price_at_creation' => round($currentPrice, 8),
            ];
        }

        return $monitors;
    }

    /**
     * Compute effective entry zone percent with pattern-specific progressive widening.
     *
     * V2 contextual patterns use confidence-based progressive widening:
     *   - Base: max(config, 0.50)
     *   - Medium confidence (≥0.5): 0.65
     *   - High confidence (≥0.7): 0.80
     *
     * V3 remains at max(config, 0.40) — V2 must stay looser than V3.
     */
    private function computeEffectiveEntryZonePercent(
        float $basePercent,
        string $patternAlgo,
        float $patternConfidence,
        float $confirmationScore
    ): float {
        if ($patternAlgo === 'double_bottom_contextual_v2') {
            // Progressive widening based on confidence
            // Strong confirmation → price has already bounced significantly → wider zone needed
            $effectiveConfidence = max($patternConfidence, $confirmationScore);
            if ($effectiveConfidence >= 0.70) {
                return max($basePercent, 0.80);
            }
            if ($effectiveConfidence >= 0.50) {
                return max($basePercent, 0.65);
            }
            return max($basePercent, 0.50);
        }

        if ($patternAlgo === 'double_bottom_contextual_v3') {
            return max($basePercent, 0.40);
        }

        return $basePercent;
    }

    /**
     * Compute specific rejection detail for monitors not in entry_zone.
     *
     * @return string Specific reject reason or 'none' if in entry_zone
     */
    private function computeRejectDetail(
        string $status,
        float $pricePosition,
        float $entryZonePercent,
        string $side,
        float $currentPrice,
        float $entryZoneHigh,
        float $entryZoneLow
    ): string {
        if ($status === 'entry_zone') {
            return 'none';
        }

        if ($status === 'invalidated') {
            if ($pricePosition < 0.0) {
                return 'reject_invalidated_below_corridor';
            }
            return 'reject_invalidated_above_corridor';
        }

        // monitoring status — price is inside corridor but outside entry zone
        if ($side === 'short') {
            $zoneThreshold = 1.0 - $entryZonePercent;
            if ($pricePosition < $zoneThreshold) {
                return 'reject_price_below_zone';
            }
            return 'reject_monitoring_unknown';
        }

        // LONG: price is above entry zone
        if ($pricePosition > $entryZonePercent) {
            $distance = $pricePosition - $entryZonePercent;
            if ($distance > 0.30) {
                return 'reject_zone_too_far';
            }
            return 'reject_price_above_zone';
        }

        return 'reject_monitoring_unknown';
    }

    /**
     * Determine monitor status from price position (side-aware).
     */
    private function determineStatus(float $pricePosition, float $entryZonePercent, string $side = ''): string
    {
        if ($pricePosition < 0.0) {
            return 'invalidated';
        }

        if ($pricePosition >= 1.0) {
            return 'invalidated';
        }

        if ($side === 'short') {
            if ($pricePosition >= (1.0 - $entryZonePercent)) {
                return 'entry_zone';
            }
            return 'monitoring';
        }

        if ($pricePosition <= $entryZonePercent) {
            return 'entry_zone';
        }

        return 'monitoring';
    }
}
