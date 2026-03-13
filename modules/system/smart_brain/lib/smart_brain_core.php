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
        $this->logger->log('info', 'Smart Brain cycle started');

        $parser4Cfg = $this->config->getEffective('parser4');
        $corridorCfg = $this->config->getEffective('corridor');
        $riskCfg = $this->config->getEffective('risk_engine');
        $profilesCfg = $this->config->getEffective('profiles');
        $simulatorCfg = $this->config->getEffective('simulator');

        $parser = new Parser4Analyzer($parser4Cfg, $this->state);
        $candidates = $parser->run();

        $corridor = new CorridorMonitor($corridorCfg);
        $monitors = $corridor->buildMonitors($candidates);
        $this->state->writeJson('storage/monitors.json', $monitors);

        $passports = new CoinPassportEngine($this->state);
        $passports->update($candidates);

        $risk = new RiskEngine($riskCfg, $profilesCfg);
        $riskMonitors = $risk->apply($monitors);

        $signalBuilder = new SignalBuilder();
        $signals = $signalBuilder->build($riskMonitors);
        $this->state->writeJson('storage/signals.json', $signals);

        $simulator = new SimulatorEngine($simulatorCfg, $this->state);
        $simulator->sync($signals);

        $runtime = new SmartBrainRuntime($this->state);
        $runtime->snapshot($this->config->all());

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
        $uiSettings = $this->config->getEffective('ui');
        return [
            'title' => (string)($uiSettings['title'] ?? 'Smart Brain'),
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

    /**
     * @return array<string,mixed>
     */
    public function getConfigData(): array
    {
        return $this->config->all();
    }

    /**
     * @return array<string,mixed>
     */
    public function getAnalizatorData(): array
    {
        return [
            'candidates' => $this->state->readJson('storage/candidates.json', []),
            'signals' => $this->state->readJson('storage/signals.json', []),
            'monitors' => $this->state->readJson('storage/monitors.json', []),
            'last_run' => $this->state->readJson('storage/last_run.json', []),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getSimulatorData(): array
    {
        return [
            'waiting' => $this->state->readJson('storage/simulator/waiting.json', []),
            'active' => $this->state->readJson('storage/simulator/active.json', []),
            'closed' => $this->state->readJson('storage/simulator/closed.json', []),
            'last_run' => $this->state->readJson('storage/last_run.json', []),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getPassportsData(): array
    {
        $passportsDir = $this->moduleBase . '/storage/passports';
        $passports = [];

        if (is_dir($passportsDir)) {
            $files = glob($passportsDir . '/*.json');
            if ($files) {
                foreach ($files as $file) {
                    $data = json_decode((string)file_get_contents($file), true);
                    if (is_array($data)) {
                        $passports[] = $data;
                    }
                }
            }
        }

        return [
            'passports' => $passports,
            'count' => count($passports),
        ];
    }
}
