<?php

declare(strict_types=1);

/**
 * app/(bootstrap.php)の実際の位置は、ローカル開発時とさくらへの本番デプロイ時とで異なる。
 *
 *   ローカル(リポジトリ構成):  backend/public/api/index.php → backend/app/bootstrap.php
 *                              (このファイルから2階層上がってapp/)
 *   本番(README.md §3の配置): ~/www/ml-viewer/api/index.php → ~/mlviewer-app/bootstrap.php
 *                              (docルート外に置くため、3階層上がってmlviewer-app/)
 *
 * 単純に固定の相対パスを1つだけ書くと、どちらか一方の環境でしか動かない
 * （本番でrequireが失敗すると、このエラーハンドラーが未初期化のまま致命的エラーになり、
 * レスポンスが空のまま500を返す事故につながる）。そのため両方の候補を順に試す。
 */
$bootstrapCandidates = [
    __DIR__ . '/../../app/bootstrap.php',
    __DIR__ . '/../../../mlviewer-app/bootstrap.php',
];

$bootstrapPath = null;
foreach ($bootstrapCandidates as $candidate) {
    if (is_file($candidate)) {
        $bootstrapPath = $candidate;
        break;
    }
}

if ($bootstrapPath === null) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        ['error' => ['code' => 'server_error', 'message' => 'アプリケーション本体が見つかりません。配置場所を確認してください。']],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

require $bootstrapPath;

mlv_dispatch();
