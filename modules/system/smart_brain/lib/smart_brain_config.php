<?php
declare(strict_types=1);

final class SmartBrainConfig
{
    private const ALLOWED_PATTERN_ALGORITHMS = ['double_bottom', 'double_top', 'pullback_trend_continue', 'double_bottom_confirm_v2', 'double_top_confirm_v2', 'double_bottom_contextual_v2', 'double_bottom_contextual_v3'];

    private string $moduleBase;
    /** @var array<string,mixed> */
    private array $config;

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
            // Active for price_distance + price_distance_floor modes
            'trailing_price_distance_pct'  => max(0.005, min(0.20, (float)($values['trailing_price_distance_pct'] ?? 0.02))),
            // Active for price_distance_floor mode only
            'trailing_activation_floor_roi' => (float)($values['trailing_activation_floor_roi'] ?? 4.0),
            'trailing_floor_lock_roi'      => (float)($values['trailing_floor_lock_roi'] ?? 3.0),
            'trailing_step_mode'           => in_array((string)($values['trailing_step_mode'] ?? 'fixed'), ['fixed', 'auto_strength'], true) ? (string)$values['trailing_step_mode'] : 'fixed',
            'trailing_step_pct_min'        => max(0.001, min(0.10, (float)($values['trailing_step_pct_min'] ?? 0.005))),
            'trailing_step_pct_max'        => max(0.001, min(0.10, (float)($values['trailing_step_pct_max'] ?? 0.02))),
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

