<?php
/**
 * ConfigGuard Test Suite
 * 
 * Tests:
 * 1. Partial save preserves other fields
 * 2. Empty input doesn't destroy config
 * 3. Repeated saves maintain structure
 * 4. Invalid types are rejected
 * 
 * Run: php core/config/tests/configguard_test.php
 */

require_once __DIR__ . '/../configguard.php';

// Test directory
$testDir = sys_get_temp_dir() . '/configguard_test_' . getmypid();
@mkdir($testDir, 0755, true);

// Helper functions
function test_pass(string $name): void {
    echo "\033[32m✓ PASS:\033[0m {$name}\n";
}

function test_fail(string $name, string $reason): void {
    echo "\033[31m✗ FAIL:\033[0m {$name} - {$reason}\n";
}

function create_test_schema(string $dir): void {
    $schema = <<<'PHP'
<?php
return [
    'enabled' => true,
    'name' => 'test',
    'threshold' => 0.5,
    'max_items' => 100,
    'tags' => ['one', 'two', 'three'],
    'nested' => [
        'level1' => 'value1',
        'level2' => 10,
    ],
    'ui' => [
        'fields' => ['protected'],
    ],
];
PHP;
    file_put_contents($dir . '/schema.php', $schema);
}

function create_test_config(string $dir, array $config): void {
    $content = "<?php\nreturn " . var_export($config, true) . ";\n";
    file_put_contents($dir . '/config.php', $content);
}

// ============================================================
// TEST 1: Partial save preserves other fields
// ============================================================
echo "\n--- Test 1: Partial save preserves other fields ---\n";

$test1Dir = $testDir . '/test1';
@mkdir($test1Dir, 0755, true);
create_test_schema($test1Dir);
create_test_config($test1Dir, [
    'enabled' => true,
    'name' => 'original',
    'threshold' => 0.5,
    'max_items' => 100,
    'tags' => ['one', 'two', 'three'],
    'nested' => ['level1' => 'value1', 'level2' => 10],
    'ui' => ['fields' => ['protected']],
]);

// Save only 'name' change
$result = ConfigGuard::save($test1Dir . '/config.php', [
    'name' => 'changed',
]);

$savedConfig = include $test1Dir . '/config.php';

if ($result && $savedConfig['name'] === 'changed') {
    if ($savedConfig['threshold'] === 0.5 && $savedConfig['max_items'] === 100) {
        test_pass('Partial save preserves other fields');
    } else {
        test_fail('Partial save preserves other fields', 'Other fields were modified');
    }
} else {
    test_fail('Partial save preserves other fields', 'Save failed or name not changed');
}

// ============================================================
// TEST 2: Empty input doesn't destroy config
// ============================================================
echo "\n--- Test 2: Empty input doesn't destroy config ---\n";

$test2Dir = $testDir . '/test2';
@mkdir($test2Dir, 0755, true);
create_test_schema($test2Dir);
create_test_config($test2Dir, [
    'enabled' => true,
    'name' => 'original',
    'threshold' => 0.5,
    'max_items' => 100,
    'tags' => ['one', 'two', 'three'],
    'nested' => ['level1' => 'value1', 'level2' => 10],
    'ui' => ['fields' => ['protected']],
]);

// Save with empty input
$result = ConfigGuard::save($test2Dir . '/config.php', []);

$savedConfig = include $test2Dir . '/config.php';

if ($result && $savedConfig['enabled'] === true && $savedConfig['name'] === 'original') {
    test_pass('Empty input doesn\'t destroy config');
} else {
    test_fail('Empty input doesn\'t destroy config', 'Config was destroyed');
}

// ============================================================
// TEST 3: Repeated saves maintain structure
// ============================================================
echo "\n--- Test 3: Repeated saves maintain structure ---\n";

$test3Dir = $testDir . '/test3';
@mkdir($test3Dir, 0755, true);
create_test_schema($test3Dir);
create_test_config($test3Dir, [
    'enabled' => true,
    'name' => 'original',
    'threshold' => 0.5,
    'max_items' => 100,
    'tags' => ['one', 'two', 'three'],
    'nested' => ['level1' => 'value1', 'level2' => 10],
    'ui' => ['fields' => ['protected']],
]);

