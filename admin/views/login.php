<?php

declare(strict_types=1);

use Core\Auth\Auth;
use Core\System\System;

function renderLogin(string $error = ''): string
{
    $errorHtml = '';
    if ($error) {
        $errorMsg = htmlspecialchars($error, ENT_QUOTES, 'UTF-8');
        $errorHtml = "<div class=\"alert alert-danger\">{$errorMsg}</div>";
    }
    
    $csrfField = Auth::csrfField();

    // Hint for first-time setup / lost password (does NOT expose any secret)
    $hintHtml = '';
    try {
        $credFile = System::path('storage') . '/initial_credentials.txt';
        $resetWebA = '/reset-password.php'; // when docroot=/public
        $resetWebB = '/public/reset-password.php'; // when docroot=/
        if (is_file($credFile)) {
            $mtime = @filemtime($credFile);
            $mtimeTxt = $mtime ? date('Y-m-d H:i:s', $mtime) : '';
            $mtimeLine = $mtimeTxt ? "<br><small class=\"text-muted\">Файл обновлён: {$mtimeTxt}</small>" : '';
            $hintHtml = "<div class=\"alert alert-info\">" .
                "<strong>Первый вход / забыли пароль?</strong><br>" .
                "Проверьте файл <code>storage/initial_credentials.txt</code> на сервере (создаётся автоматически при первом запуске)." .
                "{$mtimeLine}<br>" .
                "Если пароль из файла не подходит — запустите сброс пароля: <a href=\"{$resetWebA}\" target=\"_blank\">{$resetWebA}</a> или <a href=\"{$resetWebB}\" target=\"_blank\">{$resetWebB}</a> (в зависимости от docroot).<br>" .
                "<small class=\"text-muted\">После сброса и входа удалите reset-password.php и initial_credentials.txt.</small>" .
                "</div>";
        } else {
            $hintHtml = "<div class=\"alert alert-info\">" .
                "<strong>Забыли пароль?</strong><br>" .
                "Запустите сброс пароля: <a href=\"{$resetWebA}\" target=\"_blank\">{$resetWebA}</a> или <a href=\"{$resetWebB}\" target=\"_blank\">{$resetWebB}</a> (в зависимости от docroot).<br>" .
                "<small class=\"text-muted\">После сброса удалите reset-password.php.</small>" .
                "</div>";
        }
    } catch (\Throwable $e) {
        // Silent: do not break login page rendering
    }

    
    return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход - Tredercopis Админ</title>
    <link rel="stylesheet" href="/admin/assets/css/style.css">
</head>
<body>
    <div class="login-container">
        <div class="login-box">
            <h1>Tredercopis</h1>
            {$errorHtml}
            {$hintHtml}
            <form method="post" action="/admin/login">
                {$csrfField}
                <div class="form-group">
                    <label for="username">Имя пользователя</label>
                    <input type="text" id="username" name="username" class="form-control" required autofocus>
                </div>
                <div class="form-group">
                    <label for="password">Пароль</label>
                    <input type="password" id="password" name="password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%;">Войти</button>
            </form>
        </div>
    </div>
</body>
</html>
HTML;
}


/* RULES
- Login view must not expose passwords.
- Shows safe hints about where initial credentials are stored and how to run reset-password utility.
- Uses CSRF field from Auth.
- LF only.
*/
