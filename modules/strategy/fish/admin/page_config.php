<?php

declare(strict_types=1);

/**
 * Fish Strategy — Admin Config Page
 *
 * Shows the full merged effective config with schema annotations.
 * Config editing is done via config/active.php file — not a web form.
 */

use Core\System\System;
use Core\System\SystemPaths;
use Core\Auth\Auth;

if (!Auth::check()) {
    header('Location: ' . System::adminUrl('login'));
    exit;
}

$moduleDir = SystemPaths::instance()->get('strategy.fish');

require_once $moduleDir . '/service.php';
require_once $moduleDir . '/bootstrap.php';

use Modules\Strategy\Fish\FishService;
use Modules\Strategy\Fish\FishBootstrap;

$service  = FishService::instance($moduleDir);
$snapshot = $service->getRuntimeSnapshot();

// Load config fresh to show live state
try {
    $boot = FishBootstrap::instance($moduleDir)->load();
    $config      = $boot['config'];
    $configValid = $boot['valid'];
    $configErrors = $boot['errors'];
} catch (\Throwable $e) {
    $config       = [];
    $configValid  = false;
    $configErrors = [$e->getMessage()];
}

// Load schema for type annotations
$schema = require $moduleDir . '/config/schema.php';

$fishUrl = rtrim(System::web('admin/strategy/fish'), '/');
?>
<style>
.config-table td { font-size: 12px; padding: 6px 10px !important; }
.config-table td:first-child { color: #94a3b8; width: 220px; font-family: monospace; }
.config-table td:nth-child(2) { color: #6b7280; width: 80px; font-size: 11px; }
.fish-nav { display: flex; gap: 8px; margin-bottom: 20px; }
</style>

<div style="max-width: 860px;">
    <!-- Sub-page Nav -->
    <div class="fish-nav">
        <a href="<?= $fishUrl ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-house"></i> Overview</a>
        <a href="<?= $fishUrl ?>/config" class="btn btn-primary btn-sm"><i class="bi bi-gear"></i> Config</a>
        <a href="<?= $fishUrl ?>/stats" class="btn btn-outline-secondary btn-sm"><i class="bi bi-bar-chart"></i> Stats</a>
        <a href="<?= $fishUrl ?>/runtime" class="btn btn-outline-secondary btn-sm"><i class="bi bi-activity"></i> Runtime</a>
    </div>

    <h5 class="mb-3"><i class="bi bi-gear me-1"></i> Fish — Config</h5>

    <!-- Validation Status -->
    <?php if (!$configValid): ?>
    <div class="alert alert-danger" style="font-size: 13px;">
        <strong>Config validation failed:</strong>
        <ul class="mb-0 mt-1">
            <?php foreach ($configErrors as $err): ?>
                <li><?= htmlspecialchars($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php else: ?>
    <div class="alert alert-success" style="font-size: 12px; padding: 8px 12px;">
        <i class="bi bi-check-circle me-1"></i> Config is valid.
    </div>
    <?php endif; ?>

    <!-- Effective Config Table -->
    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-list-check me-1"></i> Effective Config (base.php + active.php merged)</div>
        <div class="card-body p-0">
            <table class="table table-sm table-dark config-table mb-0">
                <thead>
                    <tr>
                        <th style="font-size: 11px;">Key</th>
                        <th style="font-size: 11px;">Type</th>
                        <th style="font-size: 11px;">Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($schema as $key => $expectedType): ?>
                    <tr>
                        <td><?= htmlspecialchars($key) ?></td>
                        <td><span class="badge bg-secondary" style="font-size: 10px;"><?= htmlspecialchars($expectedType) ?></span></td>
                        <td>
                            <?php
                            if (!array_key_exists($key, $config)) {
                                echo '<span class="text-danger">MISSING</span>';
                            } elseif (is_array($config[$key])) {
                                echo '<code style="font-size: 11px;">' . htmlspecialchars(json_encode($config[$key], JSON_UNESCAPED_UNICODE)) . '</code>';
                            } elseif (is_bool($config[$key])) {
                                $v = $config[$key];
                                echo '<span class="badge" style="background: ' . ($v ? '#22c55e' : '#6b7280') . ';">' . ($v ? 'true' : 'false') . '</span>';
                            } else {
                                echo '<code style="font-size: 11px;">' . htmlspecialchars((string)$config[$key]) . '</code>';
                            }
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Edit Instructions -->
    <div class="card">
        <div class="card-header"><i class="bi bi-info-circle me-1"></i> How to change config</div>
        <div class="card-body" style="font-size: 13px;">
            <p class="mb-1">All working values live in config files — do NOT hardcode in logic files.</p>
            <ul class="mb-0">
                <li><code>modules/strategy/fish/config/base.php</code> — default values for all parameters</li>
                <li><code>modules/strategy/fish/config/active.php</code> — operator overrides (minimal, only what differs)</li>
                <li><code>modules/strategy/fish/config/schema.php</code> — allowed keys and types (add new keys here first)</li>
            </ul>
        </div>
    </div>
</div>
