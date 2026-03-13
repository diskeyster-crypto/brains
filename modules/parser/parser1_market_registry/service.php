<?php
declare(strict_types=1);

use Core\System\SystemPaths;

/**
 * Parser 1: Market Registry — Service (MIGRATED)
 *
 * ARCHITECTURE:
 * - SystemPaths ONLY for any cross-module / root related paths.
 * - No filesystem guessing, no detectRootDir, no /config/config.php.
 *
 * PURPOSE (kept 1:1):
 * - Fetch Bybit public instruments registry (V5) for configured categories
 * - Save raw registry.json (grouped by category)
 * - Apply delist filter (Parser0) if enabled
 * - Apply config filters (quote_only, exclude_symbols, exclude_bases, include/exclude regex)
 * - Build active.json for downstream modules
 *
 * CronManager expects a global service class named:
 *   Parser1MarketRegistryService
 */
final class Parser1MarketRegistryService
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

        // Load config first via SystemPaths module base
        $moduleKey = 'parser.parser1_market_registry';
        $tmpCfg = $this->loadConfigByKey($paths, $moduleKey);

        // allow config override
        if (is_array($tmpCfg['sources'] ?? null) && is_string(($tmpCfg['sources']['module_key'] ?? null))) {
            $moduleKey = (string)$tmpCfg['sources']['module_key'];
        }

        $this->moduleBase = (string)$paths->get($moduleKey);
        if ($this->moduleBase === '') {
            throw new RuntimeException('SystemPaths missing module base for key: ' . $moduleKey);
        }

        $this->cfg = $this->loadConfigFromModule($this->moduleBase);

        $this->log('DEBUG', 'SystemPaths module_key=' . $moduleKey);
        $this->log('DEBUG', 'Resolved moduleBase=' . $this->moduleBase);
    }

    /**
     * Execute one run.
     *
     * @return array<string,mixed>
     */
    public function execute(): array
    {
        $t0 = microtime(true);
        $ts = date('c');

        $this->log('INFO', 'Parser1 Market Registry started');

        if (($this->cfg['enabled'] ?? false) !== true) {
            $this->log('WARN', 'Parser1 disabled');
            return $this->finish(false, 'disabled', $t0, [
                'ts' => $ts,
                'processed_categories' => 0,
                'active_symbols' => 0,
            ]);
        }

        // Ensure module storage
        $this->ensureDir($this->moduleBase . '/storage');

        $categories = $this->cfgArray('categories');
        if (empty($categories)) {
            $this->errors[] = 'config_categories_empty';
            return $this->finish(false, 'config_error', $t0, [
                'ts' => $ts,
                'processed_categories' => 0,
                'active_symbols' => 0,
            ]);
        }

        $useDelist = (bool)($this->cfg['use_delist_filter'] ?? false);

        // Fetch + normalize instruments grouped by category
        $registry = [];
        $categoryStats = [];
        foreach ($categories as $cat) {
            $cat = (string)$cat;
            if ($cat === '') {
                continue;
            }

            $resp = $this->fetchInstrumentsByCategory($cat);
            if (($resp['success'] ?? false) !== true) {
                $this->errors[] = 'fetch_failed:' . $cat . ':' . (string)($resp['error'] ?? 'unknown');
                $this->log('ERROR', 'Fetch failed category=' . $cat . ' error=' . (string)($resp['error'] ?? 'unknown'));
                continue;
            }

            /** @var array<int,array<string,mixed>> $items */
            $items = is_array($resp['items'] ?? null) ? $resp['items'] : [];
            $normalized = [];
            foreach ($items as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $n = $this->normalizeInstrument($row, $cat);
                if ($n === null) {
                    continue;
                }
                $normalized[] = $n;
            }

            $registry[$cat] = $normalized;
            $categoryStats[$cat] = [
                'raw' => (int)($resp['count'] ?? 0),
                'normalized' => count($normalized),
            ];

            $this->log('INFO', 'Category=' . $cat . ' raw=' . (int)($resp['count'] ?? 0) . ' normalized=' . count($normalized));
        }

        // Write raw registry.json
        $this->writeJson($this->outputPath('registry'), [
            'ts' => $ts,
            'categories' => $categoryStats,
            'registry' => $registry,
        ]);

        // Load delist symbols set (optional)
        $delistedSet = [];
        if ($useDelist) {
            $delistedSet = $this->loadDelistSymbols();
            $this->log('INFO', 'Delist filter: loaded=' . count($delistedSet));
        } else {
            $this->log('INFO', 'Delist filter: disabled');
        }

        // Build ACTIVE list (flat symbol => meta)
        $active = [];
        $delistedOut = [];

        $quoteOnly = $this->cfgArray('quote_only');
        $excludeSymbols = $this->toSetUpper($this->cfgArray('exclude_symbols'));
        $excludeBases = $this->toSetUpper($this->cfgArray('exclude_bases'));

        $includeRegex = (string)($this->cfg['include_regex'] ?? '');
        $excludeRegexes = $this->cfgArray('exclude_regexes');

        foreach ($registry as $cat => $items) {
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $symbol = (string)($it['symbol'] ?? '');
                if ($symbol === '') {
                    continue;
                }

                // delist filter
                if ($useDelist && isset($delistedSet[strtoupper($symbol)])) {
                    $delistedOut[$symbol] = 'delisted_by_parser0';
                    continue;
                }

                // inactive / suspended
                if ($this->looksInactiveStatus($it)) {
                    $delistedOut[$symbol] = 'inactive_status';
                    continue;
                }

                // quote_only filter
                if (!empty($quoteOnly)) {
                    $quoteCoin = strtoupper((string)($it['quoteCoin'] ?? ''));
                    if ($quoteCoin === '' || !in_array($quoteCoin, array_map('strtoupper', $quoteOnly), true)) {
                        continue;
                    }
                }

                // explicit exclude lists
                if (isset($excludeSymbols[strtoupper($symbol)])) {
                    continue;
                }
                $baseCoin = strtoupper((string)($it['baseCoin'] ?? ''));
                if ($baseCoin !== '' && isset($excludeBases[$baseCoin])) {
                    continue;
                }

                // include regex (optional)
                if ($includeRegex !== '') {
                    // allow regex in Parser1 config (kept from legacy)
                    if (@preg_match($includeRegex, $symbol) !== 1) {
                        continue;
                    }
                }

                // exclude regexes
                foreach ($excludeRegexes as $rx) {
                    $rx = (string)$rx;
                    if ($rx === '') {
                        continue;
                    }
                    if (@preg_match($rx, $symbol) === 1) {
                        continue 2;
                    }
                }

                $active[$symbol] = [
                    'symbol' => $symbol,
                    'category' => (string)$cat,
                    'baseCoin' => (string)($it['baseCoin'] ?? ''),
                    'quoteCoin' => (string)($it['quoteCoin'] ?? ''),
                    'status' => (string)($it['status'] ?? ''),
                    'ts' => $ts,
                ];
            }
        }

        // sort active by symbol for stable diffs
        ksort($active);

        $this->writeJson($this->outputPath('active'), $active);
        $this->writeJson($this->outputPath('delisted'), $delistedOut);

        $stats = [
            'ts' => $ts,
            'processed_categories' => count($categoryStats),
            'categories' => $categoryStats,
            'active_symbols' => count($active),
            'delisted_count' => count($delistedOut),
            'errors_count' => count($this->errors),
        ];

        $this->writeLastRun($stats, $t0);

        $this->log('INFO', 'Parser1 finished active=' . count($active) . ' delisted=' . count($delistedOut));
        $this->writeLog();

        $durationMs = (int)((microtime(true) - $t0) * 1000);
        return [
            'success' => true,
            'message' => 'OK',
            'result' => $stats,
            'duration_ms' => $durationMs,
        ];
    }

    /* =========================================================
       BYBIT FETCH
       ========================================================= */

    /**
     * Fetch instruments list for a category (Bybit V5 instruments-info), with cursor pagination.
     *
     * @return array<string,mixed>
     */
    private function fetchInstrumentsByCategory(string $category): array
    {
        $bybit = is_array($this->cfg['bybit'] ?? null) ? $this->cfg['bybit'] : [];
        $baseUrl = (string)($bybit['base_url'] ?? '');
        $endpoint = (string)($bybit['endpoint_instruments'] ?? '');

        if ($baseUrl === '' || $endpoint === '') {
            return ['success' => false, 'error' => 'config_bybit_base_or_endpoint_empty'];
        }

        $maxPages = (int)($bybit['max_pages_per_category'] ?? 50);
        if ($maxPages < 1) {
            $maxPages = 1;
        }

        $all = [];
        $cursor = '';
        $page = 0;

        while (true) {
            $page++;
            if ($page > $maxPages) {
                return [
                    'success' => false,
                    'error' => 'max_pages_exceeded',
                    'category' => $category,
                    'max_pages' => $maxPages,
                    'collected' => count($all),
                ];
            }

            $params = ['category' => $category];
            if ($cursor !== '') {
                $params['cursor'] = $cursor;
            }

            $url = rtrim($baseUrl, '/') . $endpoint . '?' . http_build_query($params);

            $resp = $this->httpGetJson($url);
            if (($resp['success'] ?? false) !== true) {
                $resp['category'] = $category;
                $resp['page'] = $page;
                return $resp;
            }

            /** @var array<string,mixed> $data */
            $data = is_array($resp['data'] ?? null) ? $resp['data'] : [];

            $retCode = (int)($data['retCode'] ?? -1);
            if ($retCode !== 0) {
                return [
                    'success' => false,
                    'error' => 'bybit_retcode_nonzero',
                    'retCode' => $retCode,
                    'retMsg' => (string)($data['retMsg'] ?? ''),
                    'category' => $category,
                    'page' => $page,
                ];
            }

            /** @var array<string,mixed> $result */
            $result = is_array($data['result'] ?? null) ? $data['result'] : [];

            /** @var array<int,array<string,mixed>> $list */
            $list = is_array($result['list'] ?? null) ? $result['list'] : [];

            foreach ($list as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (!isset($row['symbol']) || (string)$row['symbol'] === '') {
                    continue;
                }
                $all[] = $row;
            }

            $cursor = (string)($result['nextPageCursor'] ?? '');
            if ($cursor === '') {
                break;
            }
        }

        return [
            'success' => true,
            'category' => $category,
            'count' => count($all),
            'items' => $all,
        ];
    }

    /**
     * HTTP GET JSON with retries and max_bytes safety.
     *
     * @return array<string,mixed>
     */
    private function httpGetJson(string $url): array
    {
        $bybit = is_array($this->cfg['bybit'] ?? null) ? $this->cfg['bybit'] : [];
        $timeout = (int)($bybit['timeout_sec'] ?? 20);
        $connectTimeout = (int)($bybit['connect_timeout_sec'] ?? 10);
        $maxBytes = (int)($bybit['max_bytes'] ?? 8000000);
        $ua = (string)($bybit['user_agent'] ?? 'tredercopis-parser1-registry');

        $retry = is_array($bybit['retry'] ?? null) ? $bybit['retry'] : [];
        $retryCount = (int)($retry['count'] ?? 0);
        $sleepMs = (int)($retry['sleep_ms'] ?? 200);

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
            $httpCode = 0;

            if (isset($http_response_header) && is_array($http_response_header)) {
                foreach ($http_response_header as $h) {
                    if (preg_match('/^HTTP\/[0-9.]+\s+(\d+)/', (string)$h, $m)) {
                        $httpCode = (int)$m[1];
                        break;
                    }
                }
            }

            if ($data === false) {
                if ($attempt <= $retryCount + 1) {
                    usleep(max(0, $sleepMs) * 1000);
                    continue;
                }
                return [
                    'success' => false,
                    'error' => 'http_fetch_failed',
                    'http_code' => $httpCode,
                    'url' => $url,
                ];
            }

            $decoded = json_decode($data, true);
            if (!is_array($decoded)) {
                return [
                    'success' => false,
                    'error' => 'json_decode_failed',
                    'http_code' => $httpCode,
                    'url' => $url,
                ];
            }

            return [
                'success' => true,
                'http_code' => $httpCode,
                'data' => $decoded,
            ];
        }
    }

    /* =========================================================
       NORMALIZATION + FILTER HELPERS
       ========================================================= */

    /**
     * Normalize Bybit instrument row to a minimal structure used by this module.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    private function normalizeInstrument(array $row, string $category): ?array
    {
        $symbol = (string)($row['symbol'] ?? '');
        if ($symbol === '') {
            return null;
        }

        // keep 1:1 fields used by filters / downstream
        return [
            'symbol' => $symbol,
            'category' => $category,
            'status' => (string)($row['status'] ?? ''),
            'baseCoin' => (string)($row['baseCoin'] ?? ''),
            'quoteCoin' => (string)($row['quoteCoin'] ?? ''),
        ];
    }

    /**
     * Detect inactive/suspended status.
     *
     * @param array<string,mixed> $it
     */
    private function looksInactiveStatus(array $it): bool
    {
        $st = strtolower((string)($it['status'] ?? ''));
        if ($st === '') {
            return false;
        }

        // legacy-compatible heuristics
        return (
            $st === 'closed' ||
            $st === 'suspended' ||
            $st === 'paused' ||
            $st === 'inactive'
        );
    }

    /**
     * Load delist symbols (Parser0 blacklist.json) via SystemPaths key.
     *
     * @return array<string,bool> set
     */
    private function loadDelistSymbols(): array
    {
        $paths = SystemPaths::instance();

        // preferred: sources.delist_key + sources.delist_file
        $sources = is_array($this->cfg['sources'] ?? null) ? $this->cfg['sources'] : [];
        $delistKey = (string)($sources['delist_key'] ?? '');
        $delistFile = (string)($sources['delist_file'] ?? 'blacklist.json');

        // backward: config.delist_source like "modules/delist_parser0/storage/blacklist.json"
        // We cannot resolve that without guessing root, so we only support when delist_key is provided.
        if ($delistKey === '') {
            $this->log('WARN', 'Delist enabled but sources.delist_key missing; skipping delist filter (no filesystem guessing).');
            return [];
        }

        $base = (string)$paths->get($delistKey);
        $this->log('DEBUG', 'SystemPaths delist_key=' . $delistKey);
        $this->log('DEBUG', 'Resolved delistBase=' . $base);
        if ($base === '') {
            $this->log('WARN', 'Delist key not found in SystemPaths: ' . $delistKey);
            return [];
        }

        $filePath = rtrim($base, '/') . '/' . ltrim($delistFile, '/');
        if (!is_file($filePath)) {
            $this->log('WARN', 'Delist file not found: ' . $filePath);
            return [];
        }

        $raw = file_get_contents($filePath);
        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $set = [];
        foreach ($decoded as $k => $v) {
            if (is_string($k) && $k !== '') {
                $set[strtoupper($k)] = true;
                continue;
            }
            if (is_string($v) && $v !== '') {
                $set[strtoupper($v)] = true;
            }
        }
        return $set;
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
        if ($dir === '') {
            return;
        }
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
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
            file_put_contents($tmp, $json);
            rename($tmp, $path);
            return;
        }

        file_put_contents($path, $json);
    }

    /**
     * Write last_run.json and state.json (legacy-compatible).
     *
     * @param array<string,mixed> $stats
     */
    private function writeLastRun(array $stats, float $t0): void
    {
        $durationMs = (int)((microtime(true) - $t0) * 1000);
        $stats['duration_ms'] = $durationMs;
        $stats['success'] = ($stats['ok'] ?? true) === true;

        $this->writeJson($this->outputPath('last_run'), $stats);
        $this->writeJson($this->outputPath('state'), [
            'ts' => $stats['ts'] ?? date('c'),
            'active_symbols' => (int)($stats['active_symbols'] ?? 0),
            'errors_count' => (int)($stats['errors_count'] ?? 0),
            'hash' => $this->hashJson($this->outputPath('active')),
        ]);
    }

    private function hashJson(string $path): string
    {
        if (!is_file($path)) {
            return '';
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return '';
        }
        return sha1($raw);
    }

    /**
     * @return array<int,mixed>
     */
    private function cfgArray(string $key): array
    {
        $v = $this->cfg[$key] ?? [];
        return is_array($v) ? array_values($v) : [];
    }

    /**
     * @param array<int,mixed> $list
     * @return array<string,bool>
     */
    private function toSetUpper(array $list): array
    {
        $set = [];
        foreach ($list as $v) {
            if (!is_string($v)) {
                continue;
            }
            $v = strtoupper(trim($v));
            if ($v === '') {
                continue;
            }
            $set[$v] = true;
        }
        return $set;
    }

    /* =========================================================
       LOGGING + FINISH
       ========================================================= */

    private function log(string $level, string $message): void
    {
        $ts = date('Y-m-d H:i:s');
        $this->logLines[] = "[{$ts}] [{$level}] {$message}";
    }

    private function writeLog(): void
    {
        // optional log file; if not configured, do nothing
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

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function finish(bool $ok, string $status, float $t0, array $payload): array
    {
        $durationMs = (int)((microtime(true) - $t0) * 1000);
        $payload['ok'] = $ok;
        $payload['success'] = $ok;
        $payload['status'] = $status;
        $payload['duration_ms'] = $durationMs;
        $payload['errors_count'] = count($this->errors);

        $this->writeJson($this->outputPath('last_run'), $payload);
        $this->writeLog();

        return $payload;
    }

    /**
     * Load config via SystemPaths key before moduleBase is known.
     *
     * @return array<string,mixed>
     */
    private function loadConfigByKey(SystemPaths $paths, string $moduleKey): array
    {
        $base = (string)$paths->get($moduleKey);
        if ($base === '') {
            return [];
        }
        return $this->loadConfigFromModule($base);
    }

    /**
     * Load config/config.php from a module base path.
     *
     * @return array<string,mixed>
     */
    private function loadConfigFromModule(string $moduleBase): array
    {
        $path = rtrim($moduleBase, '/') . '/config/config.php';
        if (!is_file($path)) {
            return [];
        }
        $cfg = require $path;
        return is_array($cfg) ? $cfg : [];
    }
}

/*
RULES (Tredercopis Architecture)
SystemPaths ONLY.
No filesystem guessing.
No /config/config.php.
Outputs only into module storage.
PHP 8.2+ safe (no dynamic properties).
*/
