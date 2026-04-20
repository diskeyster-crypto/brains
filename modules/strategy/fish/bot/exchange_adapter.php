<?php

declare(strict_types=1);

namespace Modules\Strategy\Fish\Bot;

use Core\Gateway\Bybit;

/**
 * Fish Bot — Exchange Adapter
 *
 * Thin wrapper around the centralized Core\Gateway\Bybit client.
 * Fish bot calls this adapter; it must NEVER copy API-key storage, signing
 * logic, or raw HTTP transport — all of that stays in Core\Gateway\Bybit.
 *
 * LIVE mode: uses Bybit::client($accountId) where $accountId comes from
 *   Fish config key `account_id`.  Credentials are resolved by Bybit::client()
 *   via KeyCenter — identical to the old trading_bot gateway.init() live path.
 *
 * SMOKE mode: every mutating call is intercepted and returns a synthetic
 *   accepted response without touching the network.
 */
final class FishExchangeAdapter
{
    private string $executionMode;
    private string $accountId;
    private string $ownerStrategy = 'fish';

    /** @var object|null Resolved Bybit client (live only) */
    private ?object $client = null;

    /** @var string|null Non-null when gateway init failed */
    private ?string $initError = null;

    /** @var array Runtime diagnostics (safe, no secrets) */
    private array $diagnostics = [];

    /**
     * @param string $executionMode  smoke | demo | live
     * @param string $accountId      KeyCenter Bybit account ID (required for live)
     */
    public function __construct(string $executionMode = 'smoke', string $accountId = '')
    {
        $this->executionMode = $executionMode;
        $this->accountId     = $accountId;

        if ($executionMode === 'live') {
            $this->initLiveClient();
        }
    }

    // -------------------------------------------------------------------------
    // Order placement
    // -------------------------------------------------------------------------

    /**
     * Place a limit order on behalf of Fish.
     * Smoke mode returns a synthetic accepted response without touching the API.
     *
     * @param  array  $params  Bybit /v5/order/create parameters
     * @return array  Unified gateway response (or smoke-mode synthetic)
     */
    public function placeOrder(array $params): array
    {
        $params['orderLinkId'] = $this->buildLinkId($params['orderLinkId'] ?? '');

        if ($this->isSmokeMode()) {
            return $this->smokeAccepted('placeOrder', $params);
        }

        if ($this->initError !== null) {
            return $this->gatewayError('placeOrder', $this->initError);
        }

        return $this->client->request('orders.create', $params, true);
    }

    /**
     * Cancel an open Fish order.
     */
    public function cancelOrder(string $symbol, string $orderId, string $orderLinkId = ''): array
    {
        $params = [
            'category'    => 'linear',
            'symbol'      => $symbol,
            'orderId'     => $orderId,
            'orderLinkId' => $orderLinkId,
        ];

        if ($this->isSmokeMode()) {
            return $this->smokeAccepted('cancelOrder', $params);
        }

        if ($this->initError !== null) {
            return $this->gatewayError('cancelOrder', $this->initError);
        }

        return $this->client->request('orders.cancel', $params, true);
    }

    /**
     * Fetch open Fish orders for a symbol.
     */
    public function getOpenOrders(string $symbol): array
    {
        $params = [
            'category' => 'linear',
            'symbol'   => $symbol,
        ];

        if ($this->isSmokeMode()) {
            return $this->smokeRead('getOpenOrders', ['result' => ['list' => []]]);
        }

        if ($this->initError !== null) {
            return $this->gatewayError('getOpenOrders', $this->initError);
        }

        return $this->client->request('orders.list', $params, true);
    }

    /**
     * Fetch open positions for a symbol.
     */
    public function getPositions(string $symbol = ''): array
    {
        $params = ['category' => 'linear'];
        if ($symbol !== '') {
            $params['symbol'] = $symbol;
        }

        if ($this->isSmokeMode()) {
            return $this->smokeRead('getPositions', ['result' => ['list' => []]]);
        }

        if ($this->initError !== null) {
            return $this->gatewayError('getPositions', $this->initError);
        }

        return $this->client->request('positions.list', $params, true);
    }

    /**
     * Set leverage for a symbol.
     */
    public function setLeverage(string $symbol, int $leverage): array
    {
        $params = [
            'category'     => 'linear',
            'symbol'       => $symbol,
            'buyLeverage'  => (string)$leverage,
            'sellLeverage' => (string)$leverage,
        ];

        if ($this->isSmokeMode()) {
            return $this->smokeAccepted('setLeverage', $params);
        }

        if ($this->initError !== null) {
            return $this->gatewayError('setLeverage', $this->initError);
        }

        return $this->client->request('/v5/position/set-leverage', $params, true);
    }

    /**
     * Set trading-stop (stop-loss / take-profit) on an open position.
     */
    public function setTradingStop(array $params): array
    {
        $params['category'] = $params['category'] ?? 'linear';

        if ($this->isSmokeMode()) {
            return $this->smokeAccepted('setTradingStop', $params);
        }

        if ($this->initError !== null) {
            return $this->gatewayError('setTradingStop', $this->initError);
        }

        return $this->client->request('/v5/position/trading-stop', $params, true);
    }

    // -------------------------------------------------------------------------
    // Diagnostics (safe — no secrets)
    // -------------------------------------------------------------------------

