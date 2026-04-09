<?php
declare(strict_types=1);

return [
    'name'        => 'pattern_engine',
    'type'        => 'system',
    'category'    => 'system',
    'version'     => '1.0.0',
    'description' => 'Pattern Engine — standalone pattern detection, universal signal normalization, and scenario-based routing.',
    'routes'      => [
        // UI
        '/admin/pattern_engine'                        => 'PatternEngineController@index',
        '/admin/pattern_engine/candidates'             => 'PatternEngineController@candidates',
        '/admin/pattern_engine/signals'                => 'PatternEngineController@signals',
        '/admin/pattern_engine/scenarios'              => 'PatternEngineController@scenarios',
        '/admin/pattern_engine/settings'               => 'PatternEngineController@settings',
        // Actions
        'POST /admin/pattern_engine/settings/save'     => 'PatternEngineController@saveSettings',
        'POST /admin/pattern_engine/clear'             => 'PatternEngineController@clearStorage',
        // API
        '/admin/pattern_engine/api/signals'            => 'PatternEngineController@apiSignals',
        '/admin/pattern_engine/api/scenarios'          => 'PatternEngineController@apiScenarios',
        '/admin/pattern_engine/api/demo_signals'       => 'PatternEngineController@apiDemoSignals',
        '/admin/pattern_engine/api/shadow_signals'     => 'PatternEngineController@apiShadowSignals',
        '/admin/pattern_engine/api/sim_signals'        => 'PatternEngineController@apiSimSignals',
    ],
];
