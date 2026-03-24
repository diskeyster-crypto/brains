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
                'pattern_algorithm' => $row['pattern_algorithm'] ?? 'none',
                'pattern_confidence' => (float)($row['pattern_confidence'] ?? 0.0),
                'confirmation_score' => (float)($row['confirmation_score'] ?? 0.0),
                'confirmation_tier' => $row['confirmation_tier'] ?? 'none',
                'entry_action' => $row['entry_action'] ?? 'wait_retrace',
                'zone_widen_profile' => $row['zone_widen_profile'] ?? 'default',
                'v2_priority_score' => (float)($row['v2_priority_score'] ?? 0.0),
            ];
        }

        return $signals;
    }
}
