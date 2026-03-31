<?php
/**
 * Smart Brain Module - Shared Navigation Tabs
 * 
 * Usage: Include this file with $activeTab set to current page name
 * Available tabs: dashboard, user_config, config, analizator, simulator, passports, runtime
 */

$smartBrainUrl = $smartBrainUrl ?? '/admin/smart_brain';
$activeTab = $activeTab ?? 'dashboard';
?>
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'dashboard' ? 'active' : '' ?>" href="<?= $smartBrainUrl ?>">
            <i class="bi bi-house me-1"></i> Dashboard
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'user_config' ? 'active' : '' ?>" href="<?= $smartBrainUrl ?>/user_config">
            <i class="bi bi-sliders me-1"></i> User Config
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'config' ? 'active' : '' ?>" href="<?= $smartBrainUrl ?>/config">
            <i class="bi bi-gear me-1"></i> Global Config
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'analizator' ? 'active' : '' ?>" href="<?= $smartBrainUrl ?>/analizator">
            <i class="bi bi-graph-up me-1"></i> Analyzer
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'simulator' ? 'active' : '' ?>" href="<?= $smartBrainUrl ?>/simulator">
            <i class="bi bi-joystick me-1"></i> Simulator
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'simulator_analytics' ? 'active' : '' ?>" href="<?= $smartBrainUrl ?>/simulator_analytics">
            <i class="bi bi-graph-up-arrow me-1"></i> Sim Analytics
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'passports' ? 'active' : '' ?>" href="<?= $smartBrainUrl ?>/passports">
            <i class="bi bi-card-checklist me-1"></i> Coin Passports
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'live_performance' ? 'active' : '' ?>" href="<?= $smartBrainUrl ?>/live_performance">
            <i class="bi bi-heart-pulse me-1"></i> Live Performance
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'runtime' ? 'active' : '' ?>" href="<?= $smartBrainUrl ?>/runtime">
            <i class="bi bi-activity me-1"></i> Runtime
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'maintenance' ? 'active' : '' ?>" href="<?= $smartBrainUrl ?>/maintenance">
            <i class="bi bi-wrench-adjustable me-1"></i> Maintenance
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'ai_shadow' ? 'active' : '' ?>" href="<?= $smartBrainUrl ?>/ai_shadow">
            <i class="bi bi-robot me-1"></i> AI Shadow
        </a>
    </li>
</ul>
