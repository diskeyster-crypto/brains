<?php
declare(strict_types=1);

/**
 * Step 8 — CHECKER (STUB)
 *
 * NOW:
 * - Reads exec snapshot produced by Step 7
 * - Writes checker snapshot into storage/checks/
 *
 * LATER:
 * - Real verification via Positions API will be here
 */

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

/**
 * @param string $execFile
 * @return array<string,mixed>
 */
function step_8_checker(string $execFile): array
{
    $execFile = trim($execFile);

    if ($execFile === '') {
        return [
            'success' => false,
            'step' => 'checker',
            'intent_id' => null,
            'exec_file' => null,
            'output_file' => null,
            'error' => 'empty_exec_file',
        ];
    }

    if (!is_file($execFile)) {
        return [
            'success' => false,
            'step' => 'checker',
            'intent_id' => null,
            'exec_file' => $execFile,
            'output_file' => null,
            'error' => 'exec_file_not_found',
        ];
    }

    $exec = json_decode((string)file_get_contents($execFile), true);
    if (!is_array($exec)) {
        return [
            'success' => false,
            'step' => 'checker',
            'intent_id' => null,
            'exec_file' => $execFile,
            'output_file' => null,
            'error' => 'invalid_exec_json',
        ];
    }

    $intentId = trim((string)($exec['intent_id'] ?? ''));
    $symbol   = strtoupper((string)($exec['symbol'] ?? ''));

    if ($intentId === '' || $symbol === '') {
        return [
            'success' => false,
            'step' => 'checker',
            'intent_id' => $intentId !== '' ? $intentId : null,
            'exec_file' => $execFile,
            'output_file' => null,
            'error' => 'invalid_exec_contract',
        ];
    }

    // storage root derived from exec file:
    // .../storage/live/live_x.json => .../storage
    $liveDir = dirname($execFile);
    $storageDir = dirname($liveDir);
    $checksDir = rtrim($storageDir, '/') . '/checks';

    if (!executor_bot_ensure_dir($checksDir)) {
        return [
            'success' => false,
            'step' => 'checker',
            'intent_id' => $intentId,
            'exec_file' => $execFile,
            'output_file' => null,
            'error' => 'cannot_create_checks_dir',
        ];
    }

    $outFile = $checksDir . '/check_' . $intentId . '.json';

    // STUB verification: пока считаем "unknown" (без API)
    $snapshot = [
        'ts' => date('c'),
        'step' => 'checker',
        'intent_id' => $intentId,
        'symbol' => $symbol,
        'verified' => false,
        'status' => 'unknown_stub',
        'inputs' => [
            'exec_file' => $execFile,
        ],
        'error' => null,
    ];

    $ok = file_put_contents(
        $outFile,
        json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    if ($ok === false) {
        return [
            'success' => false,
            'step' => 'checker',
            'intent_id' => $intentId,
            'exec_file' => $execFile,
            'output_file' => null,
            'error' => 'cannot_write_checker_snapshot',
        ];
    }

    return [
        'success' => true,
        'step' => 'checker',
        'intent_id' => $intentId,
        'exec_file' => $execFile,
        'output_file' => $outFile,
        'error' => null,
    ];
}

/*
====================================================================
RULES (Tredercopis)
====================================================================
- Step 8 MUST accept exec_file (output_file from Step 7), NOT intent_id
- Checks directory derived from storage root
- No API yet (stub), output written into storage/checks/check_{intent_id}.json
====================================================================
*/
