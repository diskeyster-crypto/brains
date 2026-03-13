<?php
/**
 * Parser Manager - Compact Informative View
 * 
 * List and manage parsers with detailed statistics.
 */

use Core\System\System;

// Variables from controller:
// $parsers, $flash

// Protected sections that should be shown as read-only
$protectedSections = ['sources', 'output', 'ui', 'systempaths', 'paths', 'internal', 'profiles'];

// Helper functions
function formatMetricLabel(string $key): string {
    $labels = [
        'active_symbols' => 'Символов',
        'processed' => 'Обработано',
        'candidates' => 'Кандидатов',
        'blocklist' => 'Блоклист',
        'watchlist' => 'Вотчлист',
        'whitelist' => 'Вайтлист',
        'blacklist' => 'Блэклист',
        'signals' => 'Сигналов',
        'delisted_count' => 'Делистинг',
        'history_missing' => 'Нет истории',
        'rejected_below_min_abs' => 'Отклонено (min)',
        'processed_categories' => 'Категорий',
        'profile' => 'Профиль',
        'errors_count' => 'Ошибок',
    ];
    return $labels[$key] ?? ucfirst(str_replace('_', ' ', $key));
}

function formatMetricValue($value): string {
    if (is_bool($value)) return $value ? 'Да' : 'Нет';
    if (is_numeric($value)) return number_format((float)$value, 0, '.', ' ');
    return (string)$value;
}

function getStatusColor(array $parser): string {
    if (!$parser['enabled']) return '#6c757d'; // gray
    if ($parser['status'] === 'ok') return '#28a745'; // green
    if (($parser['errors_count'] ?? 0) > 0) return '#dc3545'; // red
    return '#ffc107'; // yellow
}

function getStatusIcon(array $parser): string {
    if (!$parser['enabled']) return 'bi-pause-circle';
    if ($parser['status'] === 'ok') return 'bi-check-circle-fill';
    if (($parser['errors_count'] ?? 0) > 0) return 'bi-exclamation-circle-fill';
    return 'bi-question-circle';
}

$activeCount = count(array_filter($parsers, fn($p) => $p['enabled']));
$errorCount = count(array_filter($parsers, fn($p) => ($p['errors_count'] ?? 0) > 0));
$okCount = count(array_filter($parsers, fn($p) => $p['status'] === 'ok' && $p['enabled']));
?>