    /**
     * Returns safe runtime diagnostics for bot_last_run and stats pages.
     * No API keys or secrets are included.
     */
    public function getDiagnostics(): array
    {
        return array_merge([
            'execution_mode'   => $this->executionMode,
            'account_id_used'  => $this->accountId !== '' ? $this->accountId : '(not set)',
            'client_init'      => $this->initError === null ? 'ok' : 'failed',
            'init_error'       => $this->initError,
        ], $this->diagnostics);
    }

    // -------------------------------------------------------------------------
    // State helpers
    // -------------------------------------------------------------------------

    public function isLiveMode(): bool
    {
        return $this->executionMode === 'live';
    }

    public function isSmokeMode(): bool
    {
        return $this->executionMode !== 'live';
    }

    public function getExecutionMode(): string
    {
        return $this->executionMode;
    }

    public function getInitError(): ?string
    {
        return $this->initError;
    }

    // -------------------------------------------------------------------------
    // Private: live client initialization (mirrors trading_bot/gateway.php init())
    // -------------------------------------------------------------------------

    private function initLiveClient(): void
    {
        $accountId = $this->accountId;

        if ($accountId === '') {
            $this->initError = 'account_id_not_set: Fish config key "account_id" is empty. ' .
                'Set it to a Bybit account configured in KeyCenter (Admin → KeyCenter).';
            $this->diagnostics['client_init_detail'] = $this->initError;
            return;
        }

        if (!class_exists('\\Core\\KeyCenter\\KeyCenter')) {
            $this->initError = 'KeyCenter class not found';
            return;
        }

        if (!class_exists('\\Core\\Gateway\\Bybit')) {
            $this->initError = 'Core\\Gateway\\Bybit class not found';
            return;
        }

        try {
            $keyCenter = \Core\KeyCenter\KeyCenter::instance();

            $hasCredentials = $keyCenter->hasCredentials('bybit', $accountId);

            if (!$hasCredentials) {
                $availableAccounts = $keyCenter->listAccounts('bybit');
                $availableStr = empty($availableAccounts)
                    ? '(none configured)'
                    : implode(', ', $availableAccounts);

                $this->initError = "Bybit credentials missing in KeyCenter for account: {$accountId}. " .
                    "Available Bybit accounts: {$availableStr}. " .
                    "Configure credentials via Admin → KeyCenter or update Fish config account_id.";
                $this->diagnostics['available_accounts'] = $availableAccounts ?? [];
                return;
            }

            $credentials = $keyCenter->getBybitCredentials($accountId);
            if (empty($credentials)) {
                $this->initError = "KeyCenter: Bybit credentials for account '{$accountId}' exist " .
                    "but are not usable (decryption failed). Restore the encryption key or re-save " .
                    "the API key/secret in KeyCenter.";
                return;
            }

            // Mirror trading_bot gateway: Bybit::client($accountId) loads creds from KeyCenter
            $this->client = Bybit::client($accountId);
            $this->diagnostics['account_id_resolved'] = $accountId;
            $this->diagnostics['client_init'] = 'ok';

        } catch (\Throwable $e) {
            $this->initError = 'gateway_init_exception: ' . $e->getMessage();
            $this->diagnostics['client_init_detail'] = $this->initError;
        }
    }

    // -------------------------------------------------------------------------
    // Private: link ID builder, smoke/error helpers
    // -------------------------------------------------------------------------

    /**
     * Prefix Fish's signal-id-based link IDs to ensure exchange-level ownership traceability.
     */
    private function buildLinkId(string $base): string
    {
        $prefix = 'fish_';
        if ($base === '') {
            return $prefix . substr(md5(uniqid('', true)), 0, 20);
        }
        if (str_starts_with($base, $prefix)) {
            return substr($base, 0, 36);   // Bybit max 36 chars
        }
        return substr($prefix . $base, 0, 36);
    }

    private function smokeAccepted(string $op, array $params): array
    {
        return [
            'success'      => true,
            'smoke'        => true,
            'operation'    => $op,
            'ret_code'     => 0,
            'ret_msg'      => 'smoke_mode',
            'http_code'    => 200,
            'endpoint'     => 'smoke/' . $op,
            'result'       => ['orderId' => 'smoke_' . substr(md5(serialize($params)), 0, 12)],
            'error_type'   => 'none',
            'request_meta' => ['params' => $params, 'owner_strategy' => $this->ownerStrategy],
        ];
    }

    private function smokeRead(string $op, array $resultOverride = []): array
    {
        return array_merge([
            'success'      => true,
            'smoke'        => true,
            'operation'    => $op,
            'ret_code'     => 0,
            'ret_msg'      => 'smoke_mode',
            'http_code'    => 200,
            'endpoint'     => 'smoke/' . $op,
            'result'       => [],
            'error_type'   => 'none',
            'request_meta' => ['owner_strategy' => $this->ownerStrategy],
        ], $resultOverride);
    }

    private function gatewayError(string $op, string $error): array
    {
        return [
            'success'      => false,
            'smoke'        => false,
            'operation'    => $op,
            'ret_code'     => -1,
            'ret_msg'      => $error,
            'http_code'    => 0,
            'endpoint'     => 'gateway_init_failed/' . $op,
            'result'       => [],
            'error_type'   => 'gateway_init_failed',
            'request_meta' => [
                'account_id'     => $this->accountId,
                'owner_strategy' => $this->ownerStrategy,
                'init_error'     => $error,
            ],
        ];
    }
}

