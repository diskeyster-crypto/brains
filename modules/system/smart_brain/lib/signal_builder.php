<?php
declare(strict_types=1);

final class SignalBuilder
{
    /**
     * @param array<int,array<string,mixed>> $riskMonitors
     * @return array<int,array<string,mixed>>
     */
    public function build(array $riskMonitors): array
    {
        $signals = [];

        foreach ($riskMonitors as $row) {
            $signals[] = [
                'symbol' => $row['symbol'] ?? '',
                'entry_zone_low' => $row['entry_zone_low'] ?? null,
                'entry_zone_high' => $row['entry_zone_high'] ?? null,
                'corridor_low' => $row['corridor_low'] ?? null,
                'corridor_high' => $row['corridor_high'] ?? null,
                'leverage' => $row['leverage'] ?? null,
                'budget' => $row['budget'] ?? null,
                'status' => 'waiting',
            ];
        }

        return $signals;
    }
}
