<?php
/**
 * Admin Password Reset Utility
 *
 * Web:
 *   - If docroot = /public => open: /reset-password.php
 *   - If docroot = project root => open: /public/reset-password.php or /reset-password.php (if root stub exists)
 *
 * CLI:
 *   php public/reset-password.php
 *
 * What it does:
 * - If storage/initial_credentials.txt exists: sets admin hash to match its Password: value
 * - Else: generates a new random password and writes initial_credentials.txt
 *
 * SECURITY:
 * - After successful reset and login, DELETE:
 *   - public/reset-password.php (or restrict it strongly)
 *   - storage/initial_credentials.txt
 */

declare(strict_types=1);

define('ROOT', dirname(__DIR__));
require_once ROOT . '/core/bootstrap.php';

use Core\Storage\StorageManager;
use Core\System\System;

$isCli = (php_sapi_name() === 'cli');

function out(string $msg, bool $isCli): void
{
    if ($isCli) {
        echo $msg . "\n";
        return;
    }
    echo "<p>" . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . "</p>";
}

function outSuccess(string $msg, bool $isCli): void
{
    if ($isCli) {
        echo "SUCCESS: " . $msg . "\n";
        return;
    }
    echo "<p style='color: #198754; font-weight: 700;'>" . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . "</p>";
}

function outError(string $msg, bool $isCli): void
{
    if ($isCli) {
        echo "ERROR: " . $msg . "\n";
        return;
    }
    echo "<p style='color: #dc3545; font-weight: 700;'>" . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . "</p>";
}

if (!$isCli) {
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Password Reset</title></head><body>";
    echo "<h1>Admin Password Reset</h1>";
}

try {
    // IMPORTANT: System must be initialized before any System::path()/System::config()/StorageManager usage.
    System::init([
        'root' => ROOT,
        'env'  => 'dev',
    ]);

    $storagePath = System::path('storage') . '/data.json';
    $credentialsFile = System::path('storage') . '/initial_credentials.txt';

    out("ROOT: " . ROOT, $isCli);
    out("Storage file: " . $storagePath, $isCli);
    out("Credentials file: " . $credentialsFile, $isCli);

    $storage = StorageManager::instance();
    $users = $storage->get('users') ?? [];

    if (!is_file($credentialsFile)) {
        $randomPassword = bin2hex(random_bytes(8));

        $users['admin'] = [
            'password' => password_hash($randomPassword, PASSWORD_DEFAULT),
            'role' => 'admin',
            'created_at' => $users['admin']['created_at'] ?? date('Y-m-d H:i:s'),
            'initial_password' => true,
        ];

        $storage->set('users', $users);

        $content = "Admin credentials (DELETE THIS FILE AFTER FIRST LOGIN!):\n"
                 . "Username: admin\n"
                 . "Password: {$randomPassword}\n";

        file_put_contents($credentialsFile, $content, LOCK_EX);
        @chmod($credentialsFile, 0600);

        outSuccess("Admin password generated and saved.", $isCli);
        out("Username: admin", $isCli);
        out("Password: {$randomPassword}", $isCli);
    } else {
        $content = (string)file_get_contents($credentialsFile);

        if (!preg_match('/Password:\s*(\S+)/', $content, $m)) {
            outError("Could not parse Password: from initial_credentials.txt", $isCli);
        } else {
            $password = (string)$m[1];

            $users['admin'] = [
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'role' => 'admin',
                'created_at' => $users['admin']['created_at'] ?? date('Y-m-d H:i:s'),
                'initial_password' => true,
            ];

            $storage->set('users', $users);

            outSuccess("Admin password hash updated to match initial_credentials.txt.", $isCli);
            out("Username: admin", $isCli);
            out("Password: {$password}", $isCli);
        }
    }

    out("", $isCli);
    out("Now try login at: /admin/login", $isCli);
    out("IMPORTANT: Delete reset-password.php and initial_credentials.txt after success.", $isCli);

} catch (Throwable $e) {
    outError("Exception: " . $e->getMessage(), $isCli);
    outError("File: " . $e->getFile() . ":" . $e->getLine(), $isCli);
}

if (!$isCli) {
    echo "<hr><p><a href='/admin/login'>Go to Login</a></p>";
    echo "</body></html>";
}

/* RULES
- This utility MUST call System::init() before using System::path()/StorageManager.
- It may print the password only for emergency recovery; remove/restrict this file after use.
- LF only.
*/
