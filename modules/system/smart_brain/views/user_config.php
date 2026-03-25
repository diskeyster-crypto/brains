<?php
/**
 * Smart Brain Module - User Config View
 *
 * Editable form for user hard limits.
 * Save / Reset / Reload buttons.
 */

/** @var string $smartBrainUrl */
/** @var array<string,mixed> $user_limits */
/** @var array<string,mixed> $form_values */
/** @var array{type:string,message:string}|null $flash */
/** @var list<string> $patterns_enabled */
/** @var string $pattern_mode */
/** @var bool $symbol_intelligence_enabled */
/** @var string $symbol_filter_mode */
/** @var bool $manual_symbol_universe_enabled */
/** @var string $manual_symbol_mode */
/** @var list<string> $config_warnings */
/** @var array{symbols:list<string>,count:int,valid:bool,warning:string} $manual_blacklist */

$pageTitle = 'Smart Brain - User Config';
$activeTab = 'user_config';

$patterns_enabled = $patterns_enabled ?? [];
$pattern_mode = $pattern_mode ?? 'any';
$symbol_intelligence_enabled = $symbol_intelligence_enabled ?? false;
$symbol_filter_mode = $symbol_filter_mode ?? 'all';
$manual_symbol_universe_enabled = $manual_symbol_universe_enabled ?? false;
$manual_symbol_mode = $manual_symbol_mode ?? 'manual_only';
$config_warnings = $config_warnings ?? [];
$manual_blacklist = $manual_blacklist ?? ['symbols' => [], 'count' => 0, 'valid' => true, 'warning' => ''];

