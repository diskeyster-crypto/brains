<?php
declare(strict_types=1);

/**
 * Executor Bot — Runner
 *
 * Reads Parser5 signals and (optionally) executes orders on Bybit.
 *
 * CONFIG FIRST / ZERO HARDCODE
 * SystemPaths ONLY
 *
 * Entry points:
 * - cron: modules/trading/executor_bot/cron_handler.php
 * - manual: call executor_runner.php directly
 */

use Core\System\SystemPaths;

// ----------------------------------------------------------
// Minimal core wiring (NO heavy bootstrap)
// ----------------------------------------------------------

$root = dirname(__DIR__, 3);

// Core root resolver (safe include)
$rootFile = $root . '/core/system/root.php';
if (is_file($rootFile)) {
    require_once $rootFile;
}

// SystemPaths (expected available in Core)
$systemPathsFile = $root . '/core/system/systempaths.php';
if (is_file($systemPathsFile)) {
    require_once $systemPathsFile;
}

if (!class_exists(SystemPaths::class)) {
    echo json_encode([
        'success' => false,
        'message' => 'SystemPaths not available',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// Centralized Bybit key center (optional: only needed for live mode)
$bybitFile = $root . '/core/bybit.php';
if (is_file($bybitFile)) {
    require_once $bybitFile;
}

/**
 * @return array<string,mixed>
 */
function executor_bot_run(): array
{
    $t0 = microtime(true);
    $ts = date('c');

    $paths = SystemPaths::instance();

    $cfg = executor_bot_load_config($paths);
    $moduleBase = executor_bot_module_base($paths, $cfg);

    $out = (array)($cfg['output'] ?? []);
    $logFileRel = (string)($out['log_file'] ?? 'storage/logs/executor.log');

    $log = [];
    $errors = [];

    $enabled = (bool)($cfg['module']['enabled'] ?? false);
    if (!$enabled) {
        $result = [
            'ts' => $ts,
            'ok' => false,
            'success' => false,
            'status' => 'disabled',
            'signals_seen' => 0,
            'signals_processed' => 0,
            'opened_ok' => 0,
            'failed_orders' => 0,
            'errors_count' => 0,
            'errors' => [],
        ];

        executor_bot_write_json($moduleBase, (string)($out['last_run'] ?? 'storage/last_run.json'), $result, (array)($cfg['write'] ?? []));
        $result['duration_ms'] = (int)round((microtime(true) - $t0) * 1000);

        return $result;
    }

    // Load signals
    $signalsPath = executor_bot_signals_path($paths, $cfg, $errors);
    $signalsDoc = executor_bot_read_json($signalsPath);
    $signals = is_array($signalsDoc['signals'] ?? null) ? $signalsDoc['signals'] : [];

    // Load executed index
    $executedRel = (string)($out['executed'] ?? 'storage/executed.json');
    $executedPath = $moduleBase . '/' . $executedRel;
    $executed = executor_bot_read_json($executedPath);
    if (!is_array($executed)) {
        $executed = [];
    }

    $limits = (array)($cfg['limits'] ?? []);
    $maxPerRun = (int)($limits['max_signals_per_run'] ?? 3);
    if ($maxPerRun < 0) {
        $maxPerRun = 0;
    }

    $minScore = (float)($limits['min_score'] ?? 0.0);
    $blocked = (array)($limits['blocked_symbols'] ?? []);
    $enforceExp = (bool)($limits['enforce_expires_at'] ?? true);
    $now = time();

    $mode = (string)($cfg['module']['mode'] ?? 'dry');
    $accountId = (string)($cfg['module']['account_id'] ?? 'default');
    $category = (string)($cfg['module']['category'] ?? 'linear');

    $signalsSeen = count($signals);
    $processed = 0;
    $openedOk = 0;
    $failed = 0;

    foreach ($signals as $sig) {
        if (!is_array($sig)) {
            continue;
        }

        $id = (string)($sig['id'] ?? '');
        $symbol = (string)($sig['symbol'] ?? '');
        $status = (string)($sig['status'] ?? '');
        $score = (float)($sig['score'] ?? 0.0);
        $expiresAt = (int)($sig['expires_at'] ?? 0);

        if ($processed >= $maxPerRun) {
            break;
        }

        if ($id === '' || $symbol === '') {
            continue;
        }

        if ($status !== 'active') {
            continue;
        }

        if ($score < $minScore) {
            continue;
        }

        if (in_array($symbol, $blocked, true)) {
            continue;
        }

        if ($enforceExp && $expiresAt > 0 && $expiresAt <= $now) {
            continue;
        }

        if (isset($executed[$id])) {
            // already processed
            continue;
        }

        $processed++;

        // Build order params
        $orderReq = executor_bot_build_order_request($cfg, $sig, $category);

        if ($mode !== 'live') {
            // DRY MODE: store request only
            $executed[$id] = [
                'ts' => $ts,
                'mode' => 'dry',
                'symbol' => $symbol,
                'status' => 'queued',
                'request' => $orderReq,
            ];
            executor_bot_write_order_debug($moduleBase, $out, $id, [
                'ts' => $ts,
                'mode' => 'dry',
                'signal' => $sig,
                'request' => $orderReq,
            ], (array)($cfg['write'] ?? []));
            $openedOk++;
            continue;
        }

        // LIVE MODE: send to Bybit
        $resp = executor_bot_send_order($orderReq, $accountId);

        $ok = (bool)($resp['success'] ?? false);
        if ($ok) {
            $openedOk++;
        } else {
            $failed++;
        }

        $executed[$id] = [
            'ts' => $ts,
            'mode' => 'live',
            'symbol' => $symbol,
            'status' => $ok ? 'opened' : 'failed',
            'request' => $orderReq,
            'response' => $resp,
        ];

        executor_bot_write_order_debug($moduleBase, $out, $id, $executed[$id], (array)($cfg['write'] ?? []));
    }

    // Persist executed index
    executor_bot_write_json($moduleBase, $executedRel, $executed, (array)($cfg['write'] ?? []));

    $result = [
        'ts' => $ts,
        'ok' => empty($errors) && ($failed === 0),
        'success' => empty($errors) && ($failed === 0),
        'status' => empty($errors) ? ($failed === 0 ? 'ok' : 'ok_with_errors') : 'config_error',
        'signals_seen' => $signalsSeen,
        'signals_processed' => $processed,
        'opened_ok' => $openedOk,
        'failed_orders' => $failed,
        'errors_count' => count($errors),
        'errors' => $errors,
    ];

    executor_bot_write_json($moduleBase, (string)($out['last_run'] ?? 'storage/last_run.json'), $result, (array)($cfg['write'] ?? []));

    // Log line (best effort)
    executor_bot_append_log($moduleBase, $logFileRel, sprintf(
        '[%s] mode=%s signals=%d processed=%d opened=%d failed=%d errors=%d',
        $ts,
        $mode,
        $signalsSeen,
        $processed,
        $openedOk,
        $failed,
        count($errors)
    ));

    $result['duration_ms'] = (int)round((microtime(true) - $t0) * 1000);

    return $result;
}

/**
 * @return array<string,mixed>
 */
function executor_bot_load_config(SystemPaths $paths): array
{
    // Resolve module base via configured module_key if possible; fallback to local __DIR__
    $cfgPath = __DIR__ . '/config/config.php';
    $cfg = is_file($cfgPath) ? (require $cfgPath) : [];
    return is_array($cfg) ? $cfg : [];
}

function executor_bot_module_base(SystemPaths $paths, array $cfg): string
{
    $key = (string)($cfg['sources']['module_key'] ?? 'trading.executor_bot');

    // Support both full key and base key without ".storage"
    $candidates = [$key, $key . '.storage'];

    foreach ($candidates as $k) {
        try {
            $p = (string)$paths->get($k);
            if ($p !== '') {
                return rtrim($p, '/');
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    // Final fallback: module dir
    return rtrim(__DIR__, '/');
}

function executor_bot_signals_path(SystemPaths $paths, array $cfg, array &$errors): string
{
    $key = (string)($cfg['sources']['signals_key'] ?? '');
    $file = (string)($cfg['sources']['signals_file'] ?? 'signals.json');

    if ($key === '') {
        $errors[] = 'signals_key_missing';
        return '';
    }

    try {
        $base = (string)$paths->get($key);
    } catch (Throwable $e) {
        $errors[] = 'signals_key_unknown';
        return '';
    }

    $base = rtrim($base, '/');
    return $base . '/' . ltrim($file, '/');
}

/**
 * @return mixed
 */
function executor_bot_read_json(string $path)
{
    if ($path === '' || !is_file($path)) {
        return [];
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return [];
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function executor_bot_append_log(string $moduleBase, string $rel, string $line): void
{
    $path = $moduleBase . '/' . ltrim($rel, '/');
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($path, $line . "\n", FILE_APPEND);
}

function executor_bot_write_order_debug(string $moduleBase, array $out, string $id, array $payload, array $write): void
{
    $ordersDir = (string)($out['orders'] ?? 'storage/orders');
    $rel = rtrim($ordersDir, '/') . '/' . $id . '.json';
    executor_bot_write_json($moduleBase, $rel, $payload, $write);
}

function executor_bot_write_json(string $moduleBase, string $rel, $data, array $write): void
{
    $path = $moduleBase . '/' . ltrim($rel, '/');
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if (!is_string($json)) {
        return;
    }

    $atomic = (bool)($write['atomic'] ?? true);
    if (!$atomic) {
        @file_put_contents($path, $json . "\n");
        return;
    }

    $tmp = $path . '.tmp';
    @file_put_contents($tmp, $json . "\n");
    @rename($tmp, $path);
}

/**
 * @return array<string,mixed>
 */
function executor_bot_build_order_request(array $cfg, array $sig, string $category): array
{
    $limits = (array)($cfg['limits'] ?? []);
    $orderCfg = (array)($cfg['order'] ?? []);

    $symbol = (string)($sig['symbol'] ?? '');
    $side = strtolower((string)($sig['side'] ?? ''));
    $entry = (float)($sig['entry_price'] ?? 0.0);
    $tp = (float)($sig['take_profit'] ?? 0.0);
    $sl = (float)($sig['stop_loss'] ?? 0.0);

    $budget = (float)($limits['budget_per_position_usd'] ?? 8.0);
    $leverage = (int)($limits['leverage'] ?? 10);
    if ($leverage < 1) {
        $leverage = 1;
    }

    // naive qty estimation (best effort): qty ~= (budget * leverage) / entry
    $qty = 0.0;
    if ($entry > 0) {
        $qty = ($budget * $leverage) / $entry;
    }

    $type = (string)($orderCfg['type'] ?? 'market');
    $type = in_array($type, ['market', 'limit'], true) ? $type : 'market';

    $price = null;
    if ($type === 'limit' && $entry > 0) {
        $offset = (float)($orderCfg['limit_offset'] ?? 0.0);
        if ($offset < 0) {
            $offset = 0.0;
        }
        $price = $side === 'short' ? ($entry * (1 + $offset)) : ($entry * (1 - $offset));
    }

    return [
        'category' => $category,
        'symbol' => $symbol,
        'side' => $side === 'short' ? 'Sell' : 'Buy',
        'orderType' => $type === 'limit' ? 'Limit' : 'Market',
        'qty' => $qty > 0 ? $qty : null,
        'price' => $price,
        'takeProfit' => $tp > 0 ? $tp : null,
        'stopLoss' => $sl > 0 ? $sl : null,
    ];
}

/**
 * @return array<string,mixed>
 */
function executor_bot_send_order(array $req, string $accountId): array
{
    // Uses centralized Core\Bybit::client($accountId)
    if (!class_exists('Core\\Bybit')) {
        return [
            'success' => false,
            'error' => 'Core\\Bybit not available',
        ];
    }

    try {
        /** @var object $client */
        $client = Core\Bybit::client($accountId);
        if (!$client) {
            return [
                'success' => false,
                'error' => 'bybit_client_null',
            ];
        }

        // Most Core\Bybit clients expose ->post($path, $params)
        $path = '/v5/order/create';

        $params = [];
        foreach (['category','symbol','side','orderType','qty','price','takeProfit','stopLoss'] as $k) {
            if (array_key_exists($k, $req) && $req[$k] !== null && $req[$k] !== '') {
                $params[$k] = $req[$k];
            }
        }

        if (empty($params['qty'])) {
            return [
                'success' => false,
                'error' => 'qty_missing',
            ];
        }

        $resp = $client->post($path, $params);

        return [
            'success' => true,
            'raw' => $resp,
        ];
    } catch (Throwable $e) {
        return [
            'success' => false,
            'error' => 'exception',
            'exception' => $e->getMessage(),
        ];
    }
}

$result = executor_bot_run();

echo json_encode([
    'success' => (bool)($result['success'] ?? false),
    'message' => 'OK',
    'result' => $result,
    'duration_ms' => (int)($result['duration_ms'] ?? 0),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

/* RULES
- SystemPaths ONLY
- Reads parser5 signals.json
- Writes only to executor_bot storage
- No hardcoded absolute paths
*/
