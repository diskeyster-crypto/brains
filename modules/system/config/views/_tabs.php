<?php
declare(strict_types=1);

use Core\System\System;

/**
 * Unified Config Module — Tab navigation
 */

$baseUrl = $baseUrl ?? '/admin/smart_brain/config';

$tabs = [
    'quick_control'  => ['label' => 'Quick Control',    'icon' => 'bi-sliders',         'url' => $baseUrl],
    'patterns'       => ['label' => 'Patterns',         'icon' => 'bi-diagram-3',        'url' => $baseUrl . '/patterns'],
    'smart_brain'    => ['label' => 'Smart Brain',      'icon' => 'bi-cpu',              'url' => $baseUrl . '/smart_brain'],
    'trading_bot'    => ['label' => 'Trading Bot',      'icon' => 'bi-robot',            'url' => $baseUrl . '/trading_bot'],
    'profit_manager' => ['label' => 'Profit Manager',   'icon' => 'bi-cash-coin',        'url' => $baseUrl . '/profit_manager'],
    'coin_cycle'     => ['label' => 'Coin / Cycle',     'icon' => 'bi-coin',             'url' => $baseUrl . '/coin_cycle'],
    'advanced'       => ['label' => 'Advanced / Expert','icon' => 'bi-tools',            'url' => $baseUrl . '/advanced'],
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
