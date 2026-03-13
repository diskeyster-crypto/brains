<?php
declare(strict_types=1);

/**
 * Executor Bot — Step 5: Simulator (SAFE)
 *
 * Responsibilities:
 * - Read built_{intent_id}.json (READ ONLY)
 * - Produce deterministic simulation result (NO API)
 * - Write sim_{intent_id}.json into step_5_output directory
 *
 * CRITICAL:
 * - MUST NOT modify / move / delete built file
 * - built = immutable source of truth
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
 * @param string $builtFile
 * @return array<string,mixed>
 */
function step_5_simulator(string $builtFile): array
{
    $ts = date('c');
    $builtFile = trim($builtFile);

    /* -------------------------------
       GUARDS
       ------------------------------- */

    if ($builtFile === '') {
        return [
            'success' => false,
            'step' => 'simulator',
            'intent_id' => null,
            'symbol' => null,
            'simulation_file' => null,
            'error' => 'empty_built_file',
        ];
    }

    if (!is_file($builtFile)) {
        return [
            'success' => false,
            'step' => 'simulator',
            'intent_id' => null,
            'symbol' => null,
            'simulation_file' => null,
            'error' => 'built_file_not_found',
        ];
    }

    /* -------------------------------
       READ BUILT (READ ONLY)
       ------------------------------- */

    $built = json_decode((string)file_get_contents($builtFile), true);
    if (!is_array($built)) {
        return [
            'success' => false,
            'step' => 'simulator',
            'intent_id' => null,
            'symbol' => null,
            'simulation_file' => null,
            'error' => 'invalid_built_json',
        ];
    }

    $intentId = (string)($built['intent_id'] ?? '');
    $symbol   = strtoupper((string)($built['symbol'] ?? ''));
    $side     = strtolower((string)($built['side'] ?? ''));

    if ($intentId === '' || $symbol === '' || ($side !== 'long' && $side !== 'short')) {
        return [
            'success' => false,
            'step' => 'simulator',
            'intent_id' => $intentId !== '' ? $intentId : null,
            'symbol' => $symbol !== '' ? $symbol : null,
            'simulation_file' => null,
            'error' => 'invalid_built_contract',
        ];
    }

    /* -------------------------------
       STORAGE
       ------------------------------- */

    $cfg   = executor_bot_load_config();
    $paths = $cfg['paths'] ?? [];

    $simRaw = (string)($paths['step_5_output'] ?? '');
    if ($simRaw === '') {
        return [
            'success' => false,
            'step' => 'simulator',
            'intent_id' => $intentId,
            'symbol' => $symbol,
            'simulation_file' => null,
            'error' => 'step_5_output_not_defined',
        ];
    }

    $simDir = rtrim(executor_bot_fs_path($simRaw), '/');
    if (!executor_bot_ensure_dir($simDir)) {
        return [
            'success' => false,
            'step' => 'simulator',
            'intent_id' => $intentId,
            'symbol' => $symbol,
            'simulation_file' => null,
            'error' => 'cannot_create_sim_dir',
        ];
    }

    $simulationFile = $simDir . '/sim_' . $intentId . '.json';

    /* -------------------------------
       PURE SIMULATION
       ------------------------------- */

    $order = $built['order'] ?? [];
    $risk  = $built['risk'] ?? [];

    $result = [
        'ts' => $ts,
        'step' => 'simulator',
        'intent_id' => $intentId,
        'symbol' => $symbol,
        'side' => $side,

        'inputs' => [
            'built_file' => $builtFile,
        ],

        'simulated' => true,
        'assumed_order_placed' => true,
        'assumed_stop_placed' => true,

        'order' => is_array($order) ? $order : [],
        'risk'  => is_array($risk) ? $risk : [],

        'status' => 'simulated',
        'error' => null,
    ];

    $ok = file_put_contents(
        $simulationFile,
        json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    if ($ok === false) {
        return [
            'success' => false,
            'step' => 'simulator',
            'intent_id' => $intentId,
            'symbol' => $symbol,
            'simulation_file' => null,
            'error' => 'cannot_write_simulation',
        ];
    }

    /* -------------------------------
       ABSOLUTE GUARANTEE:
       NO UNLINK / NO RENAME
       ------------------------------- */
    // intentionally NOTHING here

    return [
        'success' => true,
        'step' => 'simulator',
        'intent_id' => $intentId,
        'symbol' => $symbol,
        'simulation_file' => $simulationFile,
        'error' => null,
    ];
}

/*
====================================================================
RULES (Tredercopis)
====================================================================
- Step 5 is READ-ONLY to built
- NEVER unlink / rename / modify built
- built = immutable contract
- simulator = sandbox only
====================================================================
*/
