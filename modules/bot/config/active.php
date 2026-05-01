<?php

declare(strict_types=1);

/**
 * Bot Module — Active Config Overrides
 * Written by the admin UI.
 */

return array (
  'enabled' => true,
  'mode' => 'demo',
  'max_bot_budget' => 10.0,
  'max_bot_leverage' => 15,
  'default_entry_mode' => 'market',
  'default_max_active_positions' => 100,
  'live_enabled' => false,
  'demo_api_key' => 'gdGtgUltMZOduZpgMV',
  'demo_api_secret' => 'vT8QzLW3XDwN3tWDDXN4iru6MczGC1fjDY1b',
  'demo_api_base_url' => 'https://api-demo.bybit.com',
  'symbol_blacklist_enabled' => true,
  'auto_blacklist_enabled' => true,
  'auto_blacklist_loss_threshold' => 1,
  'auto_blacklist_window_hours' => 24,
  'auto_blacklist_duration_hours' => 24,
  'auto_blacklist_count_only_closed_losses' => true,
  'auto_blacklist_reset_on_win' => false,
  'auto_blacklist_modes' => 
  array (
    0 => 'demo',
    1 => 'live',
  ),
  'manual_symbol_blacklist' => 
  array (
    0 => 'ZEREBROUSDT',
  ),
  'symbol_freeze_after_close_enabled' => true,
  'symbol_freeze_after_close_minutes' => 190,
  'symbol_freeze_apply_to_profit_close' => true,
  'symbol_freeze_apply_to_stop_close' => true,
  'symbol_freeze_apply_to_loss_close' => true,
  'symbol_freeze_apply_to_manual_close' => true,
  'symbol_freeze_modes' => 
  array (
    0 => 'demo',
    1 => 'live',
  ),
);
