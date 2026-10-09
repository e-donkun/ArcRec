<?php
require __DIR__ . '/_layout.php';
require_admin();

use Lite\Categories;
use Lite\Entries;

$e = Entries::find((int)($_GET['id'] ?? $_POST['id'] ?? 0));
if (!$e) {
    flash('選手が見つかりません', 'err');
    redirect('entries.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Entries::save($_POST, $e['id']);   // 的番号は触らない(的番号の画面で設定)
        flash('保存しました');
        redirect('entries.php');
    } catch (InvalidArgumentException $ex) {
        flash($ex->getMessage(), 'err');
        redirect('entry_edit.php?id=' . $e['id']);
    }
}

page_head('選手の編集', 'entries');
echo '<h1>選手の編集</h1><form method="post" class="card wideform">' . csrf_field() . '<input type="hidden" name="id" value="' . $e['id'] . '">';
echo '<div class="row nowrap wide"><div><label>姓</label><input type="text" name="family_name" value="' . h($e['family_name']) . '" required></div>'
    . '<div><label>名</label><input type="text" name="given_name" value="' . h($e['given_name']) . '"></div></div>'
    . '<label>所属</label><input type="text" name="affiliation" value="' . h($e['affiliation']) . '" list="affs">'
    . '<label>部門</label><select name="category_id" required>';
foreach (Categories::all() as $c) {
    echo '<option value="' . $c['id'] . '"' . ((int)$e['category_id'] === (int)$c['id'] ? ' selected' : '') . '>' . h($c['name']) . '</option>';
}
echo '</select>'
    . '<p class="muted small">的: <b>' . ($e['target'] ? h($e['target'] . $e['letter']) : '未割当') . '</b> — 変更は <a href="targets.php">的番号</a> の画面で行います。</p>'
    . '<label style="font-weight:400"><input type="checkbox" name="status" value="1"' . ($e['status'] ? ' checked' : '') . '> 欠場(端末・結果から外す。得点がなければ的からも外れます)</label>'
    . '<p><button>保存</button> <a class="btn sub" href="entries.php">戻る</a></p></form>';
echo '<datalist id="affs">';
foreach (Lite\Past::affiliations() as $a) {
    echo '<option value="' . h($a) . '">';
}
echo '</datalist>';
page_foot();
