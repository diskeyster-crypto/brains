<?php

declare(strict_types=1);

namespace Modules\System\Brain\Lib;

/**
 * BrainValidator - Unified signal validation for Brain
 * 
 * P6.4: Shared validation for clean_signal_v1 schema
 * Validates incoming signals from Parser5 to ensure they meet the contract requirements.
 */
class BrainValidator
{
    /**
     * Schema version for clean signals
     */
    public const SCHEMA_VERSION = 'clean_signal_v1';

    /**
     * Required fields for clean_signal_v1
     */
    private const REQUIRED_FIELDS = [
        'id',
        'symbol',
        'schema_version',
    ];

    /**
     * Required fields when signal has CLEAN mode risk
     */
    private const REQUIRED_RISK_FIELDS = [
        'profile_id',
        'position_size',
        'stop_loss',
        'take_profit',
    ];

    /**
     * Validate a signal against clean_signal_v1 schema
     *
     * @param array $signal Signal to validate
     * @return array Validation result with 'valid' boolean and 'missing_fields' array
     */
    public static function validateSignal(array $signal): array
    {
        $result = [
            'valid' => true,
            'missing_fields' => [],
            'errors' => [],
        ];

        // Check required fields
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!isset($signal[$field]) || $signal[$field] === '' || $signal[$field] === null) {
                $result['valid'] = false;
                $result['missing_fields'][] = $field;
                $result['errors'][] = "missing_field:{$field}";
            }
        }

        // Validate id format
        if (isset($signal['id']) && !is_string($signal['id'])) {
            $result['valid'] = false;
            $result['errors'][] = 'invalid_field_type:id';
        }

        // Validate symbol format
        if (isset($signal['symbol']) && !is_string($signal['symbol'])) {
            $result['valid'] = false;
            $result['errors'][] = 'invalid_field_type:symbol';
        }

        // Validate schema_version matches expected
        if (isset($signal['schema_version']) && $signal['schema_version'] !== self::SCHEMA_VERSION) {
            // Not an error, but note it
            $result['warnings'][] = 'schema_version_mismatch:expected=' . self::SCHEMA_VERSION . ',got=' . $signal['schema_version'];
        }

        return $result;
    }

    /**
     * Validate risk block for CLEAN mode signals
     *
     * @param array $riskBlock Risk configuration block
     * @return array Validation result with 'valid' boolean and 'missing_fields' array
     */
    public static function validateRiskBlock(array $riskBlock): array
    {
        $result = [
            'valid' => true,
            'missing_fields' => [],
            'errors' => [],
        ];

        if (empty($riskBlock)) {
            $result['valid'] = false;
            $result['errors'][] = 'missing_field:risk';
            return $result;
        }

        // Check required risk fields
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

        // Validate stop_loss and take_profit are set
        $stopLoss = $riskBlock['stop_loss'] ?? null;
        $takeProfit = $riskBlock['take_profit'] ?? null;

        if ($stopLoss !== null && !is_array($stopLoss) && !is_numeric($stopLoss)) {
            $result['errors'][] = 'invalid_field_type:risk.stop_loss';
        }

        if ($takeProfit !== null && !is_array($takeProfit) && !is_numeric($takeProfit)) {
            $result['errors'][] = 'invalid_field_type:risk.take_profit';
        }

        if (!empty($result['missing_fields']) || !empty($result['errors'])) {
            $result['valid'] = false;
        }

        return $result;
    }

    /**
     * Validate a complete signal with risk block (for CLEAN mode)
     *
     * @param array $signal Signal to validate
     * @param bool $requireRisk Whether to require risk block (CLEAN mode)
     * @return array Validation result
     */
    public static function validateCompleteSignal(array $signal, bool $requireRisk = false): array
    {
        $signalResult = self::validateSignal($signal);

        if ($requireRisk && isset($signal['risk'])) {
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
        } elseif ($requireRisk && !isset($signal['risk'])) {
            $signalResult['valid'] = false;
            $signalResult['missing_fields'][] = 'risk';
            $signalResult['errors'][] = 'missing_field:risk';
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
