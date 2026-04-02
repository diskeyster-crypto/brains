<?php
declare(strict_types=1);

require_once __DIR__ . '/service.php';

/**
 * PatternEngineController
 *
 * Backend and API controller for the Pattern Engine module.
 *
 * Pattern Engine has no standalone user-facing pages.
 * All user-facing UI lives in Smart Brain at /admin/smart_brain/patterns.
 * No GET page routes are registered for /admin/pattern_engine.
 *
 * Action routes (POST only):
 *   POST /admin/pattern_engine/run                → trigger pipeline run
 *   POST /admin/pattern_engine/settings/save      → save config
 *   POST /admin/pattern_engine/clear              → clear storage
 *
 * API routes (read-only JSON, for Demo Execution / AI Shadow / Simulator):
 *   GET  /admin/pattern_engine/api/signals        → normalized signals JSON
 *   GET  /admin/pattern_engine/api/scenarios      → scenario decisions JSON
 *   GET  /admin/pattern_engine/api/demo_signals   → signals allowed for demo
 *   GET  /admin/pattern_engine/api/shadow_signals → signals allowed for shadow
 *   GET  /admin/pattern_engine/api/sim_signals    → signals allowed for simulator
 */
final class PatternEngineController
{
    private PatternEngineService $service;

    public function __construct()
    {
        $this->service = new PatternEngineService();
    }

    // =========================================================================
    // Action routes
    // =========================================================================

    /** POST /admin/pattern_engine/settings/save */
    public function saveSettings(): void
    {
        $body   = (string)file_get_contents('php://input');
        $posted = json_decode($body, true);

        if (!is_array($posted)) {
            $posted = $_POST;
        }

        // Merge only safe top-level keys
        $current = $this->service->getConfig();

        if (isset($posted['enabled'])) {
            $current['enabled'] = (bool)$posted['enabled'];
        }
        if (isset($posted['live_output_enabled'])) {
            $current['live_output_enabled'] = (bool)$posted['live_output_enabled'];
        }
        if (isset($posted['default_time_window_minutes'])) {
            $current['default_time_window_minutes'] = (int)$posted['default_time_window_minutes'];
        }
        if (isset($posted['scenario_profiles']) && is_array($posted['scenario_profiles'])) {
            $current['scenario_profiles'] = $posted['scenario_profiles'];
        }

        $ok = $this->service->saveConfig($current);

        header('Content-Type: application/json');
        echo json_encode(['ok' => $ok]);
    }

    /** POST /admin/pattern_engine/clear */
    public function clearStorage(): void
    {
        $this->service->clearStorage();
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
    }

    /**
     * POST /admin/pattern_engine/run
     *
     * Triggers a pipeline run. Accepts optional JSON body:
     *   { "batch": [ ...market_data_slices... ] }
     *   { "smoke_test": true }   — forces synthetic smoke batch (debug only)
     * Default (no batch, no smoke_test): fetches real klines from Bybit.
     */
    public function runNow(): void
    {
        $body      = (string)file_get_contents('php://input');
        $posted    = json_decode($body, true);
        $batch     = [];
        $smokeTest = false;

        if (is_array($posted)) {
            if (isset($posted['batch']) && is_array($posted['batch'])) {
                $batch = $posted['batch'];
            }
            $smokeTest = !empty($posted['smoke_test']);
        }

        $result = $this->service->runNow($batch, $smokeTest);

        header('Content-Type: application/json');
        echo json_encode([
            'ok'                => true,
            'candidates_count'  => count($result['candidates'] ?? []),
            'signals_count'     => count($result['signals'] ?? []),
            'scenarios_count'   => count($result['scenarios'] ?? []),
            'stats'             => $result['stats'] ?? [],
        ]);
    }

    // =========================================================================
    // API routes (JSON read-only)
    // =========================================================================

    /** GET /admin/pattern_engine/api/signals */
    public function apiSignals(): void
    {
        header('Content-Type: application/json');
        echo json_encode($this->service->getSignals());
    }

    /** GET /admin/pattern_engine/api/scenarios */
    public function apiScenarios(): void
    {
        header('Content-Type: application/json');
        echo json_encode($this->service->getScenarios());
    }

    /** GET /admin/pattern_engine/api/demo_signals */
    public function apiDemoSignals(): void
    {
        header('Content-Type: application/json');
        echo json_encode($this->service->getDemoSignals());
    }

    /** GET /admin/pattern_engine/api/shadow_signals */
    public function apiShadowSignals(): void
    {
        header('Content-Type: application/json');
        echo json_encode($this->service->getShadowSignals());
    }

    /** GET /admin/pattern_engine/api/sim_signals */
    public function apiSimSignals(): void
    {
        header('Content-Type: application/json');
        echo json_encode($this->service->getSimSignals());
    }
}
