<?php
// 素点の編集。ScorekeeperNG と同じく、矢の枠をタップして下のボタン(的面の色)で点を入れる。
//   2距離なら 前半/後半 を切り替え。「編集内容を保存」で全距離をまとめて保存(更新履歴が残る)。
//   特別な画面なので、下部タブ・左上の「<」は出さない。戻るときは画面のボタンで(保存していなければ確かめる)
require __DIR__ . '/_layout.php';
require_admin();

use Lite\Entries;
use Lite\Scoring;
use Lite\Settings;
use Lite\TargetFaces;

$e = Entries::find((int)($_GET['id'] ?? $_POST['id'] ?? 0));
if (!$e) {
    flash('参加者が見つかりません', 'err');
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

/** 更新履歴(新しい順)の表 */
function score_history($e, $nd)
{
    $log = Entries::log($e['id'], 60);
    if (!$log) {
        return '<div class="sp-empty">更新履歴はありません</div>';
    }
    $h = '<div class="tablewrap"><table style="width:auto"><tr><th>日時</th>' . ($nd > 1 ? '<th>距離</th>' : '') . '<th>エンド</th><th>内容</th><th>送信元</th></tr>';
    foreach ($log as $l) {
        $disp = [];
        foreach (str_split($l['arrows']) as $c) {
            $disp[] = trim($c) === '' ? '-' : Scoring::display($c);
        }
        $h .= '<tr><td class="small">' . h($l['at']) . '</td>' . ($nd > 1 ? '<td class="c">' . h(Settings::distanceName((int)$l['distance']) ?: (int)$l['distance']) . '</td>' : '')
            . '<td class="c">' . (int)$l['end_no'] . '</td><td>' . h(implode(' ', $disp)) . '</td><td class="small">'
            . h($l['source'] === 'device' ? '端末 ' . substr($l['device'], 0, 8) : ($l['source'] === 'admin' ? '管理画面' : $l['source'])) . '</td></tr>';
    }
    return $h . '</table></div>';
}

if (isset($_GET['history'])) {
    header('Content-Type: text/html; charset=UTF-8');
    echo score_history($e, $nd);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 確定済みは、管理画面からも編集しない(点数管理で確定を解除してから)
    if ($e['locked']) {
        json_out(['ok' => false, 'msg' => '確定済みのため編集できません(点数管理で確定を解除してください)']);
    }
    // まず全距離を検証して、1つでも不正なら何も保存しない(画面からは Ajax で送る)
    $new = [];
    for ($d = 1; $d <= $nd; $d++) {
        $s = strtoupper((string)($_POST['arrows'][$d] ?? ''));
        if (strlen($s) > $total || !preg_match('/^[' . preg_quote($allowed, '/') . ' ]*$/', $s)) {
            json_out(['ok' => false, 'msg' => '入力が正しくありません。何も保存していません。']);
        }
        $new[$d] = Scoring::pad($s, $total);
    }
    foreach ($new as $d => $str) {
        Entries::replaceArrows($e['id'], $str, 'admin', $d);
    }
    $e = Entries::find($e['id']);
    $saved = [];
    for ($d = 1; $d <= $nd; $d++) {
        $saved[] = Scoring::pad(strtoupper(Entries::arrows($e, $d)), $total);
    }
    json_out(['ok' => true, 'msg' => '編集内容を保存しました', 'arrows' => $saved]);
}

$dists = [];
for ($d = 1; $d <= $nd; $d++) {
    $dists[] = ['name' => Settings::distanceName($d) ?: ($nd > 1 ? '距離' . $d : ''), 'arrows' => Scoring::pad(strtoupper(Entries::arrows($e, $d)), $total)];
}
$pos = $e['target'] ? $e['target'] . $e['letter'] : '';

page_head('素点の編集', 'scores', ['body' => 'sp-has-keypad sp-score-edit', 'notabs' => true, 'noback' => true,
    'right' => '<button type="button" class="sp-appbar-btn" id="ScHistBtn">' . icon('history') . '<span>更新履歴</span></button>',
    'js' => [
        'LiteSc' => [
            'id' => (int)$e['id'], 'locked' => (bool)$e['locked'], 'per' => $per, 'ends' => $ends, 'dists' => $dists, 'keys' => $keys,
            'tie1' => Settings::tieChars(1), 'tie2' => Settings::tieChars(2),
            'tie1Label' => Settings::tieLabel(1), 'tie2Label' => Settings::tieLabel(2),
        ],
    ]]);
if ($e['locked']) {
    echo '<div class="sp-flash warn">このスコアカードは確定済みのため編集できません(点数管理で「全員の確定を解除」すると編集できます)。</div>';
}
// 参加者と合計のカードは、スクロールしても上部バーの下に固定する
echo '<div class="sp-card sp-sticky-card" id="ScHead"><div class="sp-card-head"><span class="sp-badge' . ($pos ? '' : ' none') . '">' . h($pos ?: '-') . '</span><div class="sp-card-main">'
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
?>
<div class="sp-keypad sp-sc-keypad" id="ScPad"></div>
<script>
(function () {
	const C = LiteSc;
	const pts = {}, disp = {};
	C.keys.forEach(k => { pts[k.l] = k.n; disp[k.l] = k.p; });
	let base = C.dists.map(d => d.arrows);                // 保存済みの内容
	let model = base.map(a => a.split(''));               // 距離ごとの矢(1文字1本、空白は未入力)
	let dist = 0, sel = 0, leaving = false, saving = false;

	function dirty() { return model.some((a, d) => a.join('') !== base[d]); }
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
		let h = '<colgroup><col style="width:26px">' + '<col>'.repeat(C.per + 2) + '</colgroup><thead><tr><th></th>';
		for (let j = 1; j <= C.per; j++) h += '<th>' + j + '</th>';
		h += '<th>小計</th><th>合計</th></tr></thead><tbody>';
		let run = 0;
		for (let e = 0; e < C.ends; e++) {
			const s = endSum(dist, e);
			if (s !== null) run += s;
			h += '<tr><td class="no">' + (e + 1) + '</td>';
			for (let j = 0; j < C.per; j++) {
				const i = e * C.per + j, c = model[dist][i];
				const changed = c !== base[dist][i];
				h += '<td><button type="button" class="sp-cell' + (i === sel && !C.locked ? ' sel' : '') + (changed ? ' changed' : '') + '" data-i="' + i + '">' + (c && c !== ' ' ? spEsc(disp[c] || c) : '') + '</button></td>';
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
		// 下のボタン: 編集前は「戻る」「編集内容を保存(押せない)」、編集したら「編集内容を保存せず戻る」「編集内容を保存」
		if (C.locked) return;   // 確定済み: 見るだけ
		const d = dirty();
		document.getElementById('ScBack').textContent = d ? '編集内容を保存せず戻る' : '戻る';
		document.getElementById('ScSave').disabled = !d || saving;
	}
	function select(i) {
		if (C.locked) return;
		const n = C.ends * C.per;
		sel = Math.max(0, Math.min(n - 1, i));
		render();
		const cell = document.querySelector('.sp-cell.sel');
		const pad = document.getElementById('ScPad').getBoundingClientRect();
		const r = cell.getBoundingClientRect();
		const head = document.getElementById('ScHead').getBoundingClientRect();
		if (r.bottom > pad.top - 8 || r.top < head.bottom + 4) cell.scrollIntoView({block: 'center', behavior: 'smooth'});
	}
	function put(c) {
		model[dist][sel] = c;
		select(c === ' ' ? sel : sel + 1);
	}
	async function save() {
		if (!dirty() || saving) return;
		saving = true;
		render();
		try {
			const params = {id: C.id};
			model.forEach((a, d) => { params['arrows[' + (d + 1) + ']'] = a.join(''); });
			const r = await spPostJson('score_edit.php?id=' + C.id, params);
			if (!r.ok) throw new Error(r.msg);
			base = r.arrows;                                  // 保存した内容を反映して、編集前の状態に戻る
			model = base.map(a => a.split(''));
			spToast(r.msg);
		} catch (e) {
			spToast('保存できませんでした: ' + e.message, true);
		}
		saving = false;
		render();
	}
	// キーパッド: 的面の色の点ボタン(3列)、素点削除・エンド削除、その下に 戻る・編集内容を保存
	const pad = document.getElementById('ScPad');
	// 確定済みは編集できないので「戻る」だけ
	if (C.locked) pad.innerHTML = '<div class="sp-pad-row nav"><button type="button" class="fn back" id="ScBack" data-fn="back">戻る</button></div>';
	else pad.innerHTML = C.keys.map(k => '<button type="button" data-k="' + k.l + '" style="background:' + k.bg + ';color:' + k.fg + '">' + spEsc(k.p) + '</button>').join('')
		+ '<div class="sp-pad-row"><button type="button" class="fn" data-fn="del">素点削除</button><button type="button" class="fn" data-fn="end">エンド削除</button></div>'
		+ '<div class="sp-pad-row nav"><button type="button" class="fn back" id="ScBack" data-fn="back">戻る</button><button type="button" class="fn save" id="ScSave" data-fn="save" disabled>編集内容を保存</button></div>';
	pad.addEventListener('click', ev => {
		const b = ev.target.closest('button');
		if (!b || b.disabled) return;
		if (b.dataset.k) put(b.dataset.k);
		else if (b.dataset.fn === 'del') put(' ');
		else if (b.dataset.fn === 'end') {
			const e0 = Math.floor(sel / C.per) * C.per;
			for (let j = 0; j < C.per; j++) model[dist][e0 + j] = ' ';
			select(e0);
		} else if (b.dataset.fn === 'save') save();
		else if (b.dataset.fn === 'back') { leaving = true; location.href = 'scores.php'; }
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
	// 更新履歴(右上のボタン)
	document.getElementById('ScHistBtn').addEventListener('click', async () => {
		const el = spSheet.open('<h2>更新履歴(新しい順)</h2><div id="ScHist" class="sp-sub">読み込み中…</div>'
			+ '<div class="sp-sheet-actions"><button type="button" class="sp-btn sub" onclick="spSheet.close()">閉じる</button></div>');
		try {
			el.querySelector('#ScHist').innerHTML = await spGetText('score_edit.php', {id: C.id, history: 1});
		} catch (e) {
			el.querySelector('#ScHist').textContent = '読み込めませんでした: ' + e.message;
		}
	});
	// 画面のボタン以外で離れようとしたら、保存していない編集があれば確かめる
	window.addEventListener('beforeunload', ev => {
		if (!leaving && dirty()) { ev.preventDefault(); ev.returnValue = '編集した内容が保存されていませんが、よろしいですか?'; }
	});
	// 固定するカードの位置 = 上部バーの高さ
	const fixTop = () => { document.getElementById('ScHead').style.top = document.querySelector('.sp-appbar').offsetHeight + 'px'; };
	fixTop();
	window.addEventListener('resize', fixTop);
	const first = model[0].findIndex(c => c === ' ');
	sel = first >= 0 ? first : 0;
	render();
})();
</script>
<?php
page_foot('scores');
