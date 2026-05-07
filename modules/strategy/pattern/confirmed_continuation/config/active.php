<?php

declare(strict_types=1);

/**
 * Confirmed Continuation Strategy — Active Config Overrides
 * Written by the admin UI. Edit via the config page.
 *
 * Demo-domain safety: strategy signals are environment-neutral.
 * Bot owns execution mode globally.
 */

return [
    'enabled'         => true,
    'handoff_enabled' => true,
    'batch_size'      => 50,
    'max_symbols_per_run' => 50,

    // OBC gate: safe defaults
    'orderbook_wall_gate_enabled'               => true,
    'orderbook_wall_gate_mode'                  => 'soft_demote',
    'orderbook_wall_soft_demote_blocks_handoff' => true,
];
