<?php
require __DIR__ . '/_layout.php';
require_admin();

use Lite\Entries;
use Lite\Scoring;
use Lite\Settings;

$e = Entries::find((int)($_GET['id'] ?? $_POST['id'] ?? 0));
if (!$e) {
    flash('選手が見つかりません', 'err');
    redirect('scores.php');
}
$per = Settings::arrowsPerEnd();
$ends = Settings::ends();
$nd = Settings::distances();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // まず全距離を検証して、1つでも不正なら何も保存しない
    $new = [];
    $bad = [];
    for ($d = 1; $d <= $nd; $d++) {
        $str = '';
        for ($n = 1; $n <= $ends; $n++) {
            $s = Scoring::parseInput(trim($_POST['end'][$d][$n] ?? ''));
            if ($s === null || strlen($s) > $per) {
                $bad[] = '距離' . $d . ' エンド' . $n;
                $s = '';
            }
            $str .= Scoring::pad($s, $per);
        }
        $new[$d] = $str;
    }
    if ($bad) {
        flash(implode(', ', $bad) . ' の入力が不正です(例: X 10 9 8 7 M)。何も保存していません。', 'err');
        redirect('score_edit.php?id=' . $e['id']);
    }
    foreach ($new as $d => $str) {
        Entries::replaceArrows($e['id'], $str, 'admin', $d);
    }
    flash('得点を訂正しました');
    redirect('scores.php');
}

page_head('得点の訂正', 'scores');
echo '<h1>得点の訂正 <span class="muted small">' . h(($e['target'] ? $e['target'] . $e['letter'] . ' ' : '') . Entries::fullName($e)) . '</span></h1>';
if ($e['locked']) {
    echo '<div class="flash warn">このスコアカードは確定済みです(管理画面からは訂正できます)。</div>';
}
echo '<p class="muted small">各エンドを <code>X 10 9 8 7 M</code> のように空白かカンマで区切って入力します(X=インナー10、M=ミス)。空欄は未射です。</p>';
echo '<form method="post" class="card">' . csrf_field() . '<input type="hidden" name="id" value="' . $e['id'] . '"><div class="row">';
for ($d = 1; $d <= $nd; $d++) {
    $arrows = Entries::arrows($e, $d);
    echo '<div style="flex:0 1 auto"><b>' . ($nd > 1 ? h(Settings::distanceName($d) ?: '距離' . $d) : h(Settings::distanceName(1) ?: '得点')) . '</b>'
        . '<table style="width:auto"><tr><th>エンド</th><th>' . $per . '射</th><th class="n">小計</th></tr>';
    for ($n = 1; $n <= $ends; $n++) {
        $s = Scoring::endString($arrows, $n, $per);
        $disp = [];
        foreach (str_split(rtrim($s)) as $c) {
            $disp[] = trim($c) === '' ? '' : Scoring::display($c);
        }
        $sub = trim($s) === '' ? '' : Scoring::total($s)[0];
        echo '<tr><td class="c">' . $n . '</td><td><input type="text" class="endin" name="end[' . $d . '][' . $n . ']" value="' . h(implode(' ', $disp)) . '"></td><td class="n">' . $sub . '</td></tr>';
    }
    echo '</table></div>';
}
echo '</div><p><button>保存</button> <a class="btn sub" href="scores.php">戻る</a></p></form>';

$log = Entries::log($e['id'], 40);
if ($log) {
    echo '<h2>更新履歴(新しい順)</h2><div class="tablewrap"><table style="width:auto"><tr><th>日時</th>' . ($nd > 1 ? '<th>距離</th>' : '') . '<th>エンド</th><th>内容</th><th>送信元</th></tr>';
    foreach ($log as $l) {
        $disp = [];
        foreach (str_split($l['arrows']) as $c) {
            $disp[] = trim($c) === '' ? '-' : Scoring::display($c);
        }
        echo '<tr><td class="small">' . h($l['at']) . '</td>' . ($nd > 1 ? '<td class="c">' . (int)$l['distance'] . '</td>' : '')
            . '<td class="c">' . (int)$l['end_no'] . '</td><td>' . h(implode(' ', $disp)) . '</td><td class="small">' . h($l['source'] === 'device' ? '端末 ' . substr($l['device'], 0, 8) : $l['source']) . '</td></tr>';
    }
    echo '</table></div>';
}
page_foot();