// Save 3 times
ConfigGuard::save($test3Dir . '/config.php', ['name' => 'first']);
ConfigGuard::save($test3Dir . '/config.php', ['threshold' => 0.7]);
ConfigGuard::save($test3Dir . '/config.php', ['max_items' => 200]);

$savedConfig = include $test3Dir . '/config.php';

if ($savedConfig['name'] === 'first' && 
    $savedConfig['threshold'] === 0.7 && 
    $savedConfig['max_items'] === 200 &&
    $savedConfig['tags'] === ['one', 'two', 'three']) {
    test_pass('Repeated saves maintain structure');
} else {
    test_fail('Repeated saves maintain structure', 'Structure was corrupted');
}

// ============================================================
// TEST 4: Invalid types are corrected
// ============================================================
echo "\n--- Test 4: Invalid types are corrected ---\n";

$test4Dir = $testDir . '/test4';
@mkdir($test4Dir, 0755, true);
create_test_schema($test4Dir);
create_test_config($test4Dir, [
    'enabled' => true,
    'name' => 'original',
    'threshold' => 0.5,
    'max_items' => 100,
    'tags' => ['one', 'two', 'three'],
    'nested' => ['level1' => 'value1', 'level2' => 10],
    'ui' => ['fields' => ['protected']],
]);

// Try to save with invalid type (string instead of int)
$result = ConfigGuard::save($test4Dir . '/config.php', [
    'max_items' => 'invalid_string',
]);

$savedConfig = include $test4Dir . '/config.php';

// Should keep original value since 'invalid_string' is not numeric
if ($savedConfig['max_items'] === 100) {
    test_pass('Invalid types are corrected (kept original)');
} else {
    test_fail('Invalid types are corrected', 'Invalid type was accepted: ' . var_export($savedConfig['max_items'], true));
}

// ============================================================
// TEST 5: UI section is protected
// ============================================================
echo "\n--- Test 5: UI section is protected ---\n";

$test5Dir = $testDir . '/test5';
@mkdir($test5Dir, 0755, true);
create_test_schema($test5Dir);
create_test_config($test5Dir, [
    'enabled' => true,
    'name' => 'original',
    'threshold' => 0.5,
    'max_items' => 100,
    'tags' => ['one', 'two', 'three'],
    'nested' => ['level1' => 'value1', 'level2' => 10],
    'ui' => ['fields' => ['protected', 'content']],
]);

// Try to modify UI section
$result = ConfigGuard::save($test5Dir . '/config.php', [
    'ui' => ['fields' => ['hacked']],
]);

$savedConfig = include $test5Dir . '/config.php';

if ($savedConfig['ui']['fields'] === ['protected', 'content']) {
    test_pass('UI section is protected');
} else {
    test_fail('UI section is protected', 'UI section was modified');
}

// ============================================================
// TEST 6: Type conversion works
// ============================================================
echo "\n--- Test 6: Type conversion works ---\n";

$test6Dir = $testDir . '/test6';
@mkdir($test6Dir, 0755, true);
create_test_schema($test6Dir);
create_test_config($test6Dir, [
    'enabled' => true,
    'name' => 'original',
    'threshold' => 0.5,
    'max_items' => 100,
    'tags' => ['one', 'two', 'three'],
    'nested' => ['level1' => 'value1', 'level2' => 10],
    'ui' => ['fields' => ['protected']],
]);

// Save with string numbers that should be converted
$result = ConfigGuard::save($test6Dir . '/config.php', [
    'threshold' => '0.75',  // String should become float
    'max_items' => '150',   // String should become int
    'enabled' => 'false',   // String should become bool
]);

$savedConfig = include $test6Dir . '/config.php';

$pass = true;
$reason = '';

if ($savedConfig['threshold'] !== 0.75) {
    $pass = false;
    $reason .= 'threshold not converted to float; ';
}
if ($savedConfig['max_items'] !== 150) {
    $pass = false;
    $reason .= 'max_items not converted to int; ';
}
if ($savedConfig['enabled'] !== false) {
    $pass = false;
    $reason .= 'enabled not converted to bool; ';
}

if ($pass) {
    test_pass('Type conversion works');
} else {
    test_fail('Type conversion works', trim($reason, '; '));
}

