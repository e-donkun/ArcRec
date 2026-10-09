<?php
// 管理画面の共通部分(認証・ヘッダ・フラッシュメッセージ)。
require_once dirname(__DIR__) . '/src/bootstrap.php';

use Lite\Auth;
use Lite\Competitions;
use Lite\Settings;

Auth::start();
Competitions::bootstrap();   // 旧データの移行・サンプル大会の用意(1リクエストにつき1回)

/**
 * ログイン必須のページの冒頭で呼ぶ。パスワードを通した大会が選ばれていなければ、大会の一覧(index.php)へ。
 * 通っていれば、その大会の DB を使うようにする。
 */
function require_admin()
{
    $code = Auth::current();
    if (!$code || Competitions::open($code) === false) {
        header('Location: index.php?next=' . rawurlencode(basename($_SERVER['SCRIPT_NAME'])));
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Auth::verifyCsrf();
    }
}

/**
 * ログイン不要の公開ページ(結果)で、どの大会かを決めて DB を開く。
 * ?c=大会コード があればその大会、なければ管理中の大会、それも無ければいちばん新しい大会。
 */
function public_comp()
{
    $code = $_GET['c'] ?? Auth::current() ?? Competitions::latest();
    if (!$code || Competitions::open($code) === false) {
        http_response_code(404);
        exit('大会が見つかりません');
    }
    return Competitions::find($code)['code'];
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

/** タブレットが接続するときの URL(…/ianseo-lite/)。いま開いている URL から作る(設定は不要) */
function server_base_url()
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
    return ($https ? 'https' : 'http') . '://' . $host . $dir . '/';
}

function page_head($title, $active = '', $public = false)
{
    $nav = [
        'home' => ['home.php', 'ホーム'],
        'setup' => ['setup.php', '競技設定'],
        'entries' => ['entries.php', '選手登録'],
        'targets' => ['targets.php', '的番号'],
        'scores' => ['scores.php', '入力状況'],
        'results' => ['results.php', '結果'],
        'qr' => ['qr.php', '端末接続'],
    ];
    $inComp = Lite\Db::hasSelection();
    $name = $inComp ? (Settings::get('name') ?: '(大会名未設定)') : '';
    $code = $inComp ? Settings::code() : '';
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . h($title) . ' - ianseo-lite</title>'
        . '<link rel="stylesheet" href="style.css"></head><body>';
    echo '<header class="top"><div class="brand">ianseo-lite' . ($inComp ? ' <span>' . h($name) . ' <code>' . h($code) . '</code></span>' : '') . '</div><nav>';
    $admin = Auth::check();
    if ($inComp && ($admin || $public)) {
        foreach ($nav as $k => $v) {
            if (!$admin && $k !== 'results') {
                continue;
            }
            // 結果は大会コードつきのリンクにして、公開用にそのまま使えるようにする
            $href = $k === 'results' ? $v[0] . '?c=' . rawurlencode($code) : $v[0];
            echo '<a href="' . $href . '"' . ($k === $active ? ' class="on"' : '') . '>' . $v[1] . '</a>';
        }
    }
    if ($admin) {
        echo '<a href="index.php"' . ($active === 'comps' ? ' class="on"' : '') . '>大会切替</a><a href="logout.php">ログアウト</a>';
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
