<?php
// 管理画面の共通部分(認証・ヘッダ・フラッシュメッセージ)。
require_once dirname(__DIR__) . '/src/bootstrap.php';

use Lite\Auth;
use Lite\Settings;

Auth::start();

/** ログイン必須のページの冒頭で呼ぶ */
function require_admin()
{
    if (!Auth::check()) {
        header('Location: index.php?next=' . rawurlencode(basename($_SERVER['SCRIPT_NAME'])));
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Auth::verifyCsrf();
    }
}

function flash($msg = null, $type = 'ok')
{
    if ($msg !== null) {
        $_SESSION['flash'][] = [$type, $msg];
        return;
    }
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function redirect($to)
{
    header('Location: ' . $to);
    exit;
}

function csrf_field()
{
    return '<input type="hidden" name="_csrf" value="' . h(Auth::csrf()) . '">';
}

/** タブレットが接続するときの URL(…/ianseo-lite/)。設定が無ければアクセスされた URL から推測 */
function server_base_url()
{
    if ($u = trim((string)Settings::get('server_url'))) {
        return rtrim($u, '/') . '/';
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
    return ($https ? 'https' : 'http') . '://' . $host . $dir . '/';
}

function page_head($title, $active = '', $public = false)
{
    $nav = [
        'index' => ['./', 'ホーム'],
        'setup' => ['setup.php', '競技設定'],
        'entries' => ['entries.php', '選手'],
        'scores' => ['scores.php', '入力状況'],
        'results' => ['results.php', '結果'],
        'qr' => ['qr.php', '端末接続'],
    ];
    $name = Settings::get('name') ?: '(大会名未設定)';
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . h($title) . ' - ianseo-lite</title>'
        . '<link rel="stylesheet" href="style.css"></head><body>';
    echo '<header class="top"><div class="brand">ianseo-lite <span>' . h($name) . '</span></div><nav>';
    if (Auth::check() || $public) {
        foreach ($nav as $k => $v) {
            if (!Auth::check() && !in_array($k, ['results'], true)) {
                continue;
            }
            echo '<a href="' . $v[0] . '"' . ($k === $active ? ' class="on"' : '') . '>' . $v[1] . '</a>';
        }
    }
    if (Auth::check()) {
        echo '<a href="logout.php">ログアウト</a>';
    }
    echo '</nav></header><main>';
    foreach ((array)flash() as $f) {
        echo '<div class="flash ' . h($f[0]) . '">' . h($f[1]) . '</div>';
    }
}

function page_foot()
{
    echo '</main></body></html>';
}
