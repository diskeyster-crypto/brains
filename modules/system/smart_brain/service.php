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
     * Cron handler: CronManager calls this method.
     *
     * @return array<string,mixed>
     */
    public function execute(): array
    {
        return $this->core->run('cron');
    }

    /**
     * Run full Smart Brain cycle.
     *
     * @param string $source  'cron' or 'manual'
     * @return array<string,mixed>
     */
    public function run(string $source = 'cron'): array
    {
        return $this->core->run($source);
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

    /**
     * @return array<string,mixed>
     */
    public function getConfig(): array
    {
        return $this->core->getConfigData();
    }

    /**
     * @return array<string,mixed>
     */
    public function getAnalizatorData(): array
    {
        return $this->core->getAnalizatorData();
    }

    /**
     * @return array<string,mixed>
     */
    public function getSimulatorData(): array
    {
        return $this->core->getSimulatorData();
    }

    /**
     * @return array<string,mixed>
     */
    public function getPassportsData(): array
    {
        return $this->core->getPassportsData();
    }
}
