<?php
declare(strict_types=1);

require_once __DIR__ . '/service.php';

/**
 * CoinPassportController
 *
 * UI and API controller for the Coin Passport standalone module.
 *
 * UI routes:
 *   GET  /admin/coin_passport                    → index (list all passports)
 *   GET  /admin/coin_passport/symbol/{symbol}    → single-symbol detail view
 *
 * Action routes:
 *   POST /admin/coin_passport/rebuild            → rebuild all passports
 *   POST /admin/coin_passport/rebuild/{symbol}   → rebuild single symbol
 *
 * API routes (JSON, future Brain/Bot integration):
 *   GET  /admin/coin_passport/api/passports             → all passports
 *   GET  /admin/coin_passport/api/passport/{symbol}     → single passport
 *   GET  /admin/coin_passport/api/guidance/{symbol}     → Brain/Bot guidance block
 */
final class CoinPassportController
{
    private CoinPassportService $service;
    private string $baseUrl;

    public function __construct()
    {
        $this->service = new CoinPassportService();
        $this->baseUrl = '/admin/coin_passport';
    }

    // =========================================================================
    // UI pages
    // =========================================================================

    /**
     * GET /admin/coin_passport
     * List all coin passports.
     */
    public function index(): void
    {
        $data     = $this->service->getAllPassports();
        $passports = $data['passports'];
        $count     = $data['count'];
        $baseUrl   = $this->baseUrl;

        include __DIR__ . '/views/index.php';
    }

    /**
     * GET /admin/coin_passport/symbol/{symbol}
     * Detail page for a single symbol.
     */
    public function detail(string $symbol): void
    {
        $symbol  = strtoupper(preg_replace('/[^A-Za-z0-9_\-]/', '', $symbol));
        $passport = $this->service->getPassport($symbol);
        $baseUrl  = $this->baseUrl;

        include __DIR__ . '/views/detail.php';
    }

    // =========================================================================
    // Action routes
    // =========================================================================

    /**
     * POST /admin/coin_passport/rebuild
     * Rebuild all passports from available trade data.
     */
    public function rebuildAll(): void
    {
        $result = $this->service->rebuildAll();

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'      => true,
            'updated' => $result['updated'],
            'symbols' => $result['symbols'],
            'errors'  => $result['errors'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * POST /admin/coin_passport/rebuild/{symbol}
     * Rebuild a single symbol's passport.
     */
    public function rebuildSymbol(string $symbol): void
    {
        $symbol   = strtoupper(preg_replace('/[^A-Za-z0-9_\-]/', '', $symbol));
        $passport = $this->service->rebuildSymbol($symbol);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'      => true,
            'symbol'  => $symbol,
            'passport' => $passport,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // =========================================================================
    // API routes (read-only, for future Brain/Bot integration)
    // =========================================================================

    /**
     * GET /admin/coin_passport/api/passports
     * Return all passports as JSON.
     */
    public function apiPassports(): void
    {
        $data = $this->service->getAllPassports();

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * GET /admin/coin_passport/api/passport/{symbol}
     * Return a single passport as JSON.
     */
    public function apiPassport(string $symbol): void
    {
        $symbol   = strtoupper(preg_replace('/[^A-Za-z0-9_\-]/', '', $symbol));
        $passport = $this->service->getPassport($symbol);

        header('Content-Type: application/json; charset=utf-8');
        if ($passport === null) {
            http_response_code(404);
            echo json_encode(['error' => 'passport_not_found', 'symbol' => $symbol]);
            return;
        }
        echo json_encode($passport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * GET /admin/coin_passport/api/guidance/{symbol}
     * Return a minimal guidance block for Brain/Bot integration.
     */
    public function apiGuidance(string $symbol): void
    {
        $symbol   = strtoupper(preg_replace('/[^A-Za-z0-9_\-]/', '', $symbol));
        $guidance = $this->service->getGuidanceForSymbol($symbol);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($guidance, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
