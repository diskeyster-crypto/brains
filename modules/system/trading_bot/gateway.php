<?php
declare(strict_types=1);

namespace Modules\System\TradingBot;

/**
 * Trading Bot Gateway
 * 
 * Exchange gateway wrapper for Trading Bot.
 * Uses Core\Gateway\Bybit::client()->request() for all API calls.
 * 
 * NO direct method calls like $client->getPositions() - these don't exist!
 * ALL calls go through $client->request(endpoint, params, signed).
 */
class TradingBotGateway
{
    private array $config;
    private string $mode = 'dry';
    private ?object $client = null;
    
    /** @var array P4: Instrument meta cache (tickSize, qtyStep, minOrderQty) */
    private array $instrumentMetaCache = [];
    
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->mode = $config['module']['mode'] ?? 'dry';
        $this->init();
    }

    /**
     * Normalize side into canonical long|short values.
     *
     * Some callers may pass Bybit-native sides (Buy/Sell).
     * Normalization MUST be tolerant here because rounding rules
     * (floor/ceil) depend on the correct canonical side.
     *
     * @param string $side Raw side
     * @return string Canonical side: long|short|<raw-lower>
     */
    private function normalizeLongShortSide(string $side): string
    {
        $s = strtolower(trim($side));

        if ($s === 'buy') {
            return 'long';
        }
        if ($s === 'sell') {
            return 'short';
        }
        if ($s === 'long' || $s === 'short') {
            return $s;
        }

        return $s;
    }
    
    /**
     * Initialize exchange client
     * 
     * @throws \RuntimeException if client cannot be initialized
     */
    public function init(): void
    {
        $accountId = $this->config['module']['account_id'] ?? 'trading_bot';
        $mode = $this->config['module']['mode'] ?? 'dry';
        
        if (!class_exists('\\Core\\Gateway\\Bybit')) {
            throw new \RuntimeException('Core\\Gateway\\Bybit class not found');
        }
        
        // P0.4.2: Preflight credentials check (LIVE mode only)
        if ($mode === 'live') {
            $keyCenter = \Core\KeyCenter\KeyCenter::instance();

            $hasStoredCredentials = $keyCenter->hasCredentials('bybit', $accountId);
            $credentials = $keyCenter->getBybitCredentials($accountId);

            if (!$hasStoredCredentials) {
                $availableAccounts = $keyCenter->listAccounts('bybit');
                $availableStr = empty($availableAccounts)
                    ? '(none configured)'
                    : implode(', ', $availableAccounts);

                throw new \RuntimeException(
                    "Bybit credentials missing in KeyCenter for account: {$accountId}. " .
                    "Available Bybit accounts: {$availableStr}. " .
                    "Configure credentials via Admin -> KeyCenter or set module.account_id to an existing account."
                );
            }

            // Credentials exist, but are not usable (most commonly: decryption failed due to wrong storage/.encryption_key).
            if (empty($credentials)) {
                throw new \RuntimeException(
                    "KeyCenter: Bybit credentials for account '{$accountId}' exist but are not usable (decryption failed). " .
                    "Restore the original storage/.encryption_key from the environment where the keys were created, " .
                    "or re-save the API key/secret in KeyCenter to re-encrypt them."
                );
            }
        }
        
        $this->client = \Core\Gateway\Bybit::client($accountId);
        
        if ($this->client === null) {
            throw new \RuntimeException("Failed to initialize Bybit client for account: {$accountId}");
        }
    }
    
    /**
     * Get category from config
     */
    private function getCategory(): string
    {
        return $this->config['module']['category'] ?? 'linear';
    }
    
    /**
     * Get settle coin from config
     */
    private function getSettleCoin(): string
    {
        return $this->config['module']['settle_coin'] ?? 'USDT';
    }
    
    

    /**
     * Extract Bybit retCode from different wrapper formats.
     *
     * @param array<string,mixed> $resp
     * @return int
     */
    private function extractRetCode(array $resp): int
    {
        $code = $resp['ret_code'] ?? $resp['retCode'] ?? $resp['ret_code'] ?? null;
        if ($code === null && isset($resp['retCode'])) {
            $code = $resp['retCode'];
        }
        if ($code === null && isset($resp['ret_code'])) {
            $code = $resp['ret_code'];
        }
        return is_numeric($code) ? (int)$code : -1;
    }

    /**
     * Extract Bybit retMsg from different wrapper formats.
     *
     * @param array<string,mixed> $resp
     * @return string
     */
    private function extractRetMsg(array $resp): string
    {
        // Prefer explicit auth hint when the underlying gateway client reports missing API key.
        $meta = $resp['request_meta'] ?? null;
        if (is_array($meta) && array_key_exists('api_key_configured', $meta) && $meta['api_key_configured'] === false) {
            $accountId = (string)($this->config['module']['account_id'] ?? '');
            $msg = 'auth_missing_api_key: signed request requires API key';
            if ($accountId !== '') {
                $msg .= ' (account_id=' . $accountId . ')';
            }
            $msg .= '. Configure it in KeyCenter (Bybit) or change trading_bot/config/bot.json module.account_id.';
            return $msg;
        }

        $msg = $resp['ret_msg'] ?? $resp['retMsg'] ?? $resp['ret_msg'] ?? $resp['retMsg'] ?? null;
        if ($msg === null && isset($resp['error'])) {
            $msg = $resp['error'];
        }
        return is_string($msg) ? $msg : '';
    }

    /**
     * Determine whether a Bybit response is successful.
     *
     * Some wrappers return:
     *   ['success' => true, 'result' => ...]
     * Others may return raw Bybit style:
     *   ['retCode' => 0, 'retMsg' => 'OK', 'result' => ...]
     *
     * @param array<string,mixed> $resp
     * @return bool
     */
    private function isSuccessResponse(array $resp): bool
    {
        if (isset($resp['success'])) {
            return $resp['success'] === true;
        }

        $code = $this->extractRetCode($resp);
        return $code === 0;
    }

    /**
     * Build compact debug string for Bybit/gateway failures.
     *
     * @param array<string,mixed> $resp
     */
    private function buildErrorSummary(array $resp): string
    {
        $code = $this->extractRetCode($resp);
        $msg = $this->extractRetMsg($resp);

        $parts = [];
        if ($code !== 0) {
            $parts[] = 'ret_code=' . $code;
        }
        if ($msg !== '') {
            $parts[] = 'ret_msg=' . $msg;
        }

        // In our gateway wrapper errors may be nested under "response.request_meta".
        if (isset($resp['response']['request_meta']) && is_array($resp['response']['request_meta'])) {
            $meta = $resp['response']['request_meta'];
            if (array_key_exists('api_key_configured', $meta)) {
                $parts[] = 'api_key_configured=' . (($meta['api_key_configured'] === true) ? 'true' : 'false');
            }
            if (isset($meta['method']) && is_string($meta['method'])) {
                $parts[] = 'method=' . $meta['method'];
            }
            if (isset($meta['url']) && is_string($meta['url'])) {
                $parts[] = 'url=' . $meta['url'];
            }
        }

        return implode('; ', $parts);
    }