// ============================================================
// TEST 7: Sources section is protected
// ============================================================
echo "\n--- Test 7: Sources section is protected ---\n";

$test7Dir = $testDir . '/test7';
@mkdir($test7Dir, 0755, true);

// Create config with sources section
create_test_config($test7Dir, [
    'enabled' => true,
    'name' => 'original',
    'sources' => [
        'api' => 'https://api.example.com',
        'fallback' => 'https://fallback.example.com',
    ],
    'ui' => ['fields' => ['protected']],
]);

// Create minimal schema
file_put_contents($test7Dir . '/schema.php', "<?php\nreturn ['enabled' => true, 'name' => '', 'sources' => [], 'ui' => []];");

// Try to modify sources section via UI
$result = ConfigGuard::save($test7Dir . '/config.php', [
    'name' => 'changed',
    'sources' => ['hacked' => 'malicious'],
]);

$savedConfig = include $test7Dir . '/config.php';

if ($savedConfig['sources']['api'] === 'https://api.example.com' && 
    $savedConfig['sources']['fallback'] === 'https://fallback.example.com' &&
    $savedConfig['name'] === 'changed') {
    test_pass('Sources section is protected');
} else {
    test_fail('Sources section is protected', 'Sources section was modified or name not changed');
}

// ============================================================
// TEST 8: Output section is protected
// ============================================================
echo "\n--- Test 8: Output section is protected ---\n";

$test8Dir = $testDir . '/test8';
@mkdir($test8Dir, 0755, true);

create_test_config($test8Dir, [
    'enabled' => true,
    'output' => [
        'path' => '/var/log/app.log',
        'format' => 'json',
    ],
]);

file_put_contents($test8Dir . '/schema.php', "<?php\nreturn ['enabled' => true, 'output' => []];");

// Try to modify output section via UI
$result = ConfigGuard::save($test8Dir . '/config.php', [
    'output' => ['path' => '/etc/passwd'],
]);

$savedConfig = include $test8Dir . '/config.php';

if ($savedConfig['output']['path'] === '/var/log/app.log') {
    test_pass('Output section is protected');
} else {
    test_fail('Output section is protected', 'Output section was modified');
}

// ============================================================
// TEST 9: Systempaths section is protected
// ============================================================
echo "\n--- Test 9: Systempaths section is protected ---\n";

$test9Dir = $testDir . '/test9';
@mkdir($test9Dir, 0755, true);

create_test_config($test9Dir, [
    'enabled' => true,
    'systempaths' => [
        'root' => '/var/www/app',
        'storage' => '/var/www/app/storage',
    ],
]);

file_put_contents($test9Dir . '/schema.php', "<?php\nreturn ['enabled' => true, 'systempaths' => []];");

// Try to modify systempaths section via UI
$result = ConfigGuard::save($test9Dir . '/config.php', [
    'systempaths' => ['root' => '/tmp/hacked'],
]);

$savedConfig = include $test9Dir . '/config.php';

if ($savedConfig['systempaths']['root'] === '/var/www/app') {
    test_pass('Systempaths section is protected');
} else {
    test_fail('Systempaths section is protected', 'Systempaths section was modified');
}

// ============================================================
// TEST 10: Paths section is protected
// ============================================================
echo "\n--- Test 10: Paths section is protected ---\n";

$test10Dir = $testDir . '/test10';
@mkdir($test10Dir, 0755, true);

create_test_config($test10Dir, [
    'enabled' => true,
    'paths' => [
        'config' => '/etc/app/config',
        'data' => '/var/data',
    ],
]);

file_put_contents($test10Dir . '/schema.php', "<?php\nreturn ['enabled' => true, 'paths' => []];");

// Try to modify paths section via UI
$result = ConfigGuard::save($test10Dir . '/config.php', [
    'paths' => ['config' => '/etc/passwd'],
]);

$savedConfig = include $test10Dir . '/config.php';

if ($savedConfig['paths']['config'] === '/etc/app/config') {
    test_pass('Paths section is protected');
} else {
    test_fail('Paths section is protected', 'Paths section was modified');
}

// ============================================================
// TEST 11: Internal section is protected
// ============================================================
echo "\n--- Test 11: Internal section is protected ---\n";

