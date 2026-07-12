<?php

declare(strict_types=1);

/**
 * PDO(SQLite)接続を返す（プロセス内シングルトン）。初回呼び出し時にマイグレーションを適用する。
 */
function mlv_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dbPath = mlv_config('db_path');
    $dataDir = dirname($dbPath);
    if (!is_dir($dataDir)) {
        @mkdir($dataDir, 0700, true);
    }
    $isNewFile = !is_file($dbPath);

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    if ($isNewFile) {
        @chmod($dbPath, 0600);
    }

    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    mlv_migrate($pdo);

    return $pdo;
}

/**
 * バージョン番号 => そのバージョンで実行するDDL文の配列。
 * 新しいカラム追加が必要になったら、次の番号でALTER TABLE等を追記するだけでよい
 * （次回リクエスト時に自動適用される）。
 *
 * @return array<int,list<string>>
 */
function mlv_migrations(): array
{
    return [
        1 => [
            "CREATE TABLE IF NOT EXISTS articles (
                id INTEGER PRIMARY KEY,
                message_id TEXT,
                subject TEXT NOT NULL DEFAULT '',
                from_addr TEXT NOT NULL DEFAULT '',
                from_name TEXT NOT NULL DEFAULT '',
                date_epoch INTEGER NOT NULL DEFAULT 0,
                body_text TEXT NOT NULL DEFAULT '',
                attachments_json TEXT NOT NULL DEFAULT '[]',
                parse_status TEXT NOT NULL DEFAULT 'ok',
                indexed_at INTEGER NOT NULL
            )",
            "CREATE INDEX IF NOT EXISTS idx_articles_date ON articles(date_epoch DESC)",
            "CREATE INDEX IF NOT EXISTS idx_articles_from ON articles(from_addr)",
            "CREATE TABLE IF NOT EXISTS users (
                email TEXT PRIMARY KEY,
                password_hash TEXT,
                created_at INTEGER NOT NULL,
                password_updated_at INTEGER,
                last_login_at INTEGER
            )",
            "CREATE TABLE IF NOT EXISTS tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL,
                token_hash TEXT NOT NULL UNIQUE,
                purpose TEXT NOT NULL DEFAULT 'set_password',
                expires_at INTEGER NOT NULL,
                used_at INTEGER,
                created_at INTEGER NOT NULL
            )",
            "CREATE INDEX IF NOT EXISTS idx_tokens_email ON tokens(email)",
            "CREATE TABLE IF NOT EXISTS rate_limits (
                bucket TEXT PRIMARY KEY,
                window_start INTEGER NOT NULL,
                count INTEGER NOT NULL
            )",
        ],
    ];
}

function mlv_migrate(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_version (version INTEGER NOT NULL)');

    $current = mlv_schema_version($pdo);
    $migrations = mlv_migrations();
    ksort($migrations);

    $hasPending = false;
    foreach ($migrations as $version => $_) {
        if ($version > $current) {
            $hasPending = true;
            break;
        }
    }
    if (!$hasPending) {
        return;
    }

    $locksDir = mlv_config('locks_dir');
    if (!is_dir($locksDir)) {
        @mkdir($locksDir, 0700, true);
    }
    $fp = fopen($locksDir . '/migrate.lock', 'c');
    if ($fp === false) {
        throw new RuntimeException('Cannot open migration lock file');
    }

    try {
        flock($fp, LOCK_EX); // 他プロセスの適用完了を待つ（ブロッキング）

        // ロック取得後に再読取し、待っている間に他プロセスが適用済みなら何もしない
        $current = mlv_schema_version($pdo);

        foreach ($migrations as $version => $statements) {
            if ($version <= $current) {
                continue;
            }
            $pdo->beginTransaction();
            try {
                foreach ($statements as $sql) {
                    $pdo->exec($sql);
                }
                mlv_set_schema_version($pdo, $version);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function mlv_schema_version(PDO $pdo): int
{
    $row = $pdo->query('SELECT version FROM schema_version LIMIT 1')->fetch();
    return $row ? (int) $row['version'] : 0;
}

function mlv_set_schema_version(PDO $pdo, int $version): void
{
    $exists = $pdo->query('SELECT COUNT(*) AS c FROM schema_version')->fetch();
    if ($exists && (int) $exists['c'] > 0) {
        $stmt = $pdo->prepare('UPDATE schema_version SET version = :v');
    } else {
        $stmt = $pdo->prepare('INSERT INTO schema_version (version) VALUES (:v)');
    }
    $stmt->execute([':v' => $version]);
}
