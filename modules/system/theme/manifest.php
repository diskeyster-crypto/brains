<?php

declare(strict_types=1);

/**
 * Theme Manager Module Manifest
 * 
 * System module for managing visual themes of the admin panel.
 * Supports light/dark themes, layout modes, custom CSS, and theme editor.
 * 
 * @package Modules\System\Theme
 * @version 1.0.0
 */

return [
    'name' => 'theme',
    'type' => 'system',
    'version' => '1.0.0',
    'description' => 'System Theme Manager — manage visual themes, layouts, and color schemes',
    'author' => 'Tredercopis',
    'requires' => [
        'core' => '>=1.3.0'
    ],
    'routes' => [
        '/admin/theme' => 'ThemeController@dashboard',
        '/admin/theme/editor' => 'ThemeController@editor',
        '/admin/theme/editor/{id}' => 'ThemeController@editor',
        '/admin/theme/layouts' => 'ThemeController@layouts',
        '/admin/theme/preview' => 'ThemeController@preview',
        '/admin/theme/create' => 'ThemeController@create',
        '/admin/theme/export' => 'ThemeController@export',
        '/admin/theme/import' => 'ThemeController@import',
        '/admin/theme/activate' => 'ThemeController@activate',
        '/admin/theme/delete' => 'ThemeController@delete',
        '/admin/theme/save' => 'ThemeController@save',
    ],
    'storage' => [
        'themes_path' => 'storage/system/themes',
        'config_key' => 'system/theme'
    ]
];
