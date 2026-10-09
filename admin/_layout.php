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
 * ログイン不要の公開ページ(結果・結果PDF/CSV)で、どの大会かを決めて DB を開く。
 * ?c=大会コード があればその大会、なければ管理中の大会。
 * 大会設定で「結果を公開」にしている大会だけ見せる(その大会を開いている管理者は、非公開でも見られる)。
 */
function public_comp()
{
    $code = $_GET['c'] ?? Auth::current();
    if (!$code || Competitions::open($code) === false) {
        http_response_code(404);
        exit('大会が見つかりません');
    }
    $code = Competitions::find($code)['code'];
    if (!Settings::resultsPublic() && !Auth::isCurrent($code)) {
        http_response_code(403);
        exit('この大会の結果は公開されていません');
    }
    return $code;
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
    // admin/ の中のページなら1つ上、トップの公開ページ(results.php)ならそのフォルダ
    $dir = dirname($_SERVER['SCRIPT_NAME']);
    if (basename($dir) === 'admin') {
        $dir = dirname($dir);
    }
    $dir = rtrim($dir, '/\\');
    return ($https ? 'https' : 'http') . '://' . $host . $dir . '/';
}

/** JSON を返して終わる(画面の Ajax 用) */
function json_out($data)
{
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** アイコン(SVG)。スマホモードと同じもの + ianseo-lite で使うもの */
function icon($name)
{
    $paths = [
        'home' => '<path d="M12 3l9 8h-3v9h-5v-6h-2v6H6v-9H3z"/>',
        'users' => '<path d="M16 11a4 4 0 1 0-4-4 4 4 0 0 0 4 4zM8 11a3 3 0 1 0-3-3 3 3 0 0 0 3 3zm0 2c-2.7 0-8 1.3-8 4v3h8v-3c0-1.1.5-2.1 1.4-2.9A12 12 0 0 0 8 13zm8 0c-2.7 0-8 1.3-8 4v3h16v-3c0-2.7-5.3-4-8-4z"/>',
        'target' => '<path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm0 18a8 8 0 1 1 8-8 8 8 0 0 1-8 8zm0-14a6 6 0 1 0 6 6 6 6 0 0 0-6-6zm0 10a4 4 0 1 1 4-4 4 4 0 0 1-4 4zm0-6a2 2 0 1 0 2 2 2 2 0 0 0-2-2z"/>',
        'edit' => '<path d="M3 17.3V21h3.8l11-11-3.8-3.8zM20.7 7a1 1 0 0 0 0-1.4l-2.3-2.3a1 1 0 0 0-1.4 0l-1.8 1.8 3.8 3.8z"/>',
        'chart' => '<path d="M4 20h16v2H4zM5 10h3v8H5zm5-6h3v14h-3zm5 3h3v11h-3z"/>',
        'reload' => '<path d="M17.6 6.4A8 8 0 1 0 20 12h-2a6 6 0 1 1-1.8-4.2L13 11h7V4z"/>',
        'print' => '<path d="M6 2h12v5H6zM4 8h16a2 2 0 0 1 2 2v7h-4v5H6v-5H2v-7a2 2 0 0 1 2-2zm4 9v3h8v-3zm10-6a1 1 0 1 0 1 1 1 1 0 0 0-1-1z"/>',
        'search' => '<path d="M10 2a8 8 0 0 1 6.3 12.9l5.4 5.4-1.4 1.4-5.4-5.4A8 8 0 1 1 10 2zm0 2a6 6 0 1 0 6 6 6 6 0 0 0-6-6z"/>',
        'gear' => '<path d="M19.4 13a7.5 7.5 0 0 0 0-2l2.1-1.6-2-3.5-2.5 1a7.6 7.6 0 0 0-1.7-1L15 3h-4l-.4 2.7a7.6 7.6 0 0 0-1.7 1l-2.5-1-2 3.5L6.5 11a7.5 7.5 0 0 0 0 2l-2.1 1.6 2 3.5 2.5-1a7.6 7.6 0 0 0 1.7 1L11 21h4l.4-2.7a7.6 7.6 0 0 0 1.7-1l2.5 1 2-3.5zM13 15.5A3.5 3.5 0 1 1 16.5 12 3.5 3.5 0 0 1 13 15.5z"/>',
        'qr' => '<path d="M3 3h8v8H3zm2 2v4h4V5zm8-2h8v8h-8zm2 2v4h4V5zM3 13h8v8H3zm2 2v4h4v-4zm8-2h3v3h-3zm3 3h3v3h-3zm3-3h2v3h-2zm-6 6h3v2h-3zm5 0h3v2h-3z"/>',
        'swap' => '<path d="M7 7h11l-3-3 1.4-1.4L22 8l-5.6 5.4L15 12l3-3H7zm10 10H6l3 3-1.4 1.4L2 16l5.6-5.4L9 12l-3 3h11z"/>',
        'logout' => '<path d="M10 3h9a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-9v-2h9V5h-9zm-1 5l1.4 1.4L8.8 11H16v2H8.8l1.6 1.6L9 16l-4-4z"/>',
        'list' => '<path d="M4 5h2v2H4zm4 0h12v2H8zM4 11h2v2H4zm4 0h12v2H8zm-4 6h2v2H4zm4 0h12v2H8z"/>',
        'plus' => '<path d="M11 4h2v7h7v2h-7v7h-2v-7H4v-2h7z"/>',
    ];
    return '<svg class="sp-icon" viewBox="0 0 24 24" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

/** 下部タブ(スマホモードと同じ並び方。大会設定・端末接続などはホームのメニュー、大会の切り替えは大会の選択画面) */
function tabs()
{
    return [
        'home' => ['home.php', 'ホーム', 'home'],
        'entries' => ['entries.php', '選手', 'users'],
        'targets' => ['targets.php', '立順', 'target'],
        'scores' => ['scores.php', '入力', 'edit'],
        'results' => ['results.php', '結果', 'chart'],
    ];
}

/**
 * 画面の上部(スマホモードと同じ: 上部バーに大会名と画面名)。
 * $opts: 'public' => 参加者向けの公開ページ(管理用のリンク・タブを出さない), 'logout' => 右上にログアウトボタン(ホームだけ), 'base' => style.css などの場所(トップのページから使うとき 'admin/'),
 *        'wide' => 表の多い画面で横幅を広げる, 'js' => JavaScript に渡す変数, 'body' => body の class
 */
function page_head($title, $tab = '', array $opts = [])
{
    $inComp = Lite\Db::hasSelection();
    $admin = Auth::check() && $inComp && empty($opts['public']);
    $GLOBALS['LITE_PUBLIC_PAGE'] = !empty($opts['public']);
    $name = $inComp ? (Settings::get('name') ?: Settings::code()) : 'ianseo-lite';
    $js = array_merge(['LITE_CSRF' => Auth::csrf()], $opts['js'] ?? []);
    $base = $opts['base'] ?? '';
    $ver = '?v=' . filemtime(__DIR__ . '/style.css') . '-' . filemtime(__DIR__ . '/app.js');
    echo '<!DOCTYPE html><html lang="ja" translate="no"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
        . '<meta name="theme-color" content="#1e4d8c"><title>' . h($title) . ' - ianseo-lite</title>'
        . '<link rel="stylesheet" href="' . $base . 'style.css' . $ver . '"><script>';
    foreach ($js as $k => $v) {
        echo 'var ' . $k . '=' . json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) . ';';
    }
    echo '</script><script src="' . $base . 'app.js' . $ver . '"></script></head>'
        . '<body class="' . h($opts['body'] ?? '') . '"><header class="sp-appbar"><div class="sp-appbar-title">';
    // 大会名は表示だけ(ここから大会は切り替えない。切り替えはログアウトして大会の選択で)
    echo '<span class="sp-tour">' . h($name) . '</span>';
    echo '<h1>' . h($title) . '</h1></div>';
    if ($admin && !empty($opts['logout'])) {
        echo '<a class="sp-appbar-btn" href="logout.php" title="この大会を閉じて、大会の選択に戻る">' . icon('logout') . '<span>ログアウト</span></a>';
    }
    echo '</header><main class="sp-main' . (!empty($opts['wide']) ? ' wide' : '') . '">';
    foreach ((array)flash() as $f) {
        echo '<div class="sp-flash ' . h($f[0]) . '">' . h($f[1]) . '</div>';
    }
}

/** 画面の下部(下部タブ。大会を開いている管理者だけ) */
function page_foot($tab = '')
{
    echo '</main>';
    if (Auth::check() && Lite\Db::hasSelection() && empty($GLOBALS['LITE_PUBLIC_PAGE'])) {
        echo '<nav class="sp-tabbar">';
        foreach (tabs() as $k => $t) {
            echo '<a href="' . $t[0] . '" class="' . ($k === $tab ? 'active' : '') . '">' . icon($t[2]) . '<span>' . $t[1] . '</span></a>';
        }
        echo '</nav>';
    }
    echo '<div id="sp-toast" class="sp-toast" role="status"></div></body></html>';
}

/**
 * 大会の一覧(スマホモードの「試合の選択」と同じカード)。タップすると、パスワードを入れて開くシートが出る。
 * 切り替えるときは毎回パスワードを入れる(前に開いた大会のパスワードは覚えない)。
 */
function render_competitions($next = 'home.php')
{
    $list = Competitions::all();
    echo '<div class="sp-list" id="CompList">';
    foreach ($list as $c) {
        $cur = Auth::isCurrent($c['code']);
        echo '<div class="sp-card tap sp-tour-item' . ($cur ? ' current' : '') . '" data-code="' . h($c['code']) . '" data-name="' . h($c['name'] ?: '(大会名未設定)') . '"'
            . ($c['sample'] ? ' data-hint="' . h(Competitions::SAMPLE_PASSWORD) . '"' : '') . ($cur ? ' data-current="1"' : '') . '>'
            . '<div class="sp-name">' . h($c['name'] ?: '(大会名未設定)')
            . ($cur ? '<span class="sp-tag pri">開いている大会</span>' : '') . ($c['sample'] ? '<span class="sp-tag">サンプル</span>' : '') . '</div>'
            . '<div class="sp-sub">' . h(implode(' / ', array_filter([$c['code'], $c['preset_label'], $c['entries'] . '人',
                $c['last_score'] ? '最終更新 ' . substr($c['last_score'], 0, 16) : '']))) . '</div></div>';
    }
    if (!$list) {
        echo '<div class="sp-empty">大会がありません</div>';
    }
    echo '</div>';
    $presets = [];
    foreach (Settings::PRESETS as $k => $p) {
        $presets[] = [$k, $p['label']];
    }
    $form = function ($inner) use ($next) {
        return '<form method="post" action="index.php?next=' . h(rawurlencode($next)) . '">' . csrf_field() . $inner . '</form>';
    };
    // シートの中身(JavaScript で開く)
    echo '<template id="CompOpenTpl">' . $form('<input type="hidden" name="do" value="open"><input type="hidden" name="code">'
        . '<h2 data-role="title"></h2><div class="sp-sub" data-role="sub"></div>'
        . '<label class="sp-field"><span>この大会のパスワード</span><input type="password" name="password" required autocomplete="current-password"></label>'
        . '<div class="sp-sheet-actions"><button type="button" class="sp-btn sub" onclick="spSheet.close()">やめる</button><button class="sp-btn">開く</button></div>') . '</template>';
    if (Auth::canCreate()) {
        $opts = '';
        foreach ($presets as $p) {
            $opts .= '<option value="' . $p[0] . '"' . ($p[0] === Settings::DEFAULTS['preset'] ? ' selected' : '') . '>' . h($p[1]) . '</option>';
        }
        echo '<p><button type="button" class="sp-btn sub block" id="CompNewBtn">' . icon('plus') . '新しい大会を作る</button></p>';
    } else {
        echo '<p class="sp-note">新しい大会は、どれかの大会をパスワードで開いたあと、この画面から作れます。</p>';
    }
    if (Auth::canCreate()) {
        echo '<template id="CompNewTpl">' . $form('<input type="hidden" name="do" value="create"><h2>新しい大会</h2>'
            . '<div class="sp-sub">大会コード(8桁の16進数)は自動で付きます。パスワードはこの大会だけのもので、大会の DB に保存します。</div>'
            . '<label class="sp-field"><span>大会名</span><input type="text" name="name"></label>'
            . '<label class="sp-field"><span>競技形式</span><select name="preset">' . $opts . '</select></label>'
            . '<div class="sp-row"><label class="sp-field"><span>パスワード</span><input type="password" name="password" required minlength="4" autocomplete="new-password"></label>'
            . '<label class="sp-field"><span>パスワード(確認)</span><input type="password" name="password2" required minlength="4" autocomplete="new-password"></label></div>'
            . '<div class="sp-sheet-actions"><button type="button" class="sp-btn sub" onclick="spSheet.close()">やめる</button><button class="sp-btn">作成</button></div>') . '</template>';
    }
    echo '<script>(function(){'
        . 'document.getElementById("CompList").addEventListener("click",function(ev){var c=ev.target.closest("[data-code]");if(!c)return;'
        . 'if(c.dataset.current){location.href="home.php";return}'
        . 'var el=spSheet.open(document.getElementById("CompOpenTpl").innerHTML);'
        . 'el.querySelector("[name=code]").value=c.dataset.code;el.querySelector("[data-role=title]").textContent=c.dataset.name;'
        . 'el.querySelector("[data-role=sub]").textContent=c.dataset.code+(c.dataset.hint?"(サンプル: パスワードは "+c.dataset.hint+")":"");'
        . 'setTimeout(function(){el.querySelector("[name=password]").focus()},250)});'
        . 'var nb=document.getElementById("CompNewBtn");if(nb)nb.onclick=function(){spSheet.open(document.getElementById("CompNewTpl").innerHTML)};'
        . '})()</script>';
}
