<?php
declare(strict_types=1);

require_once __DIR__ . '/service.php';

final class SmartBrainController
{
    private SmartBrainService $service;
    private string $smartBrainUrl;

    public function __construct()
    {
        $this->service = new SmartBrainService();
        $this->smartBrainUrl = '/admin/smart_brain';
    }

    /**
     * Dashboard page
     * GET /admin/smart_brain
     */
    public function index(): void
    {
        $data = $this->service->getDashboardData();
        $data['smartBrainUrl'] = $this->smartBrainUrl;

        extract($data, EXTR_SKIP);
        include __DIR__ . '/views/dashboard.php';
    }

    /**
     * Manual run — execute one Smart Brain cycle.
     * POST /admin/smart_brain/run
     */
    public function run(): void
    {
        $result = $this->service->run('manual');

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Global config page
     * GET /admin/smart_brain/config
     */
    public function config(): void
    {
        $data = $this->service->getConfig();
        $data['smartBrainUrl'] = $this->smartBrainUrl;

        extract($data, EXTR_SKIP);
        include __DIR__ . '/views/config.php';
    }

    /**
     * User Config page — editable form
     * GET /admin/smart_brain/user_config
     */
    public function userConfig(): void
    {
        $data = $this->service->getUserConfigData();
        $data['smartBrainUrl'] = $this->smartBrainUrl;
        $data['flash'] = null;
        $data['form_values'] = $data['user_limits'];

        extract($data, EXTR_SKIP);
        include __DIR__ . '/views/user_config.php';
    }

    /**
     * Save User Config
     * POST /admin/smart_brain/user_config/save
     */
    public function saveUserConfig(): void
    {
        $values = $_POST;

        // Handle checkboxes (not sent when unchecked)
        $values['bootstrap_enabled'] = !empty($_POST['bootstrap_enabled']);
        $values['brain_may_tighten_stop'] = !empty($_POST['brain_may_tighten_stop']);
        $values['trailing_enabled'] = !empty($_POST['trailing_enabled']);
        $values['brain_may_delay_trailing'] = !empty($_POST['brain_may_delay_trailing']);
        $values['break_even_enabled'] = !empty($_POST['break_even_enabled']);
        $values['early_failure_enabled'] = !empty($_POST['early_failure_enabled']);
        $values['symbol_intelligence_enabled'] = !empty($_POST['symbol_intelligence_enabled']);
        $values['soft_whitelist_enabled'] = !empty($_POST['soft_whitelist_enabled']);
        $values['manual_symbol_universe_enabled'] = !empty($_POST['manual_symbol_universe_enabled']);

        // Trailing block add-on toggle
        $values['profit_addon_enabled'] = !empty($_POST['profit_addon_enabled']);

        // Live Trading Control checkboxes
        $values['live_trading_enabled'] = !empty($_POST['live_trading_enabled']);
        $values['live_one_trade_per_symbol'] = !empty($_POST['live_one_trade_per_symbol']);
        $values['live_reverse_side_enabled'] = !empty($_POST['live_reverse_side_enabled']);

        // Pattern selection: checkboxes send array, absent when none checked
        $values['patterns_enabled'] = isset($_POST['patterns_enabled']) && is_array($_POST['patterns_enabled'])
            ? $_POST['patterns_enabled']
            : [];
        $values['pattern_mode'] = (string)($_POST['pattern_mode'] ?? 'any');

        $result = $this->service->saveUserConfig($values);

        $data = $this->service->getUserConfigData();
        $data['smartBrainUrl'] = $this->smartBrainUrl;

        if ($result['ok']) {
            // Config Conflict Guard: mention warnings in flash message if present
            $configWarnings = $data['config_warnings'] ?? [];
            if (!empty($configWarnings)) {
                $data['flash'] = ['type' => 'warning', 'message' => 'User config saved. ⚠ ' . implode(' ', $configWarnings)];
            } else {
                $data['flash'] = ['type' => 'success', 'message' => 'User config saved successfully.'];
            }
            $data['form_values'] = $data['user_limits'];
        } else {
            $data['flash'] = ['type' => 'error', 'message' => 'Validation errors: ' . implode('; ', $result['errors'])];
            // Preserve entered form values on error
            $data['form_values'] = $values;
            // Preserve pattern selection on error
            $data['patterns_enabled'] = $values['patterns_enabled'] ?? [];
            $data['pattern_mode'] = $values['pattern_mode'] ?? 'any';
            $data['symbol_intelligence_enabled'] = !empty($values['symbol_intelligence_enabled']);
            $data['symbol_filter_mode'] = $values['symbol_filter_mode'] ?? 'all';
            $data['manual_symbol_universe_enabled'] = !empty($values['manual_symbol_universe_enabled']);
            $data['manual_symbol_mode'] = $values['manual_symbol_mode'] ?? 'manual_only';
            // Live trading form values on error
            $data['live_trading_enabled'] = !empty($values['live_trading_enabled']);
            $data['live_signal_selection_mode'] = $values['live_signal_selection_mode'] ?? 'whitelist_only';
        }

        extract($data, EXTR_SKIP);
        include __DIR__ . '/views/user_config.php';
    }

    /**
     * Analyzer page
     * GET /admin/smart_brain/analizator
     */
    public function analizator(): void
    {
        $data = $this->service->getAnalizatorData();
        $data['smartBrainUrl'] = $this->smartBrainUrl;

        extract($data, EXTR_SKIP);
        include __DIR__ . '/views/analizator.php';
    }

    /**
     * Simulator page
     * GET /admin/smart_brain/simulator
     */
    public function simulator(): void
    {
        $data = $this->service->getSimulatorData();
        $data['smartBrainUrl'] = $this->smartBrainUrl;

        extract($data, EXTR_SKIP);
        include __DIR__ . '/views/simulator.php';
    }

    /**
     * Simulator Analytics page
     * GET /admin/smart_brain/simulator_analytics
     */
    public function simulatorAnalytics(): void
    {
        $data = $this->service->getSimulatorAnalyticsData();
        $data['smartBrainUrl'] = $this->smartBrainUrl;

        extract($data, EXTR_SKIP);
        include __DIR__ . '/views/simulator_analytics.php';
    }

    /**
     * Coin Passports page — delegates to the standalone coin_passport module.
     * GET /admin/smart_brain/passports
     */
    public function passports(): void
    {
        // The standalone coin_passport module is now the single source of truth.
        header('Location: /admin/coin_passport', true, 302);
        exit;
    }

    /**
     * Live Performance Analyzer page
     * GET /admin/smart_brain/live_performance
     */
    public function livePerformance(): void
    {
        $data = $this->service->getLivePerformanceData();
        $data['smartBrainUrl'] = $this->smartBrainUrl;

        extract($data, EXTR_SKIP);
        include __DIR__ . '/views/live_performance.php';
    }

    /**
     * Runtime page
     * GET /admin/smart_brain/runtime
     */
    public function runtimePage(): void
    {
        $data = $this->service->getRuntimeData();
        $data['smartBrainUrl'] = $this->smartBrainUrl;

        extract($data, EXTR_SKIP);
        include __DIR__ . '/views/runtime.php';
    }

    /**
     * Runtime API
     * GET /admin/smart_brain/api/runtime
     */
    public function runtime(): void
    {
        $data = $this->service->getRuntimeData();

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Maintenance page
     * GET /admin/smart_brain/maintenance
     */
    public function maintenance(): void
    {
        $smartBrainUrl = $this->smartBrainUrl;
        $flash = null;

        include __DIR__ . '/views/maintenance.php';
    }

    /**
     * Soft Cleanup
     * POST /admin/smart_brain/cleanup/soft
     */
    public function cleanupSoft(): void
    {
        require_once __DIR__ . '/lib/cleanup_manager.php';

        $cleanup = new CleanupManager(__DIR__);
        $result = $cleanup->softCleanup();

        $smartBrainUrl = $this->smartBrainUrl;
        $flash = ['type' => 'success', 'message' => 'Soft Cleanup completed — ' . $result['deleted'] . ' file(s) deleted.'];

        include __DIR__ . '/views/maintenance.php';
    }

    /**
     * Simulator Reset
     * POST /admin/smart_brain/cleanup/simulator
     */
    public function cleanupSimulator(): void
    {
        require_once __DIR__ . '/lib/cleanup_manager.php';

        $cleanup = new CleanupManager(__DIR__);
        $result = $cleanup->resetSimulator();

        $smartBrainUrl = $this->smartBrainUrl;
        $flash = ['type' => 'success', 'message' => 'Simulator Reset completed — ' . $result['deleted'] . ' file(s) deleted.'];

        include __DIR__ . '/views/maintenance.php';
    }

    /**
     * Full Runtime Reset
     * POST /admin/smart_brain/cleanup/full
     */
    public function cleanupFull(): void
    {
        require_once __DIR__ . '/lib/cleanup_manager.php';

        $cleanup = new CleanupManager(__DIR__);
        $result = $cleanup->fullRuntimeReset();

        $smartBrainUrl = $this->smartBrainUrl;
        $flash = ['type' => 'success', 'message' => 'Full Runtime Reset completed — ' . $result['deleted'] . ' file(s) deleted.'];

        include __DIR__ . '/views/maintenance.php';
    }

    /**
     * Save Manual Blacklist
     * POST /admin/smart_brain/blacklist/save
     */
    public function saveBlacklist(): void
    {
        $rawInput = (string)($_POST['manual_blacklist_symbols'] ?? '');
        // Parse: one symbol per line, or comma-separated
        $symbols = preg_split('/[\r\n,]+/', $rawInput, -1, PREG_SPLIT_NO_EMPTY);
        $symbols = array_map('trim', $symbols);
        $symbols = array_filter($symbols, fn($s) => $s !== '');

        $result = $this->service->saveManualBlacklist($symbols);

        // Redirect back to user_config with flash
        $data = $this->service->getUserConfigData();
        $data['smartBrainUrl'] = $this->smartBrainUrl;

        if ($result['ok']) {
            $data['flash'] = ['type' => 'success', 'message' => 'Manual blacklist saved — ' . $result['count'] . ' symbol(s).'];
        } else {
            $data['flash'] = ['type' => 'error', 'message' => 'Failed to save manual blacklist.'];
        }
        $data['form_values'] = $data['user_limits'];

        extract($data, EXTR_SKIP);
        include __DIR__ . '/views/user_config.php';
    }

    // =========================================================================
    // AI Shadow control-plane (Brain acts as control-plane; execution stays in ai_shadow module)
    // =========================================================================

    /**
     * AI Shadow control page
     * GET /admin/smart_brain/ai_shadow
     */
    public function aiShadow(): void
    {
        $data = $this->service->getAiShadowData();
        $data['smartBrainUrl'] = $this->smartBrainUrl;

        extract($data, EXTR_SKIP);
        include __DIR__ . '/views/ai_shadow.php';
    }

    /**
     * POST /admin/smart_brain/ai_shadow/run_mirror
     */
    public function aiShadowRunMirror(): void
    {
        $result = $this->service->runAiShadowMirror();

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * POST /admin/smart_brain/ai_shadow/run_replay
     */
    public function aiShadowRunReplay(): void
    {
        $rawInput = (string)file_get_contents('php://input');
        $body     = json_decode($rawInput, true);
        $signals  = is_array($body['signals'] ?? null) ? $body['signals'] : [];

        $result = $this->service->runAiShadowReplay($signals);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * POST /admin/smart_brain/ai_shadow/save_settings
     * Body: JSON with non-secret AI Shadow config fields only.
     * Raw API keys must NEVER be sent here — use credential_id to reference KeyCenter entries.
     */
    public function aiShadowSaveSettings(): void
    {
        $rawInput = (string)file_get_contents('php://input');
        $body     = json_decode($rawInput, true);

        if (!is_array($body)) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'invalid_json']);
            return;
        }

        $result = $this->service->saveAiShadowSettings($body);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * GET /admin/smart_brain/ai_shadow/stats
     */
    public function aiShadowStats(): void
    {
        $stats = $this->service->getAiShadowStats();

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * GET /admin/smart_brain/ai_shadow/journal
     */
    public function aiShadowJournal(): void
    {
        $svc      = $this->service->getAiShadowServicePublic();
        $signalId = isset($_GET['signal_id']) ? (string)$_GET['signal_id'] : '';
        $limit    = isset($_GET['limit'])     ? (int)$_GET['limit']        : 100;

        if ($svc === null) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'service_unavailable']);
            return;
        }

        if ($signalId !== '') {
            $data = $svc->getSignalJournal($signalId);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['signal_id' => $signalId, 'events' => $data, 'count' => count($data)],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $data = $svc->getRecentJournalEvents($limit);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['events' => $data, 'count' => count($data)],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    /**
     * POST /admin/smart_brain/ai_shadow/clear_storage
     */
    public function aiShadowClearStorage(): void
    {
        $this->service->clearAiShadowStorage();

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'cleared_at' => time()]);
    }

