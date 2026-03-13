<?php
declare(strict_types=1);

final class SimulatorEngine
{
    /** @var array<string,mixed> */
    private array $cfg;
    private StateManager $state;

    /**
     * @param array<string,mixed> $cfg
     */
    public function __construct(array $cfg, StateManager $state)
    {
        $this->cfg = $cfg;
        $this->state = $state;
    }

    /**
     * @param array<int,array<string,mixed>> $signals
     */
    public function sync(array $signals): void
    {
        if (($this->cfg['enabled'] ?? false) !== true) {
            return;
        }

        $waiting = [];
        foreach ($signals as $signal) {
            $waiting[] = [
                'symbol' => $signal['symbol'] ?? '',
                'entry_zone_low' => $signal['entry_zone_low'] ?? null,
                'entry_zone_high' => $signal['entry_zone_high'] ?? null,
                'budget' => $signal['budget'] ?? null,
                'leverage' => $signal['leverage'] ?? null,
                'stoploss' => null,
                'takeprofit' => null,
                'status' => 'waiting',
            ];
        }

        $this->state->writeJson('storage/simulator/waiting.json', $waiting);
        $this->state->writeJson('storage/simulator/active.json', $this->state->readJson('storage/simulator/active.json', []));
        $this->state->writeJson('storage/simulator/closed.json', $this->state->readJson('storage/simulator/closed.json', []));
    }
}
