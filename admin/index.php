<?php
// 大会の一覧(切り替え・パスワード入力・新規作成)。大会ごとに DB とパスワードがある。
require __DIR__ . '/_layout.php';

use Lite\Auth;
use Lite\Competitions;
use Lite\Db;
use Lite\Settings;

$next = basename($_GET['next'] ?? '');
$next = preg_match('/^[a-z_]+\.php$/', $next) ? $next : 'home.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();
    try {
        switch ($_POST['do'] ?? '') {
            case 'open':
                $code = (string)($_POST['code'] ?? '');
                if (Auth::authorized($code)) {
                    Auth::switchTo($code);
                } elseif (!Auth::login($code, (string)($_POST['password'] ?? ''))) {
                    throw new InvalidArgumentException('パスワードが違います');
                }
                redirect($next);
            case 'create':
                if (!Auth::canCreate()) {
                    throw new InvalidArgumentException('大会を作るには、先にどれかの大会をパスワードで開いてください');
                }
                if (($_POST['password'] ?? '') !== ($_POST['password2'] ?? '')) {
                    throw new InvalidArgumentException('確認用パスワードが一致しません');
                }
                $code = Competitions::create($_POST['name'] ?? '', $_POST['preset'] ?? '', (string)($_POST['password'] ?? ''));
                Auth::login($code, (string)$_POST['password']);
                flash('大会を作りました。競技設定を確認してください');
                redirect('setup.php');
        }
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'err');
        redirect('index.php' . (isset($_GET['next']) ? '?next=' . rawurlencode($next) : ''));
    }
}

// 管理中の大会があれば、そのメニューを出せるように DB を開いておく
if ($cur = Auth::current()) {
    Competitions::open($cur);
} else {
    Db::unselect();
}
page_head('大会の一覧', 'comps', true);
echo '<h1>大会の一覧</h1>';
echo '<p class="muted small">大会ごとにデータ(SQLite)とパスワードが別になっています。開いたことのある大会は、パスワードなしで切り替えられます。</p>';

$list = Competitions::all();
echo '<div class="tablewrap"><table><tr><th>大会</th><th>競技形式</th><th class="n">選手</th><th>最終更新</th><th></th></tr>';
foreach ($list as $c) {
    $isCur = $cur && strcasecmp($cur, $c['code']) === 0;
    $authed = Auth::authorized($c['code']);
    echo '<tr><td><b>' . h($c['name'] ?: '(大会名未設定)') . '</b>'
        . ($c['sample'] ? ' <span class="badge">サンプル</span>' : '')
        . ($isCur ? ' <span class="badge on">管理中</span>' : '')
        . '<br><code class="small">' . h($c['code']) . '</code>'
        . ($c['sample'] ? ' <span class="small muted">パスワード: <code>' . h(Competitions::SAMPLE_PASSWORD) . '</code></span>' : '') . '</td>'
        . '<td>' . h($c['preset_label']) . '</td><td class="n">' . (int)$c['entries'] . '</td>'
        . '<td class="small">' . h($c['last_score'] ?: ($c['updated'] ? date('Y-m-d H:i', $c['updated']) : '')) . '</td>'
        . '<td><form method="post" class="inline-form">' . csrf_field() . '<input type="hidden" name="do" value="open"><input type="hidden" name="code" value="' . h($c['code']) . '">'
        . ($authed ? '' : '<input type="password" name="password" placeholder="パスワード" required style="width:9rem"> ')
        . '<button class="sm">' . ($isCur ? '開く' : ($authed ? '切り替え' : '開く')) . '</button></form></td></tr>';
}
echo '</table></div>';

if (Auth::canCreate()) {
    echo '<h2>新しい大会を作る</h2><form method="post" class="card wideform">' . csrf_field() . '<input type="hidden" name="do" value="create">'
        . '<label>大会名</label><input type="text" name="name">'
        . '<label>競技形式</label><select name="preset">';
    foreach (Settings::PRESETS as $k => $p) {
        echo '<option value="' . $k . '"' . ($k === Settings::DEFAULTS['preset'] ? ' selected' : '') . '>' . h($p['label']) . '</option>';
    }
    echo '</select><div class="row nowrap wide"><div><label>この大会のパスワード</label><input type="password" name="password" required minlength="4"></div>'
        . '<div><label>パスワード(確認)</label><input type="password" name="password2" required minlength="4"></div></div>'
        . '<p class="muted small">大会コード(8桁の16進数)は自動で付きます。パスワードはこの大会だけのものです。</p>'
        . '<p><button>作成</button></p></form>';
} else {
    echo '<p class="muted small">新しい大会を作るには、どれかの大会をパスワードで開いてください。</p>';
}
page_foot();
