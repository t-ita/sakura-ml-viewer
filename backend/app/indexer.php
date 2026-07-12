<?php

declare(strict_types=1);

/**
 * fmlスプールの遅延増分インデクサー。CRONが使えないため、GET /articles 等のAPIリクエストに
 * 便乗してインデックスを1バッチ分だけ前進させる。flock(LOCK_NB)により多重実行を防ぐ。
 */

/**
 * インデックスを可能な範囲で前進させ、進捗情報を返す。
 * 他プロセスが処理中、またはseqファイルが読めない場合は前進させずに現状を返す。
 *
 * @return array{indexed_max:int,seq:int,pending:int}
 */
function mlv_maybe_index(): array
{
    $pdo = mlv_db();
    $max = mlv_current_max_article_id($pdo);
    $seq = mlv_read_seq();

    if ($seq === null) {
        return ['indexed_max' => $max, 'seq' => $max, 'pending' => 0];
    }
    if ($seq <= $max) {
        return ['indexed_max' => $max, 'seq' => $seq, 'pending' => 0];
    }

    $locksDir = mlv_config('locks_dir');
    if (!is_dir($locksDir)) {
        @mkdir($locksDir, 0700, true);
    }
    $fp = @fopen($locksDir . '/indexer.lock', 'c');

    if ($fp !== false) {
        if (flock($fp, LOCK_EX | LOCK_NB)) {
            try {
                mlv_run_index_batch($pdo, $max, $seq);
            } finally {
                flock($fp, LOCK_UN);
                fclose($fp);
            }
        } else {
            // 他プロセスが処理中。今回は現状データで応答する。
            fclose($fp);
        }
    }

    $max = mlv_current_max_article_id($pdo);
    return ['indexed_max' => $max, 'seq' => $seq, 'pending' => max(0, $seq - $max)];
}

function mlv_read_seq(): ?int
{
    $raw = @file_get_contents(mlv_config('seq_file'));
    if ($raw === false) {
        return null;
    }
    $seq = (int) trim($raw);
    return $seq > 0 ? $seq : null;
}

function mlv_current_max_article_id(PDO $pdo): int
{
    $row = $pdo->query('SELECT COALESCE(MAX(id), 0) AS m FROM articles')->fetch();
    return (int) $row['m'];
}

/**
 * 未取込の記事番号を、件数上限・時間上限のいずれかに達するまで取り込む。
 * 欠番（ファイルが存在しない）も parse_status='error' の行で埋め、次回以降の
 * 増分判定を「seq - MAX(id)」だけで完結させる。
 */
function mlv_run_index_batch(PDO $pdo, int $max, int $seq): void
{
    $spoolDir = mlv_config('spool_dir');
    $batchSize = (int) mlv_config('index_batch_size');
    $timeBudget = (int) mlv_config('index_time_budget_s');
    $deadline = time() + $timeBudget;
    $now = time();

    $insert = $pdo->prepare(
        'INSERT OR IGNORE INTO articles
            (id, message_id, subject, from_addr, from_name, date_epoch, body_text, attachments_json, parse_status, indexed_at)
         VALUES (:id, :message_id, :subject, :from_addr, :from_name, :date_epoch, :body_text, :attachments_json, :parse_status, :indexed_at)'
    );

    $processed = 0;
    $pdo->beginTransaction();

    try {
        for ($n = $max + 1; $n <= $seq; $n++) {
            if ($processed >= $batchSize || time() >= $deadline) {
                break;
            }

            $path = $spoolDir . '/' . $n;
            $parsed = is_file($path) ? mlv_parse_mail_file($path) : mlv_error_result();

            $insert->execute([
                ':id' => $n,
                ':message_id' => $parsed['message_id'],
                ':subject' => $parsed['subject'],
                ':from_addr' => $parsed['from_addr'],
                ':from_name' => $parsed['from_name'],
                ':date_epoch' => $parsed['date_epoch'],
                ':body_text' => $parsed['body_text'],
                ':attachments_json' => json_encode($parsed['attachments'], JSON_UNESCAPED_UNICODE),
                ':parse_status' => $parsed['parse_status'],
                ':indexed_at' => $now,
            ]);

            $processed++;
            if ($processed % 10 === 0) {
                $pdo->commit();
                $pdo->beginTransaction();
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        mlv_log('indexer: batch failed: ' . $e->getMessage());
    }
}
