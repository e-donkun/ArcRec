<?php
// 結果一覧の画面(ianseo 本体のスマホモード「ライブ得点集計」と同じ画面構成)。
//   ランキング/的順のチップ、検索、自動更新(最初はオフ。入れると 30秒 から数字が減る)、部門ごとの見出し、カードをタップするとスコアカード(第1・第2優先順位の本数も)
// 管理画面の admin/results.php と、参加者向けのトップの results.php(記録会設定で公開にした記録会だけ)から使う。

use Lite\Entries;
use Lite\Results;
use Lite\Scoring;
use Lite\Settings;

/** 画面に渡す結果(部門ごと)。カードを開いたときのスコアカードも入れる */
function results_data()
{
    $per = Settings::arrowsPerEnd();
    $ends = Settings::ends();
    $nd = Settings::distances();
    $sections = [];
    foreach (Results::byCategory() as $g) {
        if (!$g['rows']) {
            continue;
        }
        $items = [];
        foreach ($g['rows'] as $r) {
            $dists = [];
            $sc = [];
            $run = 0;
            for ($d = 1; $d <= $nd; $d++) {
                $arrows = Entries::arrows($r, $d);
                $endRows = [];
                for ($n = 1; $n <= $ends; $n++) {
                    $s = Scoring::endString($arrows, $n, $per);
                    if (trim($s) === '') {
                        continue;
                    }
                    list($et, $eg, $ex) = Scoring::total($s);
                    $run += $et;
                    $endRows[] = ['n' => $n, 'a' => array_map(function ($c) {
                        return trim($c) === '' ? '' : Scoring::display($c);
                    }, str_split($s)), 't' => $et, 'g' => $eg, 'x' => $ex, 'r' => $run];
                }
                list($dt, $dg, $dx) = Scoring::total($arrows);
                $dists[] = ['name' => Settings::distanceName($d) ?: ($nd > 1 ? '距離' . $d : ''), 'score' => $r['dist_scores'][$d]];
                $sc[] = ['name' => Settings::distanceName($d) ?: ($nd > 1 ? '距離' . $d : '得点'), 'ends' => $endRows, 't' => $dt, 'g' => $dg, 'x' => $dx];
            }
            $items[] = [
                'id' => (int)$r['id'], 'rank' => $r['rank'], 'tie' => $r['tie'], 'absent' => $r['absent'],
                't' => (int)$r['target'], 'l' => $r['letter'], 'name' => Entries::fullName($r), 'aff' => $r['affiliation'],
                'score' => $r['score'], 'golds' => $r['golds'], 'xs' => $r['xs'], 'hits' => $r['hits'],
                'dists' => $dists, 'sc' => $sc,
            ];
        }
        $sections[] = ['name' => $g['category']['name'], 'items' => $items];
    }
    $p = Results::progress();
    return [
        'sections' => $sections, 'per' => $per, 'total' => Settings::totalArrows(),
        'goldLabel' => Settings::tieLabel(1), 'xLabel' => Settings::tieLabel(2),
        'round' => Settings::roundLabel(), 'updated' => Results::lastUpdateText(),
        'finished' => $p['finished'], 'archers' => $p['archers'], 'ends' => Results::endsProgress(),
    ];
}

/**
 * 結果の画面。$public = true はトップの results.php(参加者向け。管理用のタブ・リンクを出さない)
 */
