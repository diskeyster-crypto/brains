<?php

declare(strict_types=1);

/**
 * Bybit API Configuration (Mainnet Only)
 * 
 * CREDENTIALS: Store in KeyCenter, not here!
 *   php index.php keys set bybit <api_key> <api_secret>
 * 
 * This file contains ONLY non-sensitive settings.
 * Testnet removed for production stability.
 */
return [
    // Request settings (required)
    'timeout' => 30,           // Request timeout in seconds
    'max_retries' => 3,        // Maximum retry attempts
    'recv_window' => 5000,     // Receive window in milliseconds
    
    // Config fallback disabled by default
    // KeyCenter is the SINGLE SOURCE OF TRUTH for credentials
    // Set to true only for legacy/migration purposes
    'allow_config_fallback' => false,
    
    // Legacy credentials (NOT RECOMMENDED - use KeyCenter)
    // Only used if allow_config_fallback is true AND KeyCenter is empty
    'api_key' => '',
    'api_secret' => '',
];

/*
 * ============================================================================
 * RULES
 * ============================================================================
 * 
 * 1. PURPOSE: Bybit API non-sensitive configuration
 * 2. CREDENTIALS: Use KeyCenter, NOT this file
 *    Command: php index.php keys set bybit <key> <secret>
 * 3. CONFIG FALLBACK: Disabled by default (allow_config_fallback = false)
 * 4. MAINNET ONLY: No testnet support
 * 5. REQUIRED KEYS: timeout, max_retries
 * 
 * ============================================================================
 */
