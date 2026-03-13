<?php
declare(strict_types=1);

namespace Modules\System\TradingBot\Lib;

/**
 * Bot Validator
 * 
 * Input validation for Trading Bot.
 * 
 * P0.2 FIX: Separates signal validation (clean_signal_v1) from intent validation (intent_live_v1).
 * - validateSignal(): Checks Brain signals against clean_signal_v1 schema
 * - validateIntent(): Checks internal intents - does NOT compare schema (signal was already validated)
 */
class BotValidator
{
    private array $config;
    
    public function __construct(array $config)
    {
        $this->config = $config;
    }
    
    /**
     * Validate signal from Brain (clean_signal_v1)
     * 
     * @param array $signal Signal data
     * @return array Validation result
     */
    public function validateSignal(array $signal): array
    {
        $result = [
            'valid' => true,
            'reason' => null,
            'missing_fields' => [],
            'invalid_fields' => [],
        ];
        
        // P1.1: Strict schema validation - signal MUST have schema_version
        $schemaVersion = $signal['schema_version'] ?? null;
        $expectedSchema = $this->config['validation']['signal_schema_version'] 
            ?? $this->config['validation']['schema_version'] 
            ?? 'clean_signal_v1';
        
        if ($schemaVersion === null) {
            $result['valid'] = false;
            $result['reason'] = 'missing_schema_version';
            return $result;
        }
        
        if ($schemaVersion !== $expectedSchema) {
            $result['valid'] = false;
            $result['reason'] = 'schema_mismatch:expected=' . $expectedSchema . ',got=' . $schemaVersion;
            return $result;
        }
        
        // Check required signal fields
        $requiredFields = $this->config['validation']['required_signal_fields'] ?? [
            'id',
            'symbol',
            'side',
            'entry',
            'created_ts',
            'entry_action',
            'risk',
        ];
        
        foreach ($requiredFields as $field) {
            if ($field === 'entry') {
                if (!isset($signal['entry']) && !isset($signal['entry_price'])) {
                    $result['missing_fields'][] = $field;
                }
            } elseif (!isset($signal[$field])) {
                $result['missing_fields'][] = $field;
            }
        }
        
        if (!empty($result['missing_fields'])) {
            $result['valid'] = false;
            $result['reason'] = 'missing_field:' . implode(',', $result['missing_fields']);
            return $result;
        }
        
        // Validate side
        $side = strtolower($signal['side'] ?? '');
        if (!in_array($side, ['long', 'short'], true)) {
            $result['valid'] = false;
            $result['reason'] = 'invalid_side:' . $side;
            return $result;
        }
        
        // Validate entry action
        $entryAction = $signal['entry_action'] ?? '';
        if (!in_array($entryAction, ['enter_now', 'wait_retrace'], true)) {
            $result['valid'] = false;
            $result['reason'] = 'invalid_entry_action:' . $entryAction;
            return $result;
        }
        
        // Validate risk block exists
        if (!is_array($signal['risk'] ?? null) || empty($signal['risk'])) {
            $result['valid'] = false;
            $result['reason'] = 'missing_risk_block';
            return $result;
        }
        
        // Validate symbol format
        $symbol = $signal['symbol'] ?? '';
        if (empty($symbol) || !preg_match('/^[A-Z0-9]+USDT$/i', $symbol)) {
            $result['valid'] = false;
            $result['reason'] = 'invalid_symbol:' . $symbol;
            return $result;
        }
        
        // Validate entry price
        $entryPrice = $this->extractEntryPrice($signal);
        if ($entryPrice === null || $entryPrice <= 0) {
            $result['valid'] = false;
            $result['reason'] = 'invalid_entry_price';
            return $result;
        }
        
        return $result;
    }
    
