<?php
/**
 * Installer Module Manifest
 * 
 * Web-based installation wizard for Tredercopis Core OS
 * 
 * @package Modules\Installer
 */

return [
    'name' => 'installer',
    'version' => '1.0.0',
    'description' => 'Web-based installation wizard',
    'entry' => 'controller.php',
    'requires' => [
        'core' => '>=1.0.0',
    ],
    'author' => 'Tredercopis Core',
    'routes' => [
        '/install' => 'InstallerController@index',
        'POST /install' => 'InstallerController@process',
    ],
];

/* RULES
- Purpose: Module manifest for web installer
- Config sources: None
- Paths: None
- Logs: None
- Prohibitions: None
*/
