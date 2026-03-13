<?php
declare(strict_types=1);

/**
 * Executor Bot — Step 6: Finalizer
 *
 * Responsibilities:
 * - Clean up temporary artifacts
 * - Move queue_inflight/{intent_id}.json -> queue_done OR queue_rejected
 * - Persist final execution snapshot (done/rejected file content)
 *
 * IMPORTANT:
 * - No API calls here
 * - This step is the canonical place where intent becomes DONE/REJECTED
 */

/* ==========================================================
   SHARED HELPERS (executor_bot)
   ========================================================== */

if (!function_exists('executor_bot_load_config')) {
    function executor_bot_load_config(): array
    {
        $cfgPath = dirname(__DIR__) . '/config/config.php';
        $cfg = is_file($cfgPath) ? require $cfgPath : [];
        return is_array($cfg) ? $cfg : [];
    }
}

if (!function_exists('executor_bot_site_root')) {
    function executor_bot_site_root(): string
    {
        return dirname(__DIR__, 4);
    }
}

if (!function_exists('executor_bot_fs_path')) {
    function executor_bot_fs_path(string $pathRaw): string
    {
        $pathRaw = trim($pathRaw);
        if ($pathRaw === '') {
            return '';
        }

        if (is_file($pathRaw) || is_dir($pathRaw)) {
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
   MAIN
   ========================================================== */

/**
 * @param array<string,mixed> $step0Result
 * @param array<string,mixed> $step4Result
 * @param array<string,mixed> $step5Result
 * @return array<string,mixed>
 */
function step_6_finalize(array $step0Result, array $step4Result, array $step5Result): array
{
    $ts = date('c');

    $meta = $step0Result['meta'] ?? [];
    $order = $step0Result['order'] ?? [];

    $intentId = is_array($meta) ? (string)($meta['intent_id'] ?? '') : '';
    $symbol = is_array($order) ? strtoupper((string)($order['symbol'] ?? '')) : '';

    if ($intentId === '') {
        $intentId = (string)($step4Result['intent_id'] ?? ($step5Result['intent_id'] ?? ''));
    }
    if ($symbol === '') {
        $symbol = strtoupper((string)($step4Result['symbol'] ?? ($step5Result['symbol'] ?? '')));
    }

    if ($intentId === '' || $symbol === '') {
        return [
            'success' => false,
            'step' => 'finalize',
            'intent_id' => $intentId !== '' ? $intentId : null,
            'symbol' => $symbol !== '' ? $symbol : null,
            'status' => 'error',
            'done_file' => null,
            'error' => 'missing_intent_or_symbol',
        ];
    }

    $cfg = executor_bot_load_config();
    $paths = $cfg['paths'] ?? [];

    $doneRaw = (string)($paths['queue_done_dir'] ?? '');
    $rejectedRaw = (string)($paths['queue_rejected_dir'] ?? '');

    if ($doneRaw === '' || $rejectedRaw === '') {
        return [
            'success' => false,
            'step' => 'finalize',
            'intent_id' => $intentId,
            'symbol' => $symbol,
            'status' => 'error',
            'done_file' => null,
            'error' => 'done_or_rejected_dir_not_defined',
        ];
    }

    $doneDir = rtrim(executor_bot_fs_path($doneRaw), '/');
    $rejectedDir = rtrim(executor_bot_fs_path($rejectedRaw), '/');

    executor_bot_ensure_dir($doneDir);
    executor_bot_ensure_dir($rejectedDir);

    // Cleanup built file (Step 4 artifact)
    $builtFile = (string)($step4Result['built_file'] ?? '');
    if ($builtFile !== '' && is_file($builtFile)) {
        @unlink($builtFile);
    }

    // Determine final status based on Step 5 (simulator)
    $ok = (($step5Result['success'] ?? false) === true);

    $finalStatus = $ok ? 'done' : 'rejected';

    $targetDir = $ok ? $doneDir : $rejectedDir;
    $finalFile = $targetDir . '/' . $intentId . '.json';

    // Move inflight queue file if present
    $queueInflight = (string)($step0Result['queue_file'] ?? '');
    if ($queueInflight !== '' && is_file($queueInflight)) {
        @rename($queueInflight, $finalFile);
    }

    // Write / overwrite final snapshot (source of truth)
    $finalSnapshot = [
        'ts' => $ts,
        'step' => 'finalize',
        'intent_id' => $intentId,
        'symbol' => $symbol,
        'status' => $finalStatus,

        'inputs' => [
            'queue_inflight_file' => $queueInflight,
            'built_file' => $builtFile,
            'simulation_file' => (string)($step5Result['simulation_file'] ?? ''),
        ],

        'order' => is_array($order) ? $order : [],
        'meta' => is_array($meta) ? $meta : [],

        'simulator' => $step5Result,

        'error' => $ok ? null : (string)($step5Result['error'] ?? 'simulator_failed'),
    ];

    @file_put_contents(
        $finalFile,
        json_encode($finalSnapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    return [
        'success' => true,
        'step' => 'finalize',
        'intent_id' => $intentId,
        'symbol' => $symbol,
        'status' => $finalStatus,
        'done_file' => $finalFile,
        'error' => $ok ? null : (string)($step5Result['error'] ?? 'simulator_failed'),
    ];
}

/*
====================================================================
RULES (Tredercopis)
====================================================================
- Step 6 is the ONLY place that marks intent DONE/REJECTED
- Deletes Step4 built task
- Moves queue_inflight/{intent_id}.json to done/rejected (best-effort)
- Writes final snapshot into {done|rejected}/{intent_id}.json
- CONFIG FIRST, LF only
====================================================================
*/
