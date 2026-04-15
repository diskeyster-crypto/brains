<?php
declare(strict_types=1);

/**
 * Центр Конфигурации — Win Universe (Выигрышные монеты)
 *
 * Editable Win Universe settings + live runtime data (pool, promotions, demotions).
 * Saves to win_universe standalone module user config overlay.
 * SHADOW ONLY — no trading behavior changes occur from this step.
 *
 * @var string                   $tab
 * @var string                   $title
 * @var string                   $baseUrl
 * @var array<string,mixed>      $flash
 * @var array<string,mixed>      $wuConfig    Current effective win_universe config
 * @var array<string,mixed>|null $wuUniverse  win_universe.json runtime data
 * @var array<string,mixed>|null $wuStatus    win_universe_status.json
 * @var array<string,mixed>|null $wuPool      win_universe_pool.json
 * @var array<string,mixed>|null $wuPromotions win_universe_promotions.json
 * @var array<string,mixed>|null $wuDemotions  win_universe_demotions.json
 */

$wuCfg       = $wuConfig['win_universe'] ?? [];
$wuStatus    = $wuStatus    ?? null;
$wuUniverse  = $wuUniverse  ?? null;
$wuPool      = $wuPool      ?? null;
$wuPromotions = $wuPromotions ?? null;
$wuDemotions  = $wuDemotions  ?? null;

// Current values for form
$cfgEnabled    = (bool)($wuCfg['win_universe_enabled'] ?? true);
$cfgMode       = (string)($wuCfg['win_universe_mode']  ?? 'shadow');
$cfgMinRoi     = (float)($wuCfg['min_roi_threshold']   ?? 0.05);
$cfgLookback   = (int)($wuCfg['lookback_days']         ?? 60);
$cfgMinTrades  = (int)($wuCfg['min_closed_trades']     ?? 2);
$cfgMinWinrate = (float)($wuCfg['min_winrate']         ?? 0.0);
$cfgMinAvgRoi  = (float)($wuCfg['min_avg_roi']         ?? 0.005);
$cfgExpiry     = (int)($wuCfg['qualification_expiry_days'] ?? 90);
$cfgStreak     = (int)($wuCfg['demotion_loss_streak']  ?? 0);
$cfgPrioEnabled = (bool)($wuCfg['priority_bonus_enabled'] ?? false);
$cfgPrioStr    = (float)($wuCfg['priority_bonus_strength'] ?? 0.1);

// Runtime summary
$qualCount  = count($wuUniverse['qualified']    ?? []);
$nearCount  = count($wuUniverse['near_qualified'] ?? []);
$rejCount   = count($wuUniverse['rejected']     ?? []);
$poolCount  = count($wuPool['win_pool'] ?? ($wuUniverse['win_pool'] ?? []));
$winPool    = $wuPool['win_pool'] ?? ($wuUniverse['win_pool'] ?? []);
$lastRun    = $wuStatus['run_at'] ?? null;
$lastOk     = $wuStatus['ok']    ?? null;
$sensitivity    = $wuStatus['threshold_sensitivity']         ?? $wuUniverse['threshold_sensitivity']         ?? [];
$candPreview    = $wuStatus['candidate_sensitivity_preview'] ?? $wuUniverse['candidate_sensitivity_preview'] ?? [];

$promoEvents = $wuPromotions['events'] ?? [];
$demoEvents  = $wuDemotions['events']  ?? [];

ob_start();
?>

<!-- Flash message -->
<?php if (!empty($flash)): ?>
<div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'warning' ? 'warning' : 'danger') ?> mb-4 py-2">
    <?= htmlspecialchars($flash['message'] ?? '') ?>
</div>
<?php endif; ?>

<!-- Shadow-only notice -->
<div class="alert py-2 mb-4" style="background:rgba(245,158,11,0.08); border-color:rgba(245,158,11,0.25); font-size:0.85rem;">
    <i class="bi bi-shield-check text-warning me-1"></i>
    <strong class="text-warning">Только теневой режим</strong> —
    Win Universe является аналитическим модулем. Настройки режима сохраняются, но
    <strong>не влияют на торговлю</strong> в текущем шаге.
    Интеграция приоритетов будет добавлена в следующем шаге.
</div>