$pageContent = function() use ($form_values, $user_limits, $smartBrainUrl, $patterns_enabled, $pattern_mode, $symbol_intelligence_enabled, $symbol_filter_mode, $manual_symbol_universe_enabled, $manual_symbol_mode, $config_warnings, $manual_blacklist) {
    $v = function(string $key, $default = '') use ($form_values) {
        return htmlspecialchars((string)($form_values[$key] ?? $default));
    };
    $checked = function(string $key) use ($form_values): string {
        return !empty($form_values[$key]) ? 'checked' : '';
    };
    $patternChecked = function(string $algo) use ($patterns_enabled): string {
        return in_array($algo, $patterns_enabled, true) ? 'checked' : '';
    };
?>
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-sliders me-2 text-primary"></i>User Config</h4>
            <p class="text-secondary mb-0">Edit hard limits — these are user-controlled values</p>
        </div>
    </div>

    <!-- Config Conflict Guard: warnings -->
    <?php if (!empty($config_warnings)): ?>
    <div class="alert alert-warning mb-4" style="font-size:0.85rem;">
        <h6 class="mb-2"><i class="bi bi-exclamation-triangle-fill me-1"></i> Config Conflict Guard</h6>
        <?php foreach ($config_warnings as $cw): ?>
        <div class="mb-1"><i class="bi bi-exclamation-triangle me-1"></i> <?= htmlspecialchars($cw) ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="<?= htmlspecialchars($smartBrainUrl) ?>/user_config/save" id="user-config-form">

        <!-- Execution Profile Section -->
        <?php
            $currentProfile = (string)($form_values['execution_profile'] ?? 'custom');
            $profileBundles = SmartBrainConfig::getExecutionProfileBundles();
            $managedFields = SmartBrainConfig::getProfileManagedFields();
            $isProfileMode = $currentProfile !== 'custom';
            $currentBundle = $profileBundles[$currentProfile] ?? $profileBundles['custom'];
            $patternRoutingBundles = SmartBrainConfig::getProfilePatternRoutingBundles();
            $currentPatternRouting = $patternRoutingBundles[$currentProfile] ?? $patternRoutingBundles['custom'];
            $patternProfileMode = (string)($form_values['pattern_profile_mode'] ?? 'manual_override');
            $isPatternProfileControlled = $isProfileMode && $patternProfileMode === 'profile_controlled';
            $patternLabels = [
                'double_bottom' => 'Double Bottom',
                'double_top' => 'Double Top',
                'pullback_trend_continue' => 'Pullback Trend Continue',
                'double_bottom_confirm_v2' => 'Double Bottom V2 (confirmed)',
                'double_top_confirm_v2' => 'Double Top V2 (confirmed)',
                'double_bottom_contextual_v2' => 'Double Bottom Contextual V2',
                'double_bottom_contextual_v3' => 'Double Bottom Contextual V3',
            ];
        ?>
        <div class="card mb-4" style="border-color: <?= $isProfileMode ? '#6366f1' : '#6b7280' ?>;">
            <div class="card-header d-flex align-items-center" style="background: <?= $isProfileMode ? 'rgba(99,102,241,0.1)' : 'rgba(107,114,128,0.1)' ?>;">
                <i class="bi bi-crosshair me-2"></i>
                <h5 style="margin: 0;">Execution Profile</h5>
                <?php if ($isProfileMode): ?>
                <span class="badge bg-primary ms-2"><?= htmlspecialchars($currentBundle['label']) ?></span>
                <?php else: ?>
                <span class="badge bg-secondary ms-2">Custom</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <p class="text-secondary mb-3" style="font-size: 0.85rem;">
                    Choose an execution profile to apply a coordinated bundle of V2 entry quality parameters and pattern routing.
                    Profiles control confirmation thresholds, zone widening, entry policy, quality floors, and which patterns are live.
                    Select <strong>Custom</strong> to edit all fields manually.
                </p>
                <div class="row">
                    <div class="col-md-5 mb-3">
                        <label for="execution_profile" class="form-label fw-bold">Active Profile</label>
                        <select class="form-select" id="execution_profile" name="execution_profile">
                            <?php foreach ($profileBundles as $pid => $pbundle): ?>
                            <option value="<?= htmlspecialchars($pid) ?>" <?= $currentProfile === $pid ? 'selected' : '' ?>>
                                <?= htmlspecialchars($pbundle['label']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-7 mb-3">
                        <div id="profile-description-card" class="alert <?= $isProfileMode ? 'alert-info' : 'alert-secondary' ?> mb-0" style="font-size: 0.85rem;">
                            <strong id="profile-desc-label"><?= htmlspecialchars($currentBundle['label']) ?>: </strong>
                            <span id="profile-desc-text"><?= htmlspecialchars($currentBundle['description']) ?></span>
                            <?php if ($currentProfile === 'sniper_75_attempt'): ?>
                            <div class="mt-1 text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Very selective profile. Fewer trades expected. Higher target precision, not guaranteed winrate.</div>
                            <?php elseif ($currentProfile === 'sniper_lite'): ?>
                            <div class="mt-1 text-info"><i class="bi bi-info-circle me-1"></i>Moderately selective V3-only profile. Allows strong and selected medium confirmations. Higher signal count than Sniper.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Pattern Routing Policy Summary -->
                <?php if ($isProfileMode): ?>
                <div class="mt-2 mb-2">
                    <h6 class="mb-2"><i class="bi bi-diagram-3 me-1"></i>Pattern Routing Policy</h6>
                    <div class="row" style="font-size: 0.85rem;">
                        <div class="col-md-4 mb-2">
                            <div class="card border-success" style="background: rgba(34,197,94,0.05);">
                                <div class="card-body py-2 px-3">
                                    <div class="fw-bold text-success mb-1"><i class="bi bi-check-circle me-1"></i>Live</div>
                                    <?php if (!empty($currentPatternRouting['live_patterns'])): ?>
                                        <?php foreach ($currentPatternRouting['live_patterns'] as $lp): ?>
                                        <div><code><?= htmlspecialchars($patternLabels[$lp] ?? $lp) ?></code></div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="text-secondary">—</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-2">
                            <div class="card border-warning" style="background: rgba(234,179,8,0.05);">
                                <div class="card-body py-2 px-3">
                                    <div class="fw-bold text-warning mb-1"><i class="bi bi-eye me-1"></i>Shadow</div>
                                    <?php if (!empty($currentPatternRouting['shadow_patterns'])): ?>
                                        <?php foreach ($currentPatternRouting['shadow_patterns'] as $sp): ?>
                                        <div><code><?= htmlspecialchars($patternLabels[$sp] ?? $sp) ?></code></div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="text-secondary">—</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-2">
                            <div class="card border-secondary" style="background: rgba(107,114,128,0.05);">
                                <div class="card-body py-2 px-3">
                                    <div class="fw-bold text-secondary mb-1"><i class="bi bi-x-circle me-1"></i>Disabled</div>
                                    <?php if (!empty($currentPatternRouting['disabled_patterns'])): ?>
                                        <?php foreach ($currentPatternRouting['disabled_patterns'] as $dp): ?>
                                        <div><code class="text-muted"><?= htmlspecialchars($patternLabels[$dp] ?? $dp) ?></code></div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="text-secondary">—</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label for="pattern_profile_mode" class="form-label fw-bold" style="font-size: 0.85rem;">Pattern Routing Mode</label>
                        <select class="form-select form-select-sm" id="pattern_profile_mode" name="pattern_profile_mode" style="max-width: 320px;">
                            <option value="profile_controlled" <?= $patternProfileMode === 'profile_controlled' ? 'selected' : '' ?>>Use profile pattern set</option>
                            <option value="manual_override" <?= $patternProfileMode === 'manual_override' ? 'selected' : '' ?>>Manual pattern override</option>
                        </select>
                        <small class="text-secondary d-block mt-1">When «Use profile pattern set» is active, the profile controls which patterns are live. Switch to «Manual» to override.</small>
                    </div>
                </div>
                <?php else: ?>
                <input type="hidden" name="pattern_profile_mode" value="manual_override">
                <?php endif; ?>

                <!-- Profile Managed Fields Preview (collapsible) -->
                <div class="mt-1">
                    <a class="text-decoration-none small" data-bs-toggle="collapse" href="#profile-managed-fields" role="button" aria-expanded="false">
                        <i class="bi bi-gear me-1"></i>Show managed V2 parameters (<?= count($managedFields) ?> fields)
                    </a>
                    <div class="collapse mt-2" id="profile-managed-fields">
                        <div class="card card-body" style="font-size: 0.82rem; background: rgba(255,255,255,0.03);">
                            <p class="mb-2 text-secondary">These V2 entry quality fields are controlled by the selected profile. In <strong>Custom</strong> mode they are editable via <em>config/risk_engine.php</em> user_limits.</p>
                            <table class="table table-sm table-dark mb-0">
                                <thead><tr><th>Field</th><th>Active Value</th></tr></thead>
                                <tbody>
                                <?php foreach ($managedFields as $mf): ?>
                                <tr>
                                    <td><code><?= htmlspecialchars($mf) ?></code></td>
                                    <td>
                                        <?php
                                            if ($isProfileMode && array_key_exists($mf, $currentBundle['values'])) {
                                                $mfVal = $currentBundle['values'][$mf];
                                                if (is_bool($mfVal)) {
                                                    echo $mfVal ? '<span class="text-success">true</span>' : '<span class="text-danger">false</span>';
                                                } else {
                                                    echo '<span class="text-info">' . htmlspecialchars((string)$mfVal) . '</span>';
                                                }
                                                echo ' <small class="text-secondary">(profile)</small>';
                                            } else {
                                                $val = $form_values[$mf] ?? $user_limits[$mf] ?? '-';
                                                if (is_bool($val)) {
                                                    echo $val ? '<span class="text-success">true</span>' : '<span class="text-danger">false</span>';
                                                } else {
                                                    echo htmlspecialchars((string)$val);
                                                }
                                                echo ' <small class="text-secondary">(manual)</small>';
                                            }
                                        ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sniper V3 Live Filters (visible only for sniper profiles) -->
        <?php if (in_array($currentProfile, ['sniper_75_attempt', 'sniper_lite'], true)): ?>
        <?php
            $sniperV3Fields = SmartBrainConfig::getSniperV3FilterFields();
            $sniperV3AllowedTiers = SmartBrainConfig::getSniperV3AllowedTiers($currentProfile);
            $sniperV3Enabled = (bool)($form_values['sniper_v3_live_filter_enabled'] ?? $currentBundle['values']['sniper_v3_live_filter_enabled'] ?? true);
        ?>
        <div class="card mb-4" style="border-color: #f59e0b;">
            <div class="card-header d-flex align-items-center" style="background: rgba(245,158,11,0.1);">
                <i class="bi bi-crosshair2 me-2"></i>
                <h5 style="margin: 0;">Sniper V3 Live Filters</h5>
                <span class="badge <?= $sniperV3Enabled ? 'bg-success' : 'bg-secondary' ?> ms-2"><?= $sniperV3Enabled ? 'Active' : 'Disabled' ?></span>
            </div>
            <div class="card-body">
                <div class="alert <?= $currentProfile === 'sniper_lite' ? 'alert-info' : 'alert-warning' ?> small py-2 mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    <?php if ($currentProfile === 'sniper_lite'): ?>
                    Sniper Lite allows strong and selected medium V3 confirmations into live.
                    <?php else: ?>
                    Sniper profile allows only the cleanest V3 confirmations into live.
                    <?php endif; ?>
                    Other structurally valid V3 cases remain shadow/debug only.
                    Only <strong><?= implode(', ', $sniperV3AllowedTiers) ?></strong> confirmation tiers are allowed for live entry.
                </div>
                <div class="row" style="font-size: 0.85rem;">
                    <div class="col-md-6 mb-2">
                        <strong>Allowed Tiers:</strong>
                        <?php foreach ($sniperV3AllowedTiers as $tier): ?>
                        <span class="badge bg-success ms-1"><?= htmlspecialchars($tier) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <div class="col-md-6 mb-2">
                        <strong>Entry Action:</strong>
                        <span class="badge bg-info">wait_retrace</span>
                    </div>
                </div>
                <table class="table table-sm table-dark mt-2 mb-0" style="font-size: 0.82rem;">
                    <thead><tr><th>Filter</th><th>Threshold</th></tr></thead>
                    <tbody>
                    <?php
                        $sniperV3Labels = [
                            'sniper_v3_live_filter_enabled' => 'Filter Enabled',
                            'sniper_v3_min_confirmation_score' => 'Min Confirmation Score',
                            'sniper_v3_min_pattern_confidence' => 'Min Pattern Confidence',
                            'sniper_v3_min_trend_match_score' => 'Min Trend Match Score',
                            'sniper_v3_min_entry_quality_score' => 'Min Entry Quality Score',
                            'sniper_v3_min_corridor_fit_score' => 'Min Corridor Fit Score',
                            'sniper_v3_max_price_position' => 'Max Price Position',
                            'sniper_v3_min_reclaim_strength_score' => 'Min Reclaim Strength',
                            'sniper_v3_min_hold_quality_score' => 'Min Hold Quality',
                            'sniper_v3_min_post_reclaim_stability_score' => 'Min Post-Reclaim Stability',
                            'sniper_v3_min_zone_defense_score' => 'Min Zone Defense',
                        ];
                        foreach ($sniperV3Fields as $sf):
                            $sfVal = $currentBundle['values'][$sf] ?? $form_values[$sf] ?? '-';
                    ?>
                    <tr>
                        <td><code><?= htmlspecialchars($sniperV3Labels[$sf] ?? $sf) ?></code></td>
                        <td>
                            <?php if (is_bool($sfVal)): ?>
                                <span class="<?= $sfVal ? 'text-success' : 'text-danger' ?>"><?= $sfVal ? 'true' : 'false' ?></span>
                            <?php else: ?>
                                <span class="text-info"><?= htmlspecialchars((string)$sfVal) ?></span>
                            <?php endif; ?>
                            <small class="text-secondary">(profile)</small>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- V2 Live Quality Floor (visible for all profiles) -->
        <?php
            $v2FloorEnabled = (bool)($form_values['v2_live_quality_floor_enabled'] ?? $currentBundle['values']['v2_live_quality_floor_enabled'] ?? true);
        ?>
        <div class="card mb-4" style="border-color: #8b5cf6;">
            <div class="card-header d-flex align-items-center" style="background: rgba(139,92,246,0.1);">
                <i class="bi bi-shield-check me-2"></i>
                <h5 style="margin: 0;">V2 Live Quality Floor</h5>
                <span class="badge <?= $v2FloorEnabled ? 'bg-success' : 'bg-secondary' ?> ms-2"><?= $v2FloorEnabled ? 'Active' : 'Disabled' ?></span>
            </div>
            <div class="card-body">
                <div class="alert alert-info small py-2 mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    V2 signals with confirmation_score, pattern_confidence, or trend_match below these floors are filtered before live approval. This prevents weak/medium-quality V2 leakage into live.
                </div>
                <table class="table table-sm table-dark mt-2 mb-0" style="font-size: 0.82rem;">
                    <thead><tr><th>Filter</th><th>Threshold</th></tr></thead>
                    <tbody>
                    <?php
                        $v2FloorLabels = [
                            'v2_live_quality_floor_enabled' => 'Floor Enabled',
                            'v2_live_min_confirmation_score' => 'Min Confirmation Score',
                            'v2_live_min_pattern_confidence' => 'Min Pattern Confidence',
                            'v2_live_min_trend_match_score' => 'Min Trend Match Score',
                        ];
                        foreach ($v2FloorLabels as $fk => $label):
                            $fkVal = $currentBundle['values'][$fk] ?? $form_values[$fk] ?? '-';
                    ?>
                    <tr>
                        <td><code><?= htmlspecialchars($label) ?></code></td>
                        <td>
                            <?php if (is_bool($fkVal)): ?>
                                <span class="<?= $fkVal ? 'text-success' : 'text-danger' ?>"><?= $fkVal ? 'true' : 'false' ?></span>
                            <?php else: ?>
                                <span class="text-info"><?= htmlspecialchars((string)$fkVal) ?></span>
                            <?php endif; ?>
                            <small class="text-secondary">(profile)</small>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pattern Selection Section -->
        <div class="row">
            <div class="col-md-6 mb-4">
                <div class="card h-100" id="pattern-selection-card" style="<?= $isPatternProfileControlled ? 'opacity: 0.6;' : '' ?>">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-search me-2"></i>
                        <h5 style="margin: 0;">Pattern Selection</h5>
                        <?php if ($isPatternProfileControlled): ?>
                        <span class="badge bg-info ms-2">profile-controlled</span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if ($isPatternProfileControlled): ?>
                        <div class="alert alert-info small py-2 mb-3">
                            <i class="bi bi-info-circle me-1"></i>Pattern selection is controlled by the active execution profile. Switch to <strong>Manual pattern override</strong> above to edit manually.
                        </div>
                        <?php endif; ?>
                        <p class="text-secondary mb-3" style="font-size: 0.85rem;">
                            Выбранные алгоритмы используются анализатором для поиска входов.<br>
                            Режим «any» — достаточно совпадения любого включённого алгоритма.<br>
                            Режим «one» — используется только один выбранный алгоритм.<br>
                            Режим «all» — сигнал допускается только при подтверждении всеми включёнными алгоритмами.
                        </p>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Enabled Algorithms</label>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="pattern_double_bottom" name="patterns_enabled[]" value="double_bottom" <?= $patternChecked('double_bottom') ?> <?= $isPatternProfileControlled ? 'disabled' : '' ?>>
                                <label class="form-check-label" for="pattern_double_bottom">Double Bottom</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="pattern_double_top" name="patterns_enabled[]" value="double_top" <?= $patternChecked('double_top') ?> <?= $isPatternProfileControlled ? 'disabled' : '' ?>>
                                <label class="form-check-label" for="pattern_double_top">Double Top</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="pattern_pullback" name="patterns_enabled[]" value="pullback_trend_continue" <?= $patternChecked('pullback_trend_continue') ?> <?= $isPatternProfileControlled ? 'disabled' : '' ?>>
                                <label class="form-check-label" for="pattern_pullback">Pullback Trend Continue</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="pattern_double_bottom_v2" name="patterns_enabled[]" value="double_bottom_confirm_v2" <?= $patternChecked('double_bottom_confirm_v2') ?> <?= $isPatternProfileControlled ? 'disabled' : '' ?>>
                                <label class="form-check-label" for="pattern_double_bottom_v2">Double Bottom V2 <small class="text-info">(confirmed)</small></label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="pattern_double_top_v2" name="patterns_enabled[]" value="double_top_confirm_v2" <?= $patternChecked('double_top_confirm_v2') ?> <?= $isPatternProfileControlled ? 'disabled' : '' ?>>
                                <label class="form-check-label" for="pattern_double_top_v2">Double Top V2 <small class="text-info">(confirmed)</small></label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="pattern_double_bottom_ctx_v2" name="patterns_enabled[]" value="double_bottom_contextual_v2" <?= $patternChecked('double_bottom_contextual_v2') ?> <?= $isPatternProfileControlled ? 'disabled' : '' ?>>
                                <label class="form-check-label" for="pattern_double_bottom_ctx_v2">Double Bottom Contextual V2 <small class="text-warning">(context-aware)</small></label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="pattern_double_bottom_ctx_v3" name="patterns_enabled[]" value="double_bottom_contextual_v3" <?= $patternChecked('double_bottom_contextual_v3') ?> <?= $isPatternProfileControlled ? 'disabled' : '' ?>>
                                <label class="form-check-label" for="pattern_double_bottom_ctx_v3">Double Bottom Contextual V3 <small class="text-danger">(regime-aware)</small></label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="pattern_mode" class="form-label fw-bold">Pattern Mode</label>
                            <select class="form-select" id="pattern_mode" name="pattern_mode" <?= $isPatternProfileControlled ? 'disabled' : '' ?>>
                                <?php foreach (['one' => 'One', 'any' => 'Any', 'all' => 'All'] as $pm => $pmLabel): ?>
                                <option value="<?= $pm ?>" <?= $pattern_mode === $pm ? 'selected' : '' ?>><?= $pmLabel ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-secondary">Режим проверки алгоритмов</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Leverage Control & Stop Control -->
        <div class="row">
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-speedometer me-2"></i>
                        <h5 style="margin: 0;">Leverage Control</h5>
                    </div>
                    <div class="card-body">
                        <div class="cfg-legend mb-3">
                            <h6><i class="bi bi-info-circle me-1"></i> Плечо и риск</h6>
                            <p class="mb-0">Режим «auto» — плечо рассчитывается мозгом. «Manual» — фиксированное плечо.<br>
                            <span class="text-warning">⚠ Чем выше плечо, тем ближе ликвидация и тем меньше % движения цены нужен для стоп-лосса по ROI.</span></p>
                        </div>
                        <div class="mb-3">
                            <label for="leverage_mode" class="form-label fw-bold">Leverage Mode</label>
                            <select class="form-select" id="leverage_mode" name="leverage_mode">
                                <?php foreach (['auto' => 'Auto', 'manual' => 'Manual'] as $lm => $lmLabel): ?>
                                <option value="<?= $lm ?>" <?= ($form_values['leverage_mode'] ?? 'auto') === $lm ? 'selected' : '' ?>><?= $lmLabel ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="cfg-hint">Режим выбора плеча</div>
                        </div>
                        <div class="mb-3">
                            <label for="manual_leverage" class="form-label">Manual Leverage
                                <i class="bi bi-question-circle cfg-info" title="Фиксированное плечо. При 5x, ROI 10% стоп ≈ 2% движения цены."></i>
                            </label>
                            <input type="number" step="1" min="1" class="form-control" id="manual_leverage" name="manual_leverage" value="<?= $v('manual_leverage', '3') ?>">
                            <div class="cfg-hint">Фиксированное плечо (только в режиме Manual). Пример: <code>5</code> = 5x.</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-shield-minus me-2"></i>
                        <h5 style="margin: 0;">Stop Control</h5>
                    </div>
                    <div class="card-body">
                        <!-- Stop Modes Explanation -->
                        <div class="cfg-legend mb-3">
                            <h6><i class="bi bi-info-circle me-1"></i> Режимы стоп-лосса</h6>
                            <ul class="mb-0 ps-3">
                                <li><strong class="text-info">Auto</strong> — стоп рассчитывается от ликвидационной цены. Brain управляет автоматически.</li>
                                <li><strong class="text-warning">Manual</strong> — стоп по ROI позиции (зависит от плеча). Пример: <code>0.03</code> = -3% ROI; при 5x ≈ -0.6% движения цены.</li>
                                <li><strong class="text-success">Entry ROI</strong> — стоп как % от цены входа (не зависит от плеча). Пример: <code>0.10</code> = 10% от цены входа.</li>
                            </ul>
                        </div>
                        <div class="mb-3">
                            <label for="stop_control_mode" class="form-label fw-bold">Stop Control Mode</label>
                            <select class="form-select" id="stop_control_mode" name="stop_control_mode">
                                <?php foreach (['auto' => 'Auto (Liquidation-based)', 'manual' => 'Manual (Fixed ROI)', 'entry_roi' => 'Entry ROI (% from entry price)'] as $sc => $scLabel): ?>
                                <option value="<?= $sc ?>" <?= ($form_values['stop_control_mode'] ?? 'auto') === $sc ? 'selected' : '' ?>><?= $scLabel ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="cfg-hint">Режим управления стоп-лоссом</div>
                        </div>
                        <?php $activeStopMode = $form_values['stop_control_mode'] ?? 'auto'; ?>
                        <div class="mb-3<?= $activeStopMode !== 'manual' ? ' cfg-muted-field' : '' ?>" id="manual_stop_group">
                            <label for="manual_stop_loss_roi" class="form-label">Manual Stop Loss ROI
                                <i class="bi bi-question-circle cfg-info" title="Стоп по ROI позиции. Зависит от плеча: ROI_stop / leverage ≈ % движения цены."></i>
                            </label>
                            <input type="number" step="0.001" min="0.001" class="form-control" id="manual_stop_loss_roi" name="manual_stop_loss_roi" value="<?= $v('manual_stop_loss_roi', '0.03') ?>">
                            <div class="cfg-hint">Фиксированный ROI стоп (<code>0.03</code> = 3% ROI). При 5x ≈ 0.6% цены.</div>
                            <?php if ($activeStopMode !== 'manual'): ?>
                            <div class="cfg-mode-note">⚠ Используется только в режиме Manual</div>
                            <?php endif; ?>
                        </div>
                        <div class="mb-3<?= $activeStopMode !== 'entry_roi' ? ' cfg-muted-field' : '' ?>" id="entry_roi_stop_group">
                            <label for="stop_loss_from_entry_roi" class="form-label">Stop Loss from Entry ROI
                                <i class="bi bi-question-circle cfg-info" title="% от цены входа. Не зависит от плеча. 0.10 = стоп на 10% от цены входа."></i>
                            </label>
                            <input type="number" step="0.01" min="0.01" max="1.0" class="form-control" id="stop_loss_from_entry_roi" name="stop_loss_from_entry_roi" value="<?= $v('stop_loss_from_entry_roi', '0.10') ?>">
                            <div class="cfg-hint">% от цены входа (<code>0.10</code> = 10% от цены). Не зависит от плеча.</div>
                            <?php if ($activeStopMode !== 'entry_roi'): ?>
                            <div class="cfg-mode-note">⚠ Используется только в режиме Entry ROI</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Symbol Intelligence V2 -->
        <div class="row">
            <div class="col-md-12 mb-4">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-stars me-2"></i>
                        <h5 style="margin: 0;">Symbol Intelligence</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-secondary mb-3" style="font-size: 0.85rem;">
                            Интеллектуальная фильтрация монет по истории торгов.<br>
                            Включите и выберите режим фильтрации для фокусировки на лучших символах.<br>
                            При выключенном режиме — торгуются все монеты без фильтрации.
                        </p>
                        <div class="row">
                            <!-- Enable + Filter Mode -->
                            <div class="col-md-4">
                                <div class="mb-3 form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="symbol_intelligence_enabled" name="symbol_intelligence_enabled" value="1" <?= $symbol_intelligence_enabled ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="symbol_intelligence_enabled">Symbol Intelligence Enabled</label>
                                    <br><small class="text-secondary">Включить фильтрацию по спискам</small>
                                </div>
                                <div class="mb-3">
                                    <label for="symbol_filter_mode" class="form-label fw-bold">Filter Mode</label>
                                    <select class="form-select" id="symbol_filter_mode" name="symbol_filter_mode">
                                        <?php foreach ([
                                            'all' => 'All — все символы',
                                            'whitelist_only' => 'Whitelist Only — только хорошие',
                                            'exclude_blacklist' => 'Exclude Blacklist — без плохих',
                                            'watchlist_only' => 'Watchlist Only — только наблюдение',
                                            'soft_whitelist_only' => 'Soft Whitelist Only — только перспективные',
                                            'whitelist_plus_soft' => 'Whitelist + Soft — хорошие + перспективные',
                                        ] as $fm => $fmLabel): ?>
                                        <option value="<?= $fm ?>" <?= $symbol_filter_mode === $fm ? 'selected' : '' ?>><?= htmlspecialchars($fmLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-secondary">Режим фильтрации кандидатов</small>
                                </div>
                                <div class="mb-3">
                                    <label for="symbol_recent_window" class="form-label fw-bold">Окно последних сделок</label>
                                    <input type="number" step="1" min="1" class="form-control" id="symbol_recent_window" name="symbol_recent_window" value="<?= $v('symbol_recent_window', '5') ?>">
                                    <small class="text-secondary">Кол-во последних сделок для анализа (recent window)</small>
                                </div>
                            </div>
                            <!-- Whitelist thresholds -->
                            <div class="col-md-4">
                                <h6 class="text-success mb-2">Whitelist пороги</h6>
                                <div class="mb-2">
                                    <label for="whitelist_min_trades" class="form-label">Мин. сделок для whitelist</label>
                                    <input type="number" step="1" min="1" class="form-control form-control-sm" id="whitelist_min_trades" name="whitelist_min_trades" value="<?= $v('whitelist_min_trades', '5') ?>">
                                </div>
                                <div class="mb-2">
                                    <label for="whitelist_min_winrate" class="form-label">Мин. винрейт для whitelist</label>
                                    <input type="number" step="0.01" min="0" max="1" class="form-control form-control-sm" id="whitelist_min_winrate" name="whitelist_min_winrate" value="<?= $v('whitelist_min_winrate', '0.50') ?>">
                                </div>
                                <div class="mb-2">
                                    <label for="whitelist_min_avg_roi" class="form-label">Мин. ср. ROI для whitelist</label>
                                    <input type="number" step="0.001" class="form-control form-control-sm" id="whitelist_min_avg_roi" name="whitelist_min_avg_roi" value="<?= $v('whitelist_min_avg_roi', '0') ?>">
                                </div>
                                <hr>
                                <h6 class="text-info mb-2">Soft Whitelist пороги</h6>
                                <div class="mb-2 form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="soft_whitelist_enabled" name="soft_whitelist_enabled" value="1" <?= $checked('soft_whitelist_enabled') ?>>
                                    <label class="form-check-label" for="soft_whitelist_enabled">Включить soft whitelist</label>
                                </div>
                                <div class="mb-2">
                                    <label for="soft_whitelist_min_trades" class="form-label">Мин. сделок для soft whitelist</label>
                                    <input type="number" step="1" min="1" class="form-control form-control-sm" id="soft_whitelist_min_trades" name="soft_whitelist_min_trades" value="<?= $v('soft_whitelist_min_trades', '1') ?>">
                                </div>
                                <div class="mb-2">
                                    <label for="soft_whitelist_min_winrate" class="form-label">Мин. винрейт для soft whitelist</label>
                                    <input type="number" step="0.01" min="0" max="1" class="form-control form-control-sm" id="soft_whitelist_min_winrate" name="soft_whitelist_min_winrate" value="<?= $v('soft_whitelist_min_winrate', '0.50') ?>">
                                </div>
                                <div class="mb-2">
                                    <label for="soft_whitelist_min_avg_roi" class="form-label">Мин. ср. ROI для soft whitelist</label>
                                    <input type="number" step="0.001" class="form-control form-control-sm" id="soft_whitelist_min_avg_roi" name="soft_whitelist_min_avg_roi" value="<?= $v('soft_whitelist_min_avg_roi', '0.005') ?>">
                                </div>
                            </div>
                            <!-- Blacklist thresholds -->
                            <div class="col-md-4">
                                <h6 class="text-danger mb-2">Blacklist пороги</h6>
                                <div class="mb-2">
                                    <label for="blacklist_min_trades" class="form-label">Мин. сделок для blacklist</label>
                                    <input type="number" step="1" min="1" class="form-control form-control-sm" id="blacklist_min_trades" name="blacklist_min_trades" value="<?= $v('blacklist_min_trades', '3') ?>">
                                </div>
                                <div class="mb-2">
                                    <label for="blacklist_max_winrate" class="form-label">Макс. винрейт для blacklist</label>
                                    <input type="number" step="0.01" min="0" max="1" class="form-control form-control-sm" id="blacklist_max_winrate" name="blacklist_max_winrate" value="<?= $v('blacklist_max_winrate', '0.30') ?>">
                                </div>
                                <div class="mb-2">
                                    <label for="blacklist_max_early_failure_ratio" class="form-label">Макс. доля early failure</label>
                                    <input type="number" step="0.01" min="0" max="1" class="form-control form-control-sm" id="blacklist_max_early_failure_ratio" name="blacklist_max_early_failure_ratio" value="<?= $v('blacklist_max_early_failure_ratio', '0.60') ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Manual Symbol Universe -->
        <div class="row">
            <div class="col-md-12 mb-4">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-list-ul me-2"></i>
                        <h5 style="margin: 0;">Ручной список монет</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-secondary mb-3" style="font-size: 0.85rem;">
                            Используйте этот список, если хотите ограничить или закрепить набор монет для симуляции,<br>
                            даже при сбросе статистики whitelist / blacklist / soft whitelist.
                        </p>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3 form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="manual_symbol_universe_enabled" name="manual_symbol_universe_enabled" value="1" <?= $manual_symbol_universe_enabled ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="manual_symbol_universe_enabled">Ручной список включён</label>
                                    <br><small class="text-secondary">Включить ограничение по ручному списку монет</small>
                                </div>
                                <div class="mb-3">
                                    <label for="manual_symbol_mode" class="form-label fw-bold">Режим ручного списка</label>
                                    <select class="form-select" id="manual_symbol_mode" name="manual_symbol_mode">
                                        <?php foreach ([
                                            'manual_only' => 'Manual Only — только из списка',
                                            'manual_plus_whitelist' => 'Manual + Whitelist — список + whitelist',
                                            'manual_plus_soft' => 'Manual + Soft Whitelist — список + soft whitelist',
                                            'manual_exclude_blacklist' => 'Manual − Blacklist — список без blacklist',
                                        ] as $mm => $mmLabel): ?>
                                        <option value="<?= $mm ?>" <?= $manual_symbol_mode === $mm ? 'selected' : '' ?>><?= htmlspecialchars($mmLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-secondary">Как комбинировать ручной список с автоматическими списками</small>
                                </div>
                            </div>
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label for="manual_symbol_list" class="form-label fw-bold">Список монет</label>
                                    <textarea class="form-control" id="manual_symbol_list" name="manual_symbol_list" rows="5" placeholder="BTCUSDT, ETHUSDT, SOLUSDT"><?= $v('manual_symbol_list', '') ?></textarea>
                                    <small class="text-secondary">По одной монете на строку или через запятую. Пример: BTCUSDT, ETHUSDT</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Manual Live Blacklist — separate form, not part of user config -->
        <div class="row">
            <div class="col-md-12 mb-4">
                <div class="card h-100" style="border-color: #dc3545;">
                    <div class="card-header d-flex align-items-center" style="background: rgba(220,53,69,0.08);">
                        <i class="bi bi-shield-x me-2 text-danger"></i>
                        <h5 style="margin: 0;">Manual Live Blacklist</h5>
                        <span class="badge bg-danger ms-2"><?= $manual_blacklist['count'] ?> symbol(s)</span>
                    </div>
                    <div class="card-body">
                        <p class="text-secondary mb-3" style="font-size: 0.85rem;">
                            <i class="bi bi-info-circle me-1"></i>
                            Applies to <strong>LIVE only</strong>. Simulator and analytics are unaffected.<br>
                            Blacklisted symbols will not receive live approval or live intents.
                            Structural detection continues for research/debug.
                        </p>
                        <?php if (!$manual_blacklist['valid']): ?>
                        <div class="alert alert-warning py-1 px-2 mb-2" style="font-size: 0.85rem;">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            Blacklist warning: <?= htmlspecialchars($manual_blacklist['warning']) ?>
                        </div>
                        <?php endif; ?>
                        <form method="POST" action="<?= htmlspecialchars($smartBrainUrl) ?>/blacklist/save">
                            <div class="row">
                                <div class="col-md-8">
                                    <label for="manual_blacklist_symbols" class="form-label fw-bold">Blacklisted Symbols</label>
                                    <textarea class="form-control" id="manual_blacklist_symbols" name="manual_blacklist_symbols" rows="4" placeholder="BADCOINUSDT&#10;SPIKEUSDT&#10;TRASHUSDT"><?= htmlspecialchars(implode("\n", $manual_blacklist['symbols'])) ?></textarea>
                                    <small class="text-secondary">One symbol per line or comma-separated. Will be normalized to uppercase.</small>
                                </div>
                                <div class="col-md-4 d-flex flex-column justify-content-end">
                                    <button type="submit" class="btn btn-danger mb-2">
                                        <i class="bi bi-shield-x me-1"></i> Save Blacklist
                                    </button>
                                    <small class="text-secondary">Changes take effect on next Brain cycle.</small>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-shield-check me-2"></i>
                        <h5 style="margin: 0;">Trading Limits</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="max_budget_per_coin" class="form-label">Max Budget per Coin (USDT)</label>
                            <input type="number" step="0.01" min="0.01" class="form-control" id="max_budget_per_coin" name="max_budget_per_coin" value="<?= $v('max_budget_per_coin', '20') ?>">
                            <small class="text-secondary">Maximum USDT budget per coin position</small>
                        </div>
                        <div class="mb-3">
                            <label for="max_active_tasks" class="form-label">Max Active Tasks</label>
                            <input type="number" step="1" min="1" class="form-control" id="max_active_tasks" name="max_active_tasks" value="<?= $v('max_active_tasks', '10') ?>">
                            <small class="text-secondary">Maximum concurrent active signals</small>
                        </div>
                        <div class="mb-3">
                            <label for="max_leverage" class="form-label">Max Leverage</label>
                            <input type="number" step="1" min="1" class="form-control" id="max_leverage" name="max_leverage" value="<?= $v('max_leverage', '15') ?>">
                            <small class="text-secondary">Maximum leverage for any position</small>
                        </div>
                        <div class="mb-3">
                            <label for="brain_mode" class="form-label">Brain Mode</label>
                            <select class="form-select" id="brain_mode" name="brain_mode">
                                <?php foreach (['safe', 'balanced', 'aggressive'] as $mode): ?>
                                <option value="<?= $mode ?>" <?= ($form_values['brain_mode'] ?? 'balanced') === $mode ? 'selected' : '' ?>><?= ucfirst($mode) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-secondary">Risk tolerance profile</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bootstrap Settings -->
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-rocket-takeoff me-2"></i>
                        <h5 style="margin: 0;">Bootstrap Mode</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3 form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="bootstrap_enabled" name="bootstrap_enabled" value="1" <?= $checked('bootstrap_enabled') ?>>
                            <label class="form-check-label" for="bootstrap_enabled">Bootstrap Enabled</label>
                            <br><small class="text-secondary">Allow signals for cold-start passports</small>
                        </div>
                        <div class="mb-3">
                            <label for="bootstrap_max_signals" class="form-label">Bootstrap Max Signals</label>
                            <input type="number" step="1" min="0" class="form-control" id="bootstrap_max_signals" name="bootstrap_max_signals" value="<?= $v('bootstrap_max_signals', '5') ?>">
                            <small class="text-secondary">Max bootstrap signals per cycle</small>
                        </div>
                        <div class="mb-3">
                            <label for="bootstrap_budget_factor" class="form-label">Bootstrap Budget Factor</label>
                            <input type="number" step="0.01" min="0.01" max="1" class="form-control" id="bootstrap_budget_factor" name="bootstrap_budget_factor" value="<?= $v('bootstrap_budget_factor', '0.5') ?>">
                            <small class="text-secondary">Fraction of max_budget for bootstrap (0..1)</small>
                        </div>
                        <div class="mb-3">
                            <label for="bootstrap_max_leverage" class="form-label">Bootstrap Max Leverage</label>
                            <input type="number" step="1" min="1" class="form-control" id="bootstrap_max_leverage" name="bootstrap_max_leverage" value="<?= $v('bootstrap_max_leverage', '3') ?>">
                            <small class="text-secondary">Max leverage for bootstrap signals</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Warmup / Reliability -->
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-thermometer-half me-2"></i>
                        <h5 style="margin: 0;">Warmup &amp; Reliability</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="min_reliability_after_warmup" class="form-label">Min Reliability (after warmup)</label>
                            <input type="number" step="0.01" min="0" max="1" class="form-control" id="min_reliability_after_warmup" name="min_reliability_after_warmup" value="<?= $v('min_reliability_after_warmup', '0.15') ?>">
                            <small class="text-secondary">Minimum reliability_score for normal signals (0..1)</small>
                        </div>
                        <div class="mb-3">
                            <label for="warmup_min_trades" class="form-label">Warmup Min Trades</label>
                            <input type="number" step="1" min="0" class="form-control" id="warmup_min_trades" name="warmup_min_trades" value="<?= $v('warmup_min_trades', '10') ?>">
                            <small class="text-secondary">Min trades in passport before normal mode activates</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Exit Policy Section -->
        <div class="row">
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-door-open me-2"></i>
                        <h5 style="margin: 0;">Exit Policy</h5>
                    </div>
                    <div class="card-body">
                        <!-- Exit Modes Explanation -->
                        <div class="cfg-legend mb-3">
                            <h6><i class="bi bi-info-circle me-1"></i> Режимы выхода</h6>
                            <ul class="mb-0 ps-3">
                                <li><strong class="text-info">Fixed TP</strong> — фиксированный тейк-профит при достижении ROI цели.</li>
                                <li><strong class="text-success">Trailing TP</strong> — трейлинг-стоп следит за максимальной прибылью и фиксирует при откате.</li>
                                <li><strong class="text-warning">Hybrid</strong> — часть позиции закрывается по Fixed TP, остальное — по трейлингу.</li>
                            </ul>
                        </div>
                        <div class="mb-3">
                            <label for="exit_mode" class="form-label fw-bold">Exit Mode
                                <i class="bi bi-question-circle cfg-info" title="fixed_tp = фиксированный тейк. trailing_tp = динамический трейлинг. hybrid = часть + трейлинг."></i>
                            </label>
                            <select class="form-select" id="exit_mode" name="exit_mode">
                                <?php foreach (['fixed_tp' => 'Fixed TP', 'trailing_tp' => 'Trailing TP', 'hybrid' => 'Hybrid'] as $em => $emLabel): ?>
                                <option value="<?= $em ?>" <?= ($form_values['exit_mode'] ?? 'fixed_tp') === $em ? 'selected' : '' ?>><?= $emLabel ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="cfg-hint">Как управляется тейк-профит</div>
                        </div>
                        <div class="mb-3">
                            <label for="stop_floor_type" class="form-label">Stop Floor Type</label>
                            <select class="form-select" id="stop_floor_type" name="stop_floor_type">
                                <?php foreach (['roi_percent' => 'ROI Percent', 'corridor_percent' => 'Corridor Percent'] as $sf => $sfLabel): ?>
                                <option value="<?= $sf ?>" <?= ($form_values['stop_floor_type'] ?? 'roi_percent') === $sf ? 'selected' : '' ?>><?= $sfLabel ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="cfg-hint">Метод расчёта минимальной стоп-защиты</div>
                        </div>
                        <div class="mb-3">
                            <label for="stop_floor_value" class="form-label">Stop Floor Value</label>
                            <input type="number" step="0.001" min="0.001" class="form-control" id="stop_floor_value" name="stop_floor_value" value="<?= $v('stop_floor_value', '0.03') ?>">
                            <div class="cfg-hint">Минимальная стоп-защита (<code>0.03</code> = 3%)</div>
                        </div>
                        <div class="mb-3 form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="brain_may_tighten_stop" name="brain_may_tighten_stop" value="1" <?= $checked('brain_may_tighten_stop') ?>>
                            <label class="form-check-label" for="brain_may_tighten_stop">Brain May Tighten Stop</label>
                            <br><div class="cfg-hint">Brain может ужесточить стоп (но никогда не ослабит ниже floor)</div>
                        </div>
                        <?php $activeExitMode = $form_values['exit_mode'] ?? 'fixed_tp'; ?>
                        <div class="mb-3">
                            <label for="fixed_take_profit_roi" class="form-label">Fixed Take Profit ROI
                                <i class="bi bi-question-circle cfg-info" title="Целевой ROI для фиксированного тейк-профита. 0.03 = +3% ROI."></i>
                            </label>
                            <input type="number" step="0.001" min="0" class="form-control" id="fixed_take_profit_roi" name="fixed_take_profit_roi" value="<?= $v('fixed_take_profit_roi', '0.05') ?>">
                            <div class="cfg-hint">ROI для фиксированного TP (<code>0.03</code> = +3% ROI). Используется в Fixed TP и Hybrid.</div>
                        </div>
                        <div class="mb-3<?= $activeExitMode !== 'hybrid' ? ' cfg-muted-field' : '' ?>">
                            <label for="hybrid_tp_share" class="form-label">Hybrid TP Share
                                <i class="bi bi-question-circle cfg-info" title="Доля позиции для Fixed TP в Hybrid режиме. 0.40 = 40% позиции."></i>
                            </label>
                            <input type="number" step="0.01" min="0" max="1" class="form-control" id="hybrid_tp_share" name="hybrid_tp_share" value="<?= $v('hybrid_tp_share', '0.5') ?>">
                            <div class="cfg-hint">Доля позиции для Fixed TP в Hybrid (<code>0.40</code> = 40%). Остальное — по трейлингу.</div>
                            <?php if ($activeExitMode !== 'hybrid'): ?>
                            <div class="cfg-mode-note">⚠ Используется только в режиме Hybrid</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Trailing Settings -->
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-graph-up-arrow me-2"></i>
                        <h5 style="margin: 0;">Trailing Stop</h5>
                    </div>
                    <div class="card-body">
                        <!-- Trailing Parameter Legend -->
                        <div class="cfg-legend mb-3">
                            <h6><i class="bi bi-info-circle me-1"></i> Как работает трейлинг</h6>
                            <p class="mb-0">Трейлинг следит за максимумом прибыли и подтягивает стоп при росте. Если цена откатится от максимума на <code>drawdown_factor</code> × максимальный ROI — позиция закрывается.</p>
                        </div>
                        <div class="mb-3 form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="trailing_enabled" name="trailing_enabled" value="1" <?= $checked('trailing_enabled') ?>>
                            <label class="form-check-label" for="trailing_enabled">Trailing Enabled</label>
                            <br><div class="cfg-hint">Включить трейлинг-стоп для фиксации прибыли</div>
                        </div>
                        <div class="mb-3">
                            <label for="trailing_mode" class="form-label">Trailing Mode
                                <i class="bi bi-question-circle cfg-info" title="roi_giveback = classic drawdown-factor trailing. price_distance = fixed % distance from current price. price_distance_floor = activation floor + locked ROI + price distance + step corridor."></i>
                            </label>
                            <select class="form-select" id="trailing_mode" name="trailing_mode">
                                <?php $currentTrailingMode = $v('trailing_mode', 'roi_giveback'); ?>
                                <option value="roi_giveback" <?= $currentTrailingMode === 'roi_giveback' ? 'selected' : '' ?>>ROI Giveback (classic)</option>
                                <option value="price_distance" <?= $currentTrailingMode === 'price_distance' ? 'selected' : '' ?>>Price Distance (fixed % from price)</option>
                                <option value="price_distance_floor" <?= $currentTrailingMode === 'price_distance_floor' ? 'selected' : '' ?>>Price Distance + Floor (activation floor + locked ROI + distance)</option>
                            </select>
                            <div class="cfg-hint">
                                <code>roi_giveback</code> = стоп следит за drawdown_factor × макс. ROI.<br>
                                <code>price_distance</code> = стоп держится на фиксированном расстоянии от текущей цены (например 2–3%)<br>
                                <code>price_distance_floor</code> = активация по порогу ROI → фиксация минимального профита → distance-trailing от лучшей цены + шаговый коридор
                            </div>
                        </div>
                        <!-- Price Distance field — active for price_distance and price_distance_floor modes -->
                        <div class="mb-3<?= $currentTrailingMode === 'roi_giveback' ? ' cfg-muted-field' : '' ?>" id="price_distance_fields_group">
                            <label for="trailing_price_distance_pct" class="form-label">Trailing Price Distance %
                                <i class="bi bi-question-circle cfg-info" title="Used in price_distance and price_distance_floor modes. 0.02 = 2% from current price. Min 0.005, max 0.20."></i>
                            </label>
                            <input type="number" step="0.001" min="0.005" max="0.20" class="form-control" id="trailing_price_distance_pct" name="trailing_price_distance_pct" value="<?= $v('trailing_price_distance_pct', '0.02') ?>">
                            <div class="cfg-hint">Расстояние от текущей цены (<code>0.02</code> = 2% от текущей цены). Используется в режимах price_distance и price_distance_floor</div>
                            <div class="cfg-mode-note" style="<?= $currentTrailingMode === 'roi_giveback' ? '' : 'display:none' ?>">⚠ Активно только в режимах Price Distance / Floor</div>
                        </div>
                        <!-- ROI Giveback specific fields — active for roi_giveback and price_distance modes -->
                        <div id="roi_giveback_fields_group" class="<?= $currentTrailingMode === 'price_distance_floor' ? 'cfg-muted-field' : '' ?>">
                        <div class="card mb-2 <?= $currentTrailingMode === 'price_distance_floor' ? 'border-secondary' : 'border-success' ?>">
                            <div class="card-header d-flex justify-content-between align-items-center <?= $currentTrailingMode === 'price_distance_floor' ? 'bg-secondary bg-opacity-10' : 'bg-success bg-opacity-10' ?>" id="roi_giveback_header"
                                 style="cursor:pointer" data-bs-toggle="collapse" data-bs-target="#roi_giveback_collapse">
                                <span>
                                    <strong>ROI Giveback Settings</strong>
                                    <?php if ($currentTrailingMode === 'price_distance_floor'): ?>
                                    <span class="badge bg-secondary ms-2">inactive — other mode</span>
                                    <?php else: ?>
                                    <span class="badge bg-success ms-2">active</span>
                                    <?php endif; ?>
                                </span>
                                <i class="bi bi-chevron-down"></i>
                            </div>
                            <div id="roi_giveback_collapse" class="collapse <?= $currentTrailingMode !== 'price_distance_floor' ? 'show' : '' ?>">
                            <div class="card-body">
                        <div class="mb-3">
                            <label for="trailing_activation_roi" class="form-label">Trailing Activation ROI
                                <i class="bi bi-question-circle cfg-info" title="Трейлинг начинает работать после достижения этого ROI. 0.05 = +5% ROI."></i>
                            </label>
                            <input type="number" step="0.001" min="0" class="form-control" id="trailing_activation_roi" name="trailing_activation_roi" value="<?= $v('trailing_activation_roi', '0.03') ?>">
                            <div class="cfg-hint">Порог ROI для активации трейлинга (<code>0.05</code> = после +5% ROI). Для roi_giveback и price_distance режимов</div>
                        </div>
                        <div class="mb-3">
                            <label for="trailing_min_lock_roi" class="form-label">Trailing Min Lock ROI
                                <i class="bi bi-question-circle cfg-info" title="Минимальная прибыль, которую трейлинг зафиксирует. 0.012 = +1.2% ROI."></i>
                            </label>
                            <input type="number" step="0.001" min="0" class="form-control" id="trailing_min_lock_roi" name="trailing_min_lock_roi" value="<?= $v('trailing_min_lock_roi', '0.008') ?>">
                            <div class="cfg-hint">Минимальная фиксируемая прибыль (<code>0.012</code> = зафиксировать не менее +1.2% ROI)</div>
                        </div>
                        <div class="mb-3">
                            <label for="trailing_min_step" class="form-label">Trailing Min Step
                                <i class="bi bi-question-circle cfg-info" title="Минимальный шаг подтягивания стопа. 0.01 = стоп сдвигается при изменении на 1%."></i>
                            </label>
                            <input type="number" step="0.001" min="0.001" class="form-control" id="trailing_min_step" name="trailing_min_step" value="<?= $v('trailing_min_step', '0.005') ?>">
                            <div class="cfg-hint">Минимальный шаг подтягивания стопа (<code>0.01</code> = шаг 1%)</div>
                        </div>
                        <div class="mb-3 form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="brain_may_delay_trailing" name="brain_may_delay_trailing" value="1" <?= $checked('brain_may_delay_trailing') ?>>
                            <label class="form-check-label" for="brain_may_delay_trailing">Brain May Delay Trailing</label>
                            <br><div class="cfg-hint">Brain может задержать активацию трейлинга</div>
                        </div>
                            </div>
                            </div>
                        </div>
                        <div class="cfg-mode-note" style="<?= $currentTrailingMode === 'price_distance_floor' ? '' : 'display:none' ?>">⚠ Поля выше активны только в режимах ROI Giveback / Price Distance</div>
                        </div><!-- /roi_giveback_fields_group -->

                        <!-- Price Distance Floor specific fields — active only for price_distance_floor mode -->
                        <div class="mb-3<?= $currentTrailingMode !== 'price_distance_floor' ? ' cfg-muted-field' : '' ?>" id="floor_trailing_fields_group">
                            <div class="card <?= $currentTrailingMode === 'price_distance_floor' ? 'border-info' : 'border-secondary' ?>">
                                <div class="card-header d-flex justify-content-between align-items-center <?= $currentTrailingMode === 'price_distance_floor' ? 'bg-info bg-opacity-10' : 'bg-secondary bg-opacity-10' ?>" id="floor_trailing_header"
                                     style="cursor:pointer" data-bs-toggle="collapse" data-bs-target="#floor_trailing_collapse">
                                    <span>
                                        <strong>Price Distance Floor Settings</strong>
                                        <?php if ($currentTrailingMode === 'price_distance_floor'): ?>
                                        <span class="badge bg-info ms-2">active</span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary ms-2">inactive — other mode</span>
                                        <?php endif; ?>
                                    </span>
                                    <i class="bi bi-chevron-down"></i>
                                </div>
                                <div id="floor_trailing_collapse" class="collapse <?= $currentTrailingMode === 'price_distance_floor' ? 'show' : '' ?>">
                                <div class="card-body">
                                    <div class="mb-2">
                                        <label for="trailing_activation_floor_roi" class="form-label">Floor Activation ROI
                                            <i class="bi bi-question-circle cfg-info" title="ROI threshold to activate floor trailing. 0.04 = +4% ROI."></i>
                                        </label>
                                        <input type="number" step="0.001" min="0.005" class="form-control" id="trailing_activation_floor_roi" name="trailing_activation_floor_roi" value="<?= $v('trailing_activation_floor_roi', '0.04') ?>">
                                        <div class="cfg-hint">Порог ROI для активации floor-трейлинга (<code>0.04</code> = после +4% ROI)</div>
                                    </div>
                                    <div class="mb-2">
                                        <label for="trailing_floor_lock_roi" class="form-label">Floor Lock ROI
                                            <i class="bi bi-question-circle cfg-info" title="Minimum guaranteed protected ROI once trailing activates. Must be less than Floor Activation ROI. 0.03 = +3% ROI guaranteed."></i>
                                        </label>
                                        <input type="number" step="0.001" min="0.001" class="form-control" id="trailing_floor_lock_roi" name="trailing_floor_lock_roi" value="<?= $v('trailing_floor_lock_roi', '0.03') ?>">
                                        <div class="cfg-hint">Минимальная гарантированная защищённая прибыль (<code>0.03</code> = не менее +3% ROI после активации). Должно быть меньше Floor Activation ROI</div>
                                    </div>
                                    <div class="mb-2">
                                        <label for="trailing_step_mode" class="form-label">Step Mode
                                            <i class="bi bi-question-circle cfg-info" title="fixed = use step_pct_min as fixed threshold. auto_strength = dynamic step based on move strength within corridor."></i>
                                        </label>
                                        <select class="form-select" id="trailing_step_mode" name="trailing_step_mode">
                                            <?php $currentStepMode = $v('trailing_step_mode', 'fixed'); ?>
                                            <option value="fixed" <?= $currentStepMode === 'fixed' ? 'selected' : '' ?>>Fixed</option>
                                            <option value="auto_strength" <?= $currentStepMode === 'auto_strength' ? 'selected' : '' ?>>Auto Strength</option>
                                        </select>
                                        <div class="cfg-hint"><code>fixed</code> = фиксированный шаг обновления. <code>auto_strength</code> = динамический шаг по силе движения</div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6 mb-2">
                                            <label for="trailing_step_pct_min" class="form-label">Step Min %
                                                <i class="bi bi-question-circle cfg-info" title="Minimum step size for trailing updates. 0.005 = 0.5%."></i>
                                            </label>
                                            <input type="number" step="0.001" min="0.001" max="0.10" class="form-control" id="trailing_step_pct_min" name="trailing_step_pct_min" value="<?= $v('trailing_step_pct_min', '0.005') ?>">
                                            <div class="cfg-hint">Мин. шаг обновления (<code>0.005</code> = 0.5%)</div>
                                        </div>
                                        <div class="col-md-6 mb-2">
                                            <label for="trailing_step_pct_max" class="form-label">Step Max %
                                                <i class="bi bi-question-circle cfg-info" title="Maximum step size for trailing updates. 0.02 = 2%."></i>
                                            </label>
                                            <input type="number" step="0.001" min="0.001" max="0.10" class="form-control" id="trailing_step_pct_max" name="trailing_step_pct_max" value="<?= $v('trailing_step_pct_max', '0.02') ?>">
                                            <div class="cfg-hint">Макс. шаг обновления (<code>0.02</code> = 2%)</div>
                                        </div>
                                    </div>
                                </div>
                                </div>
                            </div>
                            <div class="cfg-mode-note" style="<?= $currentTrailingMode !== 'price_distance_floor' ? '' : 'display:none' ?>">⚠ Активно только в режиме Price Distance Floor</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Exit Safety Section -->
        <div class="row">
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-shield-exclamation me-2"></i>
                        <h5 style="margin: 0;">Exit Safety</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3 form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="break_even_enabled" name="break_even_enabled" value="1" <?= $checked('break_even_enabled') ?>>
                            <label class="form-check-label" for="break_even_enabled">Break-Even Enabled
                                <i class="bi bi-question-circle cfg-info" title="Переместить стоп на цену входа (безубыток) после достижения порога ROI."></i>
                            </label>
                            <br><div class="cfg-hint">Переместить стоп на цену входа (безубыток) после достижения ROI порога</div>
                        </div>
                        <?php $beEnabled = !empty($form_values['break_even_enabled']); ?>
                        <div class="mb-3<?= !$beEnabled ? ' cfg-muted-field' : '' ?>">
                            <label for="break_even_activation_roi" class="form-label">Break-Even Activation ROI
                                <i class="bi bi-question-circle cfg-info" title="ROI, при котором стоп перемещается на безубыток. 0.025 = после +2.5% ROI."></i>
                            </label>
                            <input type="number" step="0.001" min="0" class="form-control" id="break_even_activation_roi" name="break_even_activation_roi" value="<?= $v('break_even_activation_roi', '0.015') ?>">
                            <div class="cfg-hint">ROI для активации безубытка (<code>0.025</code> = после +2.5% ROI стоп → цена входа)</div>
                            <?php if (!$beEnabled): ?>
                            <div class="cfg-mode-note">⚠ Используется только когда Break-Even включён</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stop Loss Engine V2 Section -->
        <div class="row">
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-shield-lock me-2"></i>
                        <h5 style="margin: 0;">Stop Loss Engine</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="stop_mode" class="form-label">Stop Mode</label>
                            <select class="form-select" id="stop_mode" name="stop_mode">
                                <?php foreach (['simple_liq_percent' => 'Simple Liq Percent', 'brain_managed' => 'Brain Managed'] as $sm => $smLabel): ?>
                                <option value="<?= $sm ?>" <?= ($form_values['stop_mode'] ?? 'brain_managed') === $sm ? 'selected' : '' ?>><?= $smLabel ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-secondary">Stop-loss calculation mode</small>
                        </div>
                        <div class="mb-3">
                            <label for="simple_stop_liq_factor" class="form-label">Simple Stop Liq Factor</label>
                            <input type="number" step="0.01" min="0.01" class="form-control" id="simple_stop_liq_factor" name="simple_stop_liq_factor" value="<?= $v('simple_stop_liq_factor', '0.15') ?>">
                            <small class="text-secondary">Fraction of distance-to-liquidation for simple mode (> 0)</small>
                        </div>
                        <div class="mb-3">
                            <label for="brain_stop_corridor_factor" class="form-label">Brain Stop Corridor Factor</label>
                            <input type="number" step="0.01" min="0.01" class="form-control" id="brain_stop_corridor_factor" name="brain_stop_corridor_factor" value="<?= $v('brain_stop_corridor_factor', '0.25') ?>">
                            <small class="text-secondary">Corridor component weight for brain mode (> 0)</small>
                        </div>
                        <div class="mb-3">
                            <label for="brain_stop_volatility_factor" class="form-label">Brain Stop Volatility Factor</label>
                            <input type="number" step="0.01" min="0.01" class="form-control" id="brain_stop_volatility_factor" name="brain_stop_volatility_factor" value="<?= $v('brain_stop_volatility_factor', '0.50') ?>">
                            <small class="text-secondary">Volatility component weight for brain mode (> 0)</small>
                        </div>
                        <div class="mb-3">
                            <label for="brain_stop_liq_safety_factor" class="form-label">Brain Stop Liq Safety Factor</label>
                            <input type="number" step="0.01" min="0.01" class="form-control" id="brain_stop_liq_safety_factor" name="brain_stop_liq_safety_factor" value="<?= $v('brain_stop_liq_safety_factor', '0.30') ?>">
                            <small class="text-secondary">Liquidation safety component for brain mode (> 0)</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Early Failure Guard -->
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <h5 style="margin: 0;">Early Failure Guard</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3 form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="early_failure_enabled" name="early_failure_enabled" value="1" <?= $checked('early_failure_enabled') ?>>
                            <label class="form-check-label" for="early_failure_enabled">Early Failure Enabled</label>
                            <br><small class="text-secondary">Cut obviously bad entries in the first minutes</small>
                        </div>
                        <div class="mb-3">
                            <label for="early_failure_window_minutes" class="form-label">Early Failure Window (minutes)</label>
                            <input type="number" step="1" min="1" class="form-control" id="early_failure_window_minutes" name="early_failure_window_minutes" value="<?= $v('early_failure_window_minutes', '5') ?>">
                            <small class="text-secondary">Time window after entry to check for bad entry (>= 1)</small>
                        </div>
                        <div class="mb-3">
                            <label for="early_failure_max_adverse_roi" class="form-label">Early Failure Max Adverse ROI</label>
                            <input type="number" step="0.001" max="-0.001" class="form-control" id="early_failure_max_adverse_roi" name="early_failure_max_adverse_roi" value="<?= $v('early_failure_max_adverse_roi', '-0.008') ?>">
                            <small class="text-secondary">ROI threshold to trigger early failure (must be &lt; 0, e.g. -0.008 = -0.8%)</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================================================ -->
        <!-- Live Trading Control (Brain-owned) -->
        <!-- ================================================ -->
        <div class="row">
            <div class="col-12 mb-4">
                <div class="card border-warning">
                    <div class="card-header bg-warning bg-opacity-10 d-flex align-items-center">
                        <i class="bi bi-lightning-charge me-2 text-warning"></i>
                        <h5 style="margin: 0;">Live Trading Control</h5>
                        <span class="badge bg-warning text-dark ms-2">Brain-owned</span>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">
                            Brain — единый центр контроля live-торговли. Здесь задаются все стратегические настройки для Trading Bot.
                            Bot выполняет только утверждённые Brain интенты.
                        </p>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="live_trading_enabled" name="live_trading_enabled" value="1" <?= $checked('live_trading_enabled') ?>>
                                    <label class="form-check-label fw-bold" for="live_trading_enabled">Live Trading Enabled</label>
                                    <br><small class="text-secondary">Включить генерацию live intents для Trading Bot</small>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="live_signal_selection_mode" class="form-label">Live Signal Selection Mode</label>
                                <select class="form-select" id="live_signal_selection_mode" name="live_signal_selection_mode">
<?php
    $liveSelModes = [
        'all' => 'all — все сигналы',
        'whitelist_only' => 'whitelist_only — только whitelist',
        'soft_whitelist_only' => 'soft_whitelist_only — только soft whitelist',
        'whitelist_plus_soft' => 'whitelist_plus_soft — whitelist + soft',
        'manual_only' => 'manual_only — только ручной список',
        'manual_plus_soft' => 'manual_plus_soft — manual + soft',
        'manual_plus_whitelist' => 'manual_plus_whitelist — manual + whitelist',
        'watchlist_only' => 'watchlist_only — только watchlist',
    ];
    $currentLiveMode = (string)($form_values['live_signal_selection_mode'] ?? 'whitelist_only');
    foreach ($liveSelModes as $modeKey => $modeLabel):
?>
                                    <option value="<?= $modeKey ?>" <?= $currentLiveMode === $modeKey ? 'selected' : '' ?>><?= htmlspecialchars($modeLabel) ?></option>
<?php endforeach; ?>
                                </select>
                                <small class="text-secondary">Режим отбора символов для live-торговли</small>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="live_max_positions" class="form-label">Live Max Positions</label>
                                <input type="number" step="1" min="1" max="50" class="form-control" id="live_max_positions" name="live_max_positions" value="<?= $v('live_max_positions', '3') ?>">
                                <small class="text-secondary">Макс. кол-во одновременных live-позиций</small>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="live_one_trade_per_symbol" name="live_one_trade_per_symbol" value="1" <?= $checked('live_one_trade_per_symbol') ?>>
                                    <label class="form-check-label" for="live_one_trade_per_symbol">One Trade Per Symbol</label>
                                    <br><small class="text-secondary">Не более одной live-позиции на символ</small>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="live_entry_policy" class="form-label">Live Entry Policy</label>
                                <select class="form-select" id="live_entry_policy" name="live_entry_policy">
<?php
    $currentEntryPolicy = (string)($form_values['live_entry_policy'] ?? 'enter_now');
?>
                                    <option value="enter_now" <?= $currentEntryPolicy === 'enter_now' ? 'selected' : '' ?>>enter_now — немедленный вход</option>
                                    <option value="wait_retrace" <?= $currentEntryPolicy === 'wait_retrace' ? 'selected' : '' ?>>wait_retrace — ждать откат</option>
                                </select>
                                <small class="text-secondary">Политика входа для live-позиций</small>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="form-check form-switch mt-4">
                                    <input class="form-check-input" type="checkbox" role="switch" id="live_reverse_side_enabled" name="live_reverse_side_enabled" value="1" <?= $checked('live_reverse_side_enabled') ?>>
                                    <label class="form-check-label" for="live_reverse_side_enabled">Reverse Side (Live)</label>
                                    <br><small class="text-warning">⚠ Инвертировать сторону (LONG↔SHORT) для live-торговли</small>
                                </div>
                            </div>
                        </div>
                        <div class="alert alert-info small mb-0">
                            <i class="bi bi-info-circle me-1"></i>
                            Trailing / Exit / Stop политика для live-торговли берётся из настроек выше (Exit Policy, Trailing, Stop Control).
                            Bot не имеет собственных стратегических контролов — Brain является единственным источником истины.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================================================ -->
        <!-- Stop / ROI / Leverage Reference Table -->
        <!-- ================================================ -->
        <div class="row">
            <div class="col-12 mb-4">
                <div class="card">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-table me-2"></i>
                        <h5 style="margin: 0;">Stop / ROI / Leverage — Справочная таблица</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <!-- Manual (Fixed ROI) Reference -->
                            <div class="col-md-6 mb-3">
                                <div class="cfg-legend">
                                    <h6 class="text-warning"><i class="bi bi-calculator me-1"></i> Manual (Fixed ROI) — зависит от плеча</h6>
                                    <p>ROI стоп = % убытка по позиции. Реальное движение цены = <code>ROI / leverage</code></p>
                                    <table>
                                        <thead><tr><th>ROI Stop</th><th>Leverage</th><th>≈ Движение цены</th></tr></thead>
                                        <tbody>
                                            <tr><td><code>0.05</code> (5%)</td><td>3x</td><td>≈ 1.67%</td></tr>
                                            <tr><td><code>0.05</code> (5%)</td><td>5x</td><td>≈ 1.0%</td></tr>
                                            <tr><td><code>0.05</code> (5%)</td><td>10x</td><td>≈ 0.5%</td></tr>
                                            <tr><td><code>0.10</code> (10%)</td><td>3x</td><td>≈ 3.33%</td></tr>
                                            <tr><td><code>0.10</code> (10%)</td><td>5x</td><td>≈ 2.0%</td></tr>
                                            <tr><td><code>0.10</code> (10%)</td><td>10x</td><td>≈ 1.0%</td></tr>
                                            <tr><td><code>0.15</code> (15%)</td><td>5x</td><td>≈ 3.0%</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <!-- Entry ROI Reference -->
                            <div class="col-md-6 mb-3">
                                <div class="cfg-legend">
                                    <h6 class="text-success"><i class="bi bi-pin-angle me-1"></i> Entry ROI — не зависит от плеча</h6>
                                    <p>Стоп = процент от цены входа. Одинаковое расстояние от входа при любом плече.</p>
                                    <table>
                                        <thead><tr><th>Значение</th><th>Расстояние от цены входа</th><th>Пример (вход $60000)</th></tr></thead>
                                        <tbody>
                                            <tr><td><code>0.02</code></td><td>2% от цены</td><td>SL ≈ $58800 (long)</td></tr>
                                            <tr><td><code>0.05</code></td><td>5% от цены</td><td>SL ≈ $57000 (long)</td></tr>
                                            <tr><td><code>0.10</code></td><td>10% от цены</td><td>SL ≈ $54000 (long)</td></tr>
                                            <tr><td><code>0.20</code></td><td>20% от цены</td><td>SL ≈ $48000 (long)</td></tr>
                                            <tr><td><code>0.40</code></td><td>40% от цены</td><td>SL ≈ $36000 (long)</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <!-- Trailing/BE Reference -->
                            <div class="col-md-6 mb-3">
                                <div class="cfg-legend">
                                    <h6 class="text-info"><i class="bi bi-graph-up me-1"></i> Trailing / Break-Even — активация</h6>
                                    <table>
                                        <thead><tr><th>Параметр</th><th>Значение</th><th>Что значит</th></tr></thead>
                                        <tbody>
                                            <tr><td>trailing_activation_roi</td><td><code>0.05</code></td><td>Трейлинг стартует после +5% ROI</td></tr>
                                            <tr><td>break_even_activation_roi</td><td><code>0.025</code></td><td>Безубыток после +2.5% ROI</td></tr>
                                            <tr><td>trailing_min_lock_roi</td><td><code>0.012</code></td><td>Зафиксировать мин. +1.2% ROI</td></tr>
                                            <tr><td>trailing_min_step</td><td><code>0.01</code></td><td>Подтягивать стоп шагом 1%</td></tr>
                                            <tr><td>trailing_activation_floor_roi</td><td><code>0.04</code></td><td>Floor-трейлинг стартует после +4% ROI</td></tr>
                                            <tr><td>trailing_floor_lock_roi</td><td><code>0.03</code></td><td>Гарантированная мин. прибыль +3% ROI (режим floor)</td></tr>
                                            <tr><td>trailing_step_pct_min</td><td><code>0.005</code></td><td>Мин. шаг обновления 0.5% (режим floor)</td></tr>
                                            <tr><td>trailing_step_pct_max</td><td><code>0.02</code></td><td>Макс. шаг обновления 2% (режим floor, auto_strength)</td></tr>
                                            <tr><td>fixed_take_profit_roi</td><td><code>0.03</code></td><td>Фиксированный TP на +3% ROI</td></tr>
                                            <tr><td>hybrid_tp_share</td><td><code>0.40</code></td><td>40% по Fixed TP, 60% по трейлингу</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <!-- Key Difference -->
                            <div class="col-md-6 mb-3">
                                <div class="cfg-legend">
                                    <h6 class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i> ROI vs Движение цены — не путайте!</h6>
                                    <ul class="mb-2 ps-3">
                                        <li><strong>ROI %</strong> = доход/убыток по позиции с учётом плеча. <code>ROI = price_move × leverage</code></li>
                                        <li><strong>Price move %</strong> = фактическое изменение цены актива.</li>
                                        <li><strong>Entry ROI</strong> = % от цены входа (не зависит от плеча, это price move).</li>
                                    </ul>
                                    <p class="mb-0"><strong>Пример:</strong> При 5x плече, <code>-2%</code> движения цены = <code>-10%</code> ROI.<br>
                                    Manual stop <code>0.10</code> при 5x → стоп на 2% от цены.<br>
                                    Entry ROI <code>0.10</code> при 5x → стоп на 10% от цены.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================================================ -->
        <!-- Bottom Legend -->
        <!-- ================================================ -->
        <div class="row">
            <div class="col-12 mb-4">
                <div class="card border-secondary">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-book me-2"></i>
                        <h5 style="margin: 0;">Легенда / Как читать параметры</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <!-- 1. Stop Modes -->
                            <div class="col-md-4 mb-3">
                                <div class="cfg-legend h-100">
                                    <h6>1. Режимы стоп-лосса</h6>
                                    <ul class="ps-3 mb-0">
                                        <li><strong>Auto</strong> — Brain рассчитывает от ликвидации</li>
                                        <li><strong>Manual</strong> — по ROI позиции (зависит от плеча)</li>
                                        <li><strong>Entry ROI</strong> — % от цены входа (не зависит от плеча)</li>
                                    </ul>
                                </div>
                            </div>
                            <!-- 2. Exit Modes -->
                            <div class="col-md-4 mb-3">
                                <div class="cfg-legend h-100">
                                    <h6>2. Режимы выхода</h6>
                                    <ul class="ps-3 mb-0">
                                        <li><strong>Fixed TP</strong> — закрыть при достижении ROI цели</li>
                                        <li><strong>Trailing TP</strong> — трейлинг следит за максимумом</li>
                                        <li><strong>Hybrid</strong> — часть по Fixed, остальное по трейлингу</li>
                                    </ul>
                                </div>
                            </div>
                            <!-- 3. Break-Even & Trailing -->
                            <div class="col-md-4 mb-3">
                                <div class="cfg-legend h-100">
                                    <h6>3. Безубыток и трейлинг</h6>
                                    <ul class="ps-3 mb-0">
                                        <li><strong>Break-Even</strong> — стоп → цена входа при ROI порога</li>
                                        <li><strong>Trailing</strong> — подтягивание стопа за ценой</li>
                                        <li><strong>Min Lock</strong> — мин. гарантированная прибыль</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <!-- 4. Units -->
                            <div class="col-md-4 mb-3">
                                <div class="cfg-legend h-100">
                                    <h6>4. Единицы измерения</h6>
                                    <ul class="ps-3 mb-0">
                                        <li>Все значения — <strong>ratio</strong> (0.10 = 10%)</li>
                                        <li>ROI = позиционный доход (с учётом плеча)</li>
                                        <li>Entry ROI = % от цены (без влияния плеча)</li>
                                        <li><code>price_move ≈ ROI / leverage</code></li>
                                    </ul>
                                </div>
                            </div>
                            <!-- 5. Leverage Impact -->
                            <div class="col-md-4 mb-3">
                                <div class="cfg-legend h-100">
                                    <h6>5. Влияние плеча</h6>
                                    <ul class="ps-3 mb-0">
                                        <li>При <strong>3x</strong>: 1% цены = 3% ROI</li>
                                        <li>При <strong>5x</strong>: 1% цены = 5% ROI</li>
                                        <li>При <strong>10x</strong>: 1% цены = 10% ROI</li>
                                        <li>Чем выше плечо, тем меньше «места» для стопа</li>
                                    </ul>
                                </div>
                            </div>
                            <!-- 6. Quick Examples -->
                            <div class="col-md-4 mb-3">
                                <div class="cfg-legend h-100">
                                    <h6>6. Быстрые примеры</h6>
                                    <ul class="ps-3 mb-0">
                                        <li>Entry ROI <code>0.10</code> = стоп на 10% от цены</li>
                                        <li>Manual <code>0.10</code> при 5x = стоп на 2% от цены</li>
                                        <li>Trailing <code>0.05</code> = старт после +5% ROI</li>
                                        <li>BE <code>0.025</code> = безубыток после +2.5% ROI</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="d-flex gap-2 mb-4">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-lg me-1"></i> Save
            </button>
            <button type="reset" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-counterclockwise me-1"></i> Reset
            </button>
            <a href="<?= htmlspecialchars($smartBrainUrl) ?>/user_config" class="btn btn-outline-info">
                <i class="bi bi-arrow-repeat me-1"></i> Reload
            </a>
        </div>
    </form>
    <script>
    // Dynamic mode-specific field highlighting
    document.addEventListener('DOMContentLoaded', function() {
        // Stop control mode visibility
        var stopModeSelect = document.getElementById('stop_control_mode');
        if (stopModeSelect) {
            stopModeSelect.addEventListener('change', function() {
                var mode = this.value;
                var manualGroup = document.getElementById('manual_stop_group');
                var entryGroup = document.getElementById('entry_roi_stop_group');
                if (manualGroup) {
                    manualGroup.classList.toggle('cfg-muted-field', mode !== 'manual');
                    var manualNote = manualGroup.querySelector('.cfg-mode-note');
                    if (manualNote) manualNote.style.display = mode !== 'manual' ? '' : 'none';
                }
                if (entryGroup) {
                    entryGroup.classList.toggle('cfg-muted-field', mode !== 'entry_roi');
                    var entryNote = entryGroup.querySelector('.cfg-mode-note');
                    if (entryNote) entryNote.style.display = mode !== 'entry_roi' ? '' : 'none';
                }
            });
        }

        // Trailing mode visibility — show/mute fields by active mode with collapsible sections
        var trailingModeSelect = document.getElementById('trailing_mode');
        if (trailingModeSelect) {
            function updateTrailingModeVisibility() {
                var mode = trailingModeSelect.value;
                var floorGroup = document.getElementById('floor_trailing_fields_group');
                var givebackGroup = document.getElementById('roi_giveback_fields_group');
                var distanceGroup = document.getElementById('price_distance_fields_group');

                // Floor fields: active only in price_distance_floor
                if (floorGroup) {
                    floorGroup.classList.toggle('cfg-muted-field', mode !== 'price_distance_floor');
                    var floorNote = floorGroup.querySelector('.cfg-mode-note');
                    if (floorNote) floorNote.style.display = mode !== 'price_distance_floor' ? '' : 'none';
                    // Update card styling and badge
                    var floorCard = floorGroup.querySelector('.card');
                    var floorHeader = floorGroup.querySelector('.card-header');
                    var floorBadge = floorGroup.querySelector('.card-header .badge');
                    var floorCollapse = document.getElementById('floor_trailing_collapse');
                    if (floorCard) {
                        floorCard.className = mode === 'price_distance_floor' ? 'card border-info' : 'card border-secondary';
                    }
                    if (floorHeader) {
                        floorHeader.className = floorHeader.className.replace(/bg-\w+ bg-opacity-10/, mode === 'price_distance_floor' ? 'bg-info bg-opacity-10' : 'bg-secondary bg-opacity-10');
                    }
                    if (floorBadge) {
                        floorBadge.className = mode === 'price_distance_floor' ? 'badge bg-info ms-2' : 'badge bg-secondary ms-2';
                        floorBadge.textContent = mode === 'price_distance_floor' ? 'active' : 'inactive — other mode';
                    }
                    // Auto-expand/collapse
                    if (floorCollapse) {
                        if (mode === 'price_distance_floor') {
                            floorCollapse.classList.add('show');
                        } else {
                            floorCollapse.classList.remove('show');
                        }
                    }
                }
                // ROI Giveback fields: active in roi_giveback and price_distance, muted in floor
                if (givebackGroup) {
                    givebackGroup.classList.toggle('cfg-muted-field', mode === 'price_distance_floor');
                    var gbNote = givebackGroup.querySelector('.cfg-mode-note');
                    if (gbNote) gbNote.style.display = mode === 'price_distance_floor' ? '' : 'none';
                    // Update card styling and badge
                    var gbCard = givebackGroup.querySelector('.card');
                    var gbHeader = givebackGroup.querySelector('.card-header');
                    var gbBadge = givebackGroup.querySelector('.card-header .badge');
                    var gbCollapse = document.getElementById('roi_giveback_collapse');
                    if (gbCard) {
                        gbCard.className = mode === 'price_distance_floor' ? 'card mb-2 border-secondary' : 'card mb-2 border-success';
                    }
                    if (gbHeader) {
                        gbHeader.className = gbHeader.className.replace(/bg-\w+ bg-opacity-10/, mode === 'price_distance_floor' ? 'bg-secondary bg-opacity-10' : 'bg-success bg-opacity-10');
                    }
                    if (gbBadge) {
                        gbBadge.className = mode === 'price_distance_floor' ? 'badge bg-secondary ms-2' : 'badge bg-success ms-2';
                        gbBadge.textContent = mode === 'price_distance_floor' ? 'inactive — other mode' : 'active';
                    }
                    // Auto-expand/collapse
                    if (gbCollapse) {
                        if (mode !== 'price_distance_floor') {
                            gbCollapse.classList.add('show');
                        } else {
                            gbCollapse.classList.remove('show');
                        }
                    }
                }
                // Price distance pct: active in price_distance and price_distance_floor
                if (distanceGroup) {
                    distanceGroup.classList.toggle('cfg-muted-field', mode === 'roi_giveback');
                    var distNote = distanceGroup.querySelector('.cfg-mode-note');
                    if (distNote) distNote.style.display = mode === 'roi_giveback' ? '' : 'none';
                }
            }
            trailingModeSelect.addEventListener('change', updateTrailingModeVisibility);
        }

        // Execution Profile switcher — update description and badge on change
        var profileSelect = document.getElementById('execution_profile');
        if (profileSelect) {
            var profileBundles = <?= json_encode($profileBundles, JSON_UNESCAPED_UNICODE) ?>;
            profileSelect.addEventListener('change', function() {
                var pid = this.value;
                var bundle = profileBundles[pid] || profileBundles['custom'];
                var descCard = document.getElementById('profile-description-card');
                var descLabel = document.getElementById('profile-desc-label');
                var descText = document.getElementById('profile-desc-text');
                if (descLabel) descLabel.textContent = bundle.label + ': ';
                if (descText) descText.textContent = bundle.description;
                if (descCard) {
                    descCard.className = 'alert mb-0';
                    descCard.classList.add(pid !== 'custom' ? 'alert-info' : 'alert-secondary');
                    descCard.style.fontSize = '0.85rem';
                    // Append sniper warning if applicable
                    var existing = descCard.querySelector('.text-warning');
                    if (existing) existing.remove();
                    if (pid === 'sniper_75_attempt') {
                        var warn = document.createElement('div');
                        warn.className = 'mt-1 text-warning';
                        warn.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>Very selective profile. Fewer trades expected. Higher target precision, not guaranteed winrate.';
                        descCard.appendChild(warn);
                    }
                }
                // Update header badge
                var headerCard = profileSelect.closest('.card');
                if (headerCard) {
                    var headerBadge = headerCard.querySelector('.card-header .badge');
                    if (headerBadge) {
                        headerBadge.className = pid !== 'custom' ? 'badge bg-primary ms-2' : 'badge bg-secondary ms-2';
                        headerBadge.textContent = bundle.label;
                    }
                    headerCard.style.borderColor = pid !== 'custom' ? '#6366f1' : '#6b7280';
                    var cardHeader = headerCard.querySelector('.card-header');
                    if (cardHeader) {
                        cardHeader.style.background = pid !== 'custom' ? 'rgba(99,102,241,0.1)' : 'rgba(107,114,128,0.1)';
                    }
                }
            });
        }

        // Pattern profile mode toggle — enable/disable pattern checkboxes
        var patternProfileModeSelect = document.getElementById('pattern_profile_mode');
        if (patternProfileModeSelect) {
            patternProfileModeSelect.addEventListener('change', function() {
                var isControlled = this.value === 'profile_controlled';
                var card = document.getElementById('pattern-selection-card');
                if (card) {
                    card.style.opacity = isControlled ? '0.6' : '1';
                    var badge = card.querySelector('.card-header .badge');
                    if (badge) {
                        badge.style.display = isControlled ? '' : 'none';
                    }
                }
                // Disable/enable pattern checkboxes and mode select
                var checkboxes = document.querySelectorAll('input[name="patterns_enabled[]"]');
                checkboxes.forEach(function(cb) { cb.disabled = isControlled; });
                var modeSelect = document.getElementById('pattern_mode');
                if (modeSelect) modeSelect.disabled = isControlled;
            });
        }
    });
    </script>
<?php
};

require __DIR__ . '/_layout.php';
