<?php
/**
 * Smart Brain Module - Shared Navigation Tabs
 * 
 * Usage: Include this file with $activeTab set to current page name
 * Available tabs: dashboard, config, analizator
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
        <a class="nav-link <?= $activeTab === 'config' ? 'active' : '' ?>" href="<?= $smartBrainUrl ?>/config">
            <i class="bi bi-gear me-1"></i> Global config
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'analizator' ? 'active' : '' ?>" href="<?= $smartBrainUrl ?>/analizator">
            <i class="bi bi-graph-up me-1"></i> Analizator
        </a>
    </li>
</ul>
