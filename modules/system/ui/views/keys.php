<?php
/**
 * System Control Center - KeyCenter Manager
 * Full key management UI: view, add, edit, delete, test
 * 
 * ARCHITECTURE: UI only - all data from KeyCenter::instance()
 * NO hardcoded values, NO debug dumps, NO PHP code examples
 */

use Core\System\System;

// Variables: $services, $accounts, $diagnostic, $flash

// Count totals
$totalServices = count($services);
$totalAccounts = 0;
foreach ($accounts as $service => $accs) {
    $totalAccounts += count($accs);
}
$encryptionOk = $diagnostic['encryption_key_exists'] ?? false;
$storageOk = $diagnostic['storage_exists'] ?? false;

// CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Available services for dropdown
$availableServices = ['bybit', 'binance', 'github', 'telegram', 'custom'];
?>

<!-- Flash Message -->
<?php if (!empty($flash)): ?>
<div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" style="margin-bottom: 20px;">
    <?= htmlspecialchars($flash['message']) ?>
</div>
<?php endif; ?>

<!-- Stat Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Сервисы</div>
        <div class="stat-value"><?= $totalServices ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Аккаунты</div>
        <div class="stat-value"><?= $totalAccounts ?></div>
    </div>
    <div class="stat-card <?= $encryptionOk ? 'stat-success' : 'stat-danger' ?>">
        <div class="stat-label">Шифрование</div>
        <div class="stat-value"><?= $encryptionOk ? 'AES-256' : 'Нет' ?></div>
    </div>
    <div class="stat-card <?= $storageOk ? 'stat-success' : 'stat-warning' ?>">
        <div class="stat-label">Хранилище</div>
        <div class="stat-value"><?= $storageOk ? 'OK' : 'Нет' ?></div>
    </div>
</div>

<!-- Add Key Button -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-body">
        <button type="button" class="btn btn-success" onclick="toggleAddForm()">
            <i class="bi bi-plus-circle"></i> Добавить ключ
        </button>
    </div>
</div>

<!-- Add Key Form (hidden by default) -->
<div id="addKeyForm" class="card" style="display: none; margin-bottom: 20px;">
    <div class="card-header">
        <h3 id="formTitle"><i class="bi bi-key-fill"></i> Добавить ключи</h3>
    </div>
    <div class="card-body">
        <form method="POST" action="<?= System::web('admin/system/keys/add') ?>" id="keyForm" onsubmit="return validateForm()">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="edit_mode" id="editMode" value="0">
            
            <!-- Bybit/default warning -->
            <div id="bybitWarning" class="alert alert-danger" style="display: none; margin-bottom: 15px;">
                <i class="bi bi-exclamation-triangle"></i> 
                <strong>Bybit requires explicit account id</strong><br>
                Используйте конкретный идентификатор аккаунта (например: trading_bot, main, copytrade), а не "default".
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: 600;">Сервис</label>
                    <select name="service" id="serviceSelect" class="form-control" required onchange="checkBybitDefault()">
                        <option value="">-- Выберите --</option>
                        <?php foreach ($availableServices as $svc): ?>
                        <option value="<?= $svc ?>"><?= ucfirst($svc) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: 600;">Account ID</label>
                    <input type="text" name="account_id" id="accountIdInput" class="form-control" placeholder="trading_bot" value="" onchange="checkBybitDefault()" onkeyup="checkBybitDefault()">
                    <small class="text-muted">Идентификатор аккаунта (main, trading_bot, copytrade)</small>
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: 600;">API Key</label>
                    <input type="text" name="api_key" id="apiKeyInput" class="form-control" placeholder="Введите API ключ" required>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: 600;">API Secret</label>
                    <input type="password" name="api_secret" id="apiSecretInput" class="form-control" placeholder="Введите Secret ключ" required>
                </div>
            </div>
            
            <div>
                <button type="submit" class="btn btn-primary" id="submitBtn">
                    <i class="bi bi-save"></i> Сохранить
                </button>
                <button type="button" class="btn btn-secondary" onclick="resetForm()">Отмена</button>
            </div>
        </form>
    </div>
