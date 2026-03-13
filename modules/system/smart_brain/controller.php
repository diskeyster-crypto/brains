<?php
declare(strict_types=1);

require_once __DIR__ . '/service.php';

final class SmartBrainController
{
    public function index(): void
    {
        $service = new SmartBrainService();
        $data = $service->getDashboardData();

        extract($data, EXTR_SKIP);
        include __DIR__ . '/views/index.php';
    }

    public function runtime(): void
    {
        $service = new SmartBrainService();
        $data = $service->getRuntimeData();

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
