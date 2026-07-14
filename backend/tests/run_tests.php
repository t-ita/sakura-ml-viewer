<?php

declare(strict_types=1);

/**
 * 依存ゼロの軽量テストランナー。実行方法:
 *   php backend/tests/run_tests.php
 *
 * DB・セッション等の重い初期化を行う bootstrap.php は使わず、ユニットテストに
 * 必要な最小限のapp/*.phpのみを読み込む。mlv_config() はテスト用の簡易実装をここで用意する。
 */

mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Tokyo');

$testsDir = __DIR__;
$appDir = __DIR__ . '/../app';
$tmpDir = __DIR__ . '/tmp';

if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0700, true);
}

// 実行の都度クリーンな状態から始める
$dbPath = $tmpDir . '/test.sqlite';
foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $f) {
    if (is_file($f)) {
        unlink($f);
    }
}

$GLOBALS['MLV_CONFIG'] = [
    'actives_file' => $testsDir . '/fixtures/actives_sample',
    'admins_file' => $testsDir . '/fixtures/admins_sample',
    'db_path' => $dbPath,
    'seq_file' => $testsDir . '/fixtures/does_not_exist_seq',
    'locks_dir' => $tmpDir . '/locks',
    'rate_limits' => [
        'login_email' => ['limit' => 5, 'window_s' => 900],
        'login_ip' => ['limit' => 20, 'window_s' => 900],
        'token_email' => ['limit' => 3, 'window_s' => 3600],
        'token_ip' => ['limit' => 10, 'window_s' => 3600],
        'setpw_ip' => ['limit' => 10, 'window_s' => 900],
    ],
];

/** bootstrap.php本体の同名関数の簡易版。ユニットテストではセッション等の副作用は不要。 */
function mlv_config(string $key): mixed
{
    if (!array_key_exists($key, $GLOBALS['MLV_CONFIG'])) {
        throw new RuntimeException("Missing test config key: {$key}");
    }
    return $GLOBALS['MLV_CONFIG'][$key];
}

require $testsDir . '/test_framework.php';
require $appDir . '/json.php';
require $appDir . '/db.php';
require $appDir . '/ratelimit.php';
require $appDir . '/actives.php';
require $appDir . '/mail_parser.php';
require $appDir . '/indexer.php';
require $appDir . '/auth.php';
require $appDir . '/api.php';
require $appDir . '/admin.php';

$testFiles = glob($testsDir . '/*_test.php');
sort($testFiles);
foreach ($testFiles as $file) {
    require $file;
}

$pass = 0;
$fail = 0;
$failedNames = [];

foreach (mlv_test_registry() as $name => $fn) {
    try {
        $fn();
        echo "PASS  {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "FAIL  {$name}\n";
        echo '      ' . $e->getMessage() . "\n";
        $fail++;
        $failedNames[] = $name;
    }
}

echo "\n" . str_repeat('-', 60) . "\n";
printf("Total: %d, Passed: %d, Failed: %d\n", $pass + $fail, $pass, $fail);

if ($fail > 0) {
    echo "\nFailed tests:\n";
    foreach ($failedNames as $n) {
        echo "  - {$n}\n";
    }
    exit(1);
}

exit(0);
