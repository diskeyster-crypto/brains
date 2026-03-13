<?php
declare(strict_types=1);

/**
 * Step 7 — EXECUTOR LIVE (FINAL)
 *
 * Reads built_file
 * Sets leverage (from config) with safe skip-if-already-set
 * Sends REAL order to Bybit
 * Logs EVERYTHING
 *
 * IMPORTANT:
 * - Position list check is best-effort (must NOT block trading if Bybit returns 401/empty).
 * - set-leverage retCode 110043 ("leverage not modified") is treated as SUCCESS.
 */

require_once dirname(__DIR__, 4) . '/core/bybit.php';

use Core\Bybit;

if (!function_exists('executor_bot_load_config')) {
    /**
     * @return array<string,mixed>
     */
    function executor_bot_load_config(): array
    {
        $cfgPath = __DIR__ . '/../config/config.php';
        $cfg = is_file($cfgPath) ? require $cfgPath : [];
        return is_array($cfg) ? $cfg : [];
    }
}

/**
 * @param mixed $v
 */
function step7_safe_int($v, int $default = 0): int
{
    if (is_int($v)) return $v;
    if (is_numeric($v)) return (int)$v;
    return $default;
}

/**
 * @param mixed $v
 */
function step7_safe_str($v, string $default = ''): string
{
    if (is_string($v)) return $v;
    if (is_numeric($v)) return (string)$v;
    return $default;
}

/**
 * @param mixed $v
 */
function step7_safe_float($v, float $default = 0.0): float
{
    if (is_float($v)) return $v;
    if (is_numeric($v)) return (float)$v;
    return $default;
}

/**
 * Normalizes order side for Bybit.
 * Accepts: long/short, buy/sell, Buy/Sell.
 *
 * @param array<string,mixed> $order
 * @return array<string,mixed>
 */
function step7_normalize_order_side(array $order): array
{
    if (!isset($order['side'])) {
        return $order;
    }

    $side = strtolower((string)$order['side']);

    if ($side === 'long' || $side === 'buy') {
        $order['side'] = 'Buy';
    } elseif ($side === 'short' || $side === 'sell') {
        $order['side'] = 'Sell';
    } else {
        // leave as-is, but string
        $order['side'] = (string)$order['side'];
    }

    return $order;
}

/**
 * @param array<string,mixed> $resp
 */
function step7_is_bybit_ok(array $resp): bool
{
    return (int)($resp['retCode'] ?? ($resp['ret_code'] ?? 1)) === 0;
}

/**
 * Step 7 entry
 *
 * @return array<string,mixed>
 */
