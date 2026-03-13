<?php

declare(strict_types=1);

namespace Modules\Simulator\Parser6Simulator\Lib;

/**
 * Parser6Validator - Unified signal and trade validation for Simulator
 * 
 * P6.4: Shared validation for clean_signal_v1 and sim_trade_v1 schemas
 * Validates incoming signals and trade data to ensure they meet contract requirements.
 */
class Parser6Validator
{
    /**
     * Schema versions
     */
    public const SIGNAL_SCHEMA_VERSION = 'clean_signal_v1';
    public const TRADE_SCHEMA_VERSION = 'sim_trade_v1';
    public const RISK_SCHEMA_VERSION = 'risk_active_v1';

    /**
     * Required fields for incoming signals
     */
    private const REQUIRED_SIGNAL_FIELDS = [
        'id',
        'symbol',
    ];

    /**
     * Required fields for risk block in CLEAN mode
     */
    private const REQUIRED_RISK_FIELDS = [
        'profile_id',
        'position_size',
        'stop_loss',
        'take_profit',
    ];

    /**
     * Required fields for sim_trade_v1 schema
     */
    private const REQUIRED_TRADE_FIELDS = [
        'trade_id',
        'symbol',
        'side',
        'entry',
        'opened_ts',
    ];

    /**
     * Validate a signal for processing
     * P6.1.3: signal.id is strictly required - no fallback generation
     *
     * @param array $signal Signal to validate
     * @return array Validation result with 'valid' boolean and errors
     */
    public static function validateSignal(array $signal): array
    {
        $result = [
            'valid' => true,
            'missing_fields' => [],
            'errors' => [],
        ];

        // P6.1.3: Strict id validation - no fallback
        foreach (self::REQUIRED_SIGNAL_FIELDS as $field) {
            if (!isset($signal[$field]) || $signal[$field] === '' || $signal[$field] === null) {
                $result['valid'] = false;
                $result['missing_fields'][] = $field;
                $result['errors'][] = "missing_field:{$field}";
            }
        }

        // Validate id is a non-empty string
        if (isset($signal['id'])) {
            if (!is_string($signal['id']) || $signal['id'] === '') {
                $result['valid'] = false;
                $result['errors'][] = 'invalid_field_type:id';
            }
        }

        // Validate symbol is a non-empty string
        if (isset($signal['symbol'])) {
            if (!is_string($signal['symbol']) || $signal['symbol'] === '') {
                $result['valid'] = false;
                $result['errors'][] = 'invalid_field_type:symbol';
            }
        }

        return $result;
    }

    /**
     * Validate risk block for CLEAN mode signals
     *
     * @param array|null $riskBlock Risk configuration block
     * @return array Validation result
     */
    public static function validateRiskBlock(?array $riskBlock): array
    {
        $result = [
            'valid' => true,
            'missing_fields' => [],
            'errors' => [],
        ];

        if ($riskBlock === null || empty($riskBlock)) {
            $result['valid'] = false;
            $result['errors'][] = 'missing_field:risk';
            return $result;
        }

        // Check for required risk fields
        foreach (self::REQUIRED_RISK_FIELDS as $field) {
            if (!isset($riskBlock[$field])) {
                $result['missing_fields'][] = "risk.{$field}";
            }
        }

        // Validate position_size is positive
        if (isset($riskBlock['position_size'])) {
            $positionSize = (float)$riskBlock['position_size'];
            if ($positionSize <= 0) {
                $result['errors'][] = 'invalid_field_value:risk.position_size';
            }
        }

        // Check trailing block if present
        if (isset($riskBlock['trailing']) && is_array($riskBlock['trailing'])) {
            $trailing = $riskBlock['trailing'];
            if (isset($trailing['mode']) && !in_array($trailing['mode'], ['tight', 'normal', 'loose'], true)) {
                $result['errors'][] = 'invalid_field_value:risk.trailing.mode';
            }
        }

        if (!empty($result['missing_fields']) || !empty($result['errors'])) {
            $result['valid'] = false;
        }

        return $result;
    }

    /**
     * Validate a closed trade against sim_trade_v1 schema
     *
     * @param array $trade Trade data to validate
     * @return array Validation result
     */
    public static function validateTrade(array $trade): array
    {
        $result = [
            'valid' => true,
            'missing_fields' => [],
            'errors' => [],
        ];

        // Check required trade fields
        foreach (self::REQUIRED_TRADE_FIELDS as $field) {
            if (!isset($trade[$field])) {
                $result['missing_fields'][] = $field;
            }
        }

        // Validate trade_id
        if (isset($trade['trade_id'])) {
            if (!is_string($trade['trade_id']) || $trade['trade_id'] === '') {
                $result['errors'][] = 'invalid_field_type:trade_id';
            }
        }

        // Validate side
        if (isset($trade['side'])) {
            if (!in_array(strtolower($trade['side']), ['long', 'short'], true)) {
                $result['errors'][] = 'invalid_field_value:side';
            }
        }

        // Validate entry block
        if (isset($trade['entry']) && is_array($trade['entry'])) {
            if (!isset($trade['entry']['price']) || (float)$trade['entry']['price'] <= 0) {
                $result['errors'][] = 'invalid_field_value:entry.price';
            }
        }

        if (!empty($result['missing_fields']) || !empty($result['errors'])) {
            $result['valid'] = false;
        }

        return $result;
    }

    /**
     * Validate signal for CLEAN mode processing
     * Combines signal validation with risk block validation
     *
     * @param array $signal Signal to validate
     * @return array Validation result
     */
    public static function validateSignalForCleanMode(array $signal): array
    {
        $signalResult = self::validateSignal($signal);

        // CLEAN mode requires risk block
        if (!isset($signal['risk'])) {
            $signalResult['valid'] = false;
            $signalResult['missing_fields'][] = 'risk';
            $signalResult['errors'][] = 'missing_field:risk';
        } else {
            $riskResult = self::validateRiskBlock($signal['risk']);
            if (!$riskResult['valid']) {
                $signalResult['valid'] = false;
                $signalResult['missing_fields'] = array_merge(
                    $signalResult['missing_fields'],
                    $riskResult['missing_fields']
                );
                $signalResult['errors'] = array_merge(
                    $signalResult['errors'],
                    $riskResult['errors']
                );
            }
        }

        return $signalResult;
    }

    /**
     * Format validation errors for consistent output
     *
     * @param array $validationResult Result from validate* methods
     * @return string Formatted error string
     */
    public static function formatErrors(array $validationResult): string
    {
        if ($validationResult['valid']) {
            return '';
        }

        $parts = [];

        if (!empty($validationResult['missing_fields'])) {
            $parts[] = 'missing_field:' . implode(',', $validationResult['missing_fields']);
        }

        if (!empty($validationResult['errors'])) {
            foreach ($validationResult['errors'] as $error) {
                if (!str_starts_with($error, 'missing_field:')) {
                    $parts[] = $error;
                }
            }
        }

        return implode('; ', array_unique($parts));
    }
}
