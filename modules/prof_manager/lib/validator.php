<?php

declare(strict_types=1);

namespace Modules\ProfManager\Lib;

/**
 * Validator
 *
 * Validates positions before Profit Manager processes them.
 * Invalid positions are skipped with a structured reason.
 */
class Validator
{
    /**
     * Validate a single position array.
     *
     * Required fields:
     *   - symbol
     *   - side  (long|short|buy|sell)
     *   - entry_price OR avg_price  > 0
     *   - size > 0
     *   - leverage > 0
     *   - current_price OR mark_price (if available, used for ROI; absence triggers a warning)
     *
     * @param array $position
     * @return array{ok: bool, errors: list<string>, warnings: list<string>}
     */
    public function validatePosition(array $position): array
    {
        $errors   = [];
        $warnings = [];

        // symbol
        if (empty($position['symbol']) || !is_string($position['symbol'])) {
            $errors[] = 'missing_symbol';
        }

        // side
        $side = strtolower(trim($position['side'] ?? ''));
        if (!in_array($side, ['long', 'short', 'buy', 'sell'], true)) {
            $errors[] = 'invalid_side';
        }

        // entry_price / avg_price
        $entryPrice = (float) ($position['entry_price'] ?? $position['avg_price'] ?? 0.0);
        if ($entryPrice <= 0.0) {
            $errors[] = 'missing_or_zero_entry_price';
        }

        // size
        $size = (float) ($position['size'] ?? 0.0);
        if ($size <= 0.0) {
            $errors[] = 'zero_or_negative_size';
        }

        // leverage
        $leverage = (float) ($position['leverage'] ?? 0.0);
        if ($leverage <= 0.0) {
            $errors[] = 'zero_or_negative_leverage';
        }

        // current_price / mark_price — not fatal but ROI cannot be computed without it
        $currentPrice = (float) ($position['current_price'] ?? $position['mark_price'] ?? 0.0);
        if ($currentPrice <= 0.0) {
            $warnings[] = 'no_current_price_roi_unavailable';
        }

        return [
            'ok'       => empty($errors),
            'errors'   => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Normalize the side field to canonical form.
     *
     * @param string $side
     * @return string 'long' | 'short' | ''
     */
    public function normalizeSide(string $side): string
    {
        $s = strtolower(trim($side));
        if ($s === 'buy') {
            return 'long';
        }
        if ($s === 'sell') {
            return 'short';
        }
        return $s;
    }
}
