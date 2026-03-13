<?php
/**
 * Installer Wizard View
 * 
 * Multi-step installation wizard UI
 * 
 * Variables available:
 *   $step - Current step number (1-5)
 *   $error - Error message (if any)
 *   $csrf - CSRF token
 *   $requirements - Requirements array (step 1 only)
 * 
 * @package Modules\Installer
 */

use Core\System\System;

$stepTitles = [
    1 => 'Проверка требований',
    2 => 'Создание директорий',
    3 => 'Администратор',
    4 => 'Bybit API',
    5 => 'Завершение',
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Установка Tredercopis Core</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .installer {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 600px;
            overflow: hidden;
        }
        .installer-header {
            background: #2d3748;
            color: white;
            padding: 24px;
            text-align: center;
        }
        .installer-header h1 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 8px;
        }
        .installer-header p {
            opacity: 0.8;
            font-size: 14px;
        }
        .steps {
            display: flex;
            padding: 0 24px;
            background: #f7fafc;
            border-bottom: 1px solid #e2e8f0;
        }
        .step-indicator {
            flex: 1;
            padding: 16px 8px;
            text-align: center;
            font-size: 12px;
            color: #a0aec0;
            position: relative;
        }
        .step-indicator.active {
            color: #4299e1;
            font-weight: 600;
        }
        .step-indicator.completed {
            color: #48bb78;
        }
        .step-indicator::before {
            content: attr(data-step);
            display: block;
            width: 28px;
            height: 28px;
            line-height: 28px;
            background: #e2e8f0;
            border-radius: 50%;
            margin: 0 auto 8px;
            font-weight: 600;
        }
        .step-indicator.active::before {
            background: #4299e1;
            color: white;
        }
        .step-indicator.completed::before {
            background: #48bb78;
            color: white;
            content: '✓';
        }
        .installer-content {
            padding: 32px;
        }
        .step-title {
            font-size: 20px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 24px;
        }
        .error-message {
            background: #fed7d7;
            color: #c53030;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-size: 14px;
        }
        .requirement {
            display: flex;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        .requirement:last-child { border-bottom: none; }
        .requirement-status {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            margin-right: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
        }
        .requirement-status.passed {
            background: #c6f6d5;
            color: #22543d;
        }
        .requirement-status.failed {
            background: #fed7d7;
            color: #c53030;
        }
        .requirement-name {
            flex: 1;
            font-weight: 500;
            color: #2d3748;
        }
        .requirement-value {
            color: #718096;
            font-size: 14px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            font-weight: 500;
            color: #2d3748;
            margin-bottom: 8px;
        }
        .form-group input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.2s;
        }
        .form-group input:focus {
            outline: none;
            border-color: #4299e1;
        }
        .form-group .hint {
            font-size: 12px;
            color: #718096;
            margin-top: 4px;
        }
        .checkbox-group {
            display: flex;
            align-items: center;
            margin-top: 16px;
        }
        .checkbox-group input {
            width: auto;
            margin-right: 8px;
        }
        .checkbox-group label {
            margin-bottom: 0;
            font-weight: normal;
        }
        .buttons {
            display: flex;
            gap: 12px;
            margin-top: 32px;
        }
        .btn {
            flex: 1;
            padding: 14px 24px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-primary {
            background: #4299e1;
            color: white;
        }
        .btn-primary:hover {
            background: #3182ce;
        }
        .btn-secondary {
            background: #e2e8f0;
            color: #4a5568;
        }
        .btn-secondary:hover {
            background: #cbd5e0;
        }
        .success-icon {
            width: 80px;
            height: 80px;
            background: #c6f6d5;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            color: #22543d;
            margin: 0 auto 24px;
        }
        .info-box {
            background: #ebf8ff;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 24px;
        }
        .info-box p {
            color: #2b6cb0;
            font-size: 14px;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="installer">
        <div class="installer-header">
            <h1>Tredercopis Core</h1>
            <p>Мастер установки системы</p>
        </div>
        
        <div class="steps">
            <?php for ($i = 1; $i <= 5; $i++): ?>
                <div class="step-indicator <?php echo $i < $step ? 'completed' : ($i === $step ? 'active' : ''); ?>" data-step="<?php echo $i; ?>">
                    <?php echo $stepTitles[$i]; ?>
                </div>
            <?php endfor; ?>
        </div>
        
        <div class="installer-content">
            <h2 class="step-title"><?php echo $stepTitles[$step]; ?></h2>
            
            <?php if ($error): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <form method="POST" action="<?php echo System::web('install'); ?>">
                <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                
                <?php if ($step === 1): ?>
                    <!-- Step 1: Requirements -->
                    <?php foreach ($requirements as $req): ?>
                        <div class="requirement">
                            <div class="requirement-status <?php echo $req['passed'] ? 'passed' : 'failed'; ?>">
                                <?php echo $req['passed'] ? '✓' : '✗'; ?>
                            </div>
                            <div class="requirement-name"><?php echo htmlspecialchars($req['name']); ?></div>
                            <div class="requirement-value"><?php echo htmlspecialchars($req['current']); ?></div>
                        </div>
                    <?php endforeach; ?>
                    
                <?php elseif ($step === 2): ?>
                    <!-- Step 2: Directories -->
                    <div class="info-box">
                        <p>Система автоматически создаст необходимые директории:</p>
                        <ul style="margin-top: 8px; margin-left: 20px; color: #2b6cb0;">
                            <li>storage/ — хранилище данных</li>
                            <li>runtime/logs/ — системные логи</li>
                            <li>runtime/cache/ — кэш</li>
                            <li>runtime/sessions/ — сессии</li>
                        </ul>
                    </div>
                    
                <?php elseif ($step === 3): ?>
                    <!-- Step 3: Admin Account -->
                    <div class="form-group">
                        <label for="admin_username">Имя пользователя</label>
                        <input type="text" id="admin_username" name="admin_username" required minlength="3" value="admin">
                        <div class="hint">Минимум 3 символа</div>
                    </div>
                    <div class="form-group">
                        <label for="admin_password">Пароль</label>
                        <input type="password" id="admin_password" name="admin_password" required minlength="6">
                        <div class="hint">Минимум 6 символов</div>
                    </div>
                    <div class="form-group">
                        <label for="admin_password_confirm">Подтвердите пароль</label>
                        <input type="password" id="admin_password_confirm" name="admin_password_confirm" required>
                    </div>
                    
                <?php elseif ($step === 4): ?>
                    <!-- Step 4: Bybit API -->
                    <div class="info-box">
                        <p>Настройка Bybit API необязательна. Вы можете пропустить этот шаг и настроить позже через командную строку:</p>
                        <code style="display: block; margin-top: 8px; color: #2b6cb0;">php index.php keys set bybit &lt;key&gt; &lt;secret&gt;</code>
                    </div>
                    <div class="form-group">
                        <label for="bybit_api_key">API Key</label>
                        <input type="text" id="bybit_api_key" name="bybit_api_key">
                    </div>
                    <div class="form-group">
                        <label for="bybit_api_secret">API Secret</label>
                        <input type="password" id="bybit_api_secret" name="bybit_api_secret">
                    </div>
                    <div class="checkbox-group">
                        <input type="checkbox" id="skip_bybit" name="skip_bybit">
                        <label for="skip_bybit">Пропустить настройку Bybit API</label>
                    </div>
                    
                <?php elseif ($step === 5): ?>
                    <!-- Step 5: Complete -->
                    <div class="success-icon">✓</div>
                    <div style="text-align: center;">
                        <p style="color: #2d3748; font-size: 18px; margin-bottom: 16px;">Установка завершена!</p>
                        <p style="color: #718096;">Система готова к работе. После нажатия кнопки вы будете перенаправлены в панель администратора.</p>
                    </div>
                <?php endif; ?>
                
                <div class="buttons">
                    <?php if ($step > 1): ?>
                        <button type="submit" name="action" value="back" class="btn btn-secondary">Назад</button>
                    <?php endif; ?>
                    <button type="submit" name="action" value="next" class="btn btn-primary">
                        <?php echo $step === 5 ? 'Войти в систему' : 'Далее'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>

<?php
/* RULES
- Purpose: Installer wizard HTML template
- Config sources: None
- Paths: Uses System::web() for form action
- Logs: None
- Prohibitions:
  - NO hardcoded URLs
  - All output must be escaped with htmlspecialchars()
*/
