<?php
declare(strict_types=1);

/**
 * AiShadowController
 *
 * Handles UI pages and API endpoints for the AI Shadow module.
 * All routes are read-only to live data; writes only to module storage.
 */
final class AiShadowController
{
    private string $moduleBase;
    private ?AiShadowService $service = null;
    /** @var array<string,mixed> */
    private array $config = [];

    public function __construct()
    {
        $this->moduleBase = __DIR__;
        $this->service    = $this->buildService();
        $this->config     = $this->loadConfig();
    }

    // =========================================================================
    // UI pages
    // =========================================================================

    public function index(): string
    {
        $stats         = $this->service ? $this->service->getStats()              : [];
        $virtualSignals= $this->service ? $this->service->getVirtualSignals()     : [];
        $activeTrades  = $this->service ? $this->service->getVirtualActiveTrades(): [];
        $closedTrades  = $this->service ? $this->service->getVirtualClosedTrades(): [];
        $status        = $this->service ? $this->service->getStatus()             : [];
        $config        = $this->config;

        ob_start();
        require $this->moduleBase . '/views/index.php';
        return (string)ob_get_clean();
    }

    public function settings(): string
    {
        $config = $this->config;

        ob_start();
        require $this->moduleBase . '/views/settings.php';
        return (string)ob_get_clean();
    }

    // =========================================================================
    // API endpoints
    // =========================================================================

    public function apiRunMirror(): void
    {
        $this->requirePost();
        $result = $this->service ? $this->service->runLiveMirror() : ['error' => 'service_unavailable'];
        $this->jsonResponse($result);
    }

    public function apiRunReplay(): void
    {
        $this->requirePost();

        $rawInput = (string)file_get_contents('php://input');
        $body     = json_decode($rawInput, true);
        $signals  = is_array($body['signals'] ?? null) ? $body['signals'] : [];

        $result = $this->service ? $this->service->runReplay($signals) : ['error' => 'service_unavailable'];
        $this->jsonResponse($result);
    }

    public function apiSignals(): void
    {
        $filters = $this->getQueryFilters();
        $data    = $this->service ? $this->service->getVirtualSignals($filters) : [];
        $this->jsonResponse(['signals' => $data, 'count' => count($data)]);
    }

    public function apiActiveTrades(): void
    {
        $filters = $this->getQueryFilters();
        $data    = $this->service ? $this->service->getVirtualActiveTrades($filters) : [];
        $this->jsonResponse(['trades' => $data, 'count' => count($data)]);
    }

    public function apiClosedTrades(): void
    {
        $filters = $this->getQueryFilters();
        $data    = $this->service ? $this->service->getVirtualClosedTrades($filters) : [];
        $this->jsonResponse(['trades' => $data, 'count' => count($data)]);
    }

    public function apiStats(): void
    {
        $stats = $this->service ? $this->service->getStats() : [];
        $this->jsonResponse($stats);
    }

    public function apiSaveSettings(): void
    {
        $this->requirePost();

        $rawInput = (string)file_get_contents('php://input');
        $body     = json_decode($rawInput, true);

        if (!is_array($body)) {
            $this->jsonResponse(['ok' => false, 'error' => 'invalid_json']);
            return;
        }

        $path   = $this->moduleBase . '/config/ai_shadow.json';
        $current = is_file($path)
            ? (json_decode((string)file_get_contents($path), true) ?: [])
            : [];

        // Merge only allowed non-secret fields — raw API keys must NEVER be stored here
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
            if (array_key_exists($key, $body)) {
                $current[$key] = $body[$key];
            }
        }

        // mode is always shadow — never allow override to something else
        $current['mode'] = 'shadow';

        $written = file_put_contents(
            $path,
            json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        );

        if ($written === false) {
            $this->jsonResponse(['ok' => false, 'error' => 'write_failed']);
            return;
        }

        $this->config = $current;
        $this->jsonResponse(['ok' => true]);
    }

    public function apiClearStorage(): void
    {
        $this->requirePost();

        if ($this->service) {
            $this->service->clearStorage();
        }

        $this->jsonResponse(['ok' => true, 'cleared_at' => time()]);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function buildService(): ?AiShadowService
    {
        try {
            require_once $this->moduleBase . '/service.php';
            return new AiShadowService();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function loadConfig(): array
    {
        $path = $this->moduleBase . '/config/ai_shadow.json';
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    private function requirePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            $this->jsonResponse(['error' => 'method_not_allowed']);
            exit;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function getQueryFilters(): array
    {
        $allowed = ['symbol', 'pattern_algorithm', 'side', 'ai_decision', 'agreement', 'status'];
        $filters = [];
        foreach ($allowed as $key) {
            $val = $_GET[$key] ?? null;
            if ($val !== null && $val !== '') {
                $filters[$key] = (string)$val;
            }
        }
        return $filters;
    }

    /**
     * @param array<string,mixed>|array<int,mixed> $data
     */
    private function jsonResponse(array $data): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
