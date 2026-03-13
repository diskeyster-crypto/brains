<?php
declare(strict_types=1);

/**
 * Executor Bot — Step 3: Calculator (ESTIMATOR MODE)
 *
 * REAL MODEL:
 * - We estimate qty from desired notional (budget * leverage)
 * - We NEVER try to control real margin, only predict
 * - Exchange rules (min notional / step) are respected
 */

/* ==========================================================
   CONFIG
   ========================================================== */

if (!function_exists('executor_bot_load_config')) {
    function executor_bot_load_config(): array
    {
        $cfgPath = __DIR__ . '/../config/config.php';
        $cfg = is_file($cfgPath) ? require $cfgPath : [];
        return is_array($cfg) ? $cfg : [];
    }
}

/**
 * @param string $intentId
 * @return array<string,mixed>
 */
function step_3_calculator(string $intentId): array
{
    $ts = date('c');
    $intentId = trim($intentId);

    if ($intentId === '') {
        return [
            'success' => false,
            'step' => 'calculator',
            'intent_id' => '',
            'symbol' => null,
            'output_file' => null,
            'error' => 'empty_intent_id',
        ];
    }

    $cfg = executor_bot_load_config();
    $paths  = (array)($cfg['paths'] ?? []);
    $limits = (array)($cfg['limits'] ?? []);

    if (!function_exists('executor_bot_fs_path')) {
        return [
            'success' => false,
            'step' => 'calculator',
            'intent_id' => $intentId,
            'symbol' => null,
            'output_file' => null,
            'error' => 'executor_bot_fs_path_missing',
        ];
    }

    $accDir    = rtrim(executor_bot_fs_path((string)$paths['step_1_output']), '/');
    $inworkDir = rtrim(executor_bot_fs_path((string)$paths['step_2_output']), '/');

    $inworkFile = $inworkDir . '/' . $intentId . '.json';
    if (!is_file($inworkFile)) {
        return [
            'success' => false,
            'step' => 'calculator',
            'intent_id' => $intentId,
            'symbol' => null,
            'output_file' => null,
            'error' => 'inwork_file_not_found',
        ];
    }

    $inwork = json_decode((string)file_get_contents($inworkFile), true);
    if (!is_array($inwork)) {
        return [
            'success' => false,
            'step' => 'calculator',
            'intent_id' => $intentId,
            'symbol' => null,
            'output_file' => null,
            'error' => 'invalid_inwork_json',
        ];
    }

    $symbol = strtoupper((string)($inwork['symbol'] ?? ''));
    if ($symbol === '') {
        return [
            'success' => false,
            'step' => 'calculator',
            'intent_id' => $intentId,
            'symbol' => null,
            'output_file' => null,
            'error' => 'symbol_missing',
        ];
    }

    $accFile = $accDir . '/' . strtolower($symbol) . '.json';
    if (!is_file($accFile)) {
        return [
            'success' => false,
            'step' => 'calculator',
            'intent_id' => $intentId,
            'symbol' => $symbol,
            'output_file' => null,
            'error' => 'accumulator_file_not_found',
        ];
    }

    $acc = json_decode((string)file_get_contents($accFile), true);
    if (!is_array($acc)) {
        return [
            'success' => false,
            'step' => 'calculator',
            'intent_id' => $intentId,
            'symbol' => $symbol,
            'output_file' => null,
            'error' => 'invalid_accumulator_json',
        ];
    }

    /* ===============================
       EXCHANGE DATA
       =============================== */

    $price      = (float)($acc['dynamic']['price'] ?? 0);
    $qtyStep   = (float)($acc['static']['qty_step'] ?? 0);
    $minQty    = (float)($acc['static']['min_order_qty'] ?? 0);
    $minValue  = (float)($acc['static']['min_order_value'] ?? 5);
    $maxLev    = (int)($acc['static']['max_leverage'] ?? 1);

    if ($price <= 0 || $maxLev <= 0) {
        return [
            'success' => false,
            'step' => 'calculator',
            'intent_id' => $intentId,
            'symbol' => $symbol,
            'output_file' => null,
            'error' => 'invalid_exchange_limits',
        ];
    }

    /* ===============================
       CONFIG
       =============================== */

    $budgetUsd  = (float)($limits['budget_per_position_usd'] ?? 0);
    $reqLev     = (int)($limits['leverage'] ?? 1);
    $stopPct    = (float)($limits['stop_loss_percent'] ?? 30);
    $slipPct    = (float)($limits['slippage_percent'] ?? 10);

    if ($budgetUsd <= 0 || $reqLev <= 0) {
        return [
            'success' => false,
            'step' => 'calculator',
            'intent_id' => $intentId,
            'symbol' => $symbol,
            'output_file' => null,
            'error' => 'invalid_config_limits',
        ];
    }

    $leverage = min($reqLev, $maxLev);

    /* ===============================
       CORE MODEL (REAL ONE)
       =============================== */

    // Target notional
    $targetNotional = $budgetUsd * $leverage;

    // Raw qty
    $qtyRaw = $targetNotional / $price;

    // Slippage buffer
    $qtyBuffered = $qtyRaw * (1 - $slipPct / 100);

    // Enforce min notional
    $minQtyByValue = $minValue / $price;
    $qtyFinal = max($qtyBuffered, $minQtyByValue, $minQty);

    // Apply step
    if ($qtyStep > 0) {
        $qtyFinal = floor($qtyFinal / $qtyStep) * $qtyStep;
    }

    if ($qtyFinal <= 0) {
        return [
            'success' => false,
            'step' => 'calculator',
            'intent_id' => $intentId,
            'symbol' => $symbol,
            'output_file' => null,
            'error' => 'qty_final_invalid',
        ];
    }

    $notionalFinal = $qtyFinal * $price;
    $realMargin   = $notionalFinal / $leverage;

    /* ===============================
       LIQ + STOP (ESTIMATE)
       =============================== */

    if (($inwork['side'] ?? 'long') === 'long') {
        $liqPrice  = $price * (1 - 1 / $leverage);
        $stopPrice = $price - (($price - $liqPrice) * ($stopPct / 100));
    } else {
        $liqPrice  = $price * (1 + 1 / $leverage);
        $stopPrice = $price + (($liqPrice - $price) * ($stopPct / 100));
    }

    /* ===============================
       OUTPUT
       =============================== */

    $outFile = $inworkDir . '/calc_' . $intentId . '.json';

    $result = [
        'ts' => $ts,
        'step' => 'calculator',
        'intent_id' => $intentId,
        'symbol' => $symbol,

        'market' => [
            'price' => $price,
        ],

        'config' => [
            'budget_usd' => $budgetUsd,
            'leverage' => $leverage,
            'slippage_percent' => $slipPct,
            'stop_percent' => $stopPct,
        ],

        'calc' => [
            'target_notional_usd' => round($targetNotional, 4),
            'qty_raw' => round($qtyRaw, 8),
            'qty_buffered' => round($qtyBuffered, 8),
            'qty_final' => round($qtyFinal, 8),
            'qty' => round($qtyFinal, 8),
            'notional_est_final_usd' => round($notionalFinal, 4),
            'real_margin_used_usd' => round($realMargin, 4),
        ],

        'risk' => [
            'liquidation_price' => round($liqPrice, 8),
            'stop_price' => round($stopPrice, 8),
        ],

        'meta' => $inwork['meta'] ?? [],
    ];

    file_put_contents(
        $outFile,
        json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    return [
        'success' => true,
        'step' => 'calculator',
        'intent_id' => $intentId,
        'symbol' => $symbol,
        'output_file' => $outFile,
        'error' => null,
        'calc' => $result['calc'],
        'risk' => $result['risk'],
    ];
}
