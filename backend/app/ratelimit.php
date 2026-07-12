<?php

declare(strict_types=1);

/**
 * DESIGN.md §5.6のバケット名プレフィックスに対応させる。
 */
function mlv_rate_limit_bucket_prefix(string $limitName): string
{
    return match ($limitName) {
        'login_email' => 'login:e',
        'login_ip' => 'login:ip',
        'token_email' => 'token:e',
        'token_ip' => 'token:ip',
        'setpw_ip' => 'setpw:ip',
        default => throw new RuntimeException("Unknown rate limit: {$limitName}"),
    };
}

/**
 * レート制限を適用する。超過していれば 429 を返して終了する。
 */
function mlv_enforce_rate_limit(string $limitName, string $discriminator): void
{
    $cfg = mlv_config('rate_limits');
    if (!isset($cfg[$limitName])) {
        throw new RuntimeException("Unknown rate limit: {$limitName}");
    }
    $bucket = mlv_rate_limit_bucket_prefix($limitName) . ':' . $discriminator;
    $allowed = mlv_rate_limit_hit($bucket, (int) $cfg[$limitName]['limit'], (int) $cfg[$limitName]['window_s']);
    if (!$allowed) {
        json_error('rate_limited', '試行回数が多すぎます。しばらくしてから再度お試しください。', 429);
    }
}

/**
 * 成功時にバケットをリセットする（例: ログイン成功時に失敗カウントを消す）。
 */
function mlv_rate_limit_reset(string $limitName, string $discriminator): void
{
    $bucket = mlv_rate_limit_bucket_prefix($limitName) . ':' . $discriminator;
    $stmt = mlv_db()->prepare('DELETE FROM rate_limits WHERE bucket = :b');
    $stmt->execute([':b' => $bucket]);
}

/**
 * 固定ウィンドウ方式でカウントし、許可可否を返す。SQLiteの書き込み直列化を前提に
 * BEGIN IMMEDIATE で読み取り→更新の競合を防ぐ。
 */
function mlv_rate_limit_hit(string $bucket, int $limit, int $windowSeconds): bool
{
    mlv_rate_limit_maybe_cleanup();

    $pdo = mlv_db();
    $now = time();

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $stmt = $pdo->prepare('SELECT window_start, count FROM rate_limits WHERE bucket = :b');
        $stmt->execute([':b' => $bucket]);
        $row = $stmt->fetch();

        if ($row === false) {
            $ins = $pdo->prepare('INSERT INTO rate_limits (bucket, window_start, count) VALUES (:b, :w, 1)');
            $ins->execute([':b' => $bucket, ':w' => $now]);
            $pdo->exec('COMMIT');
            return true;
        }

        $windowStart = (int) $row['window_start'];
        $count = (int) $row['count'];

        if ($now - $windowStart >= $windowSeconds) {
            $upd = $pdo->prepare('UPDATE rate_limits SET window_start = :w, count = 1 WHERE bucket = :b');
            $upd->execute([':w' => $now, ':b' => $bucket]);
            $pdo->exec('COMMIT');
            return true;
        }

        if ($count >= $limit) {
            $pdo->exec('COMMIT');
            return false;
        }

        $upd = $pdo->prepare('UPDATE rate_limits SET count = count + 1 WHERE bucket = :b');
        $upd->execute([':b' => $bucket]);
        $pdo->exec('COMMIT');
        return true;
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

function mlv_rate_limit_maybe_cleanup(): void
{
    if (random_int(1, 100) !== 1) {
        return;
    }
    $threshold = time() - 86400;
    $stmt = mlv_db()->prepare('DELETE FROM rate_limits WHERE window_start < :t');
    $stmt->execute([':t' => $threshold]);
}

function mlv_client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return is_string($ip) ? $ip : '0.0.0.0';
}
