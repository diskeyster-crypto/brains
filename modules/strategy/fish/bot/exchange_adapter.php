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
 * In smoke mode every mutating call is intercepted and only logged.
 * In live mode calls are forwarded to Bybit::client('default').
 */
final class FishExchangeAdapter
{
    private string $executionMode;
    private string $ownerStrategy = 'fish';

    public function __construct(string $executionMode = 'smoke')
    {
        $this->executionMode = $executionMode;
    }

    // -------------------------------------------------------------------------
    // Order placement
    // -------------------------------------------------------------------------

    /**
     * Place a limit order on behalf of Fish.
     * In smoke mode returns a synthetic accepted response without touching the API.
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

        return Bybit::client('default')->request('orders.create', $params, true);
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

        return Bybit::client('default')->request('orders.cancel', $params, true);
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

        return Bybit::client('default')->request('orders.list', $params, true);
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

        return Bybit::client('default')->request('positions.list', $params, true);
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

        return Bybit::client('default')->request('/v5/position/set-leverage', $params, true);
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

        return Bybit::client('default')->request('/v5/position/trading-stop', $params, true);
    }

    // -------------------------------------------------------------------------
    // Helpers
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
}
