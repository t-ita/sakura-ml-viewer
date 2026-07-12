<?php

declare(strict_types=1);

$__fixtures = __DIR__ . '/fixtures';

mlv_test('mail_parser: ISO-2022-JP件名と本文を正しくUTF-8化する', function () use ($__fixtures) {
    $r = mlv_parse_mail_file("{$__fixtures}/iso2022jp_subject.eml");
    assert_equals('ok', $r['parse_status'], 'parse_status');
    assert_equals('会議室変更のお知らせ', $r['subject'], 'subject');
    assert_equals('山田太郎', $r['from_name'], 'from_name');
    assert_equals('yamada@example.com', $r['from_addr'], 'from_addr');
    assert_str_contains($r['body_text'], 'よろしくお願いいたします。', 'body_text');
});

mlv_test('mail_parser: multipart/alternativeはtext/plainを優先する', function () use ($__fixtures) {
    $r = mlv_parse_mail_file("{$__fixtures}/multipart_alternative.eml");
    assert_equals('ok', $r['parse_status'], 'parse_status');
    assert_equals('これはプレーンテキスト版の本文です。', trim($r['body_text']), 'body_text should be the plain part');
    assert_true(!str_contains($r['body_text'], '<html>'), 'HTMLタグが混入していない');
    assert_true(!str_contains($r['body_text'], 'HTML版'), 'HTML版の文言が含まれない(plain優先)');
});

mlv_test('mail_parser: base64本文を正しくデコードする', function () use ($__fixtures) {
    $r = mlv_parse_mail_file("{$__fixtures}/base64_body.eml");
    assert_equals('ok', $r['parse_status'], 'parse_status');
    assert_str_contains($r['body_text'], 'base64でエンコードされた本文のテストです。', 'body_text');
    assert_str_contains($r['body_text'], '複数行にわたる本文を確認します。', 'body_text line2');
});

mlv_test('mail_parser: 添付ファイルを本文に含めずメタ情報のみ記録する', function () use ($__fixtures) {
    $r = mlv_parse_mail_file("{$__fixtures}/with_attachment.eml");
    assert_equals('ok', $r['parse_status'], 'parse_status');
    assert_str_contains($r['body_text'], '資料を添付します', 'body_text');
    assert_true(!str_contains($r['body_text'], 'AAAA'), '添付の実体が本文に混入していない');
    assert_equals(1, count($r['attachments']), '添付数');
    assert_equals('報告書.pdf', $r['attachments'][0]['filename'], '添付ファイル名(encoded-wordデコード済み)');
    assert_equals('application/pdf', $r['attachments'][0]['mime'], '添付MIME種別');
    assert_equals(300, $r['attachments'][0]['size'], '添付デコード後サイズ');
});

mlv_test('mail_parser: 壊れたメール(boundary欠落)はpartialとして扱いクラッシュしない', function () use ($__fixtures) {
    $r = mlv_parse_mail_file("{$__fixtures}/broken.eml");
    assert_equals('partial', $r['parse_status'], 'parse_status');
    assert_equals('壊れたメールのテスト', $r['subject'], 'subjectは壊れていなくても取得できる');
    assert_equals('broken@example.com', $r['from_addr'], 'from_addrは取得できる');
});

mlv_test('mail_parser: 存在しないファイルはerrorを返す', function () {
    $r = mlv_parse_mail_file(__DIR__ . '/fixtures/does_not_exist.eml');
    assert_equals('error', $r['parse_status'], 'parse_status');
    assert_equals('', $r['subject'], 'subjectは空');
    assert_equals([], $r['attachments'], 'attachmentsは空配列');
});

mlv_test('mail_parser: RFC2047エンコードのない生UTF-8件名を破壊しない(回帰テスト)', function () {
    $raw = "From: eve@example.com\r\n"
        . "Subject: 会議のお知らせ\r\n"
        . "Date: Wed, 1 Jul 2026 08:00:00 +0900\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "\r\n"
        . "本文です。\r\n";
    $r = mlv_parse_mail_string($raw, 1);
    assert_equals('会議のお知らせ', $r['subject'], 'subjectは生UTF-8のまま保持される');
    assert_true(!str_contains($r['subject'], '?'), 'mb_decode_mimeheaderによる文字化けが発生していない');
});

mlv_test('mail_parser: Fromの3形式(Name<addr> / addr(comment) / bare addr)を解析できる', function () {
    $f1 = mlv_parse_from_header('"Taro Yamada" <taro@example.com>');
    assert_equals('taro@example.com', $f1['addr'], 'form1 addr');
    assert_equals('Taro Yamada', $f1['name'], 'form1 name');

    $f2 = mlv_parse_from_header('hanako@example.com (Hanako Suzuki)');
    assert_equals('hanako@example.com', $f2['addr'], 'form2 addr');
    assert_equals('Hanako Suzuki', $f2['name'], 'form2 name');

    $f3 = mlv_parse_from_header('bare@example.com');
    assert_equals('bare@example.com', $f3['addr'], 'form3 addr');
    assert_equals('', $f3['name'], 'form3 name');
});
