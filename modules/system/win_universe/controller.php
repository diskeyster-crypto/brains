<?php
declare(strict_types=1);

require_once __DIR__ . '/service.php';

/**
 * WinUniverseController
 *
 * UI and API controller for the Win Universe standalone module.
 *
 * UI routes:
 *   GET  /admin/win_universe              → index (shadow dashboard)
 *
 * Action routes:
 *   POST /admin/win_universe/run          → trigger computation run
 *
 * API routes:
 *   GET  /admin/win_universe/api/universe → JSON result
 */
final class WinUniverseController
{
    private WinUniverseService $service;
    private string $baseUrl;

    public function __construct()
    {
        $this->service = new WinUniverseService();
        $this->baseUrl = '/admin/win_universe';
    }

    // =========================================================================
    // UI
    // =========================================================================

    /**
     * GET /admin/win_universe
     */
    public function index(): void
    {
        $universe = $this->service->getUniverse();
        $status   = $this->service->getStatus();
        $config   = $this->service->getConfig();
        $baseUrl  = $this->baseUrl;

        include __DIR__ . '/views/index.php';
    }

    // =========================================================================
    // Actions
    // =========================================================================

    /**
     * POST /admin/win_universe/run
     * Trigger a computation run, return JSON.
     */
    public function run(): void
    {
        header('Content-Type: application/json');
        $result = $this->service->run();
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // =========================================================================
    // API
    // =========================================================================

    /**
     * GET /admin/win_universe/api/universe
     */
    public function apiUniverse(): void
    {
        header('Content-Type: application/json');
        $data = $this->service->getUniverse() ?? ['ok' => false, 'reason' => 'no_data'];
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
