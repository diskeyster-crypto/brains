<?php
declare(strict_types=1);

/**
 * Parser 2: History Accumulator — Manifest
 *
 * Backend-only module (no UI routes)
 */

return [
    'id' => 'parser2_history_accumulator',
    'name' => 'Parser 2 — History Accumulator',
    'version' => '1.0.0',
    'type' => 'parser',
    'description' => 'Accumulates compact Bybit ticker snapshots for active symbols (Parser1) using public API.',
];

/* RULES
- Informational manifest
- No routes / no UI here
*/