    /**
     * Validate intent (internal format - intent_live_v1)
     * 
     * P0.2 FIX: Intent validation does NOT check schema_version because:
     * - Signal was already validated with clean_signal_v1 before intent creation
     * - Intent has its own schema (intent_live_v1) which is internal
     * 
     * @param array $intent Intent data
     * @return array Validation result
     */
    public function validateIntent(array $intent): array
    {
        $result = [
            'valid' => true,
            'reason' => null,
            'missing_fields' => [],
            'invalid_fields' => [],
        ];
        
        // Check required intent fields (same as signal fields for compatibility)
        $requiredFields = $this->config['validation']['required_signal_fields'] ?? [
            'id',
            'symbol',
            'side',
            'entry',
            'created_ts',
            'entry_action',
            'risk',
        ];
        
        foreach ($requiredFields as $field) {
            // Handle nested fields
            if ($field === 'entry') {
                if (!isset($intent['entry']) && !isset($intent['entry_price'])) {
                    $result['missing_fields'][] = $field;
                }
            } elseif (!isset($intent[$field])) {
                $result['missing_fields'][] = $field;
            }
        }
        
        if (!empty($result['missing_fields'])) {
            $result['valid'] = false;
            $result['reason'] = 'missing_field:' . implode(',', $result['missing_fields']);
            return $result;
        }
        
        // Validate side
        $side = strtolower($intent['side'] ?? '');
        if (!in_array($side, ['long', 'short'], true)) {
            $result['valid'] = false;
            $result['reason'] = 'invalid_side:' . $side;
            return $result;
        }
        
        // Validate entry action
        $entryAction = $intent['entry_action'] ?? '';
        if (!in_array($entryAction, ['enter_now', 'wait_retrace'], true)) {
            $result['valid'] = false;
            $result['reason'] = 'invalid_entry_action:' . $entryAction;
            return $result;
        }
        
        // Validate risk block exists
        if (!is_array($intent['risk'] ?? null) || empty($intent['risk'])) {
            $result['valid'] = false;
            $result['reason'] = 'missing_risk_block';
            return $result;
        }
        
        // P0.2: DO NOT validate schema_version for intents
        // Intent schema (intent_live_v1) is internal and different from signal schema (clean_signal_v1)
        // The signal was already validated before the intent was created
        
        // Validate symbol format
        $symbol = $intent['symbol'] ?? '';
        if (empty($symbol) || !preg_match('/^[A-Z0-9]+USDT$/i', $symbol)) {
            $result['valid'] = false;
            $result['reason'] = 'invalid_symbol:' . $symbol;
            return $result;
        }
        
        // Validate entry price
        $entryPrice = $this->extractEntryPrice($intent);
        if ($entryPrice === null || $entryPrice <= 0) {
            $result['valid'] = false;
            $result['reason'] = 'invalid_entry_price';
            return $result;
        }
        
        return $result;
    }
    
    /**
     * Validate order before submission
     * 
     * @param array $order Order data
     * @return array Validation result
     */
    public function validateOrder(array $order): array
    {
        $result = [
            'valid' => true,
            'reason' => null,
        ];
        
        // Check required order fields
        $requiredFields = ['symbol', 'side', 'order_type', 'qty'];
        
        foreach ($requiredFields as $field) {
            if (!isset($order[$field])) {
                $result['valid'] = false;
                $result['reason'] = 'missing_order_field:' . $field;
                return $result;
            }
        }
        
        // Validate quantity
        $qty = (float)($order['qty'] ?? 0);
        if ($qty <= 0) {
            $result['valid'] = false;
            $result['reason'] = 'invalid_qty:' . $qty;
            return $result;
        }
        
        // Validate order type
        $allowedTypes = $this->config['validation']['allowed_order_types'] ?? ['market'];
        $orderType = strtolower($order['order_type'] ?? '');
        
        if (!in_array($orderType, $allowedTypes, true)) {
            $result['valid'] = false;
            $result['reason'] = 'order_type_not_allowed:' . $orderType;
            return $result;
        }
        
        return $result;
    }
    
    /**
     * Extract entry price from intent/signal
     * 
     * @param array $data Intent or signal data
     * @return float|null Entry price or null
     */
    private function extractEntryPrice(array $data): ?float
    {
        // Try entry.price first
        if (isset($data['entry']['price'])) {
            return (float)$data['entry']['price'];
        }
        
        // Try entry_price
        if (isset($data['entry_price'])) {
            return (float)$data['entry_price'];
        }
        
        return null;
    }
    
    /**
     * Format validation errors
     * 
     * @param array $validation Validation result
     * @return string Formatted error message
     */
    public function formatError(array $validation): string
    {
        if ($validation['valid']) {
            return '';
        }
        
        $parts = [];
        
        if (!empty($validation['missing_fields'])) {
            $parts[] = 'missing_field:' . implode(',', $validation['missing_fields']);
        }
        
        if (!empty($validation['reason'])) {
            $parts[] = $validation['reason'];
        }
        
        return implode('; ', array_unique($parts));
    }
}

/* RULES
- Validator validates signals (clean_signal_v1) and intents (intent_live_v1)
- Signal validation requires schema_version to be present and matching
- Intent validation does NOT check schema_version (signal was already validated)
- Order validation checks required fields and allowed types
- NO silent defaults - validation fails on missing required fields
*/
