<?php
declare(strict_types=1);

namespace Modules\System\Config;

use Core\Auth\Auth;
use Core\System\System;

/**
 * UnifiedConfigController — admin UI and read-only API for the Unified Config Module.
 *
 * Routes are served under /admin/smart_brain/config_all/.
 * All pages are read-only — no config mutations are possible from the UI.
 *
 * @package Modules\System\Config
 */
final class UnifiedConfigController
{
    private UnifiedConfigService $service;
    private string $baseUrl;

    public function __construct()
    {
        // Bootstrap service (loads lib files via bootstrap.php which is auto-loaded)
        $this->service = new UnifiedConfigService();
        $this->baseUrl = '/admin/smart_brain/config_all';

        // Auto-extract on first page load if no artefacts exist yet.
        // Subsequent loads are cheap (just checks last_extract.json timestamp).
        $this->service->maybeAutoExtract();
    }

    // =========================================================================
    // UI pages
    // =========================================================================

    public function index(): void
    {
        $this->requireAuth();
        $tab     = 'quick_control';
        $title   = 'Config — Quick Control';
        $summary = $this->service->getSummary();
        $data    = $this->service->getOperationalDraft();
        $baseUrl = $this->baseUrl;
        include __DIR__ . '/views/index.php';
    }

    public function patterns(): void
    {
        $this->requireAuth();
        $tab     = 'patterns';
        $title   = 'Config — Patterns';
        $summary = $this->service->getSummary();
        $data    = $this->service->getOperationalDraft();
        $baseUrl = $this->baseUrl;
        include __DIR__ . '/views/patterns.php';
    }

    public function smartBrain(): void
    {
        $this->requireAuth();
        $tab     = 'smart_brain';
        $title   = 'Config — Smart Brain';
        $summary = $this->service->getSummary();
        $data    = $this->service->getOperationalDraft();
        $preview = $this->service->getEffectivePreview();
        $baseUrl = $this->baseUrl;
        include __DIR__ . '/views/smart_brain.php';
    }

    public function tradingBot(): void
    {
        $this->requireAuth();
        $tab     = 'trading_bot';
        $title   = 'Config — Trading Bot';
        $summary = $this->service->getSummary();
        $data    = $this->service->getOperationalDraft();
        $preview = $this->service->getEffectivePreview();
        $baseUrl = $this->baseUrl;
        include __DIR__ . '/views/trading_bot.php';
    }

    public function profitManager(): void
    {
        $this->requireAuth();
        $tab     = 'profit_manager';
        $title   = 'Config — Profit Manager';
        $summary = $this->service->getSummary();
        $data    = $this->service->getOperationalDraft();
        $preview = $this->service->getEffectivePreview();
        $baseUrl = $this->baseUrl;
        include __DIR__ . '/views/profit_manager.php';
    }

    public function coinCycle(): void
    {
        $this->requireAuth();
        $tab     = 'coin_cycle';
        $title   = 'Config — Coin / Cycle';
        $summary = $this->service->getSummary();
        $data    = $this->service->getOperationalDraft();
        $baseUrl = $this->baseUrl;
        include __DIR__ . '/views/coin_cycle.php';
    }

    public function advanced(): void
    {
        $this->requireAuth();
        $tab     = 'advanced';
        $title   = 'Config — Advanced / Expert';
        $summary = $this->service->getSummary();
        $ownership = $this->service->getOwnershipMap();
        $conflicts = $this->service->getConflictReport();
        $immutable = $this->service->getImmutableDraft();
        $baseUrl = $this->baseUrl;
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
}
