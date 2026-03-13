<?php
declare(strict_types=1);

use Core\System\SystemPaths;

/**
 * Delist Parser 0 — Service
 * 
 * АРХИТЕКТУРНАЯ РОЛЬ:
 * Parser0 = факты (listings/delistings)
 * 
 * Этот модуль делает ТОЛЬКО одно:
 * - Получает список новых листингов и делистингов с Bybit
 * - Превращает это в: whitelist (новые монеты), blacklist (делистнутые)
 * 
 * Модуль НЕ:
 * - анализирует рынок
 * - читает Parser1+
 * - знает про цены, свечи, объёмы
 * - пишет кандидатов
 * 
 * Все остальные модули берут делист отсюда:
 * SystemPaths::get('parser.delist_parser0.storage') . '/blacklist.json'
 */
final class DelistParser0Service
{
    /** @var array<string,mixed> */
    private array $cfg = [];

    private string $moduleBase = '';

    /** @var array<int,string> */
    private array $errors = [];

    /** @var array<int,string> */
    private array $logLines = [];

    public function __construct()
    {
        $paths = SystemPaths::instance();

        // Load config via SystemPaths
        $moduleKey = 'parser.delist_parser0';
        $this->moduleBase = (string)$paths->get($moduleKey);
        
        if ($this->moduleBase === '') {
            throw new RuntimeException('SystemPaths missing module base for key: ' . $moduleKey);
        }

        $this->cfg = $this->loadConfig();
        $this->log('DEBUG', 'Module initialized, base=' . $this->moduleBase);
    }

    /**
     * Execute method (alias for run) - called by CronManager
     * 
     * @return array Same as run()
     */
    public function execute(): array
    {
        return $this->run();
    }

    /**
     * Execute one run.
     * 
     * @return array{
     *   success: bool,
     *   status: string,
     *   ts: string,
     *   whitelist: int,
     *   blacklist: int,
     *   duration_ms: int,
     *   errors_count: int
     * }
     */
    public function run(): array
    {
        $t0 = microtime(true);
        $ts = date('c');

        $this->log('INFO', 'DelistParser0 started');

        if (($this->cfg['enabled'] ?? false) !== true) {
            $this->log('WARN', 'DelistParser0 disabled');
            return $this->finish(false, 'disabled', $t0, $ts, [], []);
        }

        // Ensure storage directories exist
        $this->ensureDir($this->moduleBase . '/storage');
        $this->ensureDir($this->moduleBase . '/storage/logs');

        // Load existing data
        $whitelist = $this->loadJson($this->outputPath('whitelist'));
        $blacklist = $this->loadJson($this->outputPath('blacklist'));

        // Fetch announcements
        $bybit = is_array($this->cfg['bybit'] ?? null) ? $this->cfg['bybit'] : [];
        $types = is_array($bybit['types'] ?? null) ? $bybit['types'] : [
            'new_crypto' => 'whitelist',
            'delistings' => 'blacklist',
        ];

        $newSymbols = [
            'whitelist' => [],
            'blacklist' => [],
        ];

        foreach ($types as $apiType => $targetList) {
            $this->log('INFO', "Fetching type={$apiType} → {$targetList}");
            
            $announcements = $this->fetchAnnouncements($apiType);
            
            if (empty($announcements) && ($this->cfg['fallback']['enabled'] ?? false)) {
                $this->log('INFO', "API returned empty, trying fallback for {$apiType}");
                $announcements = $this->fetchFallback($apiType);
            }

            foreach ($announcements as $ann) {
                $title = (string)($ann['title'] ?? '');
                $symbols = $this->extractSymbols($title);
                
                foreach ($symbols as $symbol) {
                    $newSymbols[$targetList][$symbol] = [
                        'source' => 'api',
                        'ts' => $ts,
                        'title' => $title,
                    ];
                }
            }

            $this->log('INFO', "Type={$apiType}: found " . count($announcements) . " announcements, extracted " . count($newSymbols[$targetList]) . " symbols");
        }

        // Merge new symbols with existing
        foreach ($newSymbols['whitelist'] as $symbol => $data) {
            if (!isset($whitelist[$symbol])) {
                $whitelist[$symbol] = $data;
            }
        }

        foreach ($newSymbols['blacklist'] as $symbol => $data) {
            if (!isset($blacklist[$symbol])) {
                $blacklist[$symbol] = $data;
            }
        }

        // Sort by symbol for stable diffs
        ksort($whitelist);
        ksort($blacklist);

        // Write outputs
        $this->writeJson($this->outputPath('whitelist'), $whitelist);
        $this->writeJson($this->outputPath('blacklist'), $blacklist);

        $this->log('INFO', 'DelistParser0 finished: whitelist=' . count($whitelist) . ', blacklist=' . count($blacklist));

        return $this->finish(true, 'ok', $t0, $ts, $whitelist, $blacklist);
    }

