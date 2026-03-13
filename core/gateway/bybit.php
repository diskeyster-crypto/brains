<?php

declare(strict_types=1);

namespace Core\Gateway;

use Core\System\System;
use Core\Logger\Logger;

/**
 * Bybit V5 API Gateway (Mainnet Only)
 * 
 * Features:
 * - X-BAPI-SIGN-TYPE: 2 header
 * - Canonical signature string
 * - Full diagnostic mode with raw HTTP
 * - Error classification (auth_failed, sign_failed, network_failed, etc.)
 * 
 * MAINNET ONLY - no testnet support for production stability.
 * 
 * Implements GatewayInterface for standard API contract.
 */
final class Bybit implements GatewayInterface
{
    private static ?self $instance = null;
    private static array $clients = [];
    
    /** @var string Mainnet base URL (testnet removed for stability) */
    private const BASE_URL = 'https://api.bybit.com';
    
    private string $apiKey = '';
    private string $apiSecret = '';
    private int $timeout = 30;
    private int $maxRetries = 3;
    private int $recvWindow = 5000;
    private string $clientName = 'default';

    private function __construct(string $clientName = 'default')
    {
        $this->clientName = $clientName;
        $this->loadConfig($clientName);
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self('default');
        }
        return self::$instance;
    }

    /**
     * Get client instance by name (supports multiple accounts)
     */
    public static function client(string $name = 'default'): self
    {
        if (!isset(self::$clients[$name])) {
            self::$clients[$name] = new self($name);
        }
        return self::$clients[$name];
    }

    /**
     * Load configuration from config/bybit.php and credentials from KeyCenter
     * 
     * KeyCenter is the SINGLE SOURCE OF TRUTH for API credentials.
     * config/bybit.php only contains non-sensitive settings (timeout, retries).
     */
    private function loadConfig(string $clientName = 'default'): void
    {
        // Load non-sensitive config from config/bybit.php
        $config = System::config()->load('bybit');
        
        // Validate required config keys exist
        $requiredKeys = ['timeout', 'max_retries'];
        $missing = [];
        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $config)) {
                $missing[] = $key;
            }
        }
        
        if (!empty($missing)) {
            throw new \RuntimeException(
                "Missing required Bybit config keys in config/bybit.php: " . implode(', ', $missing) . ". " .
                "All keys must be explicitly defined, no silent defaults allowed."
            );
        }
        
        // Load settings from config
        $this->timeout = $config['timeout'];
        $this->maxRetries = $config['max_retries'];
        $this->recvWindow = $config['recv_window'] ?? 5000;
        
        // Load credentials from KeyCenter (single source of truth for secrets)
        $keyCenter = \Core\KeyCenter\KeyCenter::instance();
        $credentials = $keyCenter->getBybitCredentials($clientName);
        
        if (!empty($credentials)) {
            $this->apiKey = $credentials['api_key'] ?? '';
            $this->apiSecret = $credentials['api_secret'] ?? '';
        } else {
            // Config fallback is DISABLED by default
            // KeyCenter is the single source of truth for credentials
            // Set 'allow_config_fallback' => true in config/bybit.php to enable legacy behavior
            $allowConfigFallback = $config['allow_config_fallback'] ?? false;
            
            if ($allowConfigFallback) {
                $this->apiKey = $config['api_key'] ?? '';
                $this->apiSecret = $config['api_secret'] ?? '';
            }
            // Otherwise credentials remain empty - private endpoints will fail with clear error
        }
    }

    /**
     * Call Bybit API endpoint
     * 
     * @return array Standard response format:
     *   - success: bool
     *   - ret_code: int
     *   - ret_msg: string
     *   - http_code: int
     *   - endpoint: string
     *   - raw_text: string (raw HTTP response body)
     *   - raw_json: array|null (decoded JSON)
     *   - result: mixed (data from API)
     *   - error_type: string (auth_failed|sign_failed|network_failed|json_parse_failed|http_error|api_error|none)
     *   - request_meta: array (debug info about the request)
     */
    public static function call(string $endpoint, array $params = [], string $client = 'default'): array
    {
        return self::client($client)->request($endpoint, $params);
    }

    /**
     * Make API request
     * 
     * @param string $endpoint Endpoint in dot notation (market.tickers) or path (/v5/market/tickers)
     * @param array $params Request parameters
     * @param bool $signed Whether request requires authentication
     * @return array Standard response format
     */
    public function request(string $endpoint, array $params = [], bool $signed = false): array
    {
        // Support both dot notation (positions.list) and path notation (/v5/position/list)
        if (strpos($endpoint, '/') !== false) {
            // Direct path like /v5/position/list - resolve method from path
            $path = $endpoint;
            $method = $this->resolveHttpMethodFromPath($path);
        } else {
            // Dot notation like positions.list
            $parts = explode('.', $endpoint);
            $methodPart = $parts[0] ?? '';
            $action = $parts[1] ?? '';
            $path = $this->resolvePath($methodPart, $action);
            $method = $this->resolveHttpMethod($action);
        }

        return $this->execute($method, $path, $params, $endpoint, $signed);
    }

    /**
     * Resolve HTTP method from path (for direct path requests)
     */
    private function resolveHttpMethodFromPath(string $path): string
    {
        $p = strtolower($path);

        // ======================================================
        // CRITICAL OVERRIDES (no heuristics)
        // ======================================================
        // Some endpoints include substrings that break naive keyword matching.
        // Example: /v5/position/closed-pnl contains "close" but MUST be GET.
        $forceGet = [
            '/v5/position/closed-pnl',
        ];
        foreach ($forceGet as $needle) {
            if (strpos($p, $needle) !== false) {
                return 'GET';
            }
        }

        // Endpoints that MUST be POST even though they don't contain typical keywords.
        $forcePost = [
            '/v5/position/trading-stop',
            '/v5/position/set-leverage',
        ];
        foreach ($forcePost as $needle) {
            if (strpos($p, $needle) !== false) {
                return 'POST';
            }
        }

        // ======================================================
        // HEURISTICS (safe defaults)
        // ======================================================
        // POST endpoints typically contain these keywords as path segments.
        // NOTE: Do NOT include "close" here; it breaks "closed-pnl" (GET).
        $postKeywords = ['create', 'cancel', 'amend', 'set'];
        foreach ($postKeywords as $keyword) {
            if (strpos($p, '/' . $keyword) !== false) {
                return 'POST';
            }
        }

        return 'GET';
    }


    private function resolvePath(string $method, string $action): string
    {
        $paths = [
            'positions.list' => '/v5/position/list',
            'orders.create' => '/v5/order/create',
            'orders.cancel' => '/v5/order/cancel',
            'orders.list' => '/v5/order/realtime',
            'account.wallet' => '/v5/account/wallet-balance',
            'market.tickers' => '/v5/market/tickers',
            'market.kline' => '/v5/market/kline',
            'market.time' => '/v5/market/time',
        ];

        $key = "{$method}.{$action}";
        return $paths[$key] ?? "/v5/{$method}/{$action}";
    }

    private function resolveHttpMethod(string $action): string
    {
        $postActions = ['create', 'cancel', 'amend', 'set'];
        return in_array($action, $postActions) ? 'POST' : 'GET';
    }

    /**
     * Build query string (used for both URL and signature)
     */
    private function buildQueryString(array $params): string
    {
        if (empty($params)) {
            return '';
        }
        // Sort params alphabetically for consistent signature
        ksort($params);
        return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Build JSON body for POST requests (no spaces, for signature)
     */
    private function buildJsonBody(array $params): string
    {
        if (empty($params)) {
            return '';
        }
        return json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function execute(string $method, string $path, array $params, string $endpoint, bool $signed = true): array
    {
        $timestamp = (string)(int)(microtime(true) * 1000);
        $recvWindow = (string)$this->recvWindow;

        // Build canonical param string for signature
        $paramString = ($method === 'POST') 
            ? $this->buildJsonBody($params)
            : $this->buildQueryString($params);

        $url = self::BASE_URL . $path;
        
        // Build headers (signed or unsigned)
        $headers = $signed ? $this->buildHeaders($timestamp, $recvWindow, $paramString) : $this->buildUnsignedHeaders();
        
        // Track expected vs actual headers for diagnostic
        $headersExpected = [];
        $headersSent = array_keys($headers);
        
        if ($signed) {
            $headersExpected = ['X-BAPI-API-KEY', 'X-BAPI-SIGN', 'X-BAPI-TIMESTAMP', 'X-BAPI-RECV-WINDOW'];
            
            // GATEWAY BUG DETECTION: if signed=true but no API key header, this is a bug
            if (!isset($headers['X-BAPI-API-KEY']) || empty($headers['X-BAPI-API-KEY'])) {
                return [
                    'success' => false,
                    'ret_code' => -2,
                    'ret_msg' => 'Gateway bug: signed request but no API key configured',
                    'http_code' => 0,
                    'endpoint' => $endpoint,
                    'raw_text' => '',
                    'raw_json' => null,
                    'result' => null,
                    'error_type' => 'gateway_bug',
                    'request_meta' => [
                        'url' => $url,
                        'method' => $method,
                        'signed' => $signed,
                        'headers_expected' => $headersExpected,
                        'headers_sent' => $headersSent,
                        'api_key_configured' => !empty($this->apiKey),
                    ],
                ];
            }
        }

        $attempt = 0;
        $lastError = null;
        $lastHttpCode = 0;
        $lastRawText = '';
        $lastCurlInfo = [];
        $lastCurlError = '';
        $lastErrorType = 'none';

        while ($attempt < $this->maxRetries) {
            try {
                $response = $this->doRequest($method, $url, $params, $paramString, $headers);
                
                // Add headers diagnostic info to request_meta
                if (isset($response['request_meta'])) {
                    $response['request_meta']['headers_expected'] = $headersExpected;
                    $response['request_meta']['signed'] = $signed;
                }
                
                Logger::info("Bybit API call: {$method} {$path}", [
                    'params' => $params,
                    'response_code' => $response['ret_code'],
                    'error_type' => $response['error_type'],
                ]);

                return $response;

            } catch (\Throwable $e) {
                $lastError = $e;
                $attempt++;
                
                // Extract metadata from exception context
                $context = json_decode($e->getMessage(), true);
                if (is_array($context)) {
                    $lastHttpCode = $context['http_code'] ?? 0;
                    $lastRawText = $context['raw_text'] ?? '';
                    $lastCurlError = $context['curl_error'] ?? '';
                    $lastCurlInfo = $context['curl_info'] ?? [];
                    $lastErrorType = $context['error_type'] ?? 'unknown';
                }
                
                Logger::warning("Bybit API retry {$attempt}/{$this->maxRetries}: {$path}", [
                    'error' => $e->getMessage(),
                    'http_code' => $lastHttpCode,
                    'error_type' => $lastErrorType,
                ]);

                if ($attempt < $this->maxRetries) {
                    usleep(100000 * $attempt);
                }
            }
        }

        Logger::error("Bybit API failed after {$this->maxRetries} retries: {$path}", [
            'error' => $lastError?->getMessage(),
            'error_type' => $lastErrorType,
        ]);

        return [
            'success' => false,
            'ret_code' => -1,
            'ret_msg' => $lastCurlError ?: ($lastError?->getMessage() ?? 'Unknown error'),
            'http_code' => $lastHttpCode,
            'endpoint' => $endpoint,
            'raw_text' => $lastRawText,
            'raw_json' => null,
            'result' => null,
            'error_type' => $lastErrorType ?: 'network_failed',
            'request_meta' => [
                'url' => $url,
                'method' => $method,
                'curl_error' => $lastCurlError,
                'curl_info' => $lastCurlInfo,
                'retries' => $attempt,
                'signed' => $signed,
                'headers_sent' => $headersSent,
                'headers_expected' => $headersExpected,
            ],
        ];
    }

    private function buildHeaders(string $timestamp, string $recvWindow, string $paramString): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => 'tredercopis-core/1.0',
            'X-BAPI-TIMESTAMP' => $timestamp,
            'X-BAPI-RECV-WINDOW' => $recvWindow,
        ];

        // Only add auth headers if credentials are set
        if (!empty($this->apiKey)) {
            $headers['X-BAPI-API-KEY'] = $this->apiKey;
            $headers['X-BAPI-SIGN-TYPE'] = '2';
            
            if (!empty($this->apiSecret)) {
                $signature = $this->generateSignature($timestamp, $recvWindow, $paramString);
                $headers['X-BAPI-SIGN'] = $signature;
            }
        }

        return $headers;
    }

    /**
     * Build headers for unsigned (public) requests
     */
    private function buildUnsignedHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => 'tredercopis-core/1.0',
        ];
    }

    /**
     * Generate HMAC-SHA256 signature for Bybit V5 API
     * 
     * Signature format: timestamp + api_key + recv_window + (queryString or raw JSON body)
     */
    private function generateSignature(string $timestamp, string $recvWindow, string $paramString): string
    {
        $signPayload = $timestamp . $this->apiKey . $recvWindow . $paramString;
        return hash_hmac('sha256', $signPayload, $this->apiSecret);
    }

    /**
     * Classify error type from HTTP response
     * 
     * @return string One of: auth_failed, sign_failed, network_failed, json_parse_failed, http_error, api_error, none
     */
    private function classifyError(int $httpCode, ?array $rawJson, string $rawText, string $curlError): string
    {
        // Network/curl errors
        if (!empty($curlError)) {
            if (str_contains($curlError, 'resolve') || str_contains($curlError, 'connect')) {
                return 'network_failed';
            }
            return 'network_failed';
        }
        
        // HTTP 401 - authentication failed
        if ($httpCode === 401) {
            return 'auth_failed';
        }
        
        // HTTP 403 - forbidden (could be IP restriction or invalid signature)
        if ($httpCode === 403) {
            return 'sign_failed';
        }
        
        // Bybit-specific error codes
        if ($rawJson !== null) {
            $retCode = $rawJson['retCode'] ?? 0;
            
            // Signature errors: 10003, 10004, 10005
            if (in_array($retCode, [10003, 10004, 10005])) {
                return 'sign_failed';
            }
            
            // Auth errors: 10001, 10002, 33004
            if (in_array($retCode, [10001, 10002, 33004])) {
                return 'auth_failed';
            }
            
            // Rate limit: 10006
            if ($retCode === 10006) {
                return 'rate_limited';
            }
            
            // Any other non-zero code is API error
            if ($retCode !== 0) {
                return 'api_error';
            }
            
            return 'none';
        }
        
        // HTTP 4xx errors
        if ($httpCode >= 400 && $httpCode < 500) {
            return 'http_error';
        }
        
        // HTTP 5xx errors
        if ($httpCode >= 500) {
            return 'server_error';
        }
        
        // JSON parsing failed
        if (empty($rawJson) && !empty($rawText)) {
            return 'json_parse_failed';
        }
        
        return 'unknown';
    }

    private function doRequest(string $method, string $url, array $params, string $paramString, array $headers): array
    {
        $ch = curl_init();

        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = "{$key}: {$value}";
        }

        // Capture response headers
        $responseHeaders = [];
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$responseHeaders) {
                $len = strlen($header);
                $header = trim($header);
                if (!empty($header) && strpos($header, ':') !== false) {
                    [$name, $value] = explode(':', $header, 2);
                    $responseHeaders[trim($name)] = trim($value);
                }
                return $len;
            },
        ]);

        $finalUrl = $url;
        $bodyData = null;

        if ($method === 'GET') {
            $queryString = $this->buildQueryString($params);
            if ($queryString) {
                $finalUrl = $url . '?' . $queryString;
            }
            curl_setopt($ch, CURLOPT_URL, $finalUrl);
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        } else {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            $bodyData = $paramString;
            curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyData);
        }

        $rawText = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        $curlInfo = curl_getinfo($ch);
        curl_close($ch);

        // Ensure raw_text is never empty for debugging
        if ($rawText === false || $rawText === '') {
            $rawText = '(empty response body)';
        }
        // Truncate very long responses in raw_text (keep full in raw_json)
        $rawTextForLog = strlen($rawText) > 1000 ? substr($rawText, 0, 1000) . '...(truncated)' : $rawText;

        // Build request metadata for debugging
        $requestMeta = [
            'url' => $finalUrl,
            'method' => $method,
            'headers_sent' => $headers,
            'body_sent' => $bodyData,
            'timestamp' => $headers['X-BAPI-TIMESTAMP'] ?? null,
            'curl_errno' => $curlErrno,
            'curl_error' => $curlError,
            'total_time' => $curlInfo['total_time'] ?? 0,
            'connect_time' => $curlInfo['connect_time'] ?? 0,
            'response_headers' => $responseHeaders,
        ];

        // Debug logging for every request
        Logger::debug("Bybit HTTP request completed", [
            'endpoint' => $finalUrl,
            'method' => $method,
            'http_code' => $httpCode,
            'curl_errno' => $curlErrno,
            'curl_error' => $curlError ?: null,
            'response_length' => strlen($rawText),
            'request_time' => $curlInfo['total_time'] ?? 0,
        ]);

        // Handle curl errors
        if ($curlErrno !== 0) {
            $errorType = $this->classifyError($httpCode, null, $rawText ?: '', $curlError);
            Logger::error("Bybit curl error", [
                'endpoint' => $finalUrl,
                'curl_errno' => $curlErrno,
                'curl_error' => $curlError,
                'raw_text' => $rawTextForLog,
            ]);
            throw new \RuntimeException(json_encode([
                'error' => 'curl_error',
                'curl_error' => $curlError,
                'curl_errno' => $curlErrno,
                'http_code' => $httpCode,
                'raw_text' => $rawText,
                'curl_info' => $curlInfo,
                'error_type' => $errorType,
            ]));
        }

        // Try to parse JSON response
        $rawJson = null;
        if (!empty($rawText)) {
            $rawJson = json_decode($rawText, true);
        }

        // Classify error type
        $errorType = $this->classifyError($httpCode, $rawJson, $rawText ?: '', $curlError);

        // Handle HTTP errors (but still try to parse response)
        if ($httpCode >= 400) {
            return [
                'success' => false,
                'ret_code' => $rawJson['retCode'] ?? -1,
                'ret_msg' => $rawJson['retMsg'] ?? "HTTP {$httpCode}",
                'http_code' => $httpCode,
                'endpoint' => $finalUrl,
                'raw_text' => $rawText ?: '(empty body)',
                'raw_json' => $rawJson,
                'result' => null,
                'error_type' => $errorType,
                'request_meta' => $requestMeta,
            ];
        }

        // Handle empty response
        if ($rawText === false || $rawText === '') {
            return [
                'success' => false,
                'ret_code' => -1,
                'ret_msg' => 'Empty response body',
                'http_code' => $httpCode,
                'endpoint' => $finalUrl,
                'raw_text' => '(empty)',
                'raw_json' => null,
                'result' => null,
                'error_type' => 'json_parse_failed',
                'request_meta' => $requestMeta,
            ];
        }

        // JSON parsing check
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'ret_code' => -1,
                'ret_msg' => 'Invalid JSON: ' . json_last_error_msg(),
                'http_code' => $httpCode,
                'endpoint' => $finalUrl,
                'raw_text' => $rawText,
                'raw_json' => null,
                'result' => null,
                'error_type' => 'json_parse_failed',
                'request_meta' => $requestMeta,
            ];
        }

        // Return standardized response format
        $isSuccess = ($rawJson['retCode'] ?? -1) === 0;
        return [
            'success' => $isSuccess,
            'ret_code' => $rawJson['retCode'] ?? -1,
            'ret_msg' => $rawJson['retMsg'] ?? 'Unknown',
            'http_code' => $httpCode,
            'endpoint' => $finalUrl,
            'raw_text' => $rawText,
            'raw_json' => $rawJson,
            'result' => $rawJson['result'] ?? null,
            'error_type' => $isSuccess ? 'none' : $errorType,
            'request_meta' => $requestMeta,
        ];
    }

    public function setCredentials(string $apiKey, string $apiSecret): void
    {
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function getBaseUrl(): string
    {
        return self::BASE_URL;
    }

    /**
     * Run gateway diagnostic with full raw HTTP details
     * 
     * Shows:
     * - KeyCenter status (credentials source)
     * - Public API test
     * - Private API test
     * - Error classification
     * 
     * @param string $account Account name (default: 'default')
     * @return array Diagnostic results including raw request/response data
     */
    public static function diagnostic(string $account = 'default'): array
    {
        $instance = self::client($account);
        
        // Check KeyCenter status
        $keyCenter = \Core\KeyCenter\KeyCenter::instance();
        $hasKeyCenterCreds = $keyCenter->hasCredentials('bybit', $account);
        $keyCenterInfo = $keyCenter->diagnostic();
        
        $result = [
            'gateway' => 'initialized',
            'account' => $account,
            'base_url' => self::BASE_URL,
            'credentials_source' => $hasKeyCenterCreds ? 'keycenter' : 'config',
            'has_credentials' => !empty($instance->apiKey),
            'api_key_prefix' => !empty($instance->apiKey) ? substr($instance->apiKey, 0, 8) . '...' : null,
            'timestamp' => date('c'),
            'php_version' => PHP_VERSION,
            'curl_version' => curl_version()['version'] ?? 'unknown',
            'keycenter' => [
                'has_bybit_credentials' => $hasKeyCenterCreds,
                'storage_exists' => $keyCenterInfo['storage_exists'] ?? false,
                'services' => $keyCenterInfo['services'] ?? [],
            ],
            'tests' => [],
        ];

        // Test 1: Server time (no auth required)
        $result['tests']['server_time'] = self::testServerTime($instance);

        // Test 2: Public API - Market tickers (no auth required)
        $result['tests']['public_api'] = self::testPublicApi($instance);

        // Test 3: Private API - Positions list (requires auth)
        if (!empty($instance->apiKey)) {
            $result['tests']['positions'] = self::testPositions($instance);
        } else {
            $result['tests']['positions'] = [
                'skipped' => true,
                'reason' => 'No API credentials. Set via: php index.php keys set bybit <key> <secret>',
            ];
        }

        // Test 4: Private API - Account wallet (requires auth)
        if (!empty($instance->apiKey)) {
            $result['tests']['wallet'] = self::testWallet($instance);
        } else {
            $result['tests']['wallet'] = [
                'skipped' => true,
                'reason' => 'No API credentials. Set via: php index.php keys set bybit <key> <secret>',
            ];
        }

        // Summary with error classification
        $result['summary'] = [
            'server_time_ok' => $result['tests']['server_time']['success'] ?? false,
            'public_api_ok' => $result['tests']['public_api']['success'] ?? false,
            'positions_ok' => $result['tests']['positions']['success'] ?? ($result['tests']['positions']['skipped'] ?? false),
            'wallet_ok' => $result['tests']['wallet']['success'] ?? ($result['tests']['wallet']['skipped'] ?? false),
            'error_types' => [
                'server_time' => $result['tests']['server_time']['error_type'] ?? 'none',
                'public_api' => $result['tests']['public_api']['error_type'] ?? 'none',
                'positions' => $result['tests']['positions']['error_type'] ?? 'none',
                'wallet' => $result['tests']['wallet']['error_type'] ?? 'none',
            ],
        ];

        Logger::info('Bybit diagnostic completed', ['account' => $account, 'summary' => $result['summary']]);
        return $result;
    }

    private static function testServerTime(self $instance): array
    {
        try {
            $response = $instance->request('market.time', []);
            return [
                'success' => $response['success'],
                'http_code' => $response['http_code'],
                'ret_code' => $response['ret_code'],
                'ret_msg' => $response['ret_msg'],
                'error_type' => $response['error_type'],
                'server_time' => $response['result']['timeSecond'] ?? null,
                'raw_text_preview' => substr($response['raw_text'] ?? '', 0, 200),
                'request_meta' => $response['request_meta'] ?? null,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'error_type' => 'exception',
            ];
        }
    }

    private static function testPublicApi(self $instance): array
    {
        try {
            // Public test: market.tickers with category=linear and symbol=BTCUSDT
            $response = $instance->request('market.tickers', ['category' => 'linear', 'symbol' => 'BTCUSDT'], false);
            $result = [
                'success' => $response['success'],
                'http_code' => $response['http_code'],
                'ret_code' => $response['ret_code'],
                'ret_msg' => $response['ret_msg'],
                'error_type' => $response['error_type'],
                'raw_text_preview' => substr($response['raw_text'] ?? '', 0, 300),
                'raw_text_length' => strlen($response['raw_text'] ?? ''),
                'request_meta' => $response['request_meta'] ?? null,
                'headers_sent' => $response['request_meta']['headers_sent'] ?? [],
            ];
            
            if (isset($response['result']['list'][0]['lastPrice'])) {
                $result['btc_price'] = $response['result']['list'][0]['lastPrice'];
            }
            
            return $result;
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'error_type' => 'exception',
            ];
        }
    }

    private static function testPrivateApi(self $instance): array
    {
        try {
            // Private test: requires auth - use signed=true
            $response = $instance->request('account.wallet', ['accountType' => 'UNIFIED'], true);
            return self::buildTestResult($response);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'error_type' => 'exception',
            ];
        }
    }

    /**
     * Test positions list endpoint (private, requires auth)
     */
    private static function testPositions(self $instance): array
    {
        try {
            // Private test: requires auth - use signed=true
            $response = $instance->request('positions.list', ['category' => 'linear'], true);
            return self::buildTestResult($response);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'error_type' => 'exception',
            ];
        }
    }

    /**
     * Test wallet balance endpoint (private, requires auth)
     */
    private static function testWallet(self $instance): array
    {
        try {
            // Private test: requires auth - use signed=true
            $response = $instance->request('account.wallet', ['accountType' => 'UNIFIED'], true);
            return self::buildTestResult($response);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'error_type' => 'exception',
            ];
        }
    }

    /**
     * Build standardized test result from response
     */
    private static function buildTestResult(array $response): array
    {
        $result = [
            'success' => $response['success'],
            'http_code' => $response['http_code'],
            'ret_code' => $response['ret_code'],
            'ret_msg' => $response['ret_msg'],
            'error_type' => $response['error_type'],
            'raw_text_preview' => substr($response['raw_text'] ?? '', 0, 500),
            'raw_text_length' => strlen($response['raw_text'] ?? ''),
            'request_meta' => $response['request_meta'] ?? null,
            'response_headers' => $response['request_meta']['response_headers'] ?? [],
            // Headers diagnostic info
            'headers_sent' => $response['request_meta']['headers_sent'] ?? [],
            'headers_expected' => $response['request_meta']['headers_expected'] ?? [],
        ];
        
        // Add probable cause for errors
        if (!$response['success']) {
            $result['probable_cause'] = self::getProbableCauseStatic($response['error_type'], $response['http_code'] ?? 0, $response['ret_code'] ?? 0);
        }
        
        return $result;
    }

    /**
     * Get probable cause description for error type
     * 
     * @return string Human-readable explanation of the error
     */
    public static function getProbableCauseStatic(string $errorType, int $httpCode = 0, int $retCode = 0): string
    {
        $causes = [
            'auth_failed' => 'Invalid API key or API key does not have required permissions. Verify your API key is correct and has trading permissions.',
            'sign_failed' => 'Signature verification failed. Check: 1) api_secret is correct, 2) system clock is synchronized, 3) recv_window is appropriate.',
            'network_failed' => 'Network connectivity issue. Check: 1) DNS resolution, 2) firewall rules, 3) internet connection, 4) Bybit API availability.',
            'json_parse_failed' => 'Server returned non-JSON response. This may indicate: 1) server maintenance, 2) rate limiting, 3) proxy/CDN issues.',
            'api_error' => "Bybit API rejected the request (retCode: {$retCode}). Check API documentation for error code meaning.",
            'rate_limited' => 'API rate limit exceeded. Wait and retry with exponential backoff.',
            'http_error' => "HTTP error {$httpCode}. Check endpoint URL and request parameters.",
            'server_error' => 'Bybit server error (5xx). The exchange may be experiencing issues. Try again later.',
            'invalid_response' => 'Unexpected response format. Check API version compatibility.',
            'gateway_bug' => 'Gateway configuration bug: signed request was made but authentication headers are missing. Check API key configuration.',
            'exception' => 'An unexpected error occurred during the request.',
            'none' => 'No error detected.',
        ];

        return $causes[$errorType] ?? "Unknown error type: {$errorType}";
    }

    /**
     * Get probable cause for error response (GatewayInterface implementation)
     * 
     * @param array $response Response from request()
     * @return string Human-readable error description
     */
    public function getProbableCause(array $response): string
    {
        $errorType = $response['error_type'] ?? 'unknown';
        $httpCode = $response['http_code'] ?? 0;
        $retCode = $response['ret_code'] ?? 0;
        
        return self::getProbableCauseStatic($errorType, $httpCode, $retCode);
    }

    /**
     * Quick connectivity check
     */
    public static function ping(): bool
    {
        try {
            $response = self::instance()->request('market.time', []);
            return $response['success'] && $response['http_code'] === 200;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

/* RULES
- Purpose: Bybit V5 API Gateway with HMAC-SHA256 signatures
- Config sources: config/bybit.php for api_base
- Paths: None (external HTTP only)
- Logs: Debug logging of requests/responses
- Prohibitions:
  - Credentials ONLY from KeyCenter (NO config fallback by default)
  - NO testnet support (mainnet only)
  - NO hardcoded API keys
*/