</div>

<!-- Keys Table -->
<div class="card">
    <div class="card-header">
        <h3><i class="bi bi-key"></i> Зарегистрированные ключи</h3>
    </div>
    <div class="card-body">
        <?php if (empty($services)): ?>
        <p class="text-muted" style="text-align: center; padding: 30px;">
            Нет зарегистрированных API ключей.<br>
            Нажмите "Добавить ключ" для создания первого ключа.
        </p>
        <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Сервис</th>
                    <th>Account ID</th>
                    <th>Ключи</th>
                    <th>Статус</th>
                    <th>Создан</th>
                    <th>Обновлён</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($accounts as $service => $serviceAccounts): ?>
                    <?php foreach ($serviceAccounts as $account => $info): 
                        $hasKeys = !empty($info['keys']);
                        $keysList = implode(', ', $info['keys'] ?? []);
                        // Mask keys display
                        $maskedKeys = preg_replace('/api_key|api_secret/', '****', $keysList);
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars(ucfirst($service)) ?></strong></td>
                        <td><code><?= htmlspecialchars($account) ?></code></td>
                        <td>
                            <?php foreach ($info['keys'] ?? [] as $keyName): ?>
                            <span class="badge badge-info" style="margin-right: 3px;"><?= htmlspecialchars($keyName) ?></span>
                            <?php endforeach; ?>
                        </td>
                        <td>
                            <?php if (!$hasKeys): ?>
                                <span class="badge badge-secondary">Пусто</span>
                            <?php else: ?>
                                <span class="badge badge-warning" title="Нажмите 'Тест' для проверки соединения">Не тестирован</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($info['created_at'] ? date('d.m.Y H:i', strtotime($info['created_at'])) : '-') ?></td>
                        <td><?= htmlspecialchars($info['updated_at'] ? date('d.m.Y H:i', strtotime($info['updated_at'])) : '-') ?></td>
                        <td>
                            <!-- Edit button -->
                            <button type="button" class="btn btn-sm btn-warning" 
                                    onclick="editKey('<?= htmlspecialchars($service) ?>', '<?= htmlspecialchars($account) ?>')"
                                    title="Редактировать">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <!-- Test button -->
                            <button type="button" class="btn btn-sm btn-info" 
                                    onclick="testKey('<?= htmlspecialchars($service) ?>', '<?= htmlspecialchars($account) ?>')"
                                    title="Тест соединения"
                                    <?php if ($service === 'bybit' && $account === 'default'): ?>disabled<?php endif; ?>>
                                <i class="bi bi-lightning"></i>
                            </button>
                            <!-- Delete button -->
                            <form method="POST" action="<?= System::web('admin/system/keys/delete') ?>" 
                                  style="display: inline-block;"
                                  onsubmit="return confirm('Удалить ключи для <?= htmlspecialchars($service) ?>/<?= htmlspecialchars($account) ?>?')">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                <input type="hidden" name="service" value="<?= htmlspecialchars($service) ?>">
                                <input type="hidden" name="account_id" value="<?= htmlspecialchars($account) ?>">
                                <button type="submit" class="btn btn-sm btn-danger" title="Удалить">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- Back Button -->
<div class="card" style="margin-top: 20px;">
    <div class="card-body">
        <a href="<?= System::web('admin/system') ?>" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> Назад к обзору
        </a>
    </div>
</div>

