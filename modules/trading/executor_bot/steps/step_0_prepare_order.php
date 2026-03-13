<?php
declare(strict_types=1);

/**
 * Executor Bot — Step 0: Prepare Order (SAFE QUEUE MODE)
 *
 * - Takes ONE intent from queue_dir
 * - Moves it to queue_inflight_dir (atomic rename)
 * - Normalizes minimal order skeleton
 *
 * IMPORTANT:
 * - Step 0 NEVER deletes queue files (only moves queue -> inflight)
 * - Runner decides final fate (done/failed/rejected) and moves inflight file further
 * - CONFIG FIRST: all paths from config/config.php
 */

/* ==========================================================
   SHARED HELPERS
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

        if ($pathRaw[0] === '/') {
            return $root . $pathRaw;
        }

        return $root . '/' . $pathRaw;
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
 * @param array<string,mixed> $ctx
 * @return array<string,mixed>
 */
function step_0_prepare_order(array $ctx = []): array
{
    try {
        $cfg = executor_bot_load_config();

        /* ==================================================
           MODULE ENABLE
           ================================================== */

        if (
            !isset($cfg['module']) ||
            !is_array($cfg['module']) ||
            !($cfg['module']['enabled'] ?? false)
        ) {
            return out_contract(true, 'skipped', null, null, null, 'Module disabled');
        }

        /* ==================================================
           PATHS (CONFIG FIRST)
           ================================================== */

        $paths = $cfg['paths'] ?? [];

        $queueRaw    = (string)($paths['queue_dir'] ?? '');
        $inflightRaw = (string)($paths['queue_inflight_dir'] ?? '');

        if ($queueRaw === '') {
            return out_contract(false, 'error', null, null, null, 'queue_dir not defined');
        }
        if ($inflightRaw === '') {
            return out_contract(false, 'error', null, null, null, 'queue_inflight_dir not defined');
        }

        $queueDir    = executor_bot_fs_path($queueRaw);
        $inflightDir = executor_bot_fs_path($inflightRaw);

        if (!is_dir($queueDir)) {
            return out_contract(true, 'skipped', null, null, null, 'Queue directory not found');
        }

        if (!executor_bot_ensure_dir($inflightDir)) {
            return out_contract(false, 'error', null, null, null, 'Cannot create inflight dir');
        }

        /* ==================================================
           READ QUEUE
           ================================================== */

        $files = glob(rtrim($queueDir, '/') . '/*.json') ?: [];
        if (!$files) {
            return out_contract(true, 'skipped', null, null, null, 'No intents in queue');
        }

        // FIFO by mtime
        usort(
            $files,
            static fn(string $a, string $b): int => (filemtime($a) ?: 0) <=> (filemtime($b) ?: 0)
        );

        $srcFile = $files[0];
        $base    = basename($srcFile);
        $dstFile = rtrim($inflightDir, '/') . '/' . $base;

        /* ==================================================
           ATOMIC CLAIM
           ================================================== */

        if (!@rename($srcFile, $dstFile)) {
            return out_contract(false, 'error', null, null, null, 'Cannot move intent to inflight');
        }

        /* ==================================================
           READ INFLIGHT
           ================================================== */

        $raw = file_get_contents($dstFile);
        if ($raw === false || trim($raw) === '') {
            return out_contract(false, 'error', null, null, $dstFile, 'Empty inflight file');
        }

        $intent = json_decode($raw, true);
        if (!is_array($intent)) {
            return out_contract(false, 'error', null, null, $dstFile, 'Invalid JSON inflight');
        }

        /* ==================================================
           NORMALIZE ORDER
           ================================================== */

        $side = normalize_side((string)($intent['side'] ?? 'long'));

        $order = [
            'category'    => 'linear',
            'symbol'      => (string)($intent['symbol'] ?? ''),
            'side'        => $side,
            'orderType'   => 'Market',
            'qty'         => '1',
            'timeInForce' => 'IOC',
            'reduceOnly'  => false,
        ];

        $meta = [
            'intent_id'  => (string)($intent['intent_id'] ?? ''),
            'confidence' => (int)($intent['confidence'] ?? 0),
            'source'     => (string)($intent['meta']['source'] ?? ($intent['source'] ?? 'queue')),
            'side'       => $side,
            'file'       => $base,
        ];

        return out_contract(true, 'prepared', $order, $meta, $dstFile, null);

    } catch (\Throwable $e) {
        return out_contract(false, 'error', null, null, null, $e->getMessage());
    }
}

/* ==========================================================
   HELPERS
   ========================================================== */

function normalize_side(string $side): string
{
    $s = strtolower(trim($side));
    return ($s === 'sell' || $s === 'short') ? 'short' : 'long';
}

function out_contract(
    bool $success,
    string $status,
    ?array $order,
    ?array $meta,
    ?string $queueFile,
    ?string $error
): array {
    return [
        'success'    => $success,
        'step'       => 'prepare_order',
        'status'     => $status,
        'order'      => $order,
        'meta'       => $meta,
        'queue_file' => $queueFile, // КРИТИЧЕСКИ ВАЖНО (inflight file)
        'error'      => $error,
    ];
}

/*
====================================================================
RULES (Tredercopis)
====================================================================
- Step 0 NEVER deletes queue files (only moves queue -> inflight)
- Uses atomic rename to inflight
- queue_file returns inflight file path for Runner finalization
- CONFIG FIRST: queue_dir, queue_inflight_dir
====================================================================
*/
