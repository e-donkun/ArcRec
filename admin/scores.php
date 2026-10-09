<?php
require __DIR__ . '/_layout.php';
require_admin();

use Lite\Entries;
use Lite\Results;
use Lite\Scoring;
use Lite\Settings;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($_POST['do'] ?? '') {
        case 'lock':
            Entries::setLocked((int)$_POST['id'], !empty($_POST['value']));
            flash(!empty($_POST['value']) ? 'スコアカードを確定しました(端末からの更新を受け付けません)' : '確定を解除しました');
            break;
        case 'lock_all':
            Entries::setAllLocked(!empty($_POST['value']));
            flash(!empty($_POST['value']) ? '全員のスコアカードを確定しました' : '全員の確定を解除しました');
            break;
    }
    redirect('scores.php' . (isset($_GET['t']) ? '?t=' . (int)$_GET['t'] : ''));
}

$total = Settings::totalArrows();
$per = Settings::arrowsPerEnd();
$nd = Settings::distances();
$rows = Entries::all();
$onlyTarget = isset($_GET['t']) ? (int)$_GET['t'] : 0;

page_head('入力状況', 'scores');
echo '<h1>入力状況</h1>';
echo '<p>スコア最終更新: <b>' . h(Results::lastUpdateText()) . '</b> <span class="muted small">— 端末から送られた得点です。この画面は自動更新されません(再読み込みしてください)。</span></p>';
echo '<div class="actions noprint"><a class="btn sub" href="pdf.php?type=scorecard_filled">スコアカード(得点入り)PDF</a>'
    . '<a class="btn sub" href="export.php?what=arrows">全矢CSV</a>'
    . '<form method="post" class="inline" onsubmit="return confirm(\'全員のスコアカードを確定します。以後、端末からの更新は拒否されます。\')">' . csrf_field() . '<input type="hidden" name="do" value="lock_all"><input type="hidden" name="value" value="1"><button class="sub">全員を確定</button></form>'
    . '<form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="do" value="lock_all"><input type="hidden" name="value" value="0"><button class="sub">全員の確定を解除</button></form></div>';

echo '<div class="tablewrap"><table><tr><th class="c">的</th><th>氏名</th><th>部門</th><th>進行</th><th class="n">射数</th>' . ($nd > 1 ? '<th class="n">' . h(Settings::distanceName(1) ?: '距離1') . '</th><th class="n">' . h(Settings::distanceName(2) ?: '距離2') . '</th>' : '') . '<th class="n">合計</th><th class="n">' . h(Settings::get('gold_label')) . '</th><th class="n">' . h(Settings::get('x_label')) . '</th><th>最終更新</th><th class="noprint"></th></tr>';
$shown = 0;
foreach ($rows as $e) {
    if ($e['status'] || ($onlyTarget && (int)$e['target'] !== $onlyTarget)) {
        continue;
    }
    $shown++;
    list($score, $g, $x, $hits) = Scoring::total(Entries::allArrows($e));
    $pct = $total ? round($hits * 100 / $total) : 0;
    $endsDone = (int)floor($hits / $per); // 全距離を通したエンド数
    echo '<tr><td class="c">' . ($e['target'] ? h($e['target'] . $e['letter']) : '-') . '</td>'
        . '<td>' . h(Entries::fullName($e)) . '</td><td>' . h($e['category_name'] ?? '') . '</td>'
        . '<td style="min-width:8rem"><div class="bar"><i style="width:' . $pct . '%"></i></div><span class="small muted">' . $endsDone . '/' . (Settings::ends() * $nd) . ' エンド</span></td>'
        . '<td class="n">' . $hits . '/' . $total . '</td>' . distCells($e, $nd) . '<td class="n"><b>' . ($hits ? $score : '') . '</b></td>'
        . '<td class="n">' . ($hits ? $g : '') . '</td><td class="n">' . ($hits ? $x : '') . '</td>'
        . '<td class="small">' . h($e['updated_at']) . ($e['signed'] ? ' 署名済' : '') . ($e['locked'] ? ' <span class="lock">確定</span>' : '') . '</td>'
        . '<td class="noprint"><a class="btn sub sm" href="score_edit.php?id=' . $e['id'] . '">訂正</a> '
        . '<form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="do" value="lock"><input type="hidden" name="id" value="' . $e['id'] . '"><input type="hidden" name="value" value="' . ($e['locked'] ? 0 : 1) . '"><button class="sm sub">' . ($e['locked'] ? '確定解除' : '確定') . '</button></form></td></tr>';
}
if (!$shown) {
    echo '<tr><td colspan="12" class="muted">対象の選手がいません</td></tr>';
}
echo '</table></div>';
page_foot();

/** 距離ごとの合計の列(2距離のときだけ) */
function distCells(array $e, $nd)
{
    if ($nd < 2) {
        return '';
    }
    $o = '';
    for ($d = 1; $d <= $nd; $d++) {
        $t = Scoring::total(Entries::arrows($e, $d));
        $o .= '<td class="n">' . ($t[3] ? $t[0] : '') . '</td>';
    }
    return $o;
}
