<?php
// 立順(的番号と位置)の設定(ianseo 本体のスマホモード「的番号設定」と同じ画面構成)。登録した選手を、的番号と位置(A〜D)に割り当てる。
// 表示は「一覧」と「割り当て表」を切り替えられる(どちらも同じ処理 Entries::place を呼ぶ):
//   ・一覧: 選手のカードごとのプルダウンで、的番号と位置を選ぶ
//   ・割り当て表: 下の未割当の選手(または枠の選手)をタップしてから、空いている枠をタップする。選手のいる枠なら入れ替え
// 点数が入っている選手は動かせない(端末は「的+位置」で選手を見分けるため)。
require __DIR__ . '/_layout.php';
require_admin();

use Lite\Categories;
use Lite\Entries;
use Lite\Settings;

/** 画面が使う状態(選手と的の数) */
function targets_state()
{
    $per = max(1, min(4, (int)Settings::get('archers_per_target')));
    $entries = [];
    $maxT = 0;
    foreach (Entries::all(false) as $e) {
        if ($e['status']) {
            continue;   // 欠場の選手は出さない
        }
        $maxT = max($maxT, (int)$e['target']);
        $entries[] = [
            'id' => (int)$e['id'],
            'name' => Entries::fullName($e),
            'aff' => $e['affiliation'],
            'cat' => (string)$e['category_name'],
            'catId' => (int)$e['category_id'],
            't' => (int)$e['target'],
            'l' => $e['letter'],
            'lock' => Entries::hasScore($e),
        ];
    }
    return [
        'per' => $per,
        'n' => max(1, $maxT, (int)ceil(count($entries) / $per)),
        'entries' => $entries,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? '';
    if ($do === 'place') {
        $r = Entries::place((int)($_POST['id'] ?? 0), (int)($_POST['target'] ?? 0), (string)($_POST['letter'] ?? ''));
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($r + ['state' => targets_state()], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        if ($do === 'auto') {
            Entries::autoAssign((int)($_POST['per_target'] ?? 4), (int)($_POST['first'] ?? 1));
            Settings::set('archers_per_target', max(1, min(4, (int)($_POST['per_target'] ?? 4))));
            flash('的番号を割り当てました(点数のある選手は動かしていません)');
        } elseif ($do === 'clear') {
            Entries::unassignAll();
            flash('点数のない選手を、すべて未割当に戻しました');
        }
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'err');
    }
    redirect('targets.php');
}

$state = targets_state();
$cats = [];
foreach (Categories::all() as $c) {
    $cats[] = ['id' => (int)$c['id'], 'name' => $c['name']];
}
page_head('立順', 'targets', ['js' => ['LiteState' => $state, 'LiteCats' => $cats]]);
if (!$state['entries']) {
    echo '<div class="sp-empty">選手がいません。先に <a href="entries.php">選手登録</a> をしてください。</div>';
}
?>
<div class="sp-toolbar">
	<div class="sp-chips" id="SpViewChips">
		<button type="button" class="sp-chip" data-view="list">一覧</button>
		<button type="button" class="sp-chip" data-view="grid">割り当て表</button>
	</div>
</div>
<div id="SpListView">
	<div class="sp-toolbar">
		<label class="sp-search"><?php echo icon('search'); ?><input type="search" id="SpSearch" placeholder="名前・所属・的番号で検索"></label>
		<label class="sp-switch"><input type="checkbox" id="SpOnlyEmpty"> 的番号が未設定の人だけ</label>
	</div>
	<div class="sp-count" id="SpCount"></div>
	<div class="sp-list" id="SpList"></div>
	<p class="sp-note">的番号と位置をプルダウンで選ぶと保存されます。位置を選ばないときは、その的の空いている最初の位置に入ります。選手のいる位置を選ぶと入れ替えます。<br>点数が入っている選手(🔒)は動かせません。点数が入っている選手のいる位置へも移せません。</p>
	<details class="card noprint">
		<summary>自動で割り当てる</summary>
		<form method="post" class="inline-form" style="margin-top:8px" onsubmit="return confirm('点数のない選手の的番号を、部門順・登録順に付け直します。よろしいですか?')">
			<?php echo csrf_field(); ?><input type="hidden" name="do" value="auto">
			<label class="inline-label">1的あたり <input type="number" name="per_target" min="1" max="4" value="<?php echo (int)$state['per']; ?>" style="width:4.5rem"> 人</label>
			<label class="inline-label">開始の的 <input type="number" name="first" min="1" value="1" style="width:5rem"></label>
			<button class="sp-btn sub">自動で割り当てる</button>
		</form>
		<form method="post" class="inline-form" onsubmit="return confirm('点数のない選手を、すべて未割当に戻します。よろしいですか?')">
			<?php echo csrf_field(); ?><input type="hidden" name="do" value="clear"><button class="sp-btn sub">割り当てをすべて外す</button>
		</form>
		<p class="sp-note">点数のある選手は動かしません。</p>
	</details>
</div>
<div id="SpGridView" class="sp-hide">
	<div id="SpGrid"></div>
	<p><button type="button" class="sp-btn sub" id="SpAddTarget"><?php echo icon('plus'); ?>的を追加</button></p>
	<p class="sp-note">下の未割当の選手をタップしてから、空いている枠をタップすると割り当てます。枠の選手をタップすると、別の空き枠へ移したり、割り当てを外したりできます(選手のいる枠をタップすると入れ替え)。🔒の選手(点数が入っている)は動かせません。</p>
</div>
<div id="SpTray" class="sp-tray sp-hide">
	<div class="sp-tray-head">
		<span id="SpTrayInfo"></span>
		<select id="SpTrayFilter" aria-label="部門で絞り込み"></select>
	</div>
	<div class="sp-tray-sel sp-hide" id="SpTraySel">
		<span id="SpTraySelText"></span>
		<button type="button" class="sp-btn sub" id="SpUnassign">割り当てを外す</button>
		<button type="button" class="sp-btn sub" id="SpCancelSel">やめる</button>
	</div>
	<div class="sp-tray-list" id="SpTrayList"></div>
</div>

<script>
const LETTERS = ['A', 'B', 'C', 'D'];
const SpGrid = {sel: null, busy: false, extra: 0, filter: spStore('tgt_tray_filter') || ''};

function spRow(id) { return LiteState.entries.find(e => e.id == id); }
function spOwner(t, l) { return LiteState.entries.find(e => e.t === t && e.l === l); }
function spPos(e) { return e.t ? e.t + e.l : ''; }
function spBoardCount() {
	let n = LiteState.n;
	LiteState.entries.forEach(e => { if (e.t > n) n = e.t; });
	return n + SpGrid.extra;
}

/* サーバーで置き直す(入れ替え・点数ありの拒否はサーバーが判断する)。結果の状態で描き直す */
async function spPlace(id, t, l) {
	if (SpGrid.busy) return false;
	SpGrid.busy = true;
	try {
		const res = await spPostJson('targets.php', {do: 'place', id: id, target: t, letter: l || ''});
		LiteState = res.state;
		const e = spRow(id);
		if (res.ok) {
			SpGrid.sel = null;
			spToast(res.msg || (e ? (e.t ? e.name + ' を ' + spPos(e) + ' に割り当てました' : e.name + ' の割り当てを外しました') : '保存しました'));
		} else {
			spToast(res.msg || '移せませんでした', true);
		}
		return res.ok;
	} catch (err) {
		spToast('保存できませんでした: ' + err.message, true);
		return false;
	} finally {
		SpGrid.busy = false;
		spRender();
	}
}

/* ---- 一覧(カードごとのプルダウン) ---- */
function spTgtCard(e) {
	const n = spBoardCount();
	let to = '<option value="0"' + (e.t ? '' : ' selected') + '>-- 的</option>';
	for (let t = 1; t <= Math.max(n, e.t) + 2; t++) to += '<option value="' + t + '"' + (e.t === t ? ' selected' : '') + '>' + t + '的</option>';
	let lo = e.t ? '' : '<option value="">位置 --</option>';
	LETTERS.forEach(l => {
		const o = e.t ? spOwner(e.t, l) : null;
		lo += '<option value="' + l + '"' + (e.l === l ? ' selected' : '') + '>' + l + (o && o.id !== e.id ? '(' + spEsc(o.name) + ')' : '') + '</option>';
	});
	const dis = e.lock ? ' disabled' : '';
	const search = spNorm([e.name, e.aff, e.cat, spPos(e)].join(' '));
	return '<div class="sp-card" data-id="' + e.id + '" data-search="' + spEsc(search) + '" data-empty="' + (e.t ? 0 : 1) + '">'
		+ '<div class="sp-card-head"><span class="sp-badge' + (e.t ? '' : ' none') + '">' + spEsc(spPos(e) || '-') + '</span><div class="sp-card-main">'
		+ '<div class="sp-name">' + spEsc(e.name) + '</div>'
		+ '<div class="sp-sub">' + spEsc([e.aff, e.cat].filter(x => x).join(' / ')) + '</div>'
		+ (e.lock ? '<div style="margin-top:4px"><span class="sp-tag danger">🔒 点数入力済み</span></div>' : '')
		+ '</div></div>'
		+ '<div class="sp-target-edit">'
		+ '<select data-role="t"' + dis + ' aria-label="的番号">' + to + '</select>'
		+ '<select data-role="l"' + dis + (e.t ? '' : ' disabled') + ' aria-label="位置">' + lo + '</select>'
		+ '</div></div>';
}

function spListRender() {
	const list = document.getElementById('SpList');
	list.innerHTML = LiteState.entries.length ? LiteState.entries.map(spTgtCard).join('') : '<div class="sp-empty">選手がいません</div>';
	spListFilter();
}

function spListFilter() {
	const only = document.getElementById('SpOnlyEmpty').checked;
	const n = spFilterCards(document.getElementById('SpList'), document.getElementById('SpSearch').value,
		card => !only || card.dataset.empty === '1' || card.hasAttribute('data-keep'));
	document.getElementById('SpCount').textContent = n + ' 人';
}

/* ---- 割り当て表 ---- */
function spGridRender() {
	const sel = SpGrid.sel ? spRow(SpGrid.sel) : null;
	let html = '';
	for (let t = 1; t <= spBoardCount(); t++) {
		html += '<div class="sp-grid-row"><div class="sp-grid-no">' + t + '的</div><div class="sp-grid-cells" style="--cols:4">';
		LETTERS.forEach(l => {
			const r = spOwner(t, l);
			if (!r) {
				html += '<button type="button" class="sp-slot empty' + (sel ? ' target' : '') + '" data-t="' + t + '" data-l="' + l + '"><small>' + l + '</small></button>';
				return;
			}
			const cls = (r.lock ? ' locked' : '') + (sel && sel.id === r.id ? ' sel' : '');
			html += '<button type="button" class="sp-slot' + cls + '" data-t="' + t + '" data-l="' + l + '" data-id="' + r.id + '"><small>' + l + (r.lock ? ' 🔒' : '') + '</small>'
				+ '<b>' + spEsc(r.name) + '</b><i>' + spEsc([r.cat, r.aff].filter(x => x).join('　')) + '</i></button>';
		});
		html += '</div></div>';
	}
	document.getElementById('SpGrid').innerHTML = html;
	spTrayRender();
}

function spTrayRender() {
	const all = LiteState.entries.filter(e => !e.t && !e.lock);
	const cats = [...new Set(all.map(e => e.cat))].sort();
	if (SpGrid.filter && cats.indexOf(SpGrid.filter) < 0) SpGrid.filter = '';
	document.getElementById('SpTrayFilter').innerHTML = '<option value="">すべての部門</option>'
		+ cats.map(c => '<option' + (c === SpGrid.filter ? ' selected' : '') + '>' + spEsc(c) + '</option>').join('');
	const items = all.filter(e => !SpGrid.filter || e.cat === SpGrid.filter);
	document.getElementById('SpTrayInfo').textContent = '未割当 ' + all.length + '人';
	document.getElementById('SpTrayList').innerHTML = items.length
		? items.map(e => '<button type="button" class="sp-tray-chip' + (SpGrid.sel == e.id ? ' sel' : '') + '" data-id="' + e.id + '"><b>' + spEsc(e.name) + '</b><i>' + spEsc(e.cat + (e.aff ? '　' + e.aff : '')) + '</i></button>').join('')
		: '<span class="sp-sub">未割当の選手はいません</span>';
	const sel = SpGrid.sel ? spRow(SpGrid.sel) : null;
	document.getElementById('SpTraySel').classList.toggle('sp-hide', !sel);
	document.getElementById('SpTrayInfo').parentNode.classList.toggle('sp-hide', !!sel);
	if (sel) {
		document.getElementById('SpTraySelText').textContent = sel.name + (sel.t ? '(' + spPos(sel) + ')' : '') + ' → 空いている枠をタップ';
		document.getElementById('SpUnassign').classList.toggle('sp-hide', !sel.t);
	}
}

function spRender() {
	if (spStore('tgt_view') === 'grid') spGridRender(); else spListRender();
}

/* 表示の切り替え(一覧／割り当て表) */
function spSetView(view) {
	spStore('tgt_view', view);
	document.querySelectorAll('#SpViewChips [data-view]').forEach(b => b.classList.toggle('active', b.dataset.view === view));
	document.getElementById('SpListView').classList.toggle('sp-hide', view !== 'list');
	document.getElementById('SpGridView').classList.toggle('sp-hide', view !== 'grid');
	document.getElementById('SpTray').classList.toggle('sp-hide', view !== 'grid');
	document.body.classList.toggle('sp-has-tray', view === 'grid');
	SpGrid.sel = null;
	spRender();
}

document.addEventListener('DOMContentLoaded', () => {
	document.getElementById('SpViewChips').addEventListener('click', ev => {
		const b = ev.target.closest('[data-view]');
		if (b) spSetView(b.dataset.view);
	});
	// 一覧: プルダウンで的番号・位置を選ぶ
	document.getElementById('SpList').addEventListener('change', ev => {
		const card = ev.target.closest('.sp-card[data-id]');
		if (!card) return;
		card.setAttribute('data-keep', '1');
		const id = +card.dataset.id, e = spRow(id);
		const t = +card.querySelector('[data-role=t]').value;
		let l = card.querySelector('[data-role=l]').value;
		if (ev.target.dataset.role === 't') l = (e && e.t === t) ? e.l : '';   // 的を選び直したら、空いている最初の位置
		spPlace(id, t, t ? l : '');
	});
	document.getElementById('SpSearch').addEventListener('input', spListFilter);
	const only = document.getElementById('SpOnlyEmpty');
	only.checked = spStore('tgt_only_empty') === '1';
	only.addEventListener('change', () => {
		spStore('tgt_only_empty', only.checked ? '1' : '0');
		document.querySelectorAll('#SpList [data-keep]').forEach(c => c.removeAttribute('data-keep'));
		spListFilter();
	});
	// 割り当て表: タップで選んで、枠をタップ
	document.getElementById('SpGrid').addEventListener('click', ev => {
		const b = ev.target.closest('.sp-slot');
		if (!b || SpGrid.busy) return;
		const t = +b.dataset.t, l = b.dataset.l;
		if (!b.hasAttribute('data-id')) {
			if (SpGrid.sel) spPlace(SpGrid.sel, t, l);
			else spToast('先に下の未割当の選手をタップしてください');
			return;
		}
		const r = spRow(b.dataset.id);
		if (SpGrid.sel && SpGrid.sel != r.id) { spPlace(SpGrid.sel, t, l); return; }   // 選手のいる枠: 入れ替え
		if (r.lock) { spToast('点数が入っているため動かせません', true); return; }
		SpGrid.sel = (SpGrid.sel == r.id ? null : r.id);
		spGridRender();
	});
	document.getElementById('SpTrayList').addEventListener('click', ev => {
		const b = ev.target.closest('.sp-tray-chip');
		if (!b) return;
		SpGrid.sel = (SpGrid.sel == b.dataset.id ? null : +b.dataset.id);
		spGridRender();
	});
	document.getElementById('SpTrayFilter').addEventListener('change', ev => {
		SpGrid.filter = ev.target.value;
		spStore('tgt_tray_filter', SpGrid.filter);
		spTrayRender();
	});
	document.getElementById('SpUnassign').addEventListener('click', () => { if (SpGrid.sel) spPlace(SpGrid.sel, 0, ''); });
	document.getElementById('SpCancelSel').addEventListener('click', () => { SpGrid.sel = null; spGridRender(); });
	document.getElementById('SpAddTarget').addEventListener('click', () => { SpGrid.extra++; spGridRender(); });
	spSetView(spStore('tgt_view') === 'grid' ? 'grid' : 'list');
});
</script>
<?php
page_foot('targets');
