<?php
declare(strict_types=1);

return [
    'mode' => 'manual',
    'settings' => [
        'enabled' => true,
        'risk_levels' => [
            ['max_corridor_width' => 0.010, 'leverage' => 6, 'budget_factor' => 0.90],
            ['max_corridor_width' => 0.025, 'leverage' => 5, 'budget_factor' => 0.75],
            ['max_corridor_width' => 0.050, 'leverage' => 4, 'budget_factor' => 0.60],
            ['max_corridor_width' => 1.000, 'leverage' => 3, 'budget_factor' => 0.45],
        ],
        'default_stop_loss_percent' => 0.05,
    ],
    'auto_rules' => [
        'allow_brain_override' => false,
        'override_fields' => [],
    ],
    'user_limits' => [
        'max_budget_per_coin' => 10.0,
        'max_active_tasks' => 5,
        'max_leverage' => 6,
        'brain_mode' => 'balanced',
        'bootstrap_enabled' => true,
        'bootstrap_max_signals' => 8,
        'bootstrap_budget_factor' => 0.35,
        'bootstrap_max_leverage' => 3,
        'min_reliability_after_warmup' => 0.12,
        'warmup_min_trades' => 6,
        'stop_floor_type' => 'corridor_percent',
        'stop_floor_value' => 0.30,
        'brain_may_tighten_stop' => true,
        'trailing_enabled' => true,
        'trailing_activation_roi' => 0.05,
        'trailing_min_lock_roi' => 0.012,
        'trailing_min_step' => 0.01,
        'brain_may_delay_trailing' => true,
        'fixed_take_profit_roi' => 0.03,
        'exit_mode' => 'hybrid_tp',
        'hybrid_tp_share' => 0.40,
        'break_even_enabled' => true,
        'break_even_activation_roi' => 0.025,
        'logical_stop_roi' => 0.03,
        // MAE-based adaptive logical stop
        'mae_stop_enabled' => true,
        'mae_stop_floor' => 0.03,
        'mae_stop_cap' => 0.08,
        'mae_stop_min_trades' => 10,
        'mae_stop_min_winners' => 5,
        'mae_stop_percentile' => 75,
        // Stop Control
        'stop_control_mode' => 'auto',
        'manual_stop_loss_roi' => 0.03,
        'stop_loss_from_entry_roi' => 0.10,
        // Stop Loss Engine V2
        'stop_mode' => 'brain_managed',
        'simple_stop_liq_factor' => 0.15,
        'brain_stop_corridor_factor' => 0.25,
        'brain_stop_volatility_factor' => 0.50,
        'brain_stop_liq_safety_factor' => 0.30,
        // Early Failure Guard
        'early_failure_enabled' => true,
        'early_failure_window_minutes' => 5,
        'early_failure_max_adverse_roi' => -0.008,
        // V2 Confirmation Tier Policy
        'v2_confirmation_weak_max' => 0.45,
        'v2_confirmation_strong_min' => 0.75,
        'v2_zone_widen_weak_pct' => 0.50,
        'v2_zone_widen_medium_pct' => 0.65,
        'v2_zone_widen_strong_pct' => 0.80,
        'v2_zone_widen_max_cap_pct' => 0.85,
        // V2 Entry Policy
        'strong_confirmation_enter_now_enabled' => true,
        'medium_confirmation_wait_retrace_enabled' => true,
        'weak_confirmation_live_enabled' => false,
        // V2 Quality Floors
        'v2_hold_quality_min' => 0.65,
        'v2_post_reclaim_stability_min' => 0.65,
        'v2_zone_defense_min' => 0.35,
        'v2_trend_match_min' => 0.50,
        'v2_price_position_max' => 0.88,
        // V2 Live Quality Floor — applied to V2 signals before live approval
        'v2_live_quality_floor_enabled' => true,
        'v2_live_min_confirmation_score' => 0.55,
        'v2_live_min_pattern_confidence' => 0.50,
        'v2_live_min_trend_match_score' => 0.40,
        // Sniper V3 Live Filters — applied ONLY when execution_profile = sniper_75_attempt AND pattern = V3
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
        // Short-side V3 overrides (softer thresholds for short V3 structural signals)
        'sniper_v3_min_trend_match_score_short' => 0.40,
        'sniper_v3_min_entry_quality_score_short' => 0.60,
        'sniper_v3_min_corridor_fit_score_short' => 0.60,
        // Slot Priority Layer — time-aware candidate ranking for limited live slots
        // Ranks competing candidates when approved signals exceed available slots.
        // Does NOT raise slot limits or bypass any hard gate.
        'slot_priority_enabled'                 => true,
        'freshness_decay_enabled'               => true,
        'slot_priority_freshness_window_minutes' => 30,
    ],
];
