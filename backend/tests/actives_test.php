<?php

declare(strict_types=1);

mlv_test('actives: コメント・空行を無視し、オプション付き行から先頭アドレスのみを小文字で取り出す', function () {
    $GLOBALS['MLV_CONFIG']['actives_file'] = __DIR__ . '/fixtures/actives_sample';

    $actives = mlv_load_actives();
    assert_true($actives !== null, 'ファイルが読み込めること');
    assert_true(isset($actives['alice@example.com']), 'alice@example.com が含まれる');
    assert_true(isset($actives['bob@example.com']), 'BOB@Example.com が小文字化されて含まれる');
    assert_true(isset($actives['carol@example.com']), 'オプション付き(s=1 m=1)でも先頭アドレスのみ取り出す');
    assert_true(isset($actives['dave@example.com']), 'タブインデントされた行も取り出す');
    assert_equals(4, count($actives), 'コメント・空行を除いた4件のみ');
});

mlv_test('actives: ファイルが存在しない場合はnullを返す(フェイルクローズ判断は呼び出し側)', function () {
    $GLOBALS['MLV_CONFIG']['actives_file'] = __DIR__ . '/fixtures/does_not_exist_actives';
    $actives = mlv_load_actives();
    assert_true($actives === null, '読み取り不能時はnull');
});
