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

$pageTitle = 'Smart Brain - User Config';
$activeTab = 'user_config';

$patterns_enabled = $patterns_enabled ?? [];
$pattern_mode = $pattern_mode ?? 'any';
$symbol_intelligence_enabled = $symbol_intelligence_enabled ?? false;
$symbol_filter_mode = $symbol_filter_mode ?? 'all';
$manual_symbol_universe_enabled = $manual_symbol_universe_enabled ?? false;
$manual_symbol_mode = $manual_symbol_mode ?? 'manual_only';
$config_warnings = $config_warnings ?? [];

$pageContent = function() use ($form_values, $user_limits, $smartBrainUrl, $patterns_enabled, $pattern_mode, $symbol_intelligence_enabled, $symbol_filter_mode, $manual_symbol_universe_enabled, $manual_symbol_mode, $config_warnings) {
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

        <!-- Pattern Selection Section -->
        <div class="row">
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-search me-2"></i>
                        <h5 style="margin: 0;">Pattern Selection</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-secondary mb-3" style="font-size: 0.85rem;">
                            Выбранные алгоритмы используются анализатором для поиска входов.<br>
                            Режим «any» — достаточно совпадения любого включённого алгоритма.<br>
                            Режим «one» — используется только один выбранный алгоритм.<br>
                            Режим «all» — сигнал допускается только при подтверждении всеми включёнными алгоритмами.
                        </p>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Enabled Algorithms</label>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="pattern_double_bottom" name="patterns_enabled[]" value="double_bottom" <?= $patternChecked('double_bottom') ?>>
                                <label class="form-check-label" for="pattern_double_bottom">Double Bottom</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="pattern_double_top" name="patterns_enabled[]" value="double_top" <?= $patternChecked('double_top') ?>>
                                <label class="form-check-label" for="pattern_double_top">Double Top</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="pattern_pullback" name="patterns_enabled[]" value="pullback_trend_continue" <?= $patternChecked('pullback_trend_continue') ?>>
                                <label class="form-check-label" for="pattern_pullback">Pullback Trend Continue</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="pattern_double_bottom_v2" name="patterns_enabled[]" value="double_bottom_confirm_v2" <?= $patternChecked('double_bottom_confirm_v2') ?>>
                                <label class="form-check-label" for="pattern_double_bottom_v2">Double Bottom V2 <small class="text-info">(confirmed)</small></label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="pattern_double_top_v2" name="patterns_enabled[]" value="double_top_confirm_v2" <?= $patternChecked('double_top_confirm_v2') ?>>
                                <label class="form-check-label" for="pattern_double_top_v2">Double Top V2 <small class="text-info">(confirmed)</small></label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="pattern_double_bottom_ctx_v2" name="patterns_enabled[]" value="double_bottom_contextual_v2" <?= $patternChecked('double_bottom_contextual_v2') ?>>
                                <label class="form-check-label" for="pattern_double_bottom_ctx_v2">Double Bottom Contextual V2 <small class="text-warning">(context-aware)</small></label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="pattern_double_bottom_ctx_v3" name="patterns_enabled[]" value="double_bottom_contextual_v3" <?= $patternChecked('double_bottom_contextual_v3') ?>>
                                <label class="form-check-label" for="pattern_double_bottom_ctx_v3">Double Bottom Contextual V3 <small class="text-danger">(regime-aware)</small></label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="pattern_mode" class="form-label fw-bold">Pattern Mode</label>
                            <select class="form-select" id="pattern_mode" name="pattern_mode">
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
                                <i class="bi bi-question-circle cfg-info" title="roi_giveback = classic drawdown-factor trailing. price_distance = fixed % distance from current price."></i>
                            </label>
                            <select class="form-select" id="trailing_mode" name="trailing_mode">
                                <?php $currentTrailingMode = $v('trailing_mode', 'roi_giveback'); ?>
                                <option value="roi_giveback" <?= $currentTrailingMode === 'roi_giveback' ? 'selected' : '' ?>>ROI Giveback (classic)</option>
                                <option value="price_distance" <?= $currentTrailingMode === 'price_distance' ? 'selected' : '' ?>>Price Distance (fixed % from price)</option>
                            </select>
                            <div class="cfg-hint">
                                <code>roi_giveback</code> = стоп следит за drawdown_factor × макс. ROI.<br>
                                <code>price_distance</code> = стоп держится на фиксированном расстоянии от текущей цены (например 2–3%)
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="trailing_price_distance_pct" class="form-label">Trailing Price Distance %
                                <i class="bi bi-question-circle cfg-info" title="Only used in price_distance mode. 0.02 = 2% from current price. Min 0.005, max 0.20."></i>
                            </label>
                            <input type="number" step="0.001" min="0.005" max="0.20" class="form-control" id="trailing_price_distance_pct" name="trailing_price_distance_pct" value="<?= $v('trailing_price_distance_pct', '0.02') ?>">
                            <div class="cfg-hint">Расстояние от текущей цены (<code>0.02</code> = 2% от текущей цены). Используется только в режиме price_distance</div>
                        </div>
                        <div class="mb-3">
                            <label for="trailing_activation_roi" class="form-label">Trailing Activation ROI
                                <i class="bi bi-question-circle cfg-info" title="Трейлинг начинает работать после достижения этого ROI. 0.05 = +5% ROI."></i>
                            </label>
                            <input type="number" step="0.001" min="0" class="form-control" id="trailing_activation_roi" name="trailing_activation_roi" value="<?= $v('trailing_activation_roi', '0.03') ?>">
                            <div class="cfg-hint">Порог ROI для активации трейлинга (<code>0.05</code> = после +5% ROI)</div>
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
    });
    </script>
<?php
};

require __DIR__ . '/_layout.php';
