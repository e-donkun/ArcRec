<?php
// 参加者登録(ianseo 本体のスマホモード「参加者リスト」と同じ画面構成)
//   検索と部門のチップ → 参加者のカード → タップで編集シート、「＋ 追加」で追加シート(新しく入力 / 過去の記録会から)
// 的番号と位置は「立順」の画面で設定する。
require __DIR__ . '/_layout.php';
require_admin();

use Lite\Categories;
use Lite\Entries;
use Lite\Past;
use Lite\Scoring;
use Lite\Settings;

/** CSV/TSV(UTF-8 か Shift_JIS)を行ごとの列に分ける。見出し行は飛ばす。@return array [行番号 => 列の配列] */
function parse_entries_csv($text)
{
    $enc = mb_detect_encoding($text, ['UTF-8', 'SJIS-win', 'EUC-JP'], true) ?: 'UTF-8';
    if ($enc !== 'UTF-8') {
        $text = mb_convert_encoding($text, 'UTF-8', $enc);
    }
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    $rows = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) as $n => $line) {
        if (trim($line) === '') {
            continue;
        }
        $delim = strpos($line, "\t") !== false ? "\t" : ',';
        $c = array_map('trim', str_getcsv($line, $delim, '"', ''));
        if ($n === 0 && preg_match('/^(姓|氏名|名前|family|name)/iu', $c[0])) {
            continue; // 見出し行
        }
        $rows[$n + 1] = $c;
    }
    return $rows;
}

/** 名簿の部門の欄のうち、登録してある部門に当てはまらない表記(重複なし) */
function unknown_categories(array $rows)
{
    $u = [];
    foreach ($rows as $c) {
        $t = trim($c[3] ?? '');
        if ($t !== '' && Categories::resolve($t) === null) {
            $u[$t] = true;
        }
    }
    return array_keys($u);
}

/**
 * 名簿を取り込む。列: 姓,名,所属,部門,的(3A など)。姓だけでもよい(名・所属は空欄)。
 * 部門が空なら並び順が一番若い部門。登録してない部門の行はエラー(先に unknown_categories で部門を作る)。
 */
function import_entries(array $rows)
{
    $added = 0;
    $errors = [];
    $first = Categories::firstId();
    foreach ($rows as $n => $c) {
        $pos = $c[4] ?? '';
        $target = 0;
        $letter = '';
        if ($pos !== '') {
            if (!preg_match('/^(\d+)\s*([A-Fa-f])$/', $pos, $m)) {
                $errors[] = $n . '行目: 的の指定「' . $pos . '」が不正です(例 3A)';
                continue;
            }
            $target = (int)$m[1];
            $letter = strtoupper($m[2]);
        }
        $catText = trim($c[3] ?? '');
        $cat = $catText === '' ? $first : Categories::resolve($catText);
        if ($cat === null) {
            $errors[] = $n . '行目: 部門「' . $catText . '」がありません';
            continue;
        }
        try {
            Entries::save([
                'family_name' => $c[0] ?? '', 'given_name' => $c[1] ?? '', 'affiliation' => $c[2] ?? '',
                'category_id' => $cat, 'target' => $target, 'letter' => $letter,
            ]);
            $added++;
        } catch (InvalidArgumentException $e) {
            $errors[] = $n . '行目: ' . $e->getMessage();
        } catch (PDOException $e) {
            $errors[] = $n . '行目: 登録できません(' . $e->getMessage() . ')';
        }
    }
    return [$added, $errors];
}

/** 画面に渡す参加者の一覧 */
function entry_rows()
{
    $total = Settings::totalArrows();
    $rows = [];
    foreach (Entries::all(false) as $e) {
        $rows[] = [
            'id' => (int)$e['id'],
            'family' => $e['family_name'],
            'given' => $e['given_name'],
            'aff' => $e['affiliation'],
            'catId' => (int)$e['category_id'],
            'cat' => (string)$e['category_name'],
            'tgt' => $e['target'] ? $e['target'] . $e['letter'] : '',
            'status' => (int)$e['status'],
            'hits' => Scoring::total(Entries::allArrows($e))[3],
            'total' => $total,
            'locked' => (int)$e['locked'],
            'scored' => Entries::hasScore($e),
        ];
    }
    return $rows;
}

