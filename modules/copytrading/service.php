<?php
declare(strict_types=1);

/**
 * Copytrading Parser — Service
 *
 * Fetches trader positions and trade history from Bybit copytrading.
 * Primary method: Bybit public API
 * Fallback: HTML scraping (if API fails)
 *
 * INPUT:
 * - Config: traders[] (array of leaderMark IDs)
 *
 * OUTPUT:
 * - storage/activ.json — Active positions
 * - storage/history.json — Trade history
 * - storage/last_run.json — Last run status
 * - storage/copytrading.log — Logs
 *
 * @package Modules\Copytrading
 */
final class CopytradingService
{
    /** @var array<string,mixed> */
    private array $cfg = [];

    private string $moduleDir;

    /** @var array<int,string> */
    private array $errors = [];

    /** @var array<int,string> */
    private array $logLines = [];

    public function __construct()
    {
        $this->moduleDir = __DIR__;
        $this->cfg = $this->loadConfig();
    }

    /**
     * Main execution entry point.
     *
     * @return array<string,mixed>
     */
    public function execute(): array
    {
        $t0 = microtime(true);
        $ts = date('c');

        $this->log('INFO', 'Copytrading Parser started');

        // Check if enabled
        if (($this->cfg['enabled'] ?? false) !== true) {
            $this->log('WARN', 'Copytrading module is disabled');
            return $this->finish(false, 'disabled', $t0, []);
        }

        // Ensure storage exists
        $this->ensureDir($this->moduleDir . '/storage');

        // Get traders list
        $traders = $this->cfg['traders'] ?? [];
        
        if (empty($traders)) {
            $this->log('WARN', 'No traders configured');
            return $this->finish(false, 'no_traders', $t0, []);
        }

        $this->log('INFO', 'Processing ' . count($traders) . ' traders');

        // Process each trader
        $allPositions = [];
        $allHistory = [];
        $successCount = 0;
        $requestDelay = (float)($this->cfg['api']['request_delay'] ?? 1.5); // Delay between requests in seconds

        foreach ($traders as $idx => $leaderMark) {
            $this->log('INFO', "Processing trader: {$leaderMark}");

            // Add delay between traders (except first one)
            if ($idx > 0 && $requestDelay > 0) {
                usleep((int)($requestDelay * 1000000));
            }

            // Fetch active positions
            $positions = $this->fetchPositions($leaderMark);
            if ($positions !== null) {
                $allPositions[$leaderMark] = [
                    'trader_id' => $leaderMark,
                    'positions' => $positions,
                    'fetched_at' => $ts,
                    'count' => count($positions),
                ];
                $this->log('INFO', "Trader {$leaderMark}: " . count($positions) . ' active positions');
            } else {
                $this->errors[] = "positions_fetch_failed: {$leaderMark}";
            }

            // Small delay between positions and history requests
            if ($requestDelay > 0) {
                usleep((int)(($requestDelay / 2) * 1000000));
            }

            // Fetch history
            $history = $this->fetchHistory($leaderMark);
            if ($history !== null) {
                $allHistory[$leaderMark] = [
                    'trader_id' => $leaderMark,
                    'trades' => $history,
                    'fetched_at' => $ts,
                    'count' => count($history),
                ];
                $this->log('INFO', "Trader {$leaderMark}: " . count($history) . ' historical trades');
                $successCount++;
            } else {
                $this->errors[] = "history_fetch_failed: {$leaderMark}";
            }
        }

        // Save results
        $storageCfg = $this->cfg['storage'] ?? [];
        
        $this->writeJson(
            $this->pathFromModule($storageCfg['activ_file'] ?? 'storage/activ.json'),
            [
                'updated_at' => $ts,
                'traders' => $allPositions,
            ]
        );

        $this->writeJson(
            $this->pathFromModule($storageCfg['history_file'] ?? 'storage/history.json'),
            [
                'updated_at' => $ts,
                'traders' => $allHistory,
            ]
        );

        // Write log
        $this->writeLog();

        return $this->finish(true, 'ok', $t0, [
            'traders_processed' => count($traders),
            'traders_success' => $successCount,
            'total_positions' => array_sum(array_map(fn($t) => $t['count'] ?? 0, $allPositions)),
            'total_history' => array_sum(array_map(fn($t) => $t['count'] ?? 0, $allHistory)),
        ]);
    }

