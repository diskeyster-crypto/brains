<?php
declare(strict_types=1);

namespace Modules\System\Config;

use Core\Auth\Auth;
use Core\System\System;

/**
 * UnifiedConfigController — admin UI and API for the Unified Config Module.
 *
 * Routes are served under /admin/smart_brain/config_all/.
 * Operational parameters are editable; immutable/internal params remain read-only.
 *
 * @package Modules\System\Config
 */
final class UnifiedConfigController
{
    private UnifiedConfigService $service;
    private string $baseUrl;

    public function __construct()
    {
        $this->service = new UnifiedConfigService();
        $this->baseUrl = '/admin/smart_brain/config_all';

        // Auto-extract on first page load if no artefacts exist yet.
        $this->service->maybeAutoExtract();
    }

    // =========================================================================
    // UI pages
    // =========================================================================

    public function index(): void
    {
        $this->requireAuth();
        $tab     = 'quick_control';
        $title   = 'Центр Конфигурации — Быстрое Управление';
        $summary = $this->service->getSummary();
        $data    = $this->service->getOperationalDraft();
        $master  = $this->service->getOperationalMaster();
        $baseUrl = $this->baseUrl;
        $flash   = $this->consumeFlash();
        include __DIR__ . '/views/index.php';
    }

    public function patterns(): void
    {
        $this->requireAuth();
        $tab     = 'patterns';
        $title   = 'Центр Конфигурации — Паттерны';
        $summary = $this->service->getSummary();
        $data    = $this->service->getOperationalDraft();
        $master  = $this->service->getOperationalMaster();
        $baseUrl = $this->baseUrl;
        $flash   = $this->consumeFlash();
        include __DIR__ . '/views/patterns.php';
    }

    public function smartBrain(): void
    {
        $this->requireAuth();
        $tab             = 'smart_brain';
        $title           = 'Центр Конфигурации — Smart Brain';
        $summary         = $this->service->getSummary();
        $data            = $this->service->getOperationalDraft();
        $master          = $this->service->getOperationalMaster();
        $preview         = $this->service->getEffectivePreview();
        $migrationStatus = $this->service->getSmartBrainMigrationStatus();
        $baseUrl         = $this->baseUrl;
        $flash           = $this->consumeFlash();
        include __DIR__ . '/views/smart_brain.php';
    }

    public function tradingBot(): void
    {
        $this->requireAuth();
        $tab             = 'trading_bot';
        $title           = 'Центр Конфигурации — Trading Bot';
        $summary         = $this->service->getSummary();
        $data            = $this->service->getOperationalDraft();
        $master          = $this->service->getOperationalMaster();
        $preview         = $this->service->getEffectivePreview();
        $migrationStatus = $this->service->getTradingBotMigrationStatus();
        $baseUrl         = $this->baseUrl;
        $flash           = $this->consumeFlash();
        include __DIR__ . '/views/trading_bot.php';
    }

    public function profitManager(): void
    {
        $this->requireAuth();
        $tab             = 'profit_manager';
        $title           = 'Центр Конфигурации — Менеджер Прибыли';
        $summary         = $this->service->getSummary();
        $data            = $this->service->getOperationalDraft();
        $master          = $this->service->getOperationalMaster();
        $preview         = $this->service->getEffectivePreview();
        $migrationStatus = $this->service->getProfitManagerMigrationStatus();
        $baseUrl         = $this->baseUrl;
        $flash           = $this->consumeFlash();
        include __DIR__ . '/views/profit_manager.php';
    }

    public function coinCycle(): void
    {
        $this->requireAuth();
        $tab             = 'coin_cycle';
        $title           = 'Центр Конфигурации — Coin Passport';
        $summary         = $this->service->getSummary();
        $data            = $this->service->getOperationalDraft();
        $master          = $this->service->getOperationalMaster();
        $preview         = $this->service->getEffectivePreview();
        $migrationStatus = $this->service->getCoinPassportMigrationStatus();
        $baseUrl         = $this->baseUrl;
        $flash           = $this->consumeFlash();
        include __DIR__ . '/views/coin_cycle.php';
    }