<!-- Test Result Modal (Enhanced for Bybit) -->
<div id="testModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 1000; justify-content: center; align-items: center;">
    <div style="background: var(--ui-card, #1c2128); padding: 25px; border-radius: 12px; max-width: 500px; width: 90%; max-height: 80vh; overflow-y: auto;">
        <h4 id="testModalTitle" style="margin-bottom: 20px; color: var(--ui-text, #e6edf3);">Тест соединения</h4>
        
        <!-- Status Badge -->
        <div id="testStatusBadge" style="margin-bottom: 15px;"></div>
        
        <!-- Detailed Results (for Bybit) -->
        <div id="testDetailedResults" style="display: none;">
            <table class="table" style="margin-bottom: 15px;">
                <tbody>
                    <tr>
                        <td style="width: 120px;"><strong>Статус</strong></td>
                        <td id="testResultStatus">-</td>
                    </tr>
                    <tr>
                        <td><strong>Режим</strong></td>
                        <td id="testResultMode">-</td>
                    </tr>
                    <tr>
                        <td><strong>Сервер</strong></td>
                        <td id="testResultServer">-</td>
                    </tr>
                    <tr>
                        <td><strong>Задержка</strong></td>
                        <td id="testResultLatency">-</td>
                    </tr>
                    <tr>
                        <td><strong>API Public</strong></td>
                        <td id="testResultPublic">-</td>
                    </tr>
                    <tr>
                        <td><strong>Positions</strong></td>
                        <td id="testResultPositions">-</td>
                    </tr>
                    <tr>
                        <td><strong>Wallet</strong></td>
                        <td id="testResultWallet">-</td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <!-- Simple Message (for non-Bybit) -->
        <p id="testModalMessage" style="margin-bottom: 15px; color: var(--ui-text-muted, #8b949e);"></p>
        
        <button type="button" class="btn btn-secondary" onclick="closeTestModal()">Закрыть</button>
    </div>
</div>

<script>
// Check for bybit/default restriction
function checkBybitDefault() {
    const service = document.getElementById('serviceSelect').value;
    const account = document.getElementById('accountIdInput').value.trim() || 'default';
    const warning = document.getElementById('bybitWarning');
    const submitBtn = document.getElementById('submitBtn');
    
    if (service === 'bybit' && account === 'default') {
        warning.style.display = 'block';
        submitBtn.disabled = true;
    } else {
        warning.style.display = 'none';
        submitBtn.disabled = false;
    }
}

// Validate form before submit
function validateForm() {
    const service = document.getElementById('serviceSelect').value;
    const account = document.getElementById('accountIdInput').value.trim() || 'default';
    
    if (service === 'bybit' && account === 'default') {
        alert('Bybit requires explicit account id (e.g. trading_bot, main, copytrade). Cannot use "default".');
        return false;
    }
    return true;
}

// Show add form
function toggleAddForm() {
    const form = document.getElementById('addKeyForm');
    form.style.display = form.style.display === 'none' ? 'block' : 'none';
}

// Reset form to add mode
function resetForm() {
    document.getElementById('addKeyForm').style.display = 'none';
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-key-fill"></i> Добавить ключи';
    document.getElementById('editMode').value = '0';
    document.getElementById('serviceSelect').value = '';
    document.getElementById('serviceSelect').disabled = false;
    document.getElementById('accountIdInput').value = '';
    document.getElementById('accountIdInput').disabled = false;
    document.getElementById('apiKeyInput').value = '';
    document.getElementById('apiSecretInput').value = '';
    document.getElementById('bybitWarning').style.display = 'none';
    document.getElementById('submitBtn').disabled = false;
}

