<?php
/**
 * Trading Bot v1 — Manifest
 * 
 * LIVE Executor module - executes trades based on Brain decisions.
 * Bot does NOT "think" - it only executes: open / update / close / reconcile / safety.
 * 
 * UI: /public/admin/trading
 * 
 * @package Modules\System\TradingBot
 * @version 1.0.0
 */

return [
    'name' => 'trading_bot',
    'type' => 'system',
    'category' => 'system',
    'version' => '1.0.0',
    'description' => 'Trading Bot v1 (LIVE Executor) - executes Brain decisions on exchange',
    'author' => 'Tredercopis Core',
    'requires' => [
        'core' => '>=1.3.0',
    ],
    'paths' => [
        'storage' => 'storage',
        'logs' => 'storage/logs',
        'config' => 'config/config.php',
    ],
    'routes' => [
        // Main dashboard
        '/admin/trading' => 'TradingBotController@index',
        '/admin/trading/dashboard' => 'TradingBotController@dashboard',
        '/admin/trading/intents' => 'TradingBotController@intents',
        '/admin/trading/trades' => 'TradingBotController@trades',
        '/admin/trading/orders' => 'TradingBotController@orders',
        '/admin/trading/settings' => 'TradingBotController@settings',
        '/admin/trading/logs' => 'TradingBotController@logs',
        '/admin/trading/profit' => 'TradingBotController@profit',
        
        // API endpoints
        '/admin/trading/api/status' => 'TradingBotController@apiStatus',
        '/admin/trading/api/intents' => 'TradingBotController@apiIntents',
        '/admin/trading/api/trades' => 'TradingBotController@apiTrades',
        '/admin/trading/api/orders' => 'TradingBotController@apiOrders',
        '/admin/trading/api/stats' => 'TradingBotController@apiStats',
        
        // Action endpoints
        'POST /admin/trading/api/run' => 'TradingBotController@apiRun',
        'POST /admin/trading/api/stop' => 'TradingBotController@apiStop',
        'POST /admin/trading/api/reconcile' => 'TradingBotController@apiReconcile',
        'POST /admin/trading/api/settings/save' => 'TradingBotController@apiSettingsSave',
        'POST /admin/trading/api/position/close' => 'TradingBotController@apiPositionClose',
        'POST /admin/trading/api/position/stops' => 'TradingBotController@apiPositionStops',

        // Profit Manager (tab)
        '/admin/trading/api/profit/state' => 'TradingBotController@apiProfitState',
        'POST /admin/trading/api/profit/run' => 'TradingBotController@apiProfitRun',
    ],
];

/* RULES
- Purpose: Module manifest for Trading Bot v1 (LIVE Executor)
- Type: System module (lives in modules/system/trading_bot)
- Config sources: Uses own storage in modules/system/trading_bot/storage/
- Risk source: ONLY from Brain (signal.risk block)
- Paths: SystemPaths ONLY (no __DIR__, ../, absolute paths)
- Prohibitions:
  - NO hardcoded values
  - NO direct $_POST/$_GET access (use controller methods)
  - NO direct file operations outside module storage
  - NO JSON config files in root config/
*/