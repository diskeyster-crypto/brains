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

        // Pattern selection: checkboxes send array, absent when none checked
        $values['patterns_enabled'] = isset($_POST['patterns_enabled']) && is_array($_POST['patterns_enabled'])
            ? $_POST['patterns_enabled']
            : [];
        $values['pattern_mode'] = (string)($_POST['pattern_mode'] ?? 'any');

        $result = $this->service->saveUserConfig($values);

        $data = $this->service->getUserConfigData();
        $data['smartBrainUrl'] = $this->smartBrainUrl;

        if ($result['ok']) {
            $data['flash'] = ['type' => 'success', 'message' => 'User config saved successfully.'];
            $data['form_values'] = $data['user_limits'];
        } else {
            $data['flash'] = ['type' => 'error', 'message' => 'Validation errors: ' . implode('; ', $result['errors'])];
            // Preserve entered form values on error
            $data['form_values'] = $values;
            // Preserve pattern selection on error
            $data['patterns_enabled'] = $values['patterns_enabled'] ?? [];
            $data['pattern_mode'] = $values['pattern_mode'] ?? 'any';
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
     * Coin Passports page
     * GET /admin/smart_brain/passports
     */
    public function passports(): void
    {
        $data = $this->service->getPassportsData();
        $data['smartBrainUrl'] = $this->smartBrainUrl;

        extract($data, EXTR_SKIP);
        include __DIR__ . '/views/passports.php';
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
}
