<?php
require __DIR__ . '/_layout.php';

use Lite\Entries;
use Lite\Results;
use Lite\Settings;

// 結果は参加者にも見せられるよう、ログイン不要で閲覧できる。
$public = !Lite\Auth::check();
$groups = Results::byCategory();
$p = Results::progress();
$ends = Settings::ends();
$per = Settings::arrowsPerEnd();

if ($public) {
    header('Refresh: 30');
}
page_head('結果', 'results', true);
echo '<h1>予選結果 <span class="muted small">' . h(Settings::get('distance_name')) . ' ' . Settings::totalArrows() . '射 / 進行 ' . $p['finished'] . '/' . $p['archers'] . ' 人終了</span></h1>';
echo '<div class="actions noprint"><a class="btn" href="pdf.php?type=results">結果PDF</a>'
    . '<a class="btn sub" href="export.php?what=results">結果CSV</a><a class="btn sub" href="results.php">更新</a></div>';
if (!$public) {
    echo '<p class="muted small noprint">この画面は自動更新されません。ログインしていない人にも結果の閲覧は公開されます(30秒ごとに自動更新)。</p>';
}
$any = false;
foreach ($groups as $g) {
    if (!$g['rows']) {
        continue;
    }
    $any = true;
    echo '<h2>' . h($g['category']['name']) . '</h2><div class="tablewrap"><table><tr><th class="c">順位</th><th class="c">的</th><th>氏名</th><th>所属</th>';
    for ($i = 1; $i <= $ends; $i++) {
        echo '<th class="n small">' . $i . '</th>';
    }
    echo '<th class="n">合計</th><th class="n">' . h(Settings::get('gold_label')) . '</th><th class="n">' . h(Settings::get('x_label')) . '</th></tr>';
    foreach ($g['rows'] as $r) {
        echo '<tr><td class="c">' . ($r['rank'] === null ? '' : $r['rank'] . ($r['tie'] ? '*' : '')) . '</td><td class="c">' . ($r['target'] ? h($r['target'] . $r['letter']) : '') . '</td>'
            . '<td>' . h(Entries::fullName($r)) . '</td><td>' . h($r['affiliation']) . '</td>';
        foreach ($r['end_scores'] as $sc) {
            echo '<td class="n small muted">' . ($sc === null ? '' : $sc) . '</td>';
        }
        echo '<td class="n"><b>' . ($r['hits'] ? $r['score'] : '') . '</b></td><td class="n">' . ($r['hits'] ? $r['golds'] : '') . '</td><td class="n">' . ($r['hits'] ? $r['xs'] : '') . '</td></tr>';
    }
    echo '</table></div>';
}
if (!$any) {
    echo '<p class="muted">まだ結果がありません。</p>';
}
echo '<p class="muted small">* 合計・' . h(Settings::get('gold_label')) . '・' . h(Settings::get('x_label')) . ' がすべて同じ選手がいます(シュートオフ等で決めてください)。</p>';
page_foot();