// Edit existing key
function editKey(service, account) {
    document.getElementById('addKeyForm').style.display = 'block';
    document.getElementById('formTitle').innerHTML = '<i class="bi bi-pencil"></i> Редактировать ключи: ' + service + '/' + account;
    document.getElementById('editMode').value = '1';
    document.getElementById('serviceSelect').value = service;
    document.getElementById('serviceSelect').disabled = true;
    document.getElementById('accountIdInput').value = account;
    document.getElementById('accountIdInput').disabled = true;
    document.getElementById('apiKeyInput').value = '';
    document.getElementById('apiKeyInput').placeholder = 'Введите новый API ключ';
    document.getElementById('apiSecretInput').value = '';
    document.getElementById('apiSecretInput').placeholder = 'Введите новый Secret ключ';
    
    // Block bybit/default editing
    if (service === 'bybit' && account === 'default') {
        document.getElementById('bybitWarning').style.display = 'block';
        document.getElementById('submitBtn').disabled = true;
    }
    
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// Test key connection
function testKey(service, account) {
    // Block bybit/default test
    if (service === 'bybit' && account === 'default') {
        alert('Bybit requires explicit account id. Cannot test "default" account.');
        return;
    }
    
    const modal = document.getElementById('testModal');
    const title = document.getElementById('testModalTitle');
    const statusBadge = document.getElementById('testStatusBadge');
    const message = document.getElementById('testModalMessage');
    const detailedResults = document.getElementById('testDetailedResults');
    
    modal.style.display = 'flex';
    title.textContent = 'Тест: ' + service + '/' + account;
    statusBadge.innerHTML = '<span class="badge badge-info">Проверяем...</span>';
    message.textContent = 'Ожидание ответа от сервера...';
    detailedResults.style.display = 'none';
    
    const formData = new FormData();
    formData.append('service', service);
    formData.append('account_id', account);
    
    fetch('<?= System::web('admin/system/keys/test') ?>', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            statusBadge.innerHTML = '<span class="badge badge-success" style="font-size: 16px; padding: 8px 16px;">✓ OK</span>';
            
            // Show detailed results for Bybit
            if (data.diagnostic && service === 'bybit') {
                detailedResults.style.display = 'block';
                message.style.display = 'none';
                
                const diag = data.diagnostic;
                document.getElementById('testResultStatus').innerHTML = diag.summary?.server_time_ok ? 
                    '<span class="badge badge-success">Online</span>' : '<span class="badge badge-danger">Offline</span>';
                document.getElementById('testResultMode').textContent = 'Mainnet';
                document.getElementById('testResultServer').textContent = diag.base_url || '-';
                document.getElementById('testResultLatency').textContent = diag.tests?.server_time?.server_time ? 
                    (Date.now()/1000 - parseInt(diag.tests.server_time.server_time)).toFixed(0) + ' ms' : '-';
                document.getElementById('testResultPublic').innerHTML = diag.tests?.public_api?.success ?
                    '<span class="badge badge-success">OK</span> ' + (diag.tests.public_api.btc_price ? 'BTC: $' + diag.tests.public_api.btc_price : '') :
                    '<span class="badge badge-danger">Fail</span>';
                document.getElementById('testResultPositions').innerHTML = diag.tests?.positions?.success ?
                    '<span class="badge badge-success">OK</span>' :
                    (diag.tests?.positions?.skipped ? '<span class="badge badge-warning">Skipped</span>' : '<span class="badge badge-danger">Fail</span>');
                document.getElementById('testResultWallet').innerHTML = diag.tests?.wallet?.success ?
                    '<span class="badge badge-success">OK</span>' :
                    (diag.tests?.wallet?.skipped ? '<span class="badge badge-warning">Skipped</span>' : '<span class="badge badge-danger">Fail</span>');
            } else {
                detailedResults.style.display = 'none';
                message.style.display = 'block';
                message.innerHTML = '<span style="color: #3fb950;">✓ ' + data.message + '</span>';
            }
        } else {
            statusBadge.innerHTML = '<span class="badge badge-danger" style="font-size: 16px; padding: 8px 16px;">✕ ERROR</span>';
            detailedResults.style.display = 'none';
            message.style.display = 'block';
            message.innerHTML = '<span style="color: #f85149;">Ошибка: ' + data.message + '</span>';
        }
    })
    .catch(error => {
        statusBadge.innerHTML = '<span class="badge badge-danger" style="font-size: 16px; padding: 8px 16px;">✕ ERROR</span>';
        detailedResults.style.display = 'none';
        message.style.display = 'block';
        message.innerHTML = '<span style="color: #f85149;">Сетевая ошибка: ' + error.message + '</span>';
    });
}

function closeTestModal() {
    document.getElementById('testModal').style.display = 'none';
}
</script>
