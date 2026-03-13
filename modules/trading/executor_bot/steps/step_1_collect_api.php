<?php
declare(strict_types=1);

/**
 * Executor Bot — Step 1: API State Recorder
 *
 * FACTS ONLY
 * - Centralized Bybit access via Core\Bybit
 * - Stores market snapshot + (optional) static exchange limits
 * - Writes accumulator/{symbol}.json (symbol key is lowercase)
 *
 * IMPORTANT:
 * - CONFIG FIRST: all paths from config/config.php
 * - NO bootstrap
 * - NO env keys
 */

/* ==========================================================
   CORE WIRING (STRICT)
   ========================================================== */

$root = dirname(__DIR__, 4);

require_once $root . '/core/system/root.php';
require_once $root . '/core/bybit.php';

use Core\Bybit;

/* ==========================================================
   SHARED HELPERS (executor_bot)
   ========================================================== */

if (!function_exists('executor_bot_site_root')) {
    function executor_bot_site_root(): string
    {
        return dirname(__DIR__, 4);
    }
}

if (!function_exists('executor_bot_load_config')) {
    function executor_bot_load_config(): array
    {
        $cfgPath = dirname(__DIR__) . '/config/config.php';
        $cfg = is_file($cfgPath) ? require $cfgPath : [];
        return is_array($cfg) ? $cfg : [];
    }
}

if (!function_exists('executor_bot_fs_path')) {
    function executor_bot_fs_path(string $pathRaw): string
    {
        $pathRaw = trim($pathRaw);
        if ($pathRaw === '') {
            return '';
        }

        if (is_dir($pathRaw) || is_file($pathRaw)) {
            return $pathRaw;
        }

        $root = executor_bot_site_root();
        return ($pathRaw[0] === '/') ? ($root . $pathRaw) : ($root . '/' . $pathRaw);
    }
}

if (!function_exists('executor_bot_ensure_dir')) {
    function executor_bot_ensure_dir(string $dir): bool
    {
        if ($dir === '') {
            return false;
        }
        if (is_dir($dir)) {
            return true;
        }
        return @mkdir($dir, 0777, true) || is_dir($dir);
    }
}

/* ==========================================================
   SMALL UTILS
   ========================================================== */

/**
 * @param array<string,mixed> $arr
 * @param array<int,string> $path
 * @param mixed $default
 * @return mixed
 */
function executor_arr_get(array $arr, array $path, $default = null)
{
    $cur = $arr;
    foreach ($path as $k) {
        if (!is_array($cur) || !array_key_exists($k, $cur)) {
            return $default;
        }
        $cur = $cur[$k];
    }
    return $cur;
}

function executor_to_float_or_null($v): ?float
{
    if ($v === null || $v === '' || $v === false) {
        return null;
    }
    return (float)$v;
}

function executor_to_int_or_null($v): ?int
{
    if ($v === null || $v === '' || $v === false) {
        return null;
    }
    return (int)$v;
}

/* ==========================================================
   MAIN
   ========================================================== */

/**
 * @param array<string,mixed> $step0
 * @return array<string,mixed>
 */