    /**
     * Fetch active positions for a trader.
     *
     * @param string $leaderMark
     * @return array<int,array<string,mixed>>|null
     */
    private function fetchPositions(string $leaderMark): ?array
    {
        // Try API first
        $result = $this->fetchPositionsViaApi($leaderMark);
        
        if ($result !== null) {
            return $result;
        }

        // Fallback: Try scraping
        $this->log('WARN', "API failed for positions, trying fallback scrape for {$leaderMark}");
        return $this->fetchPositionsViaScrape($leaderMark);
    }

    /**
     * Fetch active positions via Bybit API.
     *
     * @param string $leaderMark
     * @return array<int,array<string,mixed>>|null
     */
    private function fetchPositionsViaApi(string $leaderMark): ?array
    {
        $apiCfg = $this->cfg['api'] ?? [];
        $baseUrl = $apiCfg['base_url'] ?? 'https://www.bybit.com';
        $endpoint = $apiCfg['positions_endpoint'] ?? '/x-api/fapi/beehive/public/v1/common/position/list';
        
        $url = $baseUrl . $endpoint . '?' . http_build_query(['leaderMark' => $leaderMark]);
        
        $response = $this->httpGet($url);
        
        if ($response === null) {
            return null;
        }

        $data = json_decode($response, true);
        
        if (!is_array($data)) {
            $this->log('ERROR', "Invalid JSON response for positions: {$leaderMark}");
            return null;
        }

        // Check API response structure
        if (isset($data['retCode']) && $data['retCode'] !== 0) {
            $this->log('ERROR', "API error: " . ($data['retMsg'] ?? 'Unknown error'));
            return null;
        }

        // Log raw response structure for debugging (only in debug mode)
        if (($this->cfg['logging']['debug'] ?? false) === true) {
            $this->log('DEBUG', "Positions API response keys: " . implode(', ', array_keys($data)));
        }

        // Extract positions from response - check multiple possible paths
        // Bybit API may return data in different structures:
        // - result (direct array)
        // - result.list (paginated)
        // - data (direct array)
        // - data.list (paginated)
        $positions = [];
        if (isset($data['result'])) {
            $result = $data['result'];
            if (is_array($result)) {
                // Check if result has a 'list' key (paginated response)
                $positions = $result['list'] ?? $result;
                // If still not an array, might be an object with positions inside
                if (!is_array($positions) || isset($positions['data'])) {
                    $positions = $positions['data'] ?? [];
                }
            }
        } elseif (isset($data['data'])) {
            $dataField = $data['data'];
            if (is_array($dataField)) {
                $positions = $dataField['list'] ?? $dataField;
            }
        }
        
        if (!is_array($positions)) {
            $this->log('WARN', "Could not extract positions array from API response");
            $positions = [];
        }
        
        $this->log('DEBUG', "Positions extracted: " . count($positions) . " items");

        // Normalize position data
        return array_map(function ($pos) {
            return [
                'symbol' => $pos['symbol'] ?? $pos['pair'] ?? '',
                'side' => $pos['side'] ?? ($pos['positionSide'] ?? ''),
                'entry_price' => (float)($pos['entryPrice'] ?? $pos['avgEntryPrice'] ?? 0),
                'mark_price' => (float)($pos['markPrice'] ?? 0),
                'size' => (float)($pos['size'] ?? $pos['positionValue'] ?? 0),
                'leverage' => (float)($pos['leverage'] ?? 1),
                'unrealized_pnl' => (float)($pos['unrealisedPnl'] ?? $pos['unRealizedProfit'] ?? 0),
                'roi_pct' => (float)($pos['roe'] ?? $pos['unrealisedRoe'] ?? 0) * 100,
                'created_at' => $pos['createdTime'] ?? $pos['openTime'] ?? null,
                'raw' => $pos, // Keep original data
            ];
        }, $positions);
    }

