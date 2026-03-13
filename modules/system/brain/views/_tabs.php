<?php
/**
 * Brain Module - Shared Navigation Tabs
 * 
 * Usage: Include this file with $activeTab set to current page name
 * Available tabs: dashboard, strategies, profiles, passports, coin-passports, runtime, decisions, runs
 */

use Core\System\System;

$brainUrl = rtrim(System::web('admin/brain'), '/');
$activeTab = $activeTab ?? 'dashboard';
?>
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'dashboard' ? 'active' : '' ?>" href="<?= $brainUrl ?>">
            <i class="bi bi-house me-1"></i> Dashboard
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'strategies' ? 'active' : '' ?>" href="<?= $brainUrl ?>/strategies">
            <i class="bi bi-diagram-3 me-1"></i> Strategies
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'profiles' ? 'active' : '' ?>" href="<?= $brainUrl ?>/profiles">
            <i class="bi bi-shield-check me-1"></i> Profiles
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'passports' ? 'active' : '' ?>" href="<?= $brainUrl ?>/passports">
            <i class="bi bi-card-checklist me-1"></i> Passports
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'coin-passports' ? 'active' : '' ?>" href="<?= $brainUrl ?>/coin-passports">
            <i class="bi bi-coin me-1"></i> Coins
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'runtime' ? 'active' : '' ?>" href="<?= $brainUrl ?>/runtime">
            <i class="bi bi-activity me-1"></i> Runtime
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'decisions' ? 'active' : '' ?>" href="<?= $brainUrl ?>/decisions">
            <i class="bi bi-check2-circle me-1"></i> Decisions
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'runs' ? 'active' : '' ?>" href="<?= $brainUrl ?>/runs">
            <i class="bi bi-clock-history me-1"></i> History
        </a>
    </li>
</ul>