function step_7_executor_live(string $builtFile): array
{
    $ts = date('c');

    $log = [
        'ts' => $ts,
        'phase' => 'step_7_executor_live',
        'built_file' => $builtFile,
        'errors' => [],
        'debug' => [],
    ];

    if ($builtFile === '' || !is_file($builtFile)) {
        return [
            'success' => false,
            'step' => 'executor_live',
            'intent_id' => null,
            'built_file' => $builtFile,
            'output_file' => null,
            'error' => 'built_file_not_found',
            'log' => $log,
        ];
    }

    $task = json_decode((string)file_get_contents($builtFile), true);
    if (!is_array($task)) {
        return [
            'success' => false,
            'step' => 'executor_live',
            'intent_id' => null,
            'built_file' => $builtFile,
            'output_file' => null,
            'error' => 'built_file_invalid_json',
            'log' => $log,
        ];
    }

    $log['debug']['built_payload'] = $task;

    $intentId = step7_safe_str($task['intent_id'] ?? '');
    $order    = $task['order'] ?? [];

    if ($intentId === '' || !is_array($order)) {
        return [
            'success' => false,
            'step' => 'executor_live',
            'intent_id' => $intentId !== '' ? $intentId : null,
            'built_file' => $builtFile,
            'output_file' => null,
            'error' => 'invalid_built_contract',
            'log' => $log,
        ];
    }

    // Normalize order basic fields
    $order = step7_normalize_order_side($order);

    $category = step7_safe_str($order['category'] ?? 'linear', 'linear');
    $symbol   = strtoupper(step7_safe_str($order['symbol'] ?? ''));
    $qtyStr   = step7_safe_str($order['qty'] ?? '');

    if ($symbol === '' || $qtyStr === '') {
        return [
            'success' => false,
            'step' => 'executor_live',
            'intent_id' => $intentId,
            'built_file' => $builtFile,
            'output_file' => null,
            'error' => 'order_missing_symbol_or_qty',
            'log' => $log,
        ];
    }

    // Load config leverage
    $cfg = executor_bot_load_config();
    $limits = is_array($cfg['limits'] ?? null) ? (array)$cfg['limits'] : [];
    $configLeverage = step7_safe_int($limits['leverage'] ?? 0, 0);

    // If config leverage is invalid, do NOT block trading (just don't set leverage)
    if ($configLeverage < 1) {
        $configLeverage = 0;
    }

    $log['debug']['config_leverage'] = $configLeverage;

    try {
        // Create client
        $client = Bybit::client();
        if (!$client) {
            throw new RuntimeException('Bybit client is NULL');
        }

        // ===============================
        // LEVERAGE SET (safe)
        // ===============================
        $currentLeverage = null;

        if ($configLeverage > 0) {
            // 1) Try to read current leverage (best-effort)
            try {
                $posListParams = [
                    'category' => $category,
                    'symbol' => $symbol,
                ];

                $posResp = $client->get('/v5/position/list', $posListParams);
                $log['debug']['api_position_list'] = $posResp;

                if (is_array($posResp) && step7_is_bybit_ok($posResp)) {
                    // Extract current leverage from response (structure may vary by wrapper)
                    $result = $posResp['result'] ?? null;
                    if (is_array($result)) {
                        $list = $result['list'] ?? $result['positions'] ?? null;

                        if (is_array($list)) {
                            // find first position row
                            $row0 = $list[0] ?? null;
                            if (is_array($row0)) {
                                // Bybit can return "leverage" as string
                                $levStr = $row0['leverage'] ?? null;
                                if ($levStr !== null && $levStr !== '') {
                                    $currentLeverage = step7_safe_int($levStr, 0);
                                    if ($currentLeverage < 1) {
                                        $currentLeverage = null;
                                    }
                                }
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                // best-effort only
                $log['debug']['position_list_exception'] = $e->getMessage();
                $currentLeverage = null;
            }

            $log['debug']['current_leverage'] = $currentLeverage;

            // 2) If already set -> skip set-leverage
            $needSetLeverage = true;
            if ($currentLeverage !== null && $currentLeverage === $configLeverage) {
                $needSetLeverage = false;
                $log['debug']['leverage_action'] = 'skip_already_set';
            } else {
                $log['debug']['leverage_action'] = 'set_request';
            }

            // 3) Set leverage if needed
            if ($needSetLeverage) {
                $payloadLev = [
                    'category' => $category,
                    'symbol' => $symbol,
                    'buyLeverage' => (string)$configLeverage,
                    'sellLeverage' => (string)$configLeverage,
                ];

                $log['debug']['api_set_leverage_payload'] = $payloadLev;

                $levResp = $client->post('/v5/position/set-leverage', $payloadLev);
                $log['debug']['api_set_leverage_response'] = $levResp;

                $retCode = (int)($levResp['retCode'] ?? ($levResp['ret_code'] ?? 1));

                // retCode 0 => OK
                // retCode 110043 => "leverage not modified" => ALREADY SET => OK
                if ($retCode !== 0 && $retCode !== 110043) {
                    return [
                        'success' => false,
                        'step' => 'executor_live',
                        'intent_id' => $intentId,
                        'built_file' => $builtFile,
                        'output_file' => null,
                        'error' => 'bybit_set_leverage_failed',
                        'log' => $log,
                    ];
                }
            }
        } else {
            $log['debug']['leverage_action'] = 'skip_config_disabled';
        }

        // ===============================
        // ORDER SEND
        // ===============================
        $log['debug']['api_order_payload_normalized'] = $order;

        $response = $client->post('/v5/order/create', $order);
        $log['debug']['api_raw_response'] = $response;

        $retCode = (int)($response['retCode'] ?? ($response['ret_code'] ?? 1));
        if ($retCode !== 0) {
            return [
                'success' => false,
                'step' => 'executor_live',
                'intent_id' => $intentId,
                'built_file' => $builtFile,
                'output_file' => null,
                'error' => 'bybit_rejected',
                'log' => $log,
            ];
        }

    } catch (Throwable $e) {
        $log['errors'][] = $e->getMessage();

        return [
            'success' => false,
            'step' => 'executor_live',
            'intent_id' => $intentId,
            'built_file' => $builtFile,
            'output_file' => null,
            'error' => 'bybit_exception',
            'log' => $log,
        ];
    }

    // ===============================
    // SAVE SNAPSHOT
    // ===============================

    $outDir = dirname(__DIR__) . '/storage/live';
    if (!is_dir($outDir)) {
        @mkdir($outDir, 0777, true);
    }

    $outFile = $outDir . '/live_' . $intentId . '.json';

    $snapshot = [
        'ts' => $ts,
        'intent_id' => $intentId,
        'status' => 'sent',
        'exchange' => 'bybit',
        'mode' => 'live',
        'request' => $order,
        'response' => $response,
        'log' => $log,
    ];

    file_put_contents(
        $outFile,
        json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    return [
        'success' => true,
        'step' => 'executor_live',
        'intent_id' => $intentId,
        'built_file' => $builtFile,
        'output_file' => $outFile,
        'error' => null,
        'log' => $log,
    ];
}

/*
====================================================================
RULES (Tredercopis)
====================================================================
- Step 7 reads built_{intent_id}.json and sends REAL order to Bybit.
- Leverage is taken ONLY from config: config['limits']['leverage'].
- Position list check is BEST-EFFORT (must not block trading on 401/empty).
- set-leverage retCode 110043 ("leverage not modified") is treated as SUCCESS.
- If set-leverage fails with other retCode => bybit_set_leverage_failed.
- No hardcoded secrets; uses Core\Bybit key center.
====================================================================
*/
