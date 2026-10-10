<?php
// 管理者メニュー。
//   全体管理者(すべての記録会に共通のパスワード)でログイン中: 記録会の一覧。どの記録会もそのまま開け、新しい記録会を作れる。
//   ログインしていない: 一覧は出さない。記録会コードを入れ、その記録会があれば個別パスワードを入れて開く(個別管理)。
// 開くとき・切り替えるときは毎回パスワードを入れる(前に開いた記録会のパスワードは覚えない)。
require __DIR__ . '/_layout.php';

use Lite\Auth;
use Lite\Competitions;
use Lite\Db;

$next = basename($_GET['next'] ?? '');
$next = preg_match('/^[a-z_]+\.php$/', $next) ? $next : 'home.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();
    $back = 'index.php';
    // 公開ページのシートから来たときは、失敗したらその公開ページに戻す
    if (preg_match('#^(\.\./(\?(c=[A-Za-z0-9]+|list=1))?|index\.php\?code=[A-Za-z0-9_-]+)$#', (string)($_POST['back'] ?? ''))) {
        $back = $_POST['back'];
    }
    try {
        switch ($_POST['do'] ?? '') {
            case 'find':
                // 記録会コードから記録会を探す(全体管理者でないときの入口)。あれば、パスワードを入れる画面へ
                $f = Competitions::find((string)($_POST['code'] ?? ''));
                if (!$f) {
                    throw new InvalidArgumentException('その記録会コードの記録会はありません');
                }
                redirect('index.php?code=' . rawurlencode($f['code']) . ($next !== 'home.php' ? '&next=' . rawurlencode($next) : ''));
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
                Auth::login($code, '');   // 管理者としてログイン中なので、パスワードなしで開ける
                flash('記録会を作りました。記録会設定を確認してください');
                redirect('setup.php');
            case 'admin_login':
                if (!Auth::adminLogin((string)($_POST['admin_password'] ?? ''))) {
                    throw new InvalidArgumentException('管理者パスワードが違います');
                }
                flash('全体管理者としてログインしました');
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
                flash('全体管理者パスワードを設定しました');
                redirect('index.php');
            case 'langpack_fetch':
            case 'langpack_clear':
                // 端末アプリの言語: 本家 ianseo の翻訳サイトから取得 / 同梱の訳に戻す(全体管理者だけ)
                if (!Auth::isAdmin()) {
                    throw new InvalidArgumentException('全体管理者としてログインしてください');
                }
                if ($_POST['do'] === 'langpack_clear') {
                    Lite\LangPacks::clear();
                    flash('端末アプリの言語を同梱の訳に戻しました');
                } else {
                    $got = Lite\LangPacks::fetch();
                    flash('本家の翻訳を取得しました(' . implode('・', $got) . ')。端末アプリで「言語を更新」すると反映されます');
                }
                redirect('index.php');
            case 'admin_password':
                if (!Auth::isAdmin()) {
                    throw new InvalidArgumentException('管理者としてログインしてください');
                }
                if (($_POST['admin_password'] ?? '') !== ($_POST['admin_password2'] ?? '')) {
                    throw new InvalidArgumentException('確認用パスワードが一致しません');
                }
                Auth::setAdminPassword((string)$_POST['admin_password']);
                flash('全体管理者パスワードを変更しました');
                redirect('index.php');
        }
    } catch (InvalidArgumentException | RuntimeException $e) {
        flash($e->getMessage(), 'err');
        redirect($back);
    }
}

// 「admin/?記録会コード」で、その記録会のパスワード入力を出す(admin/index.php?code=… と同じ)
if (!isset($_GET['code']) && ($bc = bare_code()) !== null) {
    if (Competitions::find($bc)) {
        $_GET['code'] = $bc;
    } else {
        flash('その記録会コードの記録会はありません', 'err');
    }
}
// 記録会を開いていれば、その記録会を開いたまま一覧を出す(上部バーに記録会名、下部タブも出る)
if (!Auth::check() || Competitions::open(Auth::current()) === false) {
    Db::unselect();
}
page_head(Auth::isAdmin() ? '全体管理者メニュー' : '管理者メニュー', 'home', ['logout' => true, 'notabs' => true, 'back' => ['../?list=1', '記録会一覧に戻る']]);
render_competitions($next);
page_foot();
