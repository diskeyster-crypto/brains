<?php

declare(strict_types=1);

/**
 * OrderBook Wall Context — Base Config
 *
 * Shared helper that fetches Bybit public orderbook snapshots, detects
 * significant size walls, tracks wall persistence/eaten/broken state,
 * and provides wall context for:
 *   - Profit Manager exit decisions (ask-wall rejection / bid-wall rejection)
 *   - Dynamic Strategies entry gate (block entry near persistent walls)
 *
 * Override individual values in active.php without touching this file.
 *
 * SAFETY:
 *   - Walls are NEVER a sole entry or exit signal.
 *   - Stop Manager emergency stops are NOT affected.
 *   - Orderbook is fetched only for active positions / current candidates.
 *   - Cached per symbol for cache_ttl_seconds to limit API calls.
 */

return [
    // ── Feature gate ─────────────────────────────────────────────────────────
    'orderbook_wall_enabled'               => true,

    // ── Orderbook fetch settings ──────────────────────────────────────────────
    // Bybit public endpoint: GET /v5/market/orderbook?category=linear&symbol=X&limit=200
    'orderbook_wall_limit'                 => 200,    // levels to fetch per side
    'orderbook_wall_cache_ttl_seconds'     => 5,      // in-memory + file cache TTL per symbol

    // ── Wall detection window ─────────────────────────────────────────────────
    // Only inspect levels within [min_distance_pct, max_distance_pct] from current price.
    'orderbook_wall_min_distance_pct'      => 0.05,   // ignore levels < 0.05% from price
    'orderbook_wall_max_distance_pct'      => 1.5,    // ignore levels > 1.5% from price

    // ── Wall size criteria ────────────────────────────────────────────────────
    // A level is a "wall" candidate when:
    //   notional >= average_notional_in_window * size_multiple
    //   OR notional >= min_notional_usdt
    'orderbook_wall_size_multiple'         => 4.0,    // must be 4× the local average
    'orderbook_wall_min_notional_usdt'     => 5000.0, // or at least $5000 notional

    // ── Cluster merging ───────────────────────────────────────────────────────
    // Group levels within cluster_pct of each other and sum their notionals.
    'orderbook_wall_cluster_pct'           => 0.10,   // cluster radius in % of price

    // ── Wall persistence thresholds ───────────────────────────────────────────
    // A wall is "persistent" only when seen across multiple checks.
    'wall_persistence_min_seen_count'      => 2,      // must be seen >= 2 ticks
    'wall_persistence_min_seconds'         => 10,     // and span >= 10 seconds

    // ── Wall eaten / broken detection ─────────────────────────────────────────
    // "Eaten": notional drops by >= eaten_notional_drop_pct% while price approaches.
    // "Broken": price crosses through the wall level by break_price_through_pct%.
    'wall_eaten_notional_drop_pct'         => 60.0,   // 60% notional drop → eaten
    'wall_break_price_through_pct'         => 0.05,   // price 0.05% past wall → broken

    // ── Entry wall gate (Dynamic Strategies) ─────────────────────────────────
    // When enabled, blocks or demotes signals whose entry price is too close to
    // a persistent wall on the opposing side.
    //   Long entry + ask wall above → resistance gate.
    //   Short entry + bid wall below → support gate.
    'entry_wall_gate_enabled'              => true,
    'entry_wall_block_distance_pct'        => 0.8,    // block if wall within 0.8% of entry
    'entry_wall_require_persistent'        => true,   // only block for persistent walls
    'entry_wall_demote_instead_of_reject'  => true,   // demote (lower confidence) instead of hard reject

    // ── PM wall exit (Profit Manager) ─────────────────────────────────────────
    // Close or tighten lock when a profitable position approaches an unbroken wall.
    //   Long position + ask wall above → ask_wall_rejection_profit_exit.
    //   Short position + bid wall below → bid_wall_rejection_profit_exit.
    'wall_exit_enabled'                    => true,
    'wall_exit_min_roi'                    => 6.0,    // only act if position ROI >= 6%
    'wall_exit_distance_pct'               => 0.6,    // wall must be within 0.6% of price
    'wall_exit_fail_checks'                => 2,      // price must have failed to break >= 2 ticks
    'wall_exit_action'                     => 'close_or_tighten',  // close_or_tighten | close | tighten
    'wall_exit_tighten_lock_buffer_roi'    => 2.0,    // when tightening: new lock = roi - buffer
];
