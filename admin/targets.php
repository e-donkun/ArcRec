<?php
// 的番号の設定。登録した選手を、的番号と位置(A〜D)に割り当てる。
// 2 つのやり方がある(どちらも同じ処理 Entries::place を呼ぶ):
//   ・割り当て表: 選手をタップして、空き枠(または入れ替えたい選手)をタップする。下の「未割当」からも選べる
//   ・一覧: 選手ごとのプルダウンで、的番号と位置を選ぶ
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
page_head('的番号', 'targets');
echo '<h1>的番号 <span class="muted small">' . count($state['entries']) . ' 人</span></h1>';
if (!$state['entries']) {
    echo '<div class="flash warn">選手がいません。先に <a href="entries.php">選手登録</a> をしてください。</div>';
}
?>
<div class="tg-toolbar">
  <div class="tg-chips" id="tgView">
    <button type="button" class="chip on" data-view="board">割り当て表</button>
    <button type="button" class="chip" data-view="list">一覧</button>
  </div>
  <div class="tg-chips" id="tgCats">
    <button type="button" class="chip on" data-cat="0">すべて</button>
<?php foreach (Categories::all() as $c) { ?>
    <button type="button" class="chip" data-cat="<?php echo (int)$c['id']; ?>"><?php echo h($c['name']); ?></button>
<?php } ?>
  </div>
</div>

<div id="tgBoardView">
  <p class="muted small">選手をタップして(下の「未割当」からも選べます)、空き枠をタップすると移動します。選手のいる枠をタップすると入れ替えます。🔒は点数が入っていて動かせません。</p>
  <div class="tg-board" id="tgBoard"></div>
  <p><button type="button" class="sub" id="tgAddTarget">＋ 的を追加</button></p>
</div>

<div id="tgListView" class="tg-hide">
  <p class="muted small">選手ごとに、的番号と位置をプルダウンで選びます。位置を選ばないときは、その的の空いている最初の位置に入ります。</p>
  <div class="tablewrap"><table>
    <thead><tr><th>氏名</th><th>所属 / 部門</th><th class="c">的</th><th class="c">位置</th></tr></thead>
    <tbody id="tgList"></tbody>
  </table></div>
</div>

<div class="card tg-auto noprint">
  <b>自動で割り当てる</b>
  <form method="post" class="inline-form" onsubmit="return confirm('点数のない選手の的番号を、部門順・登録順に付け直します。よろしいですか?')">
    <?php echo csrf_field(); ?><input type="hidden" name="do" value="auto">
    <label class="inline-label">1的あたり <input type="number" name="per_target" min="1" max="4" value="<?php echo (int)$state['per']; ?>" style="width:4.5rem"> 人</label>
    <label class="inline-label">開始の的 <input type="number" name="first" min="1" value="1" style="width:5rem"></label>
    <button class="sub">自動で割り当てる</button>
  </form>
  <form method="post" class="inline-form" onsubmit="return confirm('点数のない選手を、すべて未割当に戻します。よろしいですか?')">
    <?php echo csrf_field(); ?><input type="hidden" name="do" value="clear"><button class="sub">割り当てをすべて外す</button>
  </form>
  <p class="muted small" style="margin:.4rem 0 0">得点のある選手は動かしません。</p>
</div>

<div class="tg-tray tg-hide" id="tgTray">
  <div class="tg-sel tg-hide" id="tgSel"><span id="tgSelText"></span>
    <button type="button" class="sm sub" id="tgUnplace">未割当に戻す</button><button type="button" class="sm sub" id="tgCancel">やめる</button></div>
  <div class="tg-tray-head">未割当 <b id="tgFree">0</b> 人</div>
  <div class="tg-tray-list" id="tgFreeList"></div>
</div>
<div id="tgToast" class="tg-toast tg-hide" role="status"></div>

