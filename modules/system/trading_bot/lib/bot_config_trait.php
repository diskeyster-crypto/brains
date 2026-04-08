<?php
declare(strict_types=1);

namespace Modules\System\TradingBot\Lib;

/**
 * Bot Config Trait
 * 
 * Configuration loading and merging for Trading Bot.
 * Supports config.php + bot.json runtime overrides.
 */
trait BotConfigTrait
{
    /**
     * Load configuration from config.php + bot.json
     * 
     * @return array Merged configuration
     */
    private function loadConfig(): array
    {
        $config = [];
        
        // Load base config from config.php
        $configPath = $this->moduleBase . '/config/config.php';
        if (is_file($configPath)) {
            $loaded = require $configPath;
            if (is_array($loaded)) {
                $config = $loaded;
            }
        }
        
        // Load runtime overrides from bot.json
        $botJsonPath = $this->moduleBase . '/config/bot.json';
        if (is_file($botJsonPath)) {
            $content = @file_get_contents($botJsonPath);
            if ($content !== false) {
                $overrides = @json_decode($content, true);
                if (is_array($overrides)) {
                    // Ensure blocks exist
                    if (!isset($config['module']) || !is_array($config['module'])) {
                        $config['module'] = [];
                    }
                    if (!isset($config['sources']) || !is_array($config['sources'])) {
                        $config['sources'] = [];
                    }
                    if (!isset($config['exchange']) || !is_array($config['exchange'])) {
                        $config['exchange'] = [];
                    }
                    if (!isset($config['execution']) || !is_array($config['execution'])) {
                        $config['execution'] = [];
                    }
                    if (!isset($config['validation']) || !is_array($config['validation'])) {
                        $config['validation'] = [];
                    }
                    if (!isset($config['ui']) || !is_array($config['ui'])) {
                        $config['ui'] = [];
                    }

                    // Symbol overrides (per symbol execution policy)
                    if (!isset($config['symbol_overrides']) || !is_array($config['symbol_overrides'])) {
                        $config['symbol_overrides'] = [];
                    }

                    // ----------------------------
                    // Legacy flat overrides (compat)
                    // ----------------------------
                    if (array_key_exists('enabled', $overrides)) {
                        $config['module']['enabled'] = (bool)$overrides['enabled'];
                    }
                    if (isset($overrides['mode']) && is_string($overrides['mode'])) {
                        $mode = strtolower(trim($overrides['mode']));
                        $validModes = ['live', 'demo', 'dry', 'paper'];
                        $config['module']['mode'] = in_array($mode, $validModes, true) ? $mode : ($config['module']['mode'] ?? 'paper');
                    }
                    if (isset($overrides['account_id']) && is_string($overrides['account_id'])) {
                        $acc = trim($overrides['account_id']);
                        if ($acc !== '') {
                            $config['module']['account_id'] = $acc;
                        }
                    }
                    // Demo mode local credentials (NOT KeyCenter)
                    if (isset($overrides['credentials']) && is_array($overrides['credentials'])) {
                        if (!isset($config['module']['credentials']) || !is_array($config['module']['credentials'])) {
                            $config['module']['credentials'] = [];
                        }
                        foreach (['demo', 'live'] as $credMode) {
                            if (isset($overrides['credentials'][$credMode]) && is_array($overrides['credentials'][$credMode])) {
                                $credBlock = $overrides['credentials'][$credMode];
                                $stored = [];
                                if (isset($credBlock['api_key']) && is_string($credBlock['api_key'])) {
                                    $stored['api_key'] = $credBlock['api_key'];
                                }
                                if (isset($credBlock['api_secret']) && is_string($credBlock['api_secret'])) {
                                    $stored['api_secret'] = $credBlock['api_secret'];
                                }
                                if (isset($credBlock['api_base_url']) && is_string($credBlock['api_base_url'])) {
                                    $stored['api_base_url'] = trim($credBlock['api_base_url']);
                                }
                                if (!empty($stored)) {
                                    $config['module']['credentials'][$credMode] = array_merge(
                                        $config['module']['credentials'][$credMode] ?? [],
                                        $stored
                                    );
                                }
                            }
                        }
                    }
                    if (array_key_exists('max_positions', $overrides)) {
                        $config['module']['max_concurrent_positions'] = max(0, (int)$overrides['max_positions']);
                    }
                    if (array_key_exists('reconcile_before_action', $overrides)) {
                        $config['module']['reconcile_before_action'] = (bool)$overrides['reconcile_before_action'];
                    }
                    if (array_key_exists('safety_stop_errors', $overrides)) {
                        $config['execution']['safety_stop_on_errors'] = max(0, (int)$overrides['safety_stop_errors']);
                    }

                    // ----------------------------
                    // Nested overrides
                    // ----------------------------
                    if (isset($overrides['sources']) && is_array($overrides['sources'])) {
                        $src = $overrides['sources'];

                        if (isset($src['signals_key']) && is_string($src['signals_key'])) {
                            $config['sources']['signals_key'] = trim($src['signals_key']);
                        }
                        if (isset($src['signals_file']) && is_string($src['signals_file'])) {
                            $config['sources']['signals_file'] = trim($src['signals_file']);
                        }
                        if (isset($src['risk_active_key']) && is_string($src['risk_active_key'])) {
                            $config['sources']['risk_active_key'] = trim($src['risk_active_key']);
                        }
                        if (isset($src['risk_active_file']) && is_string($src['risk_active_file'])) {
                            $config['sources']['risk_active_file'] = trim($src['risk_active_file']);
                        }
                        if (isset($src['commands_key']) && is_string($src['commands_key'])) {
                            $config['sources']['commands_key'] = trim($src['commands_key']);
                        }
                        if (isset($src['commands_file']) && is_string($src['commands_file'])) {
                            $config['sources']['commands_file'] = trim($src['commands_file']);
                        }
                    }

                    if (isset($overrides['exchange']) && is_array($overrides['exchange'])) {
                        $ex = $overrides['exchange'];

                        if (isset($ex['category']) && is_string($ex['category'])) {
                            $config['exchange']['category'] = trim($ex['category']);
                        }
                        if (isset($ex['settle_coin']) && is_string($ex['settle_coin'])) {
                            $config['exchange']['settle_coin'] = trim($ex['settle_coin']);
                        }
                        if (array_key_exists('position_idx', $ex)) {
                            $config['exchange']['position_idx'] = max(0, (int)$ex['position_idx']);
                        }
                        if (isset($ex['account_type']) && is_string($ex['account_type'])) {
                            $config['exchange']['account_type'] = trim($ex['account_type']);
                        }
                        if (isset($ex['tpsl_mode']) && is_string($ex['tpsl_mode'])) {
                            $config['exchange']['tpsl_mode'] = trim($ex['tpsl_mode']);
                        }
                        if (isset($ex['sl_trigger_by']) && is_string($ex['sl_trigger_by'])) {
                            $config['exchange']['sl_trigger_by'] = trim($ex['sl_trigger_by']);
                        }
                    }

                    if (isset($overrides['execution']) && is_array($overrides['execution'])) {
                        $exu = $overrides['execution'];

                        $ints = [
                            'max_scan_intents_per_run',
                            'max_intents_per_run',
                            'max_deferred_intents_per_run',
                            'default_entry_timeout_minutes',
                            'demo_entry_timeout_extra_minutes',
                            'exchange_positions_cache_ttl_sec',
                            'reconcile_closed_pnl_lookup_minutes',
                            'reconcile_closed_pnl_limit',
                            'reconcile_closed_pnl_match_window_sec',
                            'reconcile_closed_backfill_lookback_minutes',
                            'reconcile_closed_backfill_max_items',
                            'reconcile_closed_backfill_max_attempts',
                            'balance_cache_ttl_sec',
                            'safety_stop_on_errors',
                            'commands_max_per_run',
                            'post_open_reconcile_delay_ms',
                            'post_open_reconcile_retries',
                            'balance_required_buffer_pct',
                            'balance_reject_below_usdt',
                            'yellow_live_max_positions',
                            'yellow_live_max_leverage',
                            'yellow_live_require_min_healthy_samples',
                        ];

                        foreach ($ints as $k) {
                            if (array_key_exists($k, $exu)) {
                                $config['execution'][$k] = (int)$exu[$k];
                            }
                        }

                        $floats = [
                            'default_late_threshold_pct',
                            'demo_late_entry_tolerance_extra_pct',
                            'retrace_slack_pct',
                            'close_reason_sl_tolerance_pct',
                            'dumb_trailing_activation_epsilon_pct',
                            'trailing_activation_roi',
                            'trailing_drawdown_factor',
                            'break_even_activation_roi',
                            'stop_loss_pct',
                            'take_profit_pct',
                            'emergency_stop_loss_pct',
                            'yellow_live_budget_multiplier',
                        ];

                        foreach ($floats as $k) {
                            if (array_key_exists($k, $exu)) {
                                $config['execution'][$k] = (float)$exu[$k];
                            }
                        }

                        $bools = [
                            'require_price_check_live',
                            'reverse_side_enabled',
                            'commands_enabled',
                            'commands_apply_before_intents',
                            'commands_allow_close',
                            'dumb_trailing_enabled',
                            'enable_trailing_on_open',
                            'balance_strict_stable_coin_only',
                            'trailing_enabled',
                            'break_even_enabled',
                            'emergency_stop_enabled',
                            'auto_mode',
                        ];

                        foreach ($bools as $k) {
                            if (array_key_exists($k, $exu)) {
                                $config['execution'][$k] = (bool)$exu[$k];
                            }
                        }

                        if (isset($exu['balance_coin']) && is_string($exu['balance_coin'])) {
                            $config['execution']['balance_coin'] = trim($exu['balance_coin']);
                        }
                        if (isset($exu['trailing_mode']) && is_string($exu['trailing_mode'])) {
                            $config['execution']['trailing_mode'] = trim($exu['trailing_mode']);
                        }
                        if (isset($exu['live_routing_policy']) && is_string($exu['live_routing_policy'])) {
                            $policy = trim($exu['live_routing_policy']);
                            if (in_array($policy, ['green_only', 'green_plus_yellow_capped'], true)) {
                                $config['execution']['live_routing_policy'] = $policy;
                            }
                        }
                    }

                    if (isset($overrides['validation']) && is_array($overrides['validation'])) {
                        $val = $overrides['validation'];

                        if (isset($val['signal_schema_version']) && is_string($val['signal_schema_version'])) {
                            $config['validation']['signal_schema_version'] = trim($val['signal_schema_version']);
                        }
                    }

                    if (isset($overrides['ui']) && is_array($overrides['ui'])) {
                        $ui = $overrides['ui'];

                        if (array_key_exists('max_preview_items', $ui)) {
                            $config['ui']['max_preview_items'] = max(1, (int)$ui['max_preview_items']);
                        }
                    }

                    // Demo learning mode block
                    if (isset($overrides['demo_learning_mode']) && is_array($overrides['demo_learning_mode'])) {
                        if (!isset($config['demo_learning_mode']) || !is_array($config['demo_learning_mode'])) {
                            $config['demo_learning_mode'] = [];
                        }
                        $dlm = $overrides['demo_learning_mode'];

                        $dlmBools = [
                            'enabled',
                            'prefer_short_holds',
                            'allow_low_confidence_demo',
                            'force_reconcile_each_run_demo',
                            'prefer_close_stale_when_learning',
                            'healthy_close_bootstrap_enabled',
                        ];
                        foreach ($dlmBools as $k) {
                            if (array_key_exists($k, $dlm)) {
                                $config['demo_learning_mode'][$k] = (bool)$dlm[$k];
                            }
                        }

                        $dlmInts = [
                            'max_concurrent_demo_positions',
                            'max_demo_signals_per_run',
                            'max_new_positions_per_run',
                            'learning_target_closed_trades',
                            'max_hold_minutes_demo_learning',
                            'stale_trade_review_minutes',
                            'learning_close_timeout_minutes',
                            'learning_max_active_age_minutes',
                            'max_turnover_per_run',
                            'demo_closed_per_run_target',
                            'healthy_min_active_slots',
                            'orphan_max_active_slots',
                        ];
                        foreach ($dlmInts as $k) {
                            if (array_key_exists($k, $dlm)) {
                                $config['demo_learning_mode'][$k] = max(0, (int)$dlm[$k]);
                            }
                        }

                        $dlmIntsMin1 = [
                            'healthy_close_timeout_minutes_bootstrap',
                            'healthy_stale_age_minutes_bootstrap',
                        ];
                        foreach ($dlmIntsMin1 as $k) {
                            if (array_key_exists($k, $dlm)) {
                                $config['demo_learning_mode'][$k] = max(1, (int)$dlm[$k]);
                            }
                        }

                        $dlmFloats = [
                            'healthy_share_target_pct',
                            'healthy_closed_share_target_pct',
                        ];
                        foreach ($dlmFloats as $k) {
                            if (array_key_exists($k, $dlm)) {
                                $config['demo_learning_mode'][$k] = max(0.0, (float)$dlm[$k]);
                            }
                        }
                    }

                    // Demo validation mode block
                    if (isset($overrides['demo_validation_mode']) && is_array($overrides['demo_validation_mode'])) {
                        if (!isset($config['demo_validation_mode']) || !is_array($config['demo_validation_mode'])) {
                            $config['demo_validation_mode'] = [];
                        }
                        $dvm = $overrides['demo_validation_mode'];
                        if (array_key_exists('enabled', $dvm)) {
                            $config['demo_validation_mode']['enabled'] = (bool)$dvm['enabled'];
                        }
                        $dvmInts = [
                            'learning_close_timeout_minutes_override',
                            'learning_max_active_age_minutes_override',
                            'max_new_positions_per_run_override',
                        ];
                        foreach ($dvmInts as $k) {
                            if (array_key_exists($k, $dvm)) {
                                $config['demo_validation_mode'][$k] = max(1, (int)$dvm[$k]);
                            }
                        }
                    }

                    // Demo sources block (Pattern Engine demo feed wiring)
                    if (isset($overrides['demo_sources']) && is_array($overrides['demo_sources'])) {
                        if (!isset($config['demo_sources']) || !is_array($config['demo_sources'])) {
                            $config['demo_sources'] = [];
                        }
                        $ds = $overrides['demo_sources'];

                        if (isset($ds['source_mode']) && is_string($ds['source_mode'])) {
                            $config['demo_sources']['source_mode'] = trim($ds['source_mode']);
                        }
                        if (isset($ds['demo_signals_file']) && is_string($ds['demo_signals_file'])) {
                            $config['demo_sources']['demo_signals_file'] = trim($ds['demo_signals_file']);
                        }
                        if (isset($ds['demo_risk_defaults']) && is_array($ds['demo_risk_defaults'])) {
                            $config['demo_sources']['demo_risk_defaults'] = $ds['demo_risk_defaults'];
                        }
                    }

                    // Symbol overrides from runtime (bot.json)
                    if (isset($overrides['symbol_overrides']) && is_array($overrides['symbol_overrides'])) {
                        $clean = [];
                        foreach ($overrides['symbol_overrides'] as $sym => $row) {
                            if (!is_string($sym) || $sym === '') {
                                continue;
                            }
                            $symKey = strtoupper(trim($sym));
                            if ($symKey === '' || !preg_match('/^[A-Z0-9]{3,25}$/', $symKey)) {
                                continue;
                            }
                            if (!is_array($row)) {
                                continue;
                            }

                            $item = [];
                            if (array_key_exists('enabled', $row)) {
                                $item['enabled'] = (bool)$row['enabled'];
                            }
                            if (array_key_exists('reverse_side_enabled', $row)) {
                                $item['reverse_side_enabled'] = (bool)$row['reverse_side_enabled'];
                            }
                            if (array_key_exists('force_side', $row)) {
                                $fs = is_string($row['force_side']) ? strtolower(trim($row['force_side'])) : '';
                                if (in_array($fs, ['long', 'short'], true)) {
                                    $item['force_side'] = $fs;
                                }
                            }

                            if (!empty($item)) {
                                $clean[$symKey] = $item;
                            }
                        }

                        $config['symbol_overrides'] = $clean;
                    }

                }
            }
        }

        return $config;
    }
    