    /* =========================================================
       BYBIT ANNOUNCEMENTS API
       ========================================================= */

    /**
     * Fetch announcements from Bybit API
     * 
     * @return array<int,array{title:string,description?:string,dateTimestamp?:int}>
     */
    private function fetchAnnouncements(string $type): array
    {
        $bybit = is_array($this->cfg['bybit'] ?? null) ? $this->cfg['bybit'] : [];
        $baseUrl = (string)($bybit['announcements_url'] ?? 'https://api.bybit.com/v5/announcements/index');
        $locale = (string)($bybit['locale'] ?? 'ru-RU');
        $limit = (int)($bybit['limit'] ?? 20);
        $maxPages = (int)($bybit['max_pages'] ?? 3);

        $all = [];
        
        for ($page = 1; $page <= $maxPages; $page++) {
            $params = [
                'locale' => $locale,
                'type' => $type,
                'page' => $page,
                'limit' => $limit,
            ];

            $url = $baseUrl . '?' . http_build_query($params);
            $resp = $this->httpGetJson($url);

            if (($resp['success'] ?? false) !== true) {
                $this->errors[] = "api_fetch_failed:{$type}:page{$page}:" . ($resp['error'] ?? 'unknown');
                $this->log('ERROR', "API fetch failed type={$type} page={$page}: " . ($resp['error'] ?? 'unknown'));
                break;
            }

            $data = is_array($resp['data'] ?? null) ? $resp['data'] : [];
            $retCode = (int)($data['retCode'] ?? -1);

            if ($retCode !== 0) {
                $this->errors[] = "api_retcode:{$type}:page{$page}:{$retCode}";
                $this->log('ERROR', "API retCode={$retCode} for type={$type}");
                break;
            }

            $result = is_array($data['result'] ?? null) ? $data['result'] : [];
            $list = is_array($result['list'] ?? null) ? $result['list'] : [];

            if (empty($list)) {
                break; // No more results
            }

            foreach ($list as $item) {
                if (!is_array($item)) continue;
                $all[] = $item;
            }

            $this->log('DEBUG', "Page {$page}: fetched " . count($list) . " announcements");
        }

        return $all;
    }

    /**
     * Fallback: scrape HTML announcements page
     * 
     * @return array<int,array{title:string}>
     */
    private function fetchFallback(string $type): array
    {
        $fallback = is_array($this->cfg['fallback'] ?? null) ? $this->cfg['fallback'] : [];
        $urls = is_array($fallback['urls'] ?? null) ? $fallback['urls'] : [];
        
        $url = (string)($urls[$type] ?? '');
        if ($url === '') {
            return [];
        }

        $resp = $this->httpGet($url);
        if ($resp === '') {
            $this->errors[] = "fallback_fetch_failed:{$type}";
            return [];
        }

        // Simple extraction of titles from HTML
        // Look for announcement titles in the page
        $titles = [];
        
        // Try to find JSON data embedded in page (common pattern)
        if (preg_match('/window\.__NUXT__\s*=\s*({.+?});?\s*<\/script>/s', $resp, $m)) {
            $nuxt = @json_decode($m[1], true);
            if (is_array($nuxt)) {
                // Navigate the NUXT data structure to find announcements
                $this->extractTitlesFromNuxt($nuxt, $titles);
            }
        }

        // Fallback: extract from HTML structure
        if (empty($titles)) {
            // Look for title patterns
            if (preg_match_all('/<h[23][^>]*class="[^"]*title[^"]*"[^>]*>([^<]+)<\/h[23]>/i', $resp, $matches)) {
                foreach ($matches[1] as $title) {
                    $titles[] = ['title' => html_entity_decode(trim($title))];
                }
            }
        }

        $this->log('DEBUG', "Fallback {$type}: extracted " . count($titles) . " titles");
        return $titles;
    }

