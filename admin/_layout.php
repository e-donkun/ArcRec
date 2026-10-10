<?php
// 管理画面の共通部分(認証・ヘッダ・フラッシュメッセージ)。
require_once dirname(__DIR__) . '/src/bootstrap.php';

use Lite\Auth;
use Lite\Competitions;
use Lite\Settings;

Auth::start();
Competitions::bootstrap();   // サンプル記録会の用意(1リクエストにつき1回)

/**
 * ログイン必須のページの冒頭で呼ぶ。パスワードを通した記録会が選ばれていなければ、記録会の一覧(index.php)へ。
 * 通っていれば、その記録会の DB を使うようにする。
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
 * ログイン不要の公開ページ(結果・結果PDF)で、どの記録会かを決めて DB を開く。
 * ?c=記録会コード があればその記録会、なければ管理中の記録会($allowAdmin のときだけ)。
 * 「結果の公開」にしている記録会だけ見せる。$allowAdmin なら、その記録会を開いている管理者は非公開でも見られる
 * (管理画面からの結果PDF)。トップの記録会結果(一般向け)は $allowAdmin = false で、管理者かどうかは見ない。
 */
function public_comp($allowAdmin = true)
{
    $code = $_GET['c'] ?? ($allowAdmin ? Auth::current() : null);
    if (!$code || Competitions::open($code) === false) {
        http_response_code(404);
        exit('記録会が見つかりません');
    }
    $code = Competitions::find($code)['code'];
    if (!Settings::resultsPublic() && !($allowAdmin && Auth::isCurrent($code))) {
        http_response_code(403);
        exit('この記録会の結果は公開されていません');
    }
    return $code;
}

/** 「?CDE9E793」のように、クエリが記録会コードだけのときのコード(それ以外は null) */
function bare_code()
{
    $q = rawurldecode((string)($_SERVER['QUERY_STRING'] ?? ''));
    return preg_match('/^[A-Za-z0-9_-]{1,32}$/', $q) ? $q : null;
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
    // admin/ の中のページなら1つ上、トップの公開ページ(index.php)ならそのフォルダ
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
        'login' => '<path d="M11 7 9.6 8.4l2.6 2.6H2v2h10.2l-2.6 2.6L11 17l5-5zm9 12h-8v2h8a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2h-8v2h8z"/>',
        'logout' => '<path d="M10 3h9a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-9v-2h9V5h-9zm-1 5l1.4 1.4L8.8 11H16v2H8.8l1.6 1.6L9 16l-4-4z"/>',
        'list' => '<path d="M4 5h2v2H4zm4 0h12v2H8zM4 11h2v2H4zm4 0h12v2H8zm-4 6h2v2H4zm4 0h12v2H8z"/>',
        'trash' => '<path d="M9 3h6l1 1h4v2H4V4h4zM6 8h12l-1 13H7zm4 2v9h1.5v-9zm2.5 0v9H14v-9z"/>',
        'plus' => '<path d="M11 4h2v7h7v2h-7v7h-2v-7H4v-2h7z"/>',
        'copy' => '<path d="M16 1H4a2 2 0 0 0-2 2v14h2V3h12zm3 4H8a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2zm0 16H8V7h11z"/>',
        'history' => '<path d="M13 3a9 9 0 0 0-9 9H1l4 4 4-4H6a7 7 0 1 1 2 4.9l-1.4 1.4A9 9 0 1 0 13 3zm-1 5v5l4.3 2.5.7-1.2-3.5-2.1V8z"/>',
    ];
    return '<svg class="sp-icon" viewBox="0 0 24 24" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

/** 下部タブ(スマホモードと同じ並び方 + 端末設定。記録会設定などはホームのメニュー、記録会の切り替えは管理者メニュー画面) */
function tabs()
{
    return [
        'home' => ['home.php', 'ホーム', 'home'],
        'entries' => ['entries.php', '参加者', 'users'],
        'targets' => ['targets.php', '的番号', 'target'],
        'qr' => ['qr.php', '端末設定', 'qr'],
        'scores' => ['scores.php', '点数管理', 'edit'],
        'results' => ['results.php', '結果', 'chart'],
    ];
}

