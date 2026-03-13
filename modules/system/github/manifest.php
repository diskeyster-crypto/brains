<?php
/**
 * GitHub Center Module Manifest
 * 
 * System module for GitHub integration - updates, backups, and repository management.
 * This is an exemplary system module following Core architecture v1.3+
 * 
 * @package Modules\System\GitHub
 * @version 2.0.0
 */

return [
    'name' => 'github',
    'type' => 'system',
    'version' => '2.0.0',
    'description' => 'GitHub integration for updates, backups, and repository management',
    'author' => 'Tredercopis Core',
    'requires' => [
        'core' => '>=1.3.0',
    ],
    'routes' => [
        '/admin/github' => 'GithubController@index',
        '/admin/github/settings' => 'GithubController@settings',
        'POST /admin/github/settings' => 'GithubController@saveSettings',
        '/admin/github/test' => 'GithubController@test',
        '/admin/github/fetch' => 'GithubController@fetch',
        '/admin/github/update' => 'GithubController@update',
        'POST /admin/github/update' => 'GithubController@doUpdate',
        '/admin/github/backups' => 'GithubController@backups',
        'POST /admin/github/backup' => 'GithubController@createBackup',
    ],
];

/* RULES
- Purpose: Module manifest for GitHub system integration
- Type: System module (lives in modules/system/github)
- Config sources: storage/system/github.php via StorageManager::instance()
- Paths: None (uses System::path())
- Logs: Writes to system log via System::log()
- Prohibitions:
  - NO direct $_POST/$_GET access
  - NO direct file operations
  - NO direct curl calls
  - NO JSON config files
*/
