<?php

declare(strict_types=1);

/**
 * セッションのCSRFトークンを取得する。未発行なら新規生成する。
 */
function mlv_issue_csrf_token(): string
{
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/**
 * X-CSRF-Tokenヘッダとセッション内トークンを検証する。不一致・欠落は403で終了する。
 * Originヘッダが送られてきた場合は自オリジンとの一致も検証する（二重防御）。
 */
function mlv_verify_csrf(): void
{
    $expected = $_SESSION['csrf'] ?? null;
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

    if (!is_string($expected) || $expected === '' || !is_string($provided) || !hash_equals($expected, $provided)) {
        json_error('forbidden', 'リクエストを検証できませんでした。ページを再読み込みしてください。', 403);
    }

    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    if (is_string($origin) && $origin !== '') {
        $selfOrigin = mlv_self_origin();
        if ($selfOrigin !== null && !hash_equals($selfOrigin, $origin)) {
            json_error('forbidden', 'リクエストを検証できませんでした。ページを再読み込みしてください。', 403);
        }
    }
}

function mlv_self_origin(): ?string
{
    $host = $_SERVER['HTTP_HOST'] ?? null;
    if (!is_string($host) || $host === '') {
        return null;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $host;
}
