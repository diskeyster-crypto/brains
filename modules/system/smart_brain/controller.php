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
     * Global config page
     * GET /admin/smart_brain/config
     */
    public function config(): void
    {
        $config = $this->service->getConfig();
        $smartBrainUrl = $this->smartBrainUrl;

        include __DIR__ . '/views/config.php';
    }

    /**
     * Analizator page
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
     * Runtime API
     * GET /admin/smart_brain/api/runtime
     */
    public function runtime(): void
    {
        $data = $this->service->getRuntimeData();

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
