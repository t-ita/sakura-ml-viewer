<?php

declare(strict_types=1);

/**
 * リクエストパスを解決し、対応するハンドラへディスパッチする。
 * 通常は .htaccess の mod_rewrite で REQUEST_URI がそのまま渡ってくる想定だが、
 * rewriteが使えない環境向けに ?route=/articles 形式のフォールバックにも対応する。
 */

/**
 * @return array{0:string,1:string} [HTTPメソッド, ルートパス]
 */
function mlv_current_route(): array
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if (isset($_GET['route']) && is_string($_GET['route'])) {
        $path = '/' . trim($_GET['route'], '/');
        return [$method, $path === '/' ? '/' : rtrim($path, '/')];
    }

    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH);
    $path = is_string($path) ? $path : '/';

    // フロントコントローラ(index.php)のディレクトリを基準にAPIのベースパスを除去する
    $scriptDir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    if ($scriptDir !== '' && str_starts_with($path, $scriptDir)) {
        $path = substr($path, strlen($scriptDir));
    }

    $path = '/' . ltrim($path, '/');
    $path = $path === '/' ? '/' : rtrim($path, '/');

    return [$method, $path];
}

function mlv_dispatch(): void
{
    [$method, $path] = mlv_current_route();

    if ($method === 'GET' && preg_match('#^/articles/([0-9]{1,9})$#', $path, $m) === 1) {
        mlv_handle_article_detail((int) $m[1]);
        return;
    }

    $routes = [
        'GET /auth/session' => 'mlv_handle_auth_session',
        'POST /auth/login' => 'mlv_handle_login',
        'POST /auth/logout' => 'mlv_handle_logout',
        'POST /auth/request-token' => 'mlv_handle_request_token',
        'POST /auth/set-password' => 'mlv_handle_set_password',
        'POST /auth/change-password' => 'mlv_handle_change_password',
        'GET /articles' => 'mlv_handle_articles_list',
        'GET /index/status' => 'mlv_handle_index_status',
        'GET /admin/users' => 'mlv_handle_admin_users',
        'GET /admin/articles' => 'mlv_handle_admin_articles',
    ];

    $key = $method . ' ' . $path;
    if (isset($routes[$key])) {
        $routes[$key]();
        return;
    }

    json_error('not_found', '指定されたエンドポイントが見つかりません。', 404);
}
