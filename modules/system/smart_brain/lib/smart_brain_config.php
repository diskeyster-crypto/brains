<?php
declare(strict_types=1);

final class SmartBrainConfig
{
    private const ALLOWED_PATTERN_ALGORITHMS = ['double_bottom', 'double_top', 'pullback_trend_continue', 'double_bottom_confirm_v2', 'double_top_confirm_v2', 'double_bottom_contextual_v2', 'double_bottom_contextual_v3', 'double_top_contextual_v2', 'double_top_contextual_v3'];

    /** Valid execution profile IDs */
    private const ALLOWED_EXECUTION_PROFILES = ['balanced', 'conservative', 'sniper_75_attempt', 'sniper_lite', 'custom'];

    // ── Live Intent Lifecycle Constants ──────────────────────────────────
    /** Default TTL for live intents in minutes */
    public const LIVE_INTENT_TTL_MINUTES = 5;
    /** Retention window for terminal intents before cleanup (minutes) */
    public const LIVE_INTENT_CLEANUP_RETENTION_MINUTES = 60;
    /** Intent lifecycle statuses */
    public const INTENT_STATUS_PENDING  = 'pending';
    public const INTENT_STATUS_CLAIMED  = 'claimed';
    public const INTENT_STATUS_EXECUTED = 'executed';
    public const INTENT_STATUS_REJECTED = 'rejected';
    public const INTENT_STATUS_EXPIRED  = 'expired';

    private string $moduleBase;
    /** @var array<string,mixed> */
    private array $config;
    /** @var array<string,mixed> config source / migration status (set by applyUserConfig) */
    private array $configMigrationStatus = [];

    public function __construct(string $moduleBase)
    {
        $this->moduleBase = rtrim($moduleBase, '/');
        $this->config = require $this->moduleBase . '/config/config.php';
        $this->applyOverrides();
        $this->applyUserConfig();
    }

    /**
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return $this->config;
    }

    /**
     * @return mixed
     */
    public function get(string $section, $default = null)
    {
        return $this->config[$section] ?? $default;
    }

    /**
     * Get effective settings for a section (merges mode-based structure).
     * If section has mode/settings/auto_rules, returns settings.
     * Otherwise returns section as-is.
     *
     * @return array<string,mixed>
     */
    public function getEffective(string $section): array
    {
        $data = $this->config[$section] ?? [];
        if (!is_array($data)) {
            return [];
        }
        if (isset($data['settings']) && is_array($data['settings'])) {
            return $data['settings'];
        }
        return $data;
    }

    /**
     * Get user limits — merged from base config + runtime/user_config.json.
     *
     * @return array<string,mixed>
     */
    public function getUserLimits(): array
    {
        $riskEngine = $this->config['risk_engine'] ?? [];
        return (array)($riskEngine['user_limits'] ?? []);
    }

