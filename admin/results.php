<?php
// 結果一覧(ianseo 本体のスマホモード「ライブ得点集計」と同じ画面構成)。ログインなしで見られる(?c=大会コード)。
//   ランキング/的順のチップ、検索、自動更新(30秒)、部門ごとの見出し、カードをタップするとスコアカード
require __DIR__ . '/_layout.php';

use Lite\Entries;
use Lite\Results;
use Lite\Scoring;
use Lite\Settings;

$code = public_comp();   // ?c=大会コード(なければ管理中の大会、それも無ければ最新の大会)

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
                    $t = Scoring::total($s)[0];
                    $run += $t;
                    $endRows[] = ['n' => $n, 'a' => array_map(function ($c) {
                        return trim($c) === '' ? '' : Scoring::display($c);
                    }, str_split($s)), 't' => $t, 'r' => $run];
                }
                $dists[] = ['name' => Settings::distanceName($d) ?: ($nd > 1 ? '距離' . $d : ''), 'score' => $r['dist_scores'][$d]];
                $sc[] = ['name' => Settings::distanceName($d) ?: ($nd > 1 ? '距離' . $d : '得点'), 'ends' => $endRows];
            }
            $items[] = [
                'id' => (int)$r['id'], 'rank' => $r['rank'], 'tie' => $r['tie'],
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
        'goldLabel' => Settings::get('gold_label'), 'xLabel' => Settings::get('x_label'),
        'round' => Settings::roundLabel(), 'updated' => Results::lastUpdateText(),
        'finished' => $p['finished'], 'archers' => $p['archers'],
    ];
}

if (isset($_GET['json'])) {
    json_out(results_data());
}

$admin = Lite\Auth::isCurrent($code);
page_head('予選結果', 'results', ['public' => true, 'js' => ['LiteRes' => results_data(), 'LiteCode' => $code, 'LitePublic' => !$admin]]);
?>
<div class="sp-toolbar">
	<div class="sp-chips" id="SpOrder">
		<button type="button" class="sp-chip" data-order="rank">ランキング</button>
		<button type="button" class="sp-chip" data-order="target">的順</button>
	</div>
	<label class="sp-search"><?php echo icon('search'); ?><input type="search" id="SpSearch" placeholder="名前・所属で検索"></label>
	<div class="sp-live-bar">
		<label class="sp-switch"><input type="checkbox" id="SpAuto"> 自動更新(30秒)</label>
		<span id="SpInfo"></span>
		<button type="button" class="sp-btn sub" id="SpReload" style="padding:6px 10px" aria-label="更新"><?php echo icon('reload'); ?></button>
	</div>
</div>
<div class="sp-list" id="SpList"></div>
<p class="sp-note">カードをタップするとスコアカードを表示します。* は 合計・<?php echo h(Settings::get('gold_label')); ?>・<?php echo h(Settings::get('x_label')); ?> がすべて同じ(シュートオフ等で決める)選手です。</p>
<div class="actions noprint">
	<a class="sp-btn sub" href="pdf.php?type=results&c=<?php echo h($code); ?>" target="_blank"><?php echo icon('print'); ?>結果PDF</a>
	<a class="sp-btn sub" href="export.php?what=results&c=<?php echo h($code); ?>">結果CSV</a>
</div>
<p class="sp-note">公開用のURL: <code><?php echo h(server_base_url() . 'admin/results.php?c=' . $code); ?></code></p>

<script>
const SpLv = {order: spStore('res_order') || 'rank', open: new Set(), timer: null};

function spResCard(it, showCat) {
	let detail = '';
	it.sc.forEach(d => {
		if (!d.ends.length) return;
		detail += '<table class="sp-sc"><thead><tr><th colspan="' + (LiteRes.per + 3) + '">' + spEsc(d.name) + '</th></tr><tr><th>E</th>';
		for (let i = 1; i <= LiteRes.per; i++) detail += '<th>' + i + '</th>';
		detail += '<th>計</th><th>累計</th></tr></thead><tbody>';
		d.ends.forEach(e => {
			detail += '<tr><td>' + e.n + '</td>' + e.a.map(a => '<td>' + spEsc(a) + '</td>').join('') + '<td class="tot">' + e.t + '</td><td>' + e.r + '</td></tr>';
		});
		detail += '</tbody></table>';
	});
	if (!detail) detail = '<div class="sp-sub">スコアカードはまだありません</div>';
	const pos = it.t ? it.t + it.l : '-';
	const dist = it.dists.length > 1 ? it.dists.map(d => '<span>' + spEsc(d.name) + ' ' + (d.score === null ? '-' : d.score) + '</span>').join('') : '';
	return '<div class="sp-card tap' + (SpLv.open.has(it.id) ? ' open' : '') + '" data-id="' + it.id + '" data-search="' + spEsc(spNorm(it.name + ' ' + it.aff + ' ' + pos)) + '">'
		+ '<div class="sp-card-head">'
		+ (SpLv.order === 'rank'
			? '<div class="sp-rank">' + (it.rank === null ? '' : it.rank + (it.tie ? '*' : '')) + '</div>'
			: '<span class="sp-badge">' + spEsc(pos) + '</span>')
		+ '<div class="sp-card-main">'
		+ '<div class="sp-name">' + spEsc(it.name) + '</div>'
		+ '<div class="sp-sub">' + spEsc(it.aff) + (showCat ? ' / ' + spEsc(showCat) : '') + (SpLv.order === 'rank' && it.t ? ' / 的 ' + spEsc(pos) : '') + '</div>'
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
	document.getElementById('SpList').innerHTML = html || '<div class="sp-empty">表示する選手がいません</div>';
	document.getElementById('SpInfo').textContent = LiteRes.round + ' / ' + LiteRes.finished + '/' + LiteRes.archers + '人終了 / 更新 ' + LiteRes.updated;
	spResFilter();
}

function spResFilter() {
	spFilterCards(document.getElementById('SpList'), document.getElementById('SpSearch').value);
	// 選手が1人も見えない部門の見出しは隠す
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

function spResAuto(on) {
	clearInterval(SpLv.timer);
	if (on) SpLv.timer = setInterval(spResReload, 30000);
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
	document.getElementById('SpReload').addEventListener('click', spResReload);
	const auto = document.getElementById('SpAuto');
	const saved = spStore('res_auto');
	auto.checked = saved === null ? LitePublic : saved === '1';   // 参加者向け(ログインなし)は最初から自動更新
	auto.addEventListener('change', () => { spStore('res_auto', auto.checked ? '1' : '0'); spResAuto(auto.checked); });
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
