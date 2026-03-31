<?php
declare(strict_types=1);

return [
    'name'        => 'coin_passport',
    'type'        => 'system',
    'category'    => 'system',
    'version'     => '1.0.0',
    'description' => 'Coin Passport — per-symbol trade history & guidance. Single source of truth for passport storage.',
    'routes'      => [
        '/admin/coin_passport'                          => 'CoinPassportController@index',
        '/admin/coin_passport/symbol/{symbol}'          => 'CoinPassportController@detail',
        'POST /admin/coin_passport/rebuild'             => 'CoinPassportController@rebuildAll',
        'POST /admin/coin_passport/rebuild/{symbol}'    => 'CoinPassportController@rebuildSymbol',
        '/admin/coin_passport/api/passports'            => 'CoinPassportController@apiPassports',
        '/admin/coin_passport/api/passport/{symbol}'    => 'CoinPassportController@apiPassport',
        '/admin/coin_passport/api/guidance/{symbol}'    => 'CoinPassportController@apiGuidance',
    ],
];
