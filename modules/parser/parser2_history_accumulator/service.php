<?php
declare(strict_types=1);

use Core\System\SystemPaths;

/**
 * Parser 2: History Accumulator (RAW)
 *
 * Tredercopis — Parser 2 (History Accumulator)
 *
 * Responsibilities:
 * - Read active symbols from Parser1 Market Registry (active.json)
 * - Fetch Bybit public tickers in ONE request per category
 * - Append FULL RAW ticker snapshot to per-symbol NDJSON file (append-only)
 * - Write module state.json + last_run.json (+ errors.json if any)
 *
 * Prohibitions:
 * - No DB
 * - No UI
 * - No private Bybit endpoints
 * - No writes outside this module storage
 * - SystemPaths ONLY (no __DIR__/dirname/realpath/root guessing)
 *
 * Output format (never change):
 * modules/parser/parser2_history_accumulator/storage/{SYMBOL}/{YYYY-MM-DD}.ndjson
 * Each line:
 * {"ts":"...","ts_unix":123,"category":"linear","data":{...RAW_BYBIT_TICKER...}}
 *
 * CronManager expects a global service class named:
 *   Parser2HistoryAccumulatorService
 */
final class Parser2HistoryAccumulatorService
{
    /** Max number of missing symbols to track for debugging */
    private const MAX_MISSING_SYMBOLS_DEBUG = 20;

    /** @var array<string,mixed> */
    private array $config = [];

    private string $moduleBase = '';

    /** @var array<int,string> */
    private array $errors = [];

    /** @var array<int,string> */
    private array $logLines = [];

    /** @var string Registry shape detected */
    private string $registryShape = 'unknown';

    /** @var string Debug info for registry (first 200 chars) */
    private string $registryDebug = '';

    /** @var array<int,string> Missing symbols (not found in tickers) */
    private array $missingInTickers = [];

    public function __construct()
    {
        $paths = SystemPaths::instance();

        // Module base absolute path from SystemPaths
        $moduleKey = 'parser.parser2_history_accumulator';
        $base = (string)$paths->get($moduleKey);
        if ($base === '') {
            throw new RuntimeException('SystemPaths missing module base for key: ' . $moduleKey);
        }

        $this->moduleBase = rtrim($base, '/');
        $this->config = $this->loadConfig();
    }

