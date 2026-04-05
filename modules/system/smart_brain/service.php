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
                // Expose credentials from flat top-level key (matches bot_config_trait reader)
                if (isset($j['credentials']) && is_array($j['credentials'])) {
                    $cfg['module']['credentials'] = $j['credentials'];
                }
                // Merge full config blocks so getTradingBotData() returns a complete bot_config
                // and the execution page can render the real saved values without reverting.
                if (isset($j['execution']) && is_array($j['execution'])) {
                    $cfg['execution'] = $j['execution'];
                }
                if (isset($j['sources']) && is_array($j['sources'])) {
                    $cfg['sources'] = $j['sources'];
                }
                if (isset($j['exchange']) && is_array($j['exchange'])) {
                    $cfg['exchange'] = $j['exchange'];
                }
                if (isset($j['demo_learning_mode']) && is_array($j['demo_learning_mode'])) {
                    $cfg['demo_learning_mode'] = $j['demo_learning_mode'];
                }
                if (isset($j['demo_sources']) && is_array($j['demo_sources'])) {
                    $cfg['demo_sources'] = $j['demo_sources'];
                }
                // Flat keys read directly by execution.php
                if (isset($j['reconcile_before_action'])) {
                    $cfg['reconcile_before_action'] = (bool)$j['reconcile_before_action'];
                }
                if (isset($j['max_positions'])) {
                    $cfg['max_positions'] = (int)$j['max_positions'];
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
     * Scan a trades directory and return all trade records as a flat array.
     * Used as fallback when aggregate snapshot files are absent.
     *
     * @return array<int,array<string,mixed>>
     */
    private function scanTradesDir(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $trades = [];
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $c = @file_get_contents($file);
            if ($c === false || trim($c) === '') {
                continue;
            }
            $d = @json_decode($c, true);
            if (is_array($d)) {
                $trades[] = $d;
            }
        }
        return $trades;
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

        // Load active trades — prefer aggregate snapshot, fall back to per-file scan
        $activeTrades = $this->readBotJsonFile($storageDir . '/trades/open_trades.json');
        if (empty($activeTrades)) {
            $activeTrades = $this->scanTradesDir($storageDir . '/trades/active');
        }
        if (!isset($activeTrades[0])) {
            $activeTrades = array_values($activeTrades);
        }

        // Load closed trades (last 50) — prefer aggregate snapshot, fall back to per-file scan
        $closedTradesRaw = $this->readBotJsonFile($storageDir . '/trades/closed_trades.json');
        if (!is_array($closedTradesRaw) || empty($closedTradesRaw)) {
            $closedTradesRaw = $this->scanTradesDir($storageDir . '/trades/closed');
        }
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
            $demoCreds = $cfg['module']['credentials']['demo'] ?? [];
            $apiBaseUrl = trim((string)($demoCreds['api_base_url'] ?? 'https://api-demo.bybit.com'));
        } elseif ($mode === 'paper') {
            $apiBaseUrl = 'N/A (paper simulation)';
        }

        // Demo credentials — expose key (masked) but NEVER the secret in plain text
        $demoCreds = $cfg['module']['credentials']['demo'] ?? [];
        $demoApiKey = (string)($demoCreds['api_key'] ?? '');
        $demoApiSecretSet = (string)($demoCreds['api_secret'] ?? '') !== '';
        $botDemoCreds = [
            'api_key'         => $demoApiKey,
            'api_secret_set'  => $demoApiSecretSet,
            'api_base_url'    => (string)($demoCreds['api_base_url'] ?? 'https://api-demo.bybit.com'),
        ];

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
            'bot_demo_creds'     => $botDemoCreds,
            'bot_diag'           => [
                'mode'                    => $mode,
                'storage_namespace'       => basename($storageDir),
                'config_path'             => $base . '/config/bot.json',
                'demo_api_key_present'    => $demoApiKey !== '',
                'demo_api_secret_present' => $demoApiSecretSet,
                'demo_api_base_url'       => $botDemoCreds['api_base_url'],
                'is_real_exchange_mode'   => in_array($mode, ['live', 'demo'], true),
            ],
            'bot_config_debug'   => [
                'config_path'                     => $base . '/config/bot.json',
                'mode'                            => $mode,
                'execution_reverse_side_enabled'  => (bool)(($cfg['execution']['reverse_side_enabled'] ?? null)),
                'execution_trailing_enabled'      => (bool)(($cfg['execution']['trailing_enabled'] ?? null)),
                'execution_break_even_enabled'    => (bool)(($cfg['execution']['break_even_enabled'] ?? null)),
                'execution_emergency_stop_enabled'=> (bool)(($cfg['execution']['emergency_stop_enabled'] ?? null)),
                'sources_brain_source_enabled'    => (bool)(($cfg['sources']['brain_source_enabled'] ?? null)),
                'reconcile_before_action'         => (bool)(($cfg['reconcile_before_action'] ?? null)),
                'raw_execution_block'             => $cfg['execution'] ?? null,
                'raw_sources_block'               => $cfg['sources'] ?? null,
            ],
            'bot_demo_data_sufficiency' => $this->readBotJsonFile($base . '/storage_demo/runtime/demo_sufficiency.json'),
            'bot_demo_truth_audit'     => $this->readBotJsonFile($base . '/storage_demo/runtime/demo_truth_audit.json'),
            'pe_last_run'              => $this->readBotJsonFile($base . '/../pattern_engine/storage/runtime/last_run.json'),
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
     * Delegate: get live bot status with safe credential diagnostics.
     *
     * @return array<string,mixed>
     */
    public function getTradingBotStatus(): array
    {
        try {
            $status = $this->getTradingBotService()->getStatus();
        } catch (\Throwable $e) {
            $status = ['ok' => false, 'error' => $e->getMessage()];
        }

        // Attach safe credential diagnostics (boolean only, never raw values)
        $res = $this->resolveBotStorageDir();
        $cfg = $res['config'] ?? [];
        $mode = $res['mode'] ?? 'paper';
        $demoCreds = $cfg['module']['credentials']['demo'] ?? [];
        $demoKey    = trim((string)($demoCreds['api_key']    ?? ''));
        $demoSecret = trim((string)($demoCreds['api_secret'] ?? ''));
        $demoUrl    = trim((string)($demoCreds['api_base_url'] ?? 'https://api-demo.bybit.com'));

        $status['_diag'] = [
            'mode'                   => $mode,
            'storage_namespace'      => basename($res['storageDir'] ?? 'storage_paper'),
            'config_path'            => ($res['base'] ?? '') . '/config/bot.json',
            'demo_api_key_present'   => $demoKey !== '',
            'demo_api_secret_present'=> $demoSecret !== '',
            'demo_api_base_url'      => $demoUrl,
            'is_real_exchange_mode'  => in_array($mode, ['live', 'demo'], true),
        ];

        return $status;
    }

    /**
     * Save allowed bot config keys to config/bot.json.
     * Merges nested blocks correctly; preserves secrets when empty input submitted.
     *
     * @param array<string,mixed> $values
     * @return array{ok:bool,error?:string}
     */
    /**
     * Validate numeric execution fields before saving.
     * Returns null on success or a Russian error string on failure.
     */
    private function validateBotConfigValues(array $values): ?string
    {
        // trailing_drawdown_factor: working corridor 0.25–0.50
        if (array_key_exists('trailing_drawdown_factor', $values)) {
            $v = $values['trailing_drawdown_factor'];
            if (!is_numeric($v) || $v === '') {
                return 'Trailing Drawdown Factor должен быть числом в диапазоне от 0.25 до 0.50';
            }
            $f = (float)$v;
            if ($f < 0.25 || $f > 0.50) {
                return 'Trailing Drawdown Factor должен быть в диапазоне от 0.25 до 0.50 (введено: ' . $v . ')';
            }
        }

        // max_positions: integer >= 1
        if (array_key_exists('max_positions', $values)) {
            $v = $values['max_positions'];
            if (!is_numeric($v) || (int)$v < 1) {
                return 'Максимальное количество позиций должно быть целым числом >= 1';
            }
        }

        // leverage_default: > 0
        if (array_key_exists('leverage_default', $values) || array_key_exists('exchange_leverage', $values)) {
            $lev = $values['leverage_default'] ?? $values['exchange_leverage'] ?? null;
            if ($lev !== null && (!is_numeric($lev) || (float)$lev <= 0)) {
                return 'Плечо (Leverage) должно быть числом > 0';
            }
        }

        // stop_loss_pct: > 0
        if (array_key_exists('stop_loss_pct', $values)) {
            $v = $values['stop_loss_pct'];
            if (!is_numeric($v) || (float)$v <= 0) {
                return 'Stop Loss % должен быть числом > 0';
            }
        }

        // take_profit_pct: > 0
        if (array_key_exists('take_profit_pct', $values)) {
            $v = $values['take_profit_pct'];
            if (!is_numeric($v) || (float)$v <= 0) {
                return 'Take Profit % должен быть числом > 0';
            }
        }

        // emergency_stop_loss_pct: > 0
        if (array_key_exists('emergency_stop_loss_pct', $values)) {
            $v = $values['emergency_stop_loss_pct'];
            if (!is_numeric($v) || (float)$v <= 0) {
                return 'Emergency Stop Loss % должен быть числом > 0';
            }
        }

        // trailing_activation_roi: >= 0
        if (array_key_exists('trailing_activation_roi', $values)) {
            $v = $values['trailing_activation_roi'];
            if (!is_numeric($v) || (float)$v < 0) {
                return 'Trailing Activation ROI должен быть числом >= 0';
            }
        }

        // break_even_activation_roi: >= 0
        if (array_key_exists('break_even_activation_roi', $values)) {
            $v = $values['break_even_activation_roi'];
            if (!is_numeric($v) || (float)$v < 0) {
                return 'Break Even Activation ROI должен быть числом >= 0';
            }
        }

        // exchange_position_idx: integer >= 0
        if (array_key_exists('exchange_position_idx', $values)) {
            $v = $values['exchange_position_idx'];
            if (!is_numeric($v) || (int)$v < 0) {
                return 'Position IDX должен быть целым числом >= 0';
            }
        }

        // demo_learning_mode max_concurrent_demo_positions: integer >= 1
        if (array_key_exists('demo_learning_mode_max_concurrent_demo_positions', $values)) {
            $v = $values['demo_learning_mode_max_concurrent_demo_positions'];
            if (!is_numeric($v) || (int)$v < 1) {
                return 'Максимальное количество одновременных demo-позиций должно быть >= 1';
            }
        }

        // demo_learning_mode max_demo_signals_per_run: integer >= 1
        if (array_key_exists('demo_learning_mode_max_demo_signals_per_run', $values)) {
            $v = $values['demo_learning_mode_max_demo_signals_per_run'];
            if (!is_numeric($v) || (int)$v < 1) {
                return 'Максимальное количество demo-сигналов за запуск должно быть >= 1';
            }
        }

        return null;
    }

    public function saveTradingBotConfig(array $values): array
    {
        // Authoritative server-side validation — reject before touching disk
        $validationError = $this->validateBotConfigValues($values);
        if ($validationError !== null) {
            return ['ok' => false, 'error' => $validationError];
        }

        $res = $this->resolveBotStorageDir();
        $base = $res['base'];
        if ($base === null) {
            return ['ok' => false, 'error' => 'module_base_unknown'];
        }

        $path = $base . '/config/bot.json';
        $current = is_file($path)
            ? (@json_decode((string)@file_get_contents($path), true) ?: [])
            : [];

        // ---- module block ----
        if (!isset($current['module']) || !is_array($current['module'])) {
            $current['module'] = [];
        }

        $moduleFields = ['enabled', 'mode', 'account_id', 'max_positions', 'safety_stop_errors', 'reconcile_before_action'];
        foreach ($moduleFields as $k) {
            if (array_key_exists($k, $values)) {
                $current[$k] = $values[$k]; // flat overrides (bot controller reads flat + nested)
            }
        }
        if (array_key_exists('enabled', $values)) {
            $current['module']['enabled'] = (bool)$values['enabled'];
        }
        if (array_key_exists('mode', $values)) {
            $validModes = ['live', 'demo', 'paper', 'dry'];
            $m = strtolower(trim((string)$values['mode']));
            if (in_array($m, $validModes, true)) {
                $current['mode'] = $m;
                $current['module']['mode'] = $m;
            }
        }
        if (array_key_exists('account_id', $values) && (string)$values['account_id'] !== '') {
            $current['module']['account_id'] = (string)$values['account_id'];
        }
        if (array_key_exists('max_positions', $values)) {
            $current['max_positions'] = max(0, (int)$values['max_positions']);
        }
        if (array_key_exists('reconcile_before_action', $values)) {
            $current['reconcile_before_action'] = (bool)$values['reconcile_before_action'];
        }

        // ---- demo credentials (stay local; never go to KeyCenter) ----
        // Written to the FLAT credentials.demo block that bot_config_trait reads.
        if (!isset($current['credentials']) || !is_array($current['credentials'])) {
            $current['credentials'] = [];
        }
        if (!isset($current['credentials']['demo']) || !is_array($current['credentials']['demo'])) {
            $current['credentials']['demo'] = [];
        }

        if (array_key_exists('demo_api_key', $values)) {
            $current['credentials']['demo']['api_key'] = (string)$values['demo_api_key'];
        }
        // Preserve existing secret when empty is submitted
        if (array_key_exists('demo_api_secret', $values) && (string)$values['demo_api_secret'] !== '') {
            $current['credentials']['demo']['api_secret'] = (string)$values['demo_api_secret'];
        }
        if (array_key_exists('demo_api_base_url', $values) && (string)$values['demo_api_base_url'] !== '') {
            $current['credentials']['demo']['api_base_url'] = (string)$values['demo_api_base_url'];
        }

        // ---- exchange block ----
        if (!isset($current['exchange']) || !is_array($current['exchange'])) {
            $current['exchange'] = [];
        }
        $exchangeFields = ['category', 'account_type', 'settle_coin', 'position_idx', 'tpsl_mode', 'sl_trigger_by'];
        foreach ($exchangeFields as $k) {
            if (array_key_exists('exchange_' . $k, $values)) {
                $current['exchange'][$k] = $values['exchange_' . $k];
            }
        }
        $leverage = $values['leverage_default'] ?? $values['exchange_leverage'] ?? null;
        if ($leverage !== null) {
            $current['exchange']['leverage'] = (int)$leverage;
        }

        // ---- execution block ----
        if (!isset($current['execution']) || !is_array($current['execution'])) {
            $current['execution'] = [];
        }
        $execFields = [
            'order_type', 'stop_loss_pct', 'take_profit_pct',
            'trailing_enabled', 'trailing_mode', 'trailing_activation_roi', 'trailing_drawdown_factor',
            'break_even_enabled', 'break_even_activation_roi',
            'emergency_stop_enabled', 'emergency_stop_loss_pct',
            'reverse_side_enabled',
        ];
        foreach ($execFields as $k) {
            if (array_key_exists($k, $values)) {
                $current['execution'][$k] = $values[$k];
            }
        }
        // Also handle execution_* prefixed form field names (UI sends execution_trailing_enabled etc.)
        $execBoolPrefixed = ['trailing_enabled', 'break_even_enabled', 'emergency_stop_enabled', 'reverse_side_enabled'];
        foreach ($execBoolPrefixed as $k) {
            $prefixed = 'execution_' . $k;
            if (array_key_exists($prefixed, $values)) {
                $current['execution'][$k] = (bool)$values[$prefixed];
            }
        }

        // ---- sources block ----
        if (!isset($current['sources']) || !is_array($current['sources'])) {
            $current['sources'] = [];
        }
        $sourcesFields = ['brain_source_enabled', 'brain_source_auto_run', 'signals_key', 'signals_file'];
        foreach ($sourcesFields as $k) {
            if (array_key_exists('sources_' . $k, $values)) {
                $current['sources'][$k] = $values['sources_' . $k];
            }
            if (array_key_exists($k, $values)) {
                $current['sources'][$k] = $values[$k];
            }
        }

        // ---- demo_learning_mode block ----
        if (!isset($current['demo_learning_mode']) || !is_array($current['demo_learning_mode'])) {
            $current['demo_learning_mode'] = [];
        }
        $dlmFields = ['enabled', 'max_concurrent_demo_positions', 'max_demo_signals_per_run',
                      'prefer_short_holds', 'allow_low_confidence_demo', 'learning_target_closed_trades'];
        foreach ($dlmFields as $k) {
            if (array_key_exists('demo_learning_mode_' . $k, $values)) {
                $v = $values['demo_learning_mode_' . $k];
                if (in_array($k, ['enabled', 'prefer_short_holds', 'allow_low_confidence_demo'], true)) {
                    $v = (bool)$v;
                } elseif (in_array($k, ['max_concurrent_demo_positions', 'max_demo_signals_per_run', 'learning_target_closed_trades'], true)) {
                    $v = (int)$v;
                }
                $current['demo_learning_mode'][$k] = $v;
            }
        }

        $current['last_modified'] = date('c');

        $written = @file_put_contents(
            $path,
            json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        );

        return $written !== false
            ? ['ok' => true]
            : ['ok' => false, 'error' => 'write_failed'];
    }

    // =========================================================================
    // Pattern Engine control-plane delegation
    // (Brain is UI/control-plane; all pattern logic stays in pattern_engine module)
    // =========================================================================

    /**
     * Lazy-load the PatternEngineService.
     */
    private function getPatternEngineService(): object
    {
        static $svc = null;
        if ($svc === null) {
            $base = __DIR__ . '/../pattern_engine';
            require_once $base . '/config/config.php';
            require_once $base . '/lib/pattern_detector.php';
            require_once $base . '/lib/signal_adapter.php';
            require_once $base . '/lib/scenario_engine.php';
            require_once $base . '/lib/symbol_normalizer.php';
            require_once $base . '/service.php';
            $svc = new \PatternEngineService();
        }
        return $svc;
    }

    /**
     * Aggregate all data the Brain Patterns page needs.
     *
     * @return array<string,mixed>
     */
    public function getPatternEngineData(): array
    {
        try {
            $svc = $this->getPatternEngineService();
            return [
                'pe_available'  => true,
                'pe_error'      => null,
                'pe_config'     => $svc->getConfig(),
                'pe_last_run'   => $svc->getLastRun(),
                'pe_stats'      => $svc->getStats(),
                'pe_candidates' => $svc->getCandidates(),
                'pe_signals'    => $svc->getSignals(),
                'pe_scenarios'  => $svc->getScenarios(),
            ];
        } catch (\Throwable $e) {
            return [
                'pe_available'  => false,
                'pe_error'      => $e->getMessage(),
                'pe_config'     => [],
                'pe_last_run'   => [],
                'pe_stats'      => [],
                'pe_candidates' => [],
                'pe_signals'    => [],
                'pe_scenarios'  => [],
            ];
        }
    }

    /**
     * Trigger a Pattern Engine run from Brain.
     *
     * @param  list<array<string,mixed>>  $batch      Optional market-data batch
     * @param  bool                       $smokeTest  When true, forces synthetic smoke batch (debug only)
     * @return array<string,mixed>
     */
    public function runPatternEngine(array $batch = [], bool $smokeTest = false): array
    {
        try {
            return $this->getPatternEngineService()->runNow($batch, $smokeTest);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Return Pattern Engine normalized signals (the new upstream source for Brain).
     *
     * @return list<array<string,mixed>>
     */
    public function getPatternEngineSignals(): array
    {
        try {
            return $this->getPatternEngineService()->getSignals();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Return Pattern Engine scenario decisions.
     *
     * @return list<array<string,mixed>>
     */
    public function getPatternEngineScenarios(): array
    {
        try {
            return $this->getPatternEngineService()->getScenarios();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Return Pattern Engine demo-approved signals.
     *
     * @return list<array<string,mixed>>
     */
    public function getPatternEngineDemoSignals(): array
    {
        try {
            return $this->getPatternEngineService()->getDemoSignals();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Save Pattern Engine config from Brain UI.
     *
     * @param array<string,mixed> $values
     * @return array{ok:bool,error?:string}
     */
    public function savePatternEngineConfig(array $values): array
    {
        try {
            $svc     = $this->getPatternEngineService();
            $current = $svc->getConfig();

            $allowed = ['enabled', 'live_output_enabled', 'default_time_window_minutes'];
            foreach ($allowed as $k) {
                if (array_key_exists($k, $values)) {
                    $current[$k] = $values[$k];
                }
            }

            return $svc->saveConfig($current) ? ['ok' => true] : ['ok' => false, 'error' => 'write_failed'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // Module config exposure for Brain config hub
    // =========================================================================

    /**
     * Aggregate all module configs for the Brain Module Configs page.
     *
     * @return array<string,mixed>
     */
    public function getModuleConfigsData(): array
    {
        return [
            'pattern_engine_config'  => $this->getModuleConfigSafe(__DIR__ . '/../pattern_engine/config/pattern_engine.json'),
            'coin_passport_config'   => $this->getModuleConfigSafe(__DIR__ . '/../coin_passport/config/coin_passport.json'),
            'trading_bot_config'     => $this->getModuleConfigSafe(__DIR__ . '/../trading_bot/config/bot.json'),
            'ai_shadow_config'       => $this->getModuleConfigSafe(__DIR__ . '/../ai_shadow/config/ai_shadow.json'),
            'pattern_engine_descriptor'  => $this->getPatternEngineDescriptor(),
            'coin_passport_descriptor'   => $this->getCoinPassportDescriptor(),
            'trading_bot_descriptor'     => $this->getTradingBotDescriptor(),
            'ai_shadow_descriptor'       => $this->getAiShadowDescriptor(),
        ];
    }

    /**
     * Read a module JSON config safely; return [] if missing.
     *
     * @return array<string,mixed>
     */
    private function getModuleConfigSafe(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $d = @json_decode((string)@file_get_contents($path), true);
        return is_array($d) ? $d : [];
    }

    /**
     * Config descriptor for Pattern Engine.
     * Provides field metadata for rendering the config form in Brain UI.
     *
     * @return list<array<string,mixed>>
     */
    private function getPatternEngineDescriptor(): array
    {
        return [
            ['key' => 'enabled',                      'label' => 'Engine Enabled',              'type' => 'bool',   'group' => 'Core',    'user_level' => true,  'safe_edit' => true],
            ['key' => 'live_output_enabled',          'label' => 'Live Output Enabled',         'type' => 'bool',   'group' => 'Core',    'user_level' => true,  'safe_edit' => true,  'default' => false],
            ['key' => 'default_time_window_minutes',  'label' => 'Default Time Window (min)',   'type' => 'int',    'group' => 'Core',    'user_level' => false, 'safe_edit' => true,  'default' => 15],
            ['key' => 'storage.max_candidates_per_run','label'=> 'Max Candidates per Run',      'type' => 'int',    'group' => 'Storage', 'user_level' => false, 'safe_edit' => true,  'default' => 200],
            ['key' => 'storage.max_signals_stored',   'label' => 'Max Signals Stored',          'type' => 'int',    'group' => 'Storage', 'user_level' => false, 'safe_edit' => true,  'default' => 500],
            ['key' => 'storage.max_scenarios_stored', 'label' => 'Max Scenarios Stored',        'type' => 'int',    'group' => 'Storage', 'user_level' => false, 'safe_edit' => true,  'default' => 500],
        ];
    }

    /**
     * Config descriptor for Coin Passport.
     *
     * @return list<array<string,mixed>>
     */
    private function getCoinPassportDescriptor(): array
    {
        return [
            ['key' => 'enabled',                         'label' => 'Passport Engine Enabled',  'type' => 'bool',  'group' => 'Core',       'user_level' => true,  'safe_edit' => true],
            ['key' => 'min_corridor_p75_roi',            'label' => 'Min Corridor P75 ROI (%)', 'type' => 'float', 'group' => 'Live Gate',  'user_level' => true,  'safe_edit' => true,  'default' => 3.0],
            ['key' => 'min_runner_probability',          'label' => 'Min Runner Probability',   'type' => 'float', 'group' => 'Live Gate',  'user_level' => true,  'safe_edit' => true,  'default' => 0.05],
            ['key' => 'max_noise_score',                 'label' => 'Max Noise Score',          'type' => 'float', 'group' => 'Live Gate',  'user_level' => true,  'safe_edit' => true,  'default' => 0.65],
            ['key' => 'min_suitability_score',           'label' => 'Min Suitability Score',    'type' => 'float', 'group' => 'Live Gate',  'user_level' => true,  'safe_edit' => true,  'default' => 0.30],
            ['key' => 'min_market_regime_health_score',  'label' => 'Min Regime Health Score',  'type' => 'float', 'group' => 'Live Gate',  'user_level' => false, 'safe_edit' => true,  'default' => 0.30],
        ];
    }

    /**
     * Config descriptor for Trading Bot.
     *
     * @return list<array<string,mixed>>
     */
    private function getTradingBotDescriptor(): array
    {
        return [
            ['key' => 'enabled',            'label' => 'Bot Enabled',           'type' => 'bool',   'group' => 'Core',      'user_level' => true,  'safe_edit' => true],
            ['key' => 'mode',               'label' => 'Mode',                  'type' => 'select', 'group' => 'Core',      'user_level' => true,  'safe_edit' => true,  'options' => ['live','demo','paper','dry']],
            ['key' => 'max_positions',      'label' => 'Max Open Positions',    'type' => 'int',    'group' => 'Core',      'user_level' => true,  'safe_edit' => true,  'default' => 3],
            ['key' => 'exchange.leverage',  'label' => 'Leverage',              'type' => 'int',    'group' => 'Exchange',  'user_level' => true,  'safe_edit' => true,  'default' => 5],
            ['key' => 'execution.stop_loss_pct',    'label' => 'Stop Loss %',       'type' => 'float', 'group' => 'Execution', 'user_level' => true, 'safe_edit' => true],
            ['key' => 'execution.take_profit_pct',  'label' => 'Take Profit %',     'type' => 'float', 'group' => 'Execution', 'user_level' => true, 'safe_edit' => true],
            ['key' => 'execution.trailing_enabled', 'label' => 'Trailing Stop',     'type' => 'bool',  'group' => 'Execution', 'user_level' => true, 'safe_edit' => true],
        ];
    }

    /**
     * Config descriptor for AI Shadow.
     *
     * @return list<array<string,mixed>>
     */
    private function getAiShadowDescriptor(): array
    {
        return [
            ['key' => 'enabled',                    'label' => 'AI Shadow Enabled',          'type' => 'bool',   'group' => 'Core',      'user_level' => true,  'safe_edit' => true],
            ['key' => 'mode',                       'label' => 'Shadow Mode',                'type' => 'select', 'group' => 'Core',      'user_level' => false, 'safe_edit' => false, 'options' => ['shadow','sim','disabled']],
            ['key' => 'provider',                   'label' => 'AI Provider',                'type' => 'string', 'group' => 'AI',        'user_level' => false, 'safe_edit' => true],
            ['key' => 'model',                      'label' => 'AI Model',                   'type' => 'string', 'group' => 'AI',        'user_level' => false, 'safe_edit' => true],
            ['key' => 'credential_id',              'label' => 'Credential ID',              'type' => 'string', 'group' => 'AI',        'user_level' => false, 'safe_edit' => true],
            ['key' => 'max_signals_per_run',        'label' => 'Max Signals per Run',        'type' => 'int',    'group' => 'Limits',    'user_level' => false, 'safe_edit' => true],
            ['key' => 'confidence_threshold_enter', 'label' => 'Enter Confidence Threshold', 'type' => 'float',  'group' => 'Thresholds','user_level' => false, 'safe_edit' => true],
            ['key' => 'quality_score_threshold',    'label' => 'Quality Score Threshold',    'type' => 'float',  'group' => 'Thresholds','user_level' => false, 'safe_edit' => true],
        ];
    }
}
