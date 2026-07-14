<?php

declare(strict_types=1);

mlv_test('admin: load_adminsはコメント・空行を無視し、先頭アドレスのみを小文字で取り出す', function () {
    $GLOBALS['MLV_CONFIG']['admins_file'] = __DIR__ . '/fixtures/admins_sample';

    $admins = mlv_load_admins();
    assert_true($admins !== null, 'ファイルが読み込めること');
    assert_true(isset($admins['alice@example.com']), 'alice@example.com が含まれる');
    assert_true(isset($admins['admin@example.com']), 'ADMIN@Example.com が小文字化されて含まれる');
    assert_true(isset($admins['carol@example.com']), 'オプション付き(s=1 m=1)でも先頭アドレスのみ取り出す');
    assert_true(isset($admins['dave@example.com']), 'タブインデントされた行も取り出す');
    assert_equals(4, count($admins), 'コメント・空行を除いた4件のみ');

    $GLOBALS['MLV_CONFIG']['admins_file'] = __DIR__ . '/fixtures/admins_sample';
});

mlv_test('admin: load_adminsはファイル不在時にnullを返す(activesと異なりフェイルクローズしない)', function () {
    $GLOBALS['MLV_CONFIG']['admins_file'] = __DIR__ . '/fixtures/does_not_exist_admins';
    assert_true(mlv_load_admins() === null, '読み取り不能時はnull');

    $GLOBALS['MLV_CONFIG']['admins_file'] = __DIR__ . '/fixtures/admins_sample';
});

mlv_test('admin: load_adminsはadmins_fileキー自体が未設定でもnullを返す(既存デプロイ互換)', function () {
    $saved = $GLOBALS['MLV_CONFIG']['admins_file'];
    unset($GLOBALS['MLV_CONFIG']['admins_file']);

    assert_true(mlv_load_admins() === null, 'キー未設定はnull(例外にならない)');

    $GLOBALS['MLV_CONFIG']['admins_file'] = $saved;
});

mlv_test('admin: is_adminはadmins記載かつactives在籍のときのみtrueを返す', function () {
    $GLOBALS['MLV_CONFIG']['admins_file'] = __DIR__ . '/fixtures/admins_sample';
    $GLOBALS['MLV_CONFIG']['actives_file'] = __DIR__ . '/fixtures/actives_sample';

    // alice: admins記載 かつ actives在籍(actives_sampleにも存在) → true
    assert_true(mlv_is_admin('alice@example.com') === true, 'admins記載+actives在籍はtrue');

    // dave: admins記載だがactives_sampleには存在しない想定を確認するため、
    // actives_sampleの内容(alice/bob/carol/dave)には実際にdaveも含まれるため、
    // 別途「admins記載だがactivesに無い」ケースを検証する。
    $GLOBALS['MLV_CONFIG']['actives_file'] = __DIR__ . '/fixtures/actives_no_admins_overlap';
    assert_true(mlv_is_admin('alice@example.com') === false, 'admins記載でもactives不在ならfalse');

    // 大文字入力・前後空白でも正規化されて判定されること
    $GLOBALS['MLV_CONFIG']['actives_file'] = __DIR__ . '/fixtures/actives_sample';
    assert_true(mlv_is_admin(' Alice@Example.com ') === true, '大文字・前後空白は正規化される');

    assert_true(mlv_is_admin('eve@example.com') === false, 'admins不記載はfalse');

    $GLOBALS['MLV_CONFIG']['actives_file'] = __DIR__ . '/fixtures/actives_sample';
});

mlv_test('admin: build_user_listsはactivesを正としusers行が無い会員をregistered:falseで含める', function () {
    $actives = ['alice@example.com' => true, 'bob@example.com' => true];
    $userRows = [
        ['email' => 'alice@example.com', 'password_hash' => 'hash', 'created_at' => 100, 'password_updated_at' => 200, 'last_login_at' => 300],
    ];

    $result = mlv_admin_build_user_lists($actives, $userRows, []);

    assert_equals(2, count($result['members']), 'actives全員が含まれる(2件)');
    assert_equals('alice@example.com', $result['members'][0]['email'], 'email昇順の1件目');
    assert_equals('bob@example.com', $result['members'][1]['email'], 'email昇順の2件目');
    assert_true($result['members'][0]['password_registered'] === true, 'aliceは登録済み');
    assert_true($result['members'][1]['password_registered'] === false, 'bobはusers行が無いので未登録');
    assert_true($result['members'][1]['created_at'] === null, 'users行が無い会員の日時はnull');
    assert_equals(1, $result['summary']['password_registered'], 'サマリの登録済み件数');
    assert_equals(2, $result['summary']['active_members'], 'サマリの会員数');
});

mlv_test('admin: build_user_listsはactivesに不在のusers行をorphan_usersとして検出する', function () {
    $actives = ['alice@example.com' => true];
    $userRows = [
        ['email' => 'alice@example.com', 'password_hash' => 'hash', 'created_at' => 100, 'password_updated_at' => null, 'last_login_at' => null],
        ['email' => 'left@example.com', 'password_hash' => 'hash2', 'created_at' => 50, 'password_updated_at' => null, 'last_login_at' => null],
    ];

    $result = mlv_admin_build_user_lists($actives, $userRows, []);

    assert_equals(1, count($result['orphan_users']), 'activesに無い1件がorphanとして検出される');
    assert_equals('left@example.com', $result['orphan_users'][0]['email'], 'orphanのemail');
    assert_equals(1, $result['summary']['orphan_users'], 'サマリのorphan件数');
    assert_equals(1, count($result['members']), 'membersにはorphanを含めない');
});

mlv_test('admin: build_user_listsはpending_tokenを正しく反映する', function () {
    $actives = ['alice@example.com' => true, 'bob@example.com' => true];
    $result = mlv_admin_build_user_lists($actives, [], ['alice@example.com']);

    assert_true($result['members'][0]['pending_token'] === true, 'aliceは発行中トークンあり');
    assert_true($result['members'][1]['pending_token'] === false, 'bobは発行中トークンなし');
});

mlv_test('admin: build_article_whereはstatus=allのときerror行も除外しない条件になる', function () {
    [$sql, $params] = mlv_admin_build_article_where('all');
    assert_true(!str_contains($sql, 'parse_status'), 'allではparse_statusによる絞り込みをしない(error除外もしない)');
    assert_equals([], $params, 'パラメータなし');
});

mlv_test('admin: build_article_whereはok/partial/error指定時にparse_statusで絞り込む', function () {
    foreach (['ok', 'partial', 'error'] as $status) {
        [$sql, $params] = mlv_admin_build_article_where($status);
        assert_str_contains($sql, 'parse_status = :status', "{$status}: プレースホルダを含む");
        assert_equals($status, $params[':status'], "{$status}: バインド値が一致する");
    }
});
