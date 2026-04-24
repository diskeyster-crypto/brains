<?php
declare(strict_types=1);

/**
 * Unified Config Module — Manifest
 *
 * Shadow system: audits, models, and previews unified config.
 * NOT yet runtime authority for any module.
 *
 * UI: /admin/smart_brain/config_all/
 *
 * @package Modules\System\Config
 * @version 0.1.0
 */

return [
    'name'        => 'config',
    'type'        => 'system',
    'category'    => 'system',
    'version'     => '0.1.0',
    'description' => 'Unified Config Module (shadow): ownership audit, conflict report, and read-only preview UI',
    'author'      => 'Tredercopis Core',
    'requires'    => [
        'core' => '>=1.3.0',
    ],
    'paths'       => [
        'storage' => 'storage',
        'runtime' => 'storage/runtime',
        'config'  => 'config/config.php',
    ],
    'routes'      => [
        // Main config UI
        '/admin/smart_brain/config_all'                    => 'UnifiedConfigController@index',
        '/admin/smart_brain/config_all/patterns'           => 'UnifiedConfigController@patterns',
        '/admin/smart_brain/config_all/smart_brain'        => 'UnifiedConfigController@smartBrain',
        '/admin/smart_brain/config_all/trading_bot'        => 'UnifiedConfigController@tradingBot',
        '/admin/smart_brain/config_all/coin_cycle'         => 'UnifiedConfigController@coinCycle',
        '/admin/smart_brain/config_all/win_universe'       => 'UnifiedConfigController@winUniverse',
        '/admin/smart_brain/config_all/advanced'           => 'UnifiedConfigController@advanced',

        // API endpoints (read)
        '/admin/smart_brain/config_all/api/ownership'      => 'UnifiedConfigController@apiOwnership',
        '/admin/smart_brain/config_all/api/conflicts'      => 'UnifiedConfigController@apiConflicts',
        '/admin/smart_brain/config_all/api/operational'    => 'UnifiedConfigController@apiOperational',
        '/admin/smart_brain/config_all/api/master'         => 'UnifiedConfigController@apiMaster',
        '/admin/smart_brain/config_all/api/immutable'      => 'UnifiedConfigController@apiImmutable',
        '/admin/smart_brain/config_all/api/preview'        => 'UnifiedConfigController@apiPreview',

        // Action: run extraction pass
        'POST /admin/smart_brain/config_all/api/extract'            => 'UnifiedConfigController@apiExtract',

        // Action: save operational master config
        'POST /admin/smart_brain/config_all/api/save'               => 'UnifiedConfigController@apiSave',
        'POST /admin/smart_brain/config_all/api/save_and_reextract' => 'UnifiedConfigController@apiSaveAndReextract',

        // Win Universe config save
        'POST /admin/smart_brain/config_all/win_universe/save' => 'UnifiedConfigController@winUniverseSaveConfig',
    ],
];

/* RULES
- Purpose: Manifest for Unified Config Module (shadow)
- Type: System module (lives in modules/system/config)
- This module is read-only: it DOES NOT mutate configs of other modules
- Config sources: reads from sibling module config files and runtime JSON files
- Paths: SystemPaths ONLY
- Prohibitions:
  - NO hardcoded values
  - NO writes to other modules' config files
  - NO runtime authority over Smart Brain / Trading Bot / PM / Coin Passport
*/
