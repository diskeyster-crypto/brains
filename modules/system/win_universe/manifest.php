<?php
declare(strict_types=1);

return [
    'name'        => 'win_universe',
    'type'        => 'system',
    'category'    => 'system',
    'version'     => '1.0.0',
    'description' => 'Win Universe — shadow-mode module that identifies winning coins based on recent real trading results. Observational only; does not affect Smart Brain, Trading Bot, or Profit Manager.',
    'routes'      => [
        '/admin/win_universe'             => 'WinUniverseController@index',
        '/admin/win_universe/api/universe' => 'WinUniverseController@apiUniverse',
        'POST /admin/win_universe/run'    => 'WinUniverseController@run',
    ],
];