<div class="row g-4">

    <!-- ── Форма настроек ──────────────────────────────────────────────────── -->
    <div class="col-12 col-xl-6">
        <div class="card">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="bi bi-trophy text-warning"></i>
                <strong>Win Universe — Настройки</strong>
            </div>
            <div class="card-body">
                <form method="POST" action="<?= htmlspecialchars($baseUrl) ?>/win_universe/save">
                    <input type="hidden" name="_redirect" value="<?= htmlspecialchars($baseUrl) ?>/win_universe">

                    <!-- Enabled toggle -->
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="win_universe_enabled"
                                   id="wuEnabled" value="1" <?= $cfgEnabled ? 'checked' : '' ?>>
                            <label class="form-check-label" for="wuEnabled">
                                <strong>Модуль включён</strong>
                                <small class="text-secondary d-block">Когда выключен — вычисления не выполняются</small>
                            </label>
                        </div>
                    </div>

                    <!-- Mode -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="color:#cbd5e1;">Режим работы</label>
                        <select class="form-select form-select-sm" name="win_universe_mode">
                            <option value="shadow"   <?= $cfgMode === 'shadow'   ? 'selected' : '' ?>>shadow — только аналитика (текущий)</option>
                            <option value="priority" <?= $cfgMode === 'priority' ? 'selected' : '' ?>>priority — приоритет в ранжировании (будущее)</option>
                            <option value="win_only" <?= $cfgMode === 'win_only' ? 'selected' : '' ?>>win_only — только win-pool монеты (будущее)</option>
                        </select>
                        <div class="form-text text-secondary">
                            Режимы <code>priority</code> и <code>win_only</code> сохраняются, но не влияют на торговлю до следующего шага интеграции.
                        </div>
                    </div>

                    <hr style="border-color:#334155;">
                    <p class="fw-semibold mb-2" style="font-size:0.82rem; color:#94a3b8; text-transform:uppercase; letter-spacing:.06em;">Пороги квалификации</p>

                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label" style="font-size:0.82rem; color:#cbd5e1;">
                                Мин. ROI на сделку
                                <span style="color:#64748b;">(≈<?= number_format($cfgMinRoi * 100, 2) ?>%)</span>
                            </label>
                            <input type="number" step="0.001" class="form-control form-control-sm" name="min_roi_threshold"
                                   value="<?= htmlspecialchars((string)$cfgMinRoi) ?>">
                            <div class="form-text text-secondary">Дробное значение: 0.05 = 5%. Победа засчитывается при ROI ≥ этого порога.</div>
                        </div>
                        <div class="col-6">
                            <label class="form-label" style="font-size:0.82rem; color:#cbd5e1;">
                                Мин. средний ROI
                                <span style="color:#64748b;">(≈<?= number_format($cfgMinAvgRoi * 100, 2) ?>%)</span>
                            </label>
                            <input type="number" step="0.001" class="form-control form-control-sm" name="min_avg_roi"
                                   value="<?= htmlspecialchars((string)$cfgMinAvgRoi) ?>">
                            <div class="form-text text-secondary">Дробное значение: 0.005 = 0.5%. 0 = отключено.</div>
                        </div>
                        <div class="col-6">
                            <label class="form-label" style="font-size:0.82rem; color:#cbd5e1;">Окно (дней)</label>
                            <input type="number" step="1" min="1" class="form-control form-control-sm" name="lookback_days"
                                   value="<?= htmlspecialchars((string)$cfgLookback) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label" style="font-size:0.82rem; color:#cbd5e1;">Мин. сделок в окне</label>
                            <input type="number" step="1" min="1" class="form-control form-control-sm" name="min_closed_trades"
                                   value="<?= htmlspecialchars((string)$cfgMinTrades) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label" style="font-size:0.82rem; color:#cbd5e1;">Мин. winrate (0–1)</label>
                            <input type="number" step="0.01" min="0" max="1" class="form-control form-control-sm" name="min_winrate"
                                   value="<?= htmlspecialchars((string)$cfgMinWinrate) ?>">
                            <div class="form-text text-secondary">0 = отключено</div>
                        </div>
                    </div>

                    <hr style="border-color:#334155;">
                    <p class="fw-semibold mb-2" style="font-size:0.82rem; color:#94a3b8; text-transform:uppercase; letter-spacing:.06em;">Жизненный цикл пула</p>

                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label" style="font-size:0.82rem; color:#cbd5e1;">Срок действия квалификации (дней)</label>
                            <input type="number" step="1" min="0" class="form-control form-control-sm" name="qualification_expiry_days"
                                   value="<?= htmlspecialchars((string)$cfgExpiry) ?>">
                            <div class="form-text text-secondary">0 = без срока истечения</div>
                        </div>
                        <div class="col-6">
                            <label class="form-label" style="font-size:0.82rem; color:#cbd5e1;">Деквалификация при серии убытков</label>
                            <input type="number" step="1" min="0" class="form-control form-control-sm" name="demotion_loss_streak"
                                   value="<?= htmlspecialchars((string)$cfgStreak) ?>">
                            <div class="form-text text-secondary">0 = отключено</div>
                        </div>
                    </div>

                    <hr style="border-color:#334155;">
                    <p class="fw-semibold mb-2" style="font-size:0.82rem; color:#94a3b8; text-transform:uppercase; letter-spacing:.06em;">Приоритетный бонус (будущее)</p>

                    <div class="row g-3 mb-4">
                        <div class="col-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="priority_bonus_enabled"
                                       id="wuPrioEnabled" value="1" <?= $cfgPrioEnabled ? 'checked' : '' ?>>
                                <label class="form-check-label" for="wuPrioEnabled" style="font-size:0.85rem;">
                                    Бонус приоритета включён
                                    <small class="text-secondary d-block">Не влияет на торговлю в текущем шаге</small>
                                </label>
                            </div>
                        </div>
                        <div class="col-6">
                            <label class="form-label" style="font-size:0.82rem; color:#cbd5e1;">Сила бонуса (0–1)</label>
                            <input type="number" step="0.01" min="0" max="1" class="form-control form-control-sm" name="priority_bonus_strength"
                                   value="<?= htmlspecialchars((string)$cfgPrioStr) ?>">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-floppy me-1"></i>Сохранить настройки
                    </button>
                    <a href="<?= htmlspecialchars($baseUrl) ?>/win_universe" class="btn btn-outline-secondary btn-sm ms-2">
                        Сбросить
                    </a>
                </form>
            </div>
        </div>
    </div>

    <!-- ── Текущий статус и пул ────────────────────────────────────────────── -->
    <div class="col-12 col-xl-6">

        <!-- Runtime status summary -->
        <div class="card mb-4">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span><i class="bi bi-activity me-2 text-primary"></i><strong>Статус модуля</strong></span>
                <?php if ($lastRun): ?>
                <small class="text-secondary"><?= htmlspecialchars(substr($lastRun, 0, 19)) ?></small>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="row g-3 text-center">
                    <div class="col-3">
                        <div style="font-size:1.5rem; font-weight:700; color:#34d399;"><?= $qualCount ?></div>
                        <div style="font-size:0.72rem; color:#94a3b8;">Квалифицировано</div>
                    </div>
                    <div class="col-3">
                        <div style="font-size:1.5rem; font-weight:700; color:#fbbf24;"><?= $nearCount ?></div>
                        <div style="font-size:0.72rem; color:#94a3b8;">Почти</div>
                    </div>
                    <div class="col-3">
                        <div style="font-size:1.5rem; font-weight:700; color:#94a3b8;"><?= $rejCount ?></div>
                        <div style="font-size:0.72rem; color:#94a3b8;">Отклонено</div>
                    </div>
                    <div class="col-3">
                        <div style="font-size:1.5rem; font-weight:700; color:#a78bfa;"><?= $poolCount ?></div>
                        <div style="font-size:0.72rem; color:#94a3b8;">Win Pool</div>
                    </div>
                </div>
                <?php if (!$lastRun): ?>
                <p class="text-secondary small mb-0 mt-3">
                    <i class="bi bi-info-circle me-1"></i>
                    Данные ещё не вычислены.
                    Перейдите на страницу <a href="/admin/smart_brain/win_universe" class="text-primary">Win Universe</a> и нажмите «Запустить».
                </p>
                <?php endif; ?>
                <?php
                $activeCfg = $wuStatus['config_used'] ?? [];
                if (!empty($activeCfg)):
                    $activeRoiPct    = isset($activeCfg['min_roi_threshold_pct'])
                        ? $activeCfg['min_roi_threshold_pct']
                        : round((float)($activeCfg['min_roi_threshold'] ?? 0) * 100, 2);
                    $activeAvgRoiPct = isset($activeCfg['min_avg_roi_pct'])
                        ? $activeCfg['min_avg_roi_pct']
                        : round((float)($activeCfg['min_avg_roi'] ?? 0) * 100, 2);
                ?>
                <div class="mt-3" style="font-size:0.76rem; color:#64748b; border-top:1px solid #1e293b; padding-top:8px;">
                    Активные пороги: ROI≥<?= $activeRoiPct ?>%,
                    avgROI≥<?= $activeAvgRoiPct ?>%,
                    сделок≥<?= (int)($activeCfg['min_closed_trades'] ?? 0) ?>,
                    winrate≥<?= (int)(($activeCfg['min_winrate'] ?? 0) * 100) ?>%,
                    окно=<?= (int)($activeCfg['lookback_days'] ?? 0) ?>д.
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Threshold diagnostics (read-only) -->
        <?php
        $excessiveWarn = $wuStatus['excessive_qualification_warning'] ?? ($wuUniverse['excessive_qualification_warning'] ?? false);
        $excessiveNote = $wuStatus['excessive_qualification_note']    ?? ($wuUniverse['excessive_qualification_note']    ?? null);
        ?>
        <?php if ($excessiveWarn && $excessiveNote): ?>
        <div class="alert py-2 mb-3" style="background:rgba(239,68,68,0.08); border-color:rgba(239,68,68,0.3); font-size:0.83rem;">
            <i class="bi bi-exclamation-triangle text-danger me-1"></i>
            <strong class="text-danger">Предупреждение:</strong>
            <?= htmlspecialchars($excessiveNote) ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($sensitivity) || !empty($candPreview)): ?>
        <div class="card mb-4">
            <div class="card-header" style="font-size:0.82rem;">
                <i class="bi bi-bar-chart-fill text-info me-1"></i>
                <strong>Диагностика порогов</strong>
                <small class="text-secondary ms-2">— только чтение</small>
            </div>
            <div class="card-body py-2">
                <?php if (!empty($sensitivity)): ?>
                <div class="row g-2 mb-2">
                    <?php
                    $diagItems = [
                        ['label' => 'Мало сделок',  'key' => 'failed_by_min_trades_count',    'color' => '#f87171'],
                        ['label' => 'Мало ROI',      'key' => 'failed_by_roi_threshold_count', 'color' => '#fb923c'],
                        ['label' => 'Мало avg ROI',  'key' => 'failed_by_avg_roi_count',       'color' => '#facc15'],
                        ['label' => 'Мало winrate',  'key' => 'failed_by_winrate_count',       'color' => '#a78bfa'],
                    ];
                    foreach ($diagItems as $di):
                        $val = (int)($sensitivity[$di['key']] ?? 0);
                    ?>
                    <div class="col-auto">
                        <div style="background:rgba(30,41,59,0.8); border:1px solid #334155; border-radius:6px; padding:5px 10px; text-align:center;">
                            <div style="font-size:1.1rem; font-weight:700; color:<?= $val > 0 ? $di['color'] : '#475569' ?>;"><?= $val ?></div>
                            <div style="font-size:0.7rem; color:#94a3b8;"><?= htmlspecialchars($di['label']) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php
                    $onlyRoi = (int)($sensitivity['fail_by_roi_only'] ?? 0);
                    if ($onlyRoi > 0):
                    ?>
                    <div class="col-auto">
                        <div style="background:rgba(30,41,59,0.8); border:1px solid #334155; border-radius:6px; padding:5px 10px; text-align:center;">
                            <div style="font-size:1.1rem; font-weight:700; color:#34d399;"><?= $onlyRoi ?></div>
                            <div style="font-size:0.7rem; color:#94a3b8;">Только ROI мешает</div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($candPreview)): ?>
                <div style="font-size:0.78rem; color:#94a3b8;" class="mb-1">Предварительный просмотр чувствительности:</div>
                <div class="d-flex flex-wrap gap-2">
                <?php foreach ($candPreview as $key => $sc): ?>
                    <?php
                    // ROI thresholds stored as decimal fractions (0.01 = 1%); multiply by 100 for display.
                    $scRoiPct = number_format((float)($sc['min_roi_threshold'] ?? 0) * 100, 2);
                    ?>
                    <span class="badge" style="background:rgba(51,65,85,0.9); border:1px solid #475569; font-size:0.73rem; padding:4px 8px; font-weight:400;">
                        <?= htmlspecialchars($sc['label'] ?? $key) ?>:
                        <strong style="color:<?= ((int)($sc['qualified_count'] ?? 0)) > 0 ? '#34d399' : '#f87171' ?>;">
                            <?= (int)($sc['qualified_count'] ?? 0) ?>
                        </strong>
                        <span style="color:#64748b;">(ROI≥<?= $scRoiPct ?>%, ≥<?= (int)($sc['min_closed_trades'] ?? 0) ?> сд.)</span>
                    </span>
                <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Win Pool table -->
        <?php if (!empty($winPool)): ?>        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-trophy-fill text-warning me-1"></i>
                <strong>Win Pool — монеты в пуле (<?= count($winPool) ?>)</strong>
            </div>
            <div class="table-responsive">
                <table class="table table-dark table-sm mb-0">
                    <thead><tr>
                        <th>Монета</th>
                        <th>Добавлена</th>
                        <th>Ср. ROI</th>
                        <th>Причина</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($winPool as $sym => $entry): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($sym) ?></strong></td>
                            <td><?= htmlspecialchars(substr((string)($entry['promoted_at'] ?? '—'), 0, 10)) ?></td>
                            <td><?php
                                $ar = $entry['last_avg_roi'] ?? null;
                                if ($ar !== null) {
                                    $cls = (float)$ar >= 0 ? 'text-success' : 'text-danger';
                                    echo '<span class="' . $cls . '">' . number_format((float)$ar, 2) . '%</span>';
                                } else { echo '—'; }
                            ?></td>
                            <td><small class="text-secondary"><?= htmlspecialchars(substr((string)($entry['promotion_reason'] ?? ''), 0, 60)) ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Promotion / demotion history -->
        <?php if (!empty($promoEvents) || !empty($demoEvents)): ?>
        <div class="card">
            <div class="card-header">
                <i class="bi bi-arrow-left-right me-1"></i>
                <strong>История событий</strong>
                <small class="text-secondary ms-2">(повышения / понижения)</small>
            </div>
            <div class="table-responsive" style="max-height:280px; overflow-y:auto;">
                <table class="table table-dark table-sm mb-0">
                    <thead><tr>
                        <th>Монета</th>
                        <th>Событие</th>
                        <th>Дата</th>
                        <th>Причина</th>
                    </tr></thead>
                    <tbody>
                    <?php
                    // Merge and sort most-recent-first
                    $allEvents = array_merge($promoEvents, $demoEvents);
                    usort($allEvents, static function (array $a, array $b): int {
                        return strcmp((string)($b['timestamp'] ?? ''), (string)($a['timestamp'] ?? ''));
                    });
                    foreach (array_slice($allEvents, 0, 30) as $ev):
                        $isPromo = ($ev['event'] ?? '') === 'promoted';
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($ev['symbol'] ?? '') ?></strong></td>
                        <td>
                            <?php if ($isPromo): ?>
                                <span class="badge" style="background:rgba(16,185,129,0.2);color:#34d399;border:1px solid rgba(16,185,129,0.3);">↑ Повышен</span>
                            <?php else: ?>
                                <span class="badge" style="background:rgba(239,68,68,0.2);color:#f87171;border:1px solid rgba(239,68,68,0.3);">↓ Понижен</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars(substr((string)($ev['timestamp'] ?? ''), 0, 10)) ?></td>
                        <td><small class="text-secondary"><?= htmlspecialchars(substr((string)($ev['reason'] ?? ''), 0, 70)) ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if (empty($winPool) && empty($promoEvents) && empty($demoEvents)): ?>
        <div class="alert alert-secondary">
            <i class="bi bi-info-circle me-1"></i>
            Win Pool пуст. Запустите вычисление, чтобы выполнить первый проход квалификации и повышения.
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/_layout.php';
