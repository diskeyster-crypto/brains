<?php
/**
 * Brain Module Manifest
 * 
 * System module for strategy building and pipeline orchestration.
 * Provides UI for defining trader "wishes" (ROI/drawdown/session/timing/long/short)
 * and transforms them into Strategy profiles for Parser5.
 * 
 * @package Modules\System\Brain
 * @version 1.0.0
 */

return [
    'name' => 'brain',
    'type' => 'system',
    'version' => '1.0.0',
    'description' => 'Strategy Builder & Pipeline Orchestrator - define trading preferences, generate strategies',
    'author' => 'Tredercopis Core',
    'requires' => [
        'core' => '>=1.3.0',
    ],
    'routes' => [
        '/admin/brain' => 'BrainController@index',
        '/admin/brain/strategies' => 'BrainController@strategies',
        '/admin/brain/runs' => 'BrainController@runs',
        '/admin/brain/runtime' => 'BrainController@runtime',
        '/admin/brain/timeline' => 'BrainController@timeline',
        '/admin/brain/passports' => 'BrainController@passports',
        '/admin/brain/coin-passports' => 'BrainController@coinPassports',
        '/admin/brain/decisions' => 'BrainController@decisions',
        'POST /admin/brain/strategy/save' => 'BrainController@strategySave',
        'POST /admin/brain/strategy/delete' => 'BrainController@strategyDelete',
        'POST /admin/brain/strategy/toggle-simulator' => 'BrainController@strategyToggleSimulator',
        'POST /admin/brain/strategy/toggle-executor' => 'BrainController@strategyToggleExecutor',
        'POST /admin/brain/run' => 'BrainController@runPipeline',
        'POST /admin/brain/coin-passports/rebuild' => 'BrainController@coinPassportsRebuild',
        '/admin/brain/api/strategies' => 'BrainController@apiStrategies',
        '/admin/brain/api/runs' => 'BrainController@apiRuns',
        '/admin/brain/api/feedback' => 'BrainController@apiFeedback',
        '/admin/brain/api/runtime' => 'BrainController@apiRuntime',
        '/admin/brain/api/timeline' => 'BrainController@apiTimeline',
        'POST /admin/brain/api/stop' => 'BrainController@apiStop',
        '/admin/brain/api/telemetry' => 'BrainController@apiTelemetry',
        // PATCH-DEBUG-v1.3: Runtime buttons + profiles routes
        '/admin/brain/profiles' => 'BrainController@profiles',
        '/admin/brain/api/selftest' => 'BrainController@apiSelftest',
        'POST /admin/brain/api/reset_all' => 'BrainController@apiResetAll',
        '/admin/brain/api/profiles/list' => 'BrainController@apiProfilesList',
        '/admin/brain/api/profiles/get' => 'BrainController@apiProfileGet',
        'POST /admin/brain/api/profiles/create' => 'BrainController@apiProfileCreate',
        'POST /admin/brain/api/profiles/update' => 'BrainController@apiProfileUpdate',
        'POST /admin/brain/api/profiles/delete' => 'BrainController@apiProfileDelete',
        'POST /admin/brain/api/profiles/set_active' => 'BrainController@apiProfileSetActive',
    ],
];

/* RULES
- Purpose: Module manifest for Brain - Strategy Builder + Orchestrator
- Type: System module (lives in modules/system/brain)
- Config sources: Uses own storage in modules/system/brain/storage/
- Paths: None (uses System::path())
- Logs: Writes to own log file storage/logs/brain.log
- Prohibitions:
  - NO direct $_POST/$_GET access
  - NO direct file operations outside module storage
  - NO JSON config files in root config/
*/