/**
 * 画面の上部(スマホモードと同じ: 上部バーに記録会名と画面名)。
 * $opts: 'public' => 参加者向けの公開ページ(管理用のリンク・タブを出さない), 'logout' => 右上にログアウトボタン(管理者メニュー), 'back' => [左上の「<」の戻り先, 説明(, アイコン名: 「<」の代わりにアイコンと説明のボタン)],
 *        'base' => style.css などの場所(トップのページから使うとき 'admin/'),
 *        'wide' => 表の多い画面で横幅を広げる, 'js' => JavaScript に渡す変数, 'body' => body の class
 */
function page_head($title, $tab = '', array $opts = [])
{
    $inComp = Lite\Db::hasSelection();
    $admin = Auth::check() && $inComp && empty($opts['public']);
    $GLOBALS['LITE_PUBLIC_PAGE'] = !empty($opts['public']);
    $GLOBALS['LITE_NO_TABS'] = !empty($opts['notabs']);   // 下部タブを出さない(素点の編集など)
    $name = $inComp ? (Settings::get('name') ?: Settings::code()) : 'ianseo-lite';
    $js = array_merge(['LITE_CSRF' => Auth::csrf()], $opts['js'] ?? []);
    $base = $opts['base'] ?? '';
    $ver = '?v=' . filemtime(__DIR__ . '/style.css') . '-' . filemtime(__DIR__ . '/app.js');
    $superAdmin = Auth::isAdmin() && empty($opts['public']);   // 全体管理者でログイン中の管理画面は、上部バーを黒に
    echo '<!DOCTYPE html><html lang="ja" translate="no"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
        . '<meta name="theme-color" content="' . ($superAdmin ? '#111111' : '#1e4d8c') . '"><title>' . h($title) . ' - ianseo-lite</title>'
        . '<link rel="stylesheet" href="' . $base . 'style.css' . $ver . '"><script>';
    foreach ($js as $k => $v) {
        echo 'var ' . $k . '=' . json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) . ';';
    }
    echo '</script><script src="' . $base . 'app.js' . $ver . '"></script></head>'
        . '<body class="' . h($opts['body'] ?? '') . '"><header class="sp-appbar' . ($superAdmin ? ' super' : '') . '">';
    // ホーム以外の管理画面は、左上に「<」(ホームに戻る)。'back' => [URL, 説明] で戻り先を変える
    $back = $opts['back'] ?? ($admin && basename($_SERVER['SCRIPT_NAME']) !== 'home.php' && empty($opts['noback']) ? ['home.php', 'ホームに戻る'] : null);
    if ($back && !empty($back[2])) {
        // アイコンと表記のボタン(記録会管理ホームの「記録会一覧」)
        echo '<a class="sp-appbar-btn sp-appbar-left" href="' . h($back[0]) . '" title="' . h($back[1]) . '">' . icon($back[2]) . '<span>' . h($back[1]) . '</span></a>';
    } elseif ($back) {
        echo '<a class="sp-appbar-back" href="' . h($back[0]) . '" title="' . h($back[1]) . '" aria-label="' . h($back[1]) . '">'
            . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15.4 4.6 8 12l7.4 7.4-1.4 1.4L5.2 12l8.8-8.8z"/></svg></a>';
    }
    echo '<div class="sp-appbar-title">';
    // 記録会名は表示だけ(ここから記録会は切り替えない。切り替えは管理者メニューで)。管理画面では [記録会コード] も
    echo '<span class="sp-tour">' . h($name) . ($inComp && empty($opts['public']) && Auth::check() ? ' [' . h(Settings::code()) . ']' : '') . '</span>';
    echo '<h1>' . h($title) . '</h1></div>';
    if (!empty($opts['right'])) {
        echo $opts['right'];   // ページ固有の右上のボタン
    }
    if (($admin || (Auth::isAdmin() && empty($opts['public']))) && !empty($opts['logout'])) {
        echo '<a class="sp-appbar-btn" href="logout.php" title="ログアウト">' . icon('logout') . '<span>ログアウト</span></a>';
    }
    echo '</header><main class="sp-main' . (!empty($opts['wide']) ? ' wide' : '') . '">';
    foreach ((array)flash() as $f) {
        echo '<div class="sp-flash ' . h($f[0]) . '">' . h($f[1]) . '</div>';
    }
}

