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
                $code = trim($_POST['code'] ?? '');
                if (!preg_match('/^[A-Za-z0-9_-]{1,16}$/', $code)) {
                    throw new InvalidArgumentException('大会コードは英数字・- _ で16文字以内にしてください');
                }
                $pin = trim($_POST['pin'] ?? '');
                if ($pin !== '' && !preg_match('/^[A-Za-z0-9]{1,8}$/', $pin)) {
                    throw new InvalidArgumentException('PIN は英数字8文字以内にしてください(使わない場合は空欄)');
                }
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
                $face = (int)($_POST['indoor_face'] ?? 1);
                Settings::setMany([
                    'code' => $code, 'name' => trim($_POST['name'] ?? ''),
                    'preset' => $preset,
                    'distance_name' => trim($_POST['distance_name'] ?? ''), 'distance_name2' => trim($_POST['distance_name2'] ?? ''),
                    'indoor_face' => isset(Settings::INDOOR_FACES[$face]) ? $face : 1,
                    'pin' => $pin,
                    'gold_label' => trim($_POST['gold_label'] ?? '') ?: '10+X', 'x_label' => trim($_POST['x_label'] ?? '') ?: 'X',
                    'session_name' => trim($_POST['session_name'] ?? ''), 'server_url' => trim($_POST['server_url'] ?? ''),
                    'archers_per_target' => max(1, min(4, (int)($_POST['archers_per_target'] ?? 4))),
                ]);
                // 端末に保存した選手一覧・的面・距離の設定は、次の接続で作り直させる
                Db::pdo()->exec("UPDATE devices SET setup = '', setup_confirmed = 0");
                flash('競技設定を保存しました');
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
page_head('競技設定', 'setup');
echo '<h1>競技設定</h1>';
echo '<form method="post" class="card">' . csrf_field() . '<input type="hidden" name="do" value="settings">';
echo '<div class="row"><div><label>大会名</label><input type="text" name="name" value="' . h($s['name']) . '"></div>'
    . '<div><label>大会コード <span class="muted small">(端末が使う英数字。例 KYOTO26)</span></label><input type="text" name="code" value="' . h($s['code']) . '" maxlength="16" required></div></div>';

echo '<label>競技形式 <span class="muted small">(WA: 屋外は1距離36射、屋内は1距離30射。1距離または2距離)</span></label><select name="preset" id="preset">';
foreach (Settings::PRESETS as $k => $p) {
    echo '<option value="' . $k . '" data-nd="' . $p['distances'] . '" data-indoor="' . ($p['category'] === 2 ? 1 : 0) . '"' . ($s['preset'] === $k ? ' selected' : '') . '>' . h($p['label']) . '</option>';
}
echo '</select>';
echo '<div class="row"><div><label>距離1の表記</label><input type="text" name="distance_name" value="' . h($s['distance_name']) . '" placeholder="70m / 18m"></div>'
    . '<div id="d2"><label>距離2の表記</label><input type="text" name="distance_name2" value="' . h($s['distance_name2']) . '" placeholder="70m / 18m"></div>'
    . '<div id="face"><label>屋内の的面</label><select name="indoor_face">';
foreach (Settings::INDOOR_FACES as $id => $label) {
    echo '<option value="' . $id . '"' . ((int)$s['indoor_face'] === $id ? ' selected' : '') . '>' . h($label) . '</option>';
}
echo '</select></div></div>';
echo '<p class="muted small">部門はリカーブ男子・リカーブ女子の2つです。屋外の的面は 122cm(1-X) です。順位は 全距離の合計 → 10+X → X の順で決まります。</p>';
echo '<div class="row"><div><label>セッション名(任意)</label><input type="text" name="session_name" value="' . h($s['session_name']) . '" placeholder="Session 1"></div>'
    . '<div><label>1的あたりの人数(自動割当)</label><input type="number" name="archers_per_target" min="1" max="4" value="' . h($s['archers_per_target']) . '"></div></div>';
echo '<div class="row"><div><label>ゴールド欄の見出し</label><input type="text" name="gold_label" value="' . h($s['gold_label']) . '"></div>'
    . '<div><label>X欄の見出し</label><input type="text" name="x_label" value="' . h($s['x_label']) . '"></div></div>';

echo '<h2>端末(ScorekeeperNG)の接続</h2>';
echo '<div class="row"><div><label>PIN <span class="muted small">(任意。端末登録時に必要になります)</span></label><input type="text" name="pin" value="' . h($s['pin']) . '" maxlength="8"></div>'
    . '<div><label>サーバーURL <span class="muted small">(空欄なら今開いているURLから自動)</span></label><input type="text" name="server_url" value="' . h($s['server_url']) . '" placeholder="http://192.168.0.10/ianseo-lite/"></div></div>';
echo '<p><button>保存</button></p></form>';

// ---- 管理
echo '<h2>管理</h2><div class="row"><form method="post" class="card" style="flex:1 1 20rem">' . csrf_field() . '<input type="hidden" name="do" value="password">'
    . '<b>管理パスワードの変更</b><label>新しいパスワード</label><input type="password" name="password" required><label>確認</label><input type="password" name="password2" required><p><button class="sub">変更</button></p></form>';
echo '<form method="post" class="card" style="flex:1 1 20rem" onsubmit="return confirm(\'全員の得点を消します。よろしいですか?\')">' . csrf_field() . '<input type="hidden" name="do" value="clear_scores">'
    . '<b>得点の全消去</b><p class="muted small">リハーサル後などに、選手名簿はそのままで得点だけを消します。</p>'
    . '<label style="font-weight:400"><input type="checkbox" name="confirm" value="yes"> 本当に消す</label><p><button class="danger">得点を全消去</button></p></form></div>';
echo '<script>(function(){var p=document.getElementById("preset"),d=document.getElementById("d2"),f=document.getElementById("face");function u(){var o=p.options[p.selectedIndex];d.style.display=o.dataset.nd==="2"?"":"none";f.style.display=o.dataset.indoor==="1"?"":"none"}p.onchange=u;u()})()</script>';
page_foot();