    /**
     * Main execution entrypoint.
     *
     * @return array<string,mixed>
     */
    public function execute(): array
    {
        $t0 = microtime(true);
        $tsIso = date('c');
        $tsUnix = time();

        // Minimal log
        $this->log('INFO', 'Parser2 History Accumulator started');

        // Enabled?
        if (($this->config['core']['enabled'] ?? false) !== true) {
            $this->log('WARN', 'Parser2 is disabled');
            return $this->finish(false, 'disabled', $t0, [
                'ts' => $tsIso,
                'symbols_total' => 0,
                'processed' => 0,
                'written' => 0,
                'skipped' => 0,
                'tickers_total' => 0,
            ]);
        }

        // Strict config validation (no hidden defaults)
        $validation = $this->validateConfig($this->config);
        if (($validation['ok'] ?? false) !== true) {
            foreach (($validation['errors'] ?? []) as $e) {
                $this->errors[] = (string)$e;
            }
            $this->log('ERROR', 'Config invalid: ' . implode(', ', $this->errors));

            return $this->finish(false, 'config_invalid', $t0, [
                'ts' => $tsIso,
                'symbols_total' => 0,
                'processed' => 0,
                'written' => 0,
                'skipped' => 0,
                'tickers_total' => 0,
            ]);
        }

        // Resolve storage dir (module-local)
        $storageDirRel = (string)$this->config['output']['storage_dir'];
        $storageDir = $this->pathFromModule($storageDirRel);
        $this->ensureDir($storageDir);

        // Logs dir (optional)
        $logPath = '';
        if (isset($this->config['output']['log']) && is_string($this->config['output']['log']) && trim($this->config['output']['log']) !== '') {
            $logPath = $this->pathFromModule((string)$this->config['output']['log']);
            $this->ensureDir($this->dirOf($logPath));
        }

        // Categories list
        $categories = $this->asStringList($this->config['sources']['categories']);
        if (count($categories) === 0) {
            $this->errors[] = 'config_categories_empty';
        }

        // 1) Load symbols from Parser1 registry (SystemPaths)
        $symbols = $this->loadActiveSymbols();
        $symbolsTotal = count($symbols);
        
        // CRITICAL: Empty registry = ERROR state (per ТЗ section 5)
        if ($symbolsTotal === 0) {
            $this->errors[] = 'registry_empty_or_unparsed';
            $this->log('ERROR', 'Registry is empty or unparsed. Shape=' . $this->registryShape);
            
            return $this->finish(false, 'config_error', $t0, [
                'ts' => $tsIso,
                'symbols_total' => 0,
                'processed' => 0,
                'written' => 0,
                'skipped' => 0,
                'tickers_total' => 0,
                'registry_shape' => $this->registryShape,
                'registry_debug' => $this->registryDebug,
            ]);
        }

        // 2) Fetch tickers per category (ONE request per category)
        /** @var array<string,array<string,mixed>> $tickerMap */
        $tickerMap = [];
        $tickersTotal = 0;

        foreach ($categories as $category) {
            $resp = $this->fetchTickersBatch($category);

            $list = $resp['result']['list'] ?? null;
            if (!is_array($list)) {
                $this->errors[] = 'bybit_bad_list:' . $category;
                continue;
            }

            $tickersTotal += count($list);

            foreach ($list as $t) {
                if (!is_array($t)) {
                    continue;
                }
                $sym = strtoupper(trim((string)($t['symbol'] ?? '')));
                if ($sym === '') {
                    continue;
                }
                $tickerMap[$sym] = $t; // last wins
            }
        }

        // 3) Append raw snapshots for symbols
        $written = 0;
        $skipped = 0;
        $processed = 0;
        $this->missingInTickers = [];

        $day = date('Y-m-d', $tsUnix);
        foreach ($symbols as $symbol) {
            $processed++;

            if (!isset($tickerMap[$symbol])) {
                $skipped++;
                // Track missing symbols for debugging (limited to avoid large logs)
                if (count($this->missingInTickers) < self::MAX_MISSING_SYMBOLS_DEBUG) {
                    $this->missingInTickers[] = $symbol;
                }
                continue;
            }

            $ticker = $tickerMap[$symbol];

            // Build row with required fields per ТЗ section 9
            $row = [
                'ts' => $tsIso,
                'ts_unix' => $tsUnix,
                'symbol' => $symbol,
                'last_price' => (float)($ticker['lastPrice'] ?? $ticker['last_price'] ?? 0),
                'bid1_price' => (float)($ticker['bid1Price'] ?? $ticker['bid1_price'] ?? 0),
                'ask1_price' => (float)($ticker['ask1Price'] ?? $ticker['ask1_price'] ?? 0),
                'volume24h' => (float)($ticker['volume24h'] ?? 0),
                'turnover24h' => (float)($ticker['turnover24h'] ?? 0),
                'source' => 'bybit_v5_tickers',
                'category' => $this->inferCategoryFromTicker($ticker) ?? '',
                'data' => $ticker, // Keep full raw data for compatibility
            ];

            $symDir = $storageDir . '/' . $symbol;
            $this->ensureDir($symDir);

            $file = $symDir . '/' . $day . '.ndjson';

            $ok = $this->appendNdjsonLine($file, $row);
            if ($ok) {
                $written++;
            } else {
                $this->errors[] = 'write_failed:' . $symbol;
            }
        }

        // 4) Write state + last_run + errors
        $state = [
            'ts' => $tsIso,
            'status' => 'ok',
            'enabled' => true,
            'symbols_total' => $symbolsTotal,
            'processed' => $processed,
            'written' => $written,
            'skipped' => $skipped,
            'tickers_total' => $tickersTotal,
            'registry_shape' => $this->registryShape,
        ];

        $this->writeJson($this->pathFromModule((string)$this->config['output']['state']), $state, (bool)$this->config['write']['atomic']);

        $result = $this->finish(true, 'ok', $t0, [
            'ts' => $tsIso,
            'symbols_total' => $symbolsTotal,
            'processed' => $processed,
            'written' => $written,
            'skipped' => $skipped,
            'tickers_total' => $tickersTotal,
            'missing_in_tickers' => count($this->missingInTickers),
            'missing_symbols' => $this->missingInTickers,
            'registry_shape' => $this->registryShape,
        ]);

        // Write errors.json if any
        if (!empty($this->errors)) {
            $this->writeJson($this->pathFromModule((string)$this->config['output']['errors']), [
                'ts' => $tsIso,
                'errors' => $this->errors,
            ], (bool)$this->config['write']['atomic']);
        }

        // flush log if configured
        if ($logPath !== '') {
            $this->flushLog($logPath);
        }

        return $result;
    }

    /* =========================================================
       CONFIG
       ========================================================= */

    /** @return array<string,mixed> */
    private function loadConfig(): array
    {
        $path = $this->moduleBase . '/config/config.php';
        $cfg = is_file($path) ? require $path : [];
        return is_array($cfg) ? $cfg : [];
    }

