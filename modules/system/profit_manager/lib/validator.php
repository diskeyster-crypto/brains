<?php
declare(strict_types=1);

namespace Modules\System\ProfitManager\Lib;

/**
 * Validator
 * 
 * Validation logic and contracts for Profit Manager.
 */
class Validator
{
    private array $config;
    
    public function __construct(array $config)
    {
        $this->config = $config;
    }
    
    /**
     * Validate position data from exchange
     * 
     * @param array $position Position data
     * @return array Validation result with ok, errors
     */
    public function validatePosition(array $position): array
    {
        $errors = [];
        
        // Required fields
        $requiredFields = ['symbol', 'size', 'avgPrice', 'side'];
        foreach ($requiredFields as $field) {
            if (!isset($position[$field])) {
                $errors[] = "missing_field:{$field}";
            }
        }
        
        // Size must be positive
        $size = (float) ($position['size'] ?? 0);
        if ($size <= 0) {
            $errors[] = 'zero_or_negative_size';
        }
        
        // avgPrice must be positive
        $avgPrice = (float) ($position['avgPrice'] ?? 0);
        if ($avgPrice <= 0) {
            $errors[] = 'invalid_avg_price';
        }
        
        // Side must be valid
        $side = strtolower($position['side'] ?? '');
        if (!in_array($side, ['buy', 'sell', 'long', 'short'], true)) {
            $errors[] = 'invalid_side';
        }
        
        return [
            'ok' => empty($errors),
            'errors' => $errors,
        ];
    }
    
    /**
     * Validate managed symbol context
     * 
     * @param array $managed Managed symbol data
     * @return array Validation result with ok, errors
     */
    public function validateManagedContext(array $managed): array
    {
        $errors = [];
        
        // Must have position
        if (!isset($managed['position']) || !is_array($managed['position'])) {
            $errors[] = 'missing_position';
            return ['ok' => false, 'errors' => $errors];
        }
        
        // Validate position
        $posValidation = $this->validatePosition($managed['position']);
        if (!$posValidation['ok']) {
            $errors = array_merge($errors, $posValidation['errors']);
        }
        
        // Must have leverage
        $leverage = (float) ($managed['leverage'] ?? 0);
        if ($leverage <= 0) {
            $errors[] = 'invalid_leverage';
        }
        
        // Must have activation ROI
        $activationRoi = (float) ($managed['activation_roi_pct'] ?? 0);
        if ($activationRoi < 0) {
            $errors[] = 'invalid_activation_roi';
        }
return [
            'ok' => empty($errors),
            'errors' => $errors,
        ];
    }
    
    /**
     * Validate step trailing parameters
     * 
     * @return array Validation result with ok, errors
     */
    public function validateStepTrailingConfig(): array
    {
        $errors = [];
        $cfg = $this->config['step_trailing'] ?? [];
        
        if (!($cfg['enabled'] ?? false)) {
            return ['ok' => true, 'errors' => []]; // Disabled is valid
        }
        
        if (($cfg['step_roi_pct'] ?? 0) <= 0) {
            $errors[] = 'invalid_step_roi_pct';
        }
        
        if (($cfg['activation_roi_pct_default'] ?? 0) < 0) {
            $errors[] = 'invalid_activation_roi_pct_default';
        }
        
        return [
            'ok' => empty($errors),
            'errors' => $errors,
        ];
    }
    
    /**
     * Validate dumb trailing parameters
     * 
     * @return array Validation result with ok, errors
     */
    public function validateDumbTrailingConfig(): array
    {
        $errors = [];
        $cfg = $this->config['dumb_trailing'] ?? [];
        
        if (!($cfg['enabled'] ?? false)) {
            return ['ok' => true, 'errors' => []]; // Disabled is valid
        }
        
        if (($cfg['epsilon_pct'] ?? 0) <= 0) {
            $errors[] = 'invalid_epsilon_pct';
        }
        
        if (($cfg['min_distance_pct'] ?? 0) <= 0) {
            $errors[] = 'invalid_min_distance_pct';
        }
        
        return [
            'ok' => empty($errors),
            'errors' => $errors,
        ];
    }
    
