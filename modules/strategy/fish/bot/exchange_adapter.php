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
     * Submit an internal Fish order contract to the live exchange.
     *
     * Mirrors trading_bot/gateway.php::submitOrder() exactly:
     * - validates order_type
     * - normalizes qty to qtyStep via instruments-info
     * - maps snake_case internal fields → Bybit camelCase params
     * - strips internal-only fields (_fish_meta, signal_id, created_at, etc.)
     * - calls client->request('orders.create', $params, true)
     *
     * @param  array  $order  Internal order contract from FishOrderBuilder::build()
     * @return array  Unified result with success/error/order_id
     */
    public function submitOrder(array $order): array
    {
        if ($this->isSmokeMode()) {
            return $this->smokeAccepted('submitOrder', $order);
        }

        if ($this->initError !== null) {
            return $this->gatewayError('submitOrder', $this->initError);
        }

        // Validate order_type (required; no fallback — same rule as old gateway)
        if (empty($order['order_type'])) {
            return ['success' => false, 'error' => 'missing_field:order_type'];
        }

        $symbol = (string)($order['symbol'] ?? '');

        // Normalize qty to qtyStep (mirrors gateway.normalizeQty)
        $qtyNorm = $this->normalizeQty($symbol, (float)($order['qty'] ?? 0));
        if (!$qtyNorm['ok']) {
            return [
                'success' => false,
                'error'   => 'qty_invalid_step_or_min',
                'context' => $qtyNorm,
            ];
        }
        $normalizedQty = $qtyNorm['qty'];

        // Build Bybit API params — same mapping as gateway.submitOrder()
        $params = [
            'category'    => 'linear',
            'symbol'      => $symbol,
            'side'        => $order['side'],
            'orderType'   => ucfirst(strtolower($order['order_type'])),
            'qty'         => (string)$normalizedQty,
            'timeInForce' => $order['time_in_force'] ?? 'GTC',
        ];

        // Add reduceOnly if specified
        if (isset($order['reduce_only'])) {
            $params['reduceOnly'] = (bool)$order['reduce_only'];
        }

        // Add price for limit orders
        if (strtolower($order['order_type']) === 'limit' && isset($order['price'])) {
            $params['price'] = (string)$order['price'];
        }

        // Add TP/SL if present (from _fish_meta or direct keys)
        $meta = $order['_fish_meta'] ?? [];
        $stopLoss   = (float)($order['stop_loss']   ?? $meta['stop_price']        ?? 0);
        $takeProfit = (float)($order['take_profit']  ?? $meta['take_profit_price'] ?? 0);
        if ($stopLoss > 0) {
            $params['stopLoss']   = (string)$stopLoss;
        }
        if ($takeProfit > 0) {
            $params['takeProfit'] = (string)$takeProfit;
        }

        // Map order_link_id → orderLinkId (same as gateway.submitOrder())
        if (!empty($order['order_link_id'])) {
            $params['orderLinkId'] = $this->buildLinkId((string)$order['order_link_id']);
        }

        // Record diagnostics (safe — no secrets, no API keys)
        $this->diagnostics['last_internal_order_contract'] = [
            'symbol'        => $symbol,
            'side'          => $order['side'] ?? '?',
            'order_type'    => $order['order_type'],
            'qty_raw'       => $order['qty'] ?? 0,
            'qty_norm'      => $normalizedQty,
            'price'         => $order['price'] ?? null,
            'time_in_force' => $order['time_in_force'] ?? 'GTC',
            'order_link_id' => $order['order_link_id'] ?? null,
            'owner'         => $meta['owner_strategy'] ?? 'fish',
            'signal_id'     => $meta['owner_signal_id'] ?? ($order['signal_id'] ?? null),
        ];
        $this->diagnostics['last_normalized_params'] = array_diff_key($params, ['stopLoss' => 1, 'takeProfit' => 1]);

        $resp = $this->client->request('orders.create', $params, true);

        if (!isset($resp['success']) || $resp['success'] !== true) {
            $this->diagnostics['last_submit_result']   = 'failed';
            $this->diagnostics['last_submit_error']    = $resp['error'] ?? $resp['ret_msg'] ?? 'bybit_request_failed';
            return [
                'success'       => false,
                'error'         => $resp['error'] ?? $resp['ret_msg'] ?? 'bybit_request_failed',
                'response'      => $resp,
                'normalized_qty' => $normalizedQty,
            ];
        }

        $orderId = $resp['result']['orderId'] ?? '';
        if (empty($orderId)) {
            $this->diagnostics['last_submit_result'] = 'missing_order_id';
            return [
                'success'  => false,
                'error'    => 'missing_order_id',
                'response' => $resp,
            ];
        }

        $this->diagnostics['last_submit_result'] = 'ok';
        $this->diagnostics['last_submit_order_id'] = (string)$orderId;
        return [
            'success'        => true,
            'ok'             => true,
            'order_id'       => (string)$orderId,
            'result'         => $resp['result'] ?? [],   // passthrough so callers can read result.orderId
            'response'       => $resp,
            'normalized_qty' => $normalizedQty,
        ];
    }

    // -------------------------------------------------------------------------
    // Order placement (legacy thin wrapper — now delegates to submitOrder)
    // -------------------------------------------------------------------------

    /**
     * Place a limit order on behalf of Fish.
     *
     * Accepts either:
     * - an internal order contract from FishOrderBuilder::build() (preferred; has order_type field)
     * - legacy raw Bybit-style params (smoke mode / backwards compat)
     *
     * In smoke mode returns a synthetic accepted response without touching the API.
     * In live mode delegates to submitOrder() which applies full normalization.
     *
     * @param  array  $params  Internal order contract from FishOrderBuilder::build()
     * @return array  Unified gateway response (or smoke-mode synthetic)
     */
    public function placeOrder(array $params): array
    {
        // Detect internal contract (has snake_case order_type) vs legacy raw params
        if (isset($params['order_type'])) {
            return $this->submitOrder($params);
        }

        // Legacy / raw Bybit-style params path (smoke or backwards compat)
        if (!isset($params['orderLinkId']) || $params['orderLinkId'] === '') {
            $params['orderLinkId'] = $this->buildLinkId('');
        }

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
     * Fetch current position mode for a symbol (one-way vs hedge).
     *
     * In smoke mode: returns one_way / positionIdx=0 (safe default).
     * In live mode: queries /v5/position/switch-mode.
     *
     * Returns:
     *   ['success' => bool, 'position_mode' => 'one_way'|'hedge', 'position_idx' => 0|1|2,
     *    'raw_mode' => int|null, 'smoke' => bool]
     */
    public function getPositionMode(string $symbol): array
    {
        if ($this->isSmokeMode()) {
            return [
                'success'        => true,
                'smoke'          => true,
                'position_mode'  => 'one_way',
                'position_idx'   => 0,
                'raw_mode'       => 0,
            ];
        }

        if ($this->initError !== null) {
            return $this->gatewayError('getPositionMode', $this->initError);
        }

        $params = [
            'category' => 'linear',
            'symbol'   => $symbol,
        ];

        try {
            $resp = $this->client->request('/v5/position/switch-mode', $params, false);
        } catch (\Throwable $e) {
            return [
                'success'       => false,
                'position_mode' => 'one_way',
                'position_idx'  => 0,
                'error'         => 'getPositionMode exception: ' . $e->getMessage(),
            ];
        }

        if (!($resp['success'] ?? false)) {
            // Fall back to one-way (index=0) on failure — matches old bot default
            return [
                'success'       => false,
                'position_mode' => 'one_way',
                'position_idx'  => 0,
                'error'         => $resp['ret_msg'] ?? 'switch_mode_query_failed',
            ];
        }

        // Bybit returns mode: 0 = one-way, 3 = hedge
        $rawMode     = (int)($resp['result']['mode'] ?? 0);
        $isHedge     = ($rawMode === 3);
        $modeLabel   = $isHedge ? 'hedge' : 'one_way';
        // positionIdx: 0 for one-way; for hedge, use 0 as default (caller resolves side-specific 1/2)
        $positionIdx = 0;

        return [
            'success'        => true,
            'smoke'          => false,
            'position_mode'  => $modeLabel,
            'position_idx'   => $positionIdx,
            'raw_mode'       => $rawMode,
        ];
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
     * Query the current status of a Fish-owned order.
     *
     * In smoke mode: always returns 'Filled' — smoke orders have no exchange lifecycle.
     * In live mode: queries /v5/order/history with orderId.
     *
     * @param  string $symbol          Trading pair (e.g. 'BTCUSDT')
     * @param  string $exchangeOrderId Exchange-assigned order ID
     * @return array  {success, status, avg_price, qty, smoke, raw?, error?}
     */
    public function getOrderStatus(string $symbol, string $exchangeOrderId): array
    {
        if ($this->isSmokeMode()) {
            return [
                'success'   => true,
                'smoke'     => true,
                'status'    => 'Filled',
                'order_id'  => $exchangeOrderId,
                'avg_price' => 0.0,
                'qty'       => 0.0,
            ];
        }

        if ($this->initError !== null) {
            return $this->gatewayError('getOrderStatus', $this->initError);
        }

        $params = [
            'category' => 'linear',
            'symbol'   => $symbol,
            'orderId'  => $exchangeOrderId,
        ];

        $resp = $this->client->request('/v5/order/history', $params, true);

        if (!($resp['success'] ?? false)) {
            return [
                'success'  => false,
                'status'   => 'unknown',
                'error'    => $resp['ret_msg'] ?? 'order_history_failed',
                'response' => $resp,
            ];
        }

        $list  = $resp['result']['list'] ?? [];
        $order = $list[0] ?? null;

        if ($order === null) {
            return [
                'success' => false,
                'status'  => 'not_found',
                'error'   => 'order_not_in_history',
            ];
        }

        return [
            'success'   => true,
            'smoke'     => false,
            'status'    => $order['orderStatus'] ?? 'Unknown',
            'order_id'  => $exchangeOrderId,
            'avg_price' => (float)($order['avgPrice'] ?? 0.0),
            'qty'       => (float)($order['qty']      ?? 0.0),
            'raw'       => $order,
        ];
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
    // Private: order normalization helpers (mirror trading_bot/gateway.php)
    // -------------------------------------------------------------------------

    /**
     * Normalize qty to qtyStep via Bybit instruments-info.
     * Mirrors trading_bot/gateway.php::normalizeQty() exactly.
     *
     * @return array ['ok' => bool, 'qty' => float, 'error' => string|null, ...]
     */
    private function normalizeQty(string $symbol, float $qty): array
    {
        $meta = $this->getInstrumentMeta($symbol);

        if ($meta === null) {
            // No meta available — return original qty with warning (same as old gateway)
            return [
                'ok'      => true,
                'qty'     => $qty,
                'error'   => null,
                'meta'    => null,
                'warning' => 'no_instrument_meta',
            ];
        }

        $qtyStep = (float)($meta['qtyStep']      ?? 0.001);
        $minQty  = (float)($meta['minOrderQty']  ?? 0.001);

        $normalizedQty = $this->floorToStep($qty, $qtyStep);

        if ($normalizedQty < $minQty) {
            return [
                'ok'           => false,
                'qty'          => $normalizedQty,
                'error'        => 'qty_below_min',
                'meta'         => $meta,
                'min_qty'      => $minQty,
                'requested_qty' => $qty,
            ];
        }

        return [
            'ok'    => true,
            'qty'   => $normalizedQty,
            'error' => null,
            'meta'  => $meta,
        ];
    }

    /**
     * Fetch instrument meta from Bybit (tickSize, qtyStep, minOrderQty).
     * Mirrors trading_bot/gateway.php::getInstrumentMeta().
     */
    private function getInstrumentMeta(string $symbol): ?array
    {
        if ($this->client === null) {
            return null;
        }

        try {
            $resp = $this->client->request('/v5/market/instruments-info', [
                'category' => 'linear',
                'symbol'   => $symbol,
            ], false);

            if (($resp['success'] ?? false) !== true) {
                return null;
            }

            $instrument = $resp['result']['list'][0] ?? null;
            if ($instrument === null) {
                return null;
            }

            return [
                'symbol'       => $symbol,
                'tickSize'     => (float)($instrument['priceFilter']['tickSize']         ?? 0.01),
                'qtyStep'      => (float)($instrument['lotSizeFilter']['qtyStep']        ?? 0.001),
                'minOrderQty'  => (float)($instrument['lotSizeFilter']['minOrderQty']    ?? 0.001),
                'maxOrderQty'  => (float)($instrument['lotSizeFilter']['maxOrderQty']    ?? 10000),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Floor value to step (mirrors trading_bot/gateway.php::floorToStep) */
    private function floorToStep(float $value, float $step): float
    {
        if ($step <= 0) {
            return $value;
        }
        return floor($value / $step) * $step;
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

