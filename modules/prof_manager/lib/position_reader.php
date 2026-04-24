<?php

declare(strict_types=1);

namespace Modules\ProfManager\Lib;

/**
 * PositionReader
 *
 * Reads active/open positions from the bot module's paper runtime source.
 * Tries multiple candidate paths in order; uses the first non-empty valid source.
 * Returns an empty list with diagnostics when no compatible source is found.
 *
 * Does NOT invent fake positions.
 */
class PositionReader
{
    private string $repoRoot;

    public function __construct(string $repoRoot)
    {
        $this->repoRoot = rtrim($repoRoot, '/');
    }

    /**
     * Read active positions.
     *
     * @return array{positions: list<array>, source: string, diagnostics: array}
     */
    public function read(): array
    {
        // Fixed candidate paths
        $fixedCandidates = [
            'modules/bot/storage/active_positions.json',
        ];

        // Dynamic candidates: glob strategy sub-modules
        $strategyCandidates = $this->resolveStrategyCandidates();

        // Executor bot last_run as fallback
        $executorCandidates = [
            'modules/trading/executor_bot/storage/last_run.json',
        ];

        $allCandidates = array_merge($fixedCandidates, $strategyCandidates, $executorCandidates);
        $tried = [];

        foreach ($allCandidates as $relPath) {
            $absPath = $this->repoRoot . '/' . $relPath;
            $tried[] = $relPath;

            if (!is_file($absPath) || !is_readable($absPath)) {
                continue;
            }

            $raw = file_get_contents($absPath);
            if ($raw === false || $raw === '') {
                continue;
            }

            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }

            // executor_bot last_run may have a 'positions' key
            if (str_contains($relPath, 'last_run.json') && isset($decoded['positions']) && is_array($decoded['positions'])) {
                $decoded = $decoded['positions'];
            }

            $raw_positions = array_values($decoded);
            if (empty($raw_positions)) {
                continue;
            }

            $positions = $this->normalizePositions($raw_positions);

            return [
                'positions'   => $positions,
                'source'      => $relPath,
                'diagnostics' => [
                    'path'  => $absPath,
                    'count' => count($positions),
                    'tried' => $tried,
                ],
            ];
        }