    /**
     * Save runtime config to bot.json
     * 
     * @param array $settings Settings to save
     * @return bool Success
     */
    protected function saveRuntimeConfig(array $settings): bool
    {
        $botJsonPath = $this->moduleBase . '/config/bot.json';
        
        // Load existing
        $existing = [];
        if (is_file($botJsonPath)) {
            $content = @file_get_contents($botJsonPath);
            if ($content !== false) {
                $existing = @json_decode($content, true) ?? [];
            }
        }
        
        // Merge and save
        $merged = array_merge($existing, $settings, [
            'last_modified' => date('c'),
        ]);
        
        $json = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return @file_put_contents($botJsonPath, $json) !== false;
    }
    
    /**
     * Get config value by dot-notation key
     * 
     * @param string $key Config key (e.g., 'module.enabled')
     * @param mixed $default Default value
     * @return mixed Config value
     */
    protected function getConfigValue(string $key, $default = null)
    {
        $parts = explode('.', $key);
        $value = $this->config;
        
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        
        return $value;
    }
    
    /**
     * Get all validation config
     */
    protected function getValidationConfig(): array
    {
        return $this->config['validation'] ?? [];
    }
    
    /**
     * Get all execution config
     */
    protected function getExecutionConfig(): array
    {
        return $this->config['execution'] ?? [];
    }
    
    /**
     * Get output paths config
     */
    protected function getOutputConfig(): array
    {
        return $this->config['output'] ?? [];
    }
}

/* RULES
- Config trait loads configuration from config/config.php
- Uses SystemPaths to resolve module base directory
- NO hardcoded defaults - all values must be in config
- CONFIG FIRST / ZERO HARDCODE
*/