    /**
     * Fetch positions via HTML scraping (fallback).
     *
     * @param string $leaderMark
     * @return array<int,array<string,mixed>>|null
     */
    private function fetchPositionsViaScrape(string $leaderMark): ?array
    {
        $apiCfg = $this->cfg['api'] ?? [];
        $baseUrl = $apiCfg['base_url'] ?? 'https://www.bybit.com';
        
        // Try to fetch the trader's page
        $url = $baseUrl . '/copyTrade/trade-center/detail?' . http_build_query([
            'leaderMark' => $leaderMark,
            'profileDay' => 30,
        ]);

        $html = $this->httpGet($url);
        
        if ($html === null) {
            return null;
        }

        // Look for embedded JSON data in the page
        // Pattern: window.__INITIAL_STATE__ = {...}
        if (preg_match('/window\.__INITIAL_STATE__\s*=\s*(\{.+?\});?\s*<\/script>/s', $html, $matches)) {
            $jsonData = json_decode($matches[1], true);
            
            if (is_array($jsonData) && isset($jsonData['copyTrade']['positions'])) {
                return $jsonData['copyTrade']['positions'];
            }
        }

        // Alternative pattern: data-positions="[...]"
        if (preg_match('/data-positions=["\'](\[.+?\])["\']/', $html, $matches)) {
            $positions = json_decode(html_entity_decode($matches[1]), true);
            if (is_array($positions)) {
                return $positions;
            }
        }

        $this->log('WARN', 'Could not extract positions from HTML');
        return [];
    }

    /**
     * Fetch trade history for a trader.
     *
     * @param string $leaderMark
     * @return array<int,array<string,mixed>>|null
     */
    private function fetchHistory(string $leaderMark): ?array
    {
        // Try API first
        $result = $this->fetchHistoryViaApi($leaderMark);
        
        if ($result !== null) {
            return $result;
        }

        // Fallback: Try scraping
        $this->log('WARN', "API failed for history, trying fallback scrape for {$leaderMark}");
        return $this->fetchHistoryViaScrape($leaderMark);
    }

    /**
     * Fetch trade history via Bybit API.
     *
     * @param string $leaderMark
     * @return array<int,array<string,mixed>>|null
     */
    private function fetchHistoryViaApi(string $leaderMark): ?array
    {
        $apiCfg = $this->cfg['api'] ?? [];
        $historyCfg = $this->cfg['history'] ?? [];
        
        $baseUrl = $apiCfg['base_url'] ?? 'https://www.bybit.com';
        $endpoint = $apiCfg['history_endpoint'] ?? '/x-api/fapi/beehive/public/v1/common/leader-history';
        $pageSize = (int)($historyCfg['page_size'] ?? 50);
        $maxPages = (int)($historyCfg['max_pages'] ?? 5);

        $allTrades = [];
        $lastTradeId = null;

        for ($page = 0; $page < $maxPages; $page++) {
            $params = [
                'leaderMark' => $leaderMark,
                'pageSize' => $pageSize,
            ];

            if ($page === 0) {
                $params['pageAction'] = 'first_page';
            } else {
                $params['pageAction'] = 'next_page';
                if ($lastTradeId !== null) {
                    $params['lastTradeId'] = $lastTradeId;
                }
            }

            $url = $baseUrl . $endpoint . '?' . http_build_query($params);
            $response = $this->httpGet($url);

            if ($response === null) {
                // HTTP request failed - if we have some trades, return them; otherwise fail
                if (!empty($allTrades)) {
                    break;
                }
                return null;
            }

            $data = json_decode($response, true);

            if (!is_array($data)) {
                $this->log('WARN', "Invalid JSON response for history page {$page}");
                break;
            }

            // Log raw response structure for debugging (only first page, only in debug mode)
            if ($page === 0 && ($this->cfg['logging']['debug'] ?? false) === true) {
                $this->log('DEBUG', "History API response keys: " . implode(', ', array_keys($data)));
            }

            // Check API response
            if (isset($data['retCode']) && $data['retCode'] !== 0) {
                $this->log('ERROR', "History API error: " . ($data['retMsg'] ?? 'Unknown'));
                break;
            }

            // Extract trades from response - check multiple possible paths
            // Bybit API may return data in different structures
            $trades = [];
            if (isset($data['result'])) {
                $result = $data['result'];
                if (is_array($result)) {
                    $trades = $result['list'] ?? $result;
                    if (!is_array($trades) || isset($trades['data'])) {
                        $trades = $trades['data'] ?? [];
                    }
                }
            } elseif (isset($data['data'])) {
                $dataField = $data['data'];
                if (is_array($dataField)) {
                    $trades = $dataField['list'] ?? $dataField;
                }
            }
            
            if (!is_array($trades)) {
                $trades = [];
            }

            if ($page === 0) {
                $this->log('DEBUG', "History trades extracted: " . count($trades) . " items on first page");
            }
            
            if (empty($trades)) {
                break; // No more trades
            }

            // Normalize and add trades
            foreach ($trades as $trade) {
                $normalizedTrade = [
                    'symbol' => $trade['symbol'] ?? '',
                    'side' => $trade['side'] ?? '',
                    'entry_price' => (float)($trade['entryPrice'] ?? 0),
                    'close_price' => (float)($trade['closePrice'] ?? $trade['exitPrice'] ?? 0),
                    'size' => (float)($trade['size'] ?? $trade['qty'] ?? 0),
                    'leverage' => (float)($trade['leverage'] ?? 1),
                    'pnl' => (float)($trade['closedPnl'] ?? $trade['realisedPnl'] ?? $trade['pnl'] ?? 0),
                    'roi_pct' => (float)($trade['roi'] ?? $trade['roe'] ?? 0) * 100,
                    'open_time' => $trade['openTime'] ?? $trade['createdTime'] ?? null,
                    'close_time' => $trade['closeTime'] ?? $trade['updatedTime'] ?? null,
                    'trade_id' => $trade['orderId'] ?? $trade['tradeId'] ?? null,
                    'raw' => $trade,
                ];

                $allTrades[] = $normalizedTrade;
                $lastTradeId = $normalizedTrade['trade_id'];
            }

            // If we got fewer trades than pageSize, we're done
            if (count($trades) < $pageSize) {
                break;
            }
        }

        // Return empty array for valid empty response (not null - that triggers fallback)
        return $allTrades;
    }

