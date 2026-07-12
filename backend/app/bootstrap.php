<?php

declare(strict_types=1);

/**
 * 全リクエストの入口で最初に読み込まれる初期化処理。
 * 設定読込 → エラーハンドリング → セッション開始まで行う。
 */

date_default_timezone_set('Asia/Tokyo');
mb_internal_encoding('UTF-8');

$mlvConfigPath = __DIR__ . '/config.php';
if (!is_file($mlvConfigPath)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => ['code' => 'server_error', 'message' => 'サーバー設定が見つかりません。']], JSON_UNESCAPED_UNICODE);
    exit;
}

/** @var array<string,mixed> $GLOBALS['MLV_CONFIG'] */
$GLOBALS['MLV_CONFIG'] = require $mlvConfigPath;

/**
 * 設定値を取得する。存在しないキーは開発ミスとして例外にする。
 */
function mlv_config(string $key): mixed
{
    if (!array_key_exists($key, $GLOBALS['MLV_CONFIG'])) {
        throw new RuntimeException("Missing config key: {$key}");
    }
    return $GLOBALS['MLV_CONFIG'][$key];
}

/**
 * アプリログへ1行追記する（パスワード・トークン平文は書き込まないこと）。
 */
function mlv_log(string $message): void
{
    $logFile = $GLOBALS['MLV_CONFIG']['log_file'] ?? null;
    if ($logFile === null) {
        return;
    }
    $line = sprintf('[%s] %s', date('c'), $message) . PHP_EOL;
    @error_log($line, 3, $logFile);
}

// --- エラー表示は常にオフ。詳細はログへ、レスポンスは固定文言のJSONへ。 ---
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

function mlv_send_server_error(): never
{
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['error' => ['code' => 'server_error', 'message' => 'サーバーエラーが発生しました。しばらくしてから再度お試しください。']], JSON_UNESCAPED_UNICODE);
    exit;
}

set_exception_handler(function (Throwable $e): void {
    mlv_log('Uncaught exception: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    mlv_send_server_error();
});

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(function (): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        mlv_log('Fatal error: ' . $error['message'] . ' at ' . $error['file'] . ':' . $error['line']);
        if (!headers_sent() && ob_get_level() === 0) {
            mlv_send_server_error();
        }
    }
});

// --- セッション設定・開始 ---
$sessionPath = mlv_config('session_path');
if (!is_dir($sessionPath)) {
    @mkdir($sessionPath, 0700, true);
}

ini_set('session.save_path', $sessionPath);
ini_set('session.use_strict_mode', '1');
ini_set('session.gc_maxlifetime', (string) mlv_config('session_lifetime'));
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', '1');
ini_set('session.cookie_lifetime', '0');
session_name((string) mlv_config('session_cookie_name'));

session_start();

require_once __DIR__ . '/json.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/ratelimit.php';
require_once __DIR__ . '/actives.php';
require_once __DIR__ . '/mail_parser.php';
require_once __DIR__ . '/indexer.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api.php';
require_once __DIR__ . '/router.php';

mlv_db(); // 接続確立とマイグレーション適用をこの時点で実行
