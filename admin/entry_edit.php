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
        Entries::save($_POST, $e['id']);
        flash('保存しました');
        redirect('entries.php');
    } catch (InvalidArgumentException $ex) {
        flash($ex->getMessage(), 'err');
        redirect('entry_edit.php?id=' . $e['id']);
    }
}

page_head('選手の編集', 'entries');
echo '<h1>選手の編集</h1><form method="post" class="card">' . csrf_field() . '<input type="hidden" name="id" value="' . $e['id'] . '">';
$v = function ($k) use ($e) {
    return h($e[$k] ?? '');
};
echo '<div class="row"><div><label>姓</label><input type="text" name="family_name" value="' . $v('family_name') . '" required></div>'
    . '<div><label>名</label><input type="text" name="given_name" value="' . $v('given_name') . '"></div>'
    . '<div><label>所属</label><input type="text" name="affiliation" value="' . $v('affiliation') . '"></div></div>'
    . '<div class="row"><div><label>部門</label><select name="category_id" required>';
foreach (Categories::all() as $c) {
    echo '<option value="' . $c['id'] . '"' . ((int)$e['category_id'] === (int)$c['id'] ? ' selected' : '') . '>' . h($c['name']) . '</option>';
}
echo '</select></div><div><label>的番号 <span class="muted small">(0=未割当)</span></label><input type="number" name="target" min="0" value="' . h($e['target']) . '"></div>'
    . '<div><label>的の位置</label><select name="letter">';
foreach (['A', 'B', 'C', 'D'] as $l) {
    echo '<option' . ($e['letter'] === $l ? ' selected' : '') . '>' . $l . '</option>';
}
echo '</select></div></div>'
    . '<label style="font-weight:400"><input type="checkbox" name="status" value="1"' . ($e['status'] ? ' checked' : '') . '> 欠場(端末・結果から外す)</label>'
    . '<p><button>保存</button> <a class="btn sub" href="entries.php">戻る</a></p></form>';
page_foot();