// ---- Ajax
$act = $_REQUEST['act'] ?? '';
if ($act !== '') {
    if (in_array($act, ['save', 'delete', 'pastadd'], true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['ok' => false, 'msg' => 'POST で送ってください']);   // データを変える操作は POST(CSRF 確認あり)だけ
    }
    try {
        switch ($act) {
            case 'save':      // 追加(id=0)・編集
                $id = (int)($_POST['id'] ?? 0);
                $d = [
                    'family_name' => $_POST['family'] ?? '', 'given_name' => $_POST['given'] ?? '',
                    'affiliation' => $_POST['aff'] ?? '', 'category_id' => (int)($_POST['catId'] ?? 0),
                    'status' => !empty($_POST['status']),
                ];
                if ($id && !Entries::find($id)) {
                    throw new InvalidArgumentException('参加者が見つかりません');
                }
                $id = Entries::save($d, $id);
                json_out(['ok' => true, 'id' => $id, 'msg' => trim($d['family_name'] . ' ' . $d['given_name']) . ' を' . ((int)($_POST['id'] ?? 0) ? '保存' : '追加') . 'しました', 'rows' => entry_rows()]);
            case 'delete':
                $e = Entries::find((int)($_POST['id'] ?? 0));
                if (!$e) {
                    throw new InvalidArgumentException('参加者が見つかりません');
                }
                if (Entries::hasScore($e)) {
                    throw new InvalidArgumentException('点数が入っている参加者は削除できません(欠場にしてください)');
                }
                Entries::delete($e['id']);
                json_out(['ok' => true, 'msg' => Entries::fullName($e) . ' を削除しました', 'rows' => entry_rows()]);
            case 'teams':
            case 'archers':
            case 'pastadd':
                // 過去の記録会(ほかの記録会)の情報は、全体管理者だけ。個別管理では使えない
                if (!Lite\Auth::isAdmin()) {
                    throw new InvalidArgumentException('過去の記録会から選べるのは全体管理者だけです');
                }
        }
        switch ($act) {
            case 'teams':     // 過去の記録会の所属(チーム)
                json_out(['ok' => true, 'teams' => Past::teams()]);
            case 'archers':   // 過去の記録会の、その所属の参加者
                json_out(['ok' => true, 'archers' => Past::archers($_GET['team'] ?? '')]);
            case 'pastadd':   // 過去の記録会の参加者を登録
                $r = Past::add((array)($_POST['src'] ?? []));
                json_out(['ok' => true, 'msg' => $r['added'] . '人を追加しました' . ($r['skipped'] ? '(登録済みなど ' . $r['skipped'] . '人は飛ばしました)' : ''), 'rows' => entry_rows()]);
        }
        json_out(['ok' => false, 'msg' => '不明な操作です']);
    } catch (InvalidArgumentException $e) {
        json_out(['ok' => false, 'msg' => $e->getMessage()]);
    }
}

// ---- CSV の取り込み(フォーム)。登録してない部門があれば、先にその部門のコードを入れる画面へ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['do'] ?? '', ['import', 'import_cats', 'import_cancel'], true)) {
    $do = $_POST['do'];
    if ($do === 'import_cancel') {
        unset($_SESSION['lite_import']);
        flash('取り込みをやめました', 'warn');
        redirect('entries.php');
    }
    if ($do === 'import') {
        $text = $_POST['csv'] ?? '';
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            $text = file_get_contents($_FILES['file']['tmp_name']);
        }
        $rows = parse_entries_csv($text);
    } else {
        $rows = $_SESSION['lite_import'] ?? [];
        try {
            Lite\Db::immediate(function () {
                foreach ((array)($_POST['cat_code'] ?? []) as $i => $code) {
                    Categories::add($code, $_POST['cat_name'][$i] ?? '');
                }
            });
        } catch (InvalidArgumentException $e) {
            flash($e->getMessage(), 'err');
            redirect('entries.php?catmap=1');
        }
    }
    if (unknown_categories($rows)) {
        $_SESSION['lite_import'] = $rows;
        redirect('entries.php?catmap=1');
    }
    unset($_SESSION['lite_import']);
    list($added, $errors) = import_entries($rows);
    flash($added . ' 人を取り込みました', $added ? 'ok' : 'warn');
    foreach (array_slice($errors, 0, 10) as $er) {
        flash($er, 'err');
    }
    redirect('entries.php');
}