    /**
     * POST /admin/smart_brain/ai_shadow/test_connection
     * Validates credential lookup → provider init → model request path.
     */
    public function aiShadowTestConnection(): void
    {
        $result = $this->service->testAiShadowConnection();

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // =========================================================================
    // Trading Bot control-plane (Brain is UI/control-plane; bot stays backend)
    // =========================================================================

    /**
     * GET /admin/smart_brain/execution
     */
    public function execution(): void
    {
        $data = $this->service->getTradingBotData();
        $data['smartBrainUrl'] = $this->smartBrainUrl;

        extract($data, EXTR_SKIP);
        include __DIR__ . '/views/execution.php';
    }

    /**
     * POST /admin/smart_brain/execution/run
     */
    public function executionRunBot(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
            return;
        }

        $result = $this->service->runTradingBot();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * POST /admin/smart_brain/execution/reconcile
     */
    public function executionReconcile(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
            return;
        }

        $result = $this->service->reconcileTradingBot();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * GET /admin/smart_brain/execution/status
     */
    public function executionStatus(): void
    {
        $result = $this->service->getTradingBotStatus();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * POST /admin/smart_brain/execution/save_config
     */
    public function executionSaveConfig(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
            return;
        }

        $rawInput = (string)file_get_contents('php://input');
        $body     = json_decode($rawInput, true);

        if (!is_array($body)) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'invalid_json']);
            return;
        }

        $result = $this->service->saveTradingBotConfig($body);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // =========================================================================
    // Pattern Engine control-plane (Brain acts as UI; logic stays in pattern_engine)
    // =========================================================================

    /**
     * GET /admin/smart_brain/patterns
     *
     * Brain Patterns tab: overview, signals, scenario decisions from the new
     * Pattern Engine — the single active upstream pattern source.
     */
    public function patterns(): void
    {
        $data = $this->service->getPatternEngineData();
        $data['smartBrainUrl'] = $this->smartBrainUrl;

        extract($data, EXTR_SKIP);
        include __DIR__ . '/views/patterns.php';
    }

    /**
     * POST /admin/smart_brain/patterns/run
     *
     * Trigger a Pattern Engine run from Brain control-plane.
     */
    public function patternsRun(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
            return;
        }

        $rawInput  = (string)file_get_contents('php://input');
        $body      = json_decode($rawInput, true);
        $batch     = (is_array($body) && isset($body['batch']) && is_array($body['batch'])) ? $body['batch'] : [];
        $smokeTest = is_array($body) && !empty($body['smoke_test']);

        $result = $this->service->runPatternEngine($batch, $smokeTest);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'               => true,
            'candidates_count' => count($result['candidates'] ?? []),
            'signals_count'    => count($result['signals'] ?? []),
            'scenarios_count'  => count($result['scenarios'] ?? []),
            'stats'            => $result['stats'] ?? [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * POST /admin/smart_brain/patterns/save_config
     */
    public function patternsSaveConfig(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
            return;
        }

        $rawInput = (string)file_get_contents('php://input');
        $body     = json_decode($rawInput, true);

        if (!is_array($body)) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'invalid_json']);
            return;
        }

        $result = $this->service->savePatternEngineConfig($body);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * GET /admin/smart_brain/patterns/api/signals
     */
    public function patternsApiSignals(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($this->service->getPatternEngineSignals(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * GET /admin/smart_brain/patterns/api/scenarios
     */
    public function patternsApiScenarios(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($this->service->getPatternEngineScenarios(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * GET /admin/smart_brain/patterns/api/demo_signals
     *
     * Returns Pattern Engine demo-approved signals — the primary feed for
     * downstream demo execution. This replaces the legacy Brain pattern path
     * as the active upstream source.
     */
    public function patternsApiDemoSignals(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($this->service->getPatternEngineDemoSignals(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // =========================================================================
    // Module Configs hub (exposes per-module configs in Brain UI)
    // =========================================================================

    /**
     * GET /admin/smart_brain/module_configs
     *
     * Central module-config hub — surfaces editable configs for all key modules:
     * Pattern Engine, Coin Passport, Trading Bot, AI Shadow.
     * Rendered through module-owned config descriptors (preparation for future
     * unified user config layer).
     */
    public function moduleConfigs(): void
    {
        $data = $this->service->getModuleConfigsData();
        $data['smartBrainUrl'] = $this->smartBrainUrl;

        extract($data, EXTR_SKIP);
        include __DIR__ . '/views/module_configs.php';
    }
}
