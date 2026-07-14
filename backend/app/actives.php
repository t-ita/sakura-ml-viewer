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
    return mlv_load_address_list(mlv_config('actives_file'));
}

/**
 * activesと同一形式（1行1アドレス、#コメント・空行無視、先頭トークンのみ採用、小文字化）の
 * プレーンテキストファイルを読み込み、正規化済みメールアドレスの集合を返す。
 * 読み取り不能な場合は null を返す（例外は投げない）。
 *
 * @return array<string,true>|null
 */
function mlv_load_address_list(string $path): ?array
{
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
 * 管理者リストファイル（admins。§5.8）を読み込む。
 * config.phpに admins_file キーが無い、またはファイルが読めない場合は null を返す
 * （activesと異なりフェイルクローズしない: 管理機能が任意であり「無い」ことが正常状態のため）。
 *
 * @return array<string,true>|null
 */
function mlv_load_admins(): ?array
{
    $path = $GLOBALS['MLV_CONFIG']['admins_file'] ?? null;
    if (!is_string($path) || $path === '') {
        return null;
    }
    return mlv_load_address_list($path);
}

/**
 * 管理者判定（§5.8）。以下の3条件をすべて満たすときのみtrue:
 *   認証済み ∧ actives在籍 ∧ adminsファイルに記載
 * activesを外れた者はadminsに残っていても管理者ではない（退会者即時遮断の原則を適用）。
 */
function mlv_is_admin(string $email): bool
{
    $admins = mlv_load_admins();
    if ($admins === null) {
        return false;
    }
    $email = strtolower(trim($email));
    if (!isset($admins[$email])) {
        return false;
    }
    return mlv_is_active_member($email);
}

/**
 * 管理系API共通ミドルウェア。認証必須メンバーであることに加え管理者判定を行い、
 * 非管理者は一般の403(forbidden)と同一形式・同一文言で終了させる
 * （管理機能の存在や判定結果を応答から推測させないため。§7.7）。
 */
function mlv_require_admin(): string
{
    $email = mlv_require_authenticated_member();
    if (!mlv_is_admin($email)) {
        json_error('forbidden', 'メンバー資格が確認できませんでした。', 403);
    }
    return $email;
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