    /**
     * Strict config validator: required keys MUST be present.
     * No hidden defaults in code.
     *
     * @param array<string,mixed> $cfg
     * @return array{ok:bool,errors:array<int,string>}
     */
    private function validateConfig(array $cfg): array
    {
        $errors = [];

        // core.enabled
        if (!isset($cfg['core']) || !is_array($cfg['core']) || !array_key_exists('enabled', $cfg['core']) || !is_bool($cfg['core']['enabled'])) {
            $errors[] = 'config_core_enabled_missing_or_invalid';
        }

        // sources
        if (!isset($cfg['sources']) || !is_array($cfg['sources'])) {
            $errors[] = 'config_sources_missing';
        } else {
            if (!isset($cfg['sources']['registry_key']) || !is_string($cfg['sources']['registry_key']) || trim($cfg['sources']['registry_key']) === '') {
                $errors[] = 'config_sources_registry_key_missing';
            }
            if (!isset($cfg['sources']['registry_file']) || !is_string($cfg['sources']['registry_file']) || trim($cfg['sources']['registry_file']) === '') {
                $errors[] = 'config_sources_registry_file_missing';
            }
            if (!isset($cfg['sources']['categories']) || !is_array($cfg['sources']['categories'])) {
                $errors[] = 'config_sources_categories_missing';
            }
        }

        // output
        if (!isset($cfg['output']) || !is_array($cfg['output'])) {
            $errors[] = 'config_output_missing';
        } else {
            foreach (['storage_dir', 'state', 'last_run', 'errors'] as $k) {
                if (!isset($cfg['output'][$k]) || !is_string($cfg['output'][$k]) || trim($cfg['output'][$k]) === '') {
                    $errors[] = 'config_output_' . $k . '_missing';
                }
            }
            // optional output.log
            if (array_key_exists('log', $cfg['output']) && !is_string($cfg['output']['log'])) {
                $errors[] = 'config_output_log_invalid';
            }
        }

        // write.atomic
        if (!isset($cfg['write']) || !is_array($cfg['write'])) {
            $errors[] = 'config_write_missing';
        } else {
            if (!array_key_exists('atomic', $cfg['write']) || !is_bool($cfg['write']['atomic'])) {
                $errors[] = 'config_write_atomic_missing_or_invalid';
            }
        }

        // bybit
        if (!isset($cfg['bybit']) || !is_array($cfg['bybit'])) {
            $errors[] = 'config_bybit_missing';
        } else {
            foreach (['base_url', 'endpoint_tickers', 'timeout_sec', 'connect_timeout_sec', 'max_bytes', 'user_agent'] as $k) {
                if (!isset($cfg['bybit'][$k])) {
                    $errors[] = 'config_bybit_' . $k . '_missing';
                }
            }
        }

        return ['ok' => count($errors) === 0, 'errors' => $errors];
    }

    /* =========================================================
       INPUTS
       ========================================================= */

