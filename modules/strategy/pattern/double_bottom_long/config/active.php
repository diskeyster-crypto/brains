<?php

declare(strict_types=1);

/**
 * Double Bottom Long Strategy — Active Config Overrides
 * Written by the admin UI. Edit via the config page.
 *
 * Demo-domain safety: mode is explicitly set to 'demo' here so that even if
 * the admin UI accidentally writes 'live', a deploy from this repo corrects it.
 *
 * To enable live execution:
 *   1. Confirm the bot execution logic is fully wired and tested.
 *   2. Change mode to 'live' via the admin UI on the target server only.
 *   3. Do NOT commit 'live' into this file — keep repo state as 'demo'.
 */

return [
    'mode'               => 'demo',    // demo | passive — never live in this repo
    'enabled'            => true,
    'handoff_enabled'    => true,
    'batch_size'         => 50,
    'max_symbols_per_run'=> 50,

    // OrderBook wall context entry gate — safe demo defaults.
    // Mode: soft_demote (tag signals with wall risk; do not hard-reject).
    // Only fetch OBC for serious candidates (quality_score >= 0.72).
    'orderbook_entry_wall_gate_enabled'              => true,
    'orderbook_entry_wall_gate_mode'                 => 'soft_demote',
    'orderbook_entry_wall_fetch_after_quality_score' => 0.72,
    'orderbook_entry_wall_pending_enabled'           => false,
];