function results_page($code, $public)
{
    if (isset($_GET['json'])) {
        json_out(results_data());
    }
    // 公開ページ: 左上の「<」で記録会一覧へ、右上の「設定」でこの記録会の管理者画面へ
    page_head('記録会結果', 'results', ['public' => $public, 'base' => $public ? 'admin/' : '',
        'back' => $public ? ['./?list=1', '記録会一覧に戻る'] : null, 'right' => $public ? public_admin_button($code) : '',
        'js' => ['LiteRes' => results_data(), 'LiteCode' => $code, 'LitePublic' => $public]]);
    $adm = $public ? 'admin/' : '';
    if (!$public) {
        echo public_card();   // 管理画面では、結果の公開を一番上に
    }
?>
<div class="sp-toolbar">
	<div class="sp-chips" id="SpOrder">
		<button type="button" class="sp-chip" data-order="rank">ランキング</button>
		<button type="button" class="sp-chip" data-order="target">的順</button>
	</div>
	<label class="sp-search"><?php echo icon('search'); ?><input type="search" id="SpSearch" placeholder="名前・所属で検索"></label>
	<div class="sp-live-bar">
		<label class="sp-switch"><input type="checkbox" id="SpAuto"> 自動更新(<span id="SpAutoSec">30秒</span>)</label>
		<span id="SpInfo"></span>
		<button type="button" class="sp-btn sub" id="SpReload" style="padding:6px 10px" aria-label="更新"><?php echo icon('reload'); ?></button>
	</div>
</div>
<div class="sp-list" id="SpList"></div>
<div class="actions noprint">
	<a class="sp-btn sub" href="<?php echo $adm; ?>pdf.php?type=results&c=<?php echo h($code); ?>" target="_blank"><?php echo icon('print'); ?>結果PDF</a>
<?php if (!$public) { ?>
	<a class="sp-btn sub" href="export.php?what=results">結果CSV</a>
<?php } ?>
</div>

<script>
const SpLv = {order: spStore('res_order') || 'rank', open: new Set(), timer: null};

function spResCard(it, showCat) {
	// スコアカード: エンド | 素点 | 小計 | 合計 | 総計 | 第1優先 | 第2優先。見出しの左(エンド〜素点)は結合して距離の名前
	let detail = '', grand = 0;
	const blank0 = v => v ? v : '';
	it.sc.forEach(d => {
		if (!d.ends.length) return;
		detail += '<table class="sp-rsc"><colgroup><col class="c-end">' + '<col class="c-a">'.repeat(LiteRes.per)
			+ '<col class="c-n"><col class="c-n"><col class="c-n"><col class="c-t"><col class="c-t"></colgroup>'
			+ '<thead><tr><th class="dist" colspan="' + (LiteRes.per + 1) + '">' + spEsc(d.name) + '</th>'
			+ '<th>小計</th><th>合計</th><th>総計</th><th class="t">' + spEsc(LiteRes.goldLabel) + '</th><th class="t">' + spEsc(LiteRes.xLabel) + '</th></tr></thead><tbody>';
		d.ends.forEach(e => {
			grand += e.t;
			detail += '<tr><td class="end">' + e.n + '</td>' + e.a.map(a => '<td class="a"><span>' + spEsc(a) + '</span></td>').join('')
				+ '<td class="n">' + e.t + '</td><td class="n">' + e.r + '</td><td class="n">' + grand + '</td>'
				+ '<td class="n">' + blank0(e.g) + '</td><td class="n">' + blank0(e.x) + '</td></tr>';
		});
		detail += '</tbody><tfoot><tr><td colspan="' + (LiteRes.per + 2) + '"></td><td class="n">' + d.t + '</td><td class="n">' + grand + '</td>'
			+ '<td class="n">' + d.g + '</td><td class="n">' + d.x + '</td></tr></tfoot></table>';
	});
	if (!detail) detail = '<div class="sp-sub">スコアカードはまだありません</div>';
	const pos = it.t ? it.t + it.l : '-';
	const dist = it.dists.length > 1 ? it.dists.map(d => '<span>' + spEsc(d.name) + ' ' + (d.score === null ? '-' : d.score) + '</span>').join('') : '';
	return '<div class="sp-card tap' + (SpLv.open.has(it.id) ? ' open' : '') + '" data-id="' + it.id + '" data-search="' + spEsc(spNorm(it.name + ' ' + it.aff + ' ' + pos)) + '">'
		+ '<div class="sp-card-head">'
		+ (SpLv.order === 'rank'
			? '<div class="sp-rank">' + (it.absent ? '欠' : it.rank === null ? '' : it.rank + (it.tie ? '*' : '')) + '</div>'
			: '<span class="sp-badge">' + spEsc(it.absent && !it.t ? '欠' : pos) + '</span>')
		+ '<div class="sp-card-main">'
		+ '<div class="sp-name">' + spEsc(it.name) + '</div>'
		+ '<div class="sp-sub">' + spEsc(it.aff) + (showCat ? ' / ' + spEsc(showCat) : '') + (SpLv.order === 'rank' && it.t ? ' / ' + spEsc(pos) : '') + '</div>'
		+ '</div>'
		+ (it.hits
			? '<div class="sp-score"><b>' + it.score + '</b><div class="sp-sub">' + spEsc(LiteRes.goldLabel) + ':' + it.golds + ' ' + spEsc(LiteRes.xLabel) + ':' + it.xs + '</div></div>'
			: '<div class="sp-score"></div>')
		+ '</div>'
		+ (it.hits ? '<div class="sp-dists">' + dist + '<span>' + it.hits + '/' + LiteRes.total + '射</span></div>' : '')
		+ '<div class="sp-detail">' + detail + '</div>'
		+ '</div>';
}

function spResRender() {
	document.querySelectorAll('#SpOrder [data-order]').forEach(b => b.classList.toggle('active', b.dataset.order === SpLv.order));
	let html = '';
	if (SpLv.order === 'rank') {
		LiteRes.sections.forEach(sec => {
			html += '<div class="sp-section-title" data-section>' + spEsc(sec.name) + '</div>';
			sec.items.forEach(it => html += spResCard(it, ''));
		});
	} else {
		const all = [];
		LiteRes.sections.forEach(sec => sec.items.forEach(it => all.push([it, sec.name])));
		all.sort((a, b) => (a[0].t || 9999) - (b[0].t || 9999) || a[0].l.localeCompare(b[0].l));
		all.forEach(x => html += spResCard(x[0], x[1]));
	}
	document.getElementById('SpList').innerHTML = html || '<div class="sp-empty">表示する参加者がいません</div>';
	document.getElementById('SpInfo').textContent = '終了エンド ' + LiteRes.ends.done + ' / ' + LiteRes.ends.all;
	spResFilter();
}

function spResFilter() {
	spFilterCards(document.getElementById('SpList'), document.getElementById('SpSearch').value);
	// 参加者が1人も見えない部門の見出しは隠す
	document.querySelectorAll('#SpList [data-section]').forEach(h => {
		let n = h.nextElementSibling, any = false;
		while (n && !n.hasAttribute('data-section')) {
			if (!n.classList.contains('sp-hide')) any = true;
			n = n.nextElementSibling;
		}
		h.classList.toggle('sp-hide', !any);
	});
}

async function spResReload() {
	try {
		LiteRes = await spGetJson('results.php', {c: LiteCode, json: 1});
		spResRender();
	} catch (e) {
		spToast('更新できませんでした: ' + e.message, true);
	}
}

// 自動更新: チェックを入れると 30 秒からカウントダウンし、0 になったら更新して数え直す
const SP_AUTO_SEC = 30;
function spResAuto(on) {
	clearInterval(SpLv.timer);
	const label = document.getElementById('SpAutoSec');
	let left = SP_AUTO_SEC;
	label.textContent = left + '秒';
	if (!on) return;
	SpLv.timer = setInterval(async () => {
		left--;
		if (left <= 0) {
			label.textContent = '更新中';
			clearInterval(SpLv.timer);
			await spResReload();
			if (document.getElementById('SpAuto').checked) spResAuto(true);
			return;
		}
		label.textContent = left + '秒';
	}, 1000);
}

document.addEventListener('DOMContentLoaded', () => {
	document.getElementById('SpOrder').addEventListener('click', ev => {
		const b = ev.target.closest('[data-order]');
		if (!b) return;
		SpLv.order = b.dataset.order;
		spStore('res_order', SpLv.order);
		spResRender();
	});
	document.getElementById('SpSearch').addEventListener('input', spResFilter);
	document.getElementById('SpReload').addEventListener('click', async () => {
		await spResReload();
		if (document.getElementById('SpAuto').checked) spResAuto(true);   // 手で更新したら、カウントダウンも数え直す
	});
	const auto = document.getElementById('SpAuto');
	auto.checked = false;   // 自動更新は、開くたびに必ずオフから(前の状態は覚えない)
	auto.addEventListener('change', () => spResAuto(auto.checked));
	spResAuto(auto.checked);
	document.getElementById('SpList').addEventListener('click', ev => {
		const card = ev.target.closest('.sp-card[data-id]');
		if (!card) return;
		const id = +card.dataset.id;
		if (SpLv.open.has(id)) SpLv.open.delete(id); else SpLv.open.add(id);
		card.classList.toggle('open');
	});
	spResRender();
});
</script>
<?php
    page_foot('results');
}
