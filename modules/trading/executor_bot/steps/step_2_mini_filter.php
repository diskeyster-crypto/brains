<?php
declare(strict_types=1);

/**
 * Executor Bot — Step 2: Mini Filter (PURE LOGIC)
 *
 * Responsibilities:
 * - validate intent
 * - apply confidence threshold
 * - apply symbol blocklist
 * - write inwork snapshot
 *
 * NO queue operations
 * NO deletes
 * NO locks
 * CONFIG FIRST
 */

/**
 * @param array<string,mixed> $input  // результат Step 1
 * @param array<string,mixed> $stepOverrideConfig
 * @return array<string,mixed>
 */
function step_2_mini_filter(array $input, array $stepOverrideConfig = []): array
{
    try {

        if (($input['success'] ?? false) !== true) {
            return [
                'success' => false,
                'step'    => 'mini_filter',
                'passed'  => false,
                'reason'  => 'previous_step_failed',
            ];
        }

        $cfg = executor_bot_load_config();
        $limits = $cfg['limits'] ?? [];
        $paths  = $cfg['paths'] ?? [];

        $meta = $input['meta'] ?? [];
        if (!is_array($meta)) {
            return [
                'success' => false,
                'step'    => 'mini_filter',
                'passed'  => false,
                'reason'  => 'meta_missing',
            ];
        }

        $symbol = strtoupper(trim((string)($input['symbol'] ?? '')));
        if ($symbol === '') {
            return [
                'success' => false,
                'step'    => 'mini_filter',
                'passed'  => false,
                'reason'  => 'empty_symbol',
            ];
        }

        $intentId = (string)($meta['intent_id'] ?? '');
        if ($intentId === '') {
            return [
                'success' => false,
                'step'    => 'mini_filter',
                'passed'  => false,
                'reason'  => 'missing_intent_id',
            ];
        }

        $side = strtolower((string)($meta['side'] ?? ''));
        if ($side !== 'long' && $side !== 'short') {
            return [
                'success' => false,
                'step'    => 'mini_filter',
                'passed'  => false,
                'reason'  => 'invalid_side',
            ];
        }

        /* =========================
           CONFIDENCE
           ========================= */

        $minConfidence = (int)($stepOverrideConfig['min_confidence']
            ?? $limits['min_confidence']
            ?? 0);

        $confidence = (int)($meta['confidence'] ?? 0);

        if ($confidence < $minConfidence) {
            return [
                'success' => true,
                'step'    => 'mini_filter',
                'passed'  => false,
                'reason'  => 'low_confidence',
            ];
        }

        /* =========================
           BLOCKLIST
           ========================= */

        $blocked = $limits['blocked_symbols'] ?? [];
        if (is_array($blocked)) {
            $blockedUpper = array_map(
                static fn($x) => strtoupper(trim((string)$x)),
                $blocked
            );

            if (in_array($symbol, $blockedUpper, true)) {
                return [
                    'success' => true,
                    'step'    => 'mini_filter',
                    'passed'  => false,
                    'reason'  => 'blocked_symbol',
                ];
            }
        }

        /* =========================
           INWORK WRITE
           ========================= */

        $inworkRaw = (string)($paths['step_2_output'] ?? '');
        if ($inworkRaw === '') {
            return [
                'success' => false,
                'step'    => 'mini_filter',
                'passed'  => false,
                'reason'  => 'step_2_output_not_defined',
            ];
        }

        $inworkDir = executor_bot_fs_path($inworkRaw);
        $inworkDir = rtrim($inworkDir, '/');

        if (!executor_bot_ensure_dir($inworkDir)) {
            return [
                'success' => false,
                'step'    => 'mini_filter',
                'passed'  => false,
                'reason'  => 'cannot_create_inwork_dir',
            ];
        }

        // КАНОН: по intent_id
        $inworkFile = $inworkDir . '/' . $intentId . '.json';

        $snapshot = [
            'ts'        => date('c'),
            'intent_id'=> $intentId,
            'symbol'   => $symbol,
            'side'     => $side,
            'meta'     => $meta,
            'source'   => 'executor_bot',
            'status'   => 'inwork',
        ];

        $ok = file_put_contents(
            $inworkFile,
            json_encode(
                $snapshot,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            LOCK_EX
        );

        if ($ok === false) {
            return [
                'success' => false,
                'step'    => 'mini_filter',
                'passed'  => false,
                'reason'  => 'cannot_write_inwork',
            ];
        }

        return [
            'success'     => true,
            'step'        => 'mini_filter',
            'passed'      => true,
            'symbol'      => $symbol,
            'intent_id'   => $intentId,
            'inwork_file' => $inworkFile,
            'reason'      => null,
        ];

    } catch (\Throwable $e) {
        return [
            'success' => false,
            'step'    => 'mini_filter',
            'passed'  => false,
            'reason'  => 'exception: ' . $e->getMessage(),
        ];
    }
}

/*
====================================================================
RULES (Tredercopis)
====================================================================
- Step 2 is PURE LOGIC
- No deletes
- No queue ops
- Writes inwork/{intent_id}.json
- One intent = one file
====================================================================
*/