// ========================================================================
    // P4: Instrument Meta & Normalization
    // ========================================================================
    
    /**
     * P4: Get instrument meta (tickSize, qtyStep, minOrderQty)
     * 
     * Caches result to avoid repeated API calls.
     * 
     * @param string $symbol Symbol
     * @return array|null Meta or null on error
     */
    private function getInstrumentMeta(string $symbol): ?array
    {
        // Return from cache if available
        if (isset($this->instrumentMetaCache[$symbol])) {
            return $this->instrumentMetaCache[$symbol];
        }
        
        if (!$this->client) {
            return null;
        }
        
        try {
            $resp = $this->client->request('/v5/market/instruments-info', [
                'category' => $this->getCategory(),
                'symbol' => $symbol,
            ], false);
            
            if (!isset($resp['success']) || $resp['success'] !== true) {
                return null;
            }
            
            $instrument = $resp['result']['list'][0] ?? null;
            if ($instrument === null) {
                return null;
            }
            
            $meta = [
                'symbol' => $symbol,
                'tickSize' => (float)($instrument['priceFilter']['tickSize'] ?? 0.01),
                'qtyStep' => (float)($instrument['lotSizeFilter']['qtyStep'] ?? 0.001),
                'minOrderQty' => (float)($instrument['lotSizeFilter']['minOrderQty'] ?? 0.001),
                'maxOrderQty' => (float)($instrument['lotSizeFilter']['maxOrderQty'] ?? 10000),
                                'minLeverage' => (float)($instrument['leverageFilter']['minLeverage'] ?? 1),
                'maxLeverage' => (float)($instrument['leverageFilter']['maxLeverage'] ?? 0),
                'leverageStep' => (float)($instrument['leverageFilter']['leverageStep'] ?? 0),
                'cached_at' => time(),
            ];
            
            $this->instrumentMetaCache[$symbol] = $meta;
            return $meta;
            
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    /**
     * P4: Floor value to step
     */
    private function floorToStep(float $value, float $step): float
    {
        if ($step <= 0) {
            return $value;
        }
        return floor($value / $step) * $step;
    }
    
    /**
     * P4: Ceil value to step
     */
    private function ceilToStep(float $value, float $step): float
    {
        if ($step <= 0) {
            return $value;
        }
        return ceil($value / $step) * $step;
    }
    
    /**
     * P4: Normalize quantity to qtyStep
     * 
     * @param string $symbol Symbol
     * @param float $qty Raw quantity
     * @return array ['ok' => bool, 'qty' => float, 'error' => string|null, 'meta' => array|null]
     */
    public function normalizeQty(string $symbol, float $qty): array
    {
        $meta = $this->getInstrumentMeta($symbol);
        
        if ($meta === null) {
            // No meta - return original qty (fallback)
            return [
                'ok' => true,
                'qty' => $qty,
                'error' => null,
                'meta' => null,
                'warning' => 'no_instrument_meta',
            ];
        }
        
        $qtyStep = $meta['qtyStep'];
        $minQty = $meta['minOrderQty'];
        
        // Floor to qtyStep
        $normalizedQty = $this->floorToStep($qty, $qtyStep);
        
        // Check minimum
        if ($normalizedQty < $minQty) {
            return [
                'ok' => false,
                'qty' => $normalizedQty,
                'error' => 'qty_below_min',
                'meta' => $meta,
                'min_qty' => $minQty,
                'requested_qty' => $qty,
            ];
        }
        
        return [
            'ok' => true,
            'qty' => $normalizedQty,
            'error' => null,
            'meta' => $meta,
        ];
    }
    
    /**
     * P4: Normalize price for stop-loss/take-profit
     * 
     * For LONG: floor (SL should not accidentally go higher)
     * For SHORT: ceil (SL should not accidentally go lower)
     * 
     * @param string $symbol Symbol
     * @param string $side Position side (long|short)
     * @param float $price Raw price
     * @return float Normalized price
     */
    public function normalizePriceForStop(string $symbol, string $side, float $price): float
    {
        $meta = $this->getInstrumentMeta($symbol);
        
        if ($meta === null) {
            return round($price, 8);
        }
        
        $tickSize = $meta['tickSize'];
        $sideNorm = $this->normalizeLongShortSide($side);

        // LONG SL: floor to avoid raising SL unintentionally
        // SHORT SL: ceil to avoid lowering SL unintentionally
        if ($sideNorm === 'long') {
            return $this->floorToStep($price, $tickSize);
        }
        if ($sideNorm === 'short') {
            return $this->ceilToStep($price, $tickSize);
        }

        // Unknown side: do not bias rounding (avoid systematic raise/lower).
        return round($price, 8);
    }
    
    /**
     * P4: Normalize price generically (to tickSize)
     * 
     * @param string $symbol Symbol
     * @param float $price Raw price
     * @return float Normalized price
     */
    public function normalizePriceGeneric(string $symbol, float $price): float
    {
        $meta = $this->getInstrumentMeta($symbol);
        
        if ($meta === null) {
            return round($price, 8);
        }
        
        $tickSize = $meta['tickSize'];
        return $this->floorToStep($price, $tickSize);
    }
    
    /**
     * Unified response handling
     * 
     * @param array $resp Raw API response
     * @return array Normalized response with success/error
     */
    private function normalizeResponse(array $resp): array
    {
        if (!isset($resp['success']) || $resp['success'] !== true) {
            return [
                'success' => false,
                'error' => $resp['error'] ?? $resp['ret_msg'] ?? 'bybit_request_failed',
                'response' => $resp,
            ];
        }
        
        return [
            'success' => true,
            'result' => $resp['result'] ?? [],
            'response' => $resp,
        ];
    }
    
    /**
     * Get last price for symbol
     * 
     * Endpoint: market.tickers
     * Signed: false
     */
    public function getLastPrice(string $symbol): ?float
    {
        if (!$this->client) {
            return null;
        }
        
        try {
            $resp = $this->client->request('market.tickers', [
                'category' => $this->getCategory(),
                'symbol' => $symbol,
            ], false);
            
            $price = $resp['result']['list'][0]['lastPrice'] ?? null;
            
            if ($price === null) {
                return null;
            }
            
            return (float)$price;
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    /**
     * Get open positions
     * 
     * Endpoint: positions.list
     * Signed: true
     */
    public function getPositions(): array
    {
        if (!$this->client) {
            return [];
        }
        
        try {
            $resp = $this->client->request('positions.list', [
                'category' => $this->getCategory(),
                'settleCoin' => $this->getSettleCoin(),
            ], true);
            
            if (!isset($resp['success']) || $resp['success'] !== true) {
                // In LIVE mode we must surface auth/config failures instead of silently returning empty.
                if ($this->mode === 'live') {
                    throw new \RuntimeException('Bybit getPositions failed: ' . $this->buildErrorSummary($resp));
                }
                return [];
            }
            
            return $resp['result']['list'] ?? [];
        } catch (\Throwable $e) {
            // In LIVE mode we prefer surfacing the reason via BotReconcileTrait (it catches exceptions).
            if ($this->mode === 'live') {
                throw $e;
            }
            return [];
        }
    }

    /**
     * P9: Get closed PnL records within a time window
     *
     * Endpoint: /v5/position/closed-pnl
     * Signed: true
     *
     * Used for dashboard stats (lookback-based wins/losses/PnL).
     *
     * Bybit returns items under result.list and may include a pagination cursor
     * (nextPageCursor / cursor).
     *
     * @param int $startTimeMs Start time in milliseconds
     * @param int $endTimeMs End time in milliseconds
     * @param int $limit Max number of items to return (soft cap)
     * @return array{ok:bool,items:array<int,array<string,mixed>>,count:int,error?:string,response?:array<string,mixed>}
     */
    public function getClosedPnl(int $startTimeMs, int $endTimeMs, int $limit = 200): array
    {
        if (!$this->client) {
            return [
                'ok' => false,
                'items' => [],
                'count' => 0,
                'error' => 'bybit_client_not_initialized',
            ];
        }

        // Bybit V5 closed-pnl supports up to 200 per page.
        $limit = max(1, min(200, $limit));

        $items = [];
        $cursor = null;
        $pages = 0;

        try {
            while (true) {
                $pages++;
                if ($pages > 6) {
                    // Safety guard: never loop forever in UI stats.
                    break;
                }

                $params = [
                    'category' => $this->getCategory(),
                    'startTime' => $startTimeMs,
                    'endTime' => $endTimeMs,
                    'limit' => $limit,
                ];

                if (is_string($cursor) && $cursor !== '') {
                    $params['cursor'] = $cursor;
                }

                $resp = $this->client->request('/v5/position/closed-pnl', $params, true);

                if (!isset($resp['success']) || $resp['success'] !== true) {
                    return [
                        'ok' => false,
                        'items' => [],
                        'count' => 0,
                        'error' => $resp['error'] ?? $resp['ret_msg'] ?? 'closed_pnl_failed',
                        'response' => $resp,
                    ];
                }

                $list = $resp['result']['list'] ?? [];
                if (is_array($list)) {
                    foreach ($list as $row) {
                        if (is_array($row)) {
                            $items[] = $row;
                            if (count($items) >= $limit) {
                                break 2;
                            }
                        }
                    }
                }

                $next = $resp['result']['nextPageCursor'] ?? $resp['result']['cursor'] ?? null;
                if (!is_string($next) || $next === '') {
                    break;
                }

                // Continue pagination
                $cursor = $next;
            }

            return [
                'ok' => true,
                'items' => $items,
                'count' => count($items),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'items' => [],
                'count' => 0,
                'error' => 'exception:' . $e->getMessage(),
            ];
        }
    }
    
    /**
     * P6.6: Get wallet balance for preflight margin check
     * 
     * Endpoint: /v5/account/wallet-balance
     * Signed: true
     * 
     * FIX: For UNIFIED accounts, use account-level totalAvailableBalance
     * (NOT deprecated coin.availableToWithdraw).
     * 
     * @param string|null $accountType Account type (UNIFIED or CONTRACT), defaults to config
     * @param string|null $coin Coin to check (defaults to config balance_coin)
     * @return array|null Balance data or null on error
     */
        public function getWalletBalance(?string $accountType = null, ?string $coin = null): ?array
    {
        if (!$this->client) {
            return [
                'error' => 'client_not_initialized',
                'coin' => $coin ?? ($this->config['exchange']['balance_coin'] ?? 'USDT'),
                'account_type' => $accountType ?? ($this->config['exchange']['account_type'] ?? 'UNIFIED'),
            ];
        }

        $accountType = $accountType ?? ($this->config['exchange']['account_type'] ?? 'UNIFIED');
        $coin = $coin ?? ($this->config['exchange']['balance_coin'] ?? 'USDT');
        $strictStable = (bool)($this->config['execution']['balance_strict_stable_coin_only'] ?? true);

        // Optional: always send "coin" param for better compatibility across wrappers / Bybit account setups.
        $sendCoinParam = (bool)($this->config['execution']['balance_send_coin_param'] ?? true);

        try {
            $params = [
                'accountType' => $accountType,
            ];
            if ($sendCoinParam) {
                $params['coin'] = $coin;
            }

            $resp = $this->client->request('/v5/account/wallet-balance', $params, true);

            if (!is_array($resp)) {
                return [
                    'error' => 'wallet_balance_invalid_response',
                    'coin' => $coin,
                    'account_type' => $accountType,
                    'response' => $resp,
                ];
            }

            if (!$this->isSuccessResponse($resp)) {
                $errorCode = 'wallet_balance_failed';
                $meta = $resp['request_meta'] ?? null;
                if (is_array($meta) && (($meta['api_key_configured'] ?? null) === false)) {
                    $errorCode = 'wallet_balance_auth_missing';
                }
                return [
                    'error' => $errorCode,
                    'coin' => $coin,
                    'account_type' => $accountType,
                    'ret_code' => $this->extractRetCode($resp),
                    'ret_msg' => $this->extractRetMsg($resp),
                    'response' => $resp,
                ];
            }

            $accounts = $resp['result']['list'] ?? null;
            if (!is_array($accounts)) {
                return [
                    'error' => 'wallet_balance_missing_list',
                    'coin' => $coin,
                    'account_type' => $accountType,
                    'ret_code' => $this->extractRetCode($resp),
                    'ret_msg' => $this->extractRetMsg($resp),
                    'response' => $resp,
                ];
            }
            if (empty($accounts)) {
                return [
                    'error' => 'wallet_balance_empty_list',
                    'coin' => $coin,
                    'account_type' => $accountType,
                    'ret_code' => $this->extractRetCode($resp),
                    'ret_msg' => $this->extractRetMsg($resp),
                    'response' => $resp,
                ];
            }

            $account = $accounts[0];

            // Account-level totals (UNIFIED)
            $rawTotalAvailableBalance = $account['totalAvailableBalance'] ?? null;
            $totalMarginBalance = (float)($account['totalMarginBalance'] ?? 0.0);
            $totalWalletBalance = (float)($account['totalWalletBalance'] ?? 0.0);
            $totalEquity = (float)($account['totalEquity'] ?? 0.0);

            // Missing means not present or not numeric. 0 is a valid value and MUST NOT be treated as "missing".
            $accountTotalsMissing = ($rawTotalAvailableBalance === null
                || $rawTotalAvailableBalance === ''
                || !is_numeric($rawTotalAvailableBalance));

            $totalAvailableBalance = is_numeric($rawTotalAvailableBalance)
                ? (float)$rawTotalAvailableBalance
                : 0.0;

            $isStable = in_array(strtoupper($coin), ['USDT', 'USDC'], true);
            $isUnified = strtoupper($accountType) === 'UNIFIED';

            // ====================================================================
            // Determine "available" (coin units) depending on account type.
            // For UNIFIED + stable coin, totalAvailableBalance is the primary source.
            // ====================================================================
            if ($isUnified && $isStable) {
                if ($accountTotalsMissing) {
                    // Fallback to coin-level calculation
                    $coinData = null;
                    $coins = $account['coin'] ?? [];
                    if (is_array($coins)) {
                        foreach ($coins as $c) {
                            if (is_array($c) && (($c['coin'] ?? '') === $coin)) {
                                $coinData = $c;
                                break;
                            }
                        }
                    }

                    if ($coinData === null) {
                        return [
                            'error' => 'balance_coin_not_found',
                            'coin' => $coin,
                            'account_type' => $accountType,
                            'account_totals_missing' => $accountTotalsMissing,
                            'raw_totalAvailableBalance' => $rawTotalAvailableBalance,
                            'response' => $resp,
                        ];
                    }

                    // available = equity - totalOrderIM - totalPositionIM - locked - bonus
                    $equity = (float)($coinData['equity'] ?? 0.0);
                    $totalOrderIM = (float)($coinData['totalOrderIM'] ?? 0.0);
                    $totalPositionIM = (float)($coinData['totalPositionIM'] ?? 0.0);
                    $locked = (float)($coinData['locked'] ?? 0.0);
                    $bonus = (float)($coinData['bonus'] ?? 0.0);

                    $available = max(0.0, $equity - $totalOrderIM - $totalPositionIM - $locked - $bonus);
                    $source = 'coin_equity_fallback';

                    // Keep USD field consistent for dashboards
                    $totalAvailableBalance = $available;
                } else {
                    $available = $totalAvailableBalance;
                    $source = 'totalAvailableBalance';
                }
            } elseif ($isUnified && !$isStable) {
                if ($strictStable) {
                    return [
                        'error' => 'balance_coin_not_supported_unified',
                        'coin' => $coin,
                        'account_type' => $accountType,
                        'strict_stable_coin_only' => true,
                        'response' => $resp,
                    ];
                }

                // Non-strict: try conversion via coin.usdValue
                $coinData = null;
                $coins = $account['coin'] ?? [];
                if (is_array($coins)) {
                    foreach ($coins as $c) {
                        if (is_array($c) && (($c['coin'] ?? '') === $coin)) {
                            $coinData = $c;
                            break;
                        }
                    }
                }

                if ($coinData === null) {
                    return [
                        'error' => 'balance_coin_not_found',
                        'coin' => $coin,
                        'account_type' => $accountType,
                        'response' => $resp,
                    ];
                }

                $usdValue = (float)($coinData['usdValue'] ?? 0.0);
                $walletBalance = (float)($coinData['walletBalance'] ?? 0.0);

                if ($walletBalance <= 0.0 || $usdValue <= 0.0) {
                    return [
                        'error' => 'balance_coin_conversion_failed',
                        'coin' => $coin,
                        'account_type' => $accountType,
                        'response' => $resp,
                    ];
                }

                $coinPriceUsd = $usdValue / $walletBalance;
                $available = $totalAvailableBalance / $coinPriceUsd;
                $source = 'totalAvailableBalance_converted';
            } else {
                // CONTRACT account: use coin-level availableToWithdraw (legacy field)
                $coinData = null;
                $coins = $account['coin'] ?? [];
                if (is_array($coins)) {
                    foreach ($coins as $c) {
                        if (is_array($c) && (($c['coin'] ?? '') === $coin)) {
                            $coinData = $c;
                            break;
                        }
                    }
                }

                if ($coinData === null) {
                    return [
                        'error' => 'balance_coin_not_found',
                        'coin' => $coin,
                        'account_type' => $accountType,
                        'response' => $resp,
                    ];
                }

                $available = (float)($coinData['availableToWithdraw'] ?? 0.0);
                $source = 'availableToWithdraw';
            }

            return [
                'coin' => $coin,
                'account_type' => $accountType,
                'available' => (float)$available,
                'available_usd' => (float)$totalAvailableBalance,
                'total_available_balance_usd' => (float)$totalAvailableBalance,
                'total_margin_balance_usd' => (float)$totalMarginBalance,
                'total_wallet_balance_usd' => (float)$totalWalletBalance,
                'total_equity_usd' => (float)$totalEquity,
                'source' => $source,
                'account_totals_missing' => $accountTotalsMissing,
                'raw_totalAvailableBalance' => $rawTotalAvailableBalance,
                'ret_code' => $this->extractRetCode($resp),
                'ret_msg' => $this->extractRetMsg($resp),
                'deprecated_fields' => ['availableToWithdraw'],
                // Legacy fields for compatibility
                'wallet_balance' => (float)$totalWalletBalance,
                'equity' => (float)$totalEquity,
                'raw_account' => $account,
            ];

        } catch (\Throwable $e) {
            return [
                'error' => 'wallet_balance_exception',
                'coin' => $coin,
                'account_type' => $accountType,
                'message' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Get open orders
     * 
     * Endpoint: orders.list
     * Signed: true
     */
    public function getOpenOrders(): array
    {
        if (!$this->client) {
            return [];
        }
        
        try {
            $resp = $this->client->request('orders.list', [
                'category' => $this->getCategory(),
                'settleCoin' => $this->getSettleCoin(),
                'openOnly' => 1,
            ], true);
            
            if (!isset($resp['success']) || $resp['success'] !== true) {
                // In LIVE mode we must surface auth/config failures instead of silently returning empty.
                if ($this->mode === 'live') {
                    throw new \RuntimeException('Bybit getOpenOrders failed: ' . $this->buildErrorSummary($resp));
                }
                return [];
            }
            
            return $resp['result']['list'] ?? [];
        } catch (\Throwable $e) {
            // In LIVE mode we prefer surfacing the reason via BotReconcileTrait (it catches exceptions).
            if ($this->mode === 'live') {
                throw $e;
            }
            return [];
        }
    }
    
    /**
     * Submit order
     * 
     * Endpoint: orders.create
     * Signed: true
     * 
     * P4: Applies qty normalization to qtyStep before submission.
     * 
     * @param array $order Order params (symbol, side, order_type, qty, etc.)
     * @return array ['success' => bool, 'order_id' => string|null, 'error' => string|null]
     */
    public function submitOrder(array $order): array
    {
        if (!$this->client) {
            return ['success' => false, 'error' => 'client_not_initialized'];
        }
        
        try {
            // Validate required order_type (NO fallback!)
            if (empty($order['order_type'])) {
                return ['success' => false, 'error' => 'missing_field:order_type'];
            }
            
            // P4: Normalize qty to qtyStep
            $qtyNormResult = $this->normalizeQty($order['symbol'], (float)$order['qty']);
            if (!$qtyNormResult['ok']) {
                return [
                    'success' => false,
                    'ok' => false,
                    'error' => 'qty_invalid_step_or_min',
                    'context' => $qtyNormResult,
                ];
            }
            $normalizedQty = $qtyNormResult['qty'];
            
            $params = [
                'category' => $this->getCategory(),
                'symbol' => $order['symbol'],
                'side' => $order['side'],
                'orderType' => ucfirst(strtolower($order['order_type'])),
                'qty' => (string)$normalizedQty,
                'timeInForce' => $order['time_in_force'] ?? 'GTC',
            ];
            
            // Add reduceOnly if specified (must be boolean)
            if (isset($order['reduce_only'])) {
                $params['reduceOnly'] = (bool)$order['reduce_only'];
            }
            
            // Add price for limit orders
            if (strtolower($order['order_type']) === 'limit' && isset($order['price'])) {
                $params['price'] = (string)$order['price'];
            }
            
            // Add TP/SL if present
            if (isset($order['take_profit']) && $order['take_profit'] > 0) {
                $params['takeProfit'] = (string)$order['take_profit'];
            }
            if (isset($order['stop_loss']) && $order['stop_loss'] > 0) {
                $params['stopLoss'] = (string)$order['stop_loss'];
            }
            
            // P6.6: Add orderLinkId if provided (user-defined order ID)
            if (!empty($order['order_link_id'])) {
                $params['orderLinkId'] = (string)$order['order_link_id'];
            }
            
            $resp = $this->client->request('orders.create', $params, true);
            
            if (!isset($resp['success']) || $resp['success'] !== true) {
                return [
                    'success' => false,
                    'error' => $resp['error'] ?? $resp['ret_msg'] ?? 'bybit_request_failed',
                    'response' => $resp,
                ];
            }
            
            $orderId = $resp['result']['orderId'] ?? '';
            
            if (empty($orderId)) {
                return [
                    'success' => false,
                    'error' => 'missing_order_id',
                    'response' => $resp,
                ];
            }
            
            return [
                'success' => true,
                'ok' => true,
                'order_id' => (string)$orderId,
                'filled' => true, // Assume market orders fill immediately
                'response' => $resp,
                'normalized_qty' => $normalizedQty,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'ok' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Cancel order
     * 
     * Endpoint: orders.cancel
     * Signed: true
     */
    public function cancelOrder(string $orderId, string $symbol): array
    {
        if (!$this->client) {
            return ['success' => false, 'error' => 'client_not_initialized'];
        }
        
        try {
            $resp = $this->client->request('orders.cancel', [
                'category' => $this->getCategory(),
                'symbol' => $symbol,
                'orderId' => $orderId,
            ], true);
            
            if (!isset($resp['success']) || $resp['success'] !== true) {
                return [
                    'success' => false,
                    'error' => $resp['error'] ?? $resp['ret_msg'] ?? 'bybit_request_failed',
                    'response' => $resp,
                ];
            }
            
            return [
                'success' => true,
                'ok' => true,
                'response' => $resp,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'ok' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Set leverage for symbol
     * 
     * Endpoint: /v5/position/set-leverage
     * Signed: true
     */
    public function setLeverage(string $symbol, int $leverage): array
    {
        if (!$this->client) {
            return ['success' => false, 'error' => 'client_not_initialized'];
        }

        // Bybit leverage caps vary by instrument and risk-limit tiers.
        // We must NEVER fail the whole intent just because requested leverage is above instrument max.
        // Strategy:
        // 1) Try to read instrument maxLeverage (from instruments-info) and clamp requested leverage.
        // 2) Send set-leverage request.
        // 3) If Bybit still rejects with maxLeverage in message, parse and retry with parsed max.

        $requested = (int)$leverage;
        if ($requested <= 0) {
            $requested = 1;
        }

        $effective = $requested;
        $meta = $this->getInstrumentMeta($symbol);
        $metaMax = 0.0;

        if (is_array($meta) && isset($meta['maxLeverage'])) {
            $metaMax = (float)$meta['maxLeverage'];
        }

        if ($metaMax > 0 && $effective > $metaMax) {
            $effective = (int)floor($metaMax);
            if ($effective < 1) {
                $effective = 1;
            }
        }

        try {
            $first = $this->client->request('/v5/position/set-leverage', [
                'category' => $this->getCategory(),
                'symbol' => $symbol,
                'buyLeverage' => (string)$effective,
                'sellLeverage' => (string)$effective,
            ], true);

            // Normalize retCode / retMsg across wrappers
            $retCode = (int)($first['ret_code'] ?? $first['retCode'] ?? -1);
            $retMsg  = (string)($first['ret_msg'] ?? $first['retMsg'] ?? ($first['error'] ?? ''));

            // Bybit: 110043 = leverage not modified (already set). This is NOT a failure.
            if (($first['success'] ?? false) !== true) {
                $isNotModified = ($retCode === 110043)
                    || (stripos($retMsg, 'not been modified') !== false)
                    || (stripos($retMsg, 'not modified') !== false);

                if ($isNotModified) {
                    return [
                        'success' => true,
                        'note' => 'leverage_not_modified',
                        'requested' => $requested,
                        'effective' => $effective,
                        'meta_max' => $metaMax > 0 ? $metaMax : null,
                        'ret_code' => $retCode,
                        'ret_msg' => $retMsg,
                        'response' => $first,
                    ];
                }

                // If Bybit says requested leverage > maxLeverage, retry with max leverage.
                $parsedMax = $this->parseMaxLeverageFromError($retMsg);
                if ($parsedMax !== null) {
                    $retryLev = (int)floor($parsedMax);
                    if ($retryLev < 1) {
                        $retryLev = 1;
                    }

                    // Avoid infinite retry / no-op
                    if ($retryLev !== $effective) {
                        $second = $this->client->request('/v5/position/set-leverage', [
                            'category' => $this->getCategory(),
                            'symbol' => $symbol,
                            'buyLeverage' => (string)$retryLev,
                            'sellLeverage' => (string)$retryLev,
                        ], true);

                        $retCode2 = (int)($second['ret_code'] ?? $second['retCode'] ?? -1);
                        $retMsg2  = (string)($second['ret_msg'] ?? $second['retMsg'] ?? ($second['error'] ?? ''));

                        if (($second['success'] ?? false) === true || $retCode2 === 110043) {
                            return [
                                'success' => true,
                                'note' => 'leverage_clamped_retry_ok',
                                'requested' => $requested,
                                'effective' => $retryLev,
                                'meta_max' => $metaMax > 0 ? $metaMax : null,
                                'parsed_max' => $parsedMax,
                                'ret_code' => $retCode2,
                                'ret_msg' => $retMsg2,
                                'response' => $second,
                                'first_error' => [
                                    'ret_code' => $retCode,
                                    'ret_msg' => $retMsg,
                                    'response' => $first,
                                ],
                            ];
                        }

                        return [
                            'success' => false,
                            'error' => $retMsg2 !== '' ? $retMsg2 : ($second['error'] ?? 'bybit_request_failed'),
                            'requested' => $requested,
                            'effective' => $retryLev,
                            'meta_max' => $metaMax > 0 ? $metaMax : null,
                            'parsed_max' => $parsedMax,
                            'ret_code' => $retCode2,
                            'ret_msg' => $retMsg2,
                            'response' => $second,
                            'first_error' => [
                                'ret_code' => $retCode,
                                'ret_msg' => $retMsg,
                                'response' => $first,
                            ],
                        ];
                    }
                }

                // Normal error (not max leverage)
                return [
                    'success' => false,
                    'error' => $retMsg !== '' ? $retMsg : ($first['error'] ?? 'bybit_request_failed'),
                    'requested' => $requested,
                    'effective' => $effective,
                    'meta_max' => $metaMax > 0 ? $metaMax : null,
                    'ret_code' => $retCode,
                    'ret_msg' => $retMsg,
                    'response' => $first,
                ];
            }

            // Success on first try
            $note = ($effective !== $requested) ? 'leverage_clamped_to_meta_max' : 'leverage_set_ok';

            return [
                'success' => true,
                'note' => $note,
                'requested' => $requested,
                'effective' => $effective,
                'meta_max' => $metaMax > 0 ? $metaMax : null,
                'ret_code' => $retCode,
                'ret_msg' => $retMsg,
                'response' => $first,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'requested' => $requested,
                'effective' => $effective,
                'meta_max' => $metaMax > 0 ? $metaMax : null,
            ];
        }
    }

    /**
     * Parse max leverage from Bybit error messages.
     *
     * Example message:
     * "cannot set leverage [1500] gt maxLeverage [500] by risk limit"
     *
     * IMPORTANT:
     * - Sometimes Bybit returns leverage values scaled by 100 in messages (e.g., 1500 => 15x).
     * - We detect this by checking if numbers are >= 100 and divisible by 100.
     *
     * @param string $msg Error message
     * @return float|null Max leverage (human scale) if detected
     */
    private function parseMaxLeverageFromError(string $msg): ?float
    {
        $m = [];
        if (!preg_match('/maxLeverage\s*\[(\d+(?:\.\d+)?)\]/i', $msg, $m)) {
            return null;
        }

        $raw = (float)$m[1];

        // If Bybit uses x100 scaling in message: 500 => 5.00, 1500 => 15.00
        if ($raw >= 100 && abs($raw - round($raw)) < 1e-9 && ((int)round($raw)) % 100 === 0) {
            return $raw / 100.0;
        }

        return $raw;
    }

    /**

     * Set trading stop (TP/SL/Trailing)
     * 
     * Endpoint: /v5/position/trading-stop
     * Signed: true
     * 
     * P4 FIX: Added side parameter for proper price normalization.
     * 
     * @param string $symbol Symbol
     * @param string $side Position side (long|short) - for P4 price normalization
     * @param array $options Options (stop_loss, take_profit, trailing_stop, active_price, etc.)
     * @return array Result
     */
    public function setTradingStop(string $symbol, string $side, array $options): array
    {
        if (!$this->client) {
            return ['success' => false, 'error' => 'client_not_initialized'];
        }
        
        try {
            // P0.2 FIX: Bybit V5 requires positionIdx, tpslMode for trading-stop
            // Build params with required fields
            $params = [
                'category' => $this->getCategory(),
                'symbol' => $symbol,
                // Required: positionIdx (0 = one-way mode)
                'positionIdx' => (int)($options['position_idx'] ?? $this->config['exchange']['position_idx'] ?? 0),
                // Required: tpslMode ("Full" = entire position)
                'tpslMode' => $options['tpsl_mode'] ?? $this->config['exchange']['tpsl_mode'] ?? 'Full',
            ];
            
            // P4: Stop Loss - normalize to tickSize
            if (isset($options['stop_loss']) && $options['stop_loss'] > 0) {
                $normalizedSL = $this->normalizePriceForStop($symbol, $side, (float)$options['stop_loss']);
                $params['stopLoss'] = (string)$normalizedSL;
            }
            
            // SL trigger type (default: IndexPrice for stability)
            if (isset($options['sl_trigger_by'])) {
                $params['slTriggerBy'] = $options['sl_trigger_by'];
            } elseif (isset($params['stopLoss'])) {
                // Only add trigger if SL is set
                $params['slTriggerBy'] = $this->config['exchange']['sl_trigger_by'] ?? 'IndexPrice';
            }
            
            // P4: Take Profit - normalize to tickSize
            // NOTE: normalizePriceForStop uses floor for 'long' and ceil for 'short'.
            // For TP, we want the OPPOSITE: LONG TP should ceil (don't miss profit),
            // SHORT TP should floor. So we pass the inverted side to get correct rounding.
            if (isset($options['take_profit']) && $options['take_profit'] > 0) {
                $invertedSideForTP = (strtolower($side) === 'long') ? 'short' : 'long';
                $normalizedTP = $this->normalizePriceForStop($symbol, $invertedSideForTP, (float)$options['take_profit']);
                $params['takeProfit'] = (string)$normalizedTP;
            }
            
            // P4: Trailing Stop - normalize to tickSize (ceil for safety margin)
            if (isset($options['trailing_stop']) && $options['trailing_stop'] > 0) {
                $meta = $this->getInstrumentMeta($symbol);
                if ($meta !== null) {
                    // Ceil trailing distance - larger distance is safer
                    $normalizedTrailing = $this->ceilToStep((float)$options['trailing_stop'], $meta['tickSize']);
                } else {
                    // No meta - use raw value with basic rounding
                    $normalizedTrailing = round((float)$options['trailing_stop'], 8);
                }
                $params['trailingStop'] = (string)$normalizedTrailing;
            }
            
            // P4: Active Price - normalize to tickSize
            // IMPORTANT: activePrice is an activation threshold for trailing.
            // For LONG: rounding DOWN is safer (activates earlier / can activate immediately for "now" trailing).
            // For SHORT: rounding UP is safer (activates earlier / can activate immediately for "now" trailing).
            // normalizePriceForStop already implements: long -> floor, short -> ceil.
            if (isset($options['active_price']) && $options['active_price'] > 0) {
                $normalizedActive = $this->normalizePriceForStop($symbol, $side, (float)$options['active_price']);
                $params['activePrice'] = (string)$normalizedActive;
            }
            
            $resp = $this->client->request('/v5/position/trading-stop', $params, true);
            
            if (!isset($resp['success']) || $resp['success'] !== true) {
                return [
                    'success' => false,
                    'error' => $resp['error'] ?? $resp['ret_msg'] ?? 'bybit_request_failed',
                    'response' => $resp,
                    'params' => $params, // Include params for debugging
                ];
            }
            
            return [
                'success' => true,
                'response' => $resp,
                'params' => $params, // Include params for verification
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Close position via reduce-only market order
     * 
     * @param string $symbol Symbol
     * @param string $side Position side (long|short)
     * @param float $qty Position quantity
     * @return array Result
     */
    public function closePosition(string $symbol, string $side, float $qty): array
    {
        // Close by submitting opposite order with reduceOnly
        $sideNorm = $this->normalizeLongShortSide($side);
        $closeSide = ($sideNorm === 'long') ? 'Sell' : 'Buy';
        
        // Note: closePosition() is an internal method that always uses market orders by design.
        // Unlike user-facing submitOrder() which requires explicit order_type from the risk block,
        // position closing must be immediate and cannot use limit orders.
        return $this->submitOrder([
            'symbol' => $symbol,
            'side' => $closeSide,
            'order_type' => 'market',
            'qty' => $qty,
            'reduce_only' => true,
        ]);
    }
    
    /**
     * Check if client is initialized
     */
    public function isInitialized(): bool
    {
        return $this->client !== null;
    }
}

/* RULES
- Gateway wraps Core\Gateway\Bybit for Trading Bot
- ALL API calls through $client->request() - no direct method calls
- Uses account_id from config for multi-account support
- setTradingStop MUST include positionIdx, tpslMode per Bybit V5 spec
- NO hardcoded credentials - KeyCenter is source of truth
*/