    /** @return array<int,string> */
    private function loadActiveSymbols(): array
    {
        $sources = $this->config['sources'] ?? [];
        if (!is_array($sources)) {
            $this->errors[] = 'config_sources_missing';
            $this->registryShape = 'config_error';
            return [];
        }

        $registryKey = (string)($sources['registry_key'] ?? '');
        $registryFile = (string)($sources['registry_file'] ?? '');

        $base = (string)SystemPaths::instance()->get($registryKey);
        if ($base === '') {
            $this->errors[] = 'registry_base_empty:' . $registryKey;
            $this->registryShape = 'path_error';
            return [];
        }

        $path = rtrim($base, '/') . '/' . ltrim($registryFile, '/');

        if (!is_file($path)) {
            $this->errors[] = 'registry_file_missing:' . $path;
            $this->registryShape = 'file_missing';
            return [];
        }

        // Store debug info (first 200 chars of raw JSON)
        $rawJson = @file_get_contents($path);
        if (is_string($rawJson)) {
            $this->registryDebug = substr($rawJson, 0, 200);
        }

        $data = $this->readJson($path);
        if (!is_array($data)) {
            $this->errors[] = 'registry_json_invalid';
            $this->registryShape = 'json_invalid';
            return [];
        }

        $syms = [];

        // Format A: ["ROAMUSDT","BTCUSDT","ETHUSDT"] - array of strings
        if (isset($data[0]) && is_string($data[0])) {
            $this->registryShape = 'string_array';
            foreach ($data as $s) {
                $sym = $this->normalizeSymbol((string)$s);
                if ($sym !== '') {
                    $syms[] = $sym;
                }
            }
        }
        // Format B: [{symbol:"ROAMUSDT"},{symbol:"BTCUSDT"}] - array of objects with symbol key
        elseif (isset($data[0]) && is_array($data[0])) {
            $this->registryShape = 'object_array';
            foreach ($data as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $sym = $this->normalizeSymbol((string)($row['symbol'] ?? ''));
                if ($sym !== '') {
                    $syms[] = $sym;
                }
            }
        }
        // Format C: {symbols:["ROAMUSDT","BTCUSDT"]} - object with symbols array
        elseif (isset($data['symbols']) && is_array($data['symbols'])) {
            $this->registryShape = 'symbols_key';
            foreach ($data['symbols'] as $s) {
                if (is_string($s)) {
                    $sym = $this->normalizeSymbol($s);
                } elseif (is_array($s) && isset($s['symbol'])) {
                    $sym = $this->normalizeSymbol((string)$s['symbol']);
                } else {
                    continue;
                }
                if ($sym !== '') {
                    $syms[] = $sym;
                }
            }
        }
        // Format D: {items:[{symbol:"ROAMUSDT"}]} - object with items array
        elseif (isset($data['items']) && is_array($data['items'])) {
            $this->registryShape = 'items_key';
            foreach ($data['items'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $sym = $this->normalizeSymbol((string)($row['symbol'] ?? ''));
                if ($sym !== '') {
                    $syms[] = $sym;
                }
            }
        }
        // Format D (alt): {data:[{symbol:"ROAMUSDT"}]} - object with data array
        elseif (isset($data['data']) && is_array($data['data'])) {
            $this->registryShape = 'data_key';
            foreach ($data['data'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $sym = $this->normalizeSymbol((string)($row['symbol'] ?? ''));
                if ($sym !== '') {
                    $syms[] = $sym;
                }
            }
        }
        // Format E: {"ROAMUSDT":{...},"BTCUSDT":{...}} - associative array keyed by symbol
        elseif (!empty($data) && !array_is_list($data)) {
            $this->registryShape = 'assoc_map';
            foreach ($data as $key => $row) {
                if (is_array($row) && isset($row['symbol'])) {
                    $sym = $this->normalizeSymbol((string)$row['symbol']);
                    if ($sym !== '') {
                        $syms[] = $sym;
                    }
                } elseif (is_string($key) && $key !== '') {
                    // Use the key as symbol if row doesn't have symbol field
                    $sym = $this->normalizeSymbol($key);
                    if ($sym !== '') {
                        $syms[] = $sym;
                    }
                }
            }
        } else {
            $this->registryShape = 'unknown';
            $this->errors[] = 'registry_shape_unknown';
            return [];
        }

        // Apply validation: only valid USDT pair symbols (per ТЗ section 4)
        // Pattern requires: at least 2 alphanumeric chars followed by USDT
        $syms = array_filter($syms, function($s) {
            return preg_match('/^[A-Z0-9]{2,}USDT$/', $s) === 1;
        });

        $syms = array_values(array_unique($syms));
        sort($syms);
        return $syms;
    }

    /**
     * Normalize symbol string: trim whitespace, convert to uppercase.
     * Note: Validation (regex check) is done separately in loadActiveSymbols.
     */
    private function normalizeSymbol(string $s): string
    {
        return strtoupper(trim($s));
    }

    /**
     * Fetch Bybit public tickers batch for given category.
     *
     * @return array<string,mixed>
     */
    private function fetchTickersBatch(string $category): array
    {
        $base = (string)$this->config['bybit']['base_url'];
        $endpoint = (string)$this->config['bybit']['endpoint_tickers'];

        $url = rtrim($base, '/') . $endpoint . '?' . http_build_query([
            'category' => $category,
        ]);

        $timeout = (int)$this->config['bybit']['timeout_sec'];
        $connectTimeout = (int)$this->config['bybit']['connect_timeout_sec'];
        $maxBytes = (int)$this->config['bybit']['max_bytes'];
        $ua = (string)$this->config['bybit']['user_agent'];

        $json = $this->httpGetJson($url, $timeout, $connectTimeout, $maxBytes, $ua);
        if (!is_array($json)) {
            $this->errors[] = 'bybit_http_failed:' . $category;
            return [];
        }

        // minimal retCode check (Bybit V5)
        if (isset($json['retCode']) && (int)$json['retCode'] !== 0) {
            $this->errors[] = 'bybit_retCode:' . $category . ':' . (string)($json['retMsg'] ?? '');
        }

        return $json;
    }

    /**
     * Try infer category from ticker object (best-effort, informational only).
     * @param array<string,mixed> $ticker
     */
    private function inferCategoryFromTicker(array $ticker): ?string
    {
        // Bybit ticker does not always include category; keep empty if unknown
        if (isset($ticker['category']) && is_string($ticker['category']) && $ticker['category'] !== '') {
            return $ticker['category'];
        }
        return null;
    }

    /* =========================================================
       OUTPUTS
       ========================================================= */

    /**
     * Finish execution: write last_run.json and return payload.
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

        $result = array_merge($stats, [
            'ok' => $success,
            'success' => $success,
            'status' => $status,
            'duration_ms' => $durationMs,
            'errors_count' => count($this->errors),
        ]);

        $lastRunPath = $this->pathFromModule((string)$this->config['output']['last_run']);
        $this->writeJson($lastRunPath, $result, (bool)$this->config['write']['atomic']);

        $this->log('INFO', 'Parser2 finished: status=' . $status . ' duration=' . $durationMs . 'ms');

        return $result;
    }

    /**
     * Write JSON (atomic optional).
     *
     * @param mixed $data
     */
    private function writeJson(string $path, $data, bool $atomic): void
    {
        $dir = $this->dirOf($path);
        if ($dir !== '') {
            $this->ensureDir($dir);
        }

        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            $this->errors[] = 'json_encode_failed:' . $path;
            return;
        }

        if ($atomic) {
            $tmp = $path . '.tmp';
            file_put_contents($tmp, $json, LOCK_EX);
            rename($tmp, $path);
            return;
        }

        file_put_contents($path, $json, LOCK_EX);
    }

    /**
     * Append one NDJSON line safely.
     * @param array<string,mixed> $row
     */
    private function appendNdjsonLine(string $path, array $row): bool
    {
        $json = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }

        $line = $json . "\n";
        $bytes = @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);

