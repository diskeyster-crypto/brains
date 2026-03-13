<?php
/**
 * Gateway Interface - Standard contract for all API gateways
 * 
 * All gateways (Bybit, Binance, Telegram, Discord, SMTP, etc.)
 * MUST implement this interface to ensure consistent behavior.
 * 
 * Gateways are responsible for resolving HTTP method internally.
 * External code should NEVER care about HTTP method.
 * 
 * @package Core\Gateway
 */

namespace Core\Gateway;

/**
 * Interface GatewayInterface
 * 
 * Standard contract for API gateway implementations.
 */
interface GatewayInterface
{
    /**
     * Make an API request
     * 
     * @param string $endpoint API endpoint alias or path
     *                        Examples:
     *                        - 'positions.list'
     *                        - 'market.tickers'
     *                        - '/v5/market/time'
     * 
     * @param array $params Request parameters
     * @param bool $signed Whether request requires authentication
     * 
     * @return array Unified response structure:
     *   - success: bool
     *   - http_code: int
     *   - ret_code: int|null
     *   - ret_msg: string|null
     *   - endpoint: string
     *   - result: array|null
     *   - raw_text: string
     *   - raw_json: array|null
     *   - error_type: string
     *   - request_meta: array
     */
    public function request(string $endpoint, array $params = [], bool $signed = false): array;
    
    /**
     * Get probable cause for an error response
     * 
     * @param array $response The response from request()
     * @return string Human-readable probable cause
     */
    public function getProbableCause(array $response): string;
}

/* RULES
- Purpose: Define standard contract for all API gateways
- HTTP method is INTERNAL responsibility of gateway
- External code NEVER passes HTTP method
- All gateways MUST implement this interface
- Response structure MUST be unified across all gateways
*/
