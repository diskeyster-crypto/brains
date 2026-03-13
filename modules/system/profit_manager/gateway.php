<?php
declare(strict_types=1);

namespace Modules\System\ProfitManager;

/**
 * Profit Manager Gateway
 * 
 * Exchange gateway wrapper for Profit Manager.
 * Uses Core\Gateway\Bybit::client()->request() for all API calls.
 * 
 * Only uses:
 * - getPositions() - fetch open positions
 * - setTradingStop() - set/update SL/trailing
 * - getInstrumentMeta() - get tick size
 * 
 * Does NOT:
 * - Create orders
 * - Set leverage
 * - Close positions
 */
class ProfitManagerGateway
{
    private array $config;
    private ?object $client = null;
    
    /** @var array Instrument meta cache */
    private array $instrumentMetaCache = [];
    
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->init();
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
        
        // Preflight credentials check (LIVE mode only)
        if ($mode === 'live') {
            $keyCenter = \Core\KeyCenter\KeyCenter::instance();
            
            if (!$keyCenter->hasCredentials('bybit', $accountId)) {
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
        return $this->config['exchange']['category'] ?? 'linear';
    }
    
    /**
     * Get settle coin from config
     */
    private function getSettleCoin(): string
    {
        return $this->config['exchange']['settle_coin'] ?? 'USDT';
    }
    
    // =========================================================================
    // Positions
    // =========================================================================
    
    /**
     * Get open positions
     * 
     * @return array Result with ok, positions[], error
     */
    public function getPositions(): array
    {
        if (!$this->client) {
            return [
                'ok' => false,
                'error' => 'client_not_initialized',
                'positions' => [],
            ];
        }
        
        try {
            $resp = $this->client->request('/v5/position/list', [
                'category' => $this->getCategory(),
                'settleCoin' => $this->getSettleCoin(),
            ], true);
            
            if (!isset($resp['success']) || $resp['success'] !== true) {
                return [
                    'ok' => false,
                    'error' => $resp['retMsg'] ?? 'unknown_error',
                    'positions' => [],
                ];
            }
            
            $positions = $resp['result']['list'] ?? [];
            
            // Filter to only positions with size > 0
            $openPositions = [];
            foreach ($positions as $pos) {
                $size = (float) ($pos['size'] ?? 0);
                if ($size > 0) {
                    $openPositions[] = $pos;
                }
            }
            
            return [
                'ok' => true,
                'positions' => $openPositions,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
                'positions' => [],
            ];
        }
    }
    
    // =========================================================================
    // Trading Stop
    // =========================================================================
    
    /**
     * Set trading stop (SL / trailing)
     * 
     * @param string $symbol Symbol
     * @param string $side long|short
     * @param array $options Options (stopLoss, trailingStop, activePrice, slTriggerBy, positionIdx)
     * @return array Result with ok, error, response
     */
    public function setTradingStop(string $symbol, string $side, array $options): array
    {
        if (!$this->client) {
            return [
                'ok' => false,
                'error' => 'client_not_initialized',
            ];
        }
        
        $mode = $this->config['module']['mode'] ?? 'dry';
        
        // Dry mode: return success without calling API
        if ($mode !== 'live') {
            return [
                'ok' => true,
                'mode' => 'dry',
                'response' => null,
            ];
        }
        
        try {
            $params = [
                'category' => $this->getCategory(),
                'symbol' => $symbol,
                'positionIdx' => $options['positionIdx'] ?? $this->config['exchange']['position_idx'] ?? 0,
            ];
            
            // Add stop loss
            if (isset($options['stopLoss'])) {
                $params['stopLoss'] = (string) $options['stopLoss'];
                $params['slTriggerBy'] = $options['slTriggerBy'] ?? $this->config['exchange']['sl_trigger_by'] ?? 'IndexPrice';
            }
            
            // Add trailing stop
            if (isset($options['trailingStop'])) {
                $params['trailingStop'] = (string) $options['trailingStop'];
            }
            
            // Add active price
            if (isset($options['activePrice'])) {
                $params['activePrice'] = (string) $options['activePrice'];
            }
            
            $resp = $this->client->request('/v5/position/trading-stop', $params, true, 'POST');
            
            if (!isset($resp['success']) || $resp['success'] !== true) {
                return [
                    'ok' => false,
                    'error' => $resp['retMsg'] ?? 'unknown_error',
                    'response' => $resp,
                ];
            }
            
            return [
                'ok' => true,
                'response' => $resp,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    // =========================================================================
    // Instrument Meta
    // =========================================================================
    
    /**
     * Get instrument meta (tickSize, qtyStep, minOrderQty)
     * 
     * @param string $symbol Symbol
     * @return array|null Meta or null on error
     */
    public function getInstrumentMeta(string $symbol): ?array
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
                'tickSize' => (float) ($instrument['priceFilter']['tickSize'] ?? 0.0001),
                'qtyStep' => (float) ($instrument['lotSizeFilter']['qtyStep'] ?? 0.001),
                'minOrderQty' => (float) ($instrument['lotSizeFilter']['minOrderQty'] ?? 0.001),
                'minNotionalValue' => (float) ($instrument['lotSizeFilter']['minNotionalValue'] ?? 0),
            ];
            
            // Cache for future use
            $this->instrumentMetaCache[$symbol] = $meta;
            
            return $meta;
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    /**
     * Get current price for symbol
     * 
     * @param string $symbol Symbol
     * @return float|null Price or null on error
     */
    public function getCurrentPrice(string $symbol): ?float
    {
        if (!$this->client) {
            return null;
        }
        
        try {
            $resp = $this->client->request('/v5/market/tickers', [
                'category' => $this->getCategory(),
                'symbol' => $symbol,
            ], false);
            
            if (!isset($resp['success']) || $resp['success'] !== true) {
                return null;
            }
            
            $ticker = $resp['result']['list'][0] ?? null;
            if ($ticker === null) {
                return null;
            }
            
            return (float) ($ticker['lastPrice'] ?? 0);
        } catch (\Throwable $e) {
            return null;
        }
    }
}

/* RULES
- Uses Core\Gateway\Bybit::client()->request() for all calls
- Only reads positions and sets trading stops
- Does NOT create orders, set leverage, or close positions
- Caches instrument meta to avoid repeated API calls
- Dry mode returns success without calling API
*/
