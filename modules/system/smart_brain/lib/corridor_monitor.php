<?php
declare(strict_types=1);

final class CorridorMonitor
{
    /** @var array<string,mixed> */
    private array $cfg;

    /**
     * @param array<string,mixed> $cfg
     */
    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    /**
     * @param array<int,array<string,mixed>> $candidates
     * @return array<int,array<string,mixed>>
     */
    public function buildMonitors(array $candidates): array
    {
        $entryZonePercent = (float)($this->cfg['entry_zone_percent'] ?? 0.20);
        $monitors = [];

        foreach ($candidates as $candidate) {
            $low = (float)($candidate['corridor_low'] ?? 0.0);
            $high = (float)($candidate['corridor_high'] ?? 0.0);
            $width = max(0.0, $high - $low);

            $monitors[] = [
                'symbol' => (string)($candidate['symbol'] ?? ''),
                'corridor_low' => $low,
                'corridor_high' => $high,
                'entry_zone_low' => $low,
                'entry_zone_high' => $low + ($width * $entryZonePercent),
                'status' => 'waiting',
            ];
        }

        return $monitors;
    }
}
