<?php
// 結果一覧の画面(ianseo 本体のスマホモード「ライブ得点集計」と同じ画面構成)。
//   ランキング/的順のチップ、検索、自動更新(30秒)、部門ごとの見出し、カードをタップするとスコアカード(ゴールド・X の数も)
// 管理画面の admin/results.php と、参加者向けのトップの results.php(大会設定で公開にした大会だけ)から使う。

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

/**
 * 結果の画面。$public = true はトップの results.php(参加者向け。管理用のタブ・リンクを出さない)
 */
function results_page($code, $public)
{
    if (isset($_GET['json'])) {
        json_out(results_data());
    }
    page_head('予選結果', 'results', ['public' => $public, 'base' => $public ? 'admin/' : '',
        'js' => ['LiteRes' => results_data(), 'LiteCode' => $code, 'LitePublic' => $public]]);
    $adm = $public ? 'admin/' : '';
    if (!Settings::resultsPublic()) {
        echo '<div class="sp-flash warn">この大会の結果は<b>非公開</b>です(この大会を開いている管理者だけが見られます)。' . ($public ? '' : '<a href="setup.php">大会設定</a>で公開にできます。') . '</div>';
    }
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
	<a class="sp-btn sub" href="<?php echo $adm; ?>pdf.php?type=results&c=<?php echo h($code); ?>" target="_blank"><?php echo icon('print'); ?>結果PDF</a>
	<a class="sp-btn sub" href="<?php echo $adm; ?>export.php?what=results&c=<?php echo h($code); ?>">結果CSV</a>
</div>
<?php if (!$public) { ?>
<p class="sp-note">公開用のURL: <code><?php echo h(server_base_url() . 'results.php?c=' . $code); ?></code>(<?php echo Settings::resultsPublic() ? '公開中' : '非公開'; ?>)</p>
<?php } ?>

<script>
const SpLv = {order: spStore('res_order') || 'rank', open: new Set(), timer: null};

function spResCard(it, showCat) {
	let detail = '';
	it.sc.forEach(d => {
		if (!d.ends.length) return;
		detail += '<table class="sp-sc"><thead><tr><th colspan="' + (LiteRes.per + 5) + '">' + spEsc(d.name) + '</th></tr><tr><th>E</th>';
		for (let i = 1; i <= LiteRes.per; i++) detail += '<th>' + i + '</th>';
		detail += '<th>計</th><th>' + spEsc(LiteRes.goldLabel) + '</th><th>' + spEsc(LiteRes.xLabel) + '</th><th>累計</th></tr></thead><tbody>';
		d.ends.forEach(e => {
			detail += '<tr><td>' + e.n + '</td>' + e.a.map(a => '<td>' + spEsc(a) + '</td>').join('') + '<td class="tot">' + e.t + '</td><td>' + e.g + '</td><td>' + e.x + '</td><td>' + e.r + '</td></tr>';
		});
		detail += '</tbody><tfoot><tr><td colspan="' + (LiteRes.per + 1) + '">小計</td><td class="tot">' + d.t + '</td><td class="tot">' + d.g + '</td><td class="tot">' + d.x + '</td><td></td></tr></tfoot></table>';
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
}
