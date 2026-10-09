<?php
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
page_head('ホーム', 'home');
echo '<h1>ホーム</h1>';
if (!Settings::get('name')) {
    echo '<div class="flash warn">大会名がまだありません。<a href="setup.php">競技設定</a> で入れてください。</div>';
}
echo '<div class="cards">'
    . '<div class="stat"><span class="muted">選手</span><b>' . $p['archers'] . '</b><span class="small muted">的に配置 ' . $placed . '</span></div>'
    . '<div class="stat"><span class="muted">全射終了</span><b>' . $p['finished'] . ' / ' . $p['archers'] . '</b></div>'
    . '<div class="stat"><span class="muted">入力済みの矢</span><b>' . $pct . '%</b><div class="bar"><i style="width:' . $pct . '%"></i></div></div>'
    . '<div class="stat"><span class="muted">スコア最終更新</span><b style="font-size:1.1rem">' . h(Results::lastUpdateText()) . '</b></div>'
    . '<div class="stat"><span class="muted">端末(直近2分)</span><b>' . $online . ' / ' . count($devices) . '</b></div>'
    . '</div>';
echo '<div class="card"><h2 style="margin-top:0">進め方</h2><ol>'
    . '<li><a href="setup.php">競技設定</a> — 大会名・競技形式・距離ごとの的面を決める</li>'
    . '<li><a href="entries.php">選手登録</a> — 名簿を登録する(CSV取込・過去の大会から追加も可)</li>'
    . '<li><a href="targets.php">的番号</a> — 的番号と位置を設定する(タップで移動 / 一覧のプルダウン)</li>'
    . '<li><a href="pdf.php?type=scorecard_blank">スコアカード(白紙)PDF</a> を印刷して配る</li>'
    . '<li><a href="qr.php">端末接続</a> — ScorekeeperNG にサーバー設定のQRを読ませる</li>'
    . '<li><a href="scores.php">入力状況</a> で進行を確認 — 終わったら <a href="results.php?c=' . h(Settings::code()) . '">結果</a> を PDF 出力</li>'
    . '</ol></div>';
page_foot();
