<?php
// 得点の訂正。ScorekeeperNG と同じく、矢の枠をタップして下のボタン(的面の色)で点を入れる。
//   2距離なら 前半/後半 を切り替え。「保存」で全距離をまとめて保存(更新履歴が残る)
require __DIR__ . '/_layout.php';
require_admin();

use Lite\Entries;
use Lite\Scoring;
use Lite\Settings;
use Lite\TargetFaces;

$e = Entries::find((int)($_GET['id'] ?? $_POST['id'] ?? 0));
if (!$e) {
    flash('選手が見つかりません', 'err');
    redirect('scores.php');
}
$per = Settings::arrowsPerEnd();
$ends = Settings::ends();
$nd = Settings::distances();
$total = Settings::arrowsPerDistance();

// この記録会の的面で使える点(ボタン)。高い順: X → 10 → … → M
$lp = Scoring::letterPoint();
$keys = [];
foreach (TargetFaces::iskFace(Settings::targetFace(), false)['letterPoint'] as $k) {
    $keys[] = ['l' => $k['letter'], 'p' => $k['point'], 'n' => (int)$k['num'], 'bg' => $k['bg'], 'fg' => $k['fg'], 'w' => (int)($lp[$k['letter']]['W'] ?? 0)];
}
usort($keys, function ($a, $b) {
    return $b['w'] <=> $a['w'];
});
$allowed = implode('', array_column($keys, 'l'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // まず全距離を検証して、1つでも不正なら何も保存しない
    $new = [];
    for ($d = 1; $d <= $nd; $d++) {
        $s = strtoupper((string)($_POST['arrows'][$d] ?? ''));
        if (strlen($s) > $total || !preg_match('/^[' . preg_quote($allowed, '/') . ' ]*$/', $s)) {
            flash('入力が正しくありません。何も保存していません。', 'err');
            redirect('score_edit.php?id=' . $e['id']);
        }
        $new[$d] = Scoring::pad($s, $total);
    }
    foreach ($new as $d => $str) {
        Entries::replaceArrows($e['id'], $str, 'admin', $d);
    }
    flash('得点を訂正しました');
    redirect('score_edit.php?id=' . $e['id']);
}

$dists = [];
for ($d = 1; $d <= $nd; $d++) {
    $dists[] = ['name' => Settings::distanceName($d) ?: ($nd > 1 ? '距離' . $d : ''), 'arrows' => Scoring::pad(strtoupper(Entries::arrows($e, $d)), $total)];
}
$pos = $e['target'] ? $e['target'] . $e['letter'] : '';

page_head('得点の訂正', 'scores', ['body' => 'sp-has-keypad sp-score-edit', 'js' => [
    'LiteSc' => [
        'per' => $per, 'ends' => $ends, 'dists' => $dists, 'keys' => $keys,
        'tie1' => Settings::tieChars(1), 'tie2' => Settings::tieChars(2),
        'tie1Label' => Settings::tieLabel(1), 'tie2Label' => Settings::tieLabel(2),
    ],
]]);
if ($e['locked']) {
    echo '<div class="sp-flash warn">このスコアカードは確定済みです(管理画面からは訂正できます)。</div>';
}
echo '<div class="sp-card"><div class="sp-card-head"><span class="sp-badge' . ($pos ? '' : ' none') . '">' . h($pos ?: '-') . '</span><div class="sp-card-main">'
    . '<div class="sp-name">' . h(Entries::fullName($e)) . '<span class="sp-tag">' . h($e['category_name']) . '</span></div>'
    . '<div class="sp-sub">' . h($e['affiliation']) . '</div></div></div>'
    . '<div class="sp-totals" id="ScTotals"></div></div>';
if ($nd > 1) {
    echo '<div class="sp-chips" id="ScDists" style="margin-top:8px">';
    foreach ($dists as $i => $d) {
        echo '<button type="button" class="sp-chip' . ($i === 0 ? ' active' : '') . '" data-d="' . $i . '">' . h($d['name']) . '</button>';
    }
    echo '</div>';
}
echo '<table class="sp-sc sp-sc-edit" id="ScTable"></table>';
echo '<form method="post" id="ScForm">' . csrf_field() . '<input type="hidden" name="id" value="' . $e['id'] . '">';
for ($d = 1; $d <= $nd; $d++) {
    echo '<input type="hidden" name="arrows[' . $d . ']" id="ScArrows' . $d . '">';
}
echo '</form>';

$log = Entries::log($e['id'], 40);
if ($log) {
    echo '<details class="sp-advanced"><summary>更新履歴(新しい順)</summary><div class="tablewrap"><table style="width:auto"><tr><th>日時</th>' . ($nd > 1 ? '<th>距離</th>' : '') . '<th>エンド</th><th>内容</th><th>送信元</th></tr>';
    foreach ($log as $l) {
        $disp = [];
        foreach (str_split($l['arrows']) as $c) {
            $disp[] = trim($c) === '' ? '-' : Scoring::display($c);
        }
        echo '<tr><td class="small">' . h($l['at']) . '</td>' . ($nd > 1 ? '<td class="c">' . (int)$l['distance'] . '</td>' : '')
            . '<td class="c">' . (int)$l['end_no'] . '</td><td>' . h(implode(' ', $disp)) . '</td><td class="small">' . h($l['source'] === 'device' ? '端末 ' . substr($l['device'], 0, 8) : $l['source']) . '</td></tr>';
    }
    echo '</table></div></details>';
}
?>
<div class="sp-keypad sp-sc-keypad" id="ScPad"></div>
<script>
(function () {
	const C = LiteSc;
	const pts = {}, disp = {};
	C.keys.forEach(k => { pts[k.l] = k.n; disp[k.l] = k.p; });
	const model = C.dists.map(d => d.arrows.split(''));   // 距離ごとの矢(1文字1本、空白は未入力)
	let dist = 0, sel = 0, dirty = false;

	function endSum(d, e) {
		let s = 0, any = false;
		for (let j = 0; j < C.per; j++) {
			const c = model[d][e * C.per + j];
			if (c && c !== ' ') { s += pts[c] || 0; any = true; }
		}
		return any ? s : null;
	}
	function count(chars) {
		let n = 0;
		model.forEach(a => a.forEach(c => { if (c !== ' ' && chars.indexOf(c) >= 0) n++; }));
		return n;
	}
	function render() {
		// 表: エンド | 矢 | 小計 | 合計(ScorekeeperNG と同じ並び)
		let h = '<colgroup><col style="width:26px">' + '<col>'.repeat(C.per) + '<col style="width:40px"><col style="width:44px"></colgroup><thead><tr><th></th>';
		for (let j = 1; j <= C.per; j++) h += '<th>' + j + '</th>';
		h += '<th>小計</th><th>合計</th></tr></thead><tbody>';
		let run = 0;
		for (let e = 0; e < C.ends; e++) {
			const s = endSum(dist, e);
			if (s !== null) run += s;
			h += '<tr><td class="no">' + (e + 1) + '</td>';
			for (let j = 0; j < C.per; j++) {
				const i = e * C.per + j, c = model[dist][i];
				h += '<td><button type="button" class="sp-cell' + (i === sel ? ' sel' : '') + '" data-i="' + i + '">' + (c && c !== ' ' ? spEsc(disp[c] || c) : '') + '</button></td>';
			}
			h += '<td class="tot">' + (s === null ? '' : s) + '</td><td class="run">' + (s === null ? '' : run) + '</td></tr>';
		}
		document.getElementById('ScTable').innerHTML = h + '</tbody>';
		// 合計(全距離)と優先順位の本数
		let all = 0, dsum = [];
		model.forEach((a, d) => { let t = 0; for (let e = 0; e < C.ends; e++) t += endSum(d, e) || 0; dsum.push(t); all += t; });
		let tot = '';
		if (C.dists.length > 1) C.dists.forEach((d, i) => { tot += '<div><small>' + spEsc(d.name) + '</small><b>' + dsum[i] + '</b></div>'; });
		tot += '<div><small>合計</small><b>' + all + '</b></div>'
			+ '<div><small>' + spEsc(C.tie1Label) + '</small><b>' + count(C.tie1) + '</b></div>'
			+ '<div><small>' + spEsc(C.tie2Label) + '</small><b>' + count(C.tie2) + '</b></div>';
		const t = document.getElementById('ScTotals');
		t.innerHTML = tot;
		t.style.gridTemplateColumns = 'repeat(' + (C.dists.length > 1 ? 5 : 3) + ', 1fr)';
	}
	function select(i) {
		const n = C.ends * C.per;
		sel = Math.max(0, Math.min(n - 1, i));
		render();
		const cell = document.querySelector('.sp-cell.sel');
		const pad = document.getElementById('ScPad').getBoundingClientRect();
		const r = cell.getBoundingClientRect();
		if (r.bottom > pad.top - 8 || r.top < 70) cell.scrollIntoView({block: 'center', behavior: 'smooth'});
	}
	function put(c) {
		model[dist][sel] = c;
		dirty = true;
		select(c === ' ' ? sel : sel + 1);
	}
	// キーパッド: 的面の色の点ボタン(3列)+ 素点削除・エンド削除・保存
	const pad = document.getElementById('ScPad');
	pad.innerHTML = C.keys.map(k => '<button type="button" data-k="' + k.l + '" style="background:' + k.bg + ';color:' + k.fg + '">' + spEsc(k.p) + '</button>').join('')
		+ '<button type="button" class="fn" data-fn="del">素点削除</button>'
		+ '<button type="button" class="fn" data-fn="end">エンド削除</button>'
		+ '<button type="button" class="fn save" data-fn="save">保存</button>';
	pad.addEventListener('click', ev => {
		const b = ev.target.closest('button');
		if (!b) return;
		if (b.dataset.k) put(b.dataset.k);
		else if (b.dataset.fn === 'del') put(' ');
		else if (b.dataset.fn === 'end') {
			const e0 = Math.floor(sel / C.per) * C.per;
			for (let j = 0; j < C.per; j++) model[dist][e0 + j] = ' ';
			dirty = true;
			select(e0);
		} else if (b.dataset.fn === 'save') {
			model.forEach((a, d) => { document.getElementById('ScArrows' + (d + 1)).value = a.join(''); });
			dirty = false;
			document.getElementById('ScForm').submit();
		}
	});
	document.getElementById('ScTable').addEventListener('click', ev => {
		const b = ev.target.closest('.sp-cell');
		if (b) select(+b.dataset.i);
	});
	const chips = document.getElementById('ScDists');
	if (chips) chips.addEventListener('click', ev => {
		const b = ev.target.closest('[data-d]');
		if (!b) return;
		dist = +b.dataset.d;
		chips.querySelectorAll('[data-d]').forEach(x => x.classList.toggle('active', x === b));
		const first = model[dist].findIndex(c => c === ' ');
		select(first >= 0 ? first : 0);
	});
	// 保存していない変更があれば、画面を離れる前に確かめる
	window.addEventListener('beforeunload', ev => { if (dirty) { ev.preventDefault(); ev.returnValue = ''; } });
	const first = model[0].findIndex(c => c === ' ');
	sel = first >= 0 ? first : 0;
	render();
})();
</script>
<?php
page_foot('scores');