<style>
.parser-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    gap: 12px;
    margin-top: 15px;
}
.parser-card {
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 12px;
    transition: box-shadow 0.2s;
}
.parser-card:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
.parser-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 8px;
    border-bottom: 1px solid #f0f0f0;
    padding-bottom: 8px;
}
.parser-title {
    font-weight: 600;
    font-size: 14px;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 6px;
}
.parser-title .bi {
    font-size: 16px;
}
.parser-desc {
    font-size: 11px;
    color: #6c757d;
    margin-top: 2px;
}
.parser-badges {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
}
.parser-badges .badge {
    font-size: 10px;
    padding: 2px 6px;
}
.parser-metrics {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 6px;
    margin: 8px 0;
}
.metric-item {
    background: #f8f9fa;
    padding: 6px 8px;
    border-radius: 4px;
    text-align: center;
}
.metric-value {
    font-weight: 600;
    font-size: 14px;
    color: #212529;
}
.metric-label {
    font-size: 10px;
    color: #6c757d;
    text-transform: uppercase;
}
.parser-timing {
    display: flex;
    justify-content: space-between;
    font-size: 11px;
    color: #6c757d;
    padding: 6px 0;
    border-top: 1px solid #f0f0f0;
    margin-top: 6px;
}
.parser-actions {
    display: flex;
    gap: 6px;
    margin-top: 8px;
}
.parser-actions .btn {
    flex: 1;
    font-size: 11px;
    padding: 4px 8px;
}
.status-indicator {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
    margin-right: 4px;
}
.summary-bar {
    display: flex;
    gap: 20px;
    padding: 10px 15px;
    background: #f8f9fa;
    border-radius: 8px;
    margin-bottom: 15px;
}
.summary-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
}
.summary-item .count {
    font-weight: 600;
    font-size: 18px;
}
.summary-item.ok .count { color: #28a745; }
.summary-item.error .count { color: #dc3545; }
.summary-item.inactive .count { color: #6c757d; }
</style>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible" style="padding: 8px 12px; font-size: 13px;">
    <?= htmlspecialchars($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" style="padding: 10px;"></button>
</div>
<?php endif; ?>

<!-- Summary Bar -->
<div class="summary-bar">
    <div class="summary-item">
        <span class="count"><?= count($parsers) ?></span>
        <span>Всего</span>
    </div>
    <div class="summary-item ok">
        <i class="bi bi-check-circle-fill" style="color: #28a745;"></i>
        <span class="count"><?= $okCount ?></span>
        <span>OK</span>
    </div>
    <div class="summary-item error">
        <i class="bi bi-exclamation-circle-fill" style="color: #dc3545;"></i>
        <span class="count"><?= $errorCount ?></span>
        <span>Ошибки</span>
    </div>
    <div class="summary-item inactive">
        <i class="bi bi-pause-circle" style="color: #6c757d;"></i>
        <span class="count"><?= count($parsers) - $activeCount ?></span>
        <span>Выкл.</span>
    </div>
    <div style="margin-left: auto;">
        <button class="btn btn-primary btn-sm" onclick="runAllParsers()" title="Запустить все активные парсеры">
            <i class="bi bi-play-fill"></i> Запустить все
        </button>
    </div>
</div>

<?php if (empty($parsers)): ?>
    <div class="alert alert-warning">
        Парсеры не найдены. Создайте парсер в <code>modules/parser/</code> с файлом <code>cron.php</code>
    </div>
<?php else: ?>
    <!-- Parser Cards Grid -->
    <div class="parser-grid">
        <?php foreach ($parsers as $parser): 
            $statusColor = getStatusColor($parser);
            $statusIcon = getStatusIcon($parser);
            $metrics = $parser['metrics'] ?? [];
        ?>
        <div class="parser-card" data-parser="<?= htmlspecialchars($parser['name']) ?>">
            <div class="parser-header">
                <div>
                    <div class="parser-title">
                        <span class="status-indicator" style="background: <?= $statusColor ?>;"></span>
                        <i class="bi <?= $statusIcon ?>" style="color: <?= $statusColor ?>;"></i>
                        <?= htmlspecialchars($parser['title']) ?>
                    </div>
                    <?php if ($parser['description']): ?>
                        <div class="parser-desc"><?= htmlspecialchars($parser['description']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="parser-badges">
                    <?php if ($parser['enabled']): ?>
                        <span class="badge badge-success">ON</span>
                    <?php else: ?>
                        <span class="badge badge-secondary">OFF</span>
                    <?php endif; ?>
                    <span class="badge badge-info"><?= htmlspecialchars($parser['cron_interval']) ?></span>
                </div>
            </div>
            
            <!-- Key Metrics -->
            <?php if (!empty($metrics)): ?>
            <div class="parser-metrics">
                <?php 
                $displayMetrics = array_slice($metrics, 0, 6);
                foreach ($displayMetrics as $key => $value): 
                ?>
                <div class="metric-item">
                    <div class="metric-value"><?= formatMetricValue($value) ?></div>
                    <div class="metric-label"><?= formatMetricLabel($key) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <!-- Timing Info -->
            <div class="parser-timing">
                <span>
                    <i class="bi bi-clock"></i>
                    <?php if ($parser['last_run']): ?>
                        <?= date('H:i:s d.m', strtotime($parser['last_run'])) ?>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </span>
                <span>
                    <i class="bi bi-stopwatch"></i>
                    <?= $parser['duration_ms'] ? $parser['duration_ms'] . 'ms' : '—' ?>
                </span>
                <?php if (($parser['errors_count'] ?? 0) > 0): ?>
                <span style="color: #dc3545;">
                    <i class="bi bi-bug"></i> <?= $parser['errors_count'] ?> ошибок
                </span>
                <?php endif; ?>
            </div>
            
            <!-- Actions -->
            <div class="parser-actions">
                <button class="btn btn-primary btn-sm" onclick="runParser('<?= htmlspecialchars($parser['name']) ?>')">
                    <i class="bi bi-play-fill"></i> Запуск
                </button>
                <?php if ($parser['has_config']): ?>
                <button class="btn btn-outline-secondary btn-sm" onclick="showConfig('<?= htmlspecialchars($parser['name']) ?>')">
                    <i class="bi bi-gear"></i>
                </button>
                <?php endif; ?>
                <button class="btn btn-outline-warning btn-sm" onclick="clearStorage('<?= htmlspecialchars($parser['name']) ?>')" title="Очистить storage">
                    <i class="bi bi-trash"></i>
                </button>
                <button class="btn btn-outline-info btn-sm" onclick="showDetails('<?= htmlspecialchars($parser['name']) ?>')" title="Подробности">
                    <i class="bi bi-info-circle"></i>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Config Modal -->
<div id="configModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
    <div style="background: white; margin: 3% auto; padding: 15px; width: 90%; max-width: 900px; max-height: 90vh; overflow: auto; border-radius: 8px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <h4 id="configModalTitle" style="margin: 0;">Конфигурация</h4>
            <button onclick="closeConfigModal()" style="border: none; background: none; font-size: 20px; cursor: pointer;">&times;</button>
        </div>
        <div id="configContent" style="margin-bottom: 10px;">
            <pre id="configEditor" style="background: #f5f5f5; padding: 12px; border-radius: 4px; max-height: 50vh; overflow: auto; font-size: 12px;"></pre>
        </div>
        <div class="text-muted" style="margin-bottom: 10px; font-size: 11px;">
            <i class="bi bi-lock"></i> Защищённые секции (sources, output, ui) не изменяются при сохранении.
        </div>
        <div style="text-align: right;">
            <button onclick="closeConfigModal()" class="btn btn-secondary btn-sm">Закрыть</button>
            <button onclick="saveConfig()" class="btn btn-success btn-sm">Сохранить</button>
        </div>
    </div>
</div>

<!-- Details Modal -->
<div id="detailsModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
    <div style="background: white; margin: 3% auto; padding: 15px; width: 90%; max-width: 700px; max-height: 90vh; overflow: auto; border-radius: 8px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <h4 id="detailsModalTitle" style="margin: 0;">Подробности</h4>
            <button onclick="closeDetailsModal()" style="border: none; background: none; font-size: 20px; cursor: pointer;">&times;</button>
        </div>
        <div id="detailsContent"></div>
    </div>
</div>

<!-- Toast notifications -->
<div id="toast" style="display: none; position: fixed; bottom: 20px; right: 20px; padding: 10px 20px; border-radius: 4px; color: white; z-index: 9999; font-size: 13px;"></div>

<script>
const protectedSections = <?= json_encode($protectedSections) ?>;
const parsersData = <?= json_encode($parsers) ?>;
let currentParser = null;
let currentConfig = null;

function showToast(message, type = 'success') {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.style.display = 'block';
    toast.style.background = type === 'success' ? '#28a745' : '#dc3545';
    setTimeout(() => { toast.style.display = 'none'; }, 3000);
}

function runParser(name) {
    const card = document.querySelector(`[data-parser="${name}"]`);
    const btn = card.querySelector('.btn-primary');
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i>';
    
    fetch('<?= System::web('admin/parser/api/run') ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ module: name })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast('✓ ' + name + ': ' + (data.duration_ms || 0) + 'ms', 'success');
        } else {
            showToast('✗ ' + name + ': ' + (data.message || 'Ошибка'), 'error');
        }
        setTimeout(() => location.reload(), 1000);
    })
    .catch(e => {
        showToast('Ошибка сети: ' + e.message, 'error');
        btn.disabled = false;
        btn.innerHTML = origHtml;
    });
}

function runAllParsers() {
    const activeParsers = parsersData.filter(p => p.enabled);
    if (activeParsers.length === 0) {
        showToast('Нет активных парсеров', 'error');
        return;
    }
    if (!confirm('Запустить все ' + activeParsers.length + ' активных парсеров?')) return;
    
    let completed = 0;
    activeParsers.forEach((p, i) => {
        setTimeout(() => {
            runParser(p.name);
            completed++;
        }, i * 500);
    });
}

function clearStorage(name) {
    if (!confirm('Очистить storage для ' + name + '?')) return;
    
    fetch('<?= System::web('admin/parser/api/clear') ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ module: name })
    })
    .then(r => r.json())
    .then(data => {
        showToast(data.success ? 'Storage очищен' : 'Ошибка', data.success ? 'success' : 'error');
    });
}

function showConfig(name) {
    currentParser = name;
    document.getElementById('configModalTitle').textContent = 'Конфиг: ' + name;
    document.getElementById('configModal').style.display = 'block';
    document.getElementById('configEditor').textContent = 'Загрузка...';
    
    fetch('<?= System::web('admin/parser/api/config') ?>?module=' + encodeURIComponent(name))
    .then(r => r.json())
    .then(data => {
        if (data.success && data.config) {
            currentConfig = data.config;
            document.getElementById('configEditor').textContent = JSON.stringify(data.config, null, 2);
        } else {
            document.getElementById('configEditor').textContent = 'Ошибка: ' + (data.error || 'Не удалось загрузить');
        }
    });
}

function closeConfigModal() {
    document.getElementById('configModal').style.display = 'none';
    currentParser = null;
    currentConfig = null;
}

function saveConfig() {
    if (!currentParser || !currentConfig) return;
    
    try {
        let newConfig = JSON.parse(document.getElementById('configEditor').textContent);
        protectedSections.forEach(key => delete newConfig[key]);
        
        fetch('<?= System::web('admin/parser/api/config/save') ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ module: currentParser, config: newConfig })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast('Сохранено', 'success');
                closeConfigModal();
            } else {
                showToast('Ошибка: ' + (data.error || ''), 'error');
            }
        });
    } catch (e) {
        showToast('Ошибка JSON: ' + e.message, 'error');
    }
}