    /**
     * Fetch history via HTML scraping (fallback).
     *
     * @param string $leaderMark
     * @return array<int,array<string,mixed>>|null
     */
    private function fetchHistoryViaScrape(string $leaderMark): ?array
    {
        // Similar to positions scraping
        $apiCfg = $this->cfg['api'] ?? [];
        $baseUrl = $apiCfg['base_url'] ?? 'https://www.bybit.com';
        
        $url = $baseUrl . '/copyTrade/trade-center/detail?' . http_build_query([
            'leaderMark' => $leaderMark,
            'profileDay' => 30,
        ]);

        $html = $this->httpGet($url);
        
        if ($html === null) {
            return null;
        }

        // Look for embedded JSON data
        if (preg_match('/window\.__INITIAL_STATE__\s*=\s*(\{.+?\});?\s*<\/script>/s', $html, $matches)) {
            $jsonData = json_decode($matches[1], true);
            
            if (is_array($jsonData) && isset($jsonData['copyTrade']['history'])) {
                return $jsonData['copyTrade']['history'];
            }
        }

        $this->log('WARN', 'Could not extract history from HTML');
        return [];
    }

    /**
     * Perform HTTP GET request with retry logic and Cloudflare bypass headers.
     *
     * @param string $url
     * @param int $maxRetries
     * @return string|null
     */
    private function httpGet(string $url, int $maxRetries = 3): ?string
    {
        $apiCfg = $this->cfg['api'] ?? [];
        $timeout = (int)($apiCfg['timeout'] ?? 30);
        $userAgent = $apiCfg['user_agent'] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

        // Parse URL to get origin for headers
        $parsedUrl = parse_url($url);
        $origin = ($parsedUrl['scheme'] ?? 'https') . '://' . ($parsedUrl['host'] ?? 'www.bybit.com');
        
        // Full browser-like headers to bypass Cloudflare
        $headers = [
            'Accept: application/json, text/plain, */*',
            'Accept-Language: en-US,en;q=0.9',
            'Accept-Encoding: gzip, deflate, br',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            'Origin: ' . $origin,
            'Referer: ' . $origin . '/copyTrade',
            'sec-ch-ua: "Not_A Brand";v="8", "Chromium";v="120", "Google Chrome";v="120"',
            'sec-ch-ua-mobile: ?0',
            'sec-ch-ua-platform: "Windows"',
            'Sec-Fetch-Dest: empty',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Site: same-origin',
            'Connection: keep-alive',
        ];

        $lastError = '';
        $lastHttpCode = 0;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            // Add random delay between retries (exponential backoff)
            if ($attempt > 1) {
                $delay = pow(2, $attempt - 1) + (random_int(0, 1000) / 1000);
                $this->log('INFO', "Retry #{$attempt} after {$delay}s delay");
                usleep((int)($delay * 1000000));
            }

            $ch = curl_init();
            
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_ENCODING => '', // Accept all encodings (gzip, deflate, br)
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0, // Use HTTP/2
                CURLOPT_HEADER => true, // Include headers in response for Cloudflare detection
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);
            
