<?php
require __DIR__ . '/_layout.php';

use Lite\Auth;
use Lite\Entries;
use Lite\Results;
use Lite\Settings;

// ---- 初回: 管理パスワードの設定 / ログイン
if (!Auth::check()) {
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Auth::verifyCsrf();
        try {
            if (Auth::needsSetup()) {
                if (($_POST['password'] ?? '') !== ($_POST['password2'] ?? '')) {
                    throw new InvalidArgumentException('確認用パスワードが一致しません');
                }
                Auth::setPassword($_POST['password'] ?? '');
                Auth::attempt($_POST['password']);
                redirect('setup.php');
            } elseif (Auth::attempt($_POST['password'] ?? '')) {
                $next = basename($_GET['next'] ?? 'index.php');
                redirect(preg_match('/^[a-z_]+\.php$/', $next) ? $next : './');
            } else {
                $error = 'パスワードが違います';
            }
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }
    }
    page_head('ログイン', '', true);
    $first = Auth::needsSetup();
    echo '<h1>' . ($first ? '最初の設定: 管理パスワード' : '管理者ログイン') . '</h1><div class="card">';
    if ($first) {
        echo '<p>この大会の管理画面のパスワードを決めてください。(選手・得点の編集に必要です)</p>';
    }
    if ($error) {
        echo '<div class="flash err">' . h($error) . '</div>';
    }
    echo '<form method="post">' . csrf_field()
        . '<label>パスワード</label><input type="password" name="password" autofocus required>';
    if ($first) {
        echo '<label>パスワード(確認)</label><input type="password" name="password2" required>';
    }
    echo '<p><button>' . ($first ? '設定して始める' : 'ログイン') . '</button></p></form></div>';
    echo '<p class="muted">結果の閲覧だけなら <a href="results.php">結果ページ</a> を開いてください。</p>';
    page_foot();
    exit;
}

// ---- ダッシュボード
$p = Results::progress();
$pct = $p['all_arrows'] ? round($p['hits'] * 100 / $p['all_arrows']) : 0;
$devices = Lite\Devices::all();
$online = 0;
foreach ($devices as $d) {
    if (strtotime($d['last_seen']) > time() - 120) {
        $online++;
    }
}
page_head('ホーム', 'index');
echo '<h1>ホーム</h1>';
if (!Settings::get('name')) {
    echo '<div class="flash warn">まだ大会が設定されていません。<a href="setup.php">競技設定</a> から始めてください。</div>';
}
echo '<div class="cards">'
    . '<div class="stat"><span class="muted">選手</span><b>' . $p['archers'] . '</b></div>'
    . '<div class="stat"><span class="muted">全射終了</span><b>' . $p['finished'] . ' / ' . $p['archers'] . '</b></div>'
    . '<div class="stat"><span class="muted">入力済みの矢</span><b>' . $pct . '%</b><div class="bar"><i style="width:' . $pct . '%"></i></div></div>'
    . '<div class="stat"><span class="muted">スコア最終更新</span><b style="font-size:1.1rem">' . h(Results::lastUpdateText()) . '</b></div>'
    . '<div class="stat"><span class="muted">端末(直近2分)</span><b>' . $online . ' / ' . count($devices) . '</b></div>'
    . '</div>';
echo '<div class="card"><h2 style="margin-top:0">進め方</h2><ol>'
    . '<li><a href="setup.php">競技設定</a> — 大会名・射数(36/72、屋内30/60)・部門を決める</li>'
    . '<li><a href="entries.php">選手</a> — 名簿を登録(CSV取込可)し、的番号を割り当てる</li>'
    . '<li><a href="pdf.php?type=scorecard_blank">スコアカード(白紙)PDF</a> を印刷して配る</li>'
    . '<li><a href="qr.php">端末接続</a> — ScorekeeperNG にサーバー設定のQRを読ませる</li>'
    . '<li><a href="scores.php">入力状況</a> で進行を確認 — 終わったら <a href="results.php">結果</a> を PDF 出力</li>'
    . '</ol></div>';
page_foot();
