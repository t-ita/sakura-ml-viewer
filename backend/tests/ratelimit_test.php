<?php

declare(strict_types=1);

mlv_test('ratelimit: 制限回数内は許可され、超過すると拒否される', function () {
    $bucket = 'test:ratelimit:' . bin2hex(random_bytes(4));

    for ($i = 0; $i < 3; $i++) {
        assert_true(mlv_rate_limit_hit($bucket, 3, 900), 'attempt ' . ($i + 1) . ' should be allowed');
    }

    assert_true(mlv_rate_limit_hit($bucket, 3, 900) === false, '4回目は制限に引っかかる');
});

mlv_test('ratelimit: resetすると再度カウントが許可される', function () {
    $limitName = 'login_email';
    $email = 'reset-test-' . bin2hex(random_bytes(4)) . '@example.com';
    $bucket = mlv_rate_limit_bucket_prefix($limitName) . ':' . $email;

    for ($i = 0; $i < 5; $i++) {
        assert_true(mlv_rate_limit_hit($bucket, 5, 900), 'attempt ' . ($i + 1));
    }
    assert_true(mlv_rate_limit_hit($bucket, 5, 900) === false, '6回目は拒否される');

    mlv_rate_limit_reset($limitName, $email);

    assert_true(mlv_rate_limit_hit($bucket, 5, 900), 'reset後は再度許可される');
});

mlv_test('ratelimit: ウィンドウ経過後はカウントがリセットされる', function () {
    $bucket = 'test:ratelimit-window:' . bin2hex(random_bytes(4));
    // window_s=0 なら次の呼び出し時点で「経過時間 >= ウィンドウ幅」が常に真になり、
    // ウィンドウが即座にリセットされて許可され続けることを確認する。
    assert_true(mlv_rate_limit_hit($bucket, 1, 0), '1回目は許可');
    assert_true(mlv_rate_limit_hit($bucket, 1, 0), 'window_s=0なら即リセットされ2回目も許可される');
});

mlv_test('ratelimit: bucket_prefixが未知のlimitNameで例外を投げる', function () {
    $threw = false;
    try {
        mlv_rate_limit_bucket_prefix('unknown_limit_name');
    } catch (RuntimeException $e) {
        $threw = true;
    }
    assert_true($threw, '未知のlimitNameはRuntimeExceptionになる');
});
