<?php
declare(strict_types=1);

/**
 * Executor Bot — Step 8: Stop Manager
 *
 * Sets REAL stop-loss on Bybit for opened position
 *
 * Uses:
 *   POST /v5/position/trading-stop
 */

require_once dirname(__DIR__, 4) . '/core/bybit.php';

use Core\Bybit;

function step_8_set_stop(string $builtFile): array
{
    $ts = date('c');

    $log = [
        'ts' => $ts,
        'phase' => 'step_8_set_stop',
        'built_file' => $builtFile,
        'errors' => [],
        'debug' => [],
    ];

    if ($builtFile === '' || !is_file($builtFile)) {
        return [
            'success' => false,
            'step' => 'set_stop',
            'intent_id' => null,
            'error' => 'built_file_not_found',
            'log' => $log,
        ];
    }

    $task = json_decode((string)file_get_contents($builtFile), true);

    if (!is_array($task)) {
        return [
            'success' => false,
            'step' => 'set_stop',
            'intent_id' => null,
            'error' => 'invalid_built_json',
            'log' => $log,
        ];
    }

    $intentId = (string)($task['intent_id'] ?? '');
    $symbol   = (string)($task['symbol'] ?? '');
    $risk     = $task['risk'] ?? [];

    if ($intentId === '' || $symbol === '' || !is_array($risk)) {
        return [
            'success' => false,
            'step' => 'set_stop',
            'intent_id' => $intentId ?: null,
            'error' => 'invalid_task_contract',
            'log' => $log,
        ];
    }

    $stopPrice = (float)($risk['stop_price'] ?? 0);

    if ($stopPrice <= 0) {
        return [
            'success' => false,
            'step' => 'set_stop',
            'intent_id' => $intentId,
            'error' => 'stop_price_missing',
            'log' => $log,
        ];
    }

    try {
        $client = Bybit::client();

        if (!$client) {
            throw new RuntimeException('Bybit client is NULL');
        }

        $payload = [
            'category' => 'linear',
            'symbol' => $symbol,
            'stopLoss' => (string)$stopPrice,
        ];

        $log['debug']['api_payload'] = $payload;

        $response = $client->post('/v5/position/trading-stop', $payload);
        $log['debug']['api_response'] = $response;

        if (($response['retCode'] ?? 1) !== 0) {
            return [
                'success' => false,
                'step' => 'set_stop',
                'intent_id' => $intentId,
                'error' => 'bybit_rejected',
                'log' => $log,
            ];
        }

    } catch (Throwable $e) {
        $log['errors'][] = $e->getMessage();

        return [
            'success' => false,
            'step' => 'set_stop',
            'intent_id' => $intentId,
            'error' => 'bybit_exception',
            'log' => $log,
        ];
    }

    // snapshot
    $outDir = dirname(__DIR__) . '/storage/checks';
    if (!is_dir($outDir)) {
        mkdir($outDir, 0777, true);
    }

    $outFile = $outDir . '/stop_' . $intentId . '.json';

    $snapshot = [
        'ts' => $ts,
        'step' => 'set_stop',
        'intent_id' => $intentId,
        'symbol' => $symbol,
        'stop_price' => $stopPrice,
        'status' => 'stop_set',
        'exchange' => 'bybit',
        'log' => $log,
    ];

    file_put_contents(
        $outFile,
        json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    return [
        'success' => true,
        'step' => 'set_stop',
        'intent_id' => $intentId,
        'output_file' => $outFile,
        'error' => null,
        'log' => $log,
    ];
}
