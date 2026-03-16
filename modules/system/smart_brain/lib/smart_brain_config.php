<?php
declare(strict_types=1);

final class SmartBrainConfig
{
    private const ALLOWED_PATTERN_ALGORITHMS = ['double_bottom', 'double_top', 'pullback_trend_continue'];

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
        $validStopControlModes = ['manual', 'auto'];
        if (isset($values['stop_control_mode']) && !in_array((string)$values['stop_control_mode'], $validStopControlModes, true)) {
            $errors[] = 'stop_control_mode must be one of: manual, auto';
        }
        if (isset($values['manual_stop_loss_roi']) && (float)$values['manual_stop_loss_roi'] <= 0) {
            $errors[] = 'manual_stop_loss_roi must be > 0';
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
     * Build full effective config snapshot for runtime output.
     *
     * @return array<string,mixed>
     */
    public function buildEffectiveSnapshot(): array
    {
        $userLimits = $this->getUserLimits();
        return [
            'user_limits' => $userLimits,
            'brain_auto' => $this->getBrainAutoValues(),
            'exit_policy' => [
                'exit_mode' => $userLimits['exit_mode'] ?? 'fixed_tp',
                'stop_floor_type' => $userLimits['stop_floor_type'] ?? 'roi_percent',
                'stop_floor_value' => (float)($userLimits['stop_floor_value'] ?? 0.03),
                'brain_may_tighten_stop' => (bool)($userLimits['brain_may_tighten_stop'] ?? true),
                'trailing_enabled' => (bool)($userLimits['trailing_enabled'] ?? false),
                'trailing_activation_roi' => (float)($userLimits['trailing_activation_roi'] ?? 0.02),
                'trailing_min_lock_roi' => (float)($userLimits['trailing_min_lock_roi'] ?? 0.005),
                'trailing_min_step' => (float)($userLimits['trailing_min_step'] ?? 0.005),
                'brain_may_delay_trailing' => (bool)($userLimits['brain_may_delay_trailing'] ?? false),
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
