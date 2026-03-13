<?php
declare(strict_types=1);

/**
 * Parser 1.5: History Sync — Manifest
 *
 * Backend-only module (no UI routes)
 */

return [
    'id' => 'parser15_history_sync',
    'name' => 'Parser 1.5 — History Sync',
    'version' => '1.0.0',
    'type' => 'parser',
    'description' => 'Backfill and gap repair for Parser2 history. Fetches missing 1-minute klines from Bybit API.',
    'order' => 1.5,
];

/* RULES
- Informational manifest
- No routes / no UI here
*/
