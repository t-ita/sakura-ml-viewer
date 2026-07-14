<?php

declare(strict_types=1);

/**
 * 認証・パスワード管理API。DESIGN.md §4.3, §5 に対応する。
 */

/**
 * 存在しないメールアドレス・未設定パスワードに対する password_verify() の所要時間を、
 * 実在ユーザーの検証と揃えるためのダミーハッシュを返す（列挙防止）。
 *
 * 固定のダミーハッシュだとコスト値がハードコードされ、実行環境の PASSWORD_DEFAULT の
 * コストと食い違うと逆に時間差の手がかりになる（例: 生成環境ではcost=12だが、稼働先の
 * PHPバージョンによってはcost=10が既定であるなど）。かといって毎リクエスト
 * password_hash() で生成すると、そのコスト自体が password_verify() と同等かそれ以上の
 * 負荷を持つため今度はダミー側が実会員側より遅くなる。そのため初回のみ実行環境の
 * PASSWORD_DEFAULT で生成し、mlviewer-data配下に保存して使い回す。
 */
function mlv_dummy_password_hash(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $cacheFile = dirname((string) mlv_config('db_path')) . '/dummy_hash.txt';

    $existing = @file_get_contents($cacheFile);
    if (is_string($existing)) {
        $existing = trim($existing);
        if ($existing !== '' && password_get_info($existing)['algoName'] !== 'unknown') {
            $cached = $existing;
            return $cached;
        }
    }

    $cached = password_hash('mlv-dummy-timing-reference', PASSWORD_DEFAULT);

    $dir = dirname($cacheFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (@file_put_contents($cacheFile, $cached) !== false) {
        @chmod($cacheFile, 0600);
    }

    return $cached;
}

// ---------------------------------------------------------------------
// GET /auth/session
// ---------------------------------------------------------------------

function mlv_handle_auth_session(): void
{
    $email = $_SESSION['email'] ?? null;

    if (is_string($email) && $email !== '') {
        if (mlv_is_active_member($email)) {
            json_response([
                'authenticated' => true,
                'email' => $email,
                'csrf_token' => mlv_issue_csrf_token(),
                'is_admin' => mlv_is_admin($email),
            ]);
        }
        // 退会済み: 古いセッションを破棄して未認証として扱う
        $_SESSION = [];
        session_regenerate_id(true);
    }

    json_response(['authenticated' => false, 'csrf_token' => mlv_issue_csrf_token()]);
}

// ---------------------------------------------------------------------
// POST /auth/login
// ---------------------------------------------------------------------

function mlv_handle_login(): void
{
    mlv_verify_csrf();

    $body = read_json_body();
    $email = strtolower(require_string_param($body, 'email', 254));
    $password = require_string_param($body, 'password', 128, true, false);

    mlv_enforce_rate_limit('login_email', $email);
    mlv_enforce_rate_limit('login_ip', mlv_client_ip());

    if (!mlv_verify_login_credentials($email, $password)) {
        json_error('invalid_credentials', 'メールアドレスまたはパスワードが正しくありません。', 401);
    }

    mlv_rate_limit_reset('login_email', $email);
    mlv_rate_limit_reset('login_ip', mlv_client_ip());

    session_regenerate_id(true);
    // session_regenerate_id()はセッションIDのみ更新し$_SESSIONの内容(csrf含む)は
    // 引き継がれるため、権限昇格のタイミングでCSRFトークン自体も明示的に更新する。
    unset($_SESSION['csrf']);
    $_SESSION['email'] = $email;

    $stmt = mlv_db()->prepare('UPDATE users SET last_login_at = :t WHERE email = :e');
    $stmt->execute([':t' => time(), ':e' => $email]);

    json_response([
        'email' => $email,
        'csrf_token' => mlv_issue_csrf_token(),
        'is_admin' => mlv_is_admin($email),
    ]);
}

function mlv_verify_login_credentials(string $email, string $password): bool
{
    if (!mlv_is_active_member($email)) {
        password_verify($password, mlv_dummy_password_hash());
        return false;
    }

    $stmt = mlv_db()->prepare('SELECT password_hash FROM users WHERE email = :e');
    $stmt->execute([':e' => $email]);
    $row = $stmt->fetch();

    if ($row === false || $row['password_hash'] === null) {
        password_verify($password, mlv_dummy_password_hash());
        return false;
    }

    return password_verify($password, $row['password_hash']);
}

// ---------------------------------------------------------------------
// POST /auth/logout
// ---------------------------------------------------------------------

function mlv_handle_logout(): void
{
    mlv_verify_csrf();

    if (empty($_SESSION['email'])) {
        json_error('unauthorized', 'ログインが必要です。', 401);
    }

    $_SESSION = [];
    session_destroy();

    json_response([]);
}

// ---------------------------------------------------------------------
// POST /auth/request-token（初回パスワード登録・リセット共用）
// ---------------------------------------------------------------------

function mlv_handle_request_token(): void
{
    mlv_verify_csrf();

    $body = read_json_body();
    $email = strtolower(require_string_param($body, 'email', 254));

    mlv_enforce_rate_limit('token_email', $email);
    mlv_enforce_rate_limit('token_ip', mlv_client_ip());

    $actives = mlv_load_actives();
    if ($actives === null) {
        json_error('server_error', 'サーバーエラーが発生しました。しばらくしてから再度お試しください。', 503);
    }

    // 在籍有無に関わらず必ず計算する（メンバー列挙防止のためのタイミング均一化）
    $token = mlv_generate_token();
    $tokenHash = mlv_hash_token($token);

    if (isset($actives[$email])) {
        mlv_issue_password_token($email, $token, $tokenHash);
        mlv_send_token_mail($email, $token);
    }

    json_response(['message' => '入力されたアドレスがメンバーとして登録されている場合、案内メールを送信しました。']);
}

function mlv_issue_password_token(string $email, string $token, string $tokenHash): void
{
    $pdo = mlv_db();
    $now = time();

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare('INSERT OR IGNORE INTO users (email, created_at) VALUES (:e, :t)');
        $ins->execute([':e' => $email, ':t' => $now]);

        // 有効トークンは常に最大1つ: 発行前に既存の未使用分を全失効させる
        $invalidate = $pdo->prepare('UPDATE tokens SET used_at = :t WHERE email = :e AND used_at IS NULL');
        $invalidate->execute([':t' => $now, ':e' => $email]);

        $insertToken = $pdo->prepare(
            'INSERT INTO tokens (email, token_hash, purpose, expires_at, created_at) VALUES (:e, :h, :p, :exp, :c)'
        );
        $insertToken->execute([
            ':e' => $email,
            ':h' => $tokenHash,
            ':p' => 'set_password',
            ':exp' => $now + (int) mlv_config('token_ttl_s'),
            ':c' => $now,
        ]);

        mlv_cleanup_old_tokens($pdo, $now);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function mlv_cleanup_old_tokens(PDO $pdo, int $now): void
{
    $threshold = $now - 30 * 86400;
    $stmt = $pdo->prepare('DELETE FROM tokens WHERE (used_at IS NOT NULL AND used_at < :t1) OR expires_at < :t2');
    $stmt->execute([':t1' => $threshold, ':t2' => $threshold]);
}

function mlv_generate_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function mlv_hash_token(string $token): string
{
    return hash('sha256', $token);
}

// ---------------------------------------------------------------------
// POST /auth/set-password
// ---------------------------------------------------------------------

function mlv_handle_set_password(): void
{
    mlv_verify_csrf();
    mlv_enforce_rate_limit('setpw_ip', mlv_client_ip());

    $body = read_json_body();
    $token = require_string_param($body, 'token', 512);
    $password = require_string_param($body, 'password', 128, true, false);

    mlv_validate_password_policy($password);

    $tokenHash = mlv_hash_token($token);
    $pdo = mlv_db();

    $stmt = $pdo->prepare('SELECT email, expires_at, used_at FROM tokens WHERE token_hash = :h');
    $stmt->execute([':h' => $tokenHash]);
    $row = $stmt->fetch();

    $now = time();
    if ($row === false || $row['used_at'] !== null || (int) $row['expires_at'] < $now) {
        json_error('invalid_token', 'リンクが無効か、有効期限が切れています。再度お手続きください。', 400);
    }

    $email = (string) $row['email'];
    // 発行後に退会した可能性があるため再突合する
    if (!mlv_is_active_member($email)) {
        json_error('invalid_token', 'リンクが無効か、有効期限が切れています。再度お手続きください。', 400);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare('UPDATE users SET password_hash = :h, password_updated_at = :t WHERE email = :e');
        $upd->execute([':h' => $hash, ':t' => $now, ':e' => $email]);

        // 使用済みにするとともに、同emailの他トークンも全失効させる
        $consume = $pdo->prepare('UPDATE tokens SET used_at = :t WHERE email = :e AND used_at IS NULL');
        $consume->execute([':t' => $now, ':e' => $email]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    json_response([]);
}

// ---------------------------------------------------------------------
// POST /auth/change-password
// ---------------------------------------------------------------------

function mlv_handle_change_password(): void
{
    mlv_verify_csrf();
    $email = mlv_require_authenticated_member();

    $body = read_json_body();
    $currentPassword = require_string_param($body, 'current_password', 128, true, false);
    $newPassword = require_string_param($body, 'new_password', 128, true, false);

    mlv_validate_password_policy($newPassword);

    $pdo = mlv_db();
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE email = :e');
    $stmt->execute([':e' => $email]);
    $row = $stmt->fetch();

    $currentHash = ($row !== false && $row['password_hash'] !== null) ? $row['password_hash'] : mlv_dummy_password_hash();
    if (!password_verify($currentPassword, $currentHash) || $row === false || $row['password_hash'] === null) {
        json_error('invalid_credentials', '現在のパスワードが正しくありません。', 401);
    }

    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $upd = $pdo->prepare('UPDATE users SET password_hash = :h, password_updated_at = :t WHERE email = :e');
    $upd->execute([':h' => $hash, ':t' => time(), ':e' => $email]);

    session_regenerate_id(true);

    json_response([]);
}

// ---------------------------------------------------------------------
// 共通: パスワードポリシー
// ---------------------------------------------------------------------

function mlv_validate_password_policy(string $password): void
{
    $len = mb_strlen($password);
    if ($len < 8 || $len > 128) {
        json_error('invalid_request', 'パスワードは8文字以上128文字以内で入力してください。', 400);
    }
}