    /**
     * Check if position should skip step trailing
     * Returns reason string if should skip, null if should process
     * 
     * @param array $position Position data
     * @param array $managed Managed context
     * @param RiskMath $riskMath Risk math instance
     * @return string|null Skip reason or null
     */
    public function shouldSkipStepTrailing(array $position, array $managed, RiskMath $riskMath): ?string
    {
        // Check if step trailing is enabled
        if (!($this->config['step_trailing']['enabled'] ?? true)) {
            return 'step_trailing_disabled';
        }
        
        // Check if trailing is enabled for this position
        if (!($managed['trailing_enabled'] ?? true)) {
            return 'trailing_disabled_for_position';
        }
        
        // Check positionIM
        $positionIM = (float) ($position['positionIM'] ?? 0);
        if ($positionIM <= 0) {
            return 'no_positionIM';
        }
        
        // Check leverage
        $leverage = (float) ($managed['leverage'] ?? 0);
        if ($leverage <= 0) {
            return 'invalid_leverage';
        }
        
        // Calculate ROI
        $unrealisedPnl = (float) ($position['unrealisedPnl'] ?? 0);
        $roi = $riskMath->calculateRoiPct($unrealisedPnl, $positionIM);
        
        if ($roi === null) {
            return 'cannot_calculate_roi';
        }
        
        // Check activation threshold
        $activationRoi = (float) ($managed['activation_roi_pct'] ?? $this->config['step_trailing']['activation_roi_pct_default'] ?? 0);
        $stepRoi = (float) ($this->config['step_trailing']['step_roi_pct'] ?? 0);
        
        if ($roi < $activationRoi) {
            return 'below_activation';
        }
        
        return null; // Should process
    }
    
    /**
     * Check if position should skip dumb trailing
     * Returns reason string if should skip, null if should process
     * 
     * @param array $position Position data
     * @param array $managed Managed context
     * @param RiskMath $riskMath Risk math instance
     * @return string|null Skip reason or null
     */
    public function shouldSkipDumbTrailing(array $position, array $managed, RiskMath $riskMath): ?string
    {
        // Check if dumb trailing is enabled
        if (!($this->config['dumb_trailing']['enabled'] ?? false)) {
            return 'dumb_trailing_disabled';
        }
        
        // Check if trailing is enabled for this position
        if (!($managed['trailing_enabled'] ?? true)) {
            return 'trailing_disabled_for_position';
        }
        
        // Check if already has trailing stop set
        $trailingStop = (float) ($position['trailingStop'] ?? 0);
        $activePrice = (float) ($position['activePrice'] ?? 0);
        
        // If already armed, may need re-arm check
        if ($trailingStop > 0 && $activePrice > 0) {
            // Check if it needs re-arming
            if (!($this->config['dumb_trailing']['rearm_if_not_armed'] ?? true)) {
                return 'trailing_already_set';
            }
        }
        
        // Check positionIM
        $positionIM = (float) ($position['positionIM'] ?? 0);
        if ($positionIM <= 0) {
            return 'no_positionIM';
        }
        
        // Check leverage
        $leverage = (float) ($managed['leverage'] ?? 0);
        if ($leverage <= 0) {
            return 'invalid_leverage';
        }
        
        // Calculate ROI
        $unrealisedPnl = (float) ($position['unrealisedPnl'] ?? 0);
        $roi = $riskMath->calculateRoiPct($unrealisedPnl, $positionIM);
        
        if ($roi === null) {
            return 'cannot_calculate_roi';
        }
        
        // Check activation threshold
        $activationRoi = (float) ($managed['activation_roi_pct'] ?? $this->config['step_trailing']['activation_roi_pct_default'] ?? 0);
        
        if ($roi < $activationRoi) {
            return 'below_activation';
        }
        
        return null; // Should process
    }
    
    /**
     * Normalize side to canonical form
     * 
     * @param string $side Raw side
     * @return string Canonical side: long|short
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

/* RULES
- Validate all inputs before processing
- Return skip reasons as strings for debugging
- Normalize side to canonical long/short
- All thresholds from config (no hardcode)
*/
