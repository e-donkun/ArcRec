<?php
// 記録会管理ホーム: 記録会の状況、結果の公開、メニュー(下部タブと同じ)、印刷・出力。左上の「記録会一覧」で戻る(全体管理者は全体管理者メニュー、個別管理者は公開の記録会一覧)。記録会の切り替え・新しい記録会は管理者メニュー(index.php)で
require __DIR__ . '/_layout.php';
require_admin();

use Lite\Entries;
use Lite\Results;
use Lite\Settings;

// 結果の公開/非公開(トグル。Ajax)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'public') {
    Settings::set('results_public', empty($_POST['value']) ? '0' : '1');
    json_out(['ok' => true, 'public' => Settings::resultsPublic()]);
}

$devices = Lite\Devices::all();
$online = 0;
foreach ($devices as $d) {
    if (strtotime($d['last_seen']) > time() - 120) {
        $online++;
    }
}
$code = Settings::code();
// 左上の「記録会一覧」: 全体管理者は全体管理者メニュー(記録会を閉じる)、個別管理者は記録会一覧(公開ページ)へ
if (Lite\Auth::isAdmin()) {
    $back = ['close.php', '記録会一覧', 'list'];
} else {
    $back = ['../?list=1', '記録会一覧', 'list'];
}
page_head('記録会管理ホーム', 'home', ['back' => $back]);
// 見出し: 記録会名 [記録会コード]
echo '<h2 class="sp-comp-title">' . h(Settings::get('name') ?: '(記録会名なし)') . ' <span>[' . h($code) . ']</span></h2>';
if (!Settings::get('name')) {
    echo '<div class="sp-flash warn">記録会名がまだありません。<a href="setup.php">記録会設定</a> で入れてください。</div>';
}
// 終了エンド: 的に配置した参加者の全員が射ち終えたエンドの数 / 全エンド
$ep = Results::endsProgress();
$doneEnds = $ep['done'];
$allEnds = $ep['all'];
echo '<div class="sp-stats sp-stats2">'
    . '<div><small>終了エンド</small><b>' . (int)$doneEnds . '</b> / ' . $allEnds . '<div class="bar"><i style="width:' . ($allEnds ? round((int)$doneEnds * 100 / $allEnds) : 0) . '%"></i></div></div>'
    . '<div><small>端末(直近2分)</small><b>' . $online . '</b> / ' . count($devices) . '</div>'
    . '</div>';
echo '<p class="sp-note">スコア最終更新: <b>' . h(Results::lastUpdateText()) . '</b></p>';

$item = function ($href, $icon, $title, $sub = '', $attr = '') {
    return '<a class="sp-card tap" href="' . $href . '"' . $attr . '>' . icon($icon) . '<div class="sp-card-main"><div class="sp-name">' . $title . '</div>'
        . ($sub !== '' ? '<div class="sp-sub">' . $sub . '</div>' : '') . '</div></a>';
};
// 記録会の準備: 下部のボタン(タブ)と同じ内容
echo '<div class="sp-section-title">記録会の準備</div><div class="sp-menu">'
    . $item('setup.php', 'gear', '記録会設定')
    . $item('entries.php', 'users', '参加者登録')
    . $item('targets.php', 'target', '的番号設定')
    . $item('qr.php', 'qr', '端末設定')
    . $item('scores.php', 'edit', '点数管理')
    . $item('results.php', 'chart', '結果出力')
    . public_card()   // 結果の公開(トグル)。結果出力の下に
    . '</div>';
// 印刷・出力: スコアカード(白紙 / 素点入力済)と結果PDF
echo '<div class="sp-section-title">印刷・出力</div><div class="sp-print">'
    . '<div class="sp-card">' . icon('print') . '<div class="sp-card-main"><div class="sp-name">スコアカードPDF</div><div class="actions">'
    . '<a class="sp-btn sub" href="pdf.php?type=scorecard_blank" target="_blank">白紙</a>'
    . '<a class="sp-btn sub" href="pdf.php?type=scorecard_filled" target="_blank">素点入力済</a></div></div></div>'
    . '<a class="sp-card tap" href="pdf.php?type=results&amp;c=' . h($code) . '" target="_blank">' . icon('print') . '<div class="sp-card-main"><div class="sp-name">結果PDF</div><div class="sp-sub">部門別の順位</div></div></a>'
    . '</div>';

page_foot('home');
