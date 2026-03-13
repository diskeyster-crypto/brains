<?php
declare(strict_types=1);

/**
 * Profit Manager — Config Proxy
 *
 * SINGLE SOURCE OF TRUTH:
 * - trading_bot/config/config.php (profit_manager block)
 *
 * This file only returns the shared config block, so profit_manager and trading_bot
 * always operate under ONE config.
 *
 * @return array<string,mixed>
 */
return (static function (): array {
    $paths = \Core\System\SystemPaths::instance();

    // Try common aliases for Trading Bot module base
    $candidates = [
        'system.trading_bot',
        'trading.trading_bot',
        'modules.trading_bot',
    ];

    $tradingBotBase = null;
    foreach ($candidates as $key) {
        try {
            $p = $paths->get($key);
            if (is_string($p) && $p !== '' && is_dir($p)) {
                $tradingBotBase = $p;
                break;
            }
        } catch (\Throwable $e) {
            // ignore and try next key
        }
    }

    if (!is_string($tradingBotBase) || $tradingBotBase === '') {
        // Fallback: keep module disabled to avoid dangerous behavior if config cannot be found.
        return [
            'module' => [
                'enabled' => false,
                'mode' => 'dry',
                'account_id' => 'trading_bot',
            ],
        ];
    }

    $cfgPath = $tradingBotBase . '/config/config.php';
    $cfg = is_file($cfgPath) ? require $cfgPath : [];
    if (!is_array($cfg)) {
        $cfg = [];
    }

    $pm = $cfg['profit_manager'] ?? [];
    if (!is_array($pm)) {
        $pm = [];
    }
    // Runtime overrides from Trading Bot UI (bot.json)
    // IMPORTANT: UI values are authoritative, so we ALWAYS override config defaults here.
    $runtimePath = $tradingBotBase . '/config/bot.json';
    if (is_file($runtimePath)) {
        $json = file_get_contents($runtimePath);
        $runtime = is_string($json) ? json_decode($json, true) : null;

        if (is_array($runtime)) {
            if (!isset($pm['module']) || !is_array($pm['module'])) {
                $pm['module'] = [];
            }

            if (isset($runtime['account_id']) && is_string($runtime['account_id']) && $runtime['account_id'] !== '') {
                $pm['module']['account_id'] = $runtime['account_id'];
            }

            if (isset($runtime['mode']) && is_string($runtime['mode']) && in_array($runtime['mode'], ['live', 'dry'], true)) {
                $pm['module']['mode'] = $runtime['mode'];
            }
        }
    }

    return $pm;
})();

/* RULES
- profit_manager MUST use trading_bot config only (single config source)
- trading_bot/config/config.php holds profit_manager block
- UI runtime overrides are read from trading_bot/config/bot.json (account_id/mode override defaults)
- If Trading Bot config cannot be resolved: module is forced disabled (safe fallback)
- LF only
*/