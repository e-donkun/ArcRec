<?php
require __DIR__ . '/_layout.php';
require_admin();

use Lite\Db;
use Lite\Entries;
use Lite\Settings;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        switch ($_POST['do'] ?? '') {
            case 'settings':
                $preset = $_POST['preset'] ?? '';
                if (!isset(Settings::PRESETS[$preset])) {
                    throw new InvalidArgumentException('競技形式を選んでください');
                }
                // 得点が入った後に射数・距離数を変えると、既存の得点が壊れるので止める
                $old = Settings::preset();
                $new = Settings::PRESETS[$preset];
                if ($old['distances'] !== $new['distances'] || $old['ends'] !== $new['ends'] || $old['arrows'] !== $new['arrows']) {
                    foreach (Entries::all() as $e) {
                        if (Entries::hasScore($e)) {
                            throw new InvalidArgumentException('得点が入力済みのため、競技形式(射数・距離数)は変更できません(先に「この記録会の点数を全消去」を行ってください)');
                        }
                    }
                }
                // 的の種類は、選んだ競技形式(屋外/屋内)で使えるものだけ。前半・後半とも同じ的
                $opts = Settings::FACES[$new['category']];
                $f = (int)($_POST['target_face'] ?? 0);
                $f = isset($opts[$f]) ? $f : array_key_first($opts);
                $faces = ['target_face1' => $f, 'target_face2' => $f];
                // 同点のときの優先順位。競技形式の初期値と同じなら空(形式を変えたときに初期値が付いてくる)
                $ties = [];
                foreach ([1, 2] as $n) {
                    $pts = Settings::parseTie($_POST['tie' . $n] ?? '');
                    $ties['tie' . $n] = $pts === Settings::tieDefault($n, $new['category']) ? '' : implode(',', $pts);
                }
                Settings::setMany(array_merge([
                    'name' => trim($_POST['name'] ?? ''),
                    'preset' => $preset,
                    'distance_name' => trim($_POST['distance_name'] ?? ''), 'distance_name2' => trim($_POST['distance_name2'] ?? ''),
                    'archers_per_target' => max(1, min(Settings::MAX_PER_TARGET, (int)($_POST['archers_per_target'] ?? 4))),
                ], $faces, $ties));
                // 端末に保存した参加者一覧・的面・距離の設定は、次の接続で作り直させる
                Db::pdo()->exec("UPDATE devices SET setup = '', setup_confirmed = 0");
                flash('記録会設定を保存しました');
                break;

            case 'categories':
                // 部門: 一覧の変更を保存 / 1 行削除(del) / 追加(add)
                Db::immediate(function () {
                    if (!empty($_POST['del'])) {
                        Lite\Categories::delete((int)$_POST['del']);
                        flash('部門を削除しました');
                        return;
                    }
                    foreach ((array)($_POST['code'] ?? []) as $id => $code) {
                        $c = Lite\Categories::find((int)$id);
                        $name = $_POST['cname'][$id] ?? '';
                        $sort = (int)($_POST['sort'][$id] ?? 0);
                        if ($c && ($c['code'] !== $code || $c['name'] !== $name || (int)$c['sort'] !== $sort)) {
                            Lite\Categories::update((int)$id, $code, $name, $sort);
                        }
                    }
                    if (!empty($_POST['add'])) {
                        $sort = trim((string)($_POST['new_sort'] ?? ''));
                        Lite\Categories::add($_POST['new_code'] ?? '', $_POST['new_name'] ?? '', $sort === '' ? null : (int)$sort);
                        flash('部門を追加しました');
                    } else {
                        flash('部門を保存しました');
                    }
                });
                // 端末の参加者一覧(部門コード)を次の接続で作り直させる
                Db::pdo()->exec("UPDATE devices SET setup = '', setup_confirmed = 0");
                redirect('setup.php#cats');

            case 'clear_scores':
                if (($_POST['confirm'] ?? '') !== 'yes') {
                    throw new InvalidArgumentException('確認のチェックを入れてください');
                }
                Db::pdo()->exec("UPDATE entries SET arrowstring = '', arrowstring2 = '', signed = 0, locked = 0, updated_at = ''");
                Db::pdo()->exec('DELETE FROM score_log');
                flash('この記録会の点数を全消去しました', 'warn');
                break;

            case 'delete':
                // この記録会の DB ファイルを削除して、管理者メニューへ
                if (($_POST['confirm'] ?? '') !== 'yes') {
                    throw new InvalidArgumentException('確認のチェックを入れてください');
                }
                $name = Settings::title();
                Lite\Competitions::delete(Settings::code());
                Lite\Auth::closeCompetition();
                flash('記録会「' . $name . '」を削除しました', 'warn');
                redirect('index.php');

            case 'password':
                if (($_POST['password'] ?? '') !== ($_POST['password2'] ?? '')) {
                    throw new InvalidArgumentException('確認用パスワードが一致しません');
                }
                Lite\Auth::setPassword($_POST['password'] ?? '');
                flash(($_POST['password'] ?? '') === '' ? 'この記録会のパスワードをなくしました(管理者パスワードでだけ開けます)' : 'この記録会のパスワードを変更しました');
                break;
        }
    } catch (InvalidArgumentException | RuntimeException $e) {
        flash($e->getMessage(), 'err');
    }
    redirect('setup.php');
}