<script>
(function () {
  var state = <?php echo json_encode($state, JSON_UNESCAPED_UNICODE); ?>;
  var CSRF = <?php echo json_encode(Lite\Auth::csrf()); ?>;
  var LETTERS = ['A', 'B', 'C', 'D'];
  var sel = 0, extra = 0, view = 'board', catFilter = 0, busy = false;

  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); }
  function byId(id) { return state.entries.filter(function (e) { return e.id === id; })[0]; }
  function inFilter(e) { return !catFilter || e.catId === catFilter; }
  function slotOwner(t, l) { return state.entries.filter(function (e) { return e.t === t && e.l === l; })[0]; }
  function boardCount() {
    var m = state.n;
    state.entries.forEach(function (e) { if (e.t > m) m = e.t; });
    return m + extra;
  }

  function toast(msg, bad) {
    var el = $('tgToast');
    el.textContent = msg; el.className = 'tg-toast' + (bad ? ' bad' : '');
    clearTimeout(toast.t);
    toast.t = setTimeout(function () { el.className = 'tg-toast tg-hide'; }, 3200);
  }

  /* サーバーに置き直してもらう(入れ替え・点数ありの拒否はサーバーが判断する) */
  function place(id, t, l) {
    if (busy) return;
    busy = true;
    var body = new URLSearchParams({do: 'place', id: id, target: t, letter: l || '', _csrf: CSRF});
    fetch('targets.php', {method: 'POST', body: body, credentials: 'same-origin', headers: {'X-CSRF-Token': CSRF}})
      .then(function (r) { return r.json(); })
      .then(function (res) {
        busy = false;
        state = res.state;
        if (!res.ok) { toast(res.msg || '移せませんでした', true); }
        else {
          if (res.msg) toast(res.msg);
          sel = 0;
        }
        render();
      })
      .catch(function (e) { busy = false; toast('通信できませんでした: ' + e.message, true); render(); });
  }

  /* ---- 割り当て表 ---- */
  function chipHtml(e) {
    return '<span class="tg-name">' + esc(e.name) + '</span>' + (e.lock ? ' 🔒' : '');
  }
  function slotHtml(t, l) {
    var o = slotOwner(t, l), cls = 'tg-slot', attr = ' data-t="' + t + '" data-l="' + l + '"';
    if (o) {
      if (o.id === sel) cls += ' sel';
      if (!inFilter(o)) cls += ' dim';
      return '<div class="' + cls + '"' + attr + ' data-id="' + o.id + '"><span class="l">' + l + '</span>'
        + '<span class="tg-who"><b>' + chipHtml(o) + '</b><small>' + esc(o.aff) + (o.cat ? ' · ' + esc(o.cat) : '') + '</small></span></div>';
    }
    if (sel) cls += ' drop';
    return '<div class="' + cls + ' empty"' + attr + '><span class="l">' + l + '</span><span class="tg-who"><small>空き</small></span></div>';
  }
  function renderBoard() {
    var n = boardCount(), h = '';
    for (var t = 1; t <= n; t++) {
      var cnt = LETTERS.filter(function (l) { return slotOwner(t, l); }).length;
      h += '<div class="tg-card"><h3><span>的 ' + t + '</span><small>' + cnt + '/' + LETTERS.length + '</small></h3>';
      LETTERS.forEach(function (l) { h += slotHtml(t, l); });
      h += '</div>';
    }
    $('tgBoard').innerHTML = h;
  }

  /* ---- 未割当トレイ ---- */
  function renderTray() {
    var free = state.entries.filter(function (e) { return !e.t && inFilter(e); });
    var all = state.entries.filter(function (e) { return !e.t; }).length;
    $('tgFree').textContent = all;
    var h = '';
    free.forEach(function (e) {
      h += '<button type="button" class="chip tg-pick' + (e.id === sel ? ' on' : '') + '" data-id="' + e.id + '">' + esc(e.name)
        + '<small>' + esc(e.cat) + '</small></button>';
    });
    $('tgFreeList').innerHTML = h || '<span class="muted small">' + (all ? 'この部門の未割当はいません' : 'すべて割り当て済みです') + '</span>';
    var s = sel ? byId(sel) : null;
    $('tgSel').className = 'tg-sel' + (s ? '' : ' tg-hide');
    if (s) {
      $('tgSelText').textContent = '選択中: ' + s.name + (s.t ? '(' + s.t + s.l + ')' : '') + ' → 空き枠をタップ';
      $('tgUnplace').style.display = s.t ? '' : 'none';
    }
    // 未割当がいなくて、選択中の選手もいないときは、トレイを出さない(画面を狭くしない)
    $('tgTray').className = 'tg-tray' + (view === 'board' && (all || s) ? '' : ' tg-hide');
  }

  /* ---- 一覧 ---- */
  function renderList() {
    var n = boardCount(), h = '';
    state.entries.filter(inFilter).forEach(function (e) {
      var dis = e.lock ? ' disabled' : '';
      var to = '<option value="0">—</option>';
      for (var t = 1; t <= n + 2; t++) to += '<option value="' + t + '"' + (e.t === t ? ' selected' : '') + '>' + t + '</option>';
      var lo = e.t ? '' : '<option value="" selected>—</option>';
      LETTERS.forEach(function (l) {
        var o = slotOwner(e.t, l);
        lo += '<option value="' + l + '"' + (e.l === l ? ' selected' : '') + '>' + l + (o && o.id !== e.id ? ' (' + esc(o.name) + ')' : '') + '</option>';
      });
      h += '<tr data-id="' + e.id + '"><td><b>' + esc(e.name) + '</b>' + (e.lock ? ' 🔒' : '') + '</td>'
        + '<td class="small">' + esc(e.aff) + (e.cat ? ' · ' + esc(e.cat) : '') + '</td>'
        + '<td class="c"><select data-role="t"' + dis + '>' + to + '</select></td>'
        + '<td class="c"><select data-role="l"' + dis + (e.t ? '' : ' disabled') + '>' + lo + '</select></td></tr>';
    });
    $('tgList').innerHTML = h || '<tr><td colspan="4" class="muted">選手がいません</td></tr>';
  }

  function render() {
    $('tgBoardView').className = view === 'board' ? '' : 'tg-hide';
    $('tgListView').className = view === 'list' ? '' : 'tg-hide';
    if (view === 'board') renderBoard(); else renderList();
    renderTray();
  }

  /* ---- 操作 ---- */
  $('tgBoard').addEventListener('click', function (ev) {
    var el = ev.target.closest('.tg-slot');
    if (!el || busy) return;
    var t = +el.dataset.t, l = el.dataset.l, id = el.dataset.id ? +el.dataset.id : 0;
    if (!sel) {
      if (!id) { toast('先に動かしたい選手をタップしてください'); return; }
      var e = byId(id);
      if (e.lock) { toast('点数が入力済みの選手は動かせません', true); return; }
      sel = id; render(); return;
    }
    if (id === sel) { sel = 0; render(); return; }       // 同じ選手をもう一度タップ: 選択を外す
    place(sel, t, l);                                      // 空き枠なら移動、選手がいれば入れ替え(サーバーが判断)
  });
  $('tgFreeList').addEventListener('click', function (ev) {
    var el = ev.target.closest('.tg-pick');
    if (!el) return;
    var id = +el.dataset.id;
    sel = sel === id ? 0 : id;
    render();
  });
  $('tgUnplace').onclick = function () { if (sel) place(sel, 0, ''); };
  $('tgCancel').onclick = function () { sel = 0; render(); };
  $('tgAddTarget').onclick = function () { extra++; render(); };
  $('tgList').addEventListener('change', function (ev) {
    var tr = ev.target.closest('tr'); if (!tr) return;
    var id = +tr.dataset.id, t = +tr.querySelector('[data-role=t]').value, l = tr.querySelector('[data-role=l]').value;
    if (ev.target.dataset.role === 't' && t) {
      // 的を選び直したときは、位置はいまの位置のままにせず、空いている最初の位置にする
      var e = byId(id);
      l = (e && e.t === t) ? e.l : '';
    }
    if (!t) l = '';
    place(id, t, l);
  });
  $('tgView').addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-view]'); if (!b) return;
    view = b.dataset.view;
    [].forEach.call($('tgView').children, function (c) { c.classList.toggle('on', c === b); });
    sel = 0; render();
  });
  $('tgCats').addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-cat]'); if (!b) return;
    catFilter = +b.dataset.cat;
    [].forEach.call($('tgCats').children, function (c) { c.classList.toggle('on', c === b); });
    render();
  });
  render();
})();
</script>
<?php
page_foot();
