<?php
// 記録会の選択: 記録会を選んでパスワードで開く(記録会ごとに DB とパスワードがある)。記録会の切り替え・新しい記録会もこの画面だけで行う。
// 開くとき・切り替えるときは毎回パスワードを入れる(前に開いた記録会のパスワードは覚えない)。
// 管理者パスワード(すべての記録会に共通)でログインすると、どの記録会もそのまま開け、新しい記録会を作れる。公開の結果ページの「管理者設定」からここに来る。
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
                // 切り替えるときも毎回パスワードを確かめる(前に開いた記録会のパスワードは覚えていない)
                if (!Auth::login((string)($_POST['code'] ?? ''), (string)($_POST['password'] ?? ''))) {
                    throw new InvalidArgumentException('パスワードが違います');
                }
                flash((Lite\Competitions::readSetting(Auth::current(), 'name') ?: Auth::current()) . ' を開きました');
                redirect($next);
            case 'create':
                if (!Auth::isAdmin()) {   // 新しい記録会を作れるのは、管理者としてログインしているときだけ
                    throw new InvalidArgumentException('新しい記録会を作成するには、管理者としてログインしてください');
                }
                if (($_POST['password'] ?? '') !== ($_POST['password2'] ?? '')) {
                    throw new InvalidArgumentException('確認用パスワードが一致しません');
                }
                $code = Competitions::create($_POST['name'] ?? '', $_POST['preset'] ?? '', (string)($_POST['password'] ?? ''));
                Auth::login($code, (string)$_POST['password']);
                flash('記録会を作りました。記録会設定を確認してください');
                redirect('setup.php');
            case 'admin_login':
                if (!Auth::adminLogin((string)($_POST['admin_password'] ?? ''))) {
                    throw new InvalidArgumentException('管理者パスワードが違います');
                }
                flash('管理者としてログインしました');
                redirect('index.php');
            case 'admin_setup':
                if (Auth::adminIsSet()) {
                    throw new InvalidArgumentException('管理者パスワードはすでに設定されています');
                }
                if (!Auth::canSetupAdmin((string)($_POST['proof'] ?? ''))) {
                    throw new InvalidArgumentException('記録会のパスワードが違います(サンプル以外の記録会のパスワードを入れてください)');
                }
                if (($_POST['admin_password'] ?? '') !== ($_POST['admin_password2'] ?? '')) {
                    throw new InvalidArgumentException('確認用パスワードが一致しません');
                }
                Auth::setAdminPassword((string)$_POST['admin_password']);
                Auth::adminLogin((string)$_POST['admin_password']);
                flash('管理者パスワードを設定しました');
                redirect('index.php');
            case 'admin_password':
                if (!Auth::isAdmin()) {
                    throw new InvalidArgumentException('管理者としてログインしてください');
                }
                if (($_POST['admin_password'] ?? '') !== ($_POST['admin_password2'] ?? '')) {
                    throw new InvalidArgumentException('確認用パスワードが一致しません');
                }
                Auth::setAdminPassword((string)$_POST['admin_password']);
                flash('管理者パスワードを変更しました');
                redirect('index.php');
        }
    } catch (InvalidArgumentException | RuntimeException $e) {
        flash($e->getMessage(), 'err');
        redirect($back);
    }
}

// 記録会を開いていれば、その記録会を開いたまま一覧を出す(上部バーに記録会名、下部タブも出る)
if (!Auth::check() || Competitions::open(Auth::current()) === false) {
    Db::unselect();
}
page_head('記録会の選択', 'home', ['logout' => true]);
render_competitions($next);
echo '<p class="sp-note"><a href="../">記録会結果を見る(公開している記録会)</a></p>';
page_foot(Auth::check() ? 'home' : '');
