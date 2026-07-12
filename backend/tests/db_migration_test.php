<?php

declare(strict_types=1);

mlv_test('db: マイグレーションで全テーブルが作成されschema_versionが記録される', function () {
    $pdo = mlv_db();

    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['articles', 'users', 'tokens', 'rate_limits', 'schema_version'] as $expected) {
        assert_true(in_array($expected, $tables, true), "table {$expected} exists");
    }

    assert_equals(1, mlv_schema_version($pdo), 'schema_versionが1になっている');
});

mlv_test('db: マイグレーションは複数回実行しても冪等である', function () {
    $pdo = mlv_db();
    mlv_migrate($pdo);
    mlv_migrate($pdo);
    assert_equals(1, mlv_schema_version($pdo), '再実行してもversionは変わらない');
});

mlv_test('db: articlesテーブルへのINSERTとSELECTが正しく動作する(日本語含む)', function () {
    $pdo = mlv_db();
    $pdo->exec('DELETE FROM articles');

    $stmt = $pdo->prepare(
        'INSERT INTO articles (id, subject, from_addr, from_name, date_epoch, body_text, attachments_json, parse_status, indexed_at)
         VALUES (:id, :subject, :from_addr, :from_name, :date_epoch, :body_text, :attachments_json, :parse_status, :indexed_at)'
    );
    $stmt->execute([
        ':id' => 1,
        ':subject' => 'テスト件名',
        ':from_addr' => 'test@example.com',
        ':from_name' => 'テスト太郎',
        ':date_epoch' => 1000,
        ':body_text' => 'テスト本文',
        ':attachments_json' => '[]',
        ':parse_status' => 'ok',
        ':indexed_at' => 1000,
    ]);

    $row = $pdo->query('SELECT * FROM articles WHERE id = 1')->fetch();
    assert_equals('テスト件名', $row['subject'], 'subjectが正しく保存・取得できる');
    assert_equals('テスト太郎', $row['from_name'], 'from_nameが正しく保存・取得できる(日本語)');
});

mlv_test('db: PRAGMA journal_mode=WAL が有効になっている', function () {
    $pdo = mlv_db();
    $mode = $pdo->query('PRAGMA journal_mode')->fetch()['journal_mode'];
    assert_equals('wal', strtolower((string) $mode), 'journal_modeがWALである');
});
