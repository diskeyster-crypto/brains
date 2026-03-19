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
     * For LONG candidates:
     *   - entry_zone_low  = corridor_low
     *   - entry_zone_high = corridor_low + range * entry_zone_percent
     *   - entry_zone when price_position <= entry_zone_percent
     *
     * For SHORT candidates:
     *   - entry_zone_low  = corridor_high - range * entry_zone_percent
     *   - entry_zone_high = corridor_high
     *   - entry_zone when price_position >= (1 - entry_zone_percent)
     *
     * price_position = (current_price - corridor_low) / (corridor_high - corridor_low)
     * status = monitoring | entry_zone | invalidated
     *
     * @param array<int,array<string,mixed>> $candidates
     * @param array<string,float>            $prices  Optional real-time prices (symbol→price).
     *                                                 Falls back to candidate last_price if absent.
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

            // Use real price if available, otherwise fallback to candidate last_price
            $currentPrice = (isset($prices[$symbol]) && $prices[$symbol] > 0.0)
                ? $prices[$symbol]
                : (float)($candidate['last_price'] ?? 0.0);

            $range = $high - $low;

            // Side-aware entry zone boundaries:
            //   LONG  zone = bottom slice of corridor
            //   SHORT zone = top slice of corridor
            if ($side === 'short') {
                $entryZoneLow = ($range > 0.0)
                    ? $high - ($range * $entryZonePercent)
                    : $high;
                $entryZoneHigh = $high;
            } else {
                // Default to LONG logic (includes empty/unknown side for safety)
                $entryZoneLow = $low;
                $entryZoneHigh = ($range > 0.0)
                    ? $low + ($range * $entryZonePercent)
                    : $low;
            }

            // Price position: 0..1 inside corridor, <0 below, >1 above
            $pricePosition = ($range > 0.0 && $currentPrice > 0.0)
                ? ($currentPrice - $low) / $range
                : 0.5;  // default to mid if no data

            // Side-aware status determination
            $status = $this->determineStatus($pricePosition, $entryZonePercent, $side);

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
                'entry_zone_percent' => $entryZonePercent,
                'pattern_algorithm' => (string)($candidate['pattern_algorithm'] ?? 'none'),
                'pattern_confidence' => (float)($candidate['pattern_confidence'] ?? 0.0),
                'trend_match_score' => (float)($candidate['trend_match_score'] ?? 0.0),
                'corridor_fit_score' => (float)($candidate['corridor_fit_score'] ?? 0.0),
                'entry_quality_score' => (float)($candidate['entry_quality_score'] ?? 0.0),
                'analyzer_score' => (float)($candidate['analyzer_score'] ?? 0.0),
                'volatility' => (float)($candidate['volatility'] ?? 0.0),
            ];
        }

        return $monitors;
    }

    /**
     * Determine monitor status from price position (side-aware).
     *
     * LONG:
     *   - price_position < 0                        → invalidated (below corridor)
     *   - 0 <= pp <= entry_zone_percent              → entry_zone
     *   - entry_zone_percent < pp < 1                → monitoring
     *   - pp >= 1                                    → invalidated (above corridor)
     *
     * SHORT:
     *   - price_position < 0                         → invalidated (below corridor)
     *   - pp >= (1 - entry_zone_percent) AND pp < 1  → entry_zone
     *   - 0 <= pp < (1 - entry_zone_percent)         → monitoring
     *   - pp >= 1                                    → invalidated (above corridor)
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
            // SHORT: entry zone is the upper slice of the corridor
            if ($pricePosition >= (1.0 - $entryZonePercent)) {
                return 'entry_zone';
            }
            return 'monitoring';
        }

        // LONG (default): entry zone is the lower slice of the corridor
        if ($pricePosition <= $entryZonePercent) {
            return 'entry_zone';
        }

        return 'monitoring';
    }
}
