<?php
require __DIR__ . '/_layout.php';
require_admin();

use Lite\ApiLog;
use Lite\Settings;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($_POST['do'] ?? '') {
        case 'on':
            Settings::set('api_log', '1');
            flash('通信ログを記録します。端末で操作してから、この画面を再読み込みしてください');
            break;
        case 'off':
            Settings::set('api_log', '0');
            flash('通信ログの記録を止めました');
            break;
        case 'clear':
            ApiLog::clear();
            flash('通信ログを消しました');
            break;
    }
    redirect('log.php');
}

$on = ApiLog::enabled();
page_head('通信ログ', 'home');
echo '<p class="sp-note">端末(ScorekeeperNG)とのやりとり</p>';
echo '<p class="muted small">端末が「どこまで進んで、どこで止まるか」を調べるためのものです。オンのあいだ、端末からの全リクエストと返事を '
    . '<code>data/api-日付.jsonl</code> に記録します(的面画像などの長い値は短くします)。原因がわかったらオフにして、ログを消してください。</p>';
echo '<div class="actions"><form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="do" value="' . ($on ? 'off' : 'on') . '"><button class="' . ($on ? 'sub' : '') . '">'
    . ($on ? '記録を止める(今: オン)' : '記録を始める(今: オフ)') . '</button></form>'
    . '<form method="post" class="inline" onsubmit="return window.confirm(\'ログを全部消しますか?\')">' . csrf_field() . '<input type="hidden" name="do" value="clear"><button class="sub">ログを消す</button></form>'
    . '<a class="btn sub" href="log.php">再読み込み</a></div>';

$rows = ApiLog::recent(40);
if (!$rows) {
    echo '<p class="muted">' . ($on ? 'まだ記録がありません。端末で操作してください。' : '記録がありません(オフです)。') . '</p>';
}
foreach ($rows as $r) {
    $reqs = $r['request'];
    if (isset($reqs['action'])) {
        $reqs = [$reqs];
    }
    $acts = [];
    foreach ((array)$reqs as $q) {
        $acts[] = is_array($q) ? ($q['action'] ?? '?') : '?';
    }
    $resp = $r['response'];
    $ras = [];
    foreach (($resp['responses'] ?? []) as $x) {
        $ras[] = is_array($x) ? ($x['action'] ?? '?') . (isset($x['error']) && $x['error'] ? '(error ' . $x['error'] . ')' : '') : (string)$x;
    }
    $bad = !empty($resp['error']);
    echo '<details class="card" style="padding:.5rem 1rem"><summary><span class="small muted">' . h($r['time']) . ' ' . h($r['ip']) . '</span> '
        . '<b>' . h(implode(', ', $acts)) . '</b> → ' . h(implode(', ', $ras) ?: '(応答なし)')
        . ($bad ? ' <span class="err">エラー: ' . h($resp['errorMsg'] ?? '') . '</span>' : '') . '</summary>'
        . '<p class="small muted" style="margin:.4rem 0 0">リクエスト</p><pre style="overflow:auto;max-height:18rem">' . h(json_encode($r['request'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>'
        . '<p class="small muted" style="margin:.4rem 0 0">レスポンス</p><pre style="overflow:auto;max-height:24rem">' . h(json_encode($r['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre></details>';
}
page_foot('home');
