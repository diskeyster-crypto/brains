<?php
declare(strict_types=1);

/**
 * Copytrading Parser — Controller
 *
 * Handles admin routes for /admin/copytrading
 *
 * @package Modules\Copytrading
 */
final class CopytradingController
{
    private ?CopytradingService $service = null;

    public function __construct()
    {
        $servicePath = __DIR__ . '/service.php';
        if (is_file($servicePath)) {
            require_once $servicePath;
            $this->service = new CopytradingService();
        }
    }

    /**
     * Main index page.
     */
    public function index(): void
    {
        if ($this->service === null) {
            $this->error('Service not available');
            return;
        }

        $traders = $this->service->getTraders();
        $lastRun = $this->service->getLastRun();
        $positions = $this->service->getActivePositions();
        $history = $this->service->getHistory();

        require __DIR__ . '/views/index.php';
    }

    /**
     * Run parser manually.
     */
    public function run(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/admin/copytrading');
            return;
        }

        if ($this->service === null) {
            $this->jsonError('Service not available');
            return;
        }

        $result = $this->service->execute();

        $this->jsonResponse($result);
    }

    /**
     * Add trader.
     */
    public function addTrader(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/admin/copytrading');
            return;
        }

        if ($this->service === null) {
            $this->jsonError('Service not available');
            return;
        }

        $leaderMark = $_POST['leader_mark'] ?? '';
        
        // Extract leaderMark from URL if full URL provided
        if (strpos($leaderMark, 'leaderMark=') !== false) {
            parse_str(parse_url($leaderMark, PHP_URL_QUERY) ?? '', $params);
            $leaderMark = $params['leaderMark'] ?? '';
        }

        if (empty($leaderMark)) {
            $this->jsonError('Trader ID (leaderMark) is required');
            return;
        }

        $success = $this->service->addTrader($leaderMark);

        $this->jsonResponse([
            'ok' => $success,
            'message' => $success ? 'Trader added' : 'Trader already exists or save failed',
        ]);
    }

    /**
     * Remove trader.
     */
    public function removeTrader(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('/admin/copytrading');
            return;
        }

        if ($this->service === null) {
            $this->jsonError('Service not available');
            return;
        }

        $leaderMark = $_POST['leader_mark'] ?? '';

        if (empty($leaderMark)) {
            $this->jsonError('Trader ID (leaderMark) is required');
            return;
        }

        $success = $this->service->removeTrader($leaderMark);

        $this->jsonResponse([
            'ok' => $success,
            'message' => $success ? 'Trader removed' : 'Trader not found or remove failed',
        ]);
    }

    /**
     * API: Get positions.
     */
    public function apiPositions(): void
    {
        if ($this->service === null) {
            $this->jsonError('Service not available');
            return;
        }

        $this->jsonResponse($this->service->getActivePositions());
    }

    /**
     * API: Get history.
     */
    public function apiHistory(): void
    {
        if ($this->service === null) {
            $this->jsonError('Service not available');
            return;
        }

        $this->jsonResponse($this->service->getHistory());
    }

    /**
     * Redirect helper.
     */
    private function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }

    /**
     * Error page.
     */
    private function error(string $message): void
    {
        http_response_code(500);
        echo '<h1>Error</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    }

    /**
     * JSON response.
     *
     * @param mixed $data
     */
    private function jsonResponse($data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * JSON error response.
     */
    private function jsonError(string $message, int $code = 400): void
    {
        http_response_code($code);
        $this->jsonResponse(['ok' => false, 'error' => $message]);
    }
}
