<?php

declare(strict_types=1);

namespace Modules\System\Brain\Lib;

use Core\System\SystemPaths;

/**
 * BrainProfilesTrait - Risk Profiles + risk_active snapshot methods
 * 
 * This trait is part of the BrainService refactor into traits.
 * No behavior changes are allowed (refactor-only).
 */
trait BrainProfilesTrait
{
    /**
     * Get profiles index (list + active_profile_id)
     */
    public function getProfilesIndex(): array
    {
        $indexFile = $this->profilesDir . '/index.json';
        
        if (is_file($indexFile)) {
            $content = @file_get_contents($indexFile);
            $index = $content !== false ? json_decode($content, true) : null;
            if (is_array($index)) {
                return $index;
            }
        }
        
        // Default index
        return [
            'version' => '1.0',
            'active_profile_id' => null,
            'profiles' => [],
            'updated_at' => null,
        ];
    }
    
    /**
     * Save profiles index
     * @param array $index Index data
     * @return bool Success
     */
    private function saveProfilesIndex(array $index): bool
    {
        $index['updated_at'] = date('c');
        $indexFile = $this->profilesDir . '/index.json';
        return file_put_contents($indexFile, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
    }
    
    /**
     * Get all risk profiles
     * @return array Array of profiles
     */
    public function getProfiles(): array
    {
        $profiles = [];
        $files = glob($this->profilesDir . '/*.json') ?: [];
        
        foreach ($files as $file) {
            $filename = basename($file);
            if ($filename === 'index.json') {
                continue; // Skip index file
            }
            
            $profileId = basename($file, '.json');
            $content = @file_get_contents($file);
            if ($content === false) {
                continue;
            }
            
            $data = json_decode($content, true);
            if (is_array($data) && isset($data['profile_id'])) {
                $profiles[$profileId] = $data;
            }
        }
        
        // Sort by title
        uasort($profiles, fn($a, $b) => strcasecmp($a['title'] ?? '', $b['title'] ?? ''));
        
        return $profiles;
    }
    
    /**
     * Get single risk profile
     * @param string $profileId Profile ID
     * @return array|null Profile data or null
     */
    public function getProfile(string $profileId): ?array
    {
        $profileId = $this->sanitizeId($profileId);
        $file = $this->profilesDir . '/' . $profileId . '.json';
        
        if (!is_file($file)) {
            return null;
        }
        
        $content = @file_get_contents($file);
        if ($content === false) {
            return null;
        }
        
        $data = json_decode($content, true);
        return is_array($data) && isset($data['profile_id']) ? $data : null;
    }
    
    /**
     * Get active risk profile
     * @return array|null Active profile or null
     */
    public function getActiveProfile(): ?array
    {
        $index = $this->getProfilesIndex();
        $activeId = $index['active_profile_id'] ?? null;
        
        if ($activeId === null) {
            return null;
        }
        
        return $this->getProfile($activeId);
    }
    
    /**
     * Get active profile ID
     * @return string|null Active profile ID or null
     */
    public function getActiveProfileId(): ?string
    {
        $index = $this->getProfilesIndex();
        return $index['active_profile_id'] ?? null;
    }
    
    /**
     * Validate risk profile data
     * Per ТЗ: Strict validation rules
     * 
     * @param array $data Profile data
     * @return array Validation result with 'valid' and 'errors'
     */
    private function validateProfile(array $data): array
    {
        $errors = [];
        
        // Required fields
        if (empty($data['profile_id'])) {
            $errors[] = 'profile_id is required';
        } elseif (!preg_match('/^[a-z0-9_]+$/i', $data['profile_id'])) {
            $errors[] = 'profile_id must contain only letters, numbers, and underscores';
        }
        
        if (empty($data['title'])) {
            $errors[] = 'title is required';
        }
        
        // Budget validation
        $budget = $data['budget_usdt_per_trade'] ?? null;
        if (!is_numeric($budget) || (float)$budget <= 0) {
            $errors[] = 'budget_usdt_per_trade must be > 0';
        }
        
        // Leverage validation
        $leverage = $data['leverage'] ?? null;
        if (!is_numeric($leverage) || (int)$leverage < 1) {
            $errors[] = 'leverage must be >= 1';
        }
        
        // Stop from liq range validation (1..90)
        $stopPct = $data['stop_from_liq_range_pct'] ?? null;
        if (!is_numeric($stopPct) || (float)$stopPct < 1 || (float)$stopPct > 90) {
            $errors[] = 'stop_from_liq_range_pct must be between 1 and 90';
        }
        
        // Slippage validation
        $slippage = $data['slippage_bps'] ?? null;
        if (!is_numeric($slippage) || (int)$slippage < 0) {
            $errors[] = 'slippage_bps must be >= 0';
        }
        
        // Trailing validation
        $trailing = $data['trailing'] ?? [];
        if (isset($trailing['enabled']) && $trailing['enabled'] === true) {
            $activationRoi = $trailing['activation_roi_pct'] ?? null;
            if (!is_numeric($activationRoi) || (float)$activationRoi <= 0) {
                $errors[] = 'trailing.activation_roi_pct must be > 0 when trailing is enabled';
            }
            
            // STEP 2: Validate mode if provided (optional, for backward compat)
            if (isset($trailing['mode'])) {
                $validModes = ['tight', 'normal', 'loose'];
                if (!in_array($trailing['mode'], $validModes, true)) {
                    $errors[] = 'trailing.mode must be one of: tight, normal, loose';
                }
            }
            
            // STEP 2: Validate drawdown_factor if provided (optional)
            if (isset($trailing['drawdown_factor'])) {
                $factor = (float)$trailing['drawdown_factor'];
                if ($factor < 0.20 || $factor > 0.80) {
                    $errors[] = 'trailing.drawdown_factor must be between 0.20 and 0.80';
                }
            }
        }
        
        // Take profit validation
        $tp = $data['take_profit'] ?? [];
        if (isset($tp['enabled']) && $tp['enabled'] === true) {
            $tpRoi = $tp['roi_pct'] ?? null;
            if (!is_numeric($tpRoi) || (float)$tpRoi <= 0) {
                $errors[] = 'take_profit.roi_pct must be > 0 when take_profit is enabled';
            }
        }
        
        // C1: Limits validation (optional, with defaults)
        $limits = $data['limits'] ?? [];
        if (isset($limits['max_open_trades']) && !is_int($limits['max_open_trades']) && !is_numeric($limits['max_open_trades'])) {
            $errors[] = 'limits.max_open_trades must be an integer';
        }
        if (isset($limits['one_trade_per_symbol']) && !is_bool($limits['one_trade_per_symbol'])) {
            $errors[] = 'limits.one_trade_per_symbol must be a boolean';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }
    
    /**
     * Save risk profile (create or update)
     * Per ТЗ: Risk Profile v1 structure
     * 
     * @param array $data Profile data
     * @return array Result with success, profile, errors
     */
    public function saveProfile(array $data): array
    {
        // Validate input
        $validation = $this->validateProfile($data);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'errors' => $validation['errors'],
            ];
        }
        
        $profileId = $this->sanitizeId($data['profile_id']);
        $isNew = !is_file($this->profilesDir . '/' . $profileId . '.json');
        
        // Build normalized profile structure (per ТЗ 3.2)
        $profile = [
            'schema_version' => '1.0',
            'profile_id' => $profileId,
            'title' => (string)($data['title'] ?? 'Unnamed Profile'),
            'enabled' => (bool)($data['enabled'] ?? true),
            
            'budget_usdt_per_trade' => (float)($data['budget_usdt_per_trade'] ?? 50),
            'leverage' => (int)($data['leverage'] ?? 10),
            
            'stop_from_liq_range_pct' => (float)($data['stop_from_liq_range_pct'] ?? 20),
            
            'slippage_bps' => (int)($data['slippage_bps'] ?? 20),
            
            'trailing' => [
                'enabled' => (bool)(($data['trailing']['enabled'] ?? false)),
                'activation_roi_pct' => ($data['trailing']['activation_roi_pct'] ?? null) !== null 
                    ? (float)$data['trailing']['activation_roi_pct'] 
                    : 6,
                // STEP 2: Save mode and drawdown_factor if provided (optional for backward compat)
                'mode' => $data['trailing']['mode'] ?? null,
                'drawdown_factor' => isset($data['trailing']['drawdown_factor']) 
                    ? (float)$data['trailing']['drawdown_factor'] 
                    : null,
            ],
            
            'take_profit' => [
                'enabled' => (bool)(($data['take_profit']['enabled'] ?? false)),
                'roi_pct' => ($data['take_profit']['roi_pct'] ?? null) !== null 
                    ? (float)$data['take_profit']['roi_pct'] 
                    : null,
            ],
            
            // C1: Limits (moved from Parser6 config - Brain is single source of truth)
            'limits' => [
                'max_open_trades' => (int)($data['limits']['max_open_trades'] ?? 0),
                'one_trade_per_symbol' => (bool)($data['limits']['one_trade_per_symbol'] ?? true),
            ],
            
            'notes' => (string)($data['notes'] ?? ''),
            
            'created_at' => $data['created_at'] ?? date('c'),
            'updated_at' => date('c'),
        ];
        
        // Save profile file
        $file = $this->profilesDir . '/' . $profileId . '.json';
        $written = file_put_contents($file, json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        if ($written === false) {
            return [
                'success' => false,
                'errors' => ['Failed to write profile file'],
            ];
        }
        
        // Update index
        $index = $this->getProfilesIndex();
        if (!isset($index['profiles'])) {
            $index['profiles'] = [];
        }
        $index['profiles'][$profileId] = [
            'profile_id' => $profileId,
            'title' => $profile['title'],
            'enabled' => $profile['enabled'],
        ];
        $this->saveProfilesIndex($index);
        
        // C1.1: Update risk_active.json if this is the active profile
        $activeProfileId = $index['active_profile_id'] ?? null;
        if ($activeProfileId === $profileId) {
            $this->saveRiskActiveSnapshot($profile);
        }
        
        $this->log("Profile " . ($isNew ? 'created' : 'updated') . ": {$profileId}");
        
        return [
            'success' => true,
            'profile' => $profile,
            'created' => $isNew,
        ];
    }
    
    /**
     * Delete risk profile
     * @param string $profileId Profile ID
     * @return array Result with success/error
     */
    public function deleteProfile(string $profileId): array
    {
        $profileId = $this->sanitizeId($profileId);
        $file = $this->profilesDir . '/' . $profileId . '.json';
        
        if (!is_file($file)) {
            return [
                'success' => false,
                'error' => 'Profile not found',
            ];
        }
        
        // Remove file
        if (!@unlink($file)) {
            return [
                'success' => false,
                'error' => 'Failed to delete profile file',
            ];
        }
        
        // Update index
        $index = $this->getProfilesIndex();
        unset($index['profiles'][$profileId]);
        
        // C1.1: Clear risk_active.json if this was active profile
        if (($index['active_profile_id'] ?? null) === $profileId) {
            $index['active_profile_id'] = null;
            $this->saveRiskActiveSnapshot(null);
        }
        
        $this->saveProfilesIndex($index);
        
        $this->log("Profile deleted: {$profileId}");
        
        return [
            'success' => true,
        ];
    }
    
    /**
     * Set active risk profile
     * @param string|null $profileId Profile ID or null to clear
     * @return array Result with success/error
     */
    public function setActiveProfile(?string $profileId): array
    {
        $index = $this->getProfilesIndex();
        
        if ($profileId === null) {
            // Clear active profile
            $index['active_profile_id'] = null;
            $this->saveProfilesIndex($index);
            // C-5: Clear risk_active.json when profile is cleared
            $this->saveRiskActiveSnapshot(null);
            $this->log("Active profile cleared");
            return ['success' => true, 'active_profile_id' => null];
        }
        
        $profileId = $this->sanitizeId($profileId);
        
        // Verify profile exists
        $profile = $this->getProfile($profileId);
        if ($profile === null) {
            return [
                'success' => false,
                'error' => 'Profile not found',
            ];
        }
        
        $index['active_profile_id'] = $profileId;
        $this->saveProfilesIndex($index);
        
        // C-5: Save risk_active.json snapshot when profile is set
        $this->saveRiskActiveSnapshot($profile);
        
        $this->log("Active profile set: {$profileId}");
        
        return [
            'success' => true,
            'active_profile_id' => $profileId,
        ];
    }
    
    /**
     * C1.1: Save active risk snapshot with strict contract v1
     * Writes to brain/storage/runtime/risk_active.json
     * 
     * Contract: risk_active_v1
     * - Parser6 reads this for RAW mode risk source
     * - CLEAN mode uses signal.risk instead
     * 
     * @param array|null $profile Active profile data or null to clear
     */
    private function saveRiskActiveSnapshot(?array $profile): void
    {
        $storageDir = $this->modulePaths['brain'] ?? null;
        if (!$storageDir) {
            return;
        }
        
        $runtimeDir = $storageDir . '/runtime';
        if (!is_dir($runtimeDir)) {
            @mkdir($runtimeDir, 0755, true);
        }
        
        $path = $runtimeDir . '/risk_active.json';
        
        if ($profile === null) {
            // Clear the file
            @unlink($path);
            return;
        }
        
        // C1.1: Get defaults from config
        $defaults = $this->config['risk_defaults'] ?? [];
        $defaultTrailing = $defaults['trailing'] ?? [];
        $defaultLimits = $defaults['limits'] ?? [];
        
        // Build trailing block with all required fields
        $profileTrailing = $profile['trailing'] ?? [];
        $trailing = [
            'enabled' => (bool)($profileTrailing['enabled'] ?? $defaultTrailing['enabled'] ?? true),
            'activation_roi_pct' => (float)($profileTrailing['activation_roi_pct'] ?? $defaultTrailing['activation_roi_pct'] ?? 6),
            'mode' => $profileTrailing['mode'] ?? $defaultTrailing['mode'] ?? 'normal',
            'drawdown_factor' => (float)($profileTrailing['drawdown_factor'] ?? $defaultTrailing['drawdown_factor'] ?? 0.50),
        ];
        
        // Build take_profit block
        $profileTp = $profile['take_profit'] ?? [];
        $takeProfit = [
            'enabled' => (bool)($profileTp['enabled'] ?? false),
            'roi_pct' => isset($profileTp['roi_pct']) ? (float)$profileTp['roi_pct'] : null,
        ];
        
        // Build limits block
        $profileLimits = $profile['limits'] ?? [];
        $limits = [
            'max_open_trades' => (int)($profileLimits['max_open_trades'] ?? $defaultLimits['max_open_trades'] ?? 0),
            'one_trade_per_symbol' => (bool)($profileLimits['one_trade_per_symbol'] ?? $defaultLimits['one_trade_per_symbol'] ?? true),
        ];
        
        // C1.1: Strict contract v1 format
        $snapshot = [
            'schema_version' => 'risk_active_v1',
            'profile_id' => (string)($profile['profile_id'] ?? 'unknown'),
            'updated_at' => date('c'),
            'updated_ts_unix' => time(),
            'risk' => [
                'budget_usdt_per_trade' => (float)($profile['budget_usdt_per_trade'] ?? $defaults['budget_usdt_per_trade'] ?? 50),
                'leverage' => (int)($profile['leverage'] ?? $defaults['leverage'] ?? 10),
                'stop_from_liq_range_pct' => (float)($profile['stop_from_liq_range_pct'] ?? $defaults['stop_from_liq_range_pct'] ?? 20),
                'slippage_bps' => (int)($profile['slippage_bps'] ?? $defaults['slippage_bps'] ?? 20),
                'fees_bps' => (int)($profile['fees_bps'] ?? $defaults['fees_bps'] ?? 6),
                'order_type' => 'market',
                'take_profit' => $takeProfit,
                'trailing' => $trailing,
                'limits' => $limits,
            ],
        ];
        
        @file_put_contents($path, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    
    /**
     * Get risk block for signal enrichment
     * Uses active profile if available, otherwise config defaults
     * STEP 2: Ensures trailing.mode and trailing.drawdown_factor are always present
     * 
     * @return array Risk block for signal
     */
    public function getRiskBlockForSignal(): array
    {
        $activeProfile = $this->getActiveProfile();
        $defaults = $this->config['risk_defaults'] ?? [];
        $defaultTrailing = $defaults['trailing'] ?? [];
        $defaultLimits = $defaults['limits'] ?? [];
        
        // STEP 2: Default trailing mode/factor from config
        $defaultMode = $defaultTrailing['mode'] ?? 'normal';
        $defaultFactor = (float)($defaultTrailing['drawdown_factor'] ?? 0.50);
        
        if ($activeProfile !== null) {
            // Use active profile, ensuring mode/factor are present
            $trailing = $activeProfile['trailing'] ?? [];
            $trailing['mode'] = $trailing['mode'] ?? $defaultMode;
            $trailing['drawdown_factor'] = (float)($trailing['drawdown_factor'] ?? $defaultFactor);
            
            // C1: Limits from profile (with defaults)
            $profileLimits = $activeProfile['limits'] ?? [];
            $limits = [
                'max_open_trades' => (int)($profileLimits['max_open_trades'] ?? $defaultLimits['max_open_trades'] ?? 0),
                'one_trade_per_symbol' => (bool)($profileLimits['one_trade_per_symbol'] ?? $defaultLimits['one_trade_per_symbol'] ?? true),
            ];
            
            return [
                'profile_id' => $activeProfile['profile_id'],
                'budget_usdt_per_trade' => (float)$activeProfile['budget_usdt_per_trade'],
                'leverage' => (int)$activeProfile['leverage'],
                'stop_from_liq_range_pct' => (float)$activeProfile['stop_from_liq_range_pct'],
                'slippage_bps' => (int)$activeProfile['slippage_bps'],
                'fees_bps' => (int)($activeProfile['fees_bps'] ?? 6),
                'trailing' => $trailing,
                'take_profit' => $activeProfile['take_profit'],
                'limits' => $limits,
                'order_type' => 'market',
            ];
        }
        
        // Use config defaults, ensuring mode/factor are present
        $trailing = [
            'enabled' => (bool)($defaultTrailing['enabled'] ?? true),
            'activation_roi_pct' => (float)($defaultTrailing['activation_roi_pct'] ?? 6),
            'mode' => $defaultMode,
            'drawdown_factor' => $defaultFactor,
        ];
        
        // C1: Default limits
        $limits = [
            'max_open_trades' => (int)($defaultLimits['max_open_trades'] ?? 0),
            'one_trade_per_symbol' => (bool)($defaultLimits['one_trade_per_symbol'] ?? true),
        ];
        
        return [
            'profile_id' => 'default',
            'budget_usdt_per_trade' => (float)($defaults['budget_usdt_per_trade'] ?? 50),
            'leverage' => (int)($defaults['leverage'] ?? 10),
            'stop_from_liq_range_pct' => (float)($defaults['stop_from_liq_range_pct'] ?? 20),
            'slippage_bps' => (int)($defaults['slippage_bps'] ?? 20),
            'fees_bps' => (int)($defaults['fees_bps'] ?? 6),
            'trailing' => $trailing,
            'take_profit' => $defaults['take_profit'] ?? ['enabled' => false, 'roi_pct' => null],
            'limits' => $limits,
            'order_type' => 'market',
        ];
    }
}

/* RULES
 * NO HARDCODE. CONFIG FIRST. SystemPaths ONLY.
 * This file is part of Brain refactor into traits.
 * No behavior changes allowed (refactor-only).
 */
