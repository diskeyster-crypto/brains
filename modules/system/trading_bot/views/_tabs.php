<?php
declare(strict_types=1);

use Core\System\System;

/**
 * Trading Bot tabs navigation
 */

// Define tabs
$tabs = [
    'dashboard' => ['label' => 'Dashboard', 'icon' => 'bi-speedometer2', 'url' => System::adminUrl('trading')],
    'intents' => ['label' => 'Intents', 'icon' => 'bi-lightning', 'url' => System::adminUrl('trading/intents')],
    'trades' => ['label' => 'Trades', 'icon' => 'bi-graph-up', 'url' => System::adminUrl('trading/trades')],
    'orders' => ['label' => 'Orders', 'icon' => 'bi-list-check', 'url' => System::adminUrl('trading/orders')],
    'profit' => ['label' => 'Profit', 'icon' => 'bi-cash-coin', 'url' => System::adminUrl('trading/profit')],
    'settings' => ['label' => 'Settings', 'icon' => 'bi-gear', 'url' => System::adminUrl('trading/settings')],
    'logs' => ['label' => 'Logs', 'icon' => 'bi-file-text', 'url' => System::adminUrl('trading/logs')],
];

?>

<ul class="nav nav-tabs mb-4">
    <?php foreach ($tabs as $key => $tabData): ?>
        <li class="nav-item">
            <a class="nav-link <?= ($tab === $key) ? 'active' : '' ?>" 
               href="<?= htmlspecialchars($tabData['url']) ?>">
                <i class="bi <?= htmlspecialchars($tabData['icon']) ?> me-1"></i>
                <?= htmlspecialchars($tabData['label']) ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>
