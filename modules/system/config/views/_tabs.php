<?php
declare(strict_types=1);

use Core\System\System;

/**
 * Unified Config Module — Tab navigation (Russian UI)
 */

$baseUrl = $baseUrl ?? '/admin/smart_brain/config_all';

$tabs = [
    'quick_control'  => ['label' => 'Быстрое управление', 'icon' => 'bi-sliders',         'url' => $baseUrl],
    'patterns'       => ['label' => 'Паттерны',           'icon' => 'bi-diagram-3',        'url' => $baseUrl . '/patterns'],
    'smart_brain'    => ['label' => 'Smart Brain',        'icon' => 'bi-cpu',              'url' => $baseUrl . '/smart_brain'],
    'trading_bot'    => ['label' => 'Trading Bot',        'icon' => 'bi-robot',            'url' => $baseUrl . '/trading_bot'],
    'coin_cycle'     => ['label' => 'Coin Passport',      'icon' => 'bi-coin',             'url' => $baseUrl . '/coin_cycle'],
    'win_universe'   => ['label' => 'Выигрышные монеты',  'icon' => 'bi-trophy',           'url' => $baseUrl . '/win_universe'],
    'advanced'       => ['label' => 'Расширенный',        'icon' => 'bi-tools',            'url' => $baseUrl . '/advanced'],
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
