<?php

declare(strict_types=1);

namespace Modules\System\Brain;

use Core\System\System;
use Core\System\SystemPaths;
use Core\Auth\Auth;
use Core\Logger\Logger;

/**
 * Brain Controller
 * 
 * HTTP controller for Brain module - Strategy Builder & Pipeline Orchestrator.
 * Handles all routes for /admin/brain/*
 */
final class BrainController
{
    private static ?self $instance = null;
    private BrainService $service;

    private function __construct()
    {
        require_once SystemPaths::instance()->get('system.brain') . '/service.php';
        $this->service = BrainService::instance();
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Main Brain dashboard
     * GET /admin/brain
     */
    public function index(): string
    {
        if (!Auth::check()) {
            header('Location: ' . System::adminUrl('login'));
            exit;
        }

        $strategies = $this->service->getStrategies();
        $stats = $this->service->getStats();
        $lastRun = $this->service->getLastRun();
        $config = $this->service->getConfig();
        $moduleStatus = $this->service->getModuleStatus();
        $flash = $this->getFlash();

        ob_start();
        $title = 'Brain - Meta-Orchestrator';
        require SystemPaths::instance()->get('system.brain') . '/views/index.php';
        $content = ob_get_clean();

        return $this->wrapLayout($title, $content);
    }

    /**
     * Strategies management page
     * GET /admin/brain/strategies
     */
    public function strategies(): string
    {
        if (!Auth::check()) {
            header('Location: ' . System::adminUrl('login'));
            exit;
        }

        $strategies = $this->service->getStrategies();
        $config = $this->service->getConfig();
        $flash = $this->getFlash();

        ob_start();
        $title = 'Brain - Strategies';
        require SystemPaths::instance()->get('system.brain') . '/views/strategies.php';
        $content = ob_get_clean();

        return $this->wrapLayout($title, $content);
    }

    /**
     * Run history page
     * GET /admin/brain/runs
     */
    public function runs(): string
    {
        if (!Auth::check()) {
            header('Location: ' . System::adminUrl('login'));
            exit;
        }

        $runs = $this->service->getRuns(50);
        $flash = $this->getFlash();

        ob_start();
        $title = 'Brain - Run History';
        require SystemPaths::instance()->get('system.brain') . '/views/runs.php';
        $content = ob_get_clean();

        return $this->wrapLayout($title, $content);
    }

    /**
     * Save strategy
     * POST /admin/brain/strategy/save
     */
    public function strategySave(): void
    {
        if (!Auth::check()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $input = $this->getInput();
        $result = $this->service->saveStrategy($input);

        if ($result['success']) {
            $this->setFlash('success', 'Strategy saved successfully');
        } else {
            $this->setFlash('error', $result['error'] ?? 'Failed to save strategy');
        }

        // Check if this is an AJAX request
        if ($this->isAjax()) {
            $this->jsonResponse($result);
        } else {
            header('Location: ' . System::web('admin/brain'));
            exit;
        }
    }

    /**
     * Delete strategy
     * POST /admin/brain/strategy/delete
     */
    public function strategyDelete(): void
    {
        if (!Auth::check()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $input = $this->getInput();
        $id = $input['id'] ?? '';

        if (empty($id)) {
            $this->setFlash('error', 'Strategy ID required');
            $this->redirect('admin/brain');
            return;
        }

        $result = $this->service->deleteStrategy($id);

        if ($result['success']) {
            $this->setFlash('success', 'Strategy deleted');
        } else {
            $this->setFlash('error', $result['error'] ?? 'Failed to delete strategy');
        }

        if ($this->isAjax()) {
            $this->jsonResponse($result);
        } else {
            $this->redirect('admin/brain');
        }
    }

    /**
     * Toggle strategy for simulator
     * POST /admin/brain/strategy/toggle-simulator
     */
    public function strategyToggleSimulator(): void
    {
        if (!Auth::check()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $input = $this->getInput();
        $id = $input['id'] ?? '';
        $enabled = (bool)($input['enabled'] ?? false);

        if (empty($id)) {
            $this->jsonResponse(['success' => false, 'error' => 'Strategy ID required'], 400);
            return;
        }

        $result = $this->service->toggleSimulator($id, $enabled);
        $this->jsonResponse($result);
    }

    /**
     * Toggle strategy for executor
     * POST /admin/brain/strategy/toggle-executor
     */
    public function strategyToggleExecutor(): void
    {
        if (!Auth::check()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $input = $this->getInput();
        $id = $input['id'] ?? '';
        $enabled = (bool)($input['enabled'] ?? false);

        if (empty($id)) {
            $this->jsonResponse(['success' => false, 'error' => 'Strategy ID required'], 400);
            return;
        }

        $result = $this->service->toggleExecutor($id, $enabled);
        $this->jsonResponse($result);
    }

    /**
     * Run pipeline manually
     * POST /admin/brain/run
     */
    public function runPipeline(): void
    {
        if (!Auth::check()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $result = $this->service->runPipeline();

        if ($result['success']) {
            $this->setFlash('success', "Orchestration completed in {$result['duration_ms']}ms");
        } else {
            $errors = implode(', ', $result['errors'] ?? ['Unknown error']);
            $this->setFlash('error', "Orchestration failed: {$errors}");
        }

        if ($this->isAjax()) {
            $this->jsonResponse($result);
        } else {
            $this->redirect('admin/brain');
        }
    }

    /**
     * API: Get strategies list
     * GET /admin/brain/api/strategies
     */
    public function apiStrategies(): void
    {
        if (!Auth::check()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $strategies = $this->service->getStrategies();
        $this->jsonResponse([
            'success' => true,
            'strategies' => array_values($strategies),
            'count' => count($strategies),
        ]);
    }

    /**
     * API: Get run history
     * GET /admin/brain/api/runs
     */
    public function apiRuns(): void
    {
        if (!Auth::check()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $limit = $this->getQueryParam('limit', 20);
        $runs = $this->service->getRuns($limit);
        
        $this->jsonResponse([
            'success' => true,
            'runs' => $runs,
            'count' => count($runs),
        ]);
    }

    /**
     * API: Get feedback data
     * GET /admin/brain/api/feedback
     */
    public function apiFeedback(): void
    {
        if (!Auth::check()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $limit = $this->getQueryParam('limit', 50);
        $feedback = $this->service->getFeedback($limit);
        
        $this->jsonResponse([
            'success' => true,
            'feedback' => $feedback,
            'count' => count($feedback),
        ]);
    }
    
    // ========================================================================
    // BRAIN v2.2 — UI: Runtime & Timeline (Блок 5)
    // ========================================================================
    
    /**
     * Runtime status page (v2.2 Блок 5)
     * GET /admin/brain/runtime
     */
    public function runtime(): string
    {
        if (!Auth::check()) {
            header('Location: ' . System::adminUrl('login'));
            exit;
        }

        $runtimeInfo = $this->service->getRuntimeInfo();
        $processTelemetry = $this->service->readProcessTelemetry(50);
        $lastRun = $this->service->getLastRun();
        $stats = $this->service->getStats();
        $flash = $this->getFlash();

        ob_start();
        $title = 'Brain - Runtime';
        require SystemPaths::instance()->get('system.brain') . '/views/runtime.php';
        $content = ob_get_clean();

        return $this->wrapLayout($title, $content);
    }
    
    /**
     * Timeline page (v2.2 Блок 5)
     * GET /admin/brain/timeline
     */
    public function timeline(): string
    {
        if (!Auth::check()) {
            header('Location: ' . System::adminUrl('login'));
            exit;
        }

        $events = $this->service->getTimeline(100);
        $flash = $this->getFlash();

        ob_start();
        $title = 'Brain - Timeline';
        require SystemPaths::instance()->get('system.brain') . '/views/timeline.php';
        $content = ob_get_clean();

        return $this->wrapLayout($title, $content);
    }
    
    /**
     * Passports page
     * GET /admin/brain/passports
     */
    public function passports(): string
    {
        if (!Auth::check()) {
            header('Location: ' . System::adminUrl('login'));
            exit;
        }

        $strategies = $this->service->getStrategies();
        $flash = $this->getFlash();

        ob_start();
        $title = 'Brain - Passports';
        require SystemPaths::instance()->get('system.brain') . '/views/passports.php';
        $content = ob_get_clean();

        return $this->wrapLayout($title, $content);
    }
    
    /**
     * Decisions page
     * GET /admin/brain/decisions
     */
    public function decisions(): string
    {
        if (!Auth::check()) {
            header('Location: ' . System::adminUrl('login'));
            exit;
        }

        $runs = $this->service->getRuns(50);
        $stats = $this->service->getStats();
        $flash = $this->getFlash();

        ob_start();
        $title = 'Brain - Decisions';
        require SystemPaths::instance()->get('system.brain') . '/views/decisions.php';
        $content = ob_get_clean();

        return $this->wrapLayout($title, $content);
    }
    
    /**
     * Coin Passports page
     * GET /admin/brain/coin-passports
     */
    public function coinPassports(): string
    {
        if (!Auth::check()) {
            header('Location: ' . System::adminUrl('login'));
            exit;
        }

        $passports = $this->service->getPassports();
        $flash = $this->getFlash();

        ob_start();
        $title = 'Brain - Coin Passports';
        require SystemPaths::instance()->get('system.brain') . '/views/coin_passports.php';
        $content = ob_get_clean();

        return $this->wrapLayout($title, $content);
    }
    
    /**
     * Rebuild Coin Passports
     * POST /admin/brain/coin-passports/rebuild
     */
    public function coinPassportsRebuild(): void
    {
        if (!Auth::check()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $result = $this->service->buildPassportsFromSimulator();
        
        $this->jsonResponse([
            'success' => $result['success'],
            'passports_created' => $result['passports_created'] ?? 0,
            'symbols_processed' => $result['symbols_processed'] ?? 0,
            'total_trades' => $result['total_trades'] ?? 0,
            'errors' => $result['errors'] ?? [],
        ]);
    }
    
    /**
     * API: Get runtime info (v2.2 Блок 5)
     * GET /admin/brain/api/runtime
     */
    public function apiRuntime(): void
    {
        if (!Auth::check()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $runtimeInfo = $this->service->getRuntimeInfo();
        
        $this->jsonResponse([
            'success' => true,
            'runtime' => $runtimeInfo,
        ]);
    }
    
    /**
     * API: Get timeline events (v2.2 Блок 5)
     * GET /admin/brain/api/timeline
     */
    public function apiTimeline(): void
    {
        if (!Auth::check()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $limit = $this->getQueryParam('limit', 50);
        $type = $this->getQueryParam('type', null);
        
        $events = $this->service->readEvents($limit, $type);
        
        $this->jsonResponse([
            'success' => true,
            'events' => $events,
            'count' => count($events),
        ]);
    }
    
    /**
     * API: Stop/kill current run (v2.2 Блок 5)
     * POST /admin/brain/api/stop
     */
    public function apiStop(): void
    {
        if (!Auth::check()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $result = $this->service->stopPipelineRun();
        
        if ($result['success']) {
            $this->setFlash('success', 'Pipeline run stopped');
        } else {
            $this->setFlash('error', $result['error'] ?? 'Failed to stop run');
        }
        
        $this->jsonResponse($result);
    }
    
    /**
     * API: Get process telemetry (v2.2 Блок 3)
     * GET /admin/brain/api/telemetry
     */
    public function apiTelemetry(): void
    {
        if (!Auth::check()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $limit = $this->getQueryParam('limit', 100);
        $telemetry = $this->service->readProcessTelemetry($limit);
        
        $this->jsonResponse([
            'success' => true,
            'telemetry' => $telemetry,
            'count' => count($telemetry),
        ]);
    }

    // ========================================================================
    // RISK PROFILES v1 - UI Pages & API
    // Per ТЗ: Brain Risk Profile v1 + Smart Trailing
    // ========================================================================
    
    /**
     * Profiles management page
     * GET /admin/brain/profiles
     */
    public function profiles(): string
    {
        if (!Auth::check()) {
            header('Location: ' . System::adminUrl('login'));
            exit;
        }

        $profiles = $this->service->getProfiles();
        $activeProfileId = $this->service->getActiveProfileId();
        $config = $this->service->getConfig();
        $flash = $this->getFlash();

        ob_start();
        $title = 'Brain - Risk Profiles';
        require SystemPaths::instance()->get('system.brain') . '/views/profiles.php';
        $content = ob_get_clean();

        return $this->wrapLayout($title, $content);
    }
    
    /**
     * API: Get profiles list
     * GET /admin/brain/api/profiles/list
     */
    public function apiProfilesList(): void
    {
        if (!Auth::check()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $profiles = $this->service->getProfiles();
        $activeProfileId = $this->service->getActiveProfileId();
        
        $this->jsonResponse([
            'success' => true,
            'profiles' => array_values($profiles),
            'active_profile_id' => $activeProfileId,
            'count' => count($profiles),
        ]);
    }
    
    /**
     * API: Get single profile
     * GET /admin/brain/api/profiles/get?id=xxx
     */
    public function apiProfileGet(): void
    {
        if (!Auth::check()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $profileId = $this->getQueryParam('id', '');
        if (empty($profileId)) {
            $this->jsonResponse(['success' => false, 'error' => 'Profile ID required'], 400);
            return;
        }
        
        $profile = $this->service->getProfile($profileId);
        if ($profile === null) {
            $this->jsonResponse(['success' => false, 'error' => 'Profile not found'], 404);
            return;
        }
        
        $this->jsonResponse([
            'success' => true,
            'profile' => $profile,
        ]);
    }
    
    /**
     * API: Create profile
     * POST /admin/brain/api/profiles/create
     */
    public function apiProfileCreate(): void
    {
        if (!Auth::check()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $input = $this->getInput();
        $result = $this->service->saveProfile($input);
        
        if ($result['success']) {
            $this->jsonResponse([
                'success' => true,
                'message' => 'Profile created',
                'profile' => $result['profile'],
            ]);
        } else {
            $this->jsonResponse([
                'success' => false,
                'errors' => $result['errors'] ?? ['Unknown error'],
            ], 400);
        }
    }
    
    /**
     * API: Update profile
     * POST /admin/brain/api/profiles/update
     */
    public function apiProfileUpdate(): void
    {
        if (!Auth::check()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $input = $this->getInput();
        
        if (empty($input['profile_id'])) {
            $this->jsonResponse(['success' => false, 'error' => 'Profile ID required'], 400);
            return;
        }
        
        $result = $this->service->saveProfile($input);
        
        if ($result['success']) {
            $this->jsonResponse([
                'success' => true,
                'message' => 'Profile updated',
                'profile' => $result['profile'],
            ]);
        } else {
            $this->jsonResponse([
                'success' => false,
                'errors' => $result['errors'] ?? ['Unknown error'],
            ], 400);
        }
    }
    
    /**
     * API: Delete profile
     * POST /admin/brain/api/profiles/delete
     */
    public function apiProfileDelete(): void
    {
        if (!Auth::check()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $input = $this->getInput();
        $profileId = $input['id'] ?? $input['profile_id'] ?? '';
        
        if (empty($profileId)) {
            $this->jsonResponse(['success' => false, 'error' => 'Profile ID required'], 400);
            return;
        }
        
        $result = $this->service->deleteProfile($profileId);
        
        if ($result['success']) {
            $this->jsonResponse([
                'success' => true,
                'message' => 'Profile deleted',
            ]);
        } else {
            $this->jsonResponse([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to delete profile',
            ], 400);
        }
    }
    
    /**
     * API: Set active profile
     * POST /admin/brain/api/profiles/set_active
     */
    public function apiProfileSetActive(): void
    {
        if (!Auth::check()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $input = $this->getInput();
        $profileId = $input['id'] ?? $input['profile_id'] ?? null;
        
        // Allow null/empty to clear active profile
        if ($profileId === '' || $profileId === 'null') {
            $profileId = null;
        }
        
        $result = $this->service->setActiveProfile($profileId);
        
        if ($result['success']) {
            $this->jsonResponse([
                'success' => true,
                'message' => $profileId ? 'Active profile set' : 'Active profile cleared',
                'active_profile_id' => $result['active_profile_id'],
            ]);
        } else {
            $this->jsonResponse([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to set active profile',
            ], 400);
        }
    }

    // ========================================================================
    // C5: RESET ALL + C6: SELFTEST ENDPOINTS
    // ========================================================================

    /**
     * C5.1: Reset All - Clear Brain + Simulator storage completely
     * POST /admin/brain/api/reset_all
     * Requires: confirm=yes
     */
    public function apiResetAll(): void
    {
        if (!Auth::check()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $input = $this->getInput();
        $confirm = $input['confirm'] ?? ($_GET['confirm'] ?? '');
        
        if ($confirm !== 'yes') {
            $this->jsonResponse([
                'success' => false,
                'error' => 'Confirmation required. Send confirm=yes',
            ], 400);
            return;
        }
        
        $result = $this->service->resetAll();
        $this->jsonResponse($result);
    }
    
    /**
     * C6.1: Brain selftest endpoint
     * GET /admin/brain/api/selftest
     * Returns status of keys/files/permissions
     */
    public function apiSelftest(): void
    {
        if (!Auth::check()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }
        
        $result = $this->service->selftest();
        $this->jsonResponse($result);
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    /**
     * Get query parameter with default value
     */
    private function getQueryParam(string $key, mixed $default = null): mixed
    {
        $value = $_GET[$key] ?? $default;
        if (is_int($default)) {
            return (int)$value;
        }
        return $value;
    }

    /**
     * Get input data (POST or JSON)
     */
    private function getInput(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        
        if (strpos($contentType, 'application/json') !== false) {
            return json_decode(file_get_contents('php://input'), true) ?? [];
        }
        
        return $_POST;
    }

    /**
     * Check if AJAX request
     */
    private function isAjax(): bool
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) 
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    /**
     * Redirect helper
     */
    private function redirect(string $route): void
    {
        header('Location: ' . System::web($route));
        exit;
    }

    /**
     * Set flash message
     */
    private function setFlash(string $type, string $message): void
    {
        $_SESSION['brain_flash'] = [
            'type' => $type,
            'message' => $message,
        ];
    }

    /**
     * Get and clear flash message
     */
    private function getFlash(): ?array
    {
        if (!isset($_SESSION['brain_flash'])) {
            return null;
        }
        $flash = $_SESSION['brain_flash'];
        unset($_SESSION['brain_flash']);
        return $flash;
    }

    /**
     * JSON response helper
     * TASK 2: Ensure JSON output even on Notice/Warning
     */
    private function jsonResponse(array $data, int $status = 200): void
    {
        // TASK 2: Use output buffering to capture and discard any stray output
        // This is safer than globally suppressing errors
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        ob_start();
        
        // TASK 2: Temporarily suppress display errors only for JSON output
        $originalDisplayErrors = ini_get('display_errors');
        $originalErrorReporting = error_reporting();
        ini_set('display_errors', '0');
        error_reporting(E_ERROR | E_PARSE); // Only fatal errors
        
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        
        // Capture and discard any output that might have leaked
        ob_end_clean();
        
        // Output the JSON
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        // Restore original error settings (in case exit doesn't happen)
        ini_set('display_errors', $originalDisplayErrors);
        error_reporting($originalErrorReporting);
        
        exit;
    }

    /**
     * Wrap content in admin layout
     */
    private function wrapLayout(string $title, string $content): string
    {
        require_once System::path('root') . '/admin/views/layout.php';
        return renderLayout($title, $content, 'brain', []);
    }
}

/* RULES
 * CONFIG FIRST / ZERO-HARDCODE
 * SystemPaths / PackMap ONLY (no local path computations)
 */
