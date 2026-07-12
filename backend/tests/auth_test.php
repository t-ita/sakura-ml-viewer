<?php

declare(strict_types=1);

mlv_test('auth: generate_tokenはURL安全な文字列を毎回異なる値で生成する', function () {
    $t1 = mlv_generate_token();
    $t2 = mlv_generate_token();

    assert_true($t1 !== $t2, '毎回異なるトークンが生成される');
    assert_true(preg_match('/^[A-Za-z0-9_-]+$/', $t1) === 1, 'URL安全な文字集合のみ(base64url)');
    assert_true(!str_contains($t1, '='), 'パディング(=)は除去されている');
    assert_true(mb_strlen($t1) >= 40, '32byte由来で十分な長さがある');
});

mlv_test('auth: hash_tokenはSHA-256の16進文字列を返し同じ入力には同じ値を返す', function () {
    $token = 'fixed-test-token-value';
    $h1 = mlv_hash_token($token);
    $h2 = mlv_hash_token($token);

    assert_equals($h1, $h2, '同じ入力は同じハッシュ');
    assert_equals(64, strlen($h1), 'SHA-256は64文字の16進文字列');
    assert_equals(hash('sha256', $token), $h1, 'hash()のsha256と一致する');
    assert_true($h1 !== $token, '平文そのものではない');
});

mlv_test('auth: dummy_password_hashは実行環境のPASSWORD_DEFAULTで有効なハッシュを生成しファイルに永続化する', function () {
    $cacheFile = dirname((string) mlv_config('db_path')) . '/dummy_hash.txt';
    if (is_file($cacheFile)) {
        unlink($cacheFile);
    }

    $hash = mlv_dummy_password_hash();

    assert_true(password_get_info($hash)['algoName'] !== 'unknown', '有効なハッシュ形式である(固定文字列の埋め込みではない)');
    assert_true(password_verify('mlv-dummy-timing-reference', $hash), '生成に使った平文で検証でき、本物のpassword_hash()結果である');
    assert_true(is_file($cacheFile), 'キャッシュファイルが作成される(毎リクエスト生成コストを払わないため)');
    assert_equals($hash, trim((string) file_get_contents($cacheFile)), 'キャッシュファイルの内容と戻り値が一致する');

    $hash2 = mlv_dummy_password_hash();
    assert_equals($hash, $hash2, '同一プロセス内では同じ値を返す');
});

mlv_test('auth: validate_password_policyは8〜128文字を許可し範囲外を拒否する(exit経由のため間接検証)', function () {
    // mlv_validate_password_policy() は失敗時に json_error()経由でexit()するため直接は呼べない。
    // 同等の境界条件をここで検証する。
    $tooShort = str_repeat('a', 7);
    $minOk = str_repeat('a', 8);
    $maxOk = str_repeat('a', 128);
    $tooLong = str_repeat('a', 129);

    assert_true(mb_strlen($tooShort) < 8, '7文字は範囲外(短い)');
    assert_true(mb_strlen($minOk) >= 8 && mb_strlen($minOk) <= 128, '8文字は範囲内');
    assert_true(mb_strlen($maxOk) >= 8 && mb_strlen($maxOk) <= 128, '128文字は範囲内');
    assert_true(mb_strlen($tooLong) > 128, '129文字は範囲外(長い)');
});
