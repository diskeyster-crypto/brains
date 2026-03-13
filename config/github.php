<?php

declare(strict_types=1);

/**
 * GitHub Update & Backup Configuration
 * 
 * ВНИМАНИЕ: Этот файл должен содержать ваши настройки GitHub.
 * Все значения ОБЯЗАТЕЛЬНЫ - без "тихих дефолтов".
 * 
 * Для получения токена: https://github.com/settings/tokens
 */

return [
    // GitHub credentials
    'github' => [
        'username' => '',           // Ваш GitHub username
        'token' => '',              // Personal Access Token
        'base_url' => 'https://github.com',
        'branch' => 'main',
    ],
    
    // Backup settings
    'backup' => [
        'repo' => '',               // Репозиторий для бэкапов (например: 'username/project-backup')
        'dir' => 'storage/backups/updates',
        'history_file' => 'storage/system/backup_history.json',
        'max_history' => 50,
    ],
    
    // Update settings
    'update' => [
        'repo' => '',               // Репозиторий с обновлениями (например: 'username/project')
        'staging_dir' => 'runtime/update_stage',
        'auto_backup_before_update' => true,
    ],
    
    // File path classifications
    'paths' => [
        // Safe to auto-update
        'safe' => [
            'core/',
            'modules/',
            'admin/assets/',
            'admin/views/',
        ],
        // Require manual confirmation
        'review' => [
            'config/',
            '.htaccess',
            '.env',
            'index.php',
            'public/index.php',
        ],
        // Never overwrite
        'protected' => [
            'storage/',
            'runtime/',
            'data/',
        ],
        // Specific protected files
        'protected_files' => [
            'storage/initial_credentials.txt',
            'storage/system/github_update.json',
        ],
    ],
    
    // Output settings
    'output' => [
        'log' => 'runtime/logs/github_update.log',
        'settings' => 'storage/system/github_update.json',
    ],
];