            // Separate headers and body
            $responseHeaders = substr((string)$response, 0, $headerSize);
            $responseBody = substr((string)$response, $headerSize);

            if ($response === false || !empty($error)) {
                $lastError = $error;
                $this->log('WARN', "HTTP attempt #{$attempt} failed: {$error}");
                continue;
            }

            // Success
            if ($httpCode === 200) {
                return $responseBody;
            }

            // Rate limit - wait and retry
            if ($httpCode === 429) {
                $lastHttpCode = $httpCode;
                $this->log('WARN', "Rate limited (429), will retry...");
                continue;
            }

            // Cloudflare challenge detection via headers (cf-ray, cf-cache-status)
            $isCloudflare = stripos($responseHeaders, 'cf-ray:') !== false 
                || stripos($responseHeaders, 'cf-cache-status:') !== false
                || stripos($responseBody, 'cloudflare') !== false
                || stripos($responseBody, 'challenge-platform') !== false;
            
            if (($httpCode === 403 || $httpCode === 503) && $isCloudflare) {
                $lastHttpCode = $httpCode;
                $this->log('WARN', "Cloudflare challenge detected (HTTP {$httpCode}), will retry...");
                continue;
            }

            // Other 4xx/5xx errors - don't retry
            if ($httpCode >= 400) {
                $this->log('ERROR', "HTTP {$httpCode} for URL: {$url}");
                $this->log('DEBUG', "Response preview: " . substr($responseBody, 0, 200));
                return null;
            }

