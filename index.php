<?php

declare(strict_types=1);

/**
 * Tredercopis Core - Root Entry Point
 * 
 * This file can be run directly from root:
 *   php index.php [command]
 * 
 * For web access, use public/index.php with a web server.
 */

define('ROOT', __DIR__);

// Bootstrap core - explicit require, no autoloaders
require_once ROOT . '/core/bootstrap.php';

use Core\System\System;
use Core\Logger\Logger;
use Core\Module\ModuleManager;
use Core\Cron\CronManager;
use Core\Storage\StorageManager;
use Core\Gateway\Bybit;
use Core\Auth\Auth;
use Core\KeyCenter\KeyCenter;

// Initialize system
System::init([
    'root' => ROOT,
    'env' => 'dev',
]);

// Handle CLI commands - this file is CLI-only
$args = $_SERVER['argv'] ?? [];
$command = $args[1] ?? 'help';

switch ($command) {
    case 'doctor':
        echo "=== TREDERCOPIS CORE DOCTOR ===\n\n";
        
        $errors = 0;
        $warnings = 0;
        
        // PHP Environment
        echo "[PHP Environment]\n";
        $phpVersion = PHP_VERSION;
        $phpOk = version_compare($phpVersion, '8.2.0', '>=');
        echo ($phpOk ? "  ✓ " : "  ✗ ") . "PHP Version: {$phpVersion} (>= 8.2 required)\n";
        if (!$phpOk) $errors++;
        
        // Extensions
        echo "\n[Extensions]\n";
        $requiredExt = ['json', 'curl', 'openssl', 'mbstring'];
        foreach ($requiredExt as $ext) {
            $loaded = extension_loaded($ext);
            echo ($loaded ? "  ✓ " : "  ✗ ") . "{$ext}: " . ($loaded ? "Loaded" : "MISSING") . "\n";
            if (!$loaded) $errors++;
        }
        
        // Directories
        echo "\n[Directories]\n";
        $dirs = [
            'storage' => System::path('storage'),
            'runtime' => System::path('runtime'),
            'runtime/logs' => System::path('logs'),
            'runtime/cache' => System::path('cache'),
            'runtime/sessions' => System::path('sessions'),
        ];
        foreach ($dirs as $name => $path) {
            $exists = is_dir($path);
            $writable = $exists && is_writable($path);
            if (!$exists) {
                echo "  ✗ {$name}: NOT EXISTS\n";
                $errors++;
            } elseif (!$writable) {
                echo "  ✗ {$name}: NOT WRITABLE\n";
                $errors++;
            } else {
                echo "  ✓ {$name}: Writable\n";
            }
        }
        
        // Config Files
        echo "\n[Config Files]\n";
        $configs = ['system.php', 'storage.php', 'bybit.php'];
        foreach ($configs as $config) {
            $path = System::path('config') . '/' . $config;
            $exists = file_exists($path);
            echo ($exists ? "  ✓ " : "  ✗ ") . "config/{$config}: " . ($exists ? "Found" : "MISSING") . "\n";
            if (!$exists) $errors++;
        }
        
        // Installation Status (IRON-CLAD diagnostics)
        echo "\n[Installation Status]\n";
        $stateDiag = System::state()->getDiagnostics();
        $isInstalled = $stateDiag['is_installed'];
        
        echo "  " . ($stateDiag['file_exists'] ? "✓" : "✗") . " State file exists: " . ($stateDiag['file_exists'] ? "Yes" : "No") . "\n";
        if ($stateDiag['file_exists']) {
            echo "  " . ($stateDiag['is_valid_array'] ? "✓" : "✗") . " State is valid array: " . ($stateDiag['is_valid_array'] ? "Yes" : "No") . "\n";
            echo "  " . ($stateDiag['has_installed_key'] ? "✓" : "✗") . " Has 'installed' key: " . ($stateDiag['has_installed_key'] ? "Yes" : "No") . "\n";
            if ($stateDiag['has_installed_key']) {
                $val = $stateDiag['installed_value'];
                $type = $stateDiag['installed_value_type'];
                echo "  " . ($isInstalled ? "✓" : "✗") . " Installed value: " . var_export($val, true) . " ({$type})\n";
            }
        }
        
        echo "  " . ($isInstalled ? "✓" : "-") . " System installed: " . ($isInstalled ? "Yes" : "No") . "\n";
        
        if (!empty($stateDiag['problems'])) {
            echo "  ⚠ Problems detected:\n";
            foreach ($stateDiag['problems'] as $problem) {
                echo "    - {$problem}\n";
            }
            $warnings++;
        }
        
        if ($isInstalled) {
            $meta = System::state()->getInstallMeta();
            echo "  ✓ Installed at: " . ($meta['installed_at'] ?? 'Unknown') . "\n";
            echo "  ✓ Core version: " . ($meta['core_version'] ?? 'Unknown') . "\n";
            echo "  " . ($meta['admin_user_created'] ? "✓" : "-") . " Admin user created: " . ($meta['admin_user_created'] ? "Yes" : "No") . "\n";
        } else {
            $warnings++;
        }
        
        // Deployment (explicit mode diagnostics)
        echo "\n[Deployment]\n";
        $routeInfo = System::getRoutingDiagnostics();
        $runtimeDiag = System::runtime()->getDiagnostics();
        
        $docrootMode = $runtimeDiag['docroot_mode'];
        $docrootModeSource = $runtimeDiag['docroot_mode_source'] ?? 'auto';
        $baseUrl = $routeInfo['base_url'];
        $scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? 'N/A';
        
        echo "  Docroot mode: " . $docrootMode . "\n";
        echo "  Detected via: " . $docrootModeSource . "\n";
        echo "  BaseUrl: " . ($baseUrl === '' ? "(empty)" : $baseUrl) . "\n";
        echo "  Script filename: " . basename($scriptFilename) . "\n";
        echo "  Request example: " . $routeInfo['effective_install_url'] . "\n";
        
        // Warning for legacy mode
        if ($routeInfo['is_legacy_docroot']) {
            echo "\n  ⚠ WARNING: You are using legacy mode (docroot = /).\n";
            echo "    Recommended: Set web server docroot to /public for better security.\n";
            $warnings++;
        }
        
        // Routing Configuration (explicit baseUrl diagnostics)
        echo "\n[Routing]\n";
        echo "  Proper docroot: " . ($routeInfo['is_proper_docroot'] ? "Yes" : "No") . "\n";
        echo "  Effective install URL: " . $routeInfo['effective_install_url'] . "\n";
        echo "  Effective admin URL: " . $routeInfo['effective_admin_url'] . "\n";
        
        // Runtime diagnostics
        echo "\n[Runtime]\n";
        echo "  Memory usage: " . $runtimeDiag['memory_mb'] . " MB\n";
        echo "  PHP SAPI: " . php_sapi_name() . "\n";
        
        // Auth/Users Status
        echo "\n[Authentication]\n";
        $hasUsers = System::auth()->hasUsers();
        echo "  " . ($hasUsers ? "✓" : "-") . " Users exist: " . ($hasUsers ? "Yes" : "No") . "\n";
        if ($hasUsers) {
            $users = System::auth()->getAllUsers();
            $adminCount = count(array_filter($users, fn($u) => ($u['role'] ?? '') === 'admin'));
            echo "  ✓ Total users: " . count($users) . "\n";
            echo "  ✓ Admin users: {$adminCount}\n";
        }
        
        // System Status
        echo "\n[System Status]\n";
        echo "  ✓ System initialized: " . (System::isInitialized() ? "Yes" : "No") . "\n";
        
        // KeyCenter
        $keyStorage = System::path('storage') . '/.keys.json';
        $keyExists = file_exists($keyStorage);
        echo "  " . ($keyExists ? "✓" : "-") . " KeyCenter storage: " . ($keyExists ? "Found" : "Not created yet") . "\n";
        if (!$keyExists) $warnings++;
        
        // Modules
        echo "\n[Modules]\n";
        $modules = ModuleManager::instance()->discover();
        $enabledCount = count(array_filter($modules, fn($m) => ModuleManager::instance()->isEnabled($m['name'] ?? '')));
        echo "  ✓ Discovered modules: " . count($modules) . "\n";
        echo "  ✓ Enabled modules: {$enabledCount}\n";
        
        // Forbidden patterns check
        echo "\n[Code Standards]\n";
        $forbiddenCount = 0;
        $scanDirs = ['core', 'admin', 'config'];
        foreach ($scanDirs as $dir) {
            $dirPath = System::path('root') . '/' . $dir;
            if (is_dir($dirPath)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dirPath, RecursiveDirectoryIterator::SKIP_DOTS)
                );
                foreach ($iterator as $file) {
                    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
                    $content = file_get_contents($file->getPathname());
                    // Check for forbidden patterns (excluding comments and RULES blocks)
                    $lines = explode("\n", $content);
                    $inRulesBlock = false;
                    foreach ($lines as $line) {
                        $trimmed = trim($line);
                        // Track RULES block
                        if (strpos($trimmed, '/* RULES') !== false || strpos($trimmed, '* RULES') !== false) {
                            $inRulesBlock = true;
                        }
                        if ($inRulesBlock && strpos($trimmed, '*/') !== false) {
                            $inRulesBlock = false;
                            continue;
                        }
                        // Skip comments and RULES blocks
                        if ($inRulesBlock) continue;
                        if (strpos($trimmed, '//') === 0 || strpos($trimmed, '*') === 0 || strpos($trimmed, '-') === 0) continue;
                        // Check for ../ pattern (not in strings)
                        if (preg_match('/\\.\\.\\//', $line) && !preg_match('/[\'"][^\'"]*(\\.\\.\\/).*([\'"|\\.])/', $line)) {
                            $forbiddenCount++;
                        }
                    }
                }
            }
        }
        echo "  " . ($forbiddenCount === 0 ? "✓" : "✗") . " Forbidden patterns (../): " . ($forbiddenCount === 0 ? "None" : "{$forbiddenCount} found") . "\n";
        if ($forbiddenCount > 0) $warnings++;
        
        // .htaccess Architecture Check
        echo "\n[.htaccess Architecture]\n";
        $htaccessIssues = [];
        $forbiddenDirectives = ['<Directory', '<DirectoryMatch', '<Location', '<LocationMatch'];
        
        // Check both .htaccess files
        $htaccessFiles = [
            System::path('root') . '/.htaccess',
            System::path('root') . '/public/.htaccess',
        ];
        
        foreach ($htaccessFiles as $htaccessPath) {
            if (file_exists($htaccessPath)) {
                $htaccessContent = file_get_contents($htaccessPath);
                $relPath = str_replace(System::path('root') . '/', '', $htaccessPath);
                
                foreach ($forbiddenDirectives as $directive) {
                    if (stripos($htaccessContent, $directive) !== false) {
                        $htaccessIssues[] = "{$relPath}: Contains illegal '{$directive}' directive";
                    }
                }
                
                // Check for "Require all denied" outside FilesMatch
                $lines = explode("\n", $htaccessContent);
                $inFilesMatch = false;
                foreach ($lines as $lineNum => $line) {
                    $trimmed = trim($line);
                    if (stripos($trimmed, '<FilesMatch') !== false) {
                        $inFilesMatch = true;
                    }
                    if ($inFilesMatch && stripos($trimmed, '</FilesMatch>') !== false) {
                        $inFilesMatch = false;
                    }
                    if (!$inFilesMatch && stripos($trimmed, 'Require all denied') !== false) {
                        $htaccessIssues[] = "{$relPath} line " . ($lineNum + 1) . ": 'Require all denied' must be in VirtualHost, not .htaccess";
                    }
                }
            }
        }
        
        if (empty($htaccessIssues)) {
            echo "  ✓ No illegal Apache directives in .htaccess files\n";
            echo "  ✓ Architecture: Routing only (security in VirtualHost)\n";
        } else {
            echo "  ✗ [CRITICAL] Illegal Apache directives found:\n";
            foreach ($htaccessIssues as $issue) {
                echo "    - {$issue}\n";
            }
            echo "\n  Security MUST be configured in Apache VirtualHost, not .htaccess.\n";
            echo "  See README.md for VirtualHost configuration examples.\n";
            $errors += count($htaccessIssues);
        }
        
        // Summary
        echo "\n=== Summary ===\n";
        if ($errors === 0 && $warnings === 0) {
            echo "All checks passed!\n";
            exit(0);
        } elseif ($errors === 0) {
            echo "{$warnings} warning(s), no errors.\n";
            exit(0);
        } else {
            echo "{$errors} error(s), {$warnings} warning(s).\n";
            exit(1);
        }
        break;
        
    case 'info':
        echo "Tredercopis Core v" . System::version() . "\n";
        echo "==========================\n";
        echo "Environment: " . System::env() . "\n";
        echo "Root: " . System::path('root') . "\n";
        echo "PHP Version: " . PHP_VERSION . "\n";
        echo "SAPI: " . php_sapi_name() . "\n";
        break;
    
    case 'version':
        $info = System::versionInfo();
        echo "Tredercopis Core\n";
        echo "================\n";
        echo "Version: " . $info['version'] . "\n";
        echo "Major: " . $info['major'] . "\n";
        echo "Minor: " . $info['minor'] . "\n";
        echo "Patch: " . $info['patch'] . "\n";
        if (!empty($info['prerelease'])) {
            echo "Prerelease: " . $info['prerelease'] . "\n";
        }
        echo "PHP: " . $info['php_version'] . "\n";
        break;
        
    case 'modules':
        $modules = ModuleManager::instance()->discover();
        echo "Discovered modules:\n";
        foreach ($modules as $name => $module) {
            $status = ModuleManager::instance()->isEnabled($name) ? '[enabled]' : '[disabled]';
            echo "  - {$name} v{$module['version']} {$status}\n";
            echo "    {$module['description']}\n";
        }
        break;
        
    case 'cron':
        $subCommand = $args[2] ?? 'list';
        $taskArg = $args[3] ?? null;
        
        if ($subCommand === 'list') {
            $tasks = CronManager::instance()->list();
            echo "Registered cron tasks:\n";
            if (empty($tasks)) {
                echo "  No tasks registered\n";
            } else {
                foreach ($tasks as $id => $task) {
                    $status = ($task['enabled'] ?? false) ? '[enabled]' : '[disabled]';
                    $desc = $task['description'] ?? '';
                    echo "  - {$id} {$status}\n";
                    if ($desc) echo "    {$desc}\n";
                    echo "    Handler: " . ($task['handler'] ?? 'N/A') . "\n";
                    echo "    Interval: " . ($task['interval'] ?? 60) . "s\n";
                    echo "    Last run: " . (isset($task['last_run']) && $task['last_run'] ? date('Y-m-d H:i:s', $task['last_run']) : 'Never') . "\n";
                }
            }
        } elseif ($subCommand === 'run') {
            if ($taskArg) {
                echo "Running task: {$taskArg}...\n";
                $result = CronManager::instance()->runTask($taskArg);
                $status = $result['success'] ? 'OK' : 'FAILED';
                echo "  - {$result['task_id']}: {$status}\n";
                if (!$result['success']) {
                    echo "    Error: {$result['message']}\n";
                } else {
                    echo "    Duration: " . ($result['duration'] ?? 0) . "s\n";
                }
            } else {
                echo "Running all due cron tasks...\n";
                $results = CronManager::instance()->run();
                echo "Executed " . count($results) . " cron task(s)\n";
                foreach ($results as $taskId => $result) {
                    $status = $result['success'] ? 'OK' : 'FAILED';
                    echo "  - {$taskId}: {$status} ({$result['duration']}s)\n";
                }
            }
        } else {
            echo "Usage: php index.php cron [list|run] [task_name]\n";
            echo "  list         - List all registered tasks\n";
            echo "  run          - Run all due tasks\n";
            echo "  run <task>   - Run specific task\n";
        }
        break;
        
    case 'storage':
        $data = StorageManager::instance()->all();
        echo "Storage contents:\n";
        echo "  Keys: " . count($data) . "\n";
        foreach (array_keys($data) as $key) {
            echo "  - {$key}\n";
        }
        break;
        
    case 'bybit':
        echo "Bybit Gateway Diagnostic\n";
        echo "========================\n\n";
        $diagnostic = Bybit::diagnostic();
        
        // KeyCenter status
        echo "📦 KEY CENTER:\n";
        $kc = $diagnostic['keycenter'] ?? [];
        echo "  Storage exists: " . ($kc['storage_exists'] ? 'Yes' : 'No') . "\n";
        echo "  Bybit credentials: " . ($kc['has_bybit_credentials'] ? 'Set (KeyCenter)' : 'Not set') . "\n";
        echo "  Source: " . ($diagnostic['credentials_source'] ?? 'unknown') . "\n";
        
        if (!$kc['has_bybit_credentials']) {
            echo "\n  ⚠️  To set credentials:\n";
            echo "     php index.php keys set bybit <api_key> <api_secret>\n";
        }
        
        echo "\n📊 GATEWAY STATUS:\n";
        echo "  Gateway: {$diagnostic['gateway']}\n";
        echo "  Account: {$diagnostic['account']}\n";
        echo "  Base URL: {$diagnostic['base_url']}\n";
        echo "  Credentials: " . ($diagnostic['has_credentials'] ? 'Set' : 'Not set') . "\n";
        if (isset($diagnostic['api_key_prefix'])) {
            echo "  API Key: {$diagnostic['api_key_prefix']}\n";
        }
        echo "  PHP: {$diagnostic['php_version']}\n";
        echo "  cURL: {$diagnostic['curl_version']}\n";
        
        echo "\n🧪 TESTS:\n";
        
        // Server time test
        if (isset($diagnostic['tests']['server_time'])) {
            $test = $diagnostic['tests']['server_time'];
            $status = $test['success'] ? '✅' : '❌';
            echo "\n  {$status} Server Time Test\n";
            echo "     HTTP Code: " . ($test['http_code'] ?? 'N/A') . "\n";
            echo "     Ret Code: " . ($test['ret_code'] ?? 'N/A') . "\n";
            echo "     Error Type: " . ($test['error_type'] ?? 'none') . "\n";
            if (isset($test['server_time'])) {
                echo "     Server Time: {$test['server_time']}\n";
            }
            if (isset($test['error'])) {
                echo "     Error: {$test['error']}\n";
            }
        }
        
        // Public API test
        if (isset($diagnostic['tests']['public_api'])) {
            $test = $diagnostic['tests']['public_api'];
            $status = $test['success'] ? '✅' : '❌';
            echo "\n  {$status} Public API Test (market.tickers)\n";
            echo "     HTTP Code: " . ($test['http_code'] ?? 'N/A') . "\n";
            echo "     Ret Code: " . ($test['ret_code'] ?? 'N/A') . "\n";
            echo "     Error Type: " . ($test['error_type'] ?? 'none') . "\n";
            if (isset($test['btc_price'])) {
                echo "     BTC/USDT: \${$test['btc_price']}\n";
            }
            if (isset($test['raw_text_length'])) {
                echo "     Response: {$test['raw_text_length']} bytes\n";
            }
            if (isset($test['error'])) {
                echo "     Error: {$test['error']}\n";
            }
        }
        
        // Positions API test
        if (isset($diagnostic['tests']['positions'])) {
            $test = $diagnostic['tests']['positions'];
            if (isset($test['skipped'])) {
                echo "\n  ⏭️  Positions API Test (skipped)\n";
                echo "     Reason: {$test['reason']}\n";
            } else {
                $status = $test['success'] ? '✅' : '❌';
                echo "\n  {$status} Positions API Test (positions.list category=linear)\n";
                echo "     HTTP Code: " . ($test['http_code'] ?? 'N/A') . "\n";
                echo "     Ret Code: " . ($test['ret_code'] ?? 'N/A') . "\n";
                echo "     Error Type: " . ($test['error_type'] ?? 'none') . "\n";
                echo "     Message: " . ($test['ret_msg'] ?? 'N/A') . "\n";
                if (!$test['success'] && isset($test['probable_cause'])) {
                    echo "     Probable Cause: {$test['probable_cause']}\n";
                }
            }
        }
        
        // Wallet API test
        if (isset($diagnostic['tests']['wallet'])) {
            $test = $diagnostic['tests']['wallet'];
            if (isset($test['skipped'])) {
                echo "\n  ⏭️  Wallet API Test (skipped)\n";
                echo "     Reason: {$test['reason']}\n";
            } else {
                $status = $test['success'] ? '✅' : '❌';
                echo "\n  {$status} Wallet API Test (account.wallet accountType=UNIFIED)\n";
                echo "     HTTP Code: " . ($test['http_code'] ?? 'N/A') . "\n";
                echo "     Ret Code: " . ($test['ret_code'] ?? 'N/A') . "\n";
                echo "     Error Type: " . ($test['error_type'] ?? 'none') . "\n";
                echo "     Message: " . ($test['ret_msg'] ?? 'N/A') . "\n";
                if (!$test['success'] && isset($test['probable_cause'])) {
                    echo "     Probable Cause: {$test['probable_cause']}\n";
                }
            }
        }
        
        // Summary
        echo "\n📋 SUMMARY:\n";
        if (isset($diagnostic['summary'])) {
            $sum = $diagnostic['summary'];
            echo "  Server Time: " . ($sum['server_time_ok'] ? '✅' : '❌') . "\n";
            echo "  Public API: " . ($sum['public_api_ok'] ? '✅' : '❌') . "\n";
            echo "  Positions: " . ($sum['positions_ok'] ? '✅' : '⏭️') . "\n";
            echo "  Wallet: " . ($sum['wallet_ok'] ? '✅' : '⏭️') . "\n";
            
            $allOk = $sum['server_time_ok'] && $sum['public_api_ok'] && $sum['positions_ok'] && $sum['wallet_ok'];
            echo "\n  Overall: " . ($allOk ? '✅ All tests passed' : '⚠️  Some tests incomplete') . "\n";
        }
        
        // Show full JSON for debugging
        $showJson = in_array('--json', $args);
        if ($showJson) {
            echo "\n📄 FULL JSON:\n";
            echo json_encode($diagnostic, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        } else {
            echo "\n💡 Подробнее: php index.php bybit --json\n";
            echo "💡 Скрипт: php scripts/bybit_diagnostic.php\n";
        }
        break;
        
    case 'log':
        $message = $args[2] ?? 'Test log message from CLI';
        Logger::info($message, ['source' => 'cli', 'command' => 'log']);
        echo "Log written to: " . System::path('logs') . "/system.log\n";
        break;
    
    case 'system:scan-uppercase':
        echo "Сканирование файлов с заглавными буквами...\n";
        echo "==========================================\n\n";
        
        $uppercaseFiles = [];
        $scanDirs = ['core', 'modules', 'config', 'admin', 'public', 'runtime', 'storage'];
        
        foreach ($scanDirs as $dir) {
            $dirPath = System::path('root') . '/' . $dir;
            if (is_dir($dirPath)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dirPath, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );
                
                foreach ($iterator as $file) {
                    $basename = $file->getBasename();
                    if (preg_match('/[A-Z]/', $basename)) {
                        $relativePath = str_replace(System::path('root') . '/', '', $file->getPathname());
                        $uppercaseFiles[] = $relativePath;
                    }
                }
            }
        }
        
        // Check root files
        $rootFiles = scandir(System::path('root'));
        foreach ($rootFiles as $file) {
            if ($file !== '.' && $file !== '..' && preg_match('/[A-Z]/', $file)) {
                $uppercaseFiles[] = $file;
            }
        }
        
        if (empty($uppercaseFiles)) {
            echo "✅ Все файлы уже в lowercase!\n";
            echo json_encode(['uppercase_files' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        } else {
            echo "⚠️  Найдены файлы с заглавными буквами:\n\n";
            foreach ($uppercaseFiles as $file) {
                echo "  - /{$file}\n";
            }
            echo "\n" . json_encode(['uppercase_files' => $uppercaseFiles], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        }
        
        Logger::info('system:scan-uppercase выполнен', ['found' => count($uppercaseFiles)]);
        break;
    
    case 'system:normalize-lowercase':
        echo "Нормализация файлов к lowercase...\n";
        echo "==================================\n\n";
        
        $logFile = System::path('logs') . '/system_lowercase.log';
        $backupDir = System::path('root') . '/storage/backups';
        
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        // Create backup
        $backupName = 'lowercase_fix_' . date('Ymd_Hi') . '.zip';
        $backupPath = $backupDir . '/' . $backupName;
        
        echo "Создание резервной копии: {$backupName}\n";
        
        $zip = new ZipArchive();
        if ($zip->open($backupPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $scanDirs = ['core', 'modules', 'config', 'admin', 'public'];
            foreach ($scanDirs as $dir) {
                $dirPath = System::path('root') . '/' . $dir;
                if (is_dir($dirPath)) {
                    $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($dirPath, RecursiveDirectoryIterator::SKIP_DOTS)
                    );
                    foreach ($iterator as $file) {
                        if ($file->isFile()) {
                            $relativePath = str_replace(System::path('root') . '/', '', $file->getPathname());
                            $zip->addFile($file->getPathname(), $relativePath);
                        }
                    }
                }
            }
            $zip->close();
            echo "✅ Резервная копия создана\n\n";
        } else {
            echo "❌ Не удалось создать резервную копию\n";
            exit(1);
        }
        
        // Scan and normalize
        $uppercaseFiles = [];
        $scanDirs = ['core', 'modules', 'config', 'admin', 'public', 'runtime', 'storage'];
        
        foreach ($scanDirs as $dir) {
            $dirPath = System::path('root') . '/' . $dir;
            if (is_dir($dirPath)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dirPath, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                
                foreach ($iterator as $file) {
                    $basename = $file->getBasename();
                    if (preg_match('/[A-Z]/', $basename)) {
                        $uppercaseFiles[] = [
                            'path' => $file->getPathname(),
                            'basename' => $basename,
                            'new_basename' => strtolower($basename),
                        ];
                    }
                }
            }
        }
        
        if (empty($uppercaseFiles)) {
            echo "✅ Все файлы уже в lowercase! Нечего нормализовать.\n";
            file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Нормализация не требуется\n", FILE_APPEND);
        } else {
            $success = 0;
            $failed = 0;
            
            foreach ($uppercaseFiles as $fileInfo) {
                $oldPath = $fileInfo['path'];
                $newPath = dirname($oldPath) . '/' . $fileInfo['new_basename'];
                
                if (rename($oldPath, $newPath)) {
                    $logEntry = "[OK] {$oldPath} → {$newPath}\n";
                    echo $logEntry;
                    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] " . $logEntry, FILE_APPEND);
                    $success++;
                } else {
                    $logEntry = "[FAIL] {$oldPath} (permission denied или другая ошибка)\n";
                    echo $logEntry;
                    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] " . $logEntry, FILE_APPEND);
                    $failed++;
                }
            }
            
            echo "\n";
            echo "Результат: {$success} успешно, {$failed} ошибок\n";
            
            if ($failed > 0) {
                echo "⚠️  Есть ошибки! Проверьте права доступа.\n";
            }
        }
        
        Logger::info('system:normalize-lowercase выполнен', ['processed' => count($uppercaseFiles)]);
        break;
    
    case 'core:normalize-names':
        $isDry = in_array('--dry', $args);
        $isApply = in_array('--apply', $args);
        
        if (!$isDry && !$isApply) {
            echo "Использование: php index.php core:normalize-names [--dry|--apply]\n";
            echo "  --dry   Показать что будет изменено, без изменений\n";
            echo "  --apply Применить изменения\n";
            break;
        }
        
        echo $isDry ? "Режим проверки (dry run)...\n" : "Применение изменений...\n";
        echo "================================\n\n";
        
        $uppercaseFiles = [];
        $scanDirs = ['core', 'modules', 'config', 'admin', 'public', 'runtime', 'storage'];
        
        foreach ($scanDirs as $dir) {
            $dirPath = System::path('root') . '/' . $dir;
            if (is_dir($dirPath)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dirPath, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                
                foreach ($iterator as $file) {
                    $basename = $file->getBasename();
                    if (preg_match('/[A-Z]/', $basename)) {
                        $uppercaseFiles[] = [
                            'path' => $file->getPathname(),
                            'basename' => $basename,
                            'new_basename' => strtolower($basename),
                        ];
                    }
                }
            }
        }
        
        if (empty($uppercaseFiles)) {
            echo "✅ Все файлы уже в lowercase!\n";
        } else {
            foreach ($uppercaseFiles as $fileInfo) {
                $oldPath = $fileInfo['path'];
                $newPath = dirname($oldPath) . '/' . $fileInfo['new_basename'];
                
                if ($isDry) {
                    echo "[DRY] {$oldPath} → {$newPath}\n";
                } else {
                    if (rename($oldPath, $newPath)) {
                        echo "[OK] {$oldPath} → {$newPath}\n";
                    } else {
                        echo "[FAIL] {$oldPath}\n";
                    }
                }
            }
            
            echo "\nНайдено: " . count($uppercaseFiles) . " файлов\n";
        }
        break;
    
    case 'core:diagnostic':
        echo "Tredercopis Core Diagnostic\n";
        echo "===========================\n\n";
        
        // Paths
        echo "📁 PATHS:\n";
        $pathKeys = ['root', 'runtime', 'logs', 'cache', 'sessions', 'storage', 'modules', 'config', 'admin', 'public'];
        foreach ($pathKeys as $key) {
            try {
                $path = System::path($key);
                $exists = is_dir($path) || file_exists($path);
                $status = $exists ? '✓' : '✗';
                echo "  {$status} {$key}: {$path}\n";
            } catch (\Throwable $e) {
                echo "  ✗ {$key}: ERROR\n";
            }
        }
        
        echo "\n📊 PHP:\n";
        echo "  Version: " . PHP_VERSION . "\n";
        echo "  SAPI: " . php_sapi_name() . "\n";
        echo "  OS: " . PHP_OS . "\n";
        
        echo "\n📂 WRITABLE DIRS:\n";
        $writableDirs = ['runtime', 'logs', 'cache', 'sessions', 'storage'];
        foreach ($writableDirs as $key) {
            try {
                $path = System::path($key);
                $writable = is_writable($path);
                $status = $writable ? '✓' : '✗';
                echo "  {$status} {$key}: " . ($writable ? 'writable' : 'NOT WRITABLE') . "\n";
            } catch (\Throwable $e) {
                echo "  ✗ {$key}: ERROR\n";
            }
        }
        
        echo "\n🔌 BYBIT GATEWAY:\n";
        $bybitDiag = Bybit::diagnostic();
        echo "  Gateway: {$bybitDiag['gateway']}\n";
        echo "  Base URL: {$bybitDiag['base_url']}\n";
        echo "  Credentials: " . ($bybitDiag['has_credentials'] ? 'Set' : 'Not set') . "\n";
        if (isset($bybitDiag['api_key_prefix'])) {
            echo "  API Key: {$bybitDiag['api_key_prefix']}\n";
        }
        
        // Server time test
        if (isset($bybitDiag['tests']['server_time'])) {
            $test = $bybitDiag['tests']['server_time'];
            echo "  Server Time: " . ($test['success'] ? 'OK' : 'FAILED');
            if (isset($test['http_code'])) {
                echo " (HTTP {$test['http_code']})";
            }
            echo "\n";
        }
        
        // Public API test
        if (isset($bybitDiag['tests']['public_api'])) {
            $pubTest = $bybitDiag['tests']['public_api'];
            echo "  Public API: " . ($pubTest['success'] ? 'OK' : 'FAILED');
            if (isset($pubTest['http_code'])) {
                echo " (HTTP {$pubTest['http_code']})";
            }
            echo "\n";
            if (isset($pubTest['btc_price'])) {
                echo "    BTC/USDT: {$pubTest['btc_price']}\n";
            }
            if (isset($pubTest['error'])) {
                echo "    Error: {$pubTest['error']}\n";
            }
            if (isset($pubTest['ret_msg']) && $pubTest['ret_msg'] !== 'OK') {
                echo "    Message: {$pubTest['ret_msg']}\n";
            }
        }
        
        // Positions API test
        if (isset($bybitDiag['tests']['positions'])) {
            $posTest = $bybitDiag['tests']['positions'];
            if (isset($posTest['skipped'])) {
                echo "  Positions: Skipped\n";
            } else {
                echo "  Positions: " . ($posTest['success'] ? 'OK' : 'FAILED');
                if (isset($posTest['http_code'])) {
                    echo " (HTTP {$posTest['http_code']})";
                }
                echo "\n";
            }
        }
        
        // Wallet API test
        if (isset($bybitDiag['tests']['wallet'])) {
            $walletTest = $bybitDiag['tests']['wallet'];
            if (isset($walletTest['skipped'])) {
                echo "  Wallet: Skipped\n";
            } else {
                echo "  Wallet: " . ($walletTest['success'] ? 'OK' : 'FAILED');
                if (isset($walletTest['http_code'])) {
                    echo " (HTTP {$walletTest['http_code']})";
                }
                echo "\n";
            }
        }
        
        // Summary
        if (isset($bybitDiag['summary'])) {
            $sum = $bybitDiag['summary'];
            $allOk = ($sum['server_time_ok'] ?? false) && ($sum['public_api_ok'] ?? false) && ($sum['positions_ok'] ?? false) && ($sum['wallet_ok'] ?? false);
            echo "  Overall: " . ($allOk ? '✅ All tests passed' : '⚠️  Some tests failed/skipped') . "\n";
        }
        
        echo "\n🔑 KEY CENTER:\n";
        $keysDiag = KeyCenter::instance()->diagnostic();
        echo "  Storage: " . ($keysDiag['storage_exists'] ? 'exists' : 'not created') . "\n";
        echo "  Encryption: " . ($keysDiag['encryption_key_exists'] ? 'ready' : 'not initialized') . "\n";
        if (!empty($keysDiag['services'])) {
            echo "  Services: " . implode(', ', $keysDiag['services']) . "\n";
        } else {
            echo "  Services: none\n";
        }
        
        echo "\n⏰ CRON JOBS:\n";
        $tasks = CronManager::instance()->getAll();
        if (empty($tasks)) {
            echo "  No tasks registered\n";
        } else {
            foreach ($tasks as $id => $task) {
                $status = $task['enabled'] ? '✓' : '✗';
                echo "  {$status} {$id} (interval: {$task['interval']}s)\n";
            }
        }
        
        echo "\n👤 ADMIN STATUS:\n";
        $credentialsFile = System::path('storage') . '/initial_credentials.txt';
        if (file_exists($credentialsFile)) {
            echo "  ⚠️  Initial credentials file exists - password change required!\n";
            echo "  File: {$credentialsFile}\n";
        } else {
            echo "  ✓ No initial credentials file (password has been changed)\n";
        }
        
        echo "\n📝 LOG FILES:\n";
        $logChannels = ['system', 'security', 'cron'];
        foreach ($logChannels as $channel) {
            $logPath = System::path('logs') . '/' . $channel . '.log';
            if (file_exists($logPath)) {
                $size = filesize($logPath);
                echo "  ✓ {$channel}.log ({$size} bytes)\n";
            } else {
                echo "  - {$channel}.log (not created yet)\n";
            }
        }
        
        // Check forbidden patterns
        echo "\n🚫 FORBIDDEN PATTERNS CHECK:\n";
        
        $forbiddenPatterns = [
            '../' => 'Parent directory references',
            '__DIR__ . \'/../' => 'Parent directory via __DIR__',
            'dirname(__DIR__)' => 'Parent directory via dirname',
            '/var/www' => 'Hardcoded absolute path',
            '$_SERVER[\'DOCUMENT_ROOT\']' => 'DOCUMENT_ROOT usage',
        ];
        
        $patternViolations = [];
        $scanDirs = ['core', 'modules', 'config', 'admin', 'public'];
        
        foreach ($scanDirs as $dir) {
            $dirPath = System::path('root') . '/' . $dir;
            if (is_dir($dirPath)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dirPath, RecursiveDirectoryIterator::SKIP_DOTS)
                );
                
                foreach ($iterator as $file) {
                    if (!$file->isFile()) continue;
                    if ($file->getExtension() !== 'php') continue;
                    
                    $content = file_get_contents($file->getPathname());
                    $relativePath = str_replace(System::path('root') . '/', '', $file->getPathname());
                    
                    foreach ($forbiddenPatterns as $pattern => $description) {
                        // Skip pattern matching inside comments
                        if (strpos($content, $pattern) !== false) {
                            // Check if in comment (rough check)
                            $lines = explode("\n", $content);
                            foreach ($lines as $lineNum => $line) {
                                if (strpos($line, $pattern) !== false) {
                                    // Skip if it's a comment
                                    $trimmedLine = trim($line);
                                    if (strpos($trimmedLine, '//') === 0 || strpos($trimmedLine, '*') === 0 || strpos($trimmedLine, '#') === 0) {
                                        continue;
                                    }
                                    $patternViolations[] = [
                                        'file' => $relativePath,
                                        'line' => $lineNum + 1,
                                        'pattern' => $pattern,
                                        'description' => $description,
                                    ];
                                }
                            }
                        }
                    }
                }
            }
        }
        
        if (empty($patternViolations)) {
            echo "  ✓ No forbidden patterns found\n";
        } else {
            echo "  ⚠️  Found forbidden patterns:\n";
            foreach ($patternViolations as $v) {
                echo "    ✗ {$v['file']}:{$v['line']} - {$v['description']}\n";
            }
        }
        
        Logger::info('core:diagnostic выполнен');
        break;
    
    case 'install':
        echo "Установка Tredercopis Core\n";
        echo "==========================\n\n";
        
        // Ensure directories exist
        $dirs = [
            System::path('runtime'),
            System::path('logs'),
            System::path('cache'),
            System::path('sessions'),
            System::path('root') . '/storage',
            System::path('root') . '/storage/backups',
            System::path('modules'),
        ];
        
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
                echo "✅ Создана директория: {$dir}\n";
            } else {
                echo "✓  Директория существует: {$dir}\n";
            }
        }
        
        // Initialize storage
        echo "\nИнициализация хранилища...\n";
        StorageManager::instance()->set('system_installed', [
            'version' => '1.0.0',
            'installed_at' => date('Y-m-d H:i:s'),
        ]);
        echo "✅ Хранилище инициализировано\n";
        
        // Create default admin user with random password
        echo "\nСоздание пользователя admin...\n";
        // Force user creation by calling getAllUsers()
        Auth::getAllUsers();
        
        $credentialsFile = System::path('storage') . '/initial_credentials.txt';
        if (file_exists($credentialsFile)) {
            echo "✅ Пользователь admin создан\n";
            echo "\n⚠️  ВАЖНО! Начальные учетные данные сохранены в:\n";
            echo "   {$credentialsFile}\n";
            echo "\n   Удалите этот файл после первого входа!\n";
        } else {
            echo "✓  Пользователь admin уже существует\n";
        }
        
        echo "\n";
        echo "========================================\n";
        echo "✅ Установка завершена!\n";
        echo "========================================\n";
        echo "\nДля запуска веб-интерфейса:\n";
        echo "  php -S localhost:8080 -t public\n";
        
        Logger::info('Система установлена', ['version' => '1.0.0']);
        break;
    
    case 'core:check-lowercase':
        echo "Проверка lowercase policy...\n";
        echo "============================\n\n";
        
        $violations = [];
        $scanDirs = ['core', 'modules', 'config', 'admin', 'public', 'runtime', 'storage'];
        
        foreach ($scanDirs as $dir) {
            $dirPath = System::path('root') . '/' . $dir;
            if (is_dir($dirPath)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dirPath, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );
                
                foreach ($iterator as $file) {
                    $basename = $file->getBasename();
                    if (preg_match('/[A-Z]/', $basename)) {
                        $relativePath = str_replace(System::path('root') . '/', '', $file->getPathname());
                        $violations[] = [
                            'path' => $relativePath,
                            'type' => 'uppercase',
                            'issue' => "Contains uppercase: {$basename}",
                        ];
                    }
                }
            }
        }
        
        // Check root files
        $rootFiles = scandir(System::path('root'));
        foreach ($rootFiles as $file) {
            if ($file !== '.' && $file !== '..' && !is_dir(System::path('root') . '/' . $file)) {
                if (preg_match('/[A-Z]/', $file)) {
                    $violations[] = [
                        'path' => $file,
                        'type' => 'uppercase',
                        'issue' => "Contains uppercase: {$file}",
                    ];
                }
            }
        }
        
        if (empty($violations)) {
            echo "✅ Все файлы соответствуют lowercase policy\n";
            echo json_encode(['status' => 'ok', 'violations' => []], JSON_PRETTY_PRINT) . "\n";
            exit(0);
        } else {
            echo "❌ Найдены нарушения lowercase policy:\n\n";
            foreach ($violations as $v) {
                echo "  - {$v['path']}: {$v['issue']}\n";
            }
            echo "\n" . json_encode(['status' => 'fail', 'violations' => $violations], JSON_PRETTY_PRINT) . "\n";
            exit(1);
        }
        break;
    
    case 'core:check-lf':
        echo "Проверка LF line endings...\n";
        echo "===========================\n\n";
        
        $violations = [];
        $extensions = ['php', 'js', 'css', 'json', 'md', 'txt', 'html'];
        $scanDirs = ['core', 'modules', 'config', 'admin', 'public'];
        
        foreach ($scanDirs as $dir) {
            $dirPath = System::path('root') . '/' . $dir;
            if (is_dir($dirPath)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dirPath, RecursiveDirectoryIterator::SKIP_DOTS)
                );
                
                foreach ($iterator as $file) {
                    if (!$file->isFile()) continue;
                    
                    $ext = strtolower($file->getExtension());
                    if (!in_array($ext, $extensions)) continue;
                    
                    $content = file_get_contents($file->getPathname());
                    if ($content === false) continue;
                    
                    // Check for CRLF (Windows line endings)
                    if (strpos($content, "\r\n") !== false) {
                        $relativePath = str_replace(System::path('root') . '/', '', $file->getPathname());
                        $crlfCount = substr_count($content, "\r\n");
                        $violations[] = [
                            'path' => $relativePath,
                            'type' => 'crlf',
                            'count' => $crlfCount,
                            'issue' => "Contains {$crlfCount} CRLF line endings",
                        ];
                    }
                }
            }
        }
        
        // Check root PHP files
        foreach (glob(System::path('root') . '/*.php') as $file) {
            $content = file_get_contents($file);
            if (strpos($content, "\r\n") !== false) {
                $basename = basename($file);
                $crlfCount = substr_count($content, "\r\n");
                $violations[] = [
                    'path' => $basename,
                    'type' => 'crlf',
                    'count' => $crlfCount,
                    'issue' => "Contains {$crlfCount} CRLF line endings",
                ];
            }
        }
        
        if (empty($violations)) {
            echo "✅ Все файлы используют LF line endings\n";
            echo json_encode(['status' => 'ok', 'violations' => []], JSON_PRETTY_PRINT) . "\n";
            exit(0);
        } else {
            echo "❌ Найдены файлы с CRLF line endings:\n\n";
            foreach ($violations as $v) {
                echo "  - {$v['path']}: {$v['issue']}\n";
            }
            echo "\n" . json_encode(['status' => 'fail', 'violations' => $violations], JSON_PRETTY_PRINT) . "\n";
            exit(1);
        }
        break;
    
    case 'core:fix-lf':
        echo "Исправление CRLF → LF...\n";
        echo "========================\n\n";
        
        $fixed = [];
        $extensions = ['php', 'js', 'css', 'json', 'md', 'txt', 'html'];
        $scanDirs = ['core', 'modules', 'config', 'admin', 'public'];
        
        foreach ($scanDirs as $dir) {
            $dirPath = System::path('root') . '/' . $dir;
            if (is_dir($dirPath)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dirPath, RecursiveDirectoryIterator::SKIP_DOTS)
                );
                
                foreach ($iterator as $file) {
                    if (!$file->isFile()) continue;
                    
                    $ext = strtolower($file->getExtension());
                    if (!in_array($ext, $extensions)) continue;
                    
                    $content = file_get_contents($file->getPathname());
                    if ($content === false) continue;
                    
                    if (strpos($content, "\r\n") !== false) {
                        $newContent = str_replace("\r\n", "\n", $content);
                        if (file_put_contents($file->getPathname(), $newContent) !== false) {
                            $relativePath = str_replace(System::path('root') . '/', '', $file->getPathname());
                            $fixed[] = $relativePath;
                            echo "[OK] {$relativePath}\n";
                        }
                    }
                }
            }
        }
        
        // Fix root PHP files
        foreach (glob(System::path('root') . '/*.php') as $file) {
            $content = file_get_contents($file);
            if (strpos($content, "\r\n") !== false) {
                $newContent = str_replace("\r\n", "\n", $content);
                if (file_put_contents($file, $newContent) !== false) {
                    $basename = basename($file);
                    $fixed[] = $basename;
                    echo "[OK] {$basename}\n";
                }
            }
        }
        
        if (empty($fixed)) {
            echo "✅ Все файлы уже используют LF line endings\n";
        } else {
            echo "\n✅ Исправлено файлов: " . count($fixed) . "\n";
        }
        
        Logger::info('core:fix-lf выполнен', ['fixed' => count($fixed)]);
        break;
    
    case 'keys':
        $subCommand = $args[2] ?? 'list';
        
        switch ($subCommand) {
            case 'list':
                echo "Key Center - Сохраненные ключи\n";
                echo "==============================\n\n";
                $diagnostic = KeyCenter::instance()->diagnostic();
                $accounts = $diagnostic['accounts'];
                
                if (empty($accounts)) {
                    echo "Нет сохраненных ключей.\n";
                    echo "\nИспользование:\n";
                    echo "  php index.php keys set bybit [account] <api_key> <api_secret>\n";
                    echo "  php index.php keys get bybit [account]\n";
                    echo "  php index.php keys delete bybit [account]\n";
                } else {
                    foreach ($accounts as $service => $serviceAccounts) {
                        echo "📦 {$service}:\n";
                        foreach ($serviceAccounts as $account => $info) {
                            echo "   └─ {$account}\n";
                            echo "      Keys: " . implode(', ', $info['keys']) . "\n";
                            echo "      Created: {$info['created_at']}\n";
                        }
                    }
                }
                break;
                
            case 'set':
                $service = $args[3] ?? null;
                if ($service === 'bybit') {
                    $account = $args[4] ?? 'default';
                    $apiKey = $args[5] ?? null;
                    $apiSecret = $args[6] ?? null;
                    
                    if (!$apiKey || !$apiSecret) {
                        echo "Использование: php index.php keys set bybit [account] <api_key> <api_secret>\n";
                        echo "\nПример:\n";
                        echo "  php index.php keys set bybit default YOUR_API_KEY YOUR_API_SECRET\n";
                        break;
                    }
                    
                    KeyCenter::instance()->setBybitCredentials($apiKey, $apiSecret, $account);
                    echo "✅ Ключи Bybit для аккаунта '{$account}' сохранены\n";
                    echo "   API Key: " . substr($apiKey, 0, 8) . "...\n";
                } else {
                    echo "Использование: php index.php keys set <service> [account] <credentials...>\n";
                    echo "\nДоступные сервисы:\n";
                    echo "  bybit - Bybit API credentials\n";
                }
                break;
                
            case 'get':
                $service = $args[3] ?? null;
                $account = $args[4] ?? 'default';
                
                if (!$service) {
                    echo "Использование: php index.php keys get <service> [account]\n";
                    break;
                }
                
                $creds = KeyCenter::instance()->getCredentials($service, $account);
                if (empty($creds)) {
                    echo "Ключи не найдены для {$service}/{$account}\n";
                } else {
                    echo "Ключи {$service}/{$account}:\n";
                    foreach ($creds as $key => $value) {
                        if (str_contains(strtolower($key), 'secret') || str_contains(strtolower($key), 'password')) {
                            echo "  {$key}: ***hidden***\n";
                        } else {
                            echo "  {$key}: {$value}\n";
                        }
                    }
                }
                break;
                
            case 'delete':
                $service = $args[3] ?? null;
                $account = $args[4] ?? 'default';
                
                if (!$service) {
                    echo "Использование: php index.php keys delete <service> [account]\n";
                    break;
                }
                
                if (KeyCenter::instance()->hasCredentials($service, $account)) {
                    KeyCenter::instance()->deleteCredentials($service, $account);
                    echo "✅ Ключи {$service}/{$account} удалены\n";
                } else {
                    echo "Ключи не найдены для {$service}/{$account}\n";
                }
                break;
                
            case 'diagnostic':
                echo "Key Center Diagnostic\n";
                echo "=====================\n\n";
                $diagnostic = KeyCenter::instance()->diagnostic();
                echo json_encode($diagnostic, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
                break;
                
            default:
                echo "Key Center - Команды:\n";
                echo "  keys list                    Список всех ключей\n";
                echo "  keys set bybit [acct] <key> <secret>\n";
                echo "  keys get <service> [account] Показать ключи\n";
                echo "  keys delete <service> [acct] Удалить ключи\n";
                echo "  keys diagnostic              Диагностика хранилища\n";
                break;
        }
        break;
    
    case 'migrate':
        $subCommand = $args[2] ?? 'run';
        
        if ($subCommand === 'status') {
            echo "=== Migration Status ===\n\n";
            $status = \Core\System\SystemMigration::status();
            
            $appliedCount = 0;
            $pendingCount = 0;
            
            foreach ($status as $version => $info) {
                if ($info['applied']) {
                    echo "[Applied] {$version}";
                    if ($info['applied_at']) {
                        echo " - {$info['applied_at']}";
                    }
                    echo "\n";
                    $appliedCount++;
                } else {
                    echo "[Pending] {$version}\n";
                    $pendingCount++;
                }
            }
            
            echo "\nTotal: {$appliedCount} applied, {$pendingCount} pending\n";
        } else {
            // Run migrations
            echo "=== Running Migrations ===\n\n";
            
            $pending = \Core\System\SystemMigration::pending();
            
            if (empty($pending)) {
                echo "No pending migrations.\n";
            } else {
                echo "Pending migrations: " . implode(', ', $pending) . "\n\n";
                
                $results = \Core\System\SystemMigration::run();
                
                foreach ($results as $version => $result) {
                    if ($result['success']) {
                        echo "✓ {$version}: Applied";
                        if (!empty($result['description'])) {
                            echo " - {$result['description']}";
                        }
                        echo "\n";
                    } else {
                        echo "✗ {$version}: Failed - {$result['error']}\n";
                    }
                }
            }
        }
        break;
        
    default:
        echo "Tredercopis Core CLI v" . System::version() . "\n";
        echo "============================\n\n";
        echo "Использование: php index.php <команда> [аргументы]\n\n";
        echo "Команды:\n";
        echo "  info                          Информация о системе\n";
        echo "  version                       Версия ядра\n";
        echo "  doctor                        Проверка окружения (PHP, dirs, configs)\n";
        echo "  modules                       Список модулей\n";
        echo "  cron [list|run]               Задачи планировщика\n";
        echo "  storage                       Ключи хранилища\n";
        echo "  bybit                         Диагностика Bybit\n";
        echo "  log [сообщение]               Записать тестовый лог\n";
        echo "  install                       Установить систему\n";
        echo "  migrate                       Запустить миграции\n";
        echo "  migrate status                Статус миграций\n";
        echo "\nKey Center (управление ключами API):\n";
        echo "  keys list                     Список всех ключей\n";
        echo "  keys set bybit <key> <secret> Сохранить ключи Bybit\n";
        echo "  keys get <service>            Показать ключи\n";
        echo "  keys delete <service>         Удалить ключи\n";
        echo "\nДиагностика:\n";
        echo "  doctor                        Быстрая проверка окружения\n";
        echo "  core:diagnostic               Полная диагностика системы\n";
        echo "\nПроверка стандартов:\n";
        echo "  core:check-lowercase          Проверить lowercase policy\n";
        echo "  core:check-lf                 Проверить LF line endings\n";
        echo "  core:fix-lf                   Исправить CRLF → LF\n";
        echo "  core:normalize-names --dry    Проверить файлы (без изменений)\n";
        echo "  core:normalize-names --apply  Нормализовать к lowercase\n";
        echo "\nLegacy:\n";
        echo "  system:scan-uppercase         Поиск файлов с заглавными буквами\n";
        echo "  system:normalize-lowercase    Нормализация к lowercase\n";
        echo "\nДля веб-админки: php -S localhost:8080 -t public\n";
        break;
}

echo "\n";

/* RULES
- Purpose: CLI entrypoint - all CLI commands
- Config sources: Uses System::init() to load configs
- Paths: Defines ROOT, then only System::path()
- Logs: Commands may write to Logger
- Prohibitions:
  - NO hardcoded paths after ROOT definition
  - System::init() must be called before any System:: usage
*/
