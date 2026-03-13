<?php
declare(strict_types=1);

/**
 * Executor Bot — Intent Lock (micro-mechanism, 1 file)
 *
 * Purpose:
 * - Prevent re-processing the same symbol/intent during freeze window
 * - 1 lock file per symbol (simple and transparent)
 *
 * Storage:
 * - Uses config paths['locks'] (logical path from site root)
 * - Auto-creates directory
 *
 * Notes:
 * - Must not depend on Core\System\Root::path($arg) (not supported here)
 * - Uses helper executor_bot_fs_path() from step_0_prepare_order.php when available
 */

if (!function_exists('executor_bot_lock_fs_dir')) {
    /**
     * Resolve locks directory to filesystem.
     * @param array<string,mixed> $cfg
     */
    function executor_bot_lock_fs_dir(array $cfg): string
    {
        $paths = $cfg['paths'] ?? [];
        $locksRaw = (string)($paths['locks'] ?? '');

        // If config doesn't have locks path — derive from step_2_output
        if ($locksRaw === '') {
            $step2Raw = (string)($paths['step_2_output'] ?? '');
            if ($step2Raw !== '') {
                $locksRaw = rtrim($step2Raw, '/') . '/locks/';
            }
        }

        if ($locksRaw === '') {
            // last resort: <site_root>/modules/trading/executor_bot/storage/locks/
            $locksRaw = '/modules/trading/executor_bot/storage/locks/';
        }

        if (function_exists('executor_bot_fs_path')) {
            return rtrim(executor_bot_fs_path($locksRaw), '/');
        }

        // Fallback (should not happen in normal runner)
        $siteRoot = dirname(__DIR__, 3);
        if ($locksRaw !== '' && $locksRaw[0] === '/') {
            return rtrim($siteRoot . $locksRaw, '/');
        }
        return rtrim($siteRoot . '/' . $locksRaw, '/');
    }
}

if (!function_exists('executor_bot_lock_file')) {
    /**
     * @param array<string,mixed> $cfg
     */
    function executor_bot_lock_file(array $cfg, string $symbol): string
    {
        $dir = executor_bot_lock_fs_dir($cfg);
        $symbol = strtolower(trim($symbol));
        return $dir . '/' . $symbol . '.lock';
    }
}

if (!function_exists('executor_intent_is_locked')) {
    /**
     * Checks lock existence and TTL.
     * @param array<string,mixed> $cfg
     */
    function executor_intent_is_locked(array $cfg, string $symbol): bool
    {
        $file = executor_bot_lock_file($cfg, $symbol);

        if (!is_file($file)) {
            return false;
        }

        $raw = file_get_contents($file);
        if ($raw === false || trim($raw) === '') {
            @unlink($file);
            return false;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            @unlink($file);
            return false;
        }

        $expiresAt = (int)($data['expires_at'] ?? 0);
        if ($expiresAt > 0 && time() > $expiresAt) {
            @unlink($file);
            return false;
        }

        return true;
    }
}

if (!function_exists('executor_intent_lock')) {
    /**
     * Acquire lock for symbol.
     * @param array<string,mixed> $cfg
     */
    function executor_intent_lock(array $cfg, string $symbol, string $intentId, int $ttlSeconds): bool
    {
        $dir = executor_bot_lock_fs_dir($cfg);

        if (function_exists('executor_bot_ensure_dir')) {
            if (!executor_bot_ensure_dir($dir)) {
                return false;
            }
        } else {
            if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
                return false;
            }
        }

        $file = executor_bot_lock_file($cfg, $symbol);

        // If locked and still valid -> reject
        if (executor_intent_is_locked($cfg, $symbol)) {
            return false;
        }

        $now = time();
        $payload = [
            'symbol'     => strtoupper(trim($symbol)),
            'intent_id'  => $intentId,
            'created_at' => $now,
            'expires_at' => $ttlSeconds > 0 ? ($now + $ttlSeconds) : 0,
        ];

        $tmp = $file . '.tmp';
        $ok = file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        if ($ok === false) {
            return false;
        }

        return @rename($tmp, $file);
    }
}

if (!function_exists('executor_intent_unlock')) {
    /**
     * Release lock for symbol.
     * @param array<string,mixed> $cfg
     */
    function executor_intent_unlock(array $cfg, string $symbol): void
    {
        $file = executor_bot_lock_file($cfg, $symbol);
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

/*
====================================================================
RULES (Tredercopis)
====================================================================
- Intent lock is ONE FILE per symbol (simple)
- Directory is taken from config paths['locks'] (or derived), auto-created
- No hardcoded absolute FS paths; resolve logical paths from site root
- LF only
====================================================================
*/
