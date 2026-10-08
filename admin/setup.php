<?php
require __DIR__ . '/_layout.php';
require_admin();

use Lite\Categories;
use Lite\Db;
use Lite\Entries;
use Lite\Settings;
use Lite\TargetFaces;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        switch ($_POST['do'] ?? '') {
            case 'settings':
                $code = trim($_POST['code'] ?? '');
                if (!preg_match('/^[A-Za-z0-9_-]{1,16}$/', $code)) {
                    throw new InvalidArgumentException('大会コードは英数字・- _ で16文字以内にしてください');
                }
                $pin = trim($_POST['pin'] ?? '');
                if ($pin !== '' && !preg_match('/^[A-Za-z0-9]{1,8}$/', $pin)) {
                    throw new InvalidArgumentException('PIN は英数字8文字以内にしてください(使わない場合は空欄)');
                }
                $preset = $_POST['preset'] ?? 'custom';
                $ends = (int)($_POST['ends'] ?? 0);
                $arrows = (int)($_POST['arrows'] ?? 0);
                if ($preset !== 'custom' && isset(Settings::PRESETS[$preset])) {
                    $ends = Settings::PRESETS[$preset]['ends'];
                    $arrows = Settings::PRESETS[$preset]['arrows'];
                    $category = Settings::PRESETS[$preset]['category'];
                } else {
                    $category = (int)($_POST['category'] ?? 1) === 2 ? 2 : 1;
                }
                if ($ends < 1 || $ends > 40 || $arrows < 1 || $arrows > 6) {
                    throw new InvalidArgumentException('エンド数は1〜40、1エンドの矢数は1〜6にしてください');
                }
                // 得点が入った後に射数を変えると、既存の得点が壊れるので止める
                $cur = Settings::all();
                if (((int)$cur['ends'] !== $ends || (int)$cur['arrows'] !== $arrows)) {
                    foreach (Entries::all() as $e) {
                        if (trim($e['arrowstring']) !== '') {
                            throw new InvalidArgumentException('得点が入力済みのため、エンド数・矢数は変更できません(先に「得点の全消去」を行ってください)');
                        }
                    }
                }
                Settings::setMany([
                    'code' => $code, 'name' => trim($_POST['name'] ?? ''), 'place' => trim($_POST['place'] ?? ''),
                    'date' => trim($_POST['date'] ?? ''), 'organizer' => trim($_POST['organizer'] ?? ''),
                    'preset' => $preset, 'ends' => $ends, 'arrows' => $arrows, 'category' => $category,
                    'distance_name' => trim($_POST['distance_name'] ?? ''), 'pin' => $pin,
                    'gold_label' => trim($_POST['gold_label'] ?? '') ?: '10+X', 'x_label' => trim($_POST['x_label'] ?? '') ?: 'X',
                    'session_name' => trim($_POST['session_name'] ?? ''), 'server_url' => trim($_POST['server_url'] ?? ''),
                    'archers_per_target' => max(1, min(4, (int)($_POST['archers_per_target'] ?? 4))),
                ]);
                flash('競技設定を保存しました');
                break;

            case 'cat_save':
                Categories::save((int)($_POST['id'] ?? 0), $_POST['code'] ?? '', $_POST['name'] ?? '', (int)($_POST['targetface'] ?? 5), (int)($_POST['sort'] ?? 0));
                flash('部門を保存しました');
                break;

            case 'cat_delete':
                Categories::delete((int)$_POST['id']);
                flash('部門を削除しました(所属していた選手は「部門未設定」になります)');
                break;

            case 'cat_preset':
                // 代表的な部門を一括追加
                $face = (int)(Settings::PRESETS[Settings::get('preset')]['targetface'] ?? 5);
                foreach ([['RM', 'リカーブ男子'], ['RW', 'リカーブ女子'], ['CM', 'コンパウンド男子'], ['CW', 'コンパウンド女子']] as $c) {
                    Categories::save(0, $c[0], $c[1], substr($c[0], 0, 1) === 'C' && $face === 1 ? 3 : $face);
                }
                flash('部門を追加しました');
                break;

            case 'clear_scores':
                if (($_POST['confirm'] ?? '') !== 'yes') {
                    throw new InvalidArgumentException('確認のチェックを入れてください');
                }
                Db::pdo()->exec("UPDATE entries SET arrowstring = '', signed = 0, locked = 0, updated_at = ''");
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
page_head('競技設定', 'setup');
echo '<h1>競技設定</h1>';
echo '<form method="post" class="card">' . csrf_field() . '<input type="hidden" name="do" value="settings">';
echo '<div class="row"><div><label>大会名</label><input type="text" name="name" value="' . h($s['name']) . '"></div>'
    . '<div><label>大会コード <span class="muted small">(端末が使う英数字。例 KYOTO26)</span></label><input type="text" name="code" value="' . h($s['code']) . '" maxlength="16" required></div></div>';
echo '<div class="row"><div><label>開催日</label><input type="text" name="date" value="' . h($s['date']) . '" placeholder="2026-10-10"></div>'
    . '<div><label>会場</label><input type="text" name="place" value="' . h($s['place']) . '"></div>'
    . '<div><label>主催</label><input type="text" name="organizer" value="' . h($s['organizer']) . '"></div></div>';

echo '<label>競技形式</label><select name="preset" id="preset">';
foreach (Settings::PRESETS as $k => $p) {
    echo '<option value="' . $k . '"' . ($s['preset'] === $k ? ' selected' : '') . '>' . h($p['label']) . '</option>';
}
echo '<option value="custom"' . ($s['preset'] === 'custom' ? ' selected' : '') . '>その他(エンド数・矢数を指定)</option></select>';
echo '<div class="row" id="custom"><div><label>エンド数</label><input type="number" name="ends" min="1" max="40" value="' . h($s['ends']) . '"></div>'
    . '<div><label>1エンドの矢数</label><input type="number" name="arrows" min="1" max="6" value="' . h($s['arrows']) . '"></div>'
    . '<div><label>屋外/屋内</label><select name="category"><option value="1"' . ($s['category'] == 1 ? ' selected' : '') . '>屋外</option><option value="2"' . ($s['category'] == 2 ? ' selected' : '') . '>屋内</option></select></div></div>';
echo '<div class="row"><div><label>距離の表記</label><input type="text" name="distance_name" value="' . h($s['distance_name']) . '" placeholder="70m"></div>'
    . '<div><label>セッション名(任意)</label><input type="text" name="session_name" value="' . h($s['session_name']) . '" placeholder="Session 1"></div>'
    . '<div><label>1的あたりの人数(自動割当)</label><input type="number" name="archers_per_target" min="1" max="4" value="' . h($s['archers_per_target']) . '"></div></div>';
echo '<div class="row"><div><label>ゴールド欄の見出し</label><input type="text" name="gold_label" value="' . h($s['gold_label']) . '"></div>'
    . '<div><label>X欄の見出し</label><input type="text" name="x_label" value="' . h($s['x_label']) . '"></div></div>';

echo '<h2>端末(ScorekeeperNG)の接続</h2>';
echo '<div class="row"><div><label>PIN <span class="muted small">(任意。端末登録時に必要になります)</span></label><input type="text" name="pin" value="' . h($s['pin']) . '" maxlength="8"></div>'
    . '<div><label>サーバーURL <span class="muted small">(空欄なら今開いているURLから自動)</span></label><input type="text" name="server_url" value="' . h($s['server_url']) . '" placeholder="http://192.168.0.10/ianseo-lite/"></div></div>';
echo '<p><button>保存</button></p></form>';

// ---- 部門
echo '<h2>部門</h2><p class="muted small">順位は部門ごとに出ます。部門ごとに的面(得点入力画面の的)を選べます。</p>';
echo '<div class="tablewrap"><table><tr><th>並び</th><th>略号</th><th>部門名</th><th>的面</th><th></th></tr>';
$forms = '';
foreach (array_merge(Categories::all(), [['id' => 0, 'sort' => '', 'code' => '', 'name' => '', 'targetface' => (int)(Settings::PRESETS[$s['preset']]['targetface'] ?? 5)]]) as $c) {
    $id = (int)$c['id'];
    $f = 'cat' . $id;
    $forms .= '<form id="' . $f . '" method="post">' . csrf_field() . '<input type="hidden" name="do" value="cat_save"><input type="hidden" name="id" value="' . $id . '"></form>';
    echo '<tr><td><input form="' . $f . '" type="number" name="sort" value="' . h($c['sort']) . '" style="width:5rem"></td>'
        . '<td><input form="' . $f . '" type="text" name="code" value="' . h($c['code']) . '" maxlength="10" style="width:6rem"></td>'
        . '<td><input form="' . $f . '" type="text" name="name" value="' . h($c['name']) . '" required></td>'
        . '<td>' . faceSelect($c['targetface'], $f) . '</td><td><button form="' . $f . '" class="sm">' . ($id ? '更新' : '追加') . '</button>';
    if ($id) {
        $forms .= '<form id="del' . $id . '" method="post" onsubmit="return confirm(\'この部門を削除しますか?\')">' . csrf_field() . '<input type="hidden" name="do" value="cat_delete"><input type="hidden" name="id" value="' . $id . '"></form>';
        echo ' <button form="del' . $id . '" class="sm danger">削除</button>';
    }
    echo '</td></tr>';
}
echo '</table></div>' . $forms;
echo '<form method="post" class="actions">' . csrf_field() . '<input type="hidden" name="do" value="cat_preset"><button class="sub sm">リカーブ/コンパウンド 男女を一括追加</button></form>';

// ---- 管理
echo '<h2>管理</h2><div class="row"><form method="post" class="card" style="flex:1 1 20rem">' . csrf_field() . '<input type="hidden" name="do" value="password">'
    . '<b>管理パスワードの変更</b><label>新しいパスワード</label><input type="password" name="password" required><label>確認</label><input type="password" name="password2" required><p><button class="sub">変更</button></p></form>';
echo '<form method="post" class="card" style="flex:1 1 20rem" onsubmit="return confirm(\'全員の得点を消します。よろしいですか?\')">' . csrf_field() . '<input type="hidden" name="do" value="clear_scores">'
    . '<b>得点の全消去</b><p class="muted small">リハーサル後などに、選手名簿はそのままで得点だけを消します。</p>'
    . '<label style="font-weight:400"><input type="checkbox" name="confirm" value="yes"> 本当に消す</label><p><button class="danger">得点を全消去</button></p></form></div>';
echo '<script>(function(){var p=document.getElementById("preset"),c=document.getElementById("custom");function f(){c.style.display=p.value==="custom"?"":"none"}p.onchange=f;f()})()</script>';
page_foot();

function faceSelect($cur, $form = '')
{
    $o = '<select name="targetface"' . ($form ? ' form="' . $form . '"' : '') . '>';
    foreach (TargetFaces::options() as $id => $label) {
        $o .= '<option value="' . $id . '"' . ((int)$cur === $id ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    return $o . '</select>';
}