function showDetails(name) {
    const parser = parsersData.find(p => p.name === name);
    if (!parser) return;
    
    document.getElementById('detailsModalTitle').textContent = parser.title;
    
    let html = '<table class="table table-sm" style="font-size: 12px;">';
    html += '<tr><td><strong>Имя</strong></td><td>' + parser.name + '</td></tr>';
    html += '<tr><td><strong>Статус</strong></td><td>' + (parser.enabled ? '✓ Активен' : '✗ Выключен') + '</td></tr>';
    html += '<tr><td><strong>Интервал</strong></td><td>' + parser.cron_interval + '</td></tr>';
    html += '<tr><td><strong>Последний запуск</strong></td><td>' + (parser.last_run || '—') + '</td></tr>';
    html += '<tr><td><strong>Время выполнения</strong></td><td>' + (parser.duration_ms ? parser.duration_ms + ' ms' : '—') + '</td></tr>';
    html += '<tr><td><strong>Ошибок</strong></td><td>' + (parser.errors_count || 0) + '</td></tr>';
    
    if (parser.metrics && Object.keys(parser.metrics).length > 0) {
        html += '<tr><td colspan="2"><strong>Метрики:</strong></td></tr>';
        for (const [key, value] of Object.entries(parser.metrics)) {
            html += '<tr><td style="padding-left: 20px;">' + key + '</td><td>' + value + '</td></tr>';
        }
    }
    
    if (parser.last_run_data && Object.keys(parser.last_run_data).length > 0) {
        html += '<tr><td colspan="2"><strong>last_run.json:</strong></td></tr>';
        html += '<tr><td colspan="2"><pre style="font-size: 11px; max-height: 200px; overflow: auto;">' + JSON.stringify(parser.last_run_data, null, 2) + '</pre></td></tr>';
    }
    
    html += '</table>';
    
    document.getElementById('detailsContent').innerHTML = html;
    document.getElementById('detailsModal').style.display = 'block';
}

function closeDetailsModal() {
    document.getElementById('detailsModal').style.display = 'none';
}

// Close modals on click outside
document.getElementById('configModal').addEventListener('click', function(e) { if (e.target === this) closeConfigModal(); });
document.getElementById('detailsModal').addEventListener('click', function(e) { if (e.target === this) closeDetailsModal(); });
</script>