    public function winUniverse(): void
    {
        $this->requireAuth();

        $tab     = 'win_universe';
        $title   = 'Центр Конфигурации — Выигрышные монеты';
        $summary = $this->service->getSummary();
        $baseUrl = $this->baseUrl;
        $flash   = $this->consumeFlash();

        // Load win universe data from the standalone module
        $wuServicePath = dirname(__DIR__) . '/win_universe/service.php';
        $wuConfig     = [];
        $wuUniverse   = null;
        $wuStatus     = null;
        $wuPool       = null;
        $wuPromotions = null;
        $wuDemotions  = null;

        if (is_file($wuServicePath)) {
            try {
                require_once $wuServicePath;
                $wuService   = new \WinUniverseService();
                $wuConfig    = $wuService->getConfig();
                $wuUniverse  = $wuService->getUniverse();
                $wuStatus    = $wuService->getStatus();
                $wuPool      = $wuService->getPool();
                $wuPromotions = $wuService->getPromotions();
                $wuDemotions  = $wuService->getDemotions();
            } catch (\Throwable $e) {
                // Non-fatal — page renders without runtime data
            }
        }

        include __DIR__ . '/views/win_universe.php';
    }

    /**
     * POST /admin/smart_brain/config_all/win_universe/save
     *
     * Save Win Universe operational settings to the module's user config overlay.
     * Accepts HTML form POST. Redirects back with flash.
     */
    public function winUniverseSaveConfig(): void
    {
        $this->requireAuth();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . $this->baseUrl . '/win_universe');
            exit;
        }

        $wuServicePath = dirname(__DIR__) . '/win_universe/service.php';

        if (!is_file($wuServicePath)) {
            $this->setFlash('error', 'Win Universe module not found');
            header('Location: ' . $this->baseUrl . '/win_universe');
            exit;
        }

        require_once $wuServicePath;

        $post = $_POST;

        // Normalise checkboxes (not sent when unchecked)
        $post['win_universe_enabled']   = !empty($_POST['win_universe_enabled']);
        $post['priority_bonus_enabled'] = !empty($_POST['priority_bonus_enabled']);

        try {
            $wuService = new \WinUniverseService();
            $result    = $wuService->saveUserConfig($post);
        } catch (\Throwable $e) {
            $this->setFlash('error', 'Ошибка: ' . $e->getMessage());
            header('Location: ' . $this->baseUrl . '/win_universe');
            exit;
        }

        if ($result['ok']) {
            // Re-run Win Universe immediately so status.json reflects the new mode.
            // This keeps win_universe_status.json in sync with the saved config (no stale shadow/priority mismatch).
            try {
                $wuService->run();
            } catch (\Throwable $ignored) {
                // Non-fatal — config is saved; next scheduled run will pick it up.
            }
            $this->setFlash('success', 'Настройки Win Universe сохранены и применены (' . date('H:i:s') . ')');
        } else {
            $this->setFlash('error', 'Ошибка сохранения: ' . implode('; ', $result['errors']));
        }

        $redirect = (string)($_POST['_redirect'] ?? $this->baseUrl . '/win_universe');
        if (!str_starts_with($redirect, $this->baseUrl)) {
            $redirect = $this->baseUrl . '/win_universe';
        }
        header('Location: ' . $redirect);
        exit;
    }

    public function advanced(): void
    {
        $this->requireAuth();
        $tab       = 'advanced';
        $title     = 'Центр Конфигурации — Расширенный';
        $summary   = $this->service->getSummary();
        $ownership = $this->service->getOwnershipMap();
        $conflicts = $this->service->getConflictReport();
        $immutable = $this->service->getImmutableDraft();
        $master    = $this->service->getOperationalMaster();
        $baseUrl   = $this->baseUrl;
        $flash     = $this->consumeFlash();
        include __DIR__ . '/views/advanced.php';
    }

    // =========================================================================
    // API — read-only JSON endpoints
    // =========================================================================

    public function apiOwnership(): void
    {
        $this->requireAuth();
        $this->jsonResponse($this->service->getOwnershipMap());
    }

    public function apiConflicts(): void
    {
        $this->requireAuth();
        $this->jsonResponse($this->service->getConflictReport());
    }

    public function apiOperational(): void
    {
        $this->requireAuth();
        $this->jsonResponse($this->service->getOperationalDraft());
    }

    public function apiMaster(): void
    {
        $this->requireAuth();
        $this->jsonResponse($this->service->getOperationalMaster() ?? ['params' => []]);
    }

    public function apiImmutable(): void
    {
        $this->requireAuth();
        $this->jsonResponse($this->service->getImmutableDraft());
    }

    public function apiPreview(): void
    {
        $this->requireAuth();
        $this->jsonResponse($this->service->getEffectivePreview());
    }

    /**
     * POST /admin/smart_brain/config_all/api/extract
     * Trigger a manual extraction pass.
     */
    public function apiExtract(): void
    {
        $this->requireAuth();
        $result = $this->service->extract();
        $this->jsonResponse($result);
    }

    /**
     * POST /admin/smart_brain/config_all/api/save
     * Persist editable operational parameters to config_operational_master.json.
     * Accepts both standard HTML form POST and JSON body (Content-Type: application/json).
     *
     * After a successful save the page redirects back with a flash message.
     * On JSON requests, returns a JSON response directly.
     */
    public function apiSave(): void
    {
        $this->requireAuth();

        $isJson = str_contains($_SERVER['HTTP_CONTENT_TYPE'] ?? $_SERVER['CONTENT_TYPE'] ?? '', 'application/json');

        if ($isJson) {
            $body = @file_get_contents('php://input');
            $post = @json_decode((string)$body, true) ?? [];
        } else {
            $post = $_POST;
        }

        $result = $this->service->saveOperationalMaster($post);

        if ($isJson) {
            $this->jsonResponse($result, $result['ok'] ? 200 : 500);
        }

        // HTML form — redirect back with flash
        $redirect = (string)($_POST['_redirect'] ?? $this->baseUrl);
        // Security: only allow redirects within config_all
        if (!str_starts_with($redirect, $this->baseUrl)) {
            $redirect = $this->baseUrl;
        }

        if ($result['ok']) {
            $this->setFlash('success', 'Настройки сохранены (' . date('H:i:s') . ')');
        } else {
            $this->setFlash('error', 'Ошибка сохранения: ' . implode('; ', $result['errors']));
        }

        header('Location: ' . $redirect);
        exit;
    }

    /**
     * POST /admin/smart_brain/config_all/api/save_and_reextract
     * Save master config and immediately re-run extraction pass.
     */
    public function apiSaveAndReextract(): void
    {
        $this->requireAuth();

        $isJson = str_contains($_SERVER['HTTP_CONTENT_TYPE'] ?? $_SERVER['CONTENT_TYPE'] ?? '', 'application/json');

        if ($isJson) {
            $body = @file_get_contents('php://input');
            $post = @json_decode((string)$body, true) ?? [];
        } else {
            $post = $_POST;
        }

        $saveResult    = $this->service->saveOperationalMaster($post);
        $extractResult = $this->service->extract();

        if ($isJson) {
            $this->jsonResponse([
                'save'    => $saveResult,
                'extract' => $extractResult,
                'ok'      => $saveResult['ok'] && $extractResult['ok'],
            ]);
        }

        $redirect = (string)($_POST['_redirect'] ?? $this->baseUrl);
        if (!str_starts_with($redirect, $this->baseUrl)) {
            $redirect = $this->baseUrl;
        }

        if ($saveResult['ok'] && $extractResult['ok']) {
            $this->setFlash('success', 'Настройки сохранены и конфигурация перечитана (' . date('H:i:s') . ')');
        } elseif (!$saveResult['ok']) {
            $this->setFlash('error', 'Ошибка сохранения: ' . implode('; ', $saveResult['errors']));
        } else {
            $this->setFlash('warning', 'Настройки сохранены, но перечитать конфигурацию не удалось: ' . implode('; ', $extractResult['errors']));
        }

        header('Location: ' . $redirect);
        exit;
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    private function requireAuth(): void
    {
        if (!Auth::check()) {
            header('Location: ' . System::adminUrl('login'));
            exit;
        }
    }

    private function jsonResponse(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** Store a one-time flash message in the session. */
    private function setFlash(string $type, string $message): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['config_center_flash'] = ['type' => $type, 'message' => $message];
    }

    /** Read and clear the flash message from the session. */
    private function consumeFlash(): array
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $flash = $_SESSION['config_center_flash'] ?? [];
        unset($_SESSION['config_center_flash']);
        return $flash;
    }
}
