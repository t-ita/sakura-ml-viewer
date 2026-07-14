<?php

declare(strict_types=1);

/**
 * 管理者向け読み取り専用API。DESIGN.md §4.5, §5.8, §7.7 に対応する。
 * すべてGETのみで変更系エンドポイントは持たない。認証必須APIの毎リクエスト
 * 検証に加え、mlv_require_admin()（actives.php）による管理者判定を必ず通す。
 */

// ---------------------------------------------------------------------
// GET /admin/users
// ---------------------------------------------------------------------

function mlv_handle_admin_users(): void
{
    mlv_require_admin();

    $actives = mlv_load_actives();
    if ($actives === null) {
        json_error('server_error', 'サーバーエラーが発生しました。しばらくしてから再度お試しください。', 503);
    }

    $pdo = mlv_db();
    $userRows = $pdo->query(
        'SELECT email, password_hash, created_at, password_updated_at, last_login_at FROM users'
    )->fetchAll();

    $pendingStmt = $pdo->prepare('SELECT DISTINCT email FROM tokens WHERE used_at IS NULL AND expires_at >= :now');
    $pendingStmt->execute([':now' => time()]);
    $pendingEmails = array_column($pendingStmt->fetchAll(), 'email');

    json_response(mlv_admin_build_user_lists($actives, $userRows, $pendingEmails));
}

/**
 * activesを正とした会員一覧・残骸ユーザー一覧・サマリを構築する純関数。
 * DBにもfmlにも触らないため、fixtureデータでユニットテスト可能。
 *
 * @param array<string,true> $activesSet
 * @param list<array<string,mixed>> $userRows usersテーブルの全行
 * @param list<string> $pendingEmails 未使用・未失効トークンを持つemailの一覧
 * @return array{summary:array<string,int>, members:list<array<string,mixed>>, orphan_users:list<array<string,mixed>>}
 */
function mlv_admin_build_user_lists(array $activesSet, array $userRows, array $pendingEmails): array
{
    $pendingSet = array_fill_keys($pendingEmails, true);

    $usersByEmail = [];
    foreach ($userRows as $row) {
        $usersByEmail[(string) $row['email']] = $row;
    }

    $members = [];
    $registeredCount = 0;
    foreach (array_keys($activesSet) as $email) {
        $row = $usersByEmail[$email] ?? null;
        if ($row !== null && $row['password_hash'] !== null) {
            $registeredCount++;
        }
        $members[] = mlv_admin_user_item($email, $row, isset($pendingSet[$email]));
    }
    usort($members, static fn (array $a, array $b): int => $a['email'] <=> $b['email']);

    $orphanEmails = array_diff(array_keys($usersByEmail), array_keys($activesSet));
    $orphans = [];
    foreach ($orphanEmails as $email) {
        $orphans[] = mlv_admin_user_item($email, $usersByEmail[$email], isset($pendingSet[$email]));
    }
    usort($orphans, static fn (array $a, array $b): int => $a['email'] <=> $b['email']);

    return [
        'summary' => [
            'active_members' => count($activesSet),
            'password_registered' => $registeredCount,
            'orphan_users' => count($orphans),
        ],
        'members' => $members,
        'orphan_users' => $orphans,
    ];
}

/**
 * @param array<string,mixed>|null $row usersテーブルの行(存在しない会員はnull)
 * @return array<string,mixed>
 */
function mlv_admin_user_item(string $email, ?array $row, bool $pendingToken): array
{
    return [
        'email' => $email,
        'password_registered' => $row !== null && $row['password_hash'] !== null,
        'created_at' => $row !== null ? mlv_epoch_to_iso8601((int) $row['created_at']) : null,
        'password_updated_at' => ($row !== null && $row['password_updated_at'] !== null)
            ? mlv_epoch_to_iso8601((int) $row['password_updated_at'])
            : null,
        'last_login_at' => ($row !== null && $row['last_login_at'] !== null)
            ? mlv_epoch_to_iso8601((int) $row['last_login_at'])
            : null,
        'pending_token' => $pendingToken,
    ];
}

// ---------------------------------------------------------------------
// GET /admin/articles
// ---------------------------------------------------------------------

function mlv_handle_admin_articles(): void
{
    mlv_require_admin();

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = (int) ($_GET['per_page'] ?? 50);
    $perPage = max(1, min(100, $perPage));

    $status = mlv_query_param('status', 10);
    $status = $status === '' ? 'all' : $status;
    if (!in_array($status, ['all', 'ok', 'partial', 'error'], true)) {
        json_error('invalid_request', 'status の形式が正しくありません。', 400);
    }

    $pdo = mlv_db();

    // このエンドポイントはインデックス便乗処理(mlv_maybe_index)を実行しない。
    // 診断中は状態を動かさず観察できるべきであり、インデックスを進めたい場合は
    // 通常の一覧画面(GET /articles)を開けばよい(DESIGN.md §4.5)。
    $seq = mlv_read_seq();
    $indexedMax = mlv_current_max_article_id($pdo);
    $pending = $seq === null ? 0 : max(0, $seq - $indexedMax);

    $countByStatus = ['ok' => 0, 'partial' => 0, 'error' => 0];
    $statusRows = $pdo->query('SELECT parse_status, COUNT(*) AS c FROM articles GROUP BY parse_status')->fetchAll();
    foreach ($statusRows as $row) {
        $key = (string) $row['parse_status'];
        if (array_key_exists($key, $countByStatus)) {
            $countByStatus[$key] = (int) $row['c'];
        }
    }

    [$whereSql, $params] = mlv_admin_build_article_where($status);

    $countStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM articles WHERE {$whereSql}");
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $total = (int) $countStmt->fetch()['c'];

    $offset = ($page - 1) * $perPage;
    $listStmt = $pdo->prepare(
        "SELECT id, subject, from_addr, date_epoch, parse_status, indexed_at
         FROM articles
         WHERE {$whereSql}
         ORDER BY id DESC
         LIMIT :limit OFFSET :offset"
    );
    foreach ($params as $key => $value) {
        $listStmt->bindValue($key, $value);
    }
    $listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $listStmt->execute();

    $items = array_map('mlv_admin_article_to_item', $listStmt->fetchAll());

    json_response([
        'summary' => [
            'seq' => $seq ?? $indexedMax,
            'indexed_max' => $indexedMax,
            'pending' => $pending,
            'count_ok' => $countByStatus['ok'],
            'count_partial' => $countByStatus['partial'],
            'count_error' => $countByStatus['error'],
        ],
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
    ]);
}

/**
 * status フィルタに応じたWHERE句とバインドパラメータを構築する純関数。
 * status='all' のときは絞り込みなし(=parse_status='error' の行も含む。§4.5)。
 *
 * @return array{0:string,1:array<string,scalar>}
 */
function mlv_admin_build_article_where(string $status): array
{
    if ($status === 'all') {
        return ['1=1', []];
    }
    return ['parse_status = :status', [':status' => $status]];
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function mlv_admin_article_to_item(array $row): array
{
    $dateEpoch = (int) $row['date_epoch'];

    return [
        'id' => (int) $row['id'],
        'subject' => $row['subject'],
        'from_addr' => $row['from_addr'],
        'date' => $dateEpoch > 0 ? mlv_epoch_to_iso8601($dateEpoch) : null,
        'parse_status' => $row['parse_status'],
        'indexed_at' => mlv_epoch_to_iso8601((int) $row['indexed_at']),
    ];
}
