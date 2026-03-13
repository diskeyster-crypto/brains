<?php
declare(strict_types=1);

/**
 * Executor Bot — Step 4: Builder
 *
 * Writes FINAL order task into built_{intent_id}.json
 *
 * CRITICAL RULE:
 * - qty is taken ONLY from Step 3 (calculator)
 * - Step 0 is NEVER trusted for qty
 */

function step_4_builder(array $step0Result, array $step3Result): array
{
    $ts = date('c');

    /* ===============================
       GUARDS
       =============================== */

    if (($step0Result['success'] ?? false) !== true || ($step0Result['status'] ?? '') !== 'prepared') {
        return [
            'success' => false,
            'step' => 'builder',
            'intent_id' => null,
            'symbol' => null,
            'built_file' => null,
            'error' => 'step0_not_prepared',
        ];
    }

    if (($step3Result['success'] ?? false) !== true) {
        return [
            'success' => false,
            'step' => 'builder',
            'intent_id' => null,
            'symbol' => null,
            'built_file' => null,
            'error' => 'step3_failed',
        ];
    }

    /* ===============================
       INPUTS
       =============================== */

    $meta  = $step0Result['meta'] ?? [];
    $order = $step0Result['order'] ?? [];

    if (!is_array($meta) || !is_array($order)) {
        return [
            'success' => false,
            'step' => 'builder',
            'intent_id' => null,
            'symbol' => null,
            'built_file' => null,
            'error' => 'invalid_step0_payload',
        ];
    }

    $intentId = (string)($meta['intent_id'] ?? '');
    $symbol   = strtoupper((string)($order['symbol'] ?? ''));

    if ($intentId === '' || $symbol === '') {
        return [
            'success' => false,
            'step' => 'builder',
            'intent_id' => $intentId ?: null,
            'symbol' => $symbol ?: null,
            'built_file' => null,
            'error' => 'missing_intent_or_symbol',
        ];
    }

    /* ===============================
       PATH
       =============================== */

    $cfg = executor_bot_load_config();
    $paths = $cfg['paths'] ?? [];

    $builtRaw = (string)($paths['step_4_output'] ?? '');
    if ($builtRaw === '') {
        return [
            'success' => false,
            'step' => 'builder',
            'intent_id' => $intentId,
            'symbol' => $symbol,
            'built_file' => null,
            'error' => 'step_4_output_not_defined',
        ];
    }

    $builtDir = rtrim(executor_bot_fs_path($builtRaw), '/');

    if (!is_dir($builtDir)) {
        if (!@mkdir($builtDir, 0777, true)) {
            return [
                'success' => false,
                'step' => 'builder',
                'intent_id' => $intentId,
                'symbol' => $symbol,
                'built_file' => null,
                'error' => 'cannot_create_built_dir: ' . $builtDir,
            ];
        }
    }

    /* ===============================
       CALCULATOR SNAPSHOT (SINGLE SOURCE OF TRUTH)
       =============================== */

    $calcFile = (string)($step3Result['output_file'] ?? '');
    if ($calcFile === '' || !is_file($calcFile)) {
        return [
            'success' => false,
            'step' => 'builder',
            'intent_id' => $intentId,
            'symbol' => $symbol,
            'built_file' => null,
            'error' => 'calculator_file_not_found',
        ];
    }

    $calcSnap = json_decode((string)file_get_contents($calcFile), true);
    if (!is_array($calcSnap)) {
        return [
            'success' => false,
            'step' => 'builder',
            'intent_id' => $intentId,
            'symbol' => $symbol,
            'built_file' => null,
            'error' => 'calculator_file_invalid_json',
        ];
    }

    $calc = is_array($calcSnap['calc'] ?? null) ? (array)$calcSnap['calc'] : [];
    $risk = is_array($calcSnap['risk'] ?? null) ? (array)$calcSnap['risk'] : [];

    // Backward/forward compat:
    // - older calc: calc.qty
    // - newer calc: calc.qty_final
    $qty = $calc['qty_final'] ?? $calc['qty'] ?? null;
    if (!is_numeric($qty) || (float)$qty <= 0) {
        return [
            'success' => false,
            'step' => 'builder',
            'intent_id' => $intentId,
            'symbol' => $symbol,
            'built_file' => null,
            'error' => 'calculator_qty_missing',
        ];
    }

    $qty = (float)$qty;
    if ($qty <= 0) {
        return [
            'success' => false,
            'step' => 'builder',
            'intent_id' => $intentId,
            'symbol' => $symbol,
            'built_file' => null,
            'error' => 'invalid_calculated_qty',
        ];
    }

    /* ===============================
       FINAL ORDER (HARD OVERRIDE)
       =============================== */

    $finalOrder = $order;

    // УБИВАЕМ любое qty из step0
    unset($finalOrder['qty']);

    // СТАВИМ qty ТОЛЬКО из калькулятора
    $finalOrder['qty'] = (string)$qty;

    // Side — факт из meta
    $side = strtolower((string)($meta['side'] ?? $finalOrder['side'] ?? ''));

    /* ===============================
       BUILD FILE
       =============================== */

    $builtFile = $builtDir . '/built_' . $intentId . '.json';

    $task = [
        'ts' => $ts,
        'step' => 'builder',
        'intent_id' => $intentId,
        'symbol' => $symbol,
        'side' => $side,
        'mode' => (string)($cfg['module']['mode'] ?? 'live'),

        'inputs' => [
            'queue_file' => (string)($step0Result['queue_file'] ?? ''),
            'calc_file'  => (string)($step3Result['output_file'] ?? ''),
        ],

        'order' => $finalOrder,
        'risk'  => is_array($risk) ? $risk : [],
        'meta'  => $meta,

        'status' => 'built',
    ];

    $ok = file_put_contents(
        $builtFile,
        json_encode($task, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    if ($ok === false || !is_file($builtFile)) {
        return [
            'success' => false,
            'step' => 'builder',
            'intent_id' => $intentId,
            'symbol' => $symbol,
            'built_file' => null,
            'error' => 'cannot_write_built_file: ' . $builtFile,
        ];
    }

    return [
        'success' => true,
        'step' => 'builder',
        'intent_id' => $intentId,
        'symbol' => $symbol,
        'built_file' => $builtFile,
        'error' => null,
    ];
}

/*
====================================================================
RULES (Tredercopis)
====================================================================
- Step 4 NEVER trusts qty from Step 0
- Step 3 (calculator) is the ONLY source of qty
- Step 4 is a pure contract builder
- What is written here is EXACTLY what goes to exchange
====================================================================
*/
