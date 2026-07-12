<?php

declare(strict_types=1);

/**
 * fmlの actives ファイル（読み取り専用）とメンバー資格の突合を行う。
 * DBにはキャッシュしない: 認証必須APIの毎リクエストでこのファイルを直接読むことで、
 * 退会（activesからの削除）が次のリクエストから即座に反映されることを保証する。
 */

/**
 * activesファイルを読み込み、正規化済みメールアドレスの集合を返す。
 * 読み取り不能な場合は null を返す（呼び出し側でフェイルクローズを判断させるため例外は投げない）。
 *
 * @return array<string,true>|null
 */
function mlv_load_actives(): ?array
{
    $path = mlv_config('actives_file');
    $fp = @fopen($path, 'r');
    if ($fp === false) {
        return null;
    }

    $set = [];
    try {
        while (($line = fgets($fp)) !== false) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            // fml4はアドレスの後ろに "m=" "s=1" 等のオプションが続き得るため先頭トークンのみ採用する
            $token = preg_split('/\s+/', $line, 2)[0] ?? '';
            $token = strtolower(trim($token));
            if ($token !== '') {
                $set[$token] = true;
            }
        }
    } finally {
        fclose($fp);
    }

    return $set;
}

/**
 * メンバー資格を判定する。activesが読めない場合はフェイルクローズとして
 * 503 server_error を返しリクエストを終了させる（誤って全開放しないため）。
 */
function mlv_is_active_member(string $email): bool
{
    $actives = mlv_load_actives();
    if ($actives === null) {
        mlv_log('actives file unreadable: fail-closed 503');
        json_error('server_error', 'サーバーエラーが発生しました。しばらくしてから再度お試しください。', 503);
    }
    return isset($actives[strtolower(trim($email))]);
}

/**
 * 認証必須API共通ミドルウェア。セッション未確立、またはactivesに不在なら401/403で終了する。
 * 不在の場合はセッションを破棄し、退会後にセッションが生き残らないようにする。
 */
function mlv_require_authenticated_member(): string
{
    $email = $_SESSION['email'] ?? null;
    if (!is_string($email) || $email === '') {
        json_error('unauthorized', 'ログインが必要です。', 401);
    }

    if (!mlv_is_active_member($email)) {
        $_SESSION = [];
        session_regenerate_id(true); // 古いセッションデータを破棄し新しいIDを発行する
        json_error('forbidden', 'メンバー資格が確認できませんでした。', 403);
    }

    return $email;
}
