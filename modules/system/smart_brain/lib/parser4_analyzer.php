<?php
declare(strict_types=1);

final class Parser4Analyzer
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
     * Placeholder v2:
     * Parser4 is now a Smart Brain component.
     * Real history loading and corridor calculation will be implemented next.
     *
     * @return array<int,array<string,mixed>>
     */
    public function run(): array
    {
        $candidates = $this->state->readJson('storage/candidates.json', []);
        return array_values(array_filter($candidates, 'is_array'));
    }
}
