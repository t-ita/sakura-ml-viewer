<?php

declare(strict_types=1);

/**
 * JSON成功レスポンスを送出して終了する。
 *
 * @param array<string,mixed> $data
 */
function json_response(array $data, int $status = 200): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * JSONエラーレスポンスを送出して終了する。DESIGN.md §4.1のエラーコード表に準拠すること。
 */
function json_error(string $code, string $message, int $status): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        if ($status === 429) {
            header('Retry-After: 60');
        }
    }
    echo json_encode(['error' => ['code' => $code, 'message' => $message]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * リクエストボディをJSONとして読み取る。不正な場合は invalid_request で終了する。
 *
 * @return array<string,mixed>
 */
function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        json_error('invalid_request', 'リクエストの形式が正しくありません。', 400);
    }
    return $decoded;
}

/**
 * 文字列パラメータを取得し、長さ検証を行う。必須で欠落していれば invalid_request。
 * $trim=false はパスワードなど、前後の空白も意味を持つ値に使う。
 */
function require_string_param(array $body, string $key, int $maxLength, bool $required = true, bool $trim = true): string
{
    $value = $body[$key] ?? null;
    if ($value === null || $value === '') {
        if ($required) {
            json_error('invalid_request', "{$key} は必須です。", 400);
        }
        return '';
    }
    if (!is_string($value)) {
        json_error('invalid_request', "{$key} の形式が正しくありません。", 400);
    }
    if ($trim) {
        $value = trim($value);
    }
    if (mb_strlen($value) > $maxLength) {
        json_error('invalid_request', "{$key} が長すぎます。", 400);
    }
    return $value;
}
