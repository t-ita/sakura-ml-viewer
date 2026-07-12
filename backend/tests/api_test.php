<?php

declare(strict_types=1);

mlv_test('api: escape_likeがLIKE特殊文字を無害化する', function () {
    assert_equals('50\\%', mlv_escape_like('50%'), 'percent');
    assert_equals('a\\_b', mlv_escape_like('a_b'), 'underscore');
    assert_equals('a\\\\b', mlv_escape_like('a\\b'), 'backslash');
    assert_equals('normal', mlv_escape_like('normal'), '特殊文字なしはそのまま');
});

mlv_test('api: jst_date_to_epochがJSTの日付境界を正しくepoch化する', function () {
    $start = mlv_jst_date_to_epoch('2026-07-01', false);
    $end = mlv_jst_date_to_epoch('2026-07-01', true);

    assert_true($start !== null, '開始epochが取得できる');
    assert_true($end !== null, '終了epochが取得できる');
    assert_equals('2026-07-01T00:00:00+09:00', (new DateTimeImmutable('@' . $start))->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('c'), '開始は00:00:00 JST');
    assert_equals('2026-07-01T23:59:59+09:00', (new DateTimeImmutable('@' . $end))->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('c'), '終了は23:59:59 JST');
    assert_true($end > $start, '終了は開始より後');
});

mlv_test('api: jst_date_to_epochは不正な形式にnullを返す', function () {
    assert_true(mlv_jst_date_to_epoch('2026/07/01', false) === null, 'スラッシュ区切りは不正');
    assert_true(mlv_jst_date_to_epoch('not-a-date', false) === null, '日付でない文字列は不正');
    assert_true(mlv_jst_date_to_epoch('2026-13-40', false) === null, '存在しない日付は不正');
});

mlv_test('api: make_snippetが改行を1スペースに畳み込み120文字に丸める', function () {
    $body = "1行目\n2行目\n\n3行目";
    $snippet = mlv_make_snippet($body);
    assert_equals('1行目 2行目 3行目', $snippet, '改行はスペースに畳み込まれる');

    $long = str_repeat('あ', 200);
    $snippet2 = mlv_make_snippet($long);
    assert_equals(120, mb_strlen($snippet2), '120文字に丸められる');
});

mlv_test('api: epoch_to_iso8601がJSTのISO8601文字列を返す', function () {
    $epoch = (new DateTimeImmutable('2026-07-01T00:00:00+09:00'))->getTimestamp();
    assert_equals('2026-07-01T00:00:00+09:00', mlv_epoch_to_iso8601($epoch), 'JST ISO8601表記');
});

mlv_test('api: build_article_whereがparse_status=errorを常に除外する', function () {
    [$sql, $params] = mlv_build_article_where('', '', '', '');
    assert_str_contains($sql, "parse_status != 'error'", 'error除外条件が常に含まれる');
    assert_equals([], $params, '条件無指定時はパラメータなし');
});

mlv_test('api: build_article_whereがキーワードをAND結合し正しくバインドする', function () {
    [$sql, $params] = mlv_build_article_where('会議 資料', '', '', '');
    assert_str_contains($sql, ':q0', '1語目のプレースホルダ');
    assert_str_contains($sql, ':q1', '2語目のプレースホルダ');
    assert_equals('%会議%', $params[':q0'], '1語目の値');
    assert_equals('%資料%', $params[':q1'], '2語目の値');
});
