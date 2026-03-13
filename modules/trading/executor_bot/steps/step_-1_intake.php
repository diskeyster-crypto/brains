<?php
declare(strict_types=1);

/**
 * Executor Bot — STEP -1: Intake (External Index -> Queue)
 *
 * Responsibilities:
 * - Read external intents index file (paths.step_-1_input)
 * - Pick ONE next eligible intent per run
 * - Enqueue to internal queue_dir as {intent_id}.json
 *
 * Rules:
 * - NO trading logic
 * - NO deletes from external index file
 * - NO destructive changes outside executor_bot storage
 * - CONFIG FIRST / ZERO HARDCODE
 */

/* ==========================================================
   SHARED HELPERS (executor_bot)
   ========================================================== */

if (!function_exists('executor_bot_site_root')) {
    function executor_bot_site_root(): string
    {
        // steps/ -> executor_bot/ -> trading/ -> modules/ -> <site_root>
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

        // already absolute existing path
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
   FROZEN CHECK
   ========================================================== */

/**
 * Frozen = same symbol has DONE snapshot within freeze window.
 *
 * @param string $symbolUpper
 * @param string $doneDir
 * @param int $freezeMinutes
 */
function executor_intent_is_frozen(string $symbolUpper, string $doneDir, int $freezeMinutes): bool
{
    if ($freezeMinutes <= 0) {
        return false;
    }

    $doneDir = rtrim($doneDir, '/');
    if ($doneDir === '' || !is_dir($doneDir)) {
        return false;
    }

    $now = time();
    $ttl = $freezeMinutes * 60;

    $files = glob($doneDir . '/*.json') ?: [];
    foreach ($files as $file) {
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            continue;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            continue;
        }

        $sym = strtoupper((string)($data['symbol'] ?? ''));
        if ($sym !== $symbolUpper) {
            continue;
        }

        $ts = (string)($data['ts'] ?? '');
        $t = $ts !== '' ? strtotime($ts) : 0;
        if ($t <= 0) {
            $t = (int)@filemtime($file);
        }

        if ($t > 0 && ($now - $t) < $ttl) {
            return true;
        }
    }

    return false;
}

/* ==========================================================
   MAIN
   ========================================================== */

/**
 * @return string|null intent_id that was enqueued (or null if nothing eligible)
 */
function step_minus_1_intake(): ?string
{
    $cfg = executor_bot_load_config();
    $paths = $cfg['paths'] ?? [];
    $limits = $cfg['limits'] ?? [];

    $indexRaw = (string)($paths['step_-1_input'] ?? '');
    $queueRaw = (string)($paths['queue_dir'] ?? '');
    $inflightRaw = (string)($paths['queue_inflight_dir'] ?? '');
    $doneRaw = (string)($paths['queue_done_dir'] ?? '');
    $rejectedRaw = (string)($paths['queue_rejected_dir'] ?? '');

    if ($indexRaw === '' || $queueRaw === '' || $inflightRaw === '' || $doneRaw === '' || $rejectedRaw === '') {
        return null;
    }

    $indexFile = executor_bot_fs_path($indexRaw);
    $queueDir = executor_bot_fs_path($queueRaw);
    $inflightDir = executor_bot_fs_path($inflightRaw);
    $doneDir = executor_bot_fs_path($doneRaw);
    $rejectedDir = executor_bot_fs_path($rejectedRaw);

    executor_bot_ensure_dir($queueDir);
    executor_bot_ensure_dir($inflightDir);
    executor_bot_ensure_dir($doneDir);
    executor_bot_ensure_dir($rejectedDir);

    if (!is_file($indexFile)) {
        return null;
    }

    $raw = (string)@file_get_contents($indexFile);
    if (trim($raw) === '') {
        return null;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }

    /**
     * SUPPORT BOTH FORMATS:
     * - Standard: { items: [ ... ] }
     * - External: { index: { hash: {...}, hash: {...} } }
     */
    $items = null;

    if (isset($data['items']) && is_array($data['items'])) {
        $items = $data['items'];
    } elseif (isset($data['index']) && is_array($data['index'])) {
        $items = array_values($data['index']);
    }

    if (!is_array($items) || count($items) === 0) {
        return null;
    }

    $freezeMinutes = (int)($limits['freeze_minutes'] ?? 0);
    $enforceExpiresAt = (bool)($limits['enforce_expires_at'] ?? true);
    $allowExpired = (bool)($limits['allow_expired_intents'] ?? false);

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $intentId = (string)($item['signal_id'] ?? $item['intent_id'] ?? '');
        $symbol = strtoupper((string)($item['symbol'] ?? ''));
        $side = strtolower((string)($item['side'] ?? ''));
        $confidence = (int)($item['confidence'] ?? 0);

        if ($intentId === '' || $symbol === '' || ($side !== 'long' && $side !== 'short')) {
            continue;
        }

        if ($enforceExpiresAt && !$allowExpired) {
            $expiresAt = (string)($item['expires_at'] ?? '');
            if ($expiresAt !== '') {
                $exp = strtotime($expiresAt);
                if ($exp > 0 && $exp < time()) {
                    continue;
                }
            }
        }

        $queueFile = rtrim($queueDir, '/') . '/' . $intentId . '.json';
        $inflightFile = rtrim($inflightDir, '/') . '/' . $intentId . '.json';
        $doneFile = rtrim($doneDir, '/') . '/' . $intentId . '.json';
        $rejectedFile = rtrim($rejectedDir, '/') . '/' . $intentId . '.json';

        // IMPORTANT: do not stop on duplicates — continue scanning
        if (is_file($queueFile) || is_file($inflightFile) || is_file($doneFile) || is_file($rejectedFile)) {
            continue;
        }

        // Frozen symbol guard
        if (executor_intent_is_frozen($symbol, $doneDir, $freezeMinutes)) {
            continue;
        }

        $snapshot = [
            'ts' => date('c'),
            'intent_id' => $intentId,
            'symbol' => $symbol,
            'side' => $side,
            'confidence' => $confidence,
            'payload' => $item,
            'source' => 'step_-1_intake',
            'status' => 'queued',
        ];

        $ok = file_put_contents(
            $queueFile,
            json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );

        if ($ok === false) {
            return null;
        }

        return $intentId;
    }

    return null;
}

/*
====================================================================
RULES (Tredercopis)
====================================================================
- Step -1 reads external index and enqueues ONE eligible intent per run
- Supports both formats: items[] and index{}
- Must NOT "return null" on duplicates — continue scanning items
- Frozen is enforced using DONE snapshots within limits.freeze_minutes
- No deletes, no queue cleanup, no trading logic
- CONFIG FIRST: all paths from config.php
- LF only
====================================================================
*/
