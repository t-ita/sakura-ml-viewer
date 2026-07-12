<?php

declare(strict_types=1);

/**
 * 記事一覧・検索・詳細・インデックス状況API。DESIGN.md §4.4 に対応する。
 */

// ---------------------------------------------------------------------
// GET /articles
// ---------------------------------------------------------------------

function mlv_handle_articles_list(): void
{
    mlv_require_authenticated_member();

    $indexProgress = mlv_maybe_index();

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = (int) ($_GET['per_page'] ?? 50);
    $perPage = max(1, min(100, $perPage));

    $q = mlv_query_param('q', 100);
    $sender = mlv_query_param('sender', 254);
    $dateFrom = mlv_query_param('date_from', 10);
    $dateTo = mlv_query_param('date_to', 10);

    [$whereSql, $params] = mlv_build_article_where($q, $sender, $dateFrom, $dateTo);

    $pdo = mlv_db();

    $countStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM articles WHERE {$whereSql}");
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $total = (int) $countStmt->fetch()['c'];

    $offset = ($page - 1) * $perPage;
    $listStmt = $pdo->prepare(
        "SELECT id, subject, from_name, from_addr, date_epoch, attachments_json, body_text
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

    $items = array_map('mlv_article_to_list_item', $listStmt->fetchAll());

    json_response([
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'indexing' => ['pending' => $indexProgress['pending']],
    ]);
}

/**
 * @return array{0:string,1:array<string,scalar>}
 */
function mlv_build_article_where(string $q, string $sender, string $dateFrom, string $dateTo): array
{
    $conditions = ["parse_status != 'error'"];
    $params = [];

    if ($q !== '') {
        $terms = preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($terms as $i => $term) {
            $key = ":q{$i}";
            $conditions[] = "(subject LIKE {$key} ESCAPE '\\' OR body_text LIKE {$key} ESCAPE '\\')";
            $params[$key] = '%' . mlv_escape_like($term) . '%';
        }
    }

    if ($sender !== '') {
        $conditions[] = "(from_addr LIKE :sender ESCAPE '\\' OR from_name LIKE :sender ESCAPE '\\')";
        $params[':sender'] = '%' . mlv_escape_like($sender) . '%';
    }

    if ($dateFrom !== '') {
        $epoch = mlv_jst_date_to_epoch($dateFrom, false);
        if ($epoch === null) {
            json_error('invalid_request', 'date_from の形式が正しくありません。', 400);
        }
        $conditions[] = 'date_epoch >= :date_from';
        $params[':date_from'] = $epoch;
    }

    if ($dateTo !== '') {
        $epoch = mlv_jst_date_to_epoch($dateTo, true);
        if ($epoch === null) {
            json_error('invalid_request', 'date_to の形式が正しくありません。', 400);
        }
        $conditions[] = 'date_epoch <= :date_to';
        $params[':date_to'] = $epoch;
    }

    return [implode(' AND ', $conditions), $params];
}

function mlv_escape_like(string $s): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
}

function mlv_jst_date_to_epoch(string $ymd, bool $endOfDay): ?int
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
        return null;
    }
    try {
        $dt = new DateTimeImmutable($ymd . ' ' . ($endOfDay ? '23:59:59' : '00:00:00'), new DateTimeZone('Asia/Tokyo'));
    } catch (\Throwable) {
        return null;
    }
    return $dt->getTimestamp();
}

function mlv_query_param(string $name, int $maxLen): string
{
    $value = $_GET[$name] ?? '';
    if (!is_string($value)) {
        json_error('invalid_request', "{$name} の形式が正しくありません。", 400);
    }
    $value = trim($value);
    if (mb_strlen($value) > $maxLen) {
        json_error('invalid_request', "{$name} が長すぎます。", 400);
    }
    return $value;
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function mlv_article_to_list_item(array $row): array
{
    $attachments = json_decode((string) $row['attachments_json'], true);
    $hasAttachments = is_array($attachments) && count($attachments) > 0;

    return [
        'id' => (int) $row['id'],
        'subject' => $row['subject'],
        'from_name' => $row['from_name'],
        'from_addr' => $row['from_addr'],
        'date' => mlv_epoch_to_iso8601((int) $row['date_epoch']),
        'has_attachments' => $hasAttachments,
        'snippet' => mlv_make_snippet((string) $row['body_text']),
    ];
}

function mlv_make_snippet(string $bodyText): string
{
    $oneLine = preg_replace('/\s+/u', ' ', trim($bodyText)) ?? trim($bodyText);
    return mb_substr($oneLine, 0, 120);
}

function mlv_epoch_to_iso8601(int $epoch): string
{
    return (new DateTimeImmutable('@' . $epoch))->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('c');
}

// ---------------------------------------------------------------------
// GET /articles/{id}
// ---------------------------------------------------------------------

function mlv_handle_article_detail(int $id): void
{
    mlv_require_authenticated_member();

    $stmt = mlv_db()->prepare(
        "SELECT id, message_id, subject, from_name, from_addr, date_epoch, body_text, attachments_json, parse_status
         FROM articles
         WHERE id = :id AND parse_status != 'error'"
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    if ($row === false) {
        json_error('not_found', '指定された記事が見つかりません。', 404);
    }

    $attachments = json_decode((string) $row['attachments_json'], true);
    if (!is_array($attachments)) {
        $attachments = [];
    }

    json_response([
        'id' => (int) $row['id'],
        'subject' => $row['subject'],
        'from_name' => $row['from_name'],
        'from_addr' => $row['from_addr'],
        'date' => mlv_epoch_to_iso8601((int) $row['date_epoch']),
        'message_id' => $row['message_id'],
        'body_text' => $row['body_text'],
        'attachments' => $attachments,
        'parse_status' => $row['parse_status'],
    ]);
}

// ---------------------------------------------------------------------
// GET /index/status
// ---------------------------------------------------------------------

function mlv_handle_index_status(): void
{
    mlv_require_authenticated_member();
    json_response(mlv_maybe_index());
}