// 登録してない部門のコードを入れる画面(CSV の取り込みの途中)
if (isset($_GET['catmap']) && !empty($_SESSION['lite_import'])) {
    $unknown = unknown_categories($_SESSION['lite_import']);
    page_head('部門の追加', 'entries');
    echo '<form method="post" class="card">' . csrf_field() . '<input type="hidden" name="do" value="import_cats">'
        . '<h2 style="margin-top:0">名簿に、まだ無い部門があります</h2>'
        . '<p class="sp-note">それぞれの部門のコード(英数字 8 文字まで)を入れると、部門を作ってから名簿を取り込みます。</p>'
        . '<table class="sp-cat-table map"><thead><tr><th>部門名</th><th>コード</th></tr></thead><tbody>';
    foreach ($unknown as $i => $name) {
        echo '<tr><td><input type="text" name="cat_name[' . $i . ']" value="' . h($name) . '" required></td>'
            . '<td><input type="text" name="cat_code[' . $i . ']" maxlength="8" autocapitalize="characters" required' . ($i === 0 ? ' autofocus' : '') . '></td></tr>';
    }
    echo '</tbody></table><div class="sp-sheet-actions" style="margin-top:12px">'
        . '<button type="submit" class="sp-btn sub" form="ImportCancel">やめる</button><button class="sp-btn">部門を作って取り込む</button></div></form>'
        . '<form method="post" id="ImportCancel">' . csrf_field() . '<input type="hidden" name="do" value="import_cancel"></form>';
    page_foot('entries');
    exit;
}

$cats = [];
foreach (Categories::all() as $c) {
    $cats[] = ['id' => (int)$c['id'], 'name' => $c['name']];
}
page_head('参加者登録', 'entries', ['js' => ['LiteRows' => entry_rows(), 'LiteCats' => $cats, 'LiteAffs' => Past::affiliations(300, Lite\Auth::isAdmin()), 'LitePast' => Lite\Auth::isAdmin()]]);
?>
<div class="sp-toolbar">
	<label class="sp-search"><?php echo icon('search'); ?><input type="search" id="SpSearch" placeholder="名前・所属で検索"></label>
	<div class="sp-chips" id="SpCatChips"></div>
</div>
<div class="sp-row" style="margin-bottom:8px;align-items:center">
	<div class="sp-count" id="SpCount"></div>
	<button type="button" class="sp-btn sub" style="flex:none" id="SpAddBtn"><?php echo icon('plus'); ?>追加</button>
</div>
<div class="sp-list" id="SpList"></div>
<p class="sp-note">カードをタップすると、名前・所属・部門を変更できます。</p>
<details class="card"><summary>CSVでまとめて登録</summary>
	<form method="post" enctype="multipart/form-data"><?php echo csrf_field(); ?><input type="hidden" name="do" value="import">
	<p class="sp-note">列: <code>姓,名,所属,部門,的番号</code> (カンマ or タブ区切りで入力。姓のみ必須入力。1行目が見出しなら除外して取込。)</p>
	<label class="sp-field"><span>ファイルから</span><input type="file" name="file" accept=".csv,.tsv,.txt"></label>
	<label class="sp-field"><span>または貼り付け</span><textarea name="csv" rows="5" style="width:100%" placeholder="山田,太郎,京都大学,男子&#10;佐藤,花子,同志社大学,女子"></textarea></label>
	<button class="sp-btn">取り込む</button></form>
	<p><a class="sp-btn sub" href="export.php?what=entries">名簿をCSVで出力</a></p>
</details>
<datalist id="SpAffs"></datalist>

<script>
const SpFilter = {cat: spStore('ent_cat') || ''};

