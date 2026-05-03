<?php

declare(strict_types=1);

/**
 * Dynamic Strategies — Source Adapter Interface
 *
 * Every source strategy adapter must implement this interface so that
 * DynamicStrategiesStrategy can collect contexts without knowing the
 * internal structure of any specific strategy module.
 *
 * SAFETY CONTRACT:
 *   - Adapters MUST NOT modify source strategy storage.
 *   - Adapters MUST NOT open orders or execute trades.
 *   - Adapters are read-only consumers of existing artifacts.
 *   - Missing files must be handled gracefully (return empty contexts).
 */

namespace Modules\Strategy\DynamicStrategies\Sources;

interface DynamicSourceAdapterInterface
{
    /**
     * Unique identifier of the source strategy (e.g. 'double_bottom_long').
     */
    public function id(): string;

    /**
     * Whether this adapter should run given the merged Dynamic Strategies config.
     */
    public function enabled(array $config): bool;

    /**
     * Collect contexts from the source strategy's existing storage artifacts.
     *
     * Returns an array with three keys:
     *
     *   'contexts' => array of normalized Dynamic context records.
     *   'stats'    => associative array of per-adapter diagnostics.
     *   'errors'   => list of non-fatal error messages (file read failures, etc.)
     *
     * Every context in 'contexts' follows the common Dynamic context schema:
     *   - context_id         string     dedupe key
     *   - source_strategy    string     e.g. 'double_bottom_long'
     *   - source_module      string     module path
     *   - source_artifact    string     which file/key the data came from
     *   - source_signal_id   string|null
     *   - symbol             string
     *   - source_side        string     'long' or 'short' (source strategy side)
     *   - observed_at        string     ISO-8601 timestamp
     *   - context_type       string     e.g. 'falling_knife_reject'
     *   - dynamic_side_hint  string     'short' for most directional contexts
     *   - current_price      float|null
     *   - entry_price        float|null
     *   - neckline_level     float|null
     *   - reclaim_level      float|null
     *   - base_low           float|null
     *   - base_high          float|null
     *   - setup_class        string|null
     *   - synthetic_quality_score float|null
     *   - candidate_quality_score float|null
     *   - warnings           string[]
     *   - reason_codes       string[]
     *   - failed_stage       string|null
     *   - reject_reason      string|null
     *   - close_roi          float|null  (for trade-sourced contexts)
     *   - close_reason       string|null (for trade-sourced contexts)
     *   - close_guard        string|null (for trade-sourced contexts)
     *   - diagnostic_only    bool       always true — no trading action
     */
    public function collect(array $config): array;
}
