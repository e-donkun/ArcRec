<?php
// ホーム: 記録会の状況と、メニュー(記録会設定・端末接続・印刷)。ログアウトは上部バーの右上(ホームだけ)。記録会の切り替え・新しい記録会は、ログアウトして「記録会の選択」(index.php)で
require __DIR__ . '/_layout.php';
require_admin();

use Lite\Entries;
use Lite\Results;
use Lite\Settings;

$p = Results::progress();
$pct = $p['all_arrows'] ? round($p['hits'] * 100 / $p['all_arrows']) : 0;
$devices = Lite\Devices::all();
$online = 0;
foreach ($devices as $d) {
    if (strtotime($d['last_seen']) > time() - 120) {
        $online++;
    }
}
$placed = 0;
foreach (Entries::all() as $e) {
    if (!$e['status'] && $e['target']) {
        $placed++;
    }
}
$code = Settings::code();
page_head('ホーム', 'home', ['logout' => true]);
if (!Settings::get('name')) {
    echo '<div class="sp-flash warn">記録会名がまだありません。<a href="setup.php">記録会設定</a> で入れてください。</div>';
}
echo '<div class="sp-stats">'
    . '<div><small>選手(的に配置)</small><b>' . $p['archers'] . '</b> <span class="sp-sub">(' . $placed . ')</span></div>'
    . '<div><small>全射終了</small><b>' . $p['finished'] . '</b> / ' . $p['archers'] . '</div>'
    . '<div><small>入力済みの矢</small><b>' . $pct . '%</b><div class="bar"><i style="width:' . $pct . '%"></i></div></div>'
    . '<div><small>端末(直近2分)</small><b>' . $online . '</b> / ' . count($devices) . '</div>'
    . '</div>';
echo '<p class="sp-note">スコア最終更新: <b>' . h(Results::lastUpdateText()) . '</b></p>';

$item = function ($href, $icon, $title, $sub = '', $attr = '') {
    return '<a class="sp-card tap" href="' . $href . '"' . $attr . '>' . icon($icon) . '<div class="sp-card-main"><div class="sp-name">' . $title . '</div>'
        . ($sub !== '' ? '<div class="sp-sub">' . $sub . '</div>' : '') . '</div></a>';
};
echo '<div class="sp-section-title">記録会の準備</div><div class="sp-menu">'
    . $item('setup.php', 'gear', '記録会設定')
    . $item('entries.php', 'users', '選手登録')
    . $item('targets.php', 'target', '立順')
    . $item('qr.php', 'qr', '端末接続')
    . '</div>';
echo '<div class="sp-section-title">印刷・出力</div><div class="sp-menu">'
    . $item('pdf.php?type=scorecard_blank', 'print', 'スコアカード(白紙)PDF', '記入用。各カードに的のQR付き', ' target="_blank"')
    . $item('pdf.php?type=scorecard_filled', 'print', 'スコアカード(得点入り)PDF', '入力済みの得点を印字', ' target="_blank"')
    . $item('pdf.php?type=results&c=' . h($code), 'print', '結果PDF', '部門別の順位', ' target="_blank"')
    . $item('pdf.php?type=qr', 'qr', '的QR一覧PDF', '的に貼って端末で読み取る', ' target="_blank"')
    . '<div class="sp-card">' . icon('list') . '<div class="sp-card-main"><div class="sp-name">CSV</div><div class="actions" style="margin:4px 0 0">'
    . '<a class="btn sub sm" href="export.php?what=results&c=' . h($code) . '">結果</a><a class="btn sub sm" href="export.php?what=arrows">全矢</a>'
    . '<a class="btn sub sm" href="export.php?what=entries">名簿</a></div></div></div>'
    . '</div>';

page_foot('home');
