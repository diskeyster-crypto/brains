<?php
declare(strict_types=1);

/**
 * Executor Bot — Config Schema (UI)
 *
 * Defines which keys are editable from Admin UI.
 * Anything not listed is preserved by ConfigGuard.
 *
 * @return array<string,mixed>
 */
return [
    'editable' => [
        'module.enabled',
        'module.mode',
        'module.account_id',
        'module.category',

        'limits.max_signals_per_run',
        'limits.min_score',
        'limits.enforce_expires_at',
        'limits.blocked_symbols',
        'limits.budget_per_position_usd',
        'limits.leverage',

        'order.type',
        'order.limit_offset',
        'order.set_tp_sl',
    ],
];

/* RULES
- Schema is used by config guard / UI layer
- Only keys listed here may be modified from UI
*/
