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
                            throw new InvalidArgumentException('得点が入力済みのため、競技形式(射数・距離数)は変更できません(先に「得点の全消去」を行ってください)');
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
                    'archers_per_target' => max(1, min(4, (int)($_POST['archers_per_target'] ?? 4))),
                    'results_public' => empty($_POST['results_public']) ? '0' : '1',
                ], $faces, $ties));
                // 端末に保存した選手一覧・的面・距離の設定は、次の接続で作り直させる
                Db::pdo()->exec("UPDATE devices SET setup = '', setup_confirmed = 0");
                flash('大会設定を保存しました');
                break;

            case 'clear_scores':
                if (($_POST['confirm'] ?? '') !== 'yes') {
                    throw new InvalidArgumentException('確認のチェックを入れてください');
                }
                Db::pdo()->exec("UPDATE entries SET arrowstring = '', arrowstring2 = '', signed = 0, locked = 0, updated_at = ''");
                Db::pdo()->exec('DELETE FROM score_log');
                flash('得点を全消去しました', 'warn');
                break;

            case 'password':
                if (($_POST['password'] ?? '') !== ($_POST['password2'] ?? '')) {
                    throw new InvalidArgumentException('確認用パスワードが一致しません');
                }
                Lite\Auth::setPassword($_POST['password'] ?? '');
                flash('管理パスワードを変更しました');
                break;
        }
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'err');
    }
    redirect('setup.php');
}

$s = Settings::all();
$cat = Settings::category();
$nd = Settings::distances();
page_head('大会設定', 'home');
echo '<form method="post" class="card wideform">' . csrf_field() . '<input type="hidden" name="do" value="settings">';
echo '<label>大会名</label><input type="text" name="name" value="' . h($s['name']) . '">';

echo '<label>競技形式</label><select name="preset" id="preset">';
foreach (Settings::PRESETS as $k => $p) {
    echo '<option value="' . $k . '" data-nd="' . $p['distances'] . '" data-cat="' . $p['category'] . '"' . (Settings::preset() === $p ? ' selected' : '') . '>' . h($p['label']) . '</option>';
}
echo '</select>';

// 的の種類(前半・後半とも同じ的)
echo '<label>的の種類</label><select name="target_face" id="face">';
foreach (Settings::faceOptions() as $id => $label) {
    echo '<option value="' . $id . '"' . (Settings::targetFace() === $id ? ' selected' : '') . '>' . h($label) . '</option>';
}
echo '</select>';

echo '<label>結果の公開</label><label class="sp-switch"><input type="checkbox" name="results_public" value="1"' . (Settings::resultsPublic() ? ' checked' : '') . '> 結果を公開する(ログインなしで見られる)</label>'
    . '<p class="sp-note">公開用のURL: <code>' . h(server_base_url() . 'results.php?c=' . Settings::code()) . '</code>(非公開のときは、この大会を開いている管理者だけが見られます)</p>';

// 詳細設定(ふだんは閉じておく): 距離の名称、同点のときの優先順位
echo '<details class="sp-advanced"><summary>詳細設定</summary>';
echo '<label>距離の名称</label><div class="row nowrap wide">';
foreach ([1, 2] as $d) {
    echo '<div id="dist' . $d . '"><input type="text" name="distance_name' . ($d === 2 ? '2' : '') . '" id="dname' . $d . '" value="' . h(Settings::distanceName($d)) . '" placeholder="距離' . $d . '" aria-label="距離' . $d . 'の名称"></div>';
}
echo '</div><p class="sp-note">距離が1つなら空欄、2つなら 前半・後半 が初期値です。</p>';
echo '<div class="row nowrap wide">';
foreach ([1 => '第1優先順位', 2 => '第2優先順位'] as $n => $label) {
    echo '<div><label>' . $label . '</label><input type="text" name="tie' . $n . '" id="tie' . $n . '" value="' . h(implode(',', Settings::tiePoints($n))) . '" autocapitalize="characters">'
        . '<div class="sp-sub">見出し: <b id="tielbl' . $n . '">' . h(Settings::tieLabel($n)) . '</b></div></div>';
}
echo '</div><p class="sp-note">合計点が同じときは、第1優先順位の点の本数 → 第2優先順位の点の本数 の多い順にします。点はカンマ区切りで入力します(例 <code>10,X</code> → 見出しは 10+X)。'
    . '初期値は アウトドア 第1=X・第2=10、インドア 第1=10・第2=9 です。</p>';
echo '</details>';
echo '<input type="hidden" name="archers_per_target" value="' . h($s['archers_per_target']) . '">';
echo '<p class="muted small">大会コード <code>' . h(Settings::code()) . '</code> は自動で決まります(端末の接続は「端末接続」のQRで行います)。</p>';
echo '<p><button>保存</button></p></form>';

// ---- 管理
echo '<h2>管理</h2><div class="row"><form method="post" class="card" style="flex:1 1 20rem">' . csrf_field() . '<input type="hidden" name="do" value="password">'
    . '<b>この大会のパスワードの変更</b><p class="sp-note">パスワードはこの大会の DB に保存します。</p><label>新しいパスワード</label><input type="password" name="password" required><label>確認</label><input type="password" name="password2" required><p><button class="sub">変更</button></p></form>';
echo '<form method="post" class="card" style="flex:1 1 20rem" onsubmit="return confirm(\'全員の得点を消します。よろしいですか?\')">' . csrf_field() . '<input type="hidden" name="do" value="clear_scores">'
    . '<b>得点の全消去</b><p class="muted small">リハーサル後などに、選手名簿はそのままで得点だけを消します。</p>'
    . '<label style="font-weight:400"><input type="checkbox" name="confirm" value="yes"> 本当に消す</label><p><button class="danger">得点を全消去</button></p></form></div>';
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