$s = Settings::all();
$cat = Settings::category();
$nd = Settings::distances();
page_head('記録会設定', 'home');
echo '<form method="post" class="card wideform">' . csrf_field() . '<input type="hidden" name="do" value="settings">';
echo '<label>記録会コード</label><input type="text" value="' . h(Settings::code()) . '" readonly class="sp-readonly" aria-readonly="true">';
echo '<label>記録会名</label><input type="text" name="name" value="' . h($s['name']) . '">';

// 競技形式と的の種類(前半・後半とも同じ的)を横に並べる。結果の公開はホームのトグルで切り替える
echo '<div class="row nowrap wide"><div><label>競技形式</label><select name="preset" id="preset">';
foreach (Settings::PRESETS as $k => $p) {
    echo '<option value="' . $k . '" data-nd="' . $p['distances'] . '" data-cat="' . $p['category'] . '"' . (Settings::preset() === $p ? ' selected' : '') . '>' . h($p['label']) . '</option>';
}
echo '</select></div><div><label>的の種類</label><select name="target_face" id="face">';
foreach (Settings::faceOptions() as $id => $label) {
    echo '<option value="' . $id . '"' . (Settings::targetFace() === $id ? ' selected' : '') . '>' . h($label) . '</option>';
}
echo '</select></div></div>';

// 詳細設定(ふだんは閉じておく): 距離の名称、同点のときの優先順位
echo '<details class="sp-advanced"><summary>詳細設定</summary>';
echo '<label>距離の名称</label><div class="row nowrap wide">';
foreach ([1, 2] as $d) {
    echo '<div id="dist' . $d . '"><input type="text" name="distance_name' . ($d === 2 ? '2' : '') . '" id="dname' . $d . '" value="' . h(Settings::distanceName($d)) . '" placeholder="距離' . $d . '" aria-label="距離' . $d . 'の名称"></div>';
}
echo '</div>';
echo '<div class="row nowrap wide">';
foreach ([1 => '第1優先順位', 2 => '第2優先順位'] as $n => $label) {
    echo '<div><label>' . $label . '</label><input type="text" name="tie' . $n . '" id="tie' . $n . '" value="' . h(implode(',', Settings::tiePoints($n))) . '" autocapitalize="characters">'
        . '<div class="sp-sub">見出し: <b id="tielbl' . $n . '">' . h(Settings::tieLabel($n)) . '</b></div></div>';
}
echo '</div><p class="sp-note">カンマ区切りで入力(例 <code>10,X</code> → 見出しは 10+X)。</p>';
echo '</details>';
echo '<input type="hidden" name="archers_per_target" value="' . h($s['archers_per_target']) . '">';
echo '<p><button>保存</button></p></form>';

// ---- 部門(コード・部門名・並び順)。最後の 1 つは削除できない
$catsAll = Lite\Categories::all();
echo '<form method="post" class="card" id="cats">' . csrf_field() . '<input type="hidden" name="do" value="categories">'
    . '<button name="save" value="1" class="sp-offscreen" tabindex="-1" aria-hidden="true"></button>'   // Enter キーで送ったときは「保存」(削除ボタンにしない)
    . '<h2 style="margin-top:0">部門</h2><table class="sp-cat-table"><thead><tr><th>コード</th><th>部門名</th><th>並び順</th><th></th></tr></thead><tbody>';
foreach ($catsAll as $c) {
    $id = (int)$c['id'];
    echo '<tr><td><input type="text" name="code[' . $id . ']" value="' . h($c['code']) . '" maxlength="8" autocapitalize="characters" required></td>'
        . '<td><input type="text" name="cname[' . $id . ']" value="' . h($c['name']) . '" required></td>'
        . '<td><input type="text" inputmode="numeric" pattern="-?[0-9]*" name="sort[' . $id . ']" value="' . (int)$c['sort'] . '"></td>'
        . '<td>' . (count($catsAll) > 1
            ? '<button class="sub sp-icon-btn" name="del" value="' . $id . '" formnovalidate title="削除" aria-label="部門「' . h($c['name']) . '」を削除" onclick="return window.confirm(\'部門「' . h(addslashes($c['name'])) . '」を削除しますか?\')">' . icon('trash') . '</button>'
            : '') . '</td></tr>';
}
echo '</tbody><tfoot><tr><td><input type="text" name="new_code" maxlength="8" autocapitalize="characters" placeholder="例 RX"></td>'
    . '<td><input type="text" name="new_name" placeholder="部門名"></td>'
    . '<td><input type="text" inputmode="numeric" pattern="-?[0-9]*" name="new_sort" placeholder="最後"></td>'
    . '<td><button class="sub sp-icon-btn" name="add" value="1" title="追加" aria-label="部門を追加">' . icon('plus') . '</button></td></tr></tfoot></table>'
    . '<p class="sp-note">コードは英数字 8 文字まで</p>'
    . '<p><button>部門を保存</button></p></form>';