    /**
     * Recursively extract titles from NUXT data
     * 
     * @param array<string,mixed> $data
     * @param array<int,array{title:string}> &$titles
     */
    private function extractTitlesFromNuxt(array $data, array &$titles): void
    {
        foreach ($data as $key => $value) {
            if ($key === 'title' && is_string($value) && strlen($value) > 5) {
                $titles[] = ['title' => $value];
            }
            if (is_array($value)) {
                $this->extractTitlesFromNuxt($value, $titles);
            }
        }
    }

    /* =========================================================
       SYMBOL EXTRACTION
       ========================================================= */

    /**
     * Extract symbols from announcement title
     * 
     * @return array<int,string>
     */
    private function extractSymbols(string $title): array
    {
        $extraction = is_array($this->cfg['extraction'] ?? null) ? $this->cfg['extraction'] : [];
        $regex = (string)($extraction['symbol_regex'] ?? '/([A-Z0-9]{2,}USDT)/i');
        $minLen = (int)($extraction['min_base_length'] ?? 2);
        $maxLen = (int)($extraction['max_base_length'] ?? 20);

        $symbols = [];

        if (preg_match_all($regex, $title, $matches)) {
            foreach ($matches[1] as $match) {
                $symbol = strtoupper(trim($match));
                
                // Extract base (without USDT suffix)
                $base = preg_replace('/USDT$/i', '', $symbol);
                $baseLen = strlen($base);
                
                if ($baseLen >= $minLen && $baseLen <= $maxLen) {
                    $symbols[] = $symbol;
                }
            }
        }

        return array_unique($symbols);
    }

    /* =========================================================
       HTTP HELPERS
       ========================================================= */

    /**
     * HTTP GET JSON with retries
     * 
     * @return array<string,mixed>
     */
    private function httpGetJson(string $url): array
    {
        $bybit = is_array($this->cfg['bybit'] ?? null) ? $this->cfg['bybit'] : [];
        $timeout = (int)($bybit['timeout_sec'] ?? 15);
        $maxBytes = (int)($bybit['max_bytes'] ?? 2000000);
        $ua = (string)($bybit['user_agent'] ?? 'tredercopis-delist-parser0');

        $retry = is_array($bybit['retry'] ?? null) ? $bybit['retry'] : [];
        $retryCount = (int)($retry['count'] ?? 2);
        $sleepMs = (int)($retry['sleep_ms'] ?? 500);

        $attempt = 0;
        while (true) {
            $attempt++;

            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => $timeout,
                    'header' => "User-Agent: {$ua}\r\nAccept: application/json\r\n",
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]);

            $data = @file_get_contents($url, false, $ctx, 0, $maxBytes);

            if ($data === false) {
                if ($attempt <= $retryCount) {
                    usleep($sleepMs * 1000);
                    continue;
                }
                return ['success' => false, 'error' => 'http_fetch_failed'];
            }

            $decoded = json_decode($data, true);
            if (!is_array($decoded)) {
                return ['success' => false, 'error' => 'json_decode_failed'];
            }

