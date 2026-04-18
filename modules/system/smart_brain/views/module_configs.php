<?php
/**
 * Smart Brain — Module Configs Hub
 *
 * Surfaces per-module configs in Brain UI using module-owned config descriptors.
 * This is a preparation step for a future simplified unified user config.
 *
 * Each module owns its own real config file. Brain renders config sections
 * using per-module descriptors (field metadata, grouping, labels, types).
 *
 * Modules exposed:
 * - Pattern Engine     (modules/system/pattern_engine/config/pattern_engine.json)
 * - Coin Passport      (modules/system/coin_passport/config/coin_passport.json)
 * - Trading Bot        (modules/system/trading_bot/config/bot.json)
 * - AI Shadow          (modules/system/ai_shadow/config/ai_shadow.json)
 *
 * @var string                       $smartBrainUrl
 * @var array<string,mixed>          $pattern_engine_config
 * @var array<string,mixed>          $coin_passport_config
 * @var array<string,mixed>          $trading_bot_config
 * @var array<string,mixed>          $ai_shadow_config
 * @var list<array<string,mixed>>    $pattern_engine_descriptor
 * @var list<array<string,mixed>>    $coin_passport_descriptor
 * @var list<array<string,mixed>>    $trading_bot_descriptor
 * @var list<array<string,mixed>>    $ai_shadow_descriptor
 */

$pageTitle = 'Smart Brain — Module Configs';
$activeTab = 'module_configs';

$pattern_engine_config   = $pattern_engine_config   ?? [];
$coin_passport_config    = $coin_passport_config    ?? [];
$trading_bot_config      = $trading_bot_config      ?? [];
$ai_shadow_config        = $ai_shadow_config        ?? [];
$pattern_engine_descriptor = $pattern_engine_descriptor ?? [];
$coin_passport_descriptor  = $coin_passport_descriptor  ?? [];
$trading_bot_descriptor    = $trading_bot_descriptor    ?? [];
$ai_shadow_descriptor      = $ai_shadow_descriptor      ?? [];

