<?php

declare(strict_types=1);

/**
 * このファイルを config.php としてコピーし、環境に合わせて値を書き換える。
 * config.php は Git 管理・公開領域へのアップロード対象外とすること。
 */
return [
    // fml 側データ（読み取り専用）
    'ml_name'      => 'MLNAME',
    'spool_dir'    => '/home/ACCOUNT/fml/spool/ml/MLNAME/spool',
    'actives_file' => '/home/ACCOUNT/fml/spool/ml/MLNAME/actives',
    'seq_file'     => '/home/ACCOUNT/fml/spool/ml/MLNAME/seq',

    // アプリ側データ（ドキュメントルート外）
    'db_path'      => '/home/ACCOUNT/mlviewer-data/mlviewer.sqlite',
    'session_path' => '/home/ACCOUNT/mlviewer-data/sessions',
    'locks_dir'    => '/home/ACCOUNT/mlviewer-data/locks',
    'log_file'     => '/home/ACCOUNT/mlviewer-data/app.log',

    // Web公開URL（パスワード設定リンクの生成に使用）
    'base_url' => 'https://example.sakura.ne.jp/ml-viewer',

    // メール送信
    'mail_from'      => 'ml-viewer@example.com',
    'mail_from_name' => 'ML Viewer',

    // セッション
    'session_cookie_name' => 'MLVSESS',
    'session_lifetime'    => 28800, // 8時間

    // インデクサー
    'index_batch_size'    => 50,
    'index_time_budget_s' => 5,

    // トークン
    'token_ttl_s' => 86400, // 24時間

    // レート制限
    'rate_limits' => [
        'login_email'   => ['limit' => 5,  'window_s' => 900],
        'login_ip'      => ['limit' => 20, 'window_s' => 900],
        'token_email'   => ['limit' => 3,  'window_s' => 3600],
        'token_ip'      => ['limit' => 10, 'window_s' => 3600],
        'setpw_ip'      => ['limit' => 10, 'window_s' => 900],
    ],
];
