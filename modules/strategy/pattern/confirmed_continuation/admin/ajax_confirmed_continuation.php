<?php

declare(strict_types=1);

/**
 * Confirmed Continuation Strategy — Admin AJAX Handler
 *
 * Handles: save_config, queue_run, tick_batch, get_last_run, get_signals
 */

use Core\System\System;
use Core\System\SystemPaths;
use Core\Auth\Auth;

if (!Auth::check()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$moduleDir = SystemPaths::instance()->get('strategy.confirmed_continuation');

require_once $moduleDir . '/service.php';
require_once $moduleDir . '/bootstrap.php';

use Modules\Strategy\ConfirmedContinuation\ConfirmedContinuationService;
use Modules\Strategy\ConfirmedContinuation\ConfirmedContinuationBootstrap;

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

switch ($action) {
    case 'save_config':
        $activePath = $moduleDir . '/config/active.php';

        // Load existing active config to preserve unmanaged keys
        $existing = [];
        if (is_file($activePath)) {
            try {
                $loaded = require $activePath;
                if (is_array($loaded)) {
                    $existing = $loaded;
                }
            } catch (\Throwable) {
                $existing = [];
            }
        }

        // Fields managed by this form
        $managed = [
            'enabled',
            'handoff_enabled',
            'side_mode',
            'batch_size',
            'max_symbols_per_run',
            'min_structure_score',
            'min_candidate_quality_score',
            'orderbook_wall_gate_enabled',
            'orderbook_wall_soft_demote_blocks_handoff',
            'anti_comb_enabled',
            'day_regime_filter_enabled',
            'wall_decision_test_enabled',
        ];

        $updated = $existing;

        if (isset($_POST['enabled'])) {
            $updated['enabled'] = (bool)(int)$_POST['enabled'];
        }
        if (isset($_POST['handoff_enabled'])) {
            $updated['handoff_enabled'] = (bool)(int)$_POST['handoff_enabled'];
        }
        if (isset($_POST['side_mode']) && in_array($_POST['side_mode'], ['all', 'long', 'short'], true)) {
            $updated['side_mode'] = (string)$_POST['side_mode'];
        }
        if (isset($_POST['batch_size']) && (int)$_POST['batch_size'] > 0) {
            $updated['batch_size'] = (int)$_POST['batch_size'];
        }
        if (isset($_POST['max_symbols_per_run']) && (int)$_POST['max_symbols_per_run'] > 0) {
            $updated['max_symbols_per_run'] = (int)$_POST['max_symbols_per_run'];
        }
        if (isset($_POST['min_structure_score'])) {
            $updated['min_structure_score'] = (float)$_POST['min_structure_score'];
        }
        if (isset($_POST['min_candidate_quality_score'])) {
            $updated['min_candidate_quality_score'] = (float)$_POST['min_candidate_quality_score'];
        }
        if (isset($_POST['orderbook_wall_gate_enabled'])) {
            $updated['orderbook_wall_gate_enabled'] = (bool)(int)$_POST['orderbook_wall_gate_enabled'];
        }
        if (isset($_POST['orderbook_wall_soft_demote_blocks_handoff'])) {
            $updated['orderbook_wall_soft_demote_blocks_handoff'] = (bool)(int)$_POST['orderbook_wall_soft_demote_blocks_handoff'];
        }
        if (isset($_POST['anti_comb_enabled'])) {
            $updated['anti_comb_enabled'] = (bool)(int)$_POST['anti_comb_enabled'];
        }
        if (isset($_POST['day_regime_filter_enabled'])) {
            $updated['day_regime_filter_enabled'] = (bool)(int)$_POST['day_regime_filter_enabled'];
        }
        if (isset($_POST['wall_decision_test_enabled'])) {
            $updated['wall_decision_test_enabled'] = (bool)(int)$_POST['wall_decision_test_enabled'];
        }

        // Write config/active.php as PHP array
        $lines   = ["<?php\n\ndeclare(strict_types=1);\n\n"];
        $lines[] = "/**\n * Confirmed Continuation Strategy — Active Config Overrides\n";
        $lines[] = " * Written by the admin UI. Edit via the config page.\n";
        $lines[] = " *\n * Demo-domain safety: strategy signals are environment-neutral.\n";
        $lines[] = " * Bot owns execution mode globally.\n */\n\nreturn ";
        $lines[] = var_export($updated, true);
        $lines[] = ";\n";

        $php = implode('', $lines);
        $dir = dirname($activePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $written = @file_put_contents($activePath, $php, LOCK_EX) !== false;

        // Build effective values from what was saved
        $effective = [];
        foreach ($managed as $k) {
            if (array_key_exists($k, $updated)) {
                $effective[$k] = $updated[$k];
            }
        }

        echo json_encode([
            'ok'              => $written,
            'saved_keys'      => $managed,
            'effective_values'=> $effective,
        ]);
        break;

    case 'queue_run':
        $service = ConfirmedContinuationService::instance($moduleDir);
        $result  = $service->queueRun();
        echo json_encode(['ok' => true, 'result' => $result]);
        break;

    case 'tick_batch':
        $service = ConfirmedContinuationService::instance($moduleDir);
        $result  = $service->tickBatch();
        echo json_encode(['ok' => true, 'result' => $result]);
        break;

    case 'get_last_run':
        $path    = $moduleDir . '/storage/last_run.json';
        $content = is_file($path) ? @file_get_contents($path) : '{}';
        echo $content !== false ? $content : '{}';
        break;

    case 'get_signals':
        $path    = $moduleDir . '/storage/signals.json';
        $content = is_file($path) ? @file_get_contents($path) : '[]';
        echo $content !== false ? $content : '[]';
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => "Unknown action: {$action}"]);
        break;
}
