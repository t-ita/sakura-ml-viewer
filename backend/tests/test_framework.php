<?php

declare(strict_types=1);

/**
 * 依存ゼロの軽量テストフレームワーク。*_test.php から mlv_test() でテストを登録し、
 * run_tests.php がまとめて実行する。
 */

$GLOBALS['__mlv_tests'] = [];

function mlv_test(string $name, callable $fn): void
{
    if (isset($GLOBALS['__mlv_tests'][$name])) {
        throw new RuntimeException("duplicate test name: {$name}");
    }
    $GLOBALS['__mlv_tests'][$name] = $fn;
}

/** @return array<string,callable> */
function mlv_test_registry(): array
{
    return $GLOBALS['__mlv_tests'];
}

function assert_true(bool $cond, string $message): void
{
    if (!$cond) {
        throw new RuntimeException($message);
    }
}

function assert_equals(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        $e = is_string($expected) ? $expected : json_encode($expected, JSON_UNESCAPED_UNICODE);
        $a = is_string($actual) ? $actual : json_encode($actual, JSON_UNESCAPED_UNICODE);
        throw new RuntimeException("{$message} (expected: [{$e}], actual: [{$a}])");
    }
}

function assert_str_contains(string $haystack, string $needle, string $message): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException("{$message} ('{$needle}' not found in '{$haystack}')");
    }
}
