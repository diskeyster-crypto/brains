<?php
/**
 * Smart Brain Module - Global Config View
 * 
 * Phase 2: Centralized configuration system.
 * Shows mode (manual/auto), settings, and auto_rules per section.
 * Displays runtime override indicators.
 */

/** @var string $smartBrainUrl */
/** @var array<string,mixed> $config */

$pageTitle = 'Smart Brain - Global Config';
$activeTab = 'config';

$pageContent = function() use ($config, $smartBrainUrl) {
    $sections = [
        'parser4'     => ['icon' => 'bi-search',            'title' => 'Parser4 (Analyzer)'],
        'corridor'    => ['icon' => 'bi-arrows-expand',     'title' => 'Corridor Monitor'],
        'risk_engine' => ['icon' => 'bi-shield-exclamation', 'title' => 'Risk Engine'],
        'simulator'   => ['icon' => 'bi-joystick',          'title' => 'Simulator'],
        'profiles'    => ['icon' => 'bi-person-badge',       'title' => 'Profiles'],
        'ui'          => ['icon' => 'bi-palette',            'title' => 'UI Settings'],
    ];

    $renderValue = function($value) use (&$renderValue): string {
        if (is_bool($value)) {
            return '<span class="badge ' . ($value ? 'bg-success' : 'bg-danger') . '">' . ($value ? 'true' : 'false') . '</span>';
        }
        if (is_array($value)) {
            return '<details><summary class="text-info" style="cursor:pointer;">Array (' . count($value) . ')</summary>'
                . '<pre style="background:#0f172a; padding:8px; border-radius:4px; margin-top:4px; font-size:0.8rem; max-height:200px; overflow:auto;">'
                . htmlspecialchars(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                . '</pre></details>';
        }
        if (is_numeric($value)) {
            return '<span class="text-warning">' . htmlspecialchars((string)$value) . '</span>';
        }
        return htmlspecialchars((string)$value);
    };
?>
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-gear me-2 text-primary"></i>Global Configuration</h4>
            <p class="text-secondary mb-0">All Smart Brain configuration sections — mode / settings / auto_rules</p>
        </div>
    </div>

    <div class="row">
    <?php foreach ($sections as $key => $meta): ?>
        <?php $sectionData = $config[$key] ?? []; ?>
        <?php
            $mode = $sectionData['mode'] ?? null;
            $settings = $sectionData['settings'] ?? null;
            $autoRules = $sectionData['auto_rules'] ?? null;
            $isNewFormat = ($mode !== null && $settings !== null);
        ?>
        <div class="col-md-6 mb-4">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center">
                    <i class="bi <?= $meta['icon'] ?> me-2"></i>
                    <h5 style="margin: 0;"><?= htmlspecialchars($meta['title']) ?></h5>
                    <span class="badge bg-secondary ms-2"><?= $key ?></span>
                    <?php if ($isNewFormat): ?>
                        <span class="badge ms-auto <?= $mode === 'auto' ? 'bg-warning' : 'bg-info' ?>"><?= htmlspecialchars((string)$mode) ?></span>
                    <?php endif; ?>
                </div>
                <div class="card-body p-0">
                    <?php if ($isNewFormat && is_array($settings)): ?>
                    <!-- Settings Table -->
                    <table class="table table-dark table-hover mb-0" style="font-size: 0.9rem;">
                        <thead><tr><th style="width:40%;">Parameter</th><th>Value</th></tr></thead>
                        <tbody>
                        <?php foreach ($settings as $param => $value): ?>
                        <tr>
                            <td><code><?= htmlspecialchars((string)$param) ?></code></td>
                            <td><?= $renderValue($value) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if (is_array($autoRules) && !empty($autoRules)): ?>
                    <div style="border-top:1px solid var(--border-color); padding:10px 16px; font-size:0.85rem;">
                        <strong class="text-secondary">Auto Rules:</strong>
                        <?php foreach ($autoRules as $rk => $rv): ?>
                            <span class="ms-2"><code><?= htmlspecialchars((string)$rk) ?></code> = <?= $renderValue($rv) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php else: ?>
                    <!-- Legacy flat format -->
                    <table class="table table-dark table-hover mb-0" style="font-size: 0.9rem;">
                        <thead><tr><th style="width:40%;">Parameter</th><th>Value</th></tr></thead>
                        <tbody>
                        <?php if (empty($sectionData)): ?>
                            <tr><td colspan="2" class="text-center text-secondary py-3">No configuration</td></tr>
                        <?php else: ?>
                            <?php foreach ($sectionData as $param => $value): ?>
                            <tr>
                                <td><code><?= htmlspecialchars((string)$param) ?></code></td>
                                <td><?= $renderValue($value) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
<?php
};

require __DIR__ . '/_layout.php';
