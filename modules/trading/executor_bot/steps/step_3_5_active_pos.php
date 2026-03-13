<?php
declare(strict_types=1);

/**
 * Executor Bot — Step 3.5: Active Position Guard
 *
 * PURPOSE:
 * - Checks REAL Bybit positions
 * - If there is already an open position for this symbol -> EXIT
 *
 * RULE:
 * - NO local storage
 * - NO cache
 * - ONLY live Bybit API
 */

$root = dirname(__DIR__, 4);
require_once $root . '/core/bybit.php';

use Core\Bybit;

/**
 * @param array<string,mixed> $input (result of step_3_calculator)
 * @return array<string,mixed>
 */
function step_3_5_active_pos(array $input): array
{
    try {
        if (($input['success'] ?? false) !== true) {
            return [
                'success' => true,
                'step'    => 'active_pos_check',
                'passed'  => false,
                'reason'  => 'previous_step_failed',
            ];
        }

        $symbol = strtoupper(trim((string)($input['symbol'] ?? '')));
        if ($symbol === '') {
            return [
                'success' => false,
                'step'    => 'active_pos_check',
                'passed'  => false,
                'reason'  => 'empty_symbol',
            ];
        }

        /* ==================================================
           LIVE BYBIT CHECK
           ================================================== */

        $client = Bybit::client();

        if (!$client) {
            return [
                'success' => false,
                'step'    => 'active_pos_check',
                'passed'  => false,
                'reason'  => 'bybit_client_null',
            ];
        }

        $resp = $client->get('/v5/position/list', [
            'category' => 'linear',
        ]);

        $list = $resp['result']['list'] ?? [];

        foreach ($list as $pos) {
            if (
                strtoupper((string)$pos['symbol']) === $symbol &&
                (float)$pos['size'] > 0
            ) {
                return [
                    'success' => true,
                    'step'    => 'active_pos_check',
                    'passed'  => false,
                    'reason'  => 'active_position_exists',
                    'position'=> [
                        'symbol' => $pos['symbol'],
                        'side'   => $pos['side'],
                        'size'   => $pos['size'],
                        'entry'  => $pos['avgPrice'] ?? null,
                    ],
                ];
            }
        }

        /* ==================================================
           NO ACTIVE POSITION -> OK
           ================================================== */

        return [
            'success' => true,
            'step'    => 'active_pos_check',
            'passed'  => true,
            'reason'  => null,
        ];

    } catch (\Throwable $e) {
        return [
            'success' => false,
            'step'    => 'active_pos_check',
            'passed'  => false,
            'reason'  => 'exception: ' . $e->getMessage(),
        ];
    }
}

/*
====================================================================
RULES (Tredercopis)
====================================================================
- This step uses ONLY live Bybit API
- No storage, no cache, no snapshots
- size > 0 is the ONLY valid condition
- If passed=false -> runner must EXIT chain
====================================================================
*/