$extraStyles = '
.mc-module-card   { background: #1e293b; border: 1px solid #334155; border-radius: 10px; overflow: hidden; margin-bottom: 1.5rem; }
.mc-module-header { background: #0f172a; padding: 0.75rem 1.25rem; border-bottom: 1px solid #334155; }
.mc-module-title  { font-size: 1rem; font-weight: 600; color: #e2e8f0; }
.mc-module-sub    { font-size: 0.75rem; color: #64748b; margin-top: 0.15rem; }
.mc-group-label   { font-size: 0.7rem; font-weight: 700; letter-spacing: 0.08em; color: #64748b; text-transform: uppercase; margin: 1rem 0 0.4rem; }
.mc-field-row     { display: flex; align-items: flex-start; gap: 0.75rem; padding: 0.35rem 0; border-bottom: 1px solid rgba(255,255,255,0.04); }
.mc-field-label   { flex: 0 0 200px; font-size: 0.8rem; color: #94a3b8; }
.mc-field-value   { flex: 1; font-size: 0.82rem; color: #e2e8f0; word-break: break-all; }
.mc-field-user    { flex: 0 0 60px; text-align: right; }
.mc-badge-user    { background: rgba(59,130,246,0.15); color: #60a5fa; border: 1px solid rgba(59,130,246,0.2); border-radius: 4px; padding: 0.1rem 0.4rem; font-size: 0.65rem; }
.mc-badge-adv     { background: rgba(148,163,184,0.1); color: #64748b; border-radius: 4px; padding: 0.1rem 0.4rem; font-size: 0.65rem; }
.mc-missing       { color: #6b7280; font-style: italic; }
';

$pageContent = function() use (
    $smartBrainUrl,
    $pattern_engine_config, $coin_passport_config, $trading_bot_config, $ai_shadow_config,
    $pattern_engine_descriptor, $coin_passport_descriptor, $trading_bot_descriptor, $ai_shadow_descriptor
) {

    /**
     * Resolve a dot-notation key from a nested config array.
     * e.g. 'execution.stop_loss_pct' → $config['execution']['stop_loss_pct']
     */
    $resolveKey = static function(string $key, array $config): mixed {
        $parts = explode('.', $key);
        $cur = $config;
        foreach ($parts as $part) {
            if (!is_array($cur) || !array_key_exists($part, $cur)) {
                return null;
            }
            $cur = $cur[$part];
        }
        return $cur;
    };

    /**
     * Render a value for display.
     */
    $renderValue = static function(mixed $val, string $type): string {
        if ($val === null) {
            return '<span class="mc-missing">not set</span>';
        }
        if ($type === 'bool' || is_bool($val)) {
            return $val ? '<span class="badge bg-success">true</span>' : '<span class="badge bg-secondary">false</span>';
        }
        if ($type === 'select' || $type === 'string') {
            return '<code>' . htmlspecialchars((string)$val) . '</code>';
        }
        if ($type === 'float') {
            return number_format((float)$val, 4);
        }
        if ($type === 'int') {
            return (string)(int)$val;
        }
        if (is_array($val)) {
            return '<span class="text-secondary small">[' . count($val) . ' item(s)]</span>';
        }
        return htmlspecialchars((string)$val);
    };

    /**
     * Render a module config card using its descriptor.
     */
    $renderModuleCard = static function(
        string $title,
        string $subtitle,
        string $configPath,
        array  $config,
        array  $descriptor,
        string $editUrl
    ) use ($resolveKey, $renderValue): void {
        $available = !empty($config);
        $groups = [];
        foreach ($descriptor as $field) {
            $g = $field['group'] ?? 'General';
            $groups[$g][] = $field;
        }
        ?>
        <div class="mc-module-card">
            <div class="mc-module-header d-flex justify-content-between align-items-center">
                <div>
                    <div class="mc-module-title">
                        <i class="bi bi-box me-2 text-primary"></i><?= htmlspecialchars($title) ?>
                    </div>
                    <div class="mc-module-sub"><?= htmlspecialchars($subtitle) ?></div>
                </div>
                <div class="d-flex gap-2">
                    <?php if ($editUrl): ?>
                    <a href="<?= htmlspecialchars($editUrl) ?>" class="btn btn-sm btn-outline-secondary" target="_blank">
                        <i class="bi bi-pencil me-1"></i>Edit
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="p-3">
                <?php if (!$available): ?>
                    <div class="text-secondary small"><i class="bi bi-info-circle me-1"></i>Config file not found at <code><?= htmlspecialchars($configPath) ?></code></div>
                <?php else: ?>
                    <!-- Field legend -->
                    <div class="d-flex gap-3 mb-2" style="font-size:0.7rem; color:#64748b;">
                        <span><span class="mc-badge-user">user</span> = editable by user</span>
                        <span><span class="mc-badge-adv">adv</span> = advanced / internal</span>
                    </div>
                    <!-- Column headers -->
                    <div class="mc-field-row" style="border-bottom:1px solid #334155; font-size:0.7rem; color:#64748b; padding-bottom:0.3rem; margin-bottom:0.3rem;">
                        <div class="mc-field-label">Field</div>
                        <div class="mc-field-value">Current Value</div>
                        <div class="mc-field-user">Level</div>
                    </div>
                    <?php foreach ($groups as $groupName => $fields): ?>
                        <div class="mc-group-label"><?= htmlspecialchars($groupName) ?></div>
                        <?php foreach ($fields as $field): ?>
                            <?php
                            $val = $resolveKey($field['key'], $config);
                            $isUserLevel = (bool)($field['user_level'] ?? false);
                            ?>
                            <div class="mc-field-row">
                                <div class="mc-field-label"><?= htmlspecialchars($field['label'] ?? $field['key']) ?></div>
                                <div class="mc-field-value"><?= $renderValue($val, $field['type'] ?? 'string') ?></div>
                                <div class="mc-field-user">
                                    <?php if ($isUserLevel): ?>
                                        <span class="mc-badge-user">user</span>
                                    <?php else: ?>
                                        <span class="mc-badge-adv">adv</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    <div class="mt-2 text-secondary" style="font-size:0.72rem;">
                        Config file: <code><?= htmlspecialchars($configPath) ?></code>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    };
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1"><i class="bi bi-puzzle me-2 text-primary"></i>Module Configs</h4>
        <p class="text-secondary mb-0">
            Per-module configuration overview. Each module owns its config file.
            Brain renders it here via module-owned config descriptors — preparation for a future unified user config.
        </p>
    </div>
</div>

<!-- Future unified config note -->
<div class="alert alert-info d-flex gap-2 mb-4" style="background:rgba(59,130,246,0.08); border-color:rgba(59,130,246,0.2);">
    <i class="bi bi-info-circle-fill text-primary mt-1"></i>
    <div>
        <strong>Preparation step:</strong> This page exposes per-module configs centrally using module-owned descriptors.
        A future simplified <em>Unified User Config</em> layer will aggregate the <span class="mc-badge-user">user</span>-level fields across all modules into a single clean form,
        hiding low-level / advanced parameters.
    </div>
</div>

<?php

$renderModuleCard(
    'Pattern Engine',
    'New upstream pattern source — normalizes signals and produces scenario decisions',
    'modules/system/pattern_engine/config/pattern_engine.json',
    $pattern_engine_config,
    $pattern_engine_descriptor,
    '/admin/pattern_engine/settings'
);

$renderModuleCard(
    'Coin Passport',
    'Per-symbol passport analytics — live eligibility gate, corridor ROI, pattern behavior',
    'modules/system/coin_passport/config/coin_passport.json',
    $coin_passport_config,
    $coin_passport_descriptor,
    '/admin/coin_passport'
);

$renderModuleCard(
    'Trading Bot',
    'Execution engine — live / demo / paper trading, position management',
    'modules/system/trading_bot/config/bot.json',
    $trading_bot_config,
    $trading_bot_descriptor,
    $smartBrainUrl . '/execution'
);

$renderModuleCard(
    'AI Shadow',
    'Shadow simulation — virtual trade lifecycle, AI provider integration',
    'modules/system/ai_shadow/config/ai_shadow.json',
    $ai_shadow_config,
    $ai_shadow_descriptor,
    $smartBrainUrl . '/ai_shadow'
);
?>

<?php
};
require_once __DIR__ . '/_layout.php';
