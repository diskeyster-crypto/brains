<?php
declare(strict_types=1);

/**
 * Паттерны — конфигурация алгоритмов распознавания паттернов.
 * Editable — patterns_enabled и patterns_mode сохраняются в мастер-конфиге.
 */

$params      = $data['params'] ?? [];
$master      = $master ?? null;
$masterParams = $master['params'] ?? [];

$patternsEnabled = isset($masterParams['patterns_enabled'])
    ? $masterParams['patterns_enabled']['value']
    : ($params['patterns_enabled']['value'] ?? null);
$patternsMode = isset($masterParams['patterns_mode'])
    ? $masterParams['patterns_mode']['value']
    : ($params['patterns_mode']['value'] ?? null);

$hasMasterPatterns = isset($masterParams['patterns_enabled']);
$hasMasterMode     = isset($masterParams['patterns_mode']);

$knownPatterns = [
    'double_bottom_contextual_v2' => 'Double Bottom Contextual V2',
    'double_bottom_contextual_v3' => 'Double Bottom Contextual V3',
    'double_top_contextual_v2'    => 'Double Top Contextual V2',
    'double_top_contextual_v3'    => 'Double Top Contextual V3',
    'double_bottom'               => 'Double Bottom (классик)',
    'double_top'                  => 'Double Top (классик)',
    'double_bottom_confirm_v2'    => 'Double Bottom Confirm V2',
    'double_top_confirm_v2'       => 'Double Top Confirm V2',
    'pullback_trend_continue'     => 'Pullback Trend Continue',
];

ob_start();
?>
<div class="row g-3">

    <!-- Editable section -->
    <div class="col-12 col-md-6">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span><i class="bi bi-diagram-3 me-2 text-info"></i>Активные паттерны</span>
                <?php if ($hasMasterPatterns): ?>
                    <span class="master-badge">мастер</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <form method="post" action="<?= htmlspecialchars($baseUrl) ?>/api/save">
                    <input type="hidden" name="_redirect" value="<?= htmlspecialchars($baseUrl) ?>/patterns">
                    <input type="hidden" name="_html_form" value="1">

                    <?php if ($patternsEnabled === null): ?>
                        <p class="text-muted">Нет данных — нажмите <strong>Перечитать</strong>.</p>
                    <?php else: ?>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Режим паттернов</label>
                            <select class="form-select form-select-sm" name="patterns_mode">
                                <option value="any" <?= $patternsMode === 'any' ? 'selected' : '' ?>>any — срабатывает любой паттерн</option>
                                <option value="all" <?= $patternsMode === 'all' ? 'selected' : '' ?>>all — требуются все паттерны</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">Активные паттерны</label>
                            <div class="d-flex flex-wrap gap-2">
                            <?php
                            $enabledSet = is_array($patternsEnabled) ? array_flip($patternsEnabled) : [];
                            foreach ($knownPatterns as $pid => $plabel):
                            ?>
                                <div class="form-check me-2">
                                    <input class="form-check-input" type="checkbox"
                                           name="patterns_enabled[]"
                                           id="pat2_<?= htmlspecialchars($pid) ?>"
                                           value="<?= htmlspecialchars($pid) ?>"
                                           <?= isset($enabledSet[$pid]) ? 'checked' : '' ?>>
                                    <label class="form-check-label small" for="pat2_<?= htmlspecialchars($pid) ?>"><?= htmlspecialchars($plabel) ?></label>
                                </div>
                            <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-sm btn-primary">
                                <i class="bi bi-floppy me-1"></i>Сохранить
                            </button>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <!-- All known patterns reference table -->
    <div class="col-12 col-md-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-list-check me-2 text-muted"></i>Все известные паттерны</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead><tr><th>ID паттерна</th><th>Название</th><th>Активен</th></tr></thead>
                    <tbody>
                    <?php
                    $enabledSet = is_array($patternsEnabled) ? array_flip($patternsEnabled) : [];
                    foreach ($knownPatterns as $pid => $plabel):
                        $active = isset($enabledSet[$pid]);
                    ?>
                    <tr>
                        <td class="font-monospace small"><?= htmlspecialchars($pid) ?></td>
                        <td><?= htmlspecialchars($plabel) ?></td>
                        <td>
                            <?php if ($active): ?>
                                <span class="badge badge-ok"><i class="bi bi-check"></i> активен</span>
                            <?php else: ?>
                                <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Source info -->
    <div class="col-12">
        <div class="card">
            <div class="card-header"><i class="bi bi-info-circle me-2 text-muted"></i>Источник</div>
            <div class="card-body small text-muted">
                Выбор паттернов настраивается в <code>modules/system/smart_brain/runtime/user_config.json</code>
                (patterns.enabled + patterns.mode) и отражается в <code>runtime/effective_config.json</code>.
                Мастер-конфиг <code>config_operational_master.json</code> имеет приоритет над этим файлом при следующем запуске Smart Brain.
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_layout.php';