$test11Dir = $testDir . '/test11';
@mkdir($test11Dir, 0755, true);

create_test_config($test11Dir, [
    'enabled' => true,
    'internal' => [
        'secret_key' => 'super_secret_123',
        'debug_mode' => false,
    ],
]);

file_put_contents($test11Dir . '/schema.php', "<?php\nreturn ['enabled' => true, 'internal' => []];");

// Try to modify internal section via UI
$result = ConfigGuard::save($test11Dir . '/config.php', [
    'internal' => ['secret_key' => 'hacked', 'debug_mode' => true],
]);

$savedConfig = include $test11Dir . '/config.php';

if ($savedConfig['internal']['secret_key'] === 'super_secret_123' &&
    $savedConfig['internal']['debug_mode'] === false) {
    test_pass('Internal section is protected');
} else {
    test_fail('Internal section is protected', 'Internal section was modified');
}

// ============================================================
// TEST 12: Non-destructive save (all protected keys at once)
// ============================================================
echo "\n--- Test 12: Non-destructive save with all protected keys ---\n";

$test12Dir = $testDir . '/test12';
@mkdir($test12Dir, 0755, true);

// Create config with ALL protected sections
create_test_config($test12Dir, [
    'enabled' => true,
    'name' => 'original',
    'threshold' => 0.5,
    'sources' => ['api' => 'https://api.example.com'],
    'output' => ['path' => '/var/log/app.log'],
    'ui' => ['fields' => ['field1', 'field2']],
    'systempaths' => ['root' => '/var/www'],
    'paths' => ['config' => '/etc/app'],
    'internal' => ['key' => 'secret'],
    'profiles' => ['default' => ['sensitivity' => 0.5]],
]);

file_put_contents($test12Dir . '/schema.php', "<?php\nreturn [
    'enabled' => true,
    'name' => '',
    'threshold' => 0.0,
    'sources' => [],
    'output' => [],
    'ui' => [],
    'systempaths' => [],
    'paths' => [],
    'internal' => [],
    'profiles' => [],
];");

// Try to modify everything including protected sections
$result = ConfigGuard::save($test12Dir . '/config.php', [
    'enabled' => false,
    'name' => 'changed',
    'threshold' => 0.9,
    'sources' => ['hacked' => 'bad'],
    'output' => ['hacked' => 'bad'],
    'ui' => ['hacked' => 'bad'],
    'systempaths' => ['hacked' => 'bad'],
    'paths' => ['hacked' => 'bad'],
    'internal' => ['hacked' => 'bad'],
    'profiles' => ['hacked' => 'bad'],
]);

$savedConfig = include $test12Dir . '/config.php';

$allProtectedIntact = (
    $savedConfig['sources']['api'] === 'https://api.example.com' &&
    $savedConfig['output']['path'] === '/var/log/app.log' &&
    $savedConfig['ui']['fields'] === ['field1', 'field2'] &&
    $savedConfig['systempaths']['root'] === '/var/www' &&
    $savedConfig['paths']['config'] === '/etc/app' &&
    $savedConfig['internal']['key'] === 'secret' &&
    $savedConfig['profiles']['default']['sensitivity'] === 0.5
);

$nonProtectedChanged = (
    $savedConfig['enabled'] === false &&
    $savedConfig['name'] === 'changed' &&
    $savedConfig['threshold'] === 0.9
);

if ($allProtectedIntact && $nonProtectedChanged) {
    test_pass('Non-destructive save with all protected keys');
} else {
    $reason = '';
    if (!$allProtectedIntact) $reason .= 'Protected keys were modified; ';
    if (!$nonProtectedChanged) $reason .= 'Non-protected keys were not changed; ';
    test_fail('Non-destructive save with all protected keys', trim($reason, '; '));
}

// Cleanup
echo "\n--- Cleanup ---\n";
function rrmdir($dir) {
    if (is_dir($dir)) {
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
                if (is_dir($dir . "/" . $object)) {
                    rrmdir($dir . "/" . $object);
                } else {
                    unlink($dir . "/" . $object);
                }
            }
        }
        rmdir($dir);
    }
}
rrmdir($testDir);
echo "Test directory cleaned up.\n";

echo "\n=== All tests completed ===\n";