    /**
     * Load saved user config from runtime/user_config.json.
     *
     * @return array<string,mixed>
     */
    public function loadUserConfig(): array
    {
        $path = $this->moduleBase . '/runtime/user_config.json';
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    /**
     * Save user config to runtime/user_config.json.
     *
     * @param array<string,mixed> $values
     * @return array{ok:bool,errors:list<string>}
     */
    public function saveUserConfig(array $values): array
    {
        $errors = $this->validateUserConfig($values);
        if (!empty($errors)) {
            return ['ok' => false, 'errors' => $errors];
        }

        $clean = [
            'execution_profile'            => in_array((string)($values['execution_profile'] ?? 'custom'), self::ALLOWED_EXECUTION_PROFILES, true)
                                                ? (string)$values['execution_profile'] : 'custom',
            'pattern_profile_mode'         => in_array((string)($values['pattern_profile_mode'] ?? 'manual_override'), ['manual_override', 'profile_controlled'], true)
                                                ? (string)$values['pattern_profile_mode'] : 'manual_override',
            'max_budget_per_coin'          => (float)$values['max_budget_per_coin'],
            'max_active_tasks'             => (int)$values['max_active_tasks'],
            'max_leverage'                 => (int)$values['max_leverage'],
            'brain_mode'                   => (string)$values['brain_mode'],
            'bootstrap_enabled'            => !empty($values['bootstrap_enabled']),
            'bootstrap_max_signals'        => (int)$values['bootstrap_max_signals'],
            'bootstrap_budget_factor'      => (float)$values['bootstrap_budget_factor'],
            'bootstrap_max_leverage'       => (int)$values['bootstrap_max_leverage'],
            'min_reliability_after_warmup' => (float)$values['min_reliability_after_warmup'],
            'warmup_min_trades'            => (int)$values['warmup_min_trades'],
            // Exit Policy
            'exit_mode'                    => (string)$values['exit_mode'],
            'stop_floor_type'              => (string)$values['stop_floor_type'],
            'stop_floor_value'             => (float)$values['stop_floor_value'],
            'brain_may_tighten_stop'       => !empty($values['brain_may_tighten_stop']),
            'trailing_enabled'             => !empty($values['trailing_enabled']),
            'trailing_mode'                => in_array((string)($values['trailing_mode'] ?? 'roi_giveback'), ['roi_giveback', 'price_distance', 'price_distance_floor'], true) ? (string)$values['trailing_mode'] : 'roi_giveback',
            // ROI-based trailing preset mode: soft|medium|hard|custom
            'trailing_preset_mode'         => in_array((string)($values['trailing_preset_mode'] ?? 'custom'), ['soft', 'medium', 'hard', 'custom'], true) ? (string)$values['trailing_preset_mode'] : 'custom',
            // Active for price_distance + price_distance_floor modes
            'trailing_price_distance_pct'  => max(0.005, min(0.20, (float)($values['trailing_price_distance_pct'] ?? 0.02))),
            // Active for price_distance_floor mode only
            'trailing_activation_floor_roi' => (float)($values['trailing_activation_floor_roi'] ?? 4.0),
            'trailing_floor_lock_roi'      => (float)($values['trailing_floor_lock_roi'] ?? 3.0),
            'trailing_distance_roi'        => (float)($values['trailing_distance_roi'] ?? 0),
            // Profit Add-On: one-time scale-in into winning position, triggered at trailing_activation_floor_roi
            'profit_addon_enabled'         => !empty($values['profit_addon_enabled']),
            'profit_addon_budget_pct'      => max(0.0, min(500.0, (float)($values['profit_addon_budget_pct'] ?? 0.0))),
            'trailing_step_mode'           => in_array((string)($values['trailing_step_mode'] ?? 'fixed'), ['fixed', 'auto_strength', 'fixed_roi_ladder', 'trend_reversal_soft_ladder_short'], true) ? (string)$values['trailing_step_mode'] : 'fixed',
            'trailing_step_pct_min'        => max(0.001, min(0.10, (float)($values['trailing_step_pct_min'] ?? 0.005))),
            'trailing_step_pct_max'        => max(0.001, min(0.10, (float)($values['trailing_step_pct_max'] ?? 0.02))),
            'trailing_step_roi'            => max(0.1, min(20.0, (float)($values['trailing_step_roi'] ?? 1.5))),
            // Legacy: active for roi_giveback mode (kept for backward compatibility)
            'trailing_activation_roi'      => (float)$values['trailing_activation_roi'],
            'trailing_min_lock_roi'        => (float)$values['trailing_min_lock_roi'],
            'trailing_min_step'            => (float)$values['trailing_min_step'],
            'brain_may_delay_trailing'     => !empty($values['brain_may_delay_trailing']),
            'fixed_take_profit_roi'        => (float)$values['fixed_take_profit_roi'],
            'hybrid_tp_share'              => (float)$values['hybrid_tp_share'],
            // Exit Safety
            'break_even_enabled'           => !empty($values['break_even_enabled']),
            'break_even_activation_roi'    => (float)$values['break_even_activation_roi'],
            // Stop Loss Engine V2
            'stop_mode'                    => (string)$values['stop_mode'],
            'simple_stop_liq_factor'       => (float)$values['simple_stop_liq_factor'],
            'brain_stop_corridor_factor'   => (float)$values['brain_stop_corridor_factor'],
            'brain_stop_volatility_factor' => (float)$values['brain_stop_volatility_factor'],
            'brain_stop_liq_safety_factor' => (float)$values['brain_stop_liq_safety_factor'],
            // Early Failure Guard
            'early_failure_enabled'        => !empty($values['early_failure_enabled']),
            'early_failure_window_minutes' => (int)$values['early_failure_window_minutes'],
            'early_failure_max_adverse_roi' => (float)$values['early_failure_max_adverse_roi'],
            // V2 Entry Policy (profile-managed)
            'strong_confirmation_enter_now_enabled' => !empty($values['strong_confirmation_enter_now_enabled']),
            'medium_confirmation_wait_retrace_enabled' => !empty($values['medium_confirmation_wait_retrace_enabled']),
            'weak_confirmation_live_enabled' => !empty($values['weak_confirmation_live_enabled']),
            // V2 Quality Floors (profile-managed)
            'v2_hold_quality_min' => max(0.0, min(1.0, (float)($values['v2_hold_quality_min'] ?? 0.60))),
            'v2_post_reclaim_stability_min' => max(0.0, min(1.0, (float)($values['v2_post_reclaim_stability_min'] ?? 0.60))),
            'v2_zone_defense_min' => max(0.0, min(1.0, (float)($values['v2_zone_defense_min'] ?? 0.30))),
            'v2_trend_match_min' => max(0.0, min(1.0, (float)($values['v2_trend_match_min'] ?? 0.40))),
            'v2_price_position_max' => max(0.0, min(1.0, (float)($values['v2_price_position_max'] ?? 0.90))),
            // Leverage Control
            'leverage_mode'                => (string)($values['leverage_mode'] ?? 'auto'),
            'manual_leverage'              => (int)($values['manual_leverage'] ?? 3),
            // Stop Control
            'stop_control_mode'            => (string)($values['stop_control_mode'] ?? 'auto'),
            'manual_stop_loss_roi'         => (float)($values['manual_stop_loss_roi'] ?? 0.03),
            'stop_loss_from_entry_roi'     => (float)($values['stop_loss_from_entry_roi'] ?? 0.10),
            // Symbol Intelligence
            'symbol_intelligence_enabled'  => !empty($values['symbol_intelligence_enabled']),
            'symbol_filter_mode'           => (string)($values['symbol_filter_mode'] ?? 'all'),
            // Symbol Intelligence V2 — configurable thresholds
            'whitelist_min_trades'                => (int)($values['whitelist_min_trades'] ?? 5),
            'whitelist_min_winrate'               => (float)($values['whitelist_min_winrate'] ?? 0.50),
            'whitelist_min_avg_roi'               => (float)($values['whitelist_min_avg_roi'] ?? 0.0),
            'blacklist_min_trades'                => (int)($values['blacklist_min_trades'] ?? 3),
            'blacklist_max_winrate'               => (float)($values['blacklist_max_winrate'] ?? 0.30),
            'blacklist_max_early_failure_ratio'    => (float)($values['blacklist_max_early_failure_ratio'] ?? 0.60),
            'soft_whitelist_enabled'              => !empty($values['soft_whitelist_enabled']),
            'soft_whitelist_min_trades'           => (int)($values['soft_whitelist_min_trades'] ?? 1),
            'soft_whitelist_min_winrate'          => (float)($values['soft_whitelist_min_winrate'] ?? 0.50),
            'soft_whitelist_min_avg_roi'          => (float)($values['soft_whitelist_min_avg_roi'] ?? 0.005),
            'symbol_recent_window'                => (int)($values['symbol_recent_window'] ?? 5),
            // Manual Symbol Universe
            'manual_symbol_universe_enabled'      => !empty($values['manual_symbol_universe_enabled']),
            'manual_symbol_list'                  => (string)($values['manual_symbol_list'] ?? ''),
            'manual_symbol_mode'                  => (string)($values['manual_symbol_mode'] ?? 'manual_only'),
            // Live Trading Control (Brain-owned)
            'live_trading_enabled'                => !empty($values['live_trading_enabled']),
            'live_signal_selection_mode'           => (string)($values['live_signal_selection_mode'] ?? 'whitelist_only'),
            'live_max_positions'                  => (int)($values['live_max_positions'] ?? 3),
            'live_one_trade_per_symbol'           => !empty($values['live_one_trade_per_symbol'] ?? true),
            'live_entry_policy'                   => (string)($values['live_entry_policy'] ?? 'enter_now'),
            'live_reverse_side_enabled'           => !empty($values['live_reverse_side_enabled']),
        ];

        // Pattern Selection
        $patternsEnabled = [];
        if (isset($values['patterns_enabled']) && is_array($values['patterns_enabled'])) {
            $patternsEnabled = array_values(array_intersect($values['patterns_enabled'], self::ALLOWED_PATTERN_ALGORITHMS));
        }
        $patternMode = (string)($values['pattern_mode'] ?? 'any');
        if (!in_array($patternMode, ['one', 'any', 'all'], true)) {
            $patternMode = 'any';
        }
        $clean['patterns'] = [
            'enabled' => $patternsEnabled,
            'mode' => $patternMode,
        ];

        $dir = $this->moduleBase . '/runtime';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = $dir . '/user_config.json';
        $json = json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($path, $json, LOCK_EX);

        // Apply saved values immediately into config
        $this->config['risk_engine']['user_limits'] = array_merge(
            $this->config['risk_engine']['user_limits'] ?? [],
            $clean
        );

        // Apply execution profile bundle over managed fields immediately
        $this->applyExecutionProfile();

        // Apply pattern selection into parser4 config immediately
        $this->applyPatternSelection($clean['patterns']);

        // Apply profile-driven pattern routing AFTER manual patterns (overrides when profile_controlled)
        $this->applyProfilePatternRouting();

        return ['ok' => true, 'errors' => []];
    }

    /**
     * Validate user config values.
     *
     * @param array<string,mixed> $values
     * @return list<string>
     */
    public function validateUserConfig(array $values): array
    {
        $errors = [];

        // Execution Profile validation
        if (isset($values['execution_profile']) && !in_array((string)$values['execution_profile'], self::ALLOWED_EXECUTION_PROFILES, true)) {
            $errors[] = 'execution_profile must be one of: ' . implode(', ', self::ALLOWED_EXECUTION_PROFILES);
        }

        // Pattern Profile Mode validation
        if (isset($values['pattern_profile_mode']) && !in_array((string)$values['pattern_profile_mode'], ['manual_override', 'profile_controlled'], true)) {
            $errors[] = 'pattern_profile_mode must be one of: manual_override, profile_controlled';
        }

        if (!isset($values['max_budget_per_coin']) || (float)$values['max_budget_per_coin'] <= 0) {
            $errors[] = 'max_budget_per_coin must be > 0';
        }
        if (!isset($values['max_active_tasks']) || (int)$values['max_active_tasks'] < 1) {
            $errors[] = 'max_active_tasks must be >= 1';
        }
        if (!isset($values['max_leverage']) || (int)$values['max_leverage'] < 1) {
            $errors[] = 'max_leverage must be >= 1';
        }
        $validModes = ['safe', 'balanced', 'aggressive'];
        if (!isset($values['brain_mode']) || !in_array((string)$values['brain_mode'], $validModes, true)) {
            $errors[] = 'brain_mode must be one of: safe, balanced, aggressive';
        }
        if (isset($values['bootstrap_max_signals']) && (int)$values['bootstrap_max_signals'] < 0) {
            $errors[] = 'bootstrap_max_signals must be >= 0';
        }
        if (isset($values['bootstrap_budget_factor'])) {
            $f = (float)$values['bootstrap_budget_factor'];
            if ($f <= 0 || $f > 1) {
                $errors[] = 'bootstrap_budget_factor must be > 0 and <= 1';
            }
        }
        if (isset($values['bootstrap_max_leverage']) && (int)$values['bootstrap_max_leverage'] < 1) {
            $errors[] = 'bootstrap_max_leverage must be >= 1';
        }
        if (isset($values['min_reliability_after_warmup'])) {
            $r = (float)$values['min_reliability_after_warmup'];
            if ($r < 0 || $r > 1) {
                $errors[] = 'min_reliability_after_warmup must be >= 0 and <= 1';
            }
        }
        if (isset($values['warmup_min_trades']) && (int)$values['warmup_min_trades'] < 0) {
            $errors[] = 'warmup_min_trades must be >= 0';
        }

        // Exit Policy validation
        $validExitModes = ['fixed_tp', 'trailing_tp', 'hybrid'];
        if (isset($values['exit_mode']) && !in_array((string)$values['exit_mode'], $validExitModes, true)) {
            $errors[] = 'exit_mode must be one of: fixed_tp, trailing_tp, hybrid';
        }
        $validStopFloorTypes = ['roi_percent', 'corridor_percent'];
        if (isset($values['stop_floor_type']) && !in_array((string)$values['stop_floor_type'], $validStopFloorTypes, true)) {
            $errors[] = 'stop_floor_type must be one of: roi_percent, corridor_percent';
        }
        if (isset($values['stop_floor_value']) && (float)$values['stop_floor_value'] <= 0) {
            $errors[] = 'stop_floor_value must be > 0';
        }
        // Trailing validation: mode-aware
        // Legacy: trailing_activation_roi validated only for roi_giveback and price_distance modes
        $selectedTrailingMode = (string)($values['trailing_mode'] ?? 'roi_giveback');
        if (in_array($selectedTrailingMode, ['roi_giveback', 'price_distance'], true)) {
            if (isset($values['trailing_activation_roi']) && (float)$values['trailing_activation_roi'] < 0) {
                $errors[] = 'trailing_activation_roi must be >= 0';
            }
        }
        if (isset($values['trailing_mode']) && !in_array((string)$values['trailing_mode'], ['roi_giveback', 'price_distance', 'price_distance_floor'], true)) {
            $errors[] = 'trailing_mode must be roi_giveback, price_distance, or price_distance_floor';
        }
        // trailing_price_distance_pct validated only for price_distance and price_distance_floor modes
        if (in_array($selectedTrailingMode, ['price_distance', 'price_distance_floor'], true)) {
            if (isset($values['trailing_price_distance_pct'])) {
                $distVal = (float)$values['trailing_price_distance_pct'];
                if ($distVal < 0.005 || $distVal > 0.20) {
                    $errors[] = 'trailing_price_distance_pct must be between 0.005 (0.5%) and 0.20 (20%)';
                }
            }
        }
        // Validate trailing_preset_mode
        if (isset($values['trailing_preset_mode']) && !in_array((string)$values['trailing_preset_mode'], ['soft', 'medium', 'hard', 'custom'], true)) {
            $errors[] = 'trailing_preset_mode must be soft, medium, hard, or custom';
        }
        // Validate price_distance_floor specific fields
        if (isset($values['trailing_mode']) && (string)$values['trailing_mode'] === 'price_distance_floor') {
            if (isset($values['trailing_activation_floor_roi'])) {
                $actFloor = (float)$values['trailing_activation_floor_roi'];
                if ($actFloor <= 0) {
                    $errors[] = 'trailing_activation_floor_roi must be > 0';
                }
            }
            if (isset($values['trailing_floor_lock_roi'])) {
                $floorLock = (float)$values['trailing_floor_lock_roi'];
                if ($floorLock <= 0) {
                    $errors[] = 'trailing_floor_lock_roi must be > 0';
                }
                // Allow floor_lock_roi == activation_floor_roi (presets use equal values)
                if (isset($values['trailing_activation_floor_roi']) && $floorLock > (float)$values['trailing_activation_floor_roi']) {
                    $errors[] = 'trailing_floor_lock_roi must be <= trailing_activation_floor_roi';
                }
            }
            if (isset($values['trailing_step_mode']) && !in_array((string)$values['trailing_step_mode'], ['fixed', 'auto_strength', 'fixed_roi_ladder', 'trend_reversal_soft_ladder_short'], true)) {
                $errors[] = 'trailing_step_mode must be fixed, auto_strength, fixed_roi_ladder, or trend_reversal_soft_ladder_short';
            }
            if (isset($values['trailing_step_pct_min'])) {
                $stepMin = (float)$values['trailing_step_pct_min'];
                if ($stepMin <= 0) {
                    $errors[] = 'trailing_step_pct_min must be > 0';
                }
            }
            if (isset($values['trailing_step_pct_max'])) {
                $stepMax = (float)$values['trailing_step_pct_max'];
                if ($stepMax <= 0) {
                    $errors[] = 'trailing_step_pct_max must be > 0';
                }
                if (isset($values['trailing_step_pct_min']) && $stepMax < (float)$values['trailing_step_pct_min']) {
                    $errors[] = 'trailing_step_pct_max must be >= trailing_step_pct_min';
                }
            }
            if (isset($values['trailing_step_roi'])) {
                $stepRoi = (float)$values['trailing_step_roi'];
                if ($stepRoi <= 0) {
                    $errors[] = 'trailing_step_roi must be > 0';
                }
            }
        }
        // Legacy: trailing_min_lock_roi and trailing_min_step validated only for roi_giveback mode
        if ($selectedTrailingMode === 'roi_giveback') {
            if (isset($values['trailing_min_lock_roi']) && (float)$values['trailing_min_lock_roi'] < 0) {
                $errors[] = 'trailing_min_lock_roi must be >= 0';
            }
            if (isset($values['trailing_min_step']) && (float)$values['trailing_min_step'] <= 0) {
                $errors[] = 'trailing_min_step must be > 0';
            }
        }
        if (isset($values['fixed_take_profit_roi']) && (float)$values['fixed_take_profit_roi'] < 0) {
            $errors[] = 'fixed_take_profit_roi must be >= 0';
        }
        if (isset($values['hybrid_tp_share'])) {
            $h = (float)$values['hybrid_tp_share'];
            if ($h < 0 || $h > 1) {
                $errors[] = 'hybrid_tp_share must be between 0 and 1';
            }
        }

        // Exit Safety validation
        if (isset($values['break_even_activation_roi']) && (float)$values['break_even_activation_roi'] < 0) {
            $errors[] = 'break_even_activation_roi must be >= 0';
        }

        // Stop Loss Engine V2 validation
        $validStopModes = ['simple_liq_percent', 'brain_managed'];
        if (isset($values['stop_mode']) && !in_array((string)$values['stop_mode'], $validStopModes, true)) {
            $errors[] = 'stop_mode must be one of: simple_liq_percent, brain_managed';
        }
        if (isset($values['simple_stop_liq_factor']) && (float)$values['simple_stop_liq_factor'] <= 0) {
            $errors[] = 'simple_stop_liq_factor must be > 0';
        }
        if (isset($values['brain_stop_corridor_factor']) && (float)$values['brain_stop_corridor_factor'] <= 0) {
            $errors[] = 'brain_stop_corridor_factor must be > 0';
        }
        if (isset($values['brain_stop_volatility_factor']) && (float)$values['brain_stop_volatility_factor'] <= 0) {
            $errors[] = 'brain_stop_volatility_factor must be > 0';
        }
        if (isset($values['brain_stop_liq_safety_factor']) && (float)$values['brain_stop_liq_safety_factor'] <= 0) {
            $errors[] = 'brain_stop_liq_safety_factor must be > 0';
        }
        // Early Failure Guard validation
        if (isset($values['early_failure_window_minutes']) && (int)$values['early_failure_window_minutes'] < 1) {
            $errors[] = 'early_failure_window_minutes must be >= 1';
        }
        if (isset($values['early_failure_max_adverse_roi']) && (float)$values['early_failure_max_adverse_roi'] >= 0) {
            $errors[] = 'early_failure_max_adverse_roi must be < 0';
        }

        // Leverage Control validation
        $validLeverageModes = ['manual', 'auto'];
        if (isset($values['leverage_mode']) && !in_array((string)$values['leverage_mode'], $validLeverageModes, true)) {
            $errors[] = 'leverage_mode must be one of: manual, auto';
        }
        if (isset($values['manual_leverage'])) {
            $ml = (int)$values['manual_leverage'];
            if ($ml < 1) {
                $errors[] = 'manual_leverage must be >= 1';
            }
            $maxLev = (int)($values['max_leverage'] ?? 15);
            if ($ml > $maxLev) {
                $errors[] = 'manual_leverage must be <= max_leverage (' . $maxLev . ')';
            }
        }
        // Stop Control validation
        $validStopControlModes = ['manual', 'auto', 'entry_roi'];
        if (isset($values['stop_control_mode']) && !in_array((string)$values['stop_control_mode'], $validStopControlModes, true)) {
            $errors[] = 'stop_control_mode must be one of: manual, auto, entry_roi';
        }
        if (isset($values['manual_stop_loss_roi']) && (float)$values['manual_stop_loss_roi'] <= 0) {
            $errors[] = 'manual_stop_loss_roi must be > 0';
        }
        if (isset($values['stop_loss_from_entry_roi']) && ((float)$values['stop_loss_from_entry_roi'] <= 0 || (float)$values['stop_loss_from_entry_roi'] > 1.0)) {
            $errors[] = 'stop_loss_from_entry_roi must be > 0 and <= 1.0';
        }

        // Symbol Intelligence validation
        $validFilterModes = ['all', 'whitelist_only', 'exclude_blacklist', 'watchlist_only', 'soft_whitelist_only', 'whitelist_plus_soft'];
        if (isset($values['symbol_filter_mode']) && !in_array((string)$values['symbol_filter_mode'], $validFilterModes, true)) {
            $errors[] = 'symbol_filter_mode must be one of: ' . implode(', ', $validFilterModes);
        }

        // Symbol Intelligence V2 threshold validation
        if (isset($values['whitelist_min_trades']) && (int)$values['whitelist_min_trades'] < 1) {
            $errors[] = 'whitelist_min_trades must be >= 1';
        }
        if (isset($values['whitelist_min_winrate'])) {
            $wr = (float)$values['whitelist_min_winrate'];
            if ($wr < 0 || $wr > 1) {
                $errors[] = 'whitelist_min_winrate must be between 0 and 1';
            }
        }
        if (isset($values['blacklist_min_trades']) && (int)$values['blacklist_min_trades'] < 1) {
            $errors[] = 'blacklist_min_trades must be >= 1';
        }
        if (isset($values['blacklist_max_winrate'])) {
            $bw = (float)$values['blacklist_max_winrate'];
            if ($bw < 0 || $bw > 1) {
                $errors[] = 'blacklist_max_winrate must be between 0 and 1';
            }
        }
        if (isset($values['blacklist_max_early_failure_ratio'])) {
            $ef = (float)$values['blacklist_max_early_failure_ratio'];
            if ($ef < 0 || $ef > 1) {
                $errors[] = 'blacklist_max_early_failure_ratio must be between 0 and 1';
            }
        }
        if (isset($values['soft_whitelist_min_trades']) && (int)$values['soft_whitelist_min_trades'] < 1) {
            $errors[] = 'soft_whitelist_min_trades must be >= 1';
        }
        if (isset($values['soft_whitelist_min_winrate'])) {
            $sw = (float)$values['soft_whitelist_min_winrate'];
            if ($sw < 0 || $sw > 1) {
                $errors[] = 'soft_whitelist_min_winrate must be between 0 and 1';
            }
        }
        if (isset($values['symbol_recent_window']) && (int)$values['symbol_recent_window'] < 1) {
            $errors[] = 'symbol_recent_window must be >= 1';
        }

        // Manual Symbol Universe validation
        $validManualModes = ['manual_only', 'manual_plus_whitelist', 'manual_plus_soft', 'manual_exclude_blacklist'];
        if (isset($values['manual_symbol_mode']) && !in_array((string)$values['manual_symbol_mode'], $validManualModes, true)) {
            $errors[] = 'manual_symbol_mode must be one of: ' . implode(', ', $validManualModes);
        }
        if (!empty($values['manual_symbol_universe_enabled'])) {
            $rawList = trim((string)($values['manual_symbol_list'] ?? ''));
            if ($rawList === '') {
                $errors[] = 'manual_symbol_list cannot be empty when manual symbol universe is enabled';
            } else {
                require_once __DIR__ . '/symbol_intelligence.php';
                $parsed = SymbolIntelligence::parseManualSymbolList($rawList);
                if (empty($parsed)) {
                    $errors[] = 'manual_symbol_list must contain at least one valid symbol';
                }
            }
        }

        // Config Conflict Guard: warn about conflicting filter combinations at save time
        // This is a warning, not a blocking error — the runtime guard handles the precedence.
        // The conflict message is stored alongside the config for UI display.
        // (Save is NOT blocked, but the user sees a clear notice.)

        // Live Trading Control validation
        $validLiveSelectionModes = ['all', 'whitelist_only', 'soft_whitelist_only', 'whitelist_plus_soft', 'manual_only', 'manual_plus_soft', 'manual_plus_whitelist', 'watchlist_only'];
        if (isset($values['live_signal_selection_mode']) && !in_array((string)$values['live_signal_selection_mode'], $validLiveSelectionModes, true)) {
            $errors[] = 'live_signal_selection_mode must be one of: ' . implode(', ', $validLiveSelectionModes);
        }
        if (isset($values['live_max_positions']) && (int)$values['live_max_positions'] < 1) {
            $errors[] = 'live_max_positions must be >= 1';
        }
        $validEntryPolicies = ['enter_now', 'wait_retrace'];
        if (isset($values['live_entry_policy']) && !in_array((string)$values['live_entry_policy'], $validEntryPolicies, true)) {
            $errors[] = 'live_entry_policy must be one of: ' . implode(', ', $validEntryPolicies);
        }

        // Pattern Selection validation
        $patternsProvided = isset($values['patterns_enabled']) && is_array($values['patterns_enabled']) ? $values['patterns_enabled'] : [];
        if (empty($patternsProvided)) {
            $errors[] = 'Нужно выбрать хотя бы один алгоритм анализа.';
        } else {
            $invalid = array_diff($patternsProvided, self::ALLOWED_PATTERN_ALGORITHMS);
            if (!empty($invalid)) {
                $errors[] = 'patterns_enabled contains invalid algorithm(s): ' . implode(', ', $invalid);
            }
        }
        $validPatternModes = ['one', 'any', 'all'];
        if (isset($values['pattern_mode']) && !in_array((string)$values['pattern_mode'], $validPatternModes, true)) {
            $errors[] = 'pattern_mode must be one of: one, any, all';
        }

        return $errors;
    }

    /**
     * Detect config conflict warnings for the current user config.
     * Config Conflict Guard V2: extended detection, real JSON parsing, no fragile checks.
     * These are non-blocking warnings about potentially problematic filter combinations.
     *
     * @return list<string>
     */
    public function detectConfigConflicts(): array
    {
        $warnings = [];
        $userLimits = $this->getUserLimits();

        $manualEnabled  = (bool)($userLimits['manual_symbol_universe_enabled'] ?? false);
        $manualMode     = (string)($userLimits['manual_symbol_mode'] ?? 'manual_only');
        $intelEnabled   = (bool)($userLimits['symbol_intelligence_enabled'] ?? false);
        $filterMode     = (string)($userLimits['symbol_filter_mode'] ?? 'all');

        $restrictiveModes = ['whitelist_only', 'soft_whitelist_only', 'whitelist_plus_soft', 'watchlist_only'];

        // (A) manual_only + empty manual list
        if ($manualEnabled && $manualMode === 'manual_only') {
            $rawList = trim((string)($userLimits['manual_symbol_list'] ?? ''));
            if ($rawList === '') {
                $warnings[] = 'Конфликт конфигурации: manual_only включён, но manual_symbol_list пуст — кандидаты будут полностью отфильтрованы.';
            }
        }

        // (B) manual_plus_soft with both sources empty
        if ($manualEnabled && $manualMode === 'manual_plus_soft') {
            $rawList = trim((string)($userLimits['manual_symbol_list'] ?? ''));
            $softEmpty = $this->isSymbolListEmpty('soft_whitelist.json');
            if ($rawList === '' && $softEmpty) {
                $warnings[] = 'Конфликт конфигурации: manual_plus_soft включён, но пусты и manual_symbol_list, и soft_whitelist — кандидаты будут полностью отфильтрованы.';
            }
        }

        // (G) cross-filter conflict: manual_only + restrictive SI + empty backing lists
        if ($manualEnabled && $manualMode === 'manual_only'
            && $intelEnabled && in_array($filterMode, $restrictiveModes, true)
        ) {
            $warnings[] = 'Конфликт фильтров: включён manual_only и одновременно активен режим '
                . $filterMode . '. Режим manual_only является терминальным ограничением — '
                . 'Symbol Intelligence фильтр будет пропущен при выполнении. '
                . 'Рекомендуется отключить Symbol Intelligence или изменить manual_symbol_mode.';
        }

        // Symbol intelligence restrictive mode with empty lists
        if ($intelEnabled && in_array($filterMode, $restrictiveModes, true)) {
            // Skip this check if manual_only conflict is already detected
            if (!($manualEnabled && $manualMode === 'manual_only')) {
                $emptyList = false;

                // (C) whitelist_only with empty whitelist
                if ($filterMode === 'whitelist_only') {
                    $emptyList = $this->isSymbolListEmpty('whitelist.json');
                    if ($emptyList) {
                        $warnings[] = 'Конфликт конфигурации: whitelist_only включён, но whitelist пуст — кандидаты будут полностью отфильтрованы.';
                    }
                }
                // (D) soft_whitelist_only with empty soft list
                elseif ($filterMode === 'soft_whitelist_only') {
                    $emptyList = $this->isSymbolListEmpty('soft_whitelist.json');
                    if ($emptyList) {
                        $warnings[] = 'Конфликт конфигурации: soft_whitelist_only включён, но soft_whitelist пуст — кандидаты будут полностью отфильтрованы.';
                    }
                }
                // (E) whitelist_plus_soft with both lists empty
                elseif ($filterMode === 'whitelist_plus_soft') {
                    $emptyList = $this->isSymbolListEmpty('whitelist.json') && $this->isSymbolListEmpty('soft_whitelist.json');
                    if ($emptyList) {
                        $warnings[] = 'Конфликт конфигурации: whitelist_plus_soft включён, но пусты и whitelist, и soft_whitelist — кандидаты будут полностью отфильтрованы.';
                    }
                }
                // (F) watchlist_only with empty watchlist
                elseif ($filterMode === 'watchlist_only') {
                    $emptyList = $this->isSymbolListEmpty('watchlist.json');
                    if ($emptyList) {
                        $warnings[] = 'Конфликт конфигурации: watchlist_only включён, но watchlist пуст — кандидаты будут полностью отфильтрованы.';
                    }
                }
            }
        }

        return self::uniqueWarnings($warnings);
    }

    /**
     * Load a symbol list JSON file safely.
     * Returns decoded array or empty array on failure.
     *
     * @param string $filename Filename relative to storage/
     * @return array{list:list<mixed>,valid:bool,warning:string}
     */
    public function loadSymbolListJson(string $filename): array
    {
        $path = $this->moduleBase . '/storage/' . $filename;
        if (!is_file($path)) {
            return ['list' => [], 'valid' => true, 'warning' => ''];
        }
        $content = @file_get_contents($path);
        if ($content === false) {
            return ['list' => [], 'valid' => false, 'warning' => "Не удалось прочитать файл {$filename}."];
        }
        $decoded = @json_decode($content, true);
        if (!is_array($decoded)) {
            return ['list' => [], 'valid' => false, 'warning' => "Файл {$filename} содержит некорректный JSON и был интерпретирован как пустой список."];
        }
        return ['list' => $decoded, 'valid' => true, 'warning' => ''];
    }

    /**
     * Check if a symbol list JSON file is empty (real JSON parsing, not string guessing).
     *
     * @param string $filename Filename relative to storage/
     * @return bool true if list is empty or file is missing/invalid
     */
    public function isSymbolListEmpty(string $filename): bool
    {
        $result = $this->loadSymbolListJson($filename);
        return count($result['list']) === 0;
    }

    // =========================================================================
    // Manual Live Blacklist — authoritative manual symbol blocking for live pipeline
    // =========================================================================

    private const MANUAL_BLACKLIST_FILENAME = 'manual_live_blacklist.json';

    /**
     * Load manual blacklist from storage.
     * Returns normalized, deduplicated, uppercase array of symbol strings.
     * On missing file: creates empty default [].
     * On invalid JSON: fallback to [], set warning flag.
     *
     * @return array{symbols:list<string>,count:int,valid:bool,warning:string}
     */
    public function loadManualBlacklist(): array
    {
        $path = $this->moduleBase . '/storage/' . self::MANUAL_BLACKLIST_FILENAME;

        // If file missing, create empty default
        if (!is_file($path)) {
            @file_put_contents($path, "[\n]\n");
            return ['symbols' => [], 'count' => 0, 'valid' => true, 'warning' => 'manual_blacklist_empty_ok'];
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            return ['symbols' => [], 'count' => 0, 'valid' => false, 'warning' => 'manual_blacklist_load_failed'];
        }

        $decoded = @json_decode($content, true);
        if (!is_array($decoded)) {
            return ['symbols' => [], 'count' => 0, 'valid' => false, 'warning' => 'manual_blacklist_invalid_json'];
        }

        // Normalize: uppercase, trim, dedupe, filter empty
        $symbols = [];
        $seen = [];
        foreach ($decoded as $item) {
            if (!is_string($item)) {
                continue;
            }
            $normalized = strtoupper(trim($item));
            if ($normalized === '') {
                continue;
            }
            if (isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;
            $symbols[] = $normalized;
        }

        return [
            'symbols' => $symbols,
            'count' => count($symbols),
            'valid' => true,
            'warning' => count($symbols) === 0 ? 'manual_blacklist_empty_ok' : '',
        ];
    }

    /**
     * Save manual blacklist to storage.
     * Normalizes, deduplicates, and writes clean JSON.
     *
     * @param list<string> $symbols Raw symbol list (will be normalized)
     * @return array{ok:bool,count:int,symbols:list<string>}
     */
    public function saveManualBlacklist(array $symbols): array
    {
        // Normalize: uppercase, trim, dedupe, filter empty
        $normalized = [];
        $seen = [];
        foreach ($symbols as $item) {
            if (!is_string($item)) {
                continue;
            }
            $clean = strtoupper(trim($item));
            if ($clean === '') {
                continue;
            }
            if (isset($seen[$clean])) {
                continue;
            }
            $seen[$clean] = true;
            $normalized[] = $clean;
        }

        sort($normalized);

        $path = $this->moduleBase . '/storage/' . self::MANUAL_BLACKLIST_FILENAME;
        $json = json_encode(array_values($normalized), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $ok = @file_put_contents($path, $json . "\n") !== false;

        return ['ok' => $ok, 'count' => count($normalized), 'symbols' => $normalized];
    }

    /**
     * Check if a symbol is in the manual blacklist.
     *
     * @param string $symbol Symbol to check (will be uppercased)
     * @param list<string> $blacklist Pre-loaded normalized blacklist
     * @return bool
     */
    public static function isSymbolManuallyBlacklisted(string $symbol, array $blacklist): bool
    {
        if (empty($blacklist)) {
            return false;
        }
        return in_array(strtoupper(trim($symbol)), $blacklist, true);
    }

    /**
     * Build manual blacklist snapshot for effective config.
     *
     * @return array<string,mixed>
     */
    private function buildManualBlacklistSnapshot(): array
    {
        $data = $this->loadManualBlacklist();
        return [
            'manual_blacklist_enabled' => true,
            'manual_blacklist_symbols' => $data['symbols'],
            'manual_blacklist_count' => $data['count'],
            'blacklist_source' => 'manual_live_blacklist',
            'blacklist_source_file' => 'storage/' . self::MANUAL_BLACKLIST_FILENAME,
            'blacklist_valid' => $data['valid'],
            'blacklist_warning' => $data['warning'],
        ];
    }

    /**
     * Deduplicate warnings by normalized text.
     *
     * @param list<string> $warnings
     * @return list<string>
     */
    public static function uniqueWarnings(array $warnings): array
    {
        $seen = [];
        $unique = [];
        foreach ($warnings as $w) {
            $key = mb_strtolower(trim($w));
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $w;
            }
        }
        return $unique;
    }

    /**
     * Build the effective live trading config for Brain→Bot contract.
     *
     * @return array<string,mixed>
     */
    public function buildLiveConfig(): array
    {
        $userLimits = $this->getUserLimits();
        $trailingMode = (string)($userLimits['trailing_mode'] ?? 'roi_giveback');

        // Build mode-specific active trailing contract (only active mode's fields)
        $activeContract = $this->buildActiveTrailingContract($trailingMode, $userLimits);
        // Non-trailing exit fields added to contract for consumer convenience
        $activeContract['exit_mode'] = (string)($userLimits['exit_mode'] ?? 'fixed_tp');
        $activeContract['fixed_take_profit_roi'] = (float)($userLimits['fixed_take_profit_roi'] ?? 0.05);
        $activeContract['hybrid_tp_share'] = (float)($userLimits['hybrid_tp_share'] ?? 0.5);
        $activeContract['stop_control_mode'] = (string)($userLimits['stop_control_mode'] ?? 'auto');
        $activeContract['manual_stop_loss_roi'] = (float)($userLimits['manual_stop_loss_roi'] ?? 0.03);
        $activeContract['stop_loss_from_entry_roi'] = (float)($userLimits['stop_loss_from_entry_roi'] ?? 0.10);

        // Legacy fields preserved separately for backward compatibility
        $legacyFields = $this->buildLegacyTrailingFields($trailingMode, $userLimits);

        return [
            'live_trading_enabled' => (bool)($userLimits['live_trading_enabled'] ?? false),
            'live_signal_selection_mode' => (string)($userLimits['live_signal_selection_mode'] ?? 'whitelist_only'),
            'live_max_positions' => (int)($userLimits['live_max_positions'] ?? 3),
            'live_one_trade_per_symbol' => (bool)($userLimits['live_one_trade_per_symbol'] ?? true),
            'live_entry_policy' => (string)($userLimits['live_entry_policy'] ?? 'enter_now'),
            'live_reverse_side_enabled' => (bool)($userLimits['live_reverse_side_enabled'] ?? false),
            'trailing_contract' => $activeContract,
            'legacy_trailing_fields' => $legacyFields,
            'leverage_mode' => (string)($userLimits['leverage_mode'] ?? 'auto'),
            'manual_leverage' => (int)($userLimits['manual_leverage'] ?? 3),
            'max_leverage' => (int)($userLimits['max_leverage'] ?? 5),
        ];
    }

    /**
     * Check if Brain-controlled live mode is active.
     * This is determined from user config, NOT from whether live_intents.json loaded successfully.
     *
     * @return bool
     */
    public function isBrainControlledLiveMode(): bool
    {
        $userLimits = $this->getUserLimits();
        return (bool)($userLimits['live_trading_enabled'] ?? false);
    }

    /**
     * Compute brain auto (derived) values from current config state.
     *
     * @return array<string,mixed>
     */
    public function getBrainAutoValues(): array
    {
        $corridorCfg = $this->getEffective('corridor');
        $riskCfg = $this->getEffective('risk_engine');
        $profilesCfg = $this->getEffective('profiles');
        $userLimits = $this->getUserLimits();

        $profileKey = (string)($profilesCfg['default_profile'] ?? '111');
        $profile = (array)($profilesCfg['profiles'][$profileKey] ?? []);

        $parser4Cfg = $this->getEffective('parser4');

        return [
            'effective_strength_threshold' => (float)($parser4Cfg['strength_threshold'] ?? 0.50),
            'effective_entry_zone_percent' => (float)($corridorCfg['entry_zone_percent'] ?? 0.20),
            'effective_budget_scaler' => (float)($profile['budget'] ?? 15.0),
            'effective_leverage_scaler' => (int)($profile['max_leverage'] ?? 5),
            'effective_reliability_gate' => (float)($userLimits['min_reliability_after_warmup'] ?? 0.15),
        ];
    }

    /**
     * Get the list of active trailing field names for the given mode.
     *
     * @param string $trailingMode One of: roi_giveback, price_distance, price_distance_floor
     * @return list<string>
     */
    private function getActiveTrailingFields(string $trailingMode): array
    {
        $shared = ['trailing_enabled', 'trailing_mode'];
        switch ($trailingMode) {
            case 'price_distance_floor':
                return array_merge($shared, [
                    'trailing_price_distance_pct',
                    'trailing_activation_floor_roi',
                    'trailing_floor_lock_roi',
                    'trailing_step_mode',
                    'trailing_step_pct_min',
                    'trailing_step_pct_max',
                    'trailing_step_roi',
                ]);
            case 'price_distance':
                return array_merge($shared, [
                    'trailing_price_distance_pct',
                    'trailing_activation_roi',
                ]);
            case 'roi_giveback':
            default:
                return array_merge($shared, [
                    'trailing_activation_roi',
                    'trailing_min_lock_roi',
                    'trailing_min_step',
                    'brain_may_delay_trailing',
                ]);
        }
    }

    /**
     * Check if legacy trailing fields (roi_giveback-specific) are present but inactive.
     *
     * @param string $trailingMode Current trailing mode
     * @param array<string,mixed> $userLimits User config values
     * @return bool True if legacy giveback fields exist but mode is not roi_giveback
     */
    private function hasLegacyTrailingFields(string $trailingMode, array $userLimits): bool
    {
        if ($trailingMode === 'roi_giveback') {
            return false;
        }
        return isset($userLimits['trailing_activation_roi'])
            || isset($userLimits['trailing_min_lock_roi'])
            || isset($userLimits['trailing_min_step']);
    }

    /**
     * Build the active trailing contract containing ONLY fields relevant to the selected mode.
     * This is the canonical active trailing truth — no legacy/inactive fields mixed in.
     *
     * @param string $trailingMode Selected trailing mode
     * @param array<string,mixed> $userLimits User config values
     * @return array<string,mixed> Active trailing contract
     */
    private function buildActiveTrailingContract(string $trailingMode, array $userLimits): array
    {
        $contract = [
            'trailing_mode' => $trailingMode,
            'trailing_enabled' => (bool)($userLimits['trailing_enabled'] ?? false),
            'break_even_enabled' => (bool)($userLimits['break_even_enabled'] ?? false),
            'break_even_activation_roi' => (float)($userLimits['break_even_activation_roi'] ?? 0.01),
        ];

        switch ($trailingMode) {
            case 'price_distance_floor':
                $contract['trailing_activation_floor_roi'] = (float)($userLimits['trailing_activation_floor_roi'] ?? 4.0);
                $contract['trailing_floor_lock_roi'] = (float)($userLimits['trailing_floor_lock_roi'] ?? 3.0);
                $contract['trailing_price_distance_pct'] = (float)($userLimits['trailing_price_distance_pct'] ?? 0.02);
                $contract['trailing_step_mode'] = (string)($userLimits['trailing_step_mode'] ?? 'fixed');
                $contract['trailing_step_pct_min'] = (float)($userLimits['trailing_step_pct_min'] ?? 0.005);
                $contract['trailing_step_pct_max'] = (float)($userLimits['trailing_step_pct_max'] ?? 0.02);
                $contract['trailing_step_roi'] = max(0.1, (float)($userLimits['trailing_step_roi'] ?? 1.5));
                break;
            case 'price_distance':
                $contract['trailing_activation_roi'] = (float)($userLimits['trailing_activation_roi'] ?? 0.02);
                $contract['trailing_price_distance_pct'] = (float)($userLimits['trailing_price_distance_pct'] ?? 0.02);
                break;
            case 'roi_giveback':
            default:
                $contract['trailing_activation_roi'] = (float)($userLimits['trailing_activation_roi'] ?? 0.02);
                $contract['trailing_min_lock_roi'] = (float)($userLimits['trailing_min_lock_roi'] ?? 0.005);
                $contract['trailing_min_step'] = (float)($userLimits['trailing_min_step'] ?? 0.005);
                $contract['brain_may_delay_trailing'] = (bool)($userLimits['brain_may_delay_trailing'] ?? false);
                break;
        }

        return $contract;
    }

    /**
     * Build legacy trailing fields block for backward compatibility.
     * Contains only fields from INACTIVE modes, preserved for old config compat.
     * Returns empty array if no legacy fields are present.
     *
     * @param string $trailingMode Current active trailing mode
     * @param array<string,mixed> $userLimits User config values
     * @return array<string,mixed> Legacy fields (empty if none or if mode is roi_giveback)
     */
    private function buildLegacyTrailingFields(string $trailingMode, array $userLimits): array
    {
        $legacy = [];

        if ($trailingMode !== 'roi_giveback') {
            // Legacy: roi_giveback fields preserved for backward compatibility
            if (isset($userLimits['trailing_activation_roi'])) {
                $legacy['trailing_activation_roi'] = (float)$userLimits['trailing_activation_roi'];
            }
            if (isset($userLimits['trailing_min_lock_roi'])) {
                $legacy['trailing_min_lock_roi'] = (float)$userLimits['trailing_min_lock_roi'];
            }
            if (isset($userLimits['trailing_min_step'])) {
                $legacy['trailing_min_step'] = (float)$userLimits['trailing_min_step'];
            }
            if (isset($userLimits['brain_may_delay_trailing'])) {
                $legacy['brain_may_delay_trailing'] = (bool)$userLimits['brain_may_delay_trailing'];
            }
        }

        if ($trailingMode !== 'price_distance_floor' && $trailingMode !== 'price_distance') {
            // Legacy: price_distance / floor fields preserved for backward compatibility
            if (isset($userLimits['trailing_price_distance_pct'])) {
                $legacy['trailing_price_distance_pct'] = (float)$userLimits['trailing_price_distance_pct'];
            }
            if (isset($userLimits['trailing_activation_floor_roi'])) {
                $legacy['trailing_activation_floor_roi'] = (float)$userLimits['trailing_activation_floor_roi'];
            }
            if (isset($userLimits['trailing_floor_lock_roi'])) {
                $legacy['trailing_floor_lock_roi'] = (float)$userLimits['trailing_floor_lock_roi'];
            }
            if (isset($userLimits['trailing_step_mode'])) {
                $legacy['trailing_step_mode'] = (string)$userLimits['trailing_step_mode'];
            }
            if (isset($userLimits['trailing_step_pct_min'])) {
                $legacy['trailing_step_pct_min'] = (float)$userLimits['trailing_step_pct_min'];
            }
            if (isset($userLimits['trailing_step_pct_max'])) {
                $legacy['trailing_step_pct_max'] = (float)$userLimits['trailing_step_pct_max'];
            }
            if (isset($userLimits['trailing_step_roi'])) {
                $legacy['trailing_step_roi'] = (float)$userLimits['trailing_step_roi'];
            }
        }

        return $legacy;
    }

    /**
     * Build full effective config snapshot for runtime output.
     *
     * @return array<string,mixed>
     */
    public function buildEffectiveSnapshot(): array
    {
        $userLimits = $this->getUserLimits();
        $trailingMode = (string)($userLimits['trailing_mode'] ?? 'roi_giveback');

        // Determine which trailing fields are active vs legacy based on mode
        $activeTrailingFields = $this->getActiveTrailingFields($trailingMode);
        $legacyFieldsPresent = $this->hasLegacyTrailingFields($trailingMode, $userLimits);

        // Build structurally separated trailing contract: active vs legacy
        $activeTrailingContract = $this->buildActiveTrailingContract($trailingMode, $userLimits);
        $legacyTrailingFields = $this->buildLegacyTrailingFields($trailingMode, $userLimits);

        return [
            'user_limits' => $userLimits,
            'brain_auto' => $this->getBrainAutoValues(),
            'exit_policy' => [
                'exit_mode' => $userLimits['exit_mode'] ?? 'fixed_tp',
                'stop_floor_type' => $userLimits['stop_floor_type'] ?? 'roi_percent',
                'stop_floor_value' => (float)($userLimits['stop_floor_value'] ?? 0.03),
                'brain_may_tighten_stop' => (bool)($userLimits['brain_may_tighten_stop'] ?? true),
                'trailing_mode' => $trailingMode,
                // Structurally separated: active trailing contract (only current mode's fields)
                'active_trailing_contract' => $activeTrailingContract,
                // Mode-aware metadata
                'active_trailing_fields' => $activeTrailingFields,
                'legacy_trailing_fields_present' => $legacyFieldsPresent,
                // Legacy trailing fields preserved for backward compatibility (inactive mode's fields)
                'legacy_trailing_fields' => $legacyTrailingFields,
                // Non-trailing exit fields
                'fixed_take_profit_roi' => (float)($userLimits['fixed_take_profit_roi'] ?? 0.05),
                'hybrid_tp_share' => (float)($userLimits['hybrid_tp_share'] ?? 0.5),
            ],
            'exit_safety' => [
                'break_even_enabled' => (bool)($userLimits['break_even_enabled'] ?? false),
                'break_even_activation_roi' => (float)($userLimits['break_even_activation_roi'] ?? 0.01),
            ],
            'stop_engine' => [
                'stop_mode' => (string)($userLimits['stop_mode'] ?? 'brain_managed'),
                'simple_stop_liq_factor' => (float)($userLimits['simple_stop_liq_factor'] ?? 0.15),
                'brain_stop_corridor_factor' => (float)($userLimits['brain_stop_corridor_factor'] ?? 0.25),
                'brain_stop_volatility_factor' => (float)($userLimits['brain_stop_volatility_factor'] ?? 0.50),
                'brain_stop_liq_safety_factor' => (float)($userLimits['brain_stop_liq_safety_factor'] ?? 0.30),
            ],
            'early_failure' => [
                'early_failure_enabled' => (bool)($userLimits['early_failure_enabled'] ?? false),
                'early_failure_window_minutes' => (int)($userLimits['early_failure_window_minutes'] ?? 5),
                'early_failure_max_adverse_roi' => (float)($userLimits['early_failure_max_adverse_roi'] ?? -0.008),
            ],
            'leverage_control' => [
                'leverage_mode' => (string)($userLimits['leverage_mode'] ?? 'auto'),
                'manual_leverage' => (int)($userLimits['manual_leverage'] ?? 3),
            ],
            'stop_control' => [
                'stop_control_mode' => (string)($userLimits['stop_control_mode'] ?? 'auto'),
                'manual_stop_loss_roi' => (float)($userLimits['manual_stop_loss_roi'] ?? 0.03),
                'stop_loss_from_entry_roi' => (float)($userLimits['stop_loss_from_entry_roi'] ?? 0.10),
            ],
            'symbol_intelligence' => [
                'symbol_intelligence_enabled' => (bool)($userLimits['symbol_intelligence_enabled'] ?? false),
                'symbol_filter_mode' => (string)($userLimits['symbol_filter_mode'] ?? 'all'),
                'whitelist_min_trades' => (int)($userLimits['whitelist_min_trades'] ?? 5),
                'whitelist_min_winrate' => (float)($userLimits['whitelist_min_winrate'] ?? 0.50),
                'whitelist_min_avg_roi' => (float)($userLimits['whitelist_min_avg_roi'] ?? 0.0),
                'blacklist_min_trades' => (int)($userLimits['blacklist_min_trades'] ?? 3),
                'blacklist_max_winrate' => (float)($userLimits['blacklist_max_winrate'] ?? 0.30),
                'blacklist_max_early_failure_ratio' => (float)($userLimits['blacklist_max_early_failure_ratio'] ?? 0.60),
                'soft_whitelist_enabled' => (bool)($userLimits['soft_whitelist_enabled'] ?? true),
                'soft_whitelist_min_trades' => (int)($userLimits['soft_whitelist_min_trades'] ?? 1),
                'soft_whitelist_min_winrate' => (float)($userLimits['soft_whitelist_min_winrate'] ?? 0.50),
                'soft_whitelist_min_avg_roi' => (float)($userLimits['soft_whitelist_min_avg_roi'] ?? 0.005),
                'symbol_recent_window' => (int)($userLimits['symbol_recent_window'] ?? 5),
            ],
            'manual_symbol_universe' => [
                'manual_symbol_universe_enabled' => (bool)($userLimits['manual_symbol_universe_enabled'] ?? false),
                'manual_symbol_list' => (string)($userLimits['manual_symbol_list'] ?? ''),
                'manual_symbol_mode' => (string)($userLimits['manual_symbol_mode'] ?? 'manual_only'),
            ],
            'manual_blacklist' => $this->buildManualBlacklistSnapshot(),
            'live_trading' => [
                'live_trading_enabled' => (bool)($userLimits['live_trading_enabled'] ?? false),
                'live_signal_selection_mode' => (string)($userLimits['live_signal_selection_mode'] ?? 'whitelist_only'),
                'live_max_positions' => (int)($userLimits['live_max_positions'] ?? 3),
                'live_one_trade_per_symbol' => (bool)($userLimits['live_one_trade_per_symbol'] ?? true),
                'live_entry_policy' => (string)($userLimits['live_entry_policy'] ?? 'enter_now'),
                'live_reverse_side_enabled' => (bool)($userLimits['live_reverse_side_enabled'] ?? false),
                // Normalized: canonical trailing contract is in exit_policy.active_trailing_contract.
                // This summary references the active contract only — no legacy duplication.
                'live_trailing_contract' => [
                    'canonical_source' => 'exit_policy.active_trailing_contract',
                    'trailing_mode' => $trailingMode,
                    'trailing_enabled' => (bool)($userLimits['trailing_enabled'] ?? false),
                    'active_trailing_fields' => $activeTrailingFields,
                    'break_even_enabled' => (bool)($userLimits['break_even_enabled'] ?? false),
                    'exit_mode' => (string)($userLimits['exit_mode'] ?? 'fixed_tp'),
                    'legacy_trailing_fields_present' => $legacyFieldsPresent,
                ],
            ],
            'pattern_selection' => [
                'enabled' => (array)(($this->config['parser4']['pattern_algorithms'] ?? [])['enabled'] ?? []),
                'mode' => (string)(($this->config['parser4']['pattern_algorithms'] ?? [])['mode'] ?? 'one'),
            ],
            'parser4' => $this->get('parser4', []),
            'corridor' => $this->getEffective('corridor'),
            'risk_engine' => $this->getEffective('risk_engine'),
            'execution_profile' => $this->buildExecutionProfileSnapshot(),
            'profiles' => $this->getEffective('profiles'),
            'simulator' => $this->getEffective('simulator'),
            'ui' => $this->getEffective('ui'),
            'config_source_status' => empty($this->configMigrationStatus)
                ? ['source' => 'legacy_user_config', 'unified_config_available' => false]
                : $this->configMigrationStatus,
            'generated_at' => date('c'),
        ];
    }

    /**
     * Build the execution profile section for effective config snapshot.
     *
     * @return array<string,mixed>
     */
    private function buildExecutionProfileSnapshot(): array
    {
        $userLimits = $this->getUserLimits();
        $profileId = (string)($userLimits['execution_profile'] ?? 'custom');
        $bundles = self::getExecutionProfileBundles();
        $bundle = $bundles[$profileId] ?? $bundles['custom'];
        $patternProfileMode = (string)($userLimits['pattern_profile_mode'] ?? 'manual_override');

        // Resolve active pattern routing
        $routingBundles = self::getProfilePatternRoutingBundles();
        $routingBundle = $routingBundles[$profileId] ?? $routingBundles['custom'];

        if ($profileId === 'custom' || $patternProfileMode !== 'profile_controlled') {
            // Manual mode: derive from current parser4 enabled patterns
            $enabledPatterns = (array)(($this->config['parser4']['pattern_algorithms'] ?? [])['enabled'] ?? []);
            $fallbackUsed = false;
            $fallbackReason = '';

            // If manual override yields empty patterns and a profile preset exists, use profile as fallback
            if (empty($enabledPatterns) && $profileId !== 'custom' && isset($routingBundles[$profileId])) {
                $enabledPatterns = (array)($routingBundles[$profileId]['live_patterns'] ?? []);
                $fallbackUsed = true;
                $fallbackReason = 'manual_override_missing_lists';
            }

            $activePatternPolicy = [
                'live_patterns' => array_values($enabledPatterns),
                'shadow_patterns' => $fallbackUsed ? array_values((array)($routingBundles[$profileId]['shadow_patterns'] ?? [])) : [],
                'disabled_patterns' => array_values(array_diff(self::ALLOWED_PATTERN_ALGORITHMS, $enabledPatterns)),
                'canonical_source' => $fallbackUsed ? 'profile_fallback' : 'manual_override',
                'fallback_used' => $fallbackUsed,
                'fallback_reason' => $fallbackReason,
            ];
        } else {
            $activePatternPolicy = [
                'live_patterns' => array_values((array)($routingBundle['live_patterns'] ?? [])),
                'shadow_patterns' => array_values((array)($routingBundle['shadow_patterns'] ?? [])),
                'disabled_patterns' => array_values((array)($routingBundle['disabled_patterns'] ?? [])),
                'canonical_source' => 'execution_profile',
                'fallback_used' => false,
                'fallback_reason' => '',
            ];
        }

        return [
            'execution_profile' => $profileId,
            'execution_profile_label' => $bundle['label'],
            'execution_profile_description' => $bundle['description'],
            'execution_profile_mode' => $profileId === 'custom' ? 'custom' : 'preset',
            'active_profile_managed_fields' => self::getProfileManagedFields(),
            'active_values' => $this->getActiveProfileManagedValues(),
            'pattern_profile_mode' => $patternProfileMode,
            'pattern_policy' => $activePatternPolicy,
        ];
    }

    /**
     * Get the current active values for all profile-managed fields.
     *
     * @return array<string,mixed>
     */
    private function getActiveProfileManagedValues(): array
    {
        $userLimits = $this->getUserLimits();
        $result = [];
        foreach (self::getProfileManagedFields() as $field) {
            $result[$field] = $userLimits[$field] ?? null;
        }
        return $result;
    }

    /**
     * Get the list of field names managed by execution profiles.
     * These fields are overwritten when a non-custom profile is selected.
     *
     * @return list<string>
     */
    public static function getProfileManagedFields(): array
    {
        return [
            'v2_confirmation_weak_max',
            'v2_confirmation_strong_min',
            'v2_zone_widen_weak_pct',
            'v2_zone_widen_medium_pct',
            'v2_zone_widen_strong_pct',
            'v2_zone_widen_max_cap_pct',
            'strong_confirmation_enter_now_enabled',
            'medium_confirmation_wait_retrace_enabled',
            'weak_confirmation_live_enabled',
            'v2_hold_quality_min',
            'v2_post_reclaim_stability_min',
            'v2_zone_defense_min',
            'v2_trend_match_min',
            'v2_price_position_max',
            // V3-specific entry policy fields
            'v3_strong_enter_now_enabled',
            'v3_zone_widen_weak_pct',
            'v3_zone_widen_medium_pct',
            'v3_zone_widen_strong_pct',
            'v3_zone_widen_max_cap_pct',
            // Sniper V3 Live Filters
            'sniper_v3_live_filter_enabled',
            'sniper_v3_min_confirmation_score',
            'sniper_v3_min_pattern_confidence',
            'sniper_v3_min_trend_match_score',
            'sniper_v3_min_entry_quality_score',
            'sniper_v3_min_corridor_fit_score',
            'sniper_v3_max_price_position',
            'sniper_v3_min_reclaim_strength_score',
            'sniper_v3_min_hold_quality_score',
            'sniper_v3_min_post_reclaim_stability_score',
            'sniper_v3_min_zone_defense_score',
            // Short-side V3 overrides (softer than default)
            'sniper_v3_min_trend_match_score_short',
            'sniper_v3_min_entry_quality_score_short',
            'sniper_v3_min_corridor_fit_score_short',
            // Long-side V3 overrides (symmetric relaxation for double_bottom_contextual_v3)
            'sniper_v3_min_trend_match_score_long',
            'sniper_v3_min_entry_quality_score_long',
            'sniper_v3_min_corridor_fit_score_long',
            // V2 Live Quality Floor
            'v2_live_quality_floor_enabled',
            'v2_live_min_confirmation_score',
            'v2_live_min_pattern_confidence',
            'v2_live_min_trend_match_score',
            'v2_live_min_trend_match_score_short',
        ];
    }

    /**
     * Get execution profile bundle definitions.
     *
     * Each bundle contains:
     *   - label: human-readable name
     *   - description: what the profile does
     *   - values: managed field values (empty for custom)
     *
     * @return array<string,array{label:string,description:string,values:array<string,float>}>
     */
    public static function getExecutionProfileBundles(): array
    {
        return [
            'balanced' => [
                'label' => 'Balanced',
                'description' => 'Standard working profile balancing signal count and quality. Medium confirmations allowed, moderate zone widening.',
                'values' => [
                    'v2_confirmation_weak_max' => 0.45,
                    'v2_confirmation_strong_min' => 0.75,
                    'v2_zone_widen_weak_pct' => 0.50,
                    'v2_zone_widen_medium_pct' => 0.65,
                    'v2_zone_widen_strong_pct' => 0.80,
                    'v2_zone_widen_max_cap_pct' => 0.85,
                    'strong_confirmation_enter_now_enabled' => true,
                    'medium_confirmation_wait_retrace_enabled' => true,
                    'weak_confirmation_live_enabled' => false,
                    'v2_hold_quality_min' => 0.65,
                    'v2_post_reclaim_stability_min' => 0.65,
                    'v2_zone_defense_min' => 0.35,
                    'v2_trend_match_min' => 0.50,
                    'v2_price_position_max' => 0.88,
                    // V2 Live Quality Floor
                    'v2_live_quality_floor_enabled' => true,
                    'v2_live_min_confirmation_score' => 0.55,
                    'v2_live_min_pattern_confidence' => 0.50,
                    'v2_live_min_trend_match_score' => 0.40,
                    'v2_live_min_trend_match_score_short' => 0.38,
                    // V3-specific entry policy
                    'v3_strong_enter_now_enabled' => true,
                    'v3_zone_widen_weak_pct' => 0.40,
                    'v3_zone_widen_medium_pct' => 0.55,
                    'v3_zone_widen_strong_pct' => 0.70,
                    'v3_zone_widen_max_cap_pct' => 0.75,
                    'sniper_v3_live_filter_enabled' => false,
                    'sniper_v3_min_confirmation_score' => 0.80,
                    'sniper_v3_min_pattern_confidence' => 0.60,
                    'sniper_v3_min_trend_match_score' => 0.55,
                    'sniper_v3_min_entry_quality_score' => 0.75,
                    'sniper_v3_min_corridor_fit_score' => 0.75,
                    'sniper_v3_max_price_position' => 0.80,
                    'sniper_v3_min_reclaim_strength_score' => 0.70,
                    'sniper_v3_min_hold_quality_score' => 0.75,
                    'sniper_v3_min_post_reclaim_stability_score' => 0.70,
                    'sniper_v3_min_zone_defense_score' => 0.40,
                    // Short-side V3 overrides (softer than long defaults)
                    'sniper_v3_min_trend_match_score_short' => 0.35,
                    'sniper_v3_min_entry_quality_score_short' => 0.60,
                    'sniper_v3_min_corridor_fit_score_short' => 0.60,
                    // Long-side V3 overrides (symmetric relaxation for double_bottom_contextual_v3)
                    'sniper_v3_min_trend_match_score_long' => 0.35,
                    'sniper_v3_min_entry_quality_score_long' => 0.60,
                    'sniper_v3_min_corridor_fit_score_long' => 0.60,
                ],
            ],
            'conservative' => [
                'label' => 'Conservative',
                'description' => 'Lower-risk profile with reduced aggression. Tighter zone widening and stricter strong confirmation threshold.',
                'values' => [
                    'v2_confirmation_weak_max' => 0.45,
                    'v2_confirmation_strong_min' => 0.75,
                    'v2_zone_widen_weak_pct' => 0.45,
                    'v2_zone_widen_medium_pct' => 0.55,
                    'v2_zone_widen_strong_pct' => 0.70,
                    'v2_zone_widen_max_cap_pct' => 0.75,
                    'strong_confirmation_enter_now_enabled' => true,
                    'medium_confirmation_wait_retrace_enabled' => true,
                    'weak_confirmation_live_enabled' => false,
                    'v2_hold_quality_min' => 0.70,
                    'v2_post_reclaim_stability_min' => 0.70,
                    'v2_zone_defense_min' => 0.35,
                    'v2_trend_match_min' => 0.55,
                    'v2_price_position_max' => 0.88,
                    // V2 Live Quality Floor
                    'v2_live_quality_floor_enabled' => true,
                    'v2_live_min_confirmation_score' => 0.60,
                    'v2_live_min_pattern_confidence' => 0.55,
                    'v2_live_min_trend_match_score' => 0.45,
                    'v2_live_min_trend_match_score_short' => 0.44,
                    // V3-specific entry policy
                    'v3_strong_enter_now_enabled' => true,
                    'v3_zone_widen_weak_pct' => 0.35,
                    'v3_zone_widen_medium_pct' => 0.50,
                    'v3_zone_widen_strong_pct' => 0.65,
                    'v3_zone_widen_max_cap_pct' => 0.70,
                    'sniper_v3_live_filter_enabled' => false,
                    'sniper_v3_min_confirmation_score' => 0.80,
                    'sniper_v3_min_pattern_confidence' => 0.60,
                    'sniper_v3_min_trend_match_score' => 0.55,
                    'sniper_v3_min_entry_quality_score' => 0.75,
                    'sniper_v3_min_corridor_fit_score' => 0.75,
                    'sniper_v3_max_price_position' => 0.80,
                    'sniper_v3_min_reclaim_strength_score' => 0.70,
                    'sniper_v3_min_hold_quality_score' => 0.75,
                    'sniper_v3_min_post_reclaim_stability_score' => 0.70,
                    'sniper_v3_min_zone_defense_score' => 0.40,
                    // Short-side V3 overrides (softer than long defaults)
                    'sniper_v3_min_trend_match_score_short' => 0.40,
                    'sniper_v3_min_entry_quality_score_short' => 0.60,
                    'sniper_v3_min_corridor_fit_score_short' => 0.60,
                    // Long-side V3 overrides (symmetric relaxation for double_bottom_contextual_v3)
                    'sniper_v3_min_trend_match_score_long' => 0.40,
                    'sniper_v3_min_entry_quality_score_long' => 0.60,
                    'sniper_v3_min_corridor_fit_score_long' => 0.60,
                ],
            ],
            'sniper_75_attempt' => [
                'label' => 'Sniper Mode (75%+ attempt)',
                'description' => 'Very selective profile targeting higher winrate at the cost of fewer trades. Only strongest confirmations become live entries. This is a target intent, not a guaranteed outcome.',
                'values' => [
                    'v2_confirmation_weak_max' => 0.45,
                    'v2_confirmation_strong_min' => 0.80,
                    'v2_zone_widen_weak_pct' => 0.50,
                    'v2_zone_widen_medium_pct' => 0.60,
                    'v2_zone_widen_strong_pct' => 0.75,
                    'v2_zone_widen_max_cap_pct' => 0.75,
                    'strong_confirmation_enter_now_enabled' => true,
                    'medium_confirmation_wait_retrace_enabled' => false,
                    'weak_confirmation_live_enabled' => false,
                    'v2_hold_quality_min' => 0.80,
                    'v2_post_reclaim_stability_min' => 0.80,
                    'v2_zone_defense_min' => 0.40,
                    'v2_trend_match_min' => 0.60,
                    'v2_price_position_max' => 0.85,
                    // V2 Live Quality Floor — strictest for sniper
                    'v2_live_quality_floor_enabled' => true,
                    'v2_live_min_confirmation_score' => 0.70,
                    'v2_live_min_pattern_confidence' => 0.60,
                    'v2_live_min_trend_match_score' => 0.55,
                    'v2_live_min_trend_match_score_short' => 0.54,
                    // V3-specific entry policy — sniper uses enter_now for strong V3
                    'v3_strong_enter_now_enabled' => true,
                    'v3_zone_widen_weak_pct' => 0.40,
                    'v3_zone_widen_medium_pct' => 0.55,
                    'v3_zone_widen_strong_pct' => 0.70,
                    'v3_zone_widen_max_cap_pct' => 0.75,
                    // Sniper V3 Live Filters
                    'sniper_v3_live_filter_enabled' => true,
                    'sniper_v3_min_confirmation_score' => 0.80,
                    'sniper_v3_min_pattern_confidence' => 0.60,
                    'sniper_v3_min_trend_match_score' => 0.55,
                    'sniper_v3_min_entry_quality_score' => 0.75,
                    'sniper_v3_min_corridor_fit_score' => 0.75,
                    'sniper_v3_max_price_position' => 0.80,
                    'sniper_v3_min_reclaim_strength_score' => 0.70,
                    'sniper_v3_min_hold_quality_score' => 0.75,
                    'sniper_v3_min_post_reclaim_stability_score' => 0.70,
                    'sniper_v3_min_zone_defense_score' => 0.40,
                    // Short-side V3 overrides (softer to allow structurally strong short V3)
                    'sniper_v3_min_trend_match_score_short' => 0.40,
                    'sniper_v3_min_entry_quality_score_short' => 0.60,
                    'sniper_v3_min_corridor_fit_score_short' => 0.60,
                    // Long-side V3 overrides (symmetric relaxation for double_bottom_contextual_v3)
                    'sniper_v3_min_trend_match_score_long' => 0.40,
                    'sniper_v3_min_entry_quality_score_long' => 0.60,
                    'sniper_v3_min_corridor_fit_score_long' => 0.60,
                ],
            ],
            'sniper_lite' => [
                'label' => 'Sniper Lite',
                'description' => 'Moderately selective V3-only live policy. Strong confirmations and selected medium confirmations allowed. Higher signal count than Sniper, lower target precision.',
                'values' => [
                    'v2_confirmation_weak_max' => 0.45,
                    'v2_confirmation_strong_min' => 0.80,
                    'v2_zone_widen_weak_pct' => 0.50,
                    'v2_zone_widen_medium_pct' => 0.60,
                    'v2_zone_widen_strong_pct' => 0.75,
                    'v2_zone_widen_max_cap_pct' => 0.75,
                    'strong_confirmation_enter_now_enabled' => true,
                    'medium_confirmation_wait_retrace_enabled' => true,
                    'weak_confirmation_live_enabled' => false,
                    'v2_hold_quality_min' => 0.70,
                    'v2_post_reclaim_stability_min' => 0.70,
                    'v2_zone_defense_min' => 0.35,
                    'v2_trend_match_min' => 0.55,
                    'v2_price_position_max' => 0.88,
                    // V2 Live Quality Floor
                    'v2_live_quality_floor_enabled' => true,
                    'v2_live_min_confirmation_score' => 0.60,
                    'v2_live_min_pattern_confidence' => 0.55,
                    'v2_live_min_trend_match_score' => 0.45,
                    'v2_live_min_trend_match_score_short' => 0.44,
                    // V3-specific entry policy — sniper lite uses enter_now for strong V3
                    'v3_strong_enter_now_enabled' => true,
                    'v3_zone_widen_weak_pct' => 0.40,
                    'v3_zone_widen_medium_pct' => 0.55,
                    'v3_zone_widen_strong_pct' => 0.70,
                    'v3_zone_widen_max_cap_pct' => 0.75,
                    // Sniper Lite V3 Live Filters — tightened from prior drift
                    'sniper_v3_live_filter_enabled' => true,
                    'sniper_v3_min_confirmation_score' => 0.72,
                    'sniper_v3_min_pattern_confidence' => 0.60,
                    'sniper_v3_min_trend_match_score' => 0.50,
                    'sniper_v3_min_entry_quality_score' => 0.75,
                    'sniper_v3_min_corridor_fit_score' => 0.75,
                    'sniper_v3_max_price_position' => 0.85,
                    'sniper_v3_min_reclaim_strength_score' => 0.70,
                    'sniper_v3_min_hold_quality_score' => 0.75,
                    'sniper_v3_min_post_reclaim_stability_score' => 0.65,
                    'sniper_v3_min_zone_defense_score' => 0.35,
                    // Short-side V3 overrides (softer to allow structurally strong short V3)
                    'sniper_v3_min_trend_match_score_short' => 0.35,
                    'sniper_v3_min_entry_quality_score_short' => 0.60,
                    'sniper_v3_min_corridor_fit_score_short' => 0.60,
                    // Long-side V3 overrides (symmetric relaxation for double_bottom_contextual_v3)
                    'sniper_v3_min_trend_match_score_long' => 0.35,
                    'sniper_v3_min_entry_quality_score_long' => 0.60,
                    'sniper_v3_min_corridor_fit_score_long' => 0.60,
                ],
            ],
            'custom' => [
                'label' => 'Custom',
                'description' => 'Manual mode — all managed fields are editable directly. No profile bundle overwrites values.',
                'values' => [],
            ],
        ];
    }

    /**
     * Get profile-driven pattern routing bundles.
     *
     * Each profile defines which patterns are live, shadow, or disabled.
     * live: allowed for active candidate/monitor/signal path.
     * shadow: analyzed/counted but not live.
     * disabled: excluded from active profile behavior.
     *
     * @return array<string,array{live_patterns:list<string>,shadow_patterns:list<string>,disabled_patterns:list<string>}>
     */
    public static function getProfilePatternRoutingBundles(): array
    {
        return [
            'balanced' => [
                'live_patterns' => ['double_bottom_contextual_v2', 'double_bottom_contextual_v3'],
                'shadow_patterns' => ['double_bottom', 'double_top_contextual_v2', 'double_top_contextual_v3'],
                'disabled_patterns' => ['double_top', 'pullback_trend_continue', 'double_bottom_confirm_v2', 'double_top_confirm_v2'],
            ],
            'conservative' => [
                'live_patterns' => ['double_bottom_contextual_v2'],
                'shadow_patterns' => ['double_bottom_contextual_v3', 'double_top_contextual_v2', 'double_top_contextual_v3'],
                'disabled_patterns' => ['double_bottom', 'double_top', 'pullback_trend_continue', 'double_bottom_confirm_v2', 'double_top_confirm_v2'],
            ],
            'sniper_75_attempt' => [
                'live_patterns' => ['double_bottom_contextual_v3'],
                'shadow_patterns' => ['double_bottom_contextual_v2', 'double_top_contextual_v2', 'double_top_contextual_v3'],
                'disabled_patterns' => ['double_bottom', 'double_top', 'pullback_trend_continue', 'double_bottom_confirm_v2', 'double_top_confirm_v2'],
            ],
            'sniper_lite' => [
                'live_patterns' => ['double_bottom_contextual_v3'],
                'shadow_patterns' => ['double_bottom_contextual_v2', 'double_top_contextual_v2', 'double_top_contextual_v3'],
                'disabled_patterns' => ['double_bottom', 'double_top', 'pullback_trend_continue', 'double_bottom_confirm_v2', 'double_top_confirm_v2'],
            ],
            'custom' => [
                'live_patterns' => [],
                'shadow_patterns' => [],
                'disabled_patterns' => [],
            ],
        ];
    }

    /**
     * Get the list of sniper V3 live filter field names.
     *
     * @return list<string>
     */
    public static function getSniperV3FilterFields(): array
    {
        return [
            'sniper_v3_live_filter_enabled',
            'sniper_v3_min_confirmation_score',
            'sniper_v3_min_pattern_confidence',
            'sniper_v3_min_trend_match_score',
            'sniper_v3_min_entry_quality_score',
            'sniper_v3_min_corridor_fit_score',
            'sniper_v3_max_price_position',
            'sniper_v3_min_reclaim_strength_score',
            'sniper_v3_min_hold_quality_score',
            'sniper_v3_min_post_reclaim_stability_score',
            'sniper_v3_min_zone_defense_score',
            // Short-side V3 overrides
            'sniper_v3_min_trend_match_score_short',
            'sniper_v3_min_entry_quality_score_short',
            'sniper_v3_min_corridor_fit_score_short',
            // Long-side V3 overrides (symmetric relaxation for double_bottom_contextual_v3)
            'sniper_v3_min_trend_match_score_long',
            'sniper_v3_min_entry_quality_score_long',
            'sniper_v3_min_corridor_fit_score_long',
        ];
    }

    /**
     * Get the allowed confirmation tiers for sniper V3 live entry.
     *
     * @param string $profile Execution profile ID
     * @return list<string>
     */
    public static function getSniperV3AllowedTiers(string $profile = 'sniper_75_attempt'): array
    {
        if ($profile === 'sniper_lite') {
            return ['medium', 'strong', 'very_strong'];
        }
        return ['strong', 'very_strong'];
    }

    /**
     * Evaluate V2 live quality floor for a single signal/candidate.
     *
     * This gate prevents weak/medium-quality V2 signals from becoming live
     * when their quality scores fall below profile-configured minimums.
     * Applied to all V2 contextual patterns (double_bottom_contextual_v2)
     * during live intent generation, before bot-ready approval.
     *
     * Returns array with:
     *   'eligible' => bool,
     *   'reject_reasons' => string[],
     *   'checked_values' => array (for diagnostics)
     *
     * @param array<string,mixed> $signal     Signal or candidate payload
     * @param array<string,mixed> $userLimits Effective user limits
     * @return array{eligible:bool,reject_reasons:list<string>,checked_values:array<string,mixed>}
     */
    public static function evaluateV2LiveQualityFloor(array $signal, array $userLimits): array
    {
        $rejectReasons = [];

        // Epsilon for float-boundary safety — prevents microscopic rounding rejects
        $epsilon = 0.005;

        $confirmationScore = (float)($signal['confirmation_score'] ?? 0.0);
        $patternConfidence = (float)($signal['pattern_confidence'] ?? 0.0);
        $trendMatchScore = $signal['trend_match_score'] ?? null;
        $confirmationTier = (string)($signal['confirmation_tier'] ?? 'none');
        $side = strtolower(trim((string)($signal['side'] ?? '')));
        $entryAction = (string)($signal['entry_action'] ?? 'wait_retrace');

        $checkedValues = [
            'confirmation_tier' => $confirmationTier,
            'confirmation_score' => round($confirmationScore, 4),
            'pattern_confidence' => round($patternConfidence, 4),
            'trend_match_score' => $trendMatchScore !== null ? round((float)$trendMatchScore, 4) : null,
            'side' => $side,
            'entry_action' => $entryAction,
            'epsilon_used' => $epsilon,
        ];

        $borderlinePass = false;

        // Gate 1: Minimum confirmation_score (epsilon-safe)
        $minConfScore = (float)($userLimits['v2_live_min_confirmation_score'] ?? 0.55);
        if ($confirmationScore + $epsilon < $minConfScore) {
            $rejectReasons[] = $side === 'short'
                ? 'reject_short_enter_now_confirmation_too_low'
                : 'v2_reject_confirmation_score_too_low';
        } elseif ($confirmationScore < $minConfScore) {
            $borderlinePass = true;
        }

        // Gate 2: Minimum pattern_confidence (epsilon-safe)
        $minPatternConf = (float)($userLimits['v2_live_min_pattern_confidence'] ?? 0.50);
        if ($patternConfidence + $epsilon < $minPatternConf) {
            $rejectReasons[] = $side === 'short'
                ? 'reject_short_enter_now_pattern_conf_too_low'
                : 'v2_reject_pattern_confidence_too_low';
        } elseif ($patternConfidence < $minPatternConf) {
            $borderlinePass = true;
        }

        // Gate 3: Minimum trend_match_score (epsilon-safe, side-aware)
        // Use side-specific threshold for short when available
        $minTrendMatchLong = (float)($userLimits['v2_live_min_trend_match_score'] ?? 0.40);
        $minTrendMatchShort = (float)($userLimits['v2_live_min_trend_match_score_short'] ?? $minTrendMatchLong);
        $minTrendMatch = ($side === 'short') ? $minTrendMatchShort : $minTrendMatchLong;
        $checkedValues['trend_match_threshold_used'] = round($minTrendMatch, 4);
        $checkedValues['trend_match_side_aware'] = ($side === 'short' && isset($userLimits['v2_live_min_trend_match_score_short']));

        if ($trendMatchScore === null || (float)$trendMatchScore <= 0.0) {
            $rejectReasons[] = $side === 'short'
                ? 'reject_short_enter_now_trend_match_missing'
                : 'v2_reject_trend_match_missing';
        } elseif ((float)$trendMatchScore + $epsilon < $minTrendMatch) {
            $rejectReasons[] = $side === 'short'
                ? 'reject_short_enter_now_trend_match_too_low'
                : 'v2_reject_trend_match_too_low';
        } elseif ((float)$trendMatchScore < $minTrendMatch) {
            $borderlinePass = true;
        }

        $checkedValues['trend_match_floor_borderline_pass'] = $borderlinePass;

        return [
            'eligible' => empty($rejectReasons),
            'reject_reasons' => $rejectReasons,
            'checked_values' => $checkedValues,
        ];
    }

    /**
     * Evaluate sniper V3 live filter for a single signal/candidate.
     *
     * Returns array with:
     *   'eligible' => bool,
     *   'reject_reasons' => string[],
     *   'checked_values' => array (for diagnostics)
     *
     * @param array<string,mixed> $signal     Signal or candidate payload
     * @param array<string,mixed> $userLimits Effective user limits
     * @param string              $profile    Execution profile ID (sniper_75_attempt or sniper_lite)
     * @return array{eligible:bool,reject_reasons:list<string>,checked_values:array<string,mixed>}
     */
    public static function evaluateSniperV3LiveFilter(array $signal, array $userLimits, string $profile = 'sniper_75_attempt'): array
    {
        $rejectReasons = [];
        $epsilon = 0.005; // Epsilon-safe margin (same as V2 quality floor)

        $side = strtolower(trim((string)($signal['side'] ?? '')));
        $isShort = ($side === 'short');
        $patternAlgo = (string)($signal['pattern_algorithm'] ?? '');
        $isShortV3 = $isShort && $patternAlgo === 'double_top_contextual_v3';
        $isLongV3  = !$isShort && $patternAlgo === 'double_bottom_contextual_v3';

        $confirmationTier = (string)($signal['confirmation_tier'] ?? 'none');
        $confirmationScore = (float)($signal['confirmation_score'] ?? 0.0);
        $patternConfidence = (float)($signal['pattern_confidence'] ?? 0.0);
        $trendMatchScore = $signal['trend_match_score'] ?? null;
        $entryQualityScore = (float)($signal['entry_quality_score'] ?? 0.0);
        $corridorFitScore = (float)($signal['corridor_fit_score'] ?? 0.0);
        $pricePosition = (float)($signal['price_position'] ?? 0.0);
        $reclaimStrengthScore = (float)($signal['reclaim_strength_score'] ?? 0.0);
        $holdQualityScore = (float)($signal['hold_quality_score'] ?? 0.0);
        $postReclaimStabilityScore = (float)($signal['post_reclaim_stability_score'] ?? 0.0);
        $zoneDefenseScore = (float)($signal['zone_defense_score'] ?? 0.0);

        $thresholdSource = $isShortV3 ? 'short_v3' : ($isLongV3 ? 'long_v3' : 'default_v3');

        $checkedValues = [
            'confirmation_tier' => $confirmationTier,
            'confirmation_score' => round($confirmationScore, 4),
            'pattern_confidence' => round($patternConfidence, 4),
            'trend_match_score' => $trendMatchScore,
            'entry_quality_score' => round($entryQualityScore, 4),
            'corridor_fit_score' => round($corridorFitScore, 4),
            'price_position' => round($pricePosition, 4),
            'reclaim_strength_score' => round($reclaimStrengthScore, 4),
            'hold_quality_score' => round($holdQualityScore, 4),
            'post_reclaim_stability_score' => round($postReclaimStabilityScore, 4),
            'zone_defense_score' => round($zoneDefenseScore, 4),
            'side' => $side,
            'threshold_source' => $thresholdSource,
        ];

        // 4.1 Confirmation Tier Gate
        $allowedTiers = self::getSniperV3AllowedTiers($profile);
        if (!in_array($confirmationTier, $allowedTiers, true)) {
            $rejectReasons[] = 'sniper_reject_confirmation_tier_not_strong';
        }

        // 4.2 Minimum confirmation_score
        $minConfScore = (float)($userLimits['sniper_v3_min_confirmation_score'] ?? 0.80);
        if ($confirmationScore + $epsilon < $minConfScore) {
            $rejectReasons[] = 'sniper_reject_confirmation_score_too_low';
        }

        // 4.3 Minimum pattern_confidence
        $minPatternConf = (float)($userLimits['sniper_v3_min_pattern_confidence'] ?? 0.60);
        if ($patternConfidence + $epsilon < $minPatternConf) {
            $rejectReasons[] = 'sniper_reject_pattern_confidence_too_low';
        }

        // 4.4 Minimum trend_match_score — side-aware for short V3 and long V3
        $minTrendMatchDefault = (float)($userLimits['sniper_v3_min_trend_match_score'] ?? 0.55);
        $minTrendMatchShort = (float)($userLimits['sniper_v3_min_trend_match_score_short'] ?? $minTrendMatchDefault);
        $minTrendMatchLong  = (float)($userLimits['sniper_v3_min_trend_match_score_long']  ?? $minTrendMatchDefault);
        $minTrendMatch = $isShortV3 ? $minTrendMatchShort : ($isLongV3 ? $minTrendMatchLong : $minTrendMatchDefault);
        if ($trendMatchScore === null || (float)$trendMatchScore <= 0.0) {
            $rejectReasons[] = 'sniper_reject_trend_match_missing';
        } elseif ((float)$trendMatchScore + $epsilon < $minTrendMatch) {
            $rejectReasons[] = 'sniper_reject_trend_match_too_low';
        }
        $checkedValues['trend_match_threshold_used'] = round($minTrendMatch, 4);

        // 4.5 Minimum entry_quality_score — side-aware for short V3 and long V3
        $minEntryQualityDefault = (float)($userLimits['sniper_v3_min_entry_quality_score'] ?? 0.75);
        $minEntryQualityShort = (float)($userLimits['sniper_v3_min_entry_quality_score_short'] ?? $minEntryQualityDefault);
        $minEntryQualityLong  = (float)($userLimits['sniper_v3_min_entry_quality_score_long']  ?? $minEntryQualityDefault);
        $minEntryQuality = $isShortV3 ? $minEntryQualityShort : ($isLongV3 ? $minEntryQualityLong : $minEntryQualityDefault);
        if ($entryQualityScore + $epsilon < $minEntryQuality) {
            $rejectReasons[] = 'sniper_reject_entry_quality_too_low';
        }
        $checkedValues['entry_quality_threshold_used'] = round($minEntryQuality, 4);

        // 4.6 Minimum corridor_fit_score — side-aware for short V3 and long V3
        $minCorridorFitDefault = (float)($userLimits['sniper_v3_min_corridor_fit_score'] ?? 0.75);
        $minCorridorFitShort = (float)($userLimits['sniper_v3_min_corridor_fit_score_short'] ?? $minCorridorFitDefault);
        $minCorridorFitLong  = (float)($userLimits['sniper_v3_min_corridor_fit_score_long']  ?? $minCorridorFitDefault);
        $minCorridorFit = $isShortV3 ? $minCorridorFitShort : ($isLongV3 ? $minCorridorFitLong : $minCorridorFitDefault);
        if ($corridorFitScore + $epsilon < $minCorridorFit) {
            $rejectReasons[] = 'sniper_reject_corridor_fit_too_low';
        }
        $checkedValues['corridor_fit_threshold_used'] = round($minCorridorFit, 4);

        // 4.7 Maximum price_position
        $maxPricePosition = (float)($userLimits['sniper_v3_max_price_position'] ?? 0.80);
        if ($pricePosition > $maxPricePosition) {
            $rejectReasons[] = 'sniper_reject_price_position_too_high';
        }

        // 4.8 Component Score Floors
        $minReclaim = (float)($userLimits['sniper_v3_min_reclaim_strength_score'] ?? 0.70);
        if ($reclaimStrengthScore + $epsilon < $minReclaim) {
            $rejectReasons[] = 'sniper_reject_reclaim_strength_too_low';
        }

        $minHoldQuality = (float)($userLimits['sniper_v3_min_hold_quality_score'] ?? 0.75);
        if ($holdQualityScore + $epsilon < $minHoldQuality) {
            $rejectReasons[] = 'sniper_reject_hold_quality_too_low';
        }

        $minPostReclaim = (float)($userLimits['sniper_v3_min_post_reclaim_stability_score'] ?? 0.70);
        if ($postReclaimStabilityScore + $epsilon < $minPostReclaim) {
            $rejectReasons[] = 'sniper_reject_post_reclaim_stability_too_low';
        }

        $minZoneDefense = (float)($userLimits['sniper_v3_min_zone_defense_score'] ?? 0.40);
        if ($zoneDefenseScore + $epsilon < $minZoneDefense) {
            $rejectReasons[] = 'sniper_reject_zone_defense_too_low';
        }

        $result = [
            'eligible' => empty($rejectReasons),
            'reject_reasons' => $rejectReasons,
            'checked_values' => $checkedValues,
        ];

        // Diagnostics: mark which side-specific threshold path was used
        if ($isShortV3) {
            $result['short_v3_threshold_applied'] = true;
        }
        if ($isLongV3) {
            $result['long_v3_threshold_applied'] = true;
        }

        return $result;
    }

    /**
     * Apply runtime overrides from config_overrides/*.json
     */
    private function applyOverrides(): void
    {
        $overrideDir = $this->moduleBase . '/runtime/config_overrides';
        if (!is_dir($overrideDir)) {
            return;
        }

        $files = glob($overrideDir . '/*.json');
        if (!$files) {
            return;
        }

        foreach ($files as $file) {
            $section = pathinfo($file, PATHINFO_FILENAME);
            $overrideData = json_decode((string)file_get_contents($file), true);
            if (!is_array($overrideData) || !isset($this->config[$section])) {
                continue;
            }

            if (isset($this->config[$section]['settings']) && is_array($this->config[$section]['settings'])) {
                $this->config[$section]['settings'] = array_merge(
                    $this->config[$section]['settings'],
                    $overrideData
                );
            } else {
                $this->config[$section] = array_merge(
                    $this->config[$section],
                    $overrideData
                );
            }
        }
    }

    /**
     * Apply user config from runtime/user_config.json over base user_limits.
     *
     * Soft-switch (wave 1): after applying user_config.json, overlays first-wave
     * operational parameters from the unified Config Module operational draft (if
     * available and readable).  Values are identical — this establishes Config Module
     * as the authoritative source for tracked parameters without changing behaviour.
     */
    private function applyUserConfig(): void
    {
        $saved = $this->loadUserConfig();
        if (!empty($saved)) {
            if (!isset($this->config['risk_engine']['user_limits'])) {
                $this->config['risk_engine']['user_limits'] = [];
            }

            $this->config['risk_engine']['user_limits'] = array_merge(
                $this->config['risk_engine']['user_limits'],
                $saved
            );

            // Apply execution profile bundle over managed fields (non-custom profiles only)
            $this->applyExecutionProfile();

            // Apply pattern selection from user config into parser4 config
            if (isset($saved['patterns']) && is_array($saved['patterns'])) {
                $this->applyPatternSelection($saved['patterns']);
            }

            // Apply profile-driven pattern routing AFTER manual patterns (overrides when profile_controlled)
            $this->applyProfilePatternRouting();
        }

        // Soft-switch overlay: read first-wave params from unified Config Module draft.
        // Non-fatal — falls back to user_config.json values already applied above.
        $this->configMigrationStatus = $this->applyUnifiedConfigOverlay();
        $this->writeMigrationStatus($this->configMigrationStatus);
    }

    /**
     * Return the config migration status recorded during the last applyUserConfig() call.
     * Always call after construction (applyUserConfig runs in __construct).
     *
     * @return array<string,mixed>
     */
    public function getMigrationStatus(): array
    {
        return $this->configMigrationStatus;
    }

    /**
     * Overlay first-wave Smart Brain operational parameters from the unified
     * Config Module operational draft (shadow artifact).
     *
     * Reads config_operational_draft.json from the sibling Config Module's runtime
     * storage.  If unavailable, no overlay occurs and values from user_config.json
     * remain in effect.
     *
     * Smart Brain behaviour is NOT changed — values are identical to user_config.json
     * because the Config Module extracts them from user_config.json.  The overlay
     * establishes the unified Config Module as the tracked source for these params.
     *
     * Each param in the returned status includes:
     *   switched_params_detail[$key]:
     *     value, original_source, original_source_file, via,
     *     source_layer, unified_config_used, legacy_fallback_used
     *   fallback_params_detail[$key]:
     *     value, source_layer, unified_config_used, legacy_fallback_used, fallback_reason
     *
     * @return array<string,mixed> migration status record
     */
    private function applyUnifiedConfigOverlay(): array
    {
        // First-wave: flat operational params that map 1:1 into user_limits.
        // Patterns are excluded (nested structure; left for a later wave).
        $firstWaveParams = [
            'live_trading_enabled',
            'live_max_positions',
            'live_signal_selection_mode',
            'live_one_trade_per_symbol',
            'live_entry_policy',
            'live_reverse_side_enabled',
            'leverage_mode',
            'manual_leverage',
            'max_budget_per_coin',
            'stop_control_mode',
            'stop_loss_from_entry_roi',
            'trailing_enabled',
            'trailing_mode',
            'trailing_activation_roi',
            'trailing_activation_floor_roi',
            'trailing_floor_lock_roi',
            'break_even_enabled',
            'break_even_activation_roi',
            'execution_profile',
        ];

        $status = [
            'module'                       => 'smart_brain',
            'switch_wave'                  => 'v1_operational_params',
            'unified_config_available'     => false,
            'unified_config_draft_path'    => '',
            'source'                       => 'legacy_user_config',
            'partially_migrated'           => false,
            'first_wave_total'             => count($firstWaveParams),
            'migrated_count'               => 0,
            'fallback_count'               => 0,
            'switched_params'              => [],
            'fallback_params'              => [],
            'switched_params_detail'       => [],
            'fallback_params_detail'       => [],
            'recorded_at'                  => date('c'),
        ];

        // Locate Config Module (sibling directory under the same system/ parent)
        $systemDir = dirname($this->moduleBase);
        $draftPath = $systemDir . '/config/storage/runtime/config_operational_draft.json';
        $status['unified_config_draft_path'] = $draftPath;

        /** Build fallback detail for all first-wave params using legacy user_limits values. */
        $buildFallbackDetail = function (string $fallbackReason) use ($firstWaveParams): array {
            $detail = [];
            $userLimits = $this->config['risk_engine']['user_limits'] ?? [];
            foreach ($firstWaveParams as $key) {
                $detail[$key] = [
                    'value'               => $userLimits[$key] ?? null,
                    'source_layer'        => 'legacy_user_config',
                    'unified_config_used' => false,
                    'legacy_fallback_used'=> true,
                    'fallback_reason'     => $fallbackReason,
                    'fallback_source'     => 'brain_user_config (runtime/user_config.json)',
                ];
            }
            return $detail;
        };

        if (!is_file($draftPath)) {
            $status['fallback_params']        = $firstWaveParams;
            $status['fallback_count']         = count($firstWaveParams);
            $status['fallback_params_detail'] = $buildFallbackDetail('unified_config_draft_not_found');
            return $status;
        }

        $raw = @file_get_contents($draftPath);
        if ($raw === false) {
            $status['fallback_params']        = $firstWaveParams;
            $status['fallback_count']         = count($firstWaveParams);
            $status['fallback_params_detail'] = $buildFallbackDetail('unified_config_draft_unreadable');
            return $status;
        }

        $draft = @json_decode($raw, true);
        if (!is_array($draft) || empty($draft['params'])) {
            $status['fallback_params']        = $firstWaveParams;
            $status['fallback_count']         = count($firstWaveParams);
            $status['fallback_params_detail'] = $buildFallbackDetail('unified_config_draft_invalid_or_empty');
            return $status;
        }

        $status['unified_config_available']    = true;
        $status['unified_config_generated_at'] = $draft['generated_at'] ?? null;

        $allParams = $draft['params'];

        foreach ($firstWaveParams as $key) {
            if (!isset($allParams[$key]) || ($allParams[$key]['value'] ?? null) === null) {
                $status['fallback_params'][] = $key;
                $userLimits = $this->config['risk_engine']['user_limits'] ?? [];
                $status['fallback_params_detail'][$key] = [
                    'value'               => $userLimits[$key] ?? null,
                    'source_layer'        => 'legacy_user_config',
                    'unified_config_used' => false,
                    'legacy_fallback_used'=> true,
                    'fallback_reason'     => 'param_not_in_unified_config_draft',
                    'fallback_source'     => 'brain_user_config (runtime/user_config.json)',
                ];
                continue;
            }
            $entry = $allParams[$key];
            // Overlay the value (identical to user_config.json; Config Module read it from there)
            if (!isset($this->config['risk_engine']['user_limits'])) {
                $this->config['risk_engine']['user_limits'] = [];
            }
            $this->config['risk_engine']['user_limits'][$key] = $entry['value'];
            $status['switched_params'][]        = $key;
            $status['switched_params_detail'][$key] = [
                'value'               => $entry['value'],
                'original_source'     => $entry['source']      ?? 'unknown',
                'original_source_file'=> $entry['source_file'] ?? null,
                'via'                 => 'unified_config_operational_draft',
                'source_layer'        => 'unified_config',
                'unified_config_used' => true,
                'legacy_fallback_used'=> false,
            ];
        }

        $migratedCount = count($status['switched_params']);
        $fallbackCount = count($status['fallback_params']);
        $status['migrated_count']    = $migratedCount;
        $status['fallback_count']    = $fallbackCount;
        $status['partially_migrated']= $migratedCount > 0 && $fallbackCount > 0;

        $status['source'] = $migratedCount === 0
            ? 'legacy_user_config'
            : 'unified_config_operational_draft';

        return $status;
    }

    /**
     * Write Smart Brain config source / migration status to runtime storage.
     *
     * @param array<string,mixed> $migrationStatus
     */
    private function writeMigrationStatus(array $migrationStatus): void
    {
        $path = $this->moduleBase . '/runtime/config_source_status.json';
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents(
            $path,
            json_encode($migrationStatus, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    /**
     * Apply execution profile bundle values over managed fields in user_limits.
     *
     * When execution_profile is NOT 'custom', the profile's managed field values
     * are applied deterministically — operator values for managed fields are overwritten.
     * When execution_profile IS 'custom' (or absent for backward compat), no overwrite occurs.
     */
    private function applyExecutionProfile(): void
    {
        $userLimits = &$this->config['risk_engine']['user_limits'];
        $profileId = (string)($userLimits['execution_profile'] ?? 'custom');

        if ($profileId === 'custom') {
            return;
        }

        $bundles = self::getExecutionProfileBundles();
        if (!isset($bundles[$profileId])) {
            return;
        }

        $bundle = $bundles[$profileId];
        foreach ($bundle['values'] as $field => $value) {
            $userLimits[$field] = $value;
        }
    }

    /**
     * Apply profile-driven pattern routing when pattern_profile_mode is 'profile_controlled'.
     * When active, the profile's live_patterns are set as the enabled patterns.
     */
    private function applyProfilePatternRouting(): void
    {
        $userLimits = $this->config['risk_engine']['user_limits'] ?? [];
        $profileId = (string)($userLimits['execution_profile'] ?? 'custom');
        $patternMode = (string)($userLimits['pattern_profile_mode'] ?? 'manual_override');

        if ($profileId === 'custom' || $patternMode !== 'profile_controlled') {
            return;
        }

        $routingBundles = self::getProfilePatternRoutingBundles();
        if (!isset($routingBundles[$profileId])) {
            return;
        }

        $bundle = $routingBundles[$profileId];
        $livePatterns = $bundle['live_patterns'];

        // Apply profile live patterns as enabled patterns in parser4 config
        if (!empty($livePatterns)) {
            if (!isset($this->config['parser4']['pattern_algorithms'])) {
                $this->config['parser4']['pattern_algorithms'] = [];
            }
            $this->config['parser4']['pattern_algorithms']['enabled'] = $livePatterns;
            $this->config['parser4']['pattern_algorithms']['mode'] = 'any';
        }
    }

    /**
     * Merge user pattern selection into parser4 config.
     *
     * @param array{enabled:list<string>,mode:string} $patterns
     */
    private function applyPatternSelection(array $patterns): void
    {
        if (!isset($this->config['parser4'])) {
            return;
        }

        if (!isset($this->config['parser4']['pattern_algorithms'])) {
            $this->config['parser4']['pattern_algorithms'] = [];
        }

        if (isset($patterns['enabled']) && is_array($patterns['enabled']) && !empty($patterns['enabled'])) {
            $this->config['parser4']['pattern_algorithms']['enabled'] = $patterns['enabled'];
        }
        if (isset($patterns['mode']) && in_array($patterns['mode'], ['one', 'any', 'all'], true)) {
            $this->config['parser4']['pattern_algorithms']['mode'] = $patterns['mode'];
        }
    }

    /**
     * Get currently enabled pattern algorithms from parser4 config.
     *
     * @return list<string>
     */
    public function getEnabledPatterns(): array
    {
        return array_values((array)(($this->config['parser4']['pattern_algorithms'] ?? [])['enabled'] ?? []));
    }

    /**
     * Get list of all allowed pattern algorithm identifiers.
     *
     * @return list<string>
     */
    public static function getAllowedPatternAlgorithms(): array
    {
        return self::ALLOWED_PATTERN_ALGORITHMS;
    }

    // =========================================================================
    // Live Intent Lifecycle Helpers
    // =========================================================================

    /**
     * Atomically read-modify-write live_intents.json with flock.
     *
     * @param string   $path     Absolute path to live_intents.json
     * @param callable $modifier fn(array $data): array — receives current data, returns new data
     * @return bool True on success
     */
    public static function atomicUpdateLiveIntents(string $path, callable $modifier): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $fp = @fopen($path, 'c+');
        if ($fp === false) {
            return false;
        }

        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return false;
        }

        try {
            $content = '';
            while (!feof($fp)) {
                $content .= fread($fp, 8192);
            }

            $data = @json_decode($content, true);
            if (!is_array($data)) {
                $data = ['schema_version' => 'live_intents_v1', 'intents' => []];
            }

            $data = $modifier($data);

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            fflush($fp);

            return true;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Check whether an intent status is terminal (no further transitions).
     */
    public static function isTerminalIntentStatus(string $status): bool
    {
        return in_array($status, [
            self::INTENT_STATUS_EXECUTED,
            self::INTENT_STATUS_REJECTED,
            self::INTENT_STATUS_EXPIRED,
        ], true);
    }
}
