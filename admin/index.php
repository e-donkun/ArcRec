<?php
// 大会の選択: 大会を選んでパスワードで開く(大会ごとに DB とパスワードがある)。大会の切り替え・新しい大会もこの画面だけで行う。
// 開くとき・切り替えるときは毎回パスワードを入れる(前に開いた大会のパスワードは覚えない)。
require __DIR__ . '/_layout.php';

use Lite\Auth;
use Lite\Competitions;
use Lite\Db;

$next = basename($_GET['next'] ?? '');
$next = preg_match('/^[a-z_]+\.php$/', $next) ? $next : 'home.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();
    $back = 'index.php';
    try {
        switch ($_POST['do'] ?? '') {
            case 'open':
                // 切り替えるときも毎回パスワードを確かめる(前に開いた大会のパスワードは覚えていない)
                if (!Auth::login((string)($_POST['code'] ?? ''), (string)($_POST['password'] ?? ''))) {
                    throw new InvalidArgumentException('パスワードが違います');
                }
                flash((Lite\Competitions::readSetting(Auth::current(), 'name') ?: Auth::current()) . ' を開きました');
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
                flash('大会を作りました。大会設定を確認してください');
                redirect('setup.php');
        }
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'err');
        redirect($back);
    }
}

// 大会を開いていれば、その大会を開いたまま一覧を出す(上部バーに大会名、下部タブも出る)
if (!Auth::check() || Competitions::open(Auth::current()) === false) {
    Db::unselect();
}
page_head('大会の選択', 'home');
echo '<p class="sp-note">大会をタップして、その大会のパスワードを入れると開きます。大会ごとにデータ(SQLite)とパスワードが別です。</p>';
render_competitions($next);
echo '<p class="sp-note"><a href="../results.php">結果を見る(公開している大会)</a></p>';
page_foot(Auth::check() ? 'home' : '');