        return is_int($bytes) && $bytes > 0;
    }

    /* =========================================================
       HTTP
       ========================================================= */

    /**
     * HTTP GET and decode JSON (public endpoint).
     *
     * @return array<string,mixed>|null
     */
    private function httpGetJson(string $url, int $timeoutSec, int $connectTimeoutSec, int $maxBytes, string $userAgent): ?array
    {
        $ch = curl_init();
        if ($ch === false) {
            $this->errors[] = 'curl_init_failed';
            return null;
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeoutSec);
        curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_ENCODING, '');

        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if (!is_string($raw) || $raw === '') {
            $this->errors[] = 'http_empty:' . $httpCode . ':' . $err;
            return null;
        }

        // max bytes safety
        if ($maxBytes > 0 && strlen($raw) > $maxBytes) {
            $this->errors[] = 'http_too_big:' . strlen($raw);
            $raw = substr($raw, 0, $maxBytes);
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->errors[] = 'json_decode_failed:' . $httpCode;
            return null;
        }

        return $data;
    }

    /* =========================================================
       HELPERS
       ========================================================= */

    private function pathFromModule(string $relative): string
    {
        $rel = trim($relative);
        if ($rel === '') {
            return $this->moduleBase;
        }
        return $this->moduleBase . '/' . ltrim($rel, '/');
    }

    /** @param array<int|string,mixed> $value */
    private function asStringList(array $value): array
    {
        $out = [];
        foreach ($value as $v) {
            if (!is_string($v)) {
                continue;
            }
            $s = trim($v);
            if ($s !== '') {
                $out[] = $s;
            }
        }
        return array_values(array_unique($out));
    }

    /** @return array<string,mixed>|array<int,mixed>|null */
    private function readJson(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private function ensureDir(string $dir): void
    {
        if ($dir === '') {
            return;
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    private function dirOf(string $path): string
    {
        $p = str_replace('\\', '/', $path);
        $pos = strrpos($p, '/');
        if ($pos === false) {
            return '';
        }
        return substr($p, 0, $pos);
    }

    private function log(string $level, string $message): void
    {
        $ts = date('Y-m-d H:i:s');
        $this->logLines[] = '[' . $ts . '] [' . $level . '] ' . $message;
    }

    private function flushLog(string $path): void
    {
        if (empty($this->logLines)) {
            return;
        }
        $data = implode("\n", $this->logLines) . "\n";
        @file_put_contents($path, $data, FILE_APPEND | LOCK_EX);
        $this->logLines = [];
    }
}

/*
RULES (Tredercopis Architecture)

SystemPaths ONLY.
No filesystem guessing.
No writes outside module storage.
Append-only NDJSON history.
PHP 8.2+ safe (no dynamic properties).
*/
