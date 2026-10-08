<?php
require __DIR__ . '/_layout.php';
require_admin();

use Lite\Connect;
use Lite\Devices;
use Lite\Entries;
use Lite\Settings;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'forget') {
    Devices::forget($_POST['uuid'] ?? '');
    flash('端末の登録を削除しました(端末は次回接続時に再登録されます)');
    redirect('qr.php');
}

$base = server_base_url();
$host = parse_url($base, PHP_URL_HOST);
page_head('端末接続', 'qr');
echo '<h1>端末接続</h1>';
if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
    echo '<div class="flash warn">今開いている URL は <code>' . h($host) . '</code> です。タブレットからは届きません。'
        . '<a href="setup.php">競技設定</a> の「サーバーURL」にこのPCのLAN上のアドレス(例 <code>http://192.168.0.10:8080/</code>)を入れてください。</div>';
}
echo '<div class="row"><div class="card"><h2 style="margin-top:0">1. 接続設定のQR</h2>'
    . '<p class="muted small">ScorekeeperNG の設定画面(モード: <b>Lite</b>)でこの QR を読み取ります。</p>'
    . '<div style="max-width:240px;background:#fff;padding:8px;border-radius:8px">' . Connect::svg(Connect::setupPayload($base)) . '</div>'
    . '<p>サーバーURL: <code>' . h($base) . '</code><br>大会コード: <code>' . h(Settings::get('code')) . '</code>'
    . '<br>PIN: <code>' . h(Settings::get('pin') ?: '(なし)') . '</code></p>'
    . '<p class="muted small">QRが使えないときは、アプリの設定に上の3項目を手入力します。</p></div>';
echo '<div class="card"><h2 style="margin-top:0">2. 的のQR</h2>'
    . '<p class="muted small">各的に貼っておくと、読み取るだけでその的の選手が端末に出ます。スコアカードにも的のQRを印刷しています。(手入力でも的番号を選べます)</p>'
    . '<p><a class="btn" href="pdf.php?type=qr">的QR一覧 PDF</a></p>'
    . '<p class="muted small">対象の的: ' . h(implode(', ', Entries::targets()) ?: 'まだありません') . '</p></div></div>';

echo '<h2>登録された端末</h2><div class="tablewrap"><table><tr><th>コード</th><th>的</th><th>電池</th><th>アプリ</th><th>最終通信</th><th>設定確認</th><th></th></tr>';
foreach (Devices::all() as $d) {
    $age = time() - strtotime($d['last_seen']);
    $b = (int)$d['battery'];
    echo '<tr><td><code>' . h($d['code']) . '</code></td><td class="c">' . ($d['target'] ?: '-') . '</td>'
        . '<td>' . ($b ? abs($b) . '%' . ($b < 0 ? ' 充電中' : '') : '-') . '</td><td class="small">' . h($d['version']) . '</td>'
        . '<td class="small ' . ($age < 120 ? 'ok' : 'muted') . '">' . h($d['last_seen']) . ($age < 120 ? ' ●' : '') . '</td>'
        . '<td>' . ($d['setup_confirmed'] ? '済' : '-') . '</td>'
        . '<td><form method="post" class="inline" onsubmit="return confirm(\'この端末の登録を削除しますか?\')">' . csrf_field() . '<input type="hidden" name="do" value="forget"><input type="hidden" name="uuid" value="' . h($d['uuid']) . '"><button class="sm sub">削除</button></form></td></tr>';
}
if (!Devices::all()) {
    echo '<tr><td colspan="7" class="muted">まだ端末が接続していません</td></tr>';
}
echo '</table></div>';
page_foot();