function spEntCard(r) {
	const state = r.status ? '<span class="sp-tag warn">欠場</span>'
		: (r.hits ? '<span class="sp-tag' + (r.hits >= r.total ? ' ok' : '') + '">' + r.hits + '/' + r.total + '射</span>' : '');
	return '<div class="sp-card tap' + (r.status ? ' status-noshoot' : '') + '" data-id="' + r.id + '" data-cat="' + r.catId + '" data-search="' + spEsc(spNorm([r.family, r.given, r.family + r.given, r.aff, r.cat, r.tgt].join(' '))) + '">'
		+ '<div class="sp-card-head">'
		+ '<span class="sp-badge' + (r.tgt ? '' : ' none') + '">' + spEsc(r.tgt || '-') + '</span>'
		+ '<div class="sp-card-main">'
		+ '<div class="sp-name">' + spEsc(r.family + ' ' + r.given) + '<span class="sp-tag">' + spEsc(r.cat) + '</span></div>'
		+ '<div class="sp-sub">' + spEsc(r.aff || '(所属なし)') + '</div>'
		+ '</div>'
		+ '<div class="sp-score">' + state + (r.locked ? '<span class="sp-tag">確定</span>' : '') + '</div>'
		+ '</div></div>';
}

function spEntRender() {
	document.getElementById('SpList').innerHTML = LiteRows.length ? LiteRows.map(spEntCard).join('') : '<div class="sp-empty">参加者がいません。「＋ 追加」から登録してください</div>';
	document.getElementById('SpCatChips').innerHTML = '<button type="button" class="sp-chip' + (SpFilter.cat ? '' : ' active') + '" data-cat="">すべて</button>'
		+ LiteCats.map(c => '<button type="button" class="sp-chip' + (String(c.id) === SpFilter.cat ? ' active' : '') + '" data-cat="' + c.id + '">' + spEsc(c.name) + '</button>').join('');
	spEntFilter();
}

function spEntFilter() {
	const n = spFilterCards(document.getElementById('SpList'), document.getElementById('SpSearch').value,
		card => !SpFilter.cat || card.dataset.cat === SpFilter.cat);
	document.getElementById('SpCount').textContent = n + ' 人' + (n !== LiteRows.length ? '(全 ' + LiteRows.length + ' 人)' : '');
}

function spCatOptions(sel) {
	return LiteCats.map(c => '<option value="' + c.id + '"' + (c.id == sel ? ' selected' : '') + '>' + spEsc(c.name) + '</option>').join('');
}

function spNameFields(r) {
	return '<div class="sp-row">'
		+ '<label class="sp-field"><span>姓</span><input type="text" data-f="family" value="' + spEsc(r.family) + '" autocomplete="off"></label>'
		+ '<label class="sp-field"><span>名</span><input type="text" data-f="given" value="' + spEsc(r.given) + '" autocomplete="off"></label></div>'
		+ '<label class="sp-field"><span>所属</span><input type="text" data-f="aff" list="SpAffs" value="' + spEsc(r.aff) + '" autocomplete="off"></label>'
		+ '<label class="sp-field"><span>部門</span><select data-f="catId">' + spCatOptions(r.catId) + '</select></label>';
}

function spFormValues(el) {
	const v = {};
	el.querySelectorAll('[data-f]').forEach(i => v[i.dataset.f] = i.type === 'checkbox' ? (i.checked ? '1' : '') : i.value.trim());
	return v;
}

async function spEntSave(params) {
	const res = await spPostJson('entries.php', Object.assign({act: 'save'}, params));
	spToast(res.msg, !res.ok);
	if (res.ok) { LiteRows = res.rows; spEntRender(); }
	return res.ok;
}