function step_1_collect_api(array $step0): array
{
    $ts = date('c');

    /* ------------------------------------------------------
       STEP 0 GUARD
       ------------------------------------------------------ */
    if (($step0['success'] ?? false) !== true || ($step0['status'] ?? '') !== 'prepared') {
        return [
            'success' => true,
            'step' => 'collect_api',
            'status' => 'skipped',
            'symbol' => null,
            'accumulator_path' => null,
            'error' => 'step_0_not_prepared',
        ];
    }

    $order = $step0['order'] ?? [];
    $meta = $step0['meta'] ?? [];

    $symbol = strtoupper((string)($order['symbol'] ?? ''));
    if ($symbol === '') {
        return [
            'success' => false,
            'step' => 'collect_api',
            'status' => 'error',
            'symbol' => null,
            'accumulator_path' => null,
            'error' => 'symbol_missing',
        ];
    }

    $category = (string)($order['category'] ?? 'linear');
    $accountId = (string)($step0['account_id'] ?? 'default');

    $side = strtolower((string)($meta['side'] ?? ''));
    if ($side !== 'long' && $side !== 'short') {
        $side = '';
    }

    /* ------------------------------------------------------
       BYBIT CLIENT
       ------------------------------------------------------ */
    $client = Bybit::client($accountId);
    if ($client === null) {
        return [
            'success' => false,
            'step' => 'collect_api',
            'status' => 'error',
            'symbol' => $symbol,
            'accumulator_path' => null,
            'error' => 'bybit_client_not_available',
        ];
    }

    /* ------------------------------------------------------
       STORAGE (CONFIG FIRST)
       ------------------------------------------------------ */
    $cfg = executor_bot_load_config();
    $paths = $cfg['paths'] ?? [];

    $accRaw = (string)($paths['step_1_output'] ?? '');
    if ($accRaw === '') {
        return [
            'success' => false,
            'step' => 'collect_api',
            'status' => 'error',
            'symbol' => $symbol,
            'accumulator_path' => null,
            'error' => 'step_1_output_not_defined',
        ];
    }

    $storageDir = executor_bot_fs_path($accRaw);
    if (!executor_bot_ensure_dir($storageDir)) {
        return [
            'success' => false,
            'step' => 'collect_api',
            'status' => 'error',
            'symbol' => $symbol,
            'accumulator_path' => null,
            'error' => 'cannot_create_accumulator_dir: ' . $storageDir,
        ];
    }

    $file = rtrim($storageDir, '/') . '/' . strtolower($symbol) . '.json';
    $isNew = !is_file($file);

    /* ------------------------------------------------------
       LIGHT API — TICKER
       ------------------------------------------------------ */
    // NOTE: Core\Bybit client in this project uses methods:
    // - getMarketTickers([...])
    // - getInstrumentsInfo([...])
    $ticker = $client->getMarketTickers([
        'category' => $category,
        'symbol' => $symbol,
    ]);

    if (!is_array($ticker) || !($ticker['success'] ?? false)) {
        $msg = is_array($ticker) ? (string)($ticker['ret_msg'] ?? 'ticker_failed') : 'ticker_failed';
        return [
            'success' => false,
            'step' => 'collect_api',
            'status' => 'error',
            'symbol' => $symbol,
            'accumulator_path' => null,
            'error' => $msg,
        ];
    }

    $t = $ticker['result']['list'][0] ?? [];
    if (!is_array($t)) {
        $t = [];
    }

    $price = (float)($t['lastPrice'] ?? 0);
    $mark = (float)($t['markPrice'] ?? 0);
    $index = (float)($t['indexPrice'] ?? 0);
    $oi = (float)($t['openInterest'] ?? 0);
    $bid = isset($t['bid1Price']) ? (float)$t['bid1Price'] : null;
    $ask = isset($t['ask1Price']) ? (float)$t['ask1Price'] : null;

    /* ------------------------------------------------------
       HEAVY API — ONLY WHEN NEW
       ------------------------------------------------------ */
    $staticFromApi = [];
    if ($isNew) {
        $info = $client->getInstrumentsInfo([
            'category' => $category,
            'symbol' => $symbol,
        ]);

        if (is_array($info) && ($info['success'] ?? false)) {
            $inst = $info['result']['list'][0] ?? [];
            if (!is_array($inst)) {
                $inst = [];
            }

            $staticFromApi = [
                'category' => $category,
                'min_order_qty' => executor_to_float_or_null(executor_arr_get($inst, ['lotSizeFilter', 'minOrderQty'])) ?? 0.0,
                'max_leverage' => executor_to_int_or_null(executor_arr_get($inst, ['leverageFilter', 'maxLeverage'])) ?? 1,
                'tick_size' => executor_to_float_or_null(executor_arr_get($inst, ['priceFilter', 'tickSize'])),
                'qty_step' => executor_to_float_or_null(executor_arr_get($inst, ['lotSizeFilter', 'qtyStep'])),
                'base_coin' => $inst['baseCoin'] ?? null,
                'quote_coin' => $inst['quoteCoin'] ?? null,
            ];
        }
    }

    /* ------------------------------------------------------
       LOAD / INIT ACCUMULATOR
       ------------------------------------------------------ */
    if ($isNew) {
        $data = [
            'symbol' => $symbol,
            'static' => $staticFromApi,
            'dynamic' => [],
            'price_history' => [],
            'meta' => array_merge(is_array($meta) ? $meta : [], [
                'side' => $side,
            ]),
        ];
    } else {
        $data = json_decode((string)@file_get_contents($file), true);
        if (!is_array($data)) {
            $data = [];
        }
        if (!isset($data['meta']) || !is_array($data['meta'])) {
            $data['meta'] = [];
        }
        $data['meta']['side'] = $side;
    }

    /* ------------------------------------------------------
       UPDATE DYNAMIC
       ------------------------------------------------------ */
    $data['dynamic'] = [
        'price' => $price,
        'mark_price' => $mark,
        'index_price' => $index,
        'open_interest' => $oi,
        'bid_price' => $bid,
        'ask_price' => $ask,
        'updated_at' => $ts,
    ];

    if (!isset($data['price_history']) || !is_array($data['price_history'])) {
        $data['price_history'] = [];
    }

    $data['price_history'][] = [
        'ts' => $ts,
        'price' => $price,
        'mark' => $mark,
        'index' => $index,
        'open_interest' => $oi,
    ];

    /* ------------------------------------------------------
       SAVE
       ------------------------------------------------------ */
    $ok = file_put_contents(
        $file,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    if ($ok === false) {
        return [
            'success' => false,
            'step' => 'collect_api',
            'status' => 'error',
            'symbol' => $symbol,
            'accumulator_path' => null,
            'error' => 'cannot_write_accumulator',
        ];
    }

    return [
        'success' => true,
        'step' => 'collect_api',
        'status' => 'collected',
        'symbol' => $symbol,
        'accumulator_path' => $file,
        'error' => null,
    ];
}

/*
====================================================================
RULES (Tredercopis)
====================================================================
- Step 1 uses ONLY config paths (paths.step_1_output)
- No bootstrap, no env keys
- FACT recorder only (no trading decisions)
- SYMBOL FOR STORAGE = lowercase filename
- LF only
====================================================================
*/
