<?php
declare(strict_types=1);

/**
 * Corridor Monitor — Smart Brain Phase 4
 *
 * Transforms candidates into monitored trade opportunities.
 * Calculates entry zones, price position, and status.
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
     * Build real monitors from candidates.
     *
     * For each candidate:
     *   - entry_zone_low  = corridor_low
     *   - entry_zone_high = corridor_low + (corridor_high - corridor_low) * entry_zone_percent
     *   - price_position  = (current_price - corridor_low) / (corridor_high - corridor_low)
     *   - status = monitoring | entry_zone | invalidated
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

            // Use real price if available, otherwise fallback to candidate last_price
            $currentPrice = (isset($prices[$symbol]) && $prices[$symbol] > 0.0)
                ? $prices[$symbol]
                : (float)($candidate['last_price'] ?? 0.0);

            $range = $high - $low;

            // Entry zone
            $entryZoneLow = $low;
            $entryZoneHigh = ($range > 0.0)
                ? $low + ($range * $entryZonePercent)
                : $low;

            // Price position: 0..1 inside corridor, <0 below, >1 above
            $pricePosition = ($range > 0.0 && $currentPrice > 0.0)
                ? ($currentPrice - $low) / $range
                : 0.5;  // default to mid if no data

            // Determine status
            $status = $this->determineStatus($pricePosition, $entryZonePercent);

            $monitors[] = [
                'symbol' => (string)($candidate['symbol'] ?? ''),
                'corridor_low' => $low,
                'corridor_high' => $high,
                'corridor_width' => $corridorWidth,
                'entry_zone_low' => round($entryZoneLow, 8),
                'entry_zone_high' => round($entryZoneHigh, 8),
                'price_position' => round($pricePosition, 4),
                'status' => $status,
            ];
        }

        return $monitors;
    }

    /**
     * Determine monitor status from price position.
     *
     * - price_position < 0          → invalidated (below corridor)
     * - 0 <= pp <= entry_zone_pct   → entry_zone
     * - entry_zone_pct < pp < 1     → monitoring
     * - pp >= 1                     → invalidated (above corridor)
     */
    private function determineStatus(float $pricePosition, float $entryZonePercent): string
    {
        if ($pricePosition < 0.0) {
            return 'invalidated';
        }

        if ($pricePosition >= 1.0) {
            return 'invalidated';
        }

        if ($pricePosition <= $entryZonePercent) {
            return 'entry_zone';
        }

        return 'monitoring';
    }
}
