<?php

declare(strict_types=1);

/**
 * Double Bottom Long Strategy — Active Config Overrides
 * Written by the admin UI. Edit via the config page.
 *
 * Demo-domain safety: mode is explicitly set to 'demo' here so that even if
 * the admin UI accidentally writes 'live', a deploy from this repo corrects it.
 * Do NOT change mode to 'live' in this file.
 */

return [
    'mode'               => 'demo',    // demo | passive — never live in this repo
    'enabled'            => true,
    'handoff_enabled'    => true,
    'batch_size'         => 200,
    'max_symbols_per_run'=> 200,
];
