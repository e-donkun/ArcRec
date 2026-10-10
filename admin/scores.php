<?php
// 入力状況(端末から届いた得点の確認・訂正・確定)。スマホモードのカード一覧と同じ作り
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
$ends = Settings::ends() * $nd;
$cats = Lite\Categories::all();

page_head('入力状況', 'scores');
echo '<p class="sp-note">スコア最終更新: <b>' . h(Results::lastUpdateText()) . '</b>(端末から届いた得点。再読み込みで最新になります)</p>';
?>
<div class="sp-toolbar">
	<label class="sp-search"><?php echo icon('search'); ?><input type="search" id="SpSearch" placeholder="名前・所属・的番号で検索"></label>
	<div class="sp-chips" id="SpCatChips">
		<button type="button" class="sp-chip active" data-cat="">すべて</button>
<?php foreach ($cats as $c) { ?>
		<button type="button" class="sp-chip" data-cat="<?php echo (int)$c['id']; ?>"><?php echo h($c['name']); ?></button>
<?php } ?>
	</div>
	<label class="sp-switch"><input type="checkbox" id="SpOnlyOpen"> 終わっていない人だけ</label>
</div>
<div class="sp-count" id="SpCount"></div>
<div class="sp-list" id="SpList">
<?php
foreach (Entries::all() as $e) {
    if ($e['status']) {
        continue;
    }
    list($score, $g, $x, $hits) = Scoring::total(Entries::allArrows($e));
    $pct = $total ? round($hits * 100 / $total) : 0;
    $dist = [];
    if ($nd > 1) {
        for ($d = 1; $d <= $nd; $d++) {
            $t = Scoring::total(Entries::arrows($e, $d));
            $dist[] = '<span>' . h(Settings::distanceName($d) ?: '距離' . $d) . ' ' . ($t[3] ? $t[0] : '-') . '</span>';
        }
    }
    $pos = $e['target'] ? $e['target'] . $e['letter'] : '';
    $search = mb_strtolower(implode(' ', [Entries::fullName($e), $e['family_name'] . $e['given_name'], $e['affiliation'], $e['category_name'], $pos]));
    echo '<div class="sp-card" data-cat="' . (int)$e['category_id'] . '" data-done="' . ($hits >= $total ? 1 : 0) . '" data-search="' . h($search) . '">'
        . '<div class="sp-card-head"><span class="sp-badge' . ($pos ? '' : ' none') . '">' . h($pos ?: '-') . '</span><div class="sp-card-main">'
        . '<div class="sp-name">' . h(Entries::fullName($e)) . '<span class="sp-tag">' . h($e['category_name']) . '</span></div>'
        . '<div class="sp-sub">' . h($e['affiliation']) . '</div></div>'
        . '<div class="sp-score">' . ($hits ? '<b>' . $score . '</b><div class="sp-sub">' . h(Settings::tieLabel(1)) . ':' . $g . ' ' . h(Settings::tieLabel(2)) . ':' . $x . '</div>' : '') . '</div></div>'
        . '<div class="bar" style="margin-top:8px"><i style="width:' . $pct . '%"></i></div>'
        . '<div class="sp-dists">' . implode('', $dist) . '<span>' . (int)floor($hits / $per) . '/' . $ends . 'エンド</span><span>' . $hits . '/' . $total . '射</span></div>'
        . '<div class="sp-row" style="margin-top:8px;align-items:center"><div class="sp-sub" style="white-space:normal">'
        . ($e['updated_at'] ? '更新 ' . h(substr($e['updated_at'], 5, 11)) : '未入力')
        . ($e['signed'] ? ' <span class="sp-tag ok">署名済</span>' : '') . ($e['locked'] ? ' <span class="sp-tag warn">確定</span>' : '') . '</div>'
        . '<a class="sp-btn sub" style="flex:none;padding:6px 12px" href="score_edit.php?id=' . $e['id'] . '">訂正</a>'
        . '<form method="post" style="flex:none">' . csrf_field() . '<input type="hidden" name="do" value="lock"><input type="hidden" name="id" value="' . $e['id'] . '"><input type="hidden" name="value" value="' . ($e['locked'] ? 0 : 1) . '">'
        . '<button class="sp-btn sub" style="padding:6px 12px">' . ($e['locked'] ? '確定解除' : '確定') . '</button></form>'
        . '</div></div>';
}
?>
</div>
<p class="sp-note">「確定」したスコアカードは、端末からの更新を受け付けません(管理画面からの訂正はできます)。</p>
<div class="actions noprint">
	<a class="sp-btn sub" href="pdf.php?type=scorecard_filled" target="_blank"><?php echo icon('print'); ?>スコアカード(得点入り)PDF</a>
	<a class="sp-btn sub" href="export.php?what=arrows">全矢CSV</a>
	<form method="post" class="inline" onsubmit="return window.confirm('全員のスコアカードを確定します。以後、端末からの更新は拒否されます。')"><?php echo csrf_field(); ?><input type="hidden" name="do" value="lock_all"><input type="hidden" name="value" value="1"><button class="sp-btn sub">全員を確定</button></form>
	<form method="post" class="inline"><?php echo csrf_field(); ?><input type="hidden" name="do" value="lock_all"><input type="hidden" name="value" value="0"><button class="sp-btn sub">全員の確定を解除</button></form>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
	let cat = '';
	const only = document.getElementById('SpOnlyOpen');
	function filter() {
		const n = spFilterCards(document.getElementById('SpList'), document.getElementById('SpSearch').value,
			c => (!cat || c.dataset.cat === cat) && (!only.checked || c.dataset.done === '0'));
		document.getElementById('SpCount').textContent = n + ' 人';
	}
	document.getElementById('SpSearch').addEventListener('input', filter);
	only.addEventListener('change', filter);
	document.getElementById('SpCatChips').addEventListener('click', ev => {
		const b = ev.target.closest('[data-cat]');
		if (!b) return;
		cat = b.dataset.cat;
		document.querySelectorAll('#SpCatChips [data-cat]').forEach(x => x.classList.toggle('active', x === b));
		filter();
	});
	filter();
});
</script>
<?php
page_foot('scores');
