<?php

declare(strict_types=1);

namespace Core\Storage;

interface StorageInterface
{
    public function get(string $key): mixed;
    
    public function set(string $key, mixed $value): void;
    
    public function delete(string $key): void;
    
    public function exists(string $key): bool;
    
    public function all(): array;
    
    public function clear(): void;
}

/* RULES
- Purpose: Storage driver interface contract
- Config sources: None (interface only)
- Paths: None
- Logs: None
- Prohibitions:
  - NO implementation in interface
*/
