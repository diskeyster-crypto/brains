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
 * V3 contextual patterns also use tier-based progressive widening
 * (stricter defaults than V2) and allow enter_now for strong tier.
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

            // Compute full V2 policy fields for monitor payload consistency
            $policyFields = self::computeV2PolicyFields($candidate, $this->cfg);

            // enter_now promotion: if entry_action = enter_now and monitor is only
            // 'monitoring' (not invalidated), promote to 'entry_zone' so the Risk Engine
            // can emit a real signal. Without this, enter_now is decorative metadata.
            $enterNowPromoted = false;
            if ($policyFields['entry_action'] === 'enter_now'
                && $status === 'monitoring'
                && $pricePosition >= 0.0
                && $pricePosition < 1.0
            ) {
                $status = 'entry_zone';
                $rejectDetail = 'none';
                $enterNowPromoted = true;
            }

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
                'confirmation_tier' => $policyFields['confirmation_tier'],
                'entry_action' => $policyFields['entry_action'],
                'zone_widen_profile' => $policyFields['zone_widen_profile'],
                'v2_priority_score' => $policyFields['v2_priority_score'],
                'reclaim_strength_score' => (float)($candidate['reclaim_strength_score'] ?? 0.0),
                'hold_quality_score' => (float)($candidate['hold_quality_score'] ?? 0.0),
                'post_reclaim_stability_score' => (float)($candidate['post_reclaim_stability_score'] ?? 0.0),
                'zone_defense_score' => (float)($candidate['zone_defense_score'] ?? 0.0),
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
                'enter_now_promoted' => $enterNowPromoted,
            ];
        }

        return $monitors;
    }

    /**
     * Compute effective entry zone percent with pattern-specific progressive widening.
     *
     * V2 contextual patterns use confirmation-tier-based progressive widening:
     *   - weak tier   → v2_zone_widen_weak_pct   (default 0.50)
     *   - medium tier → v2_zone_widen_medium_pct  (default 0.65)
     *   - strong tier → v2_zone_widen_strong_pct  (default 0.80)
     *   Capped by v2_zone_widen_max_cap_pct (default 0.85).
     *
     * V3 contextual patterns use V3-specific tier-based widening (stricter than V2):
     *   - weak tier   → v3_zone_widen_weak_pct   (default 0.40)
     *   - medium tier → v3_zone_widen_medium_pct  (default 0.55)
     *   - strong tier → v3_zone_widen_strong_pct  (default 0.70)
     *   Capped by v3_zone_widen_max_cap_pct (default 0.75).
     */
    private function computeEffectiveEntryZonePercent(
        float $basePercent,
        string $patternAlgo,
        float $patternConfidence,
        float $confirmationScore
    ): float {
        if ($patternAlgo === 'double_bottom_contextual_v2') {
            $tier = self::computeConfirmationTier($confirmationScore, $this->cfg);
            $cap = (float)($this->cfg['v2_zone_widen_max_cap_pct'] ?? 0.85);

            if ($tier === 'strong') {
                $target = (float)($this->cfg['v2_zone_widen_strong_pct'] ?? 0.80);
            } elseif ($tier === 'medium') {
                $target = (float)($this->cfg['v2_zone_widen_medium_pct'] ?? 0.65);
            } else {
                $target = (float)($this->cfg['v2_zone_widen_weak_pct'] ?? 0.50);
            }

            return min(max($basePercent, $target), $cap);
        }

        if ($patternAlgo === 'double_bottom_contextual_v3') {
            $v3Tier = self::computeV3ConfirmationTier($confirmationScore);
            $v3Cap = (float)($this->cfg['v3_zone_widen_max_cap_pct'] ?? 0.75);

            if ($v3Tier === 'strong') {
                $v3Target = (float)($this->cfg['v3_zone_widen_strong_pct'] ?? 0.70);
            } elseif ($v3Tier === 'medium') {
                $v3Target = (float)($this->cfg['v3_zone_widen_medium_pct'] ?? 0.55);
            } else {
                $v3Target = (float)($this->cfg['v3_zone_widen_weak_pct'] ?? 0.40);
            }

            return min(max($basePercent, $v3Target), $v3Cap);
        }

        return $basePercent;
    }

    /**
     * Classify V2 confirmation_score into a tier.
     *
     * @param float $confirmationScore  The raw confirmation score (0.0 – 1.0)
     * @param array<string,mixed> $cfg  Config containing tier thresholds
     * @return string  'weak' | 'medium' | 'strong'
     */
    public static function computeConfirmationTier(float $confirmationScore, array $cfg = []): string
    {
        $strongMin = (float)($cfg['v2_confirmation_strong_min'] ?? 0.70);
        $weakMax   = (float)($cfg['v2_confirmation_weak_max'] ?? 0.45);

        if ($confirmationScore >= $strongMin) {
            return 'strong';
        }
        if ($confirmationScore < $weakMax) {
            return 'weak';
        }
        return 'medium';
    }

    /**
     * Compute confirmation tier for V3 contextual patterns.
     *
     * V3 is stricter by nature, so thresholds differ from V2:
     *   - weak:   confirmation_score < 0.50
     *   - medium: 0.50 <= confirmation_score < 0.75
     *   - strong: confirmation_score >= 0.75
     *
     * @param float $confirmationScore  The raw confirmation score (0.0 – 1.0)
     * @return string  'weak' | 'medium' | 'strong'
     */
    public static function computeV3ConfirmationTier(float $confirmationScore): string
    {
        if ($confirmationScore >= 0.75) {
            return 'strong';
        }
        if ($confirmationScore < 0.50) {
            return 'weak';
        }
        return 'medium';
    }

    /**
     * Compute all V2/V3 policy fields for a candidate/monitor.
     *
     * Returns confirmation_tier, entry_action, zone_widen_profile, v2_priority_score.
     * Non-V2 patterns get neutral defaults.
     *
     * @param array<string,mixed> $candidate  Candidate or monitor payload
     * @param array<string,mixed> $cfg        Config containing tier thresholds
     * @return array{confirmation_tier: string, entry_action: string, zone_widen_profile: string, v2_priority_score: float}
     */
    public static function computeV2PolicyFields(array $candidate, array $cfg = []): array
    {
        $patternAlgo = (string)($candidate['pattern_algorithm'] ?? 'none');
        $confirmationScore = (float)($candidate['confirmation_score'] ?? 0.0);

        // V3 contextual patterns also get real confirmation tier and policy fields
        if ($patternAlgo === 'double_bottom_contextual_v3' || $patternAlgo === 'double_top_contextual_v3') {
            $tier = self::computeV3ConfirmationTier($confirmationScore);

            // V3 entry action: strong confirmations can enter_now (configurable)
            $v3StrongEnterNow = (bool)($cfg['v3_strong_enter_now_enabled'] ?? true);
            if ($tier === 'strong' && $v3StrongEnterNow) {
                $entryAction = 'enter_now';
            } else {
                $entryAction = 'wait_retrace';
            }

            $zoneWidenProfile = match ($tier) {
                'strong' => 'strong_wide',
                'medium' => 'medium_wide',
                'weak'   => 'narrow',
                default  => 'default',
            };

            $patternConfidence = (float)($candidate['pattern_confidence'] ?? 0.0);
            $analyzerScore = (float)($candidate['analyzer_score'] ?? 0.0);
            $reclaimScore = (float)($candidate['reclaim_strength_score'] ?? 0.0);
            $v3PriorityScore = round(
                ($confirmationScore * 0.40) +
                ($patternConfidence * 0.25) +
                ($analyzerScore * 0.20) +
                ($reclaimScore * 0.15),
                4
            );

            return [
                'confirmation_tier' => $tier,
                'entry_action' => $entryAction,
                'zone_widen_profile' => $zoneWidenProfile,
                'v2_priority_score' => $v3PriorityScore,
            ];
        }

        // V2 contextual patterns: bottom (long) and top (short)
        if ($patternAlgo !== 'double_bottom_contextual_v2' && $patternAlgo !== 'double_top_contextual_v2') {
            return [
                'confirmation_tier' => 'none',
                'entry_action' => 'wait_retrace',
                'zone_widen_profile' => 'default',
                'v2_priority_score' => 0.0,
            ];
        }

        $tier = self::computeConfirmationTier($confirmationScore, $cfg);

        // Short V2 entry policy: after valid breakdown, price often continues down immediately.
        // Strong and medium short confirmations use enter_now to avoid stale retrace-biased zone.
        if ($patternAlgo === 'double_top_contextual_v2') {
            $entryAction = match ($tier) {
                'strong' => 'enter_now',
                'medium' => 'enter_now',
                default  => 'wait_retrace',
            };
        } else {
            $entryAction = match ($tier) {
                'strong' => 'enter_now',
                default  => 'wait_retrace',
            };
        }

        $zoneWidenProfile = match ($tier) {
            'strong' => 'strong_wide',
            'medium' => 'medium_wide',
            'weak'   => 'narrow',
            default  => 'default',
        };

        $patternConfidence = (float)($candidate['pattern_confidence'] ?? 0.0);
        $analyzerScore = (float)($candidate['analyzer_score'] ?? 0.0);
        $reclaimScore = (float)($candidate['reclaim_strength_score'] ?? 0.0);
        $v2PriorityScore = round(
            ($confirmationScore * 0.40) +
            ($patternConfidence * 0.25) +
            ($analyzerScore * 0.20) +
            ($reclaimScore * 0.15),
            4
        );

        return [
            'confirmation_tier' => $tier,
            'entry_action' => $entryAction,
            'zone_widen_profile' => $zoneWidenProfile,
            'v2_priority_score' => $v2PriorityScore,
        ];
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
                // Graduated rejection for shorts (mirroring long-side logic)
                $distance = $zoneThreshold - $pricePosition;
                if ($distance > 0.30) {
                    return 'reject_zone_too_far';
                }
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
