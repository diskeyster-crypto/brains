<?php

declare(strict_types=1);

/**
 * Fish Strategy — AJAX Handler
 *
 * Handles POST actions from admin pages:
 *   action=run          → trigger a smoke-test run
 *                          - universe_mode=all        → queue batched run (async safe)
 *                          - universe_mode=manual_list → synchronous run (small list)
 *   action=tick_batch   → advance one batch of a queued/running batched run
 *   action=save_config  → validate and write active.php overrides
 *   action=reset_active → clear active.php back to empty overrides
 *
 * Browser form POSTs set a session flash and redirect back to the config page.
 * XHR requests with X-Requested-With or ?json receive a JSON response.
 */

use Core\System\System;
use Core\System\SystemPaths;
use Core\Auth\Auth;

if (!Auth::check()) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$moduleDir = SystemPaths::instance()->get('strategy.fish');

require_once $moduleDir . '/service.php';

use Modules\Strategy\Fish\FishService;

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$isAjax = (
    !empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
    str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') ||
    isset($_GET['json'])
);

$service   = FishService::instance($moduleDir);
$configUrl = System::web('admin/strategy/fish/config');

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Parse a textarea symbol list (comma or newline separated) into a clean
 * array of uppercase symbol strings with no empty/duplicate entries.
 */
function fishParseSymbols(string $raw): array
{
    $items = preg_split('/[\r\n,]+/', $raw);
    $out   = [];
    foreach ($items as $item) {
        $sym = strtoupper(trim($item));
        if ($sym !== '' && !in_array($sym, $out, true)) {
            $out[] = $sym;
        }
    }
    return $out;
}

/**
 * Write active.php with the given overrides array.
 * Returns null on success or an error string on failure.
 */
function fishWriteActive(string $moduleDir, array $overrides): ?string
{
    $activePath = $moduleDir . '/config/active.php';

    $lines = ["<?php\n\ndeclare(strict_types=1);\n\n"];
    $lines[] = "/**\n * Fish Strategy — Active Config Overrides (v1)\n"
             . " *\n * Written by the admin UI. Edit via the config page, not this file.\n"
             . " * Values here take precedence over base.php.\n */\n\n";
    $lines[] = "return " . var_export($overrides, true) . ";\n";

    $written = @file_put_contents($activePath, implode('', $lines));
    if ($written === false) {
        return 'Could not write ' . $activePath . ' — check file permissions.';
    }
    return null;
}

/**
 * Store a flash message in the session for the config page to consume.
 */
function fishSetFlash(string $type, string $message): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['fish_flash'] = ['type' => $type, 'message' => $message];
}

// ---------------------------------------------------------------------------
// Dispatch
// ---------------------------------------------------------------------------