        // Apply pattern selection into parser4 config immediately
        $this->applyPatternSelection($clean['patterns']);

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
        if (isset($values['trailing_activation_roi']) && (float)$values['trailing_activation_roi'] < 0) {
            $errors[] = 'trailing_activation_roi must be >= 0';
        }
        if (isset($values['trailing_mode']) && !in_array((string)$values['trailing_mode'], ['roi_giveback', 'price_distance', 'price_distance_floor'], true)) {
            $errors[] = 'trailing_mode must be roi_giveback, price_distance, or price_distance_floor';
        }
        if (isset($values['trailing_price_distance_pct'])) {
            $distVal = (float)$values['trailing_price_distance_pct'];
            if ($distVal < 0.005 || $distVal > 0.20) {
                $errors[] = 'trailing_price_distance_pct must be between 0.005 (0.5%) and 0.20 (20%)';
            }
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
                if (isset($values['trailing_activation_floor_roi']) && $floorLock >= (float)$values['trailing_activation_floor_roi']) {
                    $errors[] = 'trailing_floor_lock_roi must be < trailing_activation_floor_roi';
                }
            }
            if (isset($values['trailing_step_mode']) && !in_array((string)$values['trailing_step_mode'], ['fixed', 'auto_strength'], true)) {
                $errors[] = 'trailing_step_mode must be fixed or auto_strength';
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
        }
        if (isset($values['trailing_min_lock_roi']) && (float)$values['trailing_min_lock_roi'] < 0) {
            $errors[] = 'trailing_min_lock_roi must be >= 0';
        }
        if (isset($values['trailing_min_step']) && (float)$values['trailing_min_step'] <= 0) {
            $errors[] = 'trailing_min_step must be > 0';
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
        return [
            'live_trading_enabled' => (bool)($userLimits['live_trading_enabled'] ?? false),
            'live_signal_selection_mode' => (string)($userLimits['live_signal_selection_mode'] ?? 'whitelist_only'),
            'live_max_positions' => (int)($userLimits['live_max_positions'] ?? 3),
            'live_one_trade_per_symbol' => (bool)($userLimits['live_one_trade_per_symbol'] ?? true),
            'live_entry_policy' => (string)($userLimits['live_entry_policy'] ?? 'enter_now'),
            'live_reverse_side_enabled' => (bool)($userLimits['live_reverse_side_enabled'] ?? false),
            'trailing_contract' => [
                'trailing_enabled' => (bool)($userLimits['trailing_enabled'] ?? false),
                'trailing_mode' => (string)($userLimits['trailing_mode'] ?? 'roi_giveback'),
                'trailing_price_distance_pct' => (float)($userLimits['trailing_price_distance_pct'] ?? 0.02),
                'trailing_activation_floor_roi' => (float)($userLimits['trailing_activation_floor_roi'] ?? 4.0),
                'trailing_floor_lock_roi' => (float)($userLimits['trailing_floor_lock_roi'] ?? 3.0),
                'trailing_step_mode' => (string)($userLimits['trailing_step_mode'] ?? 'fixed'),
                'trailing_step_pct_min' => (float)($userLimits['trailing_step_pct_min'] ?? 0.005),
                'trailing_step_pct_max' => (float)($userLimits['trailing_step_pct_max'] ?? 0.02),
                'trailing_activation_roi' => (float)($userLimits['trailing_activation_roi'] ?? 0.02),
                'trailing_min_lock_roi' => (float)($userLimits['trailing_min_lock_roi'] ?? 0.005),
                'trailing_min_step' => (float)($userLimits['trailing_min_step'] ?? 0.005),
                'break_even_enabled' => (bool)($userLimits['break_even_enabled'] ?? false),
                'break_even_activation_roi' => (float)($userLimits['break_even_activation_roi'] ?? 0.01),
                'exit_mode' => (string)($userLimits['exit_mode'] ?? 'fixed_tp'),
                'fixed_take_profit_roi' => (float)($userLimits['fixed_take_profit_roi'] ?? 0.05),
                'hybrid_tp_share' => (float)($userLimits['hybrid_tp_share'] ?? 0.5),
                'stop_control_mode' => (string)($userLimits['stop_control_mode'] ?? 'auto'),
                'manual_stop_loss_roi' => (float)($userLimits['manual_stop_loss_roi'] ?? 0.03),
                'stop_loss_from_entry_roi' => (float)($userLimits['stop_loss_from_entry_roi'] ?? 0.10),
            ],
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

        return [
            'user_limits' => $userLimits,
            'brain_auto' => $this->getBrainAutoValues(),
            'exit_policy' => [
                'exit_mode' => $userLimits['exit_mode'] ?? 'fixed_tp',
                'stop_floor_type' => $userLimits['stop_floor_type'] ?? 'roi_percent',
                'stop_floor_value' => (float)($userLimits['stop_floor_value'] ?? 0.03),
                'brain_may_tighten_stop' => (bool)($userLimits['brain_may_tighten_stop'] ?? true),
                'trailing_enabled' => (bool)($userLimits['trailing_enabled'] ?? false),
                'trailing_mode' => $trailingMode,
                // Active for price_distance + price_distance_floor modes
                'trailing_price_distance_pct' => (float)($userLimits['trailing_price_distance_pct'] ?? 0.02),
                // Active for price_distance_floor mode only
                'trailing_activation_floor_roi' => (float)($userLimits['trailing_activation_floor_roi'] ?? 4.0),
                'trailing_floor_lock_roi' => (float)($userLimits['trailing_floor_lock_roi'] ?? 3.0),
                'trailing_step_mode' => (string)($userLimits['trailing_step_mode'] ?? 'fixed'),
                'trailing_step_pct_min' => (float)($userLimits['trailing_step_pct_min'] ?? 0.005),
                'trailing_step_pct_max' => (float)($userLimits['trailing_step_pct_max'] ?? 0.02),
                // Legacy: active for roi_giveback mode (kept for backward compatibility)
                'trailing_activation_roi' => (float)($userLimits['trailing_activation_roi'] ?? 0.02),
                'trailing_min_lock_roi' => (float)($userLimits['trailing_min_lock_roi'] ?? 0.005),
                'trailing_min_step' => (float)($userLimits['trailing_min_step'] ?? 0.005),
                'brain_may_delay_trailing' => (bool)($userLimits['brain_may_delay_trailing'] ?? false),
                'fixed_take_profit_roi' => (float)($userLimits['fixed_take_profit_roi'] ?? 0.05),
                'hybrid_tp_share' => (float)($userLimits['hybrid_tp_share'] ?? 0.5),
                // Mode-aware metadata: which trailing fields are active for the selected mode
                'active_trailing_fields' => $activeTrailingFields,
                'legacy_trailing_fields_present' => $legacyFieldsPresent,
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
            'live_trading' => [
                'live_trading_enabled' => (bool)($userLimits['live_trading_enabled'] ?? false),
                'live_signal_selection_mode' => (string)($userLimits['live_signal_selection_mode'] ?? 'whitelist_only'),
                'live_max_positions' => (int)($userLimits['live_max_positions'] ?? 3),
                'live_one_trade_per_symbol' => (bool)($userLimits['live_one_trade_per_symbol'] ?? true),
                'live_entry_policy' => (string)($userLimits['live_entry_policy'] ?? 'enter_now'),
                'live_reverse_side_enabled' => (bool)($userLimits['live_reverse_side_enabled'] ?? false),
                // Normalized: canonical trailing contract is in exit_policy above.
                // This summary carries only the essential mode/enabled for quick reference.
                'live_trailing_contract' => [
                    'canonical_source' => 'exit_policy',
                    'trailing_enabled' => (bool)($userLimits['trailing_enabled'] ?? false),
                    'trailing_mode' => $trailingMode,
                    'active_trailing_fields' => $activeTrailingFields,
                    'break_even_enabled' => (bool)($userLimits['break_even_enabled'] ?? false),
                    'exit_mode' => (string)($userLimits['exit_mode'] ?? 'fixed_tp'),
                ],
            ],
            'pattern_selection' => [
                'enabled' => (array)(($this->config['parser4']['pattern_algorithms'] ?? [])['enabled'] ?? []),
                'mode' => (string)(($this->config['parser4']['pattern_algorithms'] ?? [])['mode'] ?? 'one'),
            ],
            'parser4' => $this->get('parser4', []),
            'corridor' => $this->getEffective('corridor'),
            'risk_engine' => $this->getEffective('risk_engine'),
            'profiles' => $this->getEffective('profiles'),
            'simulator' => $this->getEffective('simulator'),
            'ui' => $this->getEffective('ui'),
            'generated_at' => date('c'),
        ];
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
     */
    private function applyUserConfig(): void
    {
        $saved = $this->loadUserConfig();
        if (empty($saved)) {
            return;
        }

        if (!isset($this->config['risk_engine']['user_limits'])) {
            $this->config['risk_engine']['user_limits'] = [];
        }

        $this->config['risk_engine']['user_limits'] = array_merge(
            $this->config['risk_engine']['user_limits'],
            $saved
        );

        // Apply pattern selection from user config into parser4 config
        if (isset($saved['patterns']) && is_array($saved['patterns'])) {
            $this->applyPatternSelection($saved['patterns']);
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
}
