<?php

declare(strict_types=1);

/**
 * Bot Module — Admin Controller
 *
 * Operator management surface for the Bot module.
 * Allows the operator to:
 *   - see discovered strategies and their status
 *   - enable/disable a strategy
 *   - set per-strategy budget, leverage, entry_mode
 *   - view last-run and queue health
 *
 * Does NOT expose deep strategy internals (pattern thresholds, corridor
 * config, wave internals, etc.). Those stay inside each strategy module.
 *
 * Routes handled (registered in public/index.php):
 *   GET  /admin/bot              → dashboard
 *   GET  /admin/bot/api/status   → JSON status snapshot
 *   POST /admin/bot/api/overrides/save → save operator overrides
 */

namespace Modules\Bot\Admin;

use Core\System\System;
use Core\System\SystemPaths;
use Core\Auth\Auth;

final class BotAdminController
{
    private static ?self $instance = null;
    private string $moduleDir;

    private function __construct()
    {
        $this->moduleDir = rtrim(
            SystemPaths::instance()->get('bot.bot'),
            '/'
        );
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // =========================================================================
    // Pages
    // =========================================================================

    public function dashboard(): string
    {
        $registry  = $this->readJson('storage/strategy_registry.json', []);
        $overrides = $this->readJson('storage/operator_overrides.json', []);
        $lastRun   = $this->readJson('storage/last_run.json', []);
        $stats     = $this->readJson('storage/stats.json', []);

        $botUrl    = System::web('admin/bot');
        $apiBase   = System::web('admin/bot/api');

        $lastRunJson  = json_encode($lastRun,  JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $statsJson    = json_encode($stats,    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // Build per-strategy rows
        $stratRows = '';
        if (empty($registry)) {
            $stratRows = '<tr><td colspan="8" class="text-center text-muted">Стратегии не обнаружены. Бот ещё не запускался (tick не выполнен).</td></tr>';
        } else {
            foreach ($registry as $rec) {
                $stratId   = htmlspecialchars($rec['strategy_id'] ?? '', ENT_QUOTES, 'UTF-8');
                $title     = htmlspecialchars($rec['title']       ?? $stratId, ENT_QUOTES, 'UTF-8');
                $status    = htmlspecialchars($rec['status']      ?? 'discovered', ENT_QUOTES, 'UTF-8');
                $handoff   = $rec['handoff_queue_path'] ? 'Да' : 'Нет';
                $supLong   = $rec['supports_long']  ? '✓' : '—';
                $supShort  = $rec['supports_short'] ? '✓' : '—';

                $op        = (array)($overrides[$rec['strategy_id'] ?? ''] ?? []);
                $opEnabled = $op['enabled']         ?? true;
                $opBudget  = $op['bot_budget']      ?? 0;
                $opLev     = $op['bot_leverage']    ?? 0;
                $opMode    = $op['entry_mode']      ?? '';
                $opMax     = $op['max_active_positions'] ?? 0;

                $enabledBadge = $opEnabled
                    ? '<span class="badge badge-success">Включено</span>'
                    : '<span class="badge badge-danger">Выключено</span>';

                $statusBadge = $status === 'bot_ready'
                    ? '<span class="badge badge-success">bot_ready</span>'
                    : '<span class="badge badge-secondary">' . $status . '</span>';

                $dataId     = htmlspecialchars($rec['strategy_id'] ?? '', ENT_QUOTES, 'UTF-8');
                $dataTitle  = $title;
                $dataEnabled= $opEnabled ? 'true' : 'false';
                $dataBudget = (float)$opBudget;
                $dataLev    = (int)$opLev;
                $dataMode   = htmlspecialchars($opMode, ENT_QUOTES, 'UTF-8');
                $dataMax    = (int)$opMax;

                $stratRows .= <<<HTML
<tr>
    <td><strong>{$title}</strong><br><code class="small">{$stratId}</code></td>
    <td>{$statusBadge}</td>
    <td>{$supLong}</td>
    <td>{$supShort}</td>
    <td>{$handoff}</td>
    <td>{$enabledBadge}</td>
    <td>
        <small>
            Бюджет: <code>{$dataBudget}</code><br>
            Плечо: <code>{$dataLev}</code><br>
            Вход: <code>{$dataMode}</code><br>
            Макс.поз: <code>{$dataMax}</code>
        </small>
    </td>
    <td>
        <button class="btn btn-sm btn-primary"
            onclick="botOpenEdit({$dataEnabled},'{$dataId}','{$dataTitle}',{$dataBudget},{$dataLev},'{$dataMode}',{$dataMax})">
            Изменить
        </button>
    </td>
</tr>
HTML;
            }
        }

        // Last-run display
        $tickAt    = htmlspecialchars((string)($lastRun['tick_at']    ?? 'Нет данных'), ENT_QUOTES, 'UTF-8');
        $tickStatus= htmlspecialchars((string)($lastRun['status']     ?? 'never_run'),  ENT_QUOTES, 'UTF-8');
        $botEnabled= $lastRun['bot_enabled'] ?? false ? 'Да' : 'Нет';
        $botMode   = htmlspecialchars((string)($lastRun['bot_mode']   ?? 'passive'), ENT_QUOTES, 'UTF-8');
        $queueTotal= (int)($lastRun['order_queue_total']   ?? 0);
        $ordersCount= (int)($lastRun['active_orders_count']   ?? 0);
        $posCount  = (int)($lastRun['active_positions_count'] ?? 0);

        $saveUrl   = System::web('admin/bot/api/overrides/save');

        return <<<HTML
<div style="max-width:1100px;">

<div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0">Bot — Управление стратегиями</h4>
    <button class="btn btn-sm btn-outline-secondary" onclick="botRefreshStatus()">↺ Обновить статус</button>
</div>

<!-- Runtime summary -->
<div class="card mb-3">
    <div class="card-header">Последний запуск</div>
    <div class="card-body">
        <table class="table table-sm mb-0">
            <tr><th>Статус</th><td><code>{$tickStatus}</code></td>
                <th>Тик</th><td><code>{$tickAt}</code></td></tr>
            <tr><th>Включён</th><td>{$botEnabled}</td>
                <th>Режим</th><td><code>{$botMode}</code></td></tr>
            <tr><th>Очередь (active)</th><td>{$queueTotal}</td>
                <th>Ордеров / Позиций</th><td>{$ordersCount} / {$posCount}</td></tr>
        </table>
    </div>
</div>

<!-- Strategy registry -->
<div class="card mb-3">
    <div class="card-header">Обнаруженные стратегии</div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead>
                <tr>
                    <th>Стратегия</th>
                    <th>Статус</th>
                    <th>Long</th>
                    <th>Short</th>
                    <th>Handoff</th>
                    <th>Включено</th>
                    <th>Параметры</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>{$stratRows}</tbody>
        </table>
    </div>
</div>

<!-- Edit override modal (inline, no extra JS library required) -->
<div id="botEditModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.6);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--card-bg,#1e293b);border:1px solid var(--border-color,#334155);border-radius:8px;padding:24px;min-width:420px;max-width:540px;width:100%;">
        <h5 id="botEditTitle" class="mb-3">Настройки стратегии</h5>
        <form id="botEditForm" method="post" action="{$saveUrl}">
            <input type="hidden" name="strategy_id" id="editStratId">

            <div class="row g-2 mb-2">
                <div class="col-6">
                    <label class="form-label">Включено</label>
                    <select name="enabled" id="editEnabled" class="form-select form-select-sm">
                        <option value="1">Да</option>
                        <option value="0">Нет</option>
                    </select>
                </div>
                <div class="col-6">
                    <label class="form-label">Режим входа</label>
                    <select name="entry_mode" id="editEntryMode" class="form-select form-select-sm">
                        <option value="">— из сигнала —</option>
                        <option value="limit">limit</option>
                        <option value="market">market</option>
                    </select>
                </div>
            </div>

            <div class="row g-2 mb-2">
                <div class="col-4">
                    <label class="form-label">Бюджет (0=сигнал)</label>
                    <input type="number" step="0.01" min="0" name="bot_budget" id="editBudget" class="form-control form-control-sm">
                </div>
                <div class="col-4">
                    <label class="form-label">Плечо (0=сигнал)</label>
                    <input type="number" step="1" min="0" name="bot_leverage" id="editLeverage" class="form-control form-control-sm">
                </div>
                <div class="col-4">
                    <label class="form-label">Макс. позиций (0=∞)</label>
                    <input type="number" step="1" min="0" name="max_active_positions" id="editMaxPos" class="form-control form-control-sm">
                </div>
            </div>

            <div class="d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-primary btn-sm">Сохранить</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="botCloseEdit()">Отмена</button>
            </div>
        </form>
    </div>
</div>

<!-- Stats (collapsible) -->
<details class="mb-3">
    <summary class="btn btn-sm btn-outline-secondary mb-2">Статистика (stats.json)</summary>
    <pre style="background:var(--card-bg,#1e293b);padding:12px;border-radius:4px;font-size:12px;overflow:auto;">{$statsJson}</pre>
</details>

<details>
    <summary class="btn btn-sm btn-outline-secondary mb-2">Последний прогон (last_run.json)</summary>
    <pre style="background:var(--card-bg,#1e293b);padding:12px;border-radius:4px;font-size:12px;overflow:auto;">{$lastRunJson}</pre>
</details>

</div>

<script>
function botOpenEdit(enabled, stratId, title, budget, leverage, entryMode, maxPos) {
    document.getElementById('botEditTitle').textContent = 'Настройки: ' + title;
    document.getElementById('editStratId').value = stratId;
    document.getElementById('editEnabled').value = enabled ? '1' : '0';
    document.getElementById('editBudget').value = budget;
    document.getElementById('editLeverage').value = leverage;
    document.getElementById('editEntryMode').value = entryMode || '';
    document.getElementById('editMaxPos').value = maxPos;
    var m = document.getElementById('botEditModal');
    m.style.display = 'flex';
}
function botCloseEdit() {
    document.getElementById('botEditModal').style.display = 'none';
}
function botRefreshStatus() {
    fetch('{$apiBase}/status')
        .then(r => r.json())
        .then(d => {
            if (d && d.tick_at) {
                alert('Последний тик: ' + d.tick_at + '\nСтатус: ' + (d.status || 'ok'));
            }
        })
        .catch(() => alert('Не удалось получить статус'));
}
document.getElementById('botEditModal').addEventListener('click', function(e){
    if (e.target === this) botCloseEdit();
});
</script>
HTML;
    }

    // =========================================================================
    // API endpoints
    // =========================================================================

    public function apiStatus(): void
    {
        header('Content-Type: application/json');
        $lastRun  = $this->readJson('storage/last_run.json', []);
        $stats    = $this->readJson('storage/stats.json', []);
        $registry = $this->readJson('storage/strategy_registry.json', []);
        echo json_encode([
            'last_run'  => $lastRun,
            'stats'     => $stats,
            'registry'  => $registry,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Save operator overrides for a single strategy.
     *
     * Only the compact set of operator controls is accepted.
     * Deep strategy internals are NOT passed through here.
     */
    public function saveOverrides(): void
    {
        if (!Auth::check()) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
            return;
        }

        $stratId = trim((string)($_POST['strategy_id'] ?? ''));
        if ($stratId === '') {
            $this->flashAndRedirect('error', 'strategy_id не указан', System::web('admin/bot'));
            return;
        }

        // Read and merge only the explicitly defined operator controls
        $overrides = $this->readJson('storage/operator_overrides.json', []);

        $enabled    = (int)($_POST['enabled']             ?? 1);
        $budget     = (float)($_POST['bot_budget']        ?? 0.0);
        $leverage   = (int)($_POST['bot_leverage']        ?? 0);
        $entryMode  = trim((string)($_POST['entry_mode']  ?? ''));
        $maxPos     = (int)($_POST['max_active_positions'] ?? 0);

        // entry_mode: blank means "use signal value"
        if (!in_array($entryMode, ['limit', 'market'], true)) {
            $entryMode = null;
        }

        $prev = (array)($overrides[$stratId] ?? []);
        $overrides[$stratId] = array_merge($prev, [
            'enabled'              => (bool)$enabled,
            'mode'                 => $prev['mode'] ?? 'passive',
            'bot_budget'           => $budget,
            'bot_leverage'         => $leverage,
            'entry_mode'           => $entryMode,
            'stop_preset'          => $prev['stop_preset']  ?? null,
            'exit_preset'          => $prev['exit_preset']  ?? null,
            'max_active_positions' => $maxPos,
        ]);

        $this->writeJson('storage/operator_overrides.json', $overrides);

        $this->flashAndRedirect(
            'success',
            "Настройки стратегии «{$stratId}» сохранены",
            System::web('admin/bot')
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function readJson(string $relPath, mixed $default = null): mixed
    {
        $path = $this->moduleDir . '/' . $relPath;
        if (!file_exists($path)) {
            return $default;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return $default;
        }
        $decoded = json_decode($raw, true);
        return ($decoded !== null) ? $decoded : $default;
    }

    private function writeJson(string $relPath, mixed $data): void
    {
        $path = $this->moduleDir . '/' . $relPath;
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function flashAndRedirect(string $type, string $msg, string $url): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['bot_flash'] = ['type' => $type, 'msg' => $msg];
        header('Location: ' . $url);
        exit;
    }
}