            $lastHttpCode = $httpCode;
        }

        // All retries failed
        if (!empty($lastError)) {
            $this->log('ERROR', "HTTP request failed after {$maxRetries} attempts: {$lastError}");
        } else {
            $this->log('ERROR', "HTTP {$lastHttpCode} after {$maxRetries} attempts for URL: {$url}");
        }
        
        return null;
    }

    /**
     * Add trader to tracked list.
     *
     * @param string $leaderMark
     * @return bool
     */
    public function addTrader(string $leaderMark): bool
    {
        $traders = $this->cfg['traders'] ?? [];
        
        // Normalize leaderMark (decode URL encoding)
        $leaderMark = urldecode($leaderMark);
        
        if (in_array($leaderMark, $traders, true)) {
            return false; // Already exists
        }

        $traders[] = $leaderMark;
        
        return $this->saveTraders($traders);
    }

    /**
     * Remove trader from tracked list.
     *
     * @param string $leaderMark
     * @return bool
     */
    public function removeTrader(string $leaderMark): bool
    {
        $traders = $this->cfg['traders'] ?? [];
        $leaderMark = urldecode($leaderMark);
        
        $idx = array_search($leaderMark, $traders, true);
        
        if ($idx === false) {
            return false; // Not found
        }

        unset($traders[$idx]);
        $traders = array_values($traders); // Re-index
        
        return $this->saveTraders($traders);
    }

    /**
     * Get list of tracked traders.
     *
     * @return array<int,string>
     */
    public function getTraders(): array
    {
        return $this->cfg['traders'] ?? [];
    }

    /**
     * Save traders list to config.
     *
     * @param array<int,string> $traders
     * @return bool
     */
    private function saveTraders(array $traders): bool
    {
        $configPath = $this->moduleDir . '/config/config.php';
        
        // Load current config
        $this->cfg['traders'] = $traders;
        
        // Generate PHP config file
        $content = "<?php\ndeclare(strict_types=1);\n\n/**\n * Copytrading Parser — Configuration\n */\nreturn " . var_export($this->cfg, true) . ";\n";
        
        return file_put_contents($configPath, $content) !== false;
    }

    /**
     * Get active positions.
     *
     * @return array<string,mixed>
     */
    public function getActivePositions(): array
    {
        $storageCfg = $this->cfg['storage'] ?? [];
        $path = $this->pathFromModule($storageCfg['activ_file'] ?? 'storage/activ.json');
        
        if (!is_file($path)) {
            return ['updated_at' => null, 'traders' => []];
        }

        $data = json_decode(file_get_contents($path), true);
        
        return is_array($data) ? $data : ['updated_at' => null, 'traders' => []];
    }

    /**
     * Get trade history.
     *
     * @return array<string,mixed>
     */
    public function getHistory(): array
    {
        $storageCfg = $this->cfg['storage'] ?? [];
        $path = $this->pathFromModule($storageCfg['history_file'] ?? 'storage/history.json');
        
        if (!is_file($path)) {
            return ['updated_at' => null, 'traders' => []];
        }

        $data = json_decode(file_get_contents($path), true);
        
        return is_array($data) ? $data : ['updated_at' => null, 'traders' => []];
    }

    /**
     * Get last run info.
     *
     * @return array<string,mixed>
     */
    public function getLastRun(): array
    {
        $storageCfg = $this->cfg['storage'] ?? [];
        $path = $this->pathFromModule($storageCfg['last_run_file'] ?? 'storage/last_run.json');
        
        if (!is_file($path)) {
            return [];
        }

        $data = json_decode(file_get_contents($path), true);
        
        return is_array($data) ? $data : [];
    }

    /**
     * Log a message.
     */
    private function log(string $level, string $message): void
    {
        $ts = date('Y-m-d H:i:s');
        $this->logLines[] = "[{$ts}] [{$level}] {$message}";

        // Debug output
        if (($this->cfg['logging']['debug'] ?? false) === true) {
            error_log("[Copytrading] [{$level}] {$message}");
        }
    }

    /**
     * Write log to file.
     */
    private function writeLog(): void
    {
        if (empty($this->logLines)) {
            return;
        }

        $storageCfg = $this->cfg['storage'] ?? [];
        $logPath = $this->pathFromModule($storageCfg['log_file'] ?? 'storage/copytrading.log');

        $this->ensureDir(dirname($logPath));

        $content = implode("\n", $this->logLines) . "\n";

        // Rotate log if too large
        $maxSize = $this->cfg['logging']['max_log_size'] ?? 5242880;
        if (is_file($logPath) && filesize($logPath) > $maxSize) {
            $backupPath = $logPath . '.' . date('Y-m-d-His');
            rename($logPath, $backupPath);
        }

        file_put_contents($logPath, $content, FILE_APPEND | LOCK_EX);
    }

    /**
     * Finish execution and write last_run.json
     *
     * @param bool $success
     * @param string $status
     * @param float $t0
     * @param array<string,mixed> $stats
     * @return array<string,mixed>
     */
    private function finish(bool $success, string $status, float $t0, array $stats): array
    {
        $durationMs = (int)((microtime(true) - $t0) * 1000);

        $result = array_merge([
            'ok' => $success,
            'status' => $status,
            'ts' => date('c'),
            'duration_ms' => $durationMs,
            'errors_count' => count($this->errors),
            'errors' => $this->errors,
        ], $stats);

        $this->log('INFO', "Copytrading Parser finished: status={$status} duration={$durationMs}ms");
        $this->writeLog();

        // Write last_run.json
        $storageCfg = $this->cfg['storage'] ?? [];
        $lastRunPath = $this->pathFromModule($storageCfg['last_run_file'] ?? 'storage/last_run.json');
        $this->writeJson($lastRunPath, $result);

        return $result;
    }

    /**
     * Load configuration.
     *
     * @return array<string,mixed>
     */
    private function loadConfig(): array
    {
        $configPath = $this->moduleDir . '/config/config.php';

        if (!is_file($configPath)) {
            return ['enabled' => false, 'traders' => []];
        }

        $config = include $configPath;

        return is_array($config) ? $config : ['enabled' => false, 'traders' => []];
    }

    /**
     * Build path from module directory.
     */
    private function pathFromModule(string $relativePath): string
    {
        return $this->moduleDir . '/' . ltrim($relativePath, '/');
    }

    /**
     * Ensure directory exists.
     */
    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    /**
     * Write JSON file atomically.
     *
     * @param string $path
     * @param mixed $data
     */
    private function writeJson(string $path, $data): void
    {
        $this->ensureDir(dirname($path));

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            return;
        }

        $tempPath = $path . '.tmp';
        file_put_contents($tempPath, $json);
        rename($tempPath, $path);
    }
}