            return ['success' => true, 'data' => $decoded];
        }
    }

    /**
     * Simple HTTP GET (returns raw content)
     */
    private function httpGet(string $url): string
    {
        $bybit = is_array($this->cfg['bybit'] ?? null) ? $this->cfg['bybit'] : [];
        $timeout = (int)($bybit['timeout_sec'] ?? 15);
        $ua = (string)($bybit['user_agent'] ?? 'tredercopis-delist-parser0');

        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'header' => "User-Agent: {$ua}\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $data = @file_get_contents($url, false, $ctx);
        return $data === false ? '' : $data;
    }

    /* =========================================================
       IO HELPERS
       ========================================================= */

    private function outputPath(string $key): string
    {
        $out = is_array($this->cfg['output'] ?? null) ? $this->cfg['output'] : [];
        $rel = (string)($out[$key] ?? ('storage/' . $key . '.json'));
        return rtrim($this->moduleBase, '/') . '/' . ltrim($rel, '/');
    }

    private function ensureDir(string $dir): void
    {
        if ($dir !== '' && !is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function loadJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param mixed $data
     */
    private function writeJson(string $path, $data): void
    {
        $dir = dirname($path);
        $this->ensureDir($dir);

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }

        $write = is_array($this->cfg['write'] ?? null) ? $this->cfg['write'] : [];
        $atomic = (bool)($write['atomic'] ?? true);

        if ($atomic) {
            $tmp = $path . '.tmp';
            if (file_put_contents($tmp, $json) === false) {
                $this->log('ERROR', "Failed to write temp file: {$tmp}");
                return;
            }
            rename($tmp, $path);
        } else {
            file_put_contents($path, $json);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function loadConfig(): array
    {
        $path = rtrim($this->moduleBase, '/') . '/config/config.php';
        if (!is_file($path)) {
            return [];
        }
        $cfg = require $path;
        return is_array($cfg) ? $cfg : [];
    }

    /* =========================================================
       LOGGING
       ========================================================= */

    private function log(string $level, string $message): void
    {
        $ts = date('Y-m-d H:i:s');
        $this->logLines[] = "[{$ts}] [{$level}] {$message}";
    }

    private function writeLog(): void
    {
        $out = is_array($this->cfg['output'] ?? null) ? $this->cfg['output'] : [];
        $rel = (string)($out['log'] ?? '');
        if ($rel === '') {
            return;
        }
        $path = rtrim($this->moduleBase, '/') . '/' . ltrim($rel, '/');
        $this->ensureDir(dirname($path));

        $content = implode("\n", $this->logLines) . "\n";
        file_put_contents($path, $content, FILE_APPEND | LOCK_EX);
    }

    /* =========================================================
       FINISH
       ========================================================= */

    /**
     * @param array<string,mixed> $whitelist
     * @param array<string,mixed> $blacklist
     * @return array{
     *   success: bool,
     *   status: string,
     *   ts: string,
     *   whitelist: int,
     *   blacklist: int,
     *   duration_ms: int,
     *   errors_count: int
     * }
     */
    private function finish(bool $ok, string $status, float $t0, string $ts, array $whitelist, array $blacklist): array
    {
        $durationMs = (int)((microtime(true) - $t0) * 1000);

        // Write state.json
        $this->writeJson($this->outputPath('state'), [
            'whitelist' => count($whitelist),
            'blacklist' => count($blacklist),
            'errors' => count($this->errors),
        ]);

        // Write last_run.json
        $this->writeJson($this->outputPath('last_run'), [
            'ts' => $ts,
            'duration_ms' => $durationMs,
            'ok' => $ok,
        ]);

        $this->writeLog();

        return [
            'success' => $ok,
            'status' => $status,
            'ts' => $ts,
            'whitelist' => count($whitelist),
            'blacklist' => count($blacklist),
            'duration_ms' => $durationMs,
            'errors_count' => count($this->errors),
        ];
    }
}

/*
RULES (Tredercopis Architecture)
- SystemPaths ONLY
- No filesystem guessing
- Outputs only into module storage
- This is Parser0 = FACTS level
- Does NOT analyze market data
- Other modules read blacklist.json via:
  SystemPaths::get('parser.delist_parser0.storage') . '/blacklist.json'
*/
