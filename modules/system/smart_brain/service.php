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
    public function getUserConfigData(): array
    {
        return $this->core->getUserConfigData();
    }

    /**
     * @param array<string,mixed> $values
     * @return array{ok:bool,errors:list<string>}
     */
    public function saveUserConfig(array $values): array
    {
        return $this->core->saveUserConfig($values);
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
    public function getSimulatorAnalyticsData(): array
    {
        return $this->core->getSimulatorAnalyticsData();
    }

    /**
     * @return array<string,mixed>
     */
    public function getPassportsData(): array
    {
        return $this->core->getPassportsData();
    }

    /**
     * @return array<string,mixed>
     */
    public function getLivePerformanceData(): array
    {
        return $this->core->getLivePerformanceData();
    }

    /**
     * Save manual live blacklist.
     *
     * @param list<string> $symbols
     * @return array{ok:bool,count:int,symbols:list<string>}
     */
    public function saveManualBlacklist(array $symbols): array
    {
        return $this->core->saveManualBlacklist($symbols);
    }

    /**
     * Load manual live blacklist.
     *
     * @return array{symbols:list<string>,count:int,valid:bool,warning:string}
     */
    public function loadManualBlacklist(): array
    {
        return $this->core->loadManualBlacklist();
    }

    // =========================================================================
    // AI Shadow delegation
    // =========================================================================

    /**
     * Build and return a lazy AiShadowService instance.
     */
    private function getAiShadowService(): AiShadowService
    {
        static $svc = null;
        if ($svc === null) {
            $base = __DIR__ . '/../ai_shadow';
            require_once $base . '/lib/ai_shadow_state_manager.php';
            require_once $base . '/lib/ai_provider_interface.php';
            require_once $base . '/lib/ai_provider_mock.php';
            require_once $base . '/lib/virtual_lifecycle.php';
            require_once $base . '/lib/signal_mirror.php';
            require_once $base . '/lib/trade_mirror.php';
            require_once $base . '/lib/stats_engine.php';
            require_once $base . '/lib/ai_shadow_core.php';
            require_once $base . '/service.php';
            $svc = new AiShadowService();
        }
        return $svc;
    }

    /**
     * @return array<string,mixed>
     */
    public function getAiShadowConfig(): array
    {
        $path = __DIR__ . '/../ai_shadow/config/ai_shadow.json';
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    /**
     * @return array<string,mixed>
     */
    public function getAiShadowData(): array
    {
        $svc  = $this->getAiShadowService();
        return [
            'ai_shadow_config'         => $this->getAiShadowConfig(),
            'ai_shadow_stats'          => $svc->getStats(),
            'ai_shadow_status'         => $svc->getStatus(),
            'ai_shadow_closed_trades'  => $svc->getVirtualClosedTrades(),
            'ai_shadow_active_trades'  => $svc->getVirtualActiveTrades(),
            'ai_shadow_signals'        => $svc->getVirtualSignals(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function runAiShadowMirror(): array
    {
        return $this->getAiShadowService()->runLiveMirror();
    }

    /**
     * @param array<int,array<string,mixed>> $historicalSignals
     * @return array<string,mixed>
     */
    public function runAiShadowReplay(array $historicalSignals = []): array
    {
        return $this->getAiShadowService()->runReplay($historicalSignals);
    }

    /**
     * Save AI Shadow settings.  Raw secrets must NOT be in $values.
     *
     * @param array<string,mixed> $values
     * @return array{ok:bool,error?:string}
     */
    public function saveAiShadowSettings(array $values): array
    {
        $path = __DIR__ . '/../ai_shadow/config/ai_shadow.json';
        $current = is_file($path)
            ? (json_decode((string)file_get_contents($path), true) ?: [])
            : [];

        $allowed = [
            'enabled', 'mode', 'provider', 'model', 'credential_id',
            'allowed_patterns', 'allowed_sides',
            'simulate_on_live_signals', 'simulate_on_live_trades',
            'store_prototypes', 'store_images',
            'max_signals_per_run', 'max_trades_per_run',
            'confidence_threshold_enter', 'confidence_threshold_skip',
            'quality_score_threshold',
            'log_enabled', 'log_decisions', 'log_rejections',
        ];

        foreach ($allowed as $key) {
            if (array_key_exists($key, $values)) {
                $current[$key] = $values[$key];
            }
        }

        $current['mode'] = 'shadow';

        $written = file_put_contents(
            $path,
            json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        );

        return $written !== false ? ['ok' => true] : ['ok' => false, 'error' => 'write_failed'];
    }

    /**
     * @return array<string,mixed>
     */
    public function getAiShadowStats(): array
    {
        return $this->getAiShadowService()->getStats();
    }

    public function clearAiShadowStorage(): void
    {
        $this->getAiShadowService()->clearStorage();
    }
}
