<?php
declare(strict_types=1);

final class CoinPassportEngine
{
    private StateManager $state;

    public function __construct(StateManager $state)
    {
        $this->state = $state;
    }

    /**
     * @param array<int,array<string,mixed>> $candidates
     */
    public function update(array $candidates): void
    {
        foreach ($candidates as $candidate) {
            $symbol = (string)($candidate['symbol'] ?? '');
            if ($symbol === '') {
                continue;
            }

            $passport = [
                'symbol' => $symbol,
                'last_seen_at' => date('c'),
                'corridor_low' => $candidate['corridor_low'] ?? null,
                'corridor_high' => $candidate['corridor_high'] ?? null,
                'corridor_width' => $candidate['corridor_width'] ?? null,
                'volatility' => $candidate['volatility'] ?? null,
                'strength' => $candidate['strength'] ?? null,
                'trend_bias' => $candidate['trend_bias'] ?? null,
            ];

            $this->state->writeJson('storage/passports/' . $symbol . '.json', $passport);
        }
    }
}
