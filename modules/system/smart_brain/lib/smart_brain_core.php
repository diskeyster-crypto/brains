<?php
declare(strict_types=1);

require_once __DIR__ . '/smart_brain_config.php';
require_once __DIR__ . '/smart_brain_logger.php';
require_once __DIR__ . '/state_manager.php';
require_once __DIR__ . '/parser4_analyzer.php';
require_once __DIR__ . '/corridor_monitor.php';
require_once __DIR__ . '/coin_passport_engine.php';
require_once __DIR__ . '/risk_engine.php';
require_once __DIR__ . '/signal_builder.php';
require_once __DIR__ . '/simulator_engine.php';
require_once __DIR__ . '/smart_brain_runtime.php';

final class SmartBrainCore
{
    private string $moduleBase;
    private SmartBrainConfig $config;
    private SmartBrainLogger $logger;
    private StateManager $state;

    public function __construct(string $moduleBase)
    {
        $this->moduleBase = rtrim($moduleBase, '/');
        $this->config = new SmartBrainConfig($this->moduleBase);
        $this->logger = new SmartBrainLogger($this->moduleBase);
        $this->state = new StateManager($this->moduleBase);
    }

    /**
     * @return array<string,mixed>
     */
    public function run(): array
    {
        $cfg = $this->config->all();

        $this->logger->log('info', 'Smart Brain cycle started');

        $parser = new Parser4Analyzer((array)$cfg['parser4'], $this->state);
        $candidates = $parser->run();

        $corridor = new CorridorMonitor((array)$cfg['corridor']);
        $monitors = $corridor->buildMonitors($candidates);
        $this->state->writeJson('storage/monitors.json', $monitors);

        $passports = new CoinPassportEngine($this->state);
        $passports->update($candidates);

        $risk = new RiskEngine((array)$cfg['risk_engine'], (array)$cfg['profiles']);
        $riskMonitors = $risk->apply($monitors);

        $signalBuilder = new SignalBuilder();
        $signals = $signalBuilder->build($riskMonitors);
        $this->state->writeJson('storage/signals.json', $signals);

        $simulator = new SimulatorEngine((array)$cfg['simulator'], $this->state);
        $simulator->sync($signals);

        $runtime = new SmartBrainRuntime($this->state);
        $runtime->snapshot($cfg);

        $result = [
            'ok' => true,
            'updated_at' => date('c'),
            'candidates' => count($candidates),
            'monitors' => count($monitors),
            'signals' => count($signals),
        ];

        $this->state->writeJson('storage/last_run.json', $result);
        $this->logger->log('info', 'Smart Brain cycle finished: signals=' . count($signals));

        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    public function getDashboardData(): array
    {
        return [
            'title' => (string)($this->config->get('ui')['title'] ?? 'Smart Brain'),
            'last_run' => $this->state->readJson('storage/last_run.json', []),
            'signals' => $this->state->readJson('storage/signals.json', []),
            'monitors' => $this->state->readJson('storage/monitors.json', []),
            'waiting' => $this->state->readJson('storage/simulator/waiting.json', []),
            'active' => $this->state->readJson('storage/simulator/active.json', []),
            'closed' => $this->state->readJson('storage/simulator/closed.json', []),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getRuntimeData(): array
    {
        return [
            'config' => $this->config->all(),
            'snapshot' => $this->state->readJson('runtime/config.snapshot.json', []),
            'last_run' => $this->state->readJson('storage/last_run.json', []),
        ];
    }
}
