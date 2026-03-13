<?php
declare(strict_types=1);

final class SmartBrainRuntime
{
    private StateManager $state;

    public function __construct(StateManager $state)
    {
        $this->state = $state;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function snapshot(array $data): void
    {
        $data['updated_at'] = date('c');
        $this->state->writeJson('runtime/config.snapshot.json', $data);
    }
}
