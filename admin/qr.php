<?php
require __DIR__ . '/_layout.php';
require_admin();

use Lite\Connect;
use Lite\Devices;
use Lite\Entries;
use Lite\Settings;

// 的のQR(SVG 画像)。?img=1&t=的番号&d=距離(0 = 手動)
if (isset($_GET['img'])) {
    header('Content-Type: image/svg+xml');
    header('Cache-Control: no-store');
    echo Connect::svg(Connect::targetPayload(server_base_url(), (int)($_GET['t'] ?? 0), Connect::mode($_GET['d'] ?? 0)), false);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'forget') {
    Devices::forget($_POST['uuid'] ?? '');
    flash('端末の登録を削除しました(端末は次回接続時に再登録されます)');
    redirect('qr.php');
}

$base = server_base_url();
$host = parse_url($base, PHP_URL_HOST);
page_head('端末設定', 'qr');
if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
    echo '<div class="sp-flash warn">今開いている URL は <code>' . h($host) . '</code> です。タブレットからは届きません。'
        . 'このサーバーのLAN上のアドレス(例 <code>http://192.168.0.10:8080/</code>)で管理画面を開き直すと、そのアドレスのQRになります。</div>';
}
$targets = Entries::targets();
$modes = Connect::distanceModes();
$mode = Connect::defaultMode();
echo '<div class="row"><div class="card"><h2 style="margin-top:0">設定用QRコード</h2>'
    . '<p class="muted small">ScorekeeperNG の設定画面(モード: <b>Lite</b>)でこの QR を読み取ります。</p>'
    . '<div class="sp-qr">' . Connect::svg(Connect::setupPayload($base)) . '</div>'
    . '<p>サーバーURL: <code>' . h($base) . '</code><br>記録会コード: <code>' . h(Settings::code()) . '</code></p></div>';
echo '<div class="card"><h2 style="margin-top:0">設定用(的ごと)QRコード</h2>';
if (count($modes) > 1) {
    // 距離の選び方(前半 / 後半 / 指定無し)。1距離なら切り替えなし
    echo '<div class="sp-chips" id="QrModes">';
    foreach ($modes as $d => $label) {
        echo '<button type="button" class="sp-chip' . ($d === $mode ? ' active' : '') . '" data-d="' . $d . '">' . h($label) . '</button>';
    }
    echo '</div>';
}
if ($targets) {
    echo '<div class="sp-qr-carousel">'
        . '<button type="button" class="sp-qr-nav" id="QrPrev" aria-label="前の的">' . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15.4 4.6 8 12l7.4 7.4-1.4 1.4L5.2 12l8.8-8.8z"/></svg></button>'
        . '<div class="sp-qr-main"><img id="QrImg" class="sp-qr" alt="的のQR" src="qr.php?img=1&amp;t=' . (int)$targets[0] . '&amp;d=' . $mode . '">'
        . '<div class="sp-qr-label" id="QrLabel">' . h(Connect::targetLabel($targets[0], $mode)) . '</div>'
        . '<div class="sp-sub" id="QrCount">1 / ' . count($targets) . '</div></div>'
        . '<button type="button" class="sp-qr-nav" id="QrNext" aria-label="次の的">' . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8.6 4.6 16 12l-7.4 7.4 1.4 1.4 8.8-8.8L10 3.2z"/></svg></button>'
        . '</div>';
} else {
    echo '<div class="sp-empty">立順が決まった的がまだありません</div>';
}
echo '<p><a class="sp-btn" id="QrPdf" href="pdf.php?type=qr&amp;d=' . $mode . '" target="_blank">' . icon('print') . '的QR一覧 PDF</a></p>'
    . '</div></div>';
?>
<script>
(function () {
	const targets = <?php echo json_encode(array_values(array_map('intval', $targets))); ?>;
	const names = <?php echo json_encode(array_map('strval', $modes), JSON_UNESCAPED_UNICODE); ?>;
	let i = 0, d = <?php echo (int)$mode; ?>;
	function label(t) { const n = d > 0 ? (names[d] || '') : ''; return t + '的' + (n ? ' ' + n : ''); }
	function show() {
		document.getElementById('QrPdf').href = 'pdf.php?type=qr&d=' + d;
		if (!targets.length) return;
		const t = targets[i];
		document.getElementById('QrImg').src = 'qr.php?img=1&t=' + t + '&d=' + d;
		document.getElementById('QrLabel').textContent = label(t);
		document.getElementById('QrCount').textContent = (i + 1) + ' / ' + targets.length;
	}
	const prev = document.getElementById('QrPrev'), next = document.getElementById('QrNext');
	if (prev) prev.onclick = () => { i = (i + targets.length - 1) % targets.length; show(); };
	if (next) next.onclick = () => { i = (i + 1) % targets.length; show(); };
	const modes = document.getElementById('QrModes');
	if (modes) modes.addEventListener('click', ev => {
		const b = ev.target.closest('[data-d]');
		if (!b) return;
		d = +b.dataset.d;
		modes.querySelectorAll('[data-d]').forEach(x => x.classList.toggle('active', x === b));
		show();
	});
})();
</script>
<?php

echo '<h2 class="sp-h-link">登録された端末 <a class="small" href="log.php">通信ログ</a></h2><div class="tablewrap"><table><tr><th>コード</th><th>的</th><th>電池</th><th>アプリ</th><th>最終通信</th><th>設定確認</th><th></th></tr>';
foreach (Devices::all() as $d) {
    $age = time() - strtotime($d['last_seen']);
    $b = (int)$d['battery'];
    echo '<tr><td><code>' . h($d['code']) . '</code></td><td class="c">' . ($d['target'] ?: '-') . '</td>'
        . '<td>' . ($b ? abs($b) . '%' . ($b < 0 ? ' 充電中' : '') : '-') . '</td><td class="small">' . h($d['version']) . '</td>'
        . '<td class="small ' . ($age < 120 ? 'ok' : 'muted') . '">' . h($d['last_seen']) . ($age < 120 ? ' ●' : '') . '</td>'
        . '<td>' . ($d['setup_confirmed'] ? '済' : '-') . '</td>'
        . '<td><form method="post" class="inline" onsubmit="return window.confirm(\'この端末の登録を削除しますか?\')">' . csrf_field() . '<input type="hidden" name="do" value="forget"><input type="hidden" name="uuid" value="' . h($d['uuid']) . '"><button class="sm sub">削除</button></form></td></tr>';
}
if (!Devices::all()) {
    echo '<tr><td colspan="7" class="muted">まだ端末が接続していません</td></tr>';
}
echo '</table></div>';
page_foot('qr');