// ---- 管理(詳細設定と同じく、タップで開くアコーディオン。開くと3項目が縦に並ぶ)
echo '<details class="sp-advanced sp-manage"><summary>管理</summary>';
echo '<form method="post">' . csrf_field() . '<input type="hidden" name="do" value="password">'
    . '<h3>この記録会のパスワードの変更</h3>'
    . '<p class="muted small">記録会ごとに管理を任せる人のためのパスワード(任意)。空欄で変更すると、管理者パスワードでだけ開ける記録会になります。'
    . (Lite\Auth::hasCompetitionPassword(Settings::code()) ? '' : '<br>いまはパスワードなし(管理者のみ)です。') . '</p>'
    . '<label>新しいパスワード</label><input type="password" name="password" autocomplete="new-password">'
    . '<label>確認</label><input type="password" name="password2" autocomplete="new-password"><p><button class="sub">変更</button></p></form>';
echo '<form method="post" onsubmit="return window.confirm(\'この記録会の全員の点数を消します。よろしいですか?\')">' . csrf_field() . '<input type="hidden" name="do" value="clear_scores">'
    . '<h3>この記録会の点数を全消去</h3><p class="muted small">リハーサル後などに、参加者名簿はそのままで点数だけを消します。</p>'
    . '<label style="font-weight:400"><input type="checkbox" name="confirm" value="yes"> 本当に消す</label><p><button class="danger">点数を全消去</button></p></form>';
echo '<form method="post" onsubmit="return window.confirm(\'この記録会を削除します。参加者名簿・点数・設定がすべて消え、元に戻せません。よろしいですか?\')">' . csrf_field() . '<input type="hidden" name="do" value="delete">'
    . '<h3>この記録会を削除</h3><p class="muted small">この記録会のデータベースファイルを削除します。参加者名簿・点数・設定がすべて消え、元に戻せません。削除すると記録会の一覧に戻ります。</p>'
    . '<label style="font-weight:400"><input type="checkbox" name="confirm" value="yes"> 本当に削除する</label><p><button class="danger">この記録会を削除</button></p></form>';
echo '</details>';
echo '<script>(function(){'
    . 'var faces=' . json_encode(array_map(function ($o) {
        $r = [];
        foreach ($o as $id => $label) {
            $r[] = [$id, $label];
        }
        return $r;
    }, Settings::FACES), JSON_UNESCAPED_UNICODE) . ';'
    . 'var defaults=' . json_encode(Settings::DEFAULT_DISTANCE_NAMES, JSON_UNESCAPED_UNICODE) . ';'
    . 'var ties=' . json_encode(array_map(function ($c) {
        return [1 => implode(',', $c[1]), 2 => implode(',', $c[2])];
    }, Settings::TIE_DEFAULTS)) . ';'
    . 'var p=document.getElementById("preset");'
    . 'function opt(){return p.options[p.selectedIndex]}'
    . 'function names(){return opt().dataset.nd==="2"?[defaults[1],defaults[2]]:["",""]}'
    . 'var last=names(),lastCat=opt().dataset.cat;'
    // 優先順位の見出し(点を + でつなぐ)
    . 'function lbl(n){var v=document.getElementById("tie"+n).value.split(/[\s,，、+＋]+/).filter(function(x){return x}).map(function(x){return x.toUpperCase()});'
    . 'document.getElementById("tielbl"+n).textContent=v.join("+")}'
    . '[1,2].forEach(function(n){document.getElementById("tie"+n).addEventListener("input",function(){lbl(n)})});'
    . 'function u(){var o=opt(),nd=o.dataset.nd,cat=o.dataset.cat,n=names();'
    . 'document.getElementById("dist2").style.display=nd==="2"?"":"none";'
    // 名前が初期値のままなら、新しい形式の初期値に入れ替える(自分で入力した名前は残す)
    . '[1,2].forEach(function(d,i){var el=document.getElementById("dname"+d);if(el.value===last[i]||el.value===""){el.value=n[i]}});'
    // 的の種類の選択肢を、屋外/屋内に合わせて作り直す(選んでいる値が使えるなら保つ)
    . 'var sel=document.getElementById("face"),cur=sel.value;sel.innerHTML="";faces[cat].forEach(function(f){var op=document.createElement("option");op.value=f[0];op.textContent=f[1];if(String(f[0])===cur)op.selected=true;sel.appendChild(op)});'
    // 優先順位が初期値のままなら、屋外/屋内の初期値に入れ替える
    . 'if(cat!==lastCat){[1,2].forEach(function(k){var el=document.getElementById("tie"+k);if(el.value.replace(/\s/g,"").toUpperCase()===ties[lastCat][k]){el.value=ties[cat][k];lbl(k)}})}'
    . 'last=n;lastCat=cat}'
    . 'p.onchange=u;document.getElementById("dist2").style.display=' . ($nd === 2 ? '""' : '"none"') . ';'
    . '})()</script>';
page_foot('home');
