<?php
/**
 * Tredercopis Core - Event System
 * 
 * Simple but powerful event dispatcher for decoupled communication
 * between system components.
 */

namespace Core\System;

class SystemEvents
{
    /** @var array<string, array<callable>> Event listeners */
    private static array $listeners = [];
    
    /** @var array<string, array<callable>> One-time listeners */
    private static array $onceListeners = [];
    
    /**
     * Register an event listener
     * 
     * @param string $event Event name (e.g., 'user.login', 'module.loaded')
     * @param callable $handler Handler function
     * @return void
     */
    public static function on(string $event, callable $handler): void
    {
        if (!isset(self::$listeners[$event])) {
            self::$listeners[$event] = [];
        }
        
        self::$listeners[$event][] = $handler;
    }
    
    /**
     * Register a one-time event listener
     * 
     * @param string $event Event name
     * @param callable $handler Handler function (called once then removed)
     * @return void
     */
    public static function once(string $event, callable $handler): void
    {
        if (!isset(self::$onceListeners[$event])) {
            self::$onceListeners[$event] = [];
        }
        
        self::$onceListeners[$event][] = $handler;
    }
    
    /**
     * Remove event listener(s)
     * 
     * @param string $event Event name
     * @param callable|null $handler Specific handler to remove, or null to remove all
     * @return void
     */
    public static function off(string $event, ?callable $handler = null): void
    {
        if ($handler === null) {
            // Remove all listeners for this event
            unset(self::$listeners[$event]);
            unset(self::$onceListeners[$event]);
        } else {
            // Remove specific handler
            if (isset(self::$listeners[$event])) {
                self::$listeners[$event] = array_filter(
                    self::$listeners[$event],
                    fn($h) => $h !== $handler
                );
            }
            if (isset(self::$onceListeners[$event])) {
                self::$onceListeners[$event] = array_filter(
                    self::$onceListeners[$event],
                    fn($h) => $h !== $handler
                );
            }
        }
    }
    
    /**
     * Emit an event
     * 
     * @param string $event Event name
     * @param array $payload Data to pass to handlers
     * @return array Results from all handlers
     */
    public static function emit(string $event, array $payload = []): array
    {
        $results = [];
        
        // Call regular listeners
        if (isset(self::$listeners[$event])) {
            foreach (self::$listeners[$event] as $handler) {
                try {
                    $result = call_user_func($handler, $payload, $event);
                    if ($result !== null) {
                        $results[] = $result;
                    }
                } catch (\Throwable $e) {
                    // Log but don't break event chain
                    error_log("Event handler error for '$event': " . $e->getMessage());
                }
            }
        }
        
        // Call one-time listeners and remove them
        if (isset(self::$onceListeners[$event])) {
            foreach (self::$onceListeners[$event] as $handler) {
                try {
                    $result = call_user_func($handler, $payload, $event);
                    if ($result !== null) {
                        $results[] = $result;
                    }
                } catch (\Throwable $e) {
                    error_log("Event handler error for '$event': " . $e->getMessage());
                }
            }
            // Clear once listeners after firing
            unset(self::$onceListeners[$event]);
        }
        
        return $results;
    }
    
    /**
     * Check if event has listeners
     * 
     * @param string $event Event name
     * @return bool
     */
    public static function has(string $event): bool
    {
        return !empty(self::$listeners[$event]) || !empty(self::$onceListeners[$event]);
    }
    
    /**
     * Get all listeners for an event
     * 
     * @param string $event Event name
     * @return array<callable>
     */
    public static function listeners(string $event): array
    {
        $listeners = self::$listeners[$event] ?? [];
        $once = self::$onceListeners[$event] ?? [];
        
        return array_merge($listeners, $once);
    }
    
    /**
     * Get all registered events
     * 
     * @return array<string>
     */
    public static function events(): array
    {
        return array_unique(array_merge(
            array_keys(self::$listeners),
            array_keys(self::$onceListeners)
        ));
    }
    
    /**
     * Clear all listeners
     * 
     * @return void
     */
    public static function clear(): void
    {
        self::$listeners = [];
        self::$onceListeners = [];
    }
    
    /**
     * Get listener count for an event
     * 
     * @param string $event Event name
     * @return int
     */
    public static function count(string $event): int
    {
        $count = count(self::$listeners[$event] ?? []);
        $count += count(self::$onceListeners[$event] ?? []);
        return $count;
    }
}

/* RULES
 * - Purpose: Event dispatcher for decoupled component communication
 * - Config sources: None (stateless)
 * - Paths: None
 * - Logs: Errors logged via error_log()
 * - Prohibitions: No ../, no hardcoded paths, handlers must be callable
 */