switch ($action) {

    // -----------------------------------------------------------------------
    case 'run':
    // -----------------------------------------------------------------------
        // For all-universe runs: queue the batched path (safe for large universes).
        // For manual_list: run synchronously (list is small, single HTTP request is fine).
        $runConfig    = $service->getConfig();
        $universeMode = $runConfig['universe_mode'] ?? 'all';

        if ($universeMode === 'all') {
            // Queue and return immediately — the cron (or manual Tick Batch) advances it.
            try {
                $runId = $service->queueRun();
                $msg   = 'Batched run queued (' . $runId . '). Use cron or "Tick Batch" to process symbols.';
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'ok'      => true,
                        'queued'  => true,
                        'run_id'  => $runId,
                        'message' => $msg,
                    ], JSON_UNESCAPED_UNICODE);
                } else {
                    fishSetFlash('info', $msg);
                    header('Location: ' . $configUrl);
                }
            } catch (\Throwable $e) {
                if ($isAjax) {
                    http_response_code(500);
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
                } else {
                    fishSetFlash('danger', 'Queue failed: ' . $e->getMessage());
                    header('Location: ' . $configUrl);
                }
            }
        } else {
            // manual_list / smoke_demo — run synchronously
            try {
                $result = $service->run();
            } catch (\Throwable $e) {
                $result = [
                    'ok'     => false,
                    'status' => 'error',
                    'error'  => $e->getMessage(),
                ];
            }

            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE);
            } else {
                $ok  = ($result['status'] ?? '') !== 'error';
                $msg = $result['message'] ?? ($ok ? 'Smoke test completed.' : 'Smoke test failed.');
                fishSetFlash($ok ? 'success' : 'danger', 'Run result: ' . $msg);
                header('Location: ' . $configUrl);
            }
        }
        break;

    // -----------------------------------------------------------------------
    case 'tick_batch':
    // -----------------------------------------------------------------------
        // Advance one batch of the current queued/running batched run.
        // Can be called from the admin UI manually or by the cron runner.
        try {
            $result = $service->tickBatch();
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'status' => 'error', 'message' => $e->getMessage()];
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
        } else {
            $ok  = $result['ok'] ?? false;
            $msg = $result['message'] ?? 'Tick completed.';
            $runState = $service->getRunState();
            $pct = 0;
            if (($runState['total_symbols'] ?? 0) > 0) {
                $pct = (int)round(($runState['processed_symbols'] ?? 0) / $runState['total_symbols'] * 100);
            }
            fishSetFlash(
                $ok ? 'info' : 'danger',
                'Tick: ' . $msg . ($ok ? " ({$pct}% done)" : '')
            );
            header('Location: ' . $configUrl);
        }
        break;

    // -----------------------------------------------------------------------
    case 'save_config':
    // -----------------------------------------------------------------------
        $p = $_POST;

        $allowedModes         = ['active', 'passive', 'disabled', 'smoke_demo'];
        $allowedUniverseModes = ['all', 'manual_list'];

        $errors    = [];
        $overrides = [];

        // --- enabled ---
        $overrides['enabled'] = isset($p['enabled']);

        // --- mode ---
        $mode = trim($p['mode'] ?? 'passive');
        if (!in_array($mode, $allowedModes, true)) {
            $errors[] = 'Invalid mode: ' . htmlspecialchars($mode);
        } else {
            $overrides['mode'] = $mode;
        }

        // --- universe_mode ---
        $universeMode = trim($p['universe_mode'] ?? 'all');
        if (!in_array($universeMode, $allowedUniverseModes, true)) {
            $errors[] = 'Invalid universe_mode: ' . htmlspecialchars($universeMode);
        } else {
            $overrides['universe_mode'] = $universeMode;
        }

        // --- allowed_symbols ---
        $allowedSymbols = fishParseSymbols($p['allowed_symbols'] ?? '');
        if ($universeMode === 'manual_list' && empty($allowedSymbols)) {
            $errors[] = 'allowed_symbols must not be empty when universe_mode = manual_list.';
        }
        $overrides['allowed_symbols'] = $allowedSymbols;

        // --- excluded_symbols ---
        $overrides['excluded_symbols'] = fishParseSymbols($p['excluded_symbols'] ?? '');

        // --- window ---
        $windowEnabled = isset($p['window_enabled']);
        $overrides['window_enabled'] = $windowEnabled;

        $windowStart = trim($p['window_start'] ?? '08:00');
        $windowEnd   = trim($p['window_end']   ?? '22:00');

        if ($windowEnabled) {
            if (!preg_match('/^\d{2}:\d{2}$/', $windowStart)) {
                $errors[] = 'window_start must be in HH:MM format.';
            }
            if (!preg_match('/^\d{2}:\d{2}$/', $windowEnd)) {
                $errors[] = 'window_end must be in HH:MM format.';
            }
        }
        $overrides['window_start'] = $windowStart;
        $overrides['window_end']   = $windowEnd;

        // --- budget ---
        $budgetRaw = trim($p['budget'] ?? '0');
        if (!is_numeric($budgetRaw)) {
            $errors[] = 'budget must be a numeric value.';
        } else {
            $overrides['budget'] = (float)$budgetRaw;
        }

        // --- leverage ---
        $leverageRaw = trim($p['leverage'] ?? '1');
        if (!ctype_digit($leverageRaw) || (int)$leverageRaw < 1) {
            $errors[] = 'leverage must be a positive integer.';
        } else {
            $overrides['leverage'] = (int)$leverageRaw;
        }

        // --- sl_profile, pm_profile ---
        $slProfile = trim($p['sl_profile'] ?? 'default');
        $pmProfile = trim($p['pm_profile'] ?? 'default');
        if ($slProfile === '') {
            $errors[] = 'sl_profile must not be empty.';
        } else {
            $overrides['sl_profile'] = $slProfile;
        }
        if ($pmProfile === '') {
            $errors[] = 'pm_profile must not be empty.';
        } else {
            $overrides['pm_profile'] = $pmProfile;
        }

        if (!empty($errors)) {
            if ($isAjax) {
                http_response_code(422);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'errors' => $errors]);
            } else {
                fishSetFlash('danger', 'Validation failed: ' . implode(' | ', $errors));
                header('Location: ' . $configUrl);
            }
            exit;
        }

        $writeErr = fishWriteActive($moduleDir, $overrides);
        if ($writeErr !== null) {
            if ($isAjax) {
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => $writeErr]);
            } else {
                fishSetFlash('danger', 'Save failed: ' . $writeErr);
                header('Location: ' . $configUrl);
            }
            exit;
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'message' => 'Config saved.']);
        } else {
            fishSetFlash('success', 'Config saved successfully.');
            header('Location: ' . $configUrl);
        }
        break;

    // -----------------------------------------------------------------------
    case 'reset_active':
    // -----------------------------------------------------------------------
        $writeErr = fishWriteActive($moduleDir, []);
        if ($writeErr !== null) {
            fishSetFlash('danger', 'Reset failed: ' . $writeErr);
        } else {
            fishSetFlash('success', 'Active config cleared — base defaults are now in effect.');
        }
        header('Location: ' . $configUrl);
        break;

    // -----------------------------------------------------------------------
    case 'tick_bot':
    // -----------------------------------------------------------------------
        // Run one Fish bot execution cycle (enqueue signals + place orders + PM tick).
        try {
            $result = $service->tickBot();
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'status' => 'error', 'message' => $e->getMessage()];
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
        } else {
            $ok  = $result['ok']     ?? false;
            $msg = $result['message'] ?? ('Bot tick: '
                . ($result['orders_placed'] ?? 0) . ' orders placed, '
                . ($result['intents_processed'] ?? 0) . ' intents processed.');
            fishSetFlash($ok ? 'info' : 'warning', $msg);
            header('Location: ' . $configUrl);
        }
        break;

    // -----------------------------------------------------------------------
    default:
    // -----------------------------------------------------------------------
        http_response_code(400);
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Unknown action: ' . $action]);
        } else {
            header('Location: ' . System::web('admin/strategy/fish'));
        }
        break;
}
exit;
