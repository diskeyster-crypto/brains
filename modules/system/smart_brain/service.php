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
            require_once $base . '/lib/ai_provider_openai.php';
            require_once $base . '/lib/ai_shadow_journal.php';
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
     * Expose the AiShadowService instance for controller use (journal etc.)
     */
    public function getAiShadowServicePublic(): ?AiShadowService
    {
        try {
            return $this->getAiShadowService();
        } catch (\Throwable) {
            return null;
        }
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

    /**
     * Test the AI Shadow provider connection.
     *
     * @return array<string,mixed>
     */
    public function testAiShadowConnection(): array
    {
        return $this->getAiShadowService()->testConnection();
    }

    // =========================================================================
    // Trading Bot control-plane (Brain acts as UI/control-plane only;
    // all execution logic stays in the trading_bot module)
    // =========================================================================

    /**
     * Lazy-load the TradingBotService.
     */
    private function getTradingBotService(): object
    {
        static $svc = null;
        if ($svc === null) {
            $base = __DIR__ . '/../trading_bot';
            require_once $base . '/bootstrap.php';
            require_once $base . '/service.php';
            $svc = new \Modules\System\TradingBot\TradingBotService();
        }
        return $svc;
    }

    /**
     * Resolve the bot module base path and derive storage directory.
     *
     * @return array{base:string|null,storageDir:string|null,mode:string}
     */
    private function resolveBotStorageDir(): array
    {
        $base = realpath(__DIR__ . '/../trading_bot');
        if ($base === false || !is_dir($base)) {
            return ['base' => null, 'storageDir' => null, 'mode' => 'paper'];
        }

        // Read config to determine mode
        $botJson = $base . '/config/bot.json';
        $configPhp = $base . '/config/config.php';

        $cfg = [];
        if (is_file($configPhp)) {
            $loaded = @include $configPhp;
            if (is_array($loaded)) {
                $cfg = $loaded;
            }
        }
        if (is_file($botJson)) {
            $j = @json_decode((string)@file_get_contents($botJson), true);
            if (is_array($j)) {
                if (isset($j['mode'])) {
                    $cfg['module']['mode'] = $j['mode'];
                }
                if (isset($j['enabled'])) {
                    $cfg['module']['enabled'] = (bool)$j['enabled'];
                }
            }
        }

        $mode = strtolower((string)($cfg['module']['mode'] ?? 'paper'));
        if ($mode === 'live') {
            $storageSuffix = 'storage_live';
        } elseif ($mode === 'demo') {
            $storageSuffix = 'storage_demo';
        } else {
            $storageSuffix = 'storage_paper';
        }

        return [
            'base'       => $base,
            'storageDir' => $base . '/' . $storageSuffix,
            'mode'       => $mode,
            'config'     => $cfg,
        ];
    }

    /**
     * Read a JSON file; return [] on failure.
     *
     * @return array<string,mixed>
     */
    private function readBotJsonFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $c = @file_get_contents($path);
        if ($c === false || trim($c) === '') {
            return [];
        }
        $d = @json_decode($c, true);
        return is_array($d) ? $d : [];
    }

    /**
     * Aggregate all data the Brain Execution page needs.
     *
     * @return array<string,mixed>
     */
    public function getTradingBotData(): array
    {
        $res = $this->resolveBotStorageDir();
        $storageDir = $res['storageDir'];
        $mode       = $res['mode'];
        $base       = $res['base'];
        $cfg        = $res['config'] ?? [];

        if ($storageDir === null || $base === null) {
            return [
                'bot_available'   => false,
                'bot_error'       => 'module_base_unknown',
                'bot_mode'        => 'paper',
                'bot_config'      => [],
                'bot_status'      => [],
                'bot_last_run'    => [],
                'bot_stats'       => [],
                'bot_positions'   => [],
                'bot_active_trades'  => [],
                'bot_closed_trades'  => [],
                'bot_balance'     => [],
            ];
        }

        // Load status
        $status = $this->readBotJsonFile($storageDir . '/runtime/status.json');

        // Load last_run
        $lastRun = $this->readBotJsonFile($storageDir . '/last_run.json');
        if (empty($lastRun)) {
            $lastRun = $this->readBotJsonFile($storageDir . '/runtime/last_run.json');
        }

        // Load stats
        $stats = $this->readBotJsonFile($storageDir . '/runtime/stats.json');

        // Load active trades
        $activeTrades = $this->readBotJsonFile($storageDir . '/trades/open_trades.json');
        if (!isset($activeTrades[0])) {
            // Might be keyed array — normalize
            $activeTrades = array_values($activeTrades);
        }

        // Load closed trades (last 50)
        $closedTradesRaw = $this->readBotJsonFile($storageDir . '/trades/closed_trades.json');
        if (!is_array($closedTradesRaw)) {
            $closedTradesRaw = [];
        }
        $closedTrades = array_values(array_slice(array_reverse($closedTradesRaw), 0, 50));

        // Load positions snapshot
        $positions = $this->readBotJsonFile($storageDir . '/runtime/positions.json');

        // Load balance snapshot
        $balance = $this->readBotJsonFile($storageDir . '/runtime/balance.json');

        // API base URL for display
        $apiBaseUrl = 'https://api.bybit.com';
        if ($mode === 'demo') {
            $apiBaseUrl = 'https://api-demo.bybit.com';
        } elseif ($mode === 'paper') {
            $apiBaseUrl = 'N/A (paper simulation)';
        }

        return [
            'bot_available'      => true,
            'bot_error'          => null,
            'bot_mode'           => $mode,
            'bot_enabled'        => (bool)($cfg['module']['enabled'] ?? false),
            'bot_config'         => $cfg,
            'bot_status'         => $status,
            'bot_last_run'       => $lastRun,
            'bot_stats'          => $stats,
            'bot_positions'      => $positions,
            'bot_active_trades'  => $activeTrades,
            'bot_closed_trades'  => $closedTrades,
            'bot_balance'        => $balance,
            'bot_storage_dir'    => $storageDir,
            'bot_api_base_url'   => $apiBaseUrl,
            'bot_is_real_exchange' => in_array($mode, ['live', 'demo'], true),
        ];
    }

    /**
     * Delegate: run one bot execution cycle.
     *
     * @return array<string,mixed>
     */
    public function runTradingBot(): array
    {
        try {
            return $this->getTradingBotService()->execute();
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Delegate: force-reconcile bot positions/orders.
     *
     * @return array<string,mixed>
     */
    public function reconcileTradingBot(): array
    {
        try {
            return $this->getTradingBotService()->forceReconcile();
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Delegate: get live bot status.
     *
     * @return array<string,mixed>
     */
    public function getTradingBotStatus(): array
    {
        try {
            return $this->getTradingBotService()->getStatus();
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Save allowed bot config keys to config/bot.json.
     *
     * @param array<string,mixed> $values
     * @return array{ok:bool,error?:string}
     */
    public function saveTradingBotConfig(array $values): array
    {
        $res = $this->resolveBotStorageDir();
        $base = $res['base'];
        if ($base === null) {
            return ['ok' => false, 'error' => 'module_base_unknown'];
        }

        $path = $base . '/config/bot.json';
        $current = is_file($path)
            ? (@json_decode((string)@file_get_contents($path), true) ?: [])
            : [];

        // Only allow safe top-level config keys to be written via Brain UI
        $allowed = [
            'enabled', 'mode',
            'max_positions', 'reconcile_before_action',
            'brain_source_enabled', 'brain_source_auto_run',
            'order_type', 'leverage_default',
            'stop_loss_pct', 'take_profit_pct',
            'trailing_enabled', 'trailing_mode',
            'trailing_activation_roi', 'trailing_drawdown_factor',
            'break_even_enabled', 'break_even_activation_roi',
            'emergency_stop_enabled', 'emergency_stop_loss_pct',
        ];

        foreach ($allowed as $key) {
            if (array_key_exists($key, $values)) {
                $current[$key] = $values[$key];
            }
        }

        $written = @file_put_contents(
            $path,
            json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        );

        return $written !== false
            ? ['ok' => true]
            : ['ok' => false, 'error' => 'write_failed'];
    }
}