/** 画面の下部(下部タブ。記録会を開いている管理者だけ) */
function page_foot($tab = '')
{
    echo '</main>';
    if (Auth::check() && Lite\Db::hasSelection() && empty($GLOBALS['LITE_PUBLIC_PAGE']) && empty($GLOBALS['LITE_NO_TABS'])) {
        echo '<nav class="sp-tabbar">';
        foreach (tabs() as $k => $t) {
            echo '<a href="' . $t[0] . '" class="' . ($k === $tab ? 'active' : '') . '">' . icon($t[2]) . '<span>' . $t[1] . '</span></a>';
        }
        echo '</nav>';
    }
    echo '<div id="sp-toast" class="sp-toast" role="status"></div></body></html>';
}

/**
 * 記録会の一覧(スマホモードの「試合の選択」と同じカード)と、新しい記録会・管理者ログインのシート。
 * 記録会をタップすると、その記録会のパスワード(管理者パスワードでも可)を入れるシートが出る。管理者としてログインしていれば、そのまま開く。
 * 切り替えるときは毎回パスワードを入れる(前に開いた記録会のパスワードは覚えない)。
 */
function render_competitions($next = 'home.php')
{
    $isAdmin = Auth::isAdmin();
    $adminSet = Auth::adminIsSet();
    $form = function ($inner, $id = '') use ($next) {
        return '<form method="post" action="index.php?next=' . h(rawurlencode($next)) . '"' . ($id ? ' id="' . $id . '"' : '') . '>' . csrf_field() . $inner . '</form>';
    };
    $actions = function ($label) {
        return '<div class="sp-sheet-actions"><button type="button" class="sp-btn sub" onclick="spSheet.close()">戻る</button><button class="sp-btn">' . $label . '</button></div>';
    };
    $pw = function ($name, $label, $auto = 'current-password', $min = '') {
        return '<label class="sp-field"><span>' . $label . '</span><input type="password" name="' . $name . '" required' . ($min ? ' minlength="' . $min . '"' : '') . ' autocomplete="' . $auto . '"></label>';
    };

    // 新しい記録会(一覧の上)。管理者としてログインしているときだけ
    if ($isAdmin) {
        echo '<p><button type="button" class="sp-btn block" id="CompNewBtn">' . icon('plus') . '新しい記録会を作成</button></p>';
    }

    $list = Competitions::all();
    if (!$isAdmin) {
        // 全体管理者でなければ一覧は出さない。記録会コードを入れて、その記録会の個別パスワードで開く
        render_code_login($list, $form, $pw);
    } else {
    echo '<div class="sp-list" id="CompList">';
    foreach ($list as $c) {
        $cur = Auth::isCurrent($c['code']);
        echo '<div class="sp-card tap sp-tour-item' . ($cur ? ' current' : '') . '" data-code="' . h($c['code']) . '" data-name="' . h($c['name'] ?: '(記録会名未設定)') . '"'
            . ($c['sample'] ? ' data-hint="' . h(Competitions::SAMPLE_PASSWORD) . '"' : '') . ($cur ? ' data-current="1"' : '') . (Auth::hasCompetitionPassword($c['code']) ? '' : ' data-nopw="1"') . '>'
            . '<div class="sp-name">' . h($c['name'] ?: '(記録会名未設定)')
            . ($cur ? '<span class="sp-tag pri">開いている記録会</span>' : '') . ($c['sample'] ? '<span class="sp-tag">サンプル</span>' : '')
            . (Auth::hasCompetitionPassword($c['code']) ? '' : '<span class="sp-tag">管理者のみ</span>') . '</div>'
            . '<div class="sp-sub">' . h(implode(' / ', array_filter([$c['code'], $c['preset_label'], $c['entries'] . '人',
                $c['last_score'] ? '最終更新 ' . substr($c['last_score'], 0, 16) : '']))) . '</div></div>';
    }
    if (!$list) {
        echo '<div class="sp-empty">記録会がありません</div>';
    }
    echo '</div>';
    }

    // 全体管理者(一覧の下。ログイン中だけ)
    if ($isAdmin) {
        echo '<div class="sp-section-title">全体管理者</div><div class="sp-menu">'
            . '<a class="sp-card tap" href="#" id="AdminPwBtn">' . icon('edit') . '<div class="sp-card-main"><div class="sp-name">全体管理者パスワードの変更</div></div></a>';
        echo '</div>';
    }

    // シートの中身(JavaScript で開く)
    echo '<template id="CompOpenTpl">' . $form('<input type="hidden" name="do" value="open"><input type="hidden" name="code">'
        . '<h2 data-role="title"></h2><div class="sp-sub" data-role="sub"></div>'
        . $pw('password', '個別パスワード')
        . $actions('開く')) . '</template>';
    // 管理者は、パスワードを聞かずに開く
    echo $form('<input type="hidden" name="do" value="open"><input type="hidden" name="code"><input type="hidden" name="password" value="">', 'CompOpenDirect');

    $opts = '';
    foreach (Settings::PRESETS as $k => $p) {
        $opts .= '<option value="' . $k . '"' . ($k === Settings::DEFAULTS['preset'] ? ' selected' : '') . '>' . h($p['label']) . '</option>';
    }
    if ($isAdmin) {
        echo '<template id="CompNewTpl">' . $form('<input type="hidden" name="do" value="create"><h2>新しい記録会</h2>'
            . '<div class="sp-sub">記録会コード(8桁の16進数)は自動で付きます。</div>'
            . '<label class="sp-field"><span>記録会名</span><input type="text" name="name"></label>'
            . '<label class="sp-field"><span>競技形式</span><select name="preset">' . $opts . '</select></label>'
            . '<div class="sp-row">' . str_replace(' required', '', $pw('password', 'パスワード(任意)', 'new-password', 4) . $pw('password2', 'パスワード(確認)', 'new-password', 4)) . '</div>'
            . '<div class="sp-sub">この記録会の管理を任せる人のためのパスワードです。空欄なら、管理者パスワードでだけ開けます。</div>'
            . $actions('作成')) . '</template>';
    }
    echo '<template id="AdminLoginTpl">' . $form('<input type="hidden" name="do" value="admin_login"><h2>全体管理者ログイン</h2>'
        . $pw('admin_password', '管理者パスワード') . $actions('ログイン')) . '</template>';
    if (!$adminSet) {
        $needProof = !Auth::canSetupAdmin();
        echo '<template id="AdminSetupTpl">' . $form('<input type="hidden" name="do" value="admin_setup"><h2>全体管理者パスワードを設定</h2>'
            . '<div class="sp-sub">すべての記録会に共通のパスワードです。管理者としてログインすると、どの記録会も開け、新しい記録会を作れます。</div>'
            . '<div class="sp-row">' . $pw('admin_password', '管理者パスワード', 'new-password', 4) . $pw('admin_password2', '確認', 'new-password', 4) . '</div>'
            . ($needProof ? $pw('proof', 'いずれかの記録会のパスワード(サンプル以外)') . '<div class="sp-sub">最初の設定だけ、記録会を作った人であることを確かめます。</div>' : '')
            . $actions('設定する')) . '</template>';
    }
    if ($isAdmin) {
        echo '<template id="AdminPwTpl">' . $form('<input type="hidden" name="do" value="admin_password"><h2>全体管理者パスワードの変更</h2>'
            . '<div class="sp-row">' . $pw('admin_password', '新しいパスワード', 'new-password', 4) . $pw('admin_password2', '確認', 'new-password', 4) . '</div>'
            . $actions('変更')) . '</template>';
    }
    echo '<script>(function(){'
        . 'var isAdmin=' . ($isAdmin ? 'true' : 'false') . ';'
        . 'function sheet(id){var t=document.getElementById(id);if(!t)return;var el=spSheet.open(t.innerHTML);var f=el.querySelector("input:not([type=hidden])");if(f)setTimeout(function(){f.focus()},250);return el}'
        . 'var cl=document.getElementById("CompList");if(cl)cl.addEventListener("click",function(ev){var c=ev.target.closest("[data-code]");if(!c)return;'
        . 'if(c.dataset.current){location.href="home.php";return}'
        . 'if(isAdmin){var d=document.getElementById("CompOpenDirect");d.querySelector("[name=code]").value=c.dataset.code;d.submit();return}'
        . 'var el=sheet("CompOpenTpl");'
        . 'el.querySelector("[name=code]").value=c.dataset.code;el.querySelector("[data-role=title]").textContent=c.dataset.name;'
        . 'el.querySelector("[data-role=sub]").textContent=c.dataset.code+(c.dataset.hint?"(サンプル: パスワードは "+c.dataset.hint+")":"");'
        . 'if(c.dataset.nopw){el.querySelector(".sp-field span").textContent="管理者パスワード(この記録会は管理者のみ)"}});'
        . '[["CompNewBtn","CompNewTpl"],["AdminLoginBtn","AdminLoginTpl"],["AdminSetupBtn","AdminSetupTpl"],["AdminPwBtn","AdminPwTpl"]].forEach(function(x){'
        . 'var b=document.getElementById(x[0]);if(b)b.onclick=function(ev){ev.preventDefault();sheet(x[1])}});'
        . '})()</script>';
}

