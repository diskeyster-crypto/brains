<?php
declare(strict_types=1);

return [
    'module_name'       => 'ai_shadow',
    'storage_base'      => __DIR__ . '/../storage',
    'live_signals_path' => __DIR__ . '/../../smart_brain/storage/signals.json',
    'live_intents_path' => __DIR__ . '/../../smart_brain/storage/live_intents.json',
    'live_trades_dir'   => __DIR__ . '/../../trading_bot/storage',
];