/* ---- 編集(カードをタップ) ---- */
function spOpenEdit(r) {
	const html = '<h2>' + spEsc(r.family + ' ' + r.given) + '</h2>'
		+ '<div class="sp-sub">' + (r.tgt ? spEsc(r.tgt) : '的番号未割当') + (r.hits ? '/' + r.hits + '射入力済' : '') + '</div>'
		+ spNameFields(r)
		+ '<label class="sp-switch" style="margin:4px 0 8px"><input type="checkbox" data-f="status"' + (r.status ? ' checked' : '') + '> 欠場(端末・結果から外す)</label>'
		+ (r.scored ? '<p class="sp-note">点数が入っているため削除できません。</p>' : '<button type="button" class="sp-btn danger block" id="SpDelBtn">この参加者を削除</button>')
		+ '<div class="sp-sheet-actions"><button type="button" class="sp-btn sub" onclick="spSheet.close()">閉じる</button><button type="button" class="sp-btn" id="SpSaveBtn">保存</button></div>';
	const el = spSheet.open(html);
	el.querySelector('#SpSaveBtn').addEventListener('click', async () => {
		if (await spEntSave(Object.assign({id: r.id}, spFormValues(el)))) spSheet.close();
	});
	const del = el.querySelector('#SpDelBtn');
	if (del) del.addEventListener('click', async () => {
		if (!confirm(r.family + ' ' + r.given + ' さんを削除します。よろしいですか？')) return;
		const res = await spPostJson('entries.php', {act: 'delete', id: r.id});
		spToast(res.msg, !res.ok);
		if (res.ok) { LiteRows = res.rows; spEntRender(); spSheet.close(); }
	});
}

/* ---- 追加(新しく入力 / 過去の記録会から) ---- */
function spOpenAdd() {
	const blank = {family: '', given: '', aff: '', catId: SpFilter.cat || (LiteCats[0] || {}).id};
	const html = '<h2>参加者を追加</h2>'
		// 過去の記録会から選ぶのは全体管理者だけ(個別管理は新しく入力だけ)
		+ (LitePast ? '<div class="sp-seg"><button type="button" data-tab="new" class="active">新しく入力</button><button type="button" data-tab="past">過去の記録会から</button></div>' : '')
		+ '<div data-pane="new">' + spNameFields(blank)
		+ '<div class="sp-sheet-actions"><button type="button" class="sp-btn sub" onclick="spSheet.close()">閉じる</button><button type="button" class="sp-btn" id="SpAddGo1">追加</button></div>'
		+ '<p class="sp-note">追加したあとも、続けて入力できます。</p></div>'
		+ '<div data-pane="past" class="sp-hide">'
		+ '<div class="sp-sub">このサーバーにある、ほかの記録会の参加者から選びます。</div>'
		+ '<label class="sp-field"><span>1. 所属</span><select id="SpPastTeam"><option value="">読み込み中…</option></select></label>'
		+ '<div class="sp-hide" id="SpPastField"><div class="sp-field"><span>2. 追加する参加者</span></div>'
		+ '<label class="sp-switch" style="margin-bottom:6px"><input type="checkbox" id="SpPastAll"> すべて選ぶ</label>'
		+ '<div class="sp-check-list" id="SpPastList"></div></div>'
		+ '<div class="sp-sheet-actions"><button type="button" class="sp-btn sub" onclick="spSheet.close()">閉じる</button><button type="button" class="sp-btn" id="SpAddGo2" disabled>追加する</button></div></div>';
	const el = spSheet.open(html);
	el.querySelectorAll('.sp-seg [data-tab]').forEach(b => b.addEventListener('click', () => {
		el.querySelectorAll('.sp-seg [data-tab]').forEach(x => x.classList.toggle('active', x === b));
		el.querySelectorAll('[data-pane]').forEach(p => p.classList.toggle('sp-hide', p.dataset.pane !== b.dataset.tab));
		if (b.dataset.tab === 'past') spPastTeams(el);
	}));
	el.querySelector('#SpAddGo1').addEventListener('click', async () => {
		const v = spFormValues(el.querySelector('[data-pane=new]'));
		if (!v.family && !v.given) { spToast('姓か名を入れてください', true); return; }
		if (await spEntSave(Object.assign({id: 0}, v))) {
			el.querySelector('[data-f=family]').value = '';
			el.querySelector('[data-f=given]').value = '';
			el.querySelector('[data-f=family]').focus();
		}
	});
	el.querySelector('#SpPastTeam').addEventListener('change', () => spPastArchers(el));
	el.querySelector('#SpPastAll').addEventListener('change', ev => {
		el.querySelectorAll('#SpPastList input:not(:disabled)').forEach(c => c.checked = ev.target.checked);
		spPastCount(el);
	});
	el.querySelector('#SpPastList').addEventListener('change', () => spPastCount(el));
	el.querySelector('#SpAddGo2').addEventListener('click', async () => {
		const src = [...el.querySelectorAll('#SpPastList input:checked')].map(c => c.value);
		if (!src.length) return;
		const res = await spPostJson('entries.php', {act: 'pastadd', 'src[]': src});
		spToast(res.msg, !res.ok);
		if (res.ok) { LiteRows = res.rows; spEntRender(); spPastArchers(el); }
	});
	setTimeout(() => el.querySelector('[data-f=family]').focus(), 250);
}

