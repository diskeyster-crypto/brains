<?php

declare(strict_types=1);

return [
    'enabled' => true,
    'mode' => 'diagnostic_only',
    'supported_strategy_id' => 'early_impulse_growth_long',

    'collect_entry_snapshots_enabled' => true,
    'observe_active_positions_enabled' => true,
    'analyze_closed_outcomes_enabled' => true,
    'build_dynamic_profile_enabled' => true,

    'apply_learning_to_strategy_enabled' => false,
    'apply_learning_to_live_enabled' => false,
    'apply_learning_to_demo_enabled' => false,

    'observation_interval_seconds' => 30,
    'max_observations_per_position' => 40,
    'observe_orderbook_enabled' => true,
    'observe_open_interest_enabled' => true,
    'observe_price_enabled' => true,

    'bad_drawdown_roi_threshold' => -10.0,
    'good_close_roi_threshold' => 5.0,
    'good_max_profit_roi_threshold' => 5.0,
    'neutral_close_roi_min' => -2.0,
    'neutral_close_roi_max' => 2.0,

    'min_closed_outcomes_for_profile' => 10,
    'min_bad_entries_for_rule' => 2,
    'min_bad_blocked_for_rule' => 2,
    'max_good_blocked_for_rule' => 1,
    'min_rule_net_score' => 1.0,

    'rollback_guard_enabled' => true,
    'rollback_drawdown_pct' => 7.0,
    'rollback_bad_trade_streak' => 3,
    'profile_history_enabled' => true,
];