        return [
            'positions'   => [],
            'source'      => 'none',
            'diagnostics' => [
                'reason'     => 'no_positions_source_found',
                'candidates' => $allCandidates,
                'tried'      => $tried,
            ],
        ];
    }

    /**
     * Resolve dynamic strategy sub-module candidates.
     *
     * @return list<string>
     */
    private function resolveStrategyCandidates(): array
    {
        $candidates = [];
        $strategyRoot = $this->repoRoot . '/modules/strategy';

        if (!is_dir($strategyRoot)) {
            return $candidates;
        }

        $dirs = glob($strategyRoot . '/*/storage', GLOB_ONLYDIR);
        if (!is_array($dirs)) {
            return $candidates;
        }

        foreach ($dirs as $storageDir) {
            $base = str_replace($this->repoRoot . '/', '', $storageDir);
            $candidates[] = $base . '/bot_active_positions.json';
            $candidates[] = $base . '/active_positions.json';
        }

        return $candidates;
    }

    /**
     * Normalize position fields to a canonical form.
     *
     * Supports common field-name aliases used across project position sources:
     *   size       : size, qty, quantity, position_size, positionQty, contracts, amount, bot_budget
     *   leverage   : leverage, lev, bot_leverage, position_leverage
     *   entry_price: entry_price, avg_price, avgPrice, average_price, entryPrice
     *   current_price: current_price, mark_price, markPrice, last_price, price, currentPrice,
     *                  runtime.current_price, market.mark_price
     *   pnl        : unrealised_pnl, unrealized_pnl, unrealisedPnl, unrealizedPnl, pnl
     *
     * Nested unwrapping: position.*, data.*
     * Original data preserved under _raw.
     *
     * If current_price is still missing, position is tagged with _no_price_data.
     *
     * @param list<mixed> $raw
     * @return list<array>
     */
    private function normalizePositions(array $raw): array
    {
        $result = [];
        foreach ($raw as $pos) {
            if (!is_array($pos)) {
                continue;
            }

            // ── Unwrap common nesting ─────────────────────────────────────────
            if (!isset($pos['symbol']) && isset($pos['position']) && is_array($pos['position'])) {
                $nested = $pos['position'];
                unset($pos['position']);
                $pos = array_merge($nested, $pos);
            }
            if (!isset($pos['symbol']) && isset($pos['data']) && is_array($pos['data'])) {
                $nested = $pos['data'];
                unset($pos['data']);
                $pos = array_merge($nested, $pos);
            }

            // ── Store original data for diagnostics ───────────────────────────
            if (!isset($pos['_raw'])) {
                $pos['_raw'] = $pos;
            }

            // ── size ──────────────────────────────────────────────────────────
            if (!isset($pos['size']) || (float) $pos['size'] <= 0.0) {
                foreach (['qty', 'quantity', 'position_size', 'positionQty', 'contracts', 'amount', 'bot_budget'] as $alias) {
                    if (isset($pos[$alias]) && (float) $pos[$alias] > 0.0) {
                        $pos['size'] = $pos[$alias];
                        break;
                    }
                }
            }

            // ── leverage ──────────────────────────────────────────────────────
            if (!isset($pos['leverage']) || (float) $pos['leverage'] <= 0.0) {
                foreach (['lev', 'bot_leverage', 'position_leverage'] as $alias) {
                    if (isset($pos[$alias]) && (float) $pos[$alias] > 0.0) {
                        $pos['leverage'] = $pos[$alias];
                        break;
                    }
                }
            }

            // ── entry_price ───────────────────────────────────────────────────
            if (!isset($pos['entry_price']) || (float) $pos['entry_price'] <= 0.0) {
                foreach (['avg_price', 'avgPrice', 'average_price', 'entryPrice'] as $alias) {
                    if (isset($pos[$alias]) && (float) $pos[$alias] > 0.0) {
                        $pos['entry_price'] = $pos[$alias];
                        break;
                    }
                }
            }

            // ── current_price ─────────────────────────────────────────────────
            if (!isset($pos['current_price']) || (float) $pos['current_price'] <= 0.0) {
                foreach (['mark_price', 'markPrice', 'last_price', 'price', 'currentPrice'] as $alias) {
                    if (isset($pos[$alias]) && (float) $pos[$alias] > 0.0) {
                        $pos['current_price'] = $pos[$alias];
                        break;
                    }
                }
            }
            // Nested: runtime.current_price
            if ((!isset($pos['current_price']) || (float) $pos['current_price'] <= 0.0)
                && isset($pos['runtime']['current_price']) && (float) $pos['runtime']['current_price'] > 0.0) {
                $pos['current_price'] = $pos['runtime']['current_price'];
            }
            // Nested: market.mark_price
            if ((!isset($pos['current_price']) || (float) $pos['current_price'] <= 0.0)
                && isset($pos['market']['mark_price']) && (float) $pos['market']['mark_price'] > 0.0) {
                $pos['current_price'] = $pos['market']['mark_price'];
            }

            // ── unrealised_pnl ────────────────────────────────────────────────
            if (!isset($pos['unrealised_pnl'])) {
                foreach (['unrealized_pnl', 'unrealisedPnl', 'unrealizedPnl', 'pnl'] as $alias) {
                    if (isset($pos[$alias])) {
                        $pos['unrealised_pnl'] = $pos[$alias];
                        break;
                    }
                }
            }

            // ── tag positions lacking price data ──────────────────────────────
            $currentPrice = (float) ($pos['current_price'] ?? 0.0);
            if ($currentPrice <= 0.0) {
                $pos['_no_price_data'] = true;
            }

            $result[] = $pos;
        }
        return $result;
    }
}