/**
 * 全体管理者でないときの管理者メニュー: 記録会コードを入れる → その記録会があれば、個別パスワードを入れて開く。
 * 記録会を開いていれば、そのホームへ戻るカードも出す。
 */
function render_code_login(array $list, callable $form, callable $pw)
{
    $cur = Auth::current();
    if ($cur) {
        $name = Competitions::readSetting($cur, 'name') ?: $cur;
        echo '<a class="sp-card tap" href="home.php">' . icon('home') . '<div class="sp-card-main"><div class="sp-name">' . h($name)
            . ' <span class="sp-tag pri">開いている記録会</span></div><div class="sp-sub">' . h($cur) . '</div></div></a>'
            . '<div style="height:14px"></div>';
    }
    $sel = isset($_GET['code']) ? Competitions::find($_GET['code']) : null;
    echo '<div class="sp-card">' . $form('<input type="hidden" name="do" value="find">'
        . '<label class="sp-field"><span>記録会コード</span><input type="text" name="code" required autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="例: 5A4D0001"'
        . ($sel ? ' value="' . h($sel['code']) . '"' : '') . '></label>'
        . '<div class="sp-sheet-actions"><button class="sp-btn">次へ</button></div>') . '</div>';
    // 全体管理者でログイン(まだ全体管理者パスワードが無ければ、最初の設定)
    echo '<p style="margin-top:20px"><button type="button" class="sp-btn sub block" id="' . (Auth::adminIsSet() ? 'AdminLoginBtn' : 'AdminSetupBtn') . '">'
        . icon('gear') . '全体管理者でログイン</button></p>';
    if (!$sel) {
        return;
    }
    // 記録会が見つかったら、個別パスワードの入力を下から出す(全体管理者ログインと同じシート)
    $code = $sel['code'];
    $sample = false;
    foreach ($list as $c) {
        if ((string)$c['code'] === $code) {
            $sample = !empty($c['sample']);
        }
    }
    $hasPw = Auth::hasCompetitionPassword($code);
    echo '<template id="CodeOpenTpl">' . $form('<input type="hidden" name="do" value="open"><input type="hidden" name="code" value="' . h($code) . '">'
        . '<input type="hidden" name="back" value="index.php?code=' . h(rawurlencode($code)) . '">'
        . '<h2>' . h(Competitions::readSetting($code, 'name') ?: '(記録会名未設定)') . '</h2>'
        . '<div class="sp-sub">' . h($code) . ($sample ? '(サンプル: パスワードは ' . h(Competitions::SAMPLE_PASSWORD) . ')' : '') . '</div>'
        . $pw('password', $hasPw ? '個別パスワード' : '管理者パスワード(この記録会は管理者のみ)')
        . '<div class="sp-sheet-actions"><button type="button" class="sp-btn sub" onclick="spSheet.close()">戻る</button><button class="sp-btn">開く</button></div>') . '</template>'
        . '<script>document.addEventListener("DOMContentLoaded",function(){var el=spSheet.open(document.getElementById("CodeOpenTpl").innerHTML);'
        . 'var f=el.querySelector("input[type=password]");if(f)setTimeout(function(){f.focus()},250)});</script>';
}

