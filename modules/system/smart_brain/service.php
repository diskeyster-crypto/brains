<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/smart_brain_core.php';

final class SmartBrainService
{
    private SmartBrainCore $core;

    public function __construct()
    {
        $this->core = new SmartBrainCore(__DIR__);
    }

    /**
     * Run full Smart Brain cycle.
     *
     * @return array<string,mixed>
     */
    public function run(): array
    {
        return $this->core->run();
    }

    /**
     * @return array<string,mixed>
     */
    public function getDashboardData(): array
    {
        return $this->core->getDashboardData();
    }

    /**
     * @return array<string,mixed>
     */
    public function getRuntimeData(): array
    {
        return $this->core->getRuntimeData();
    }
}
