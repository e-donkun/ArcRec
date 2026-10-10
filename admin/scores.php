<?php
// 点数管理(端末から届いた得点の確認・素点の編集・全員の確定)。スマホモードのカード一覧と同じ作り
require __DIR__ . '/_layout.php';
require_admin();

use Lite\Entries;
use Lite\Results;
use Lite\Scoring;
use Lite\Settings;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($_POST['do'] ?? '') {
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

/** 「15分前」のような表示 */
function ago($at)
{
    $sec = max(0, time() - strtotime($at));
    if ($sec < 60) {
        return 'たった今';
    }
    if ($sec < 3600) {
        return floor($sec / 60) . '分前';
    }
    if ($sec < 86400) {
        return floor($sec / 3600) . '時間前';
    }
    return date('n/j', strtotime($at));
}

page_head('点数管理', 'scores');
?>
<div class="sp-updated">
	<span class="sp-note">スコア最終更新: <b><?php echo h(Results::lastUpdateText()); ?></b></span>
	<button type="button" class="sp-btn sub" onclick="location.reload()" aria-label="再読み込み" title="再読み込み"><?php echo icon('reload'); ?></button>
</div>
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
$t1 = Settings::tieLabel(1);
$t2 = Settings::tieLabel(2);
foreach (Entries::all() as $e) {
    if ($e['status']) {
        continue;
    }
    list($score, $g, $x, $hits) = Scoring::total(Entries::allArrows($e));
    $pct = $total ? round($hits * 100 / $total) : 0;
    $chips = [];
    if ($nd > 1) {
        for ($d = 1; $d <= $nd; $d++) {
            $t = Scoring::total(Entries::arrows($e, $d));
            $chips[] = h(Settings::distanceName($d) ?: '距離' . $d) . ' ' . ($t[3] ? $t[0] : '-');
        }
    }
    $chips[] = (int)floor($hits / $per) . '/' . $ends . 'エンド';
    $chips[] = $hits . '/' . $total . '射';
    $pos = $e['target'] ? $e['target'] . $e['letter'] : '';
    $search = mb_strtolower(implode(' ', [Entries::fullName($e), $e['family_name'] . $e['given_name'], $e['affiliation'], $e['category_name'], $pos]));
    echo '<div class="sp-card sp-score-card" data-cat="' . (int)$e['category_id'] . '" data-done="' . ($hits >= $total ? 1 : 0) . '" data-search="' . h($search) . '">'
        . '<div class="sp-card-head"><span class="sp-badge' . ($pos ? '' : ' none') . '">' . h($pos ?: '-') . '</span><div class="sp-card-main">'
        . '<div class="sp-name">' . h(Entries::fullName($e)) . '<span class="sp-tag">' . h($e['category_name']) . '</span>' . ($e['locked'] ? '<span class="sp-tag warn">確定</span>' : '') . '</div>'
        . '<div class="sp-sub">' . h($e['affiliation']) . '</div></div>'
        . '<div class="sp-score">' . ($hits ? '<b>' . $score . '</b><div class="sp-sub">' . h($t1) . ':' . $g . ' ' . h($t2) . ':' . $x . '</div>' : '') . '</div>'
        . '<a class="sp-btn sub sp-edit-btn" href="score_edit.php?id=' . $e['id'] . '">編集</a></div>'
        . '<div class="sp-progress"><span>' . ($e['updated_at'] ? '入力:' . h(ago($e['updated_at'])) : '未入力') . '</span><div class="bar"><i style="width:' . $pct . '%"></i></div></div>'
        . '<div class="sp-dists">' . implode('', array_map(function ($c) {
            return '<span>' . $c . '</span>';
        }, $chips)) . '</div>'
        . '</div>';
}
?>
</div>
<p class="sp-note">「全員を確定」すると、端末からの入力がブロックされます。確定を解除した場合でも、端末は QR コードから読み込み直す必要があります。</p>
<div class="actions noprint">
	<form method="post" class="inline" onsubmit="return window.confirm('全員のスコアカードを確定します。\n確定すると、端末からの入力がブロックされます。確定を解除した場合でも、端末は QR コードから読み込み直す必要があります。\nよろしいですか?')"><?php echo csrf_field(); ?><input type="hidden" name="do" value="lock_all"><input type="hidden" name="value" value="1"><button class="sp-btn sub">全員を確定</button></form>
	<form method="post" class="inline" onsubmit="return window.confirm('全員の確定を解除します。\n解除しても、端末は QR コードから読み込み直す必要があります。\nよろしいですか?')"><?php echo csrf_field(); ?><input type="hidden" name="do" value="lock_all"><input type="hidden" name="value" value="0"><button class="sp-btn sub">全員の確定を解除</button></form>
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