/** 結果の公開カード(トグル・バッジ・URLのコピー)。ホームと記録会結果の画面で使う。切り替えは home.php へ POST */
function public_card()
{
    // 状態はバッジ(公開中 / 非公開)、URL はコピーできる欄に
    $pubUrl = server_base_url() . '?c=' . Lite\Settings::code();
    $pub = Lite\Settings::resultsPublic();
    return '<div class="sp-card sp-public">' . icon('chart') . '<div class="sp-card-main">'
        . '<div class="sp-name">結果の公開 <span class="sp-tag ' . ($pub ? 'ok' : 'danger') . '" id="PubBadge">' . ($pub ? '公開中' : '非公開') . '</span></div>'
        . '<div class="sp-copy"><input type="text" readonly id="PubUrl" value="' . h($pubUrl) . '" aria-label="公開用のURL">'
        . '<button type="button" class="sp-btn sub" id="PubCopy" title="URLをコピー">' . icon('copy') . '</button></div></div>'
        . '<label class="sp-toggle" title="結果を公開する"><input type="checkbox" id="PubToggle"' . ($pub ? ' checked' : '') . '><span></span></label></div>'
        . <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', () => {
	const t = document.getElementById('PubToggle'), badge = document.getElementById('PubBadge');
	t.addEventListener('change', async () => {
		t.disabled = true;
		try {
			const r = await spPostJson('home.php', {act: 'public', value: t.checked ? 1 : 0});
			t.checked = r.public;
			badge.textContent = r.public ? '公開中' : '非公開';
			badge.className = 'sp-tag ' + (r.public ? 'ok' : 'danger');
			spToast(r.public ? '結果を公開しました' : '結果を非公開にしました');
		} catch (e) {
			t.checked = !t.checked;
			spToast('切り替えできませんでした: ' + e.message, true);
		}
		t.disabled = false;
	});
	document.getElementById('PubCopy').addEventListener('click', async () => {
		const inp = document.getElementById('PubUrl');
		try {
			await navigator.clipboard.writeText(inp.value);
		} catch (e) {
			inp.select();
			document.execCommand('copy');   // 古いブラウザ・http のとき
		}
		spToast('URLをコピーしました');
	});
});
</script>
HTML;
}