async function spPastTeams(el) {
	const sel = el.querySelector('#SpPastTeam');
	if (sel.dataset.loaded) return;
	try {
		const res = await spGetJson('entries.php', {act: 'teams'});
		sel.dataset.loaded = '1';
		const last = spStore('ent_past_team') || '';
		sel.innerHTML = res.teams.length
			? '<option value="">-- 選んでください --</option>' + res.teams.map(t => '<option value="' + spEsc(t.name) + '"' + (t.name === last ? ' selected' : '') + '>' + spEsc(t.name + '(' + t.count + '人)') + '</option>').join('')
			: '<option value="">ほかの記録会に参加者がいません</option>';
		if (sel.value) spPastArchers(el);
	} catch (e) {
		spToast('読み込めませんでした: ' + e.message, true);
	}
}

async function spPastArchers(el) {
	const team = el.querySelector('#SpPastTeam').value;
	const field = el.querySelector('#SpPastField'), list = el.querySelector('#SpPastList');
	el.querySelector('#SpPastAll').checked = false;
	if (!team) { field.classList.add('sp-hide'); list.innerHTML = ''; spPastCount(el); return; }
	spStore('ent_past_team', team);
	field.classList.remove('sp-hide');
	list.innerHTML = '<div class="sp-sub">読み込み中…</div>';
	try {
		const res = await spGetJson('entries.php', {act: 'archers', team: team});
		list.innerHTML = res.archers.length ? res.archers.map(a =>
			'<label class="sp-check' + (a.registered ? ' done' : '') + '"><input type="checkbox" value="' + spEsc(a.src) + '"' + (a.registered ? ' disabled' : '') + '>'
			+ '<span><b>' + spEsc(a.family + ' ' + a.given) + '</b>' + (a.registered ? ' <span class="sp-tag">登録済み</span>' : '')
			+ '<small>' + spEsc([a.category_name, '前回 ' + a.last].filter(x => x).join(' / ')) + '</small></span></label>').join('')
			: '<div class="sp-sub">選べる参加者がいません</div>';
	} catch (e) {
		spToast('読み込めませんでした: ' + e.message, true);
	}
	spPastCount(el);
}

function spPastCount(el) {
	const n = el.querySelectorAll('#SpPastList input:checked').length;
	const go = el.querySelector('#SpAddGo2');
	go.disabled = n === 0;
	go.textContent = n ? n + '人を追加する' : '追加する';
}

document.addEventListener('DOMContentLoaded', () => {
	document.getElementById('SpAffs').innerHTML = LiteAffs.map(a => '<option value="' + spEsc(a) + '">').join('');
	spEntRender();
	document.getElementById('SpSearch').addEventListener('input', spEntFilter);
	document.getElementById('SpCatChips').addEventListener('click', ev => {
		const b = ev.target.closest('[data-cat]');
		if (!b) return;
		SpFilter.cat = b.dataset.cat;
		spStore('ent_cat', SpFilter.cat);
		spEntRender();
	});
	document.getElementById('SpList').addEventListener('click', ev => {
		const card = ev.target.closest('.sp-card[data-id]');
		if (!card) return;
		const r = LiteRows.find(x => x.id == card.dataset.id);
		if (r) spOpenEdit(r);
	});
	document.getElementById('SpAddBtn').addEventListener('click', spOpenAdd);
});
</script>
<?php
page_foot('entries');