/**
 * 公開ページ(トップ)の右上のボタン。シートのフォームは admin/index.php に送る。
 *   $code = null … 記録会一覧: 「ログイン」。管理者メニューへ
 *   $code = 記録会コード … 記録会結果: 「設定」。この記録会を開いている(または全体管理者)ならそのまま記録会管理ホームへ、
 *                          そうでなければ個別パスワードのシート
 */
function public_admin_button($code = null)
{
    $back = $code === null ? '../?list=1' : '../?c=' . rawurlencode($code);
    $form = function ($inner) use ($back) {
        return '<form method="post" action="admin/index.php?next=home.php">' . csrf_field()
            . '<input type="hidden" name="back" value="' . h($back) . '">' . $inner . '</form>';
    };
    $actions = function ($label) {
        return '<div class="sp-sheet-actions"><button type="button" class="sp-btn sub" onclick="spSheet.close()">戻る</button><button class="sp-btn">' . $label . '</button></div>';
    };
    $pw = function ($name, $label) {
        return '<label class="sp-field"><span>' . $label . '</span><input type="password" name="' . $name . '" required autocomplete="current-password"></label>';
    };
    $btn = function ($href, $icon, $label, $id = '') {
        return '<a class="sp-appbar-btn" href="' . h($href) . '"' . ($id ? ' id="' . $id . '"' : '') . ' title="' . h($label) . '">' . icon($icon) . '<span>' . h($label) . '</span></a>';
    };
    $sheet = function ($btnId, $tplId) {
        return '<script>document.getElementById("' . $btnId . '").addEventListener("click",function(ev){ev.preventDefault();'
            . 'var el=spSheet.open(document.getElementById("' . $tplId . '").innerHTML);var f=el.querySelector("input[type=password]");if(f)setTimeout(function(){f.focus()},250)});</script>';
    };

    if ($code === null) {
        return $btn('admin/index.php', 'login', 'ログイン');   // 管理者メニューへ(記録会コード → 個別パスワード、または全体管理者でログイン)
    }
    if (Auth::isCurrent($code)) {
        return $btn('admin/home.php', 'gear', '設定');
    }
    $open = '<input type="hidden" name="do" value="open"><input type="hidden" name="code" value="' . h($code) . '">';
    if (Auth::isAdmin()) {
        // 全体管理者はパスワードなしで開く
        return $btn('#', 'gear', '設定', 'PubCompBtn')
            . $form($open . '<input type="hidden" name="password" value="">')
            . '<script>document.getElementById("PubCompBtn").addEventListener("click",function(ev){ev.preventDefault();this.nextElementSibling.submit()});</script>';
    }
    $name = Settings::title();
    return $btn('#', 'gear', '設定', 'PubCompBtn')
        . '<template id="PubCompTpl">' . $form($open . '<h2>' . h($name) . '</h2><div class="sp-sub">' . h($code) . '</div>'
            . (Auth::hasCompetitionPassword($code) ? $pw('password', '個別パスワード') : $pw('password', '管理者パスワード(この記録会は管理者のみ)'))
            . $actions('開く')) . '</template>'
        . $sheet('PubCompBtn', 'PubCompTpl');
}
