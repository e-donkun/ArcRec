<?php
require __DIR__ . '/_layout.php';
require_admin();

use Lite\Categories;
use Lite\Entries;
use Lite\Past;
use Lite\Scoring;
use Lite\Settings;

/** CSV/TSV(UTF-8 か Shift_JIS)を取り込む。列: 姓,名,所属,部門,的(3A など) */
function import_entries($text)
{
    $enc = mb_detect_encoding($text, ['UTF-8', 'SJIS-win', 'EUC-JP'], true) ?: 'UTF-8';
    if ($enc !== 'UTF-8') {
        $text = mb_convert_encoding($text, 'UTF-8', $enc);
    }
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    $lines = preg_split('/\r\n|\r|\n/', $text);
    $added = 0;
    $errors = [];
    foreach ($lines as $n => $line) {
        if (trim($line) === '') {
            continue;
        }
        $delim = strpos($line, "\t") !== false ? "\t" : ',';
        $c = array_map('trim', str_getcsv($line, $delim, '"', ''));
        if ($n === 0 && preg_match('/^(姓|氏名|名前|family|name)/iu', $c[0])) {
            continue; // 見出し行
        }
        $pos = $c[4] ?? '';
        $target = 0;
        $letter = '';
        if ($pos !== '') {
            if (!preg_match('/^(\d+)\s*([A-Da-d])$/', $pos, $m)) {
                $errors[] = ($n + 1) . '行目: 的の指定「' . $pos . '」が不正です(例 3A)';
                continue;
            }
            $target = (int)$m[1];
            $letter = strtoupper($m[2]);
        }
        $cat = Categories::resolve($c[3] ?? '');
        if ($cat === null) {
            $errors[] = ($n + 1) . '行目: 部門「' . ($c[3] ?? '') . '」を判別できません(男子 / 女子 のように書いてください)';
            continue;
        }
        try {
            Entries::save([
                'family_name' => $c[0] ?? '', 'given_name' => $c[1] ?? '', 'affiliation' => $c[2] ?? '',
                'category_id' => $cat, 'target' => $target, 'letter' => $letter,
            ]);
            $added++;
        } catch (InvalidArgumentException $e) {
            $errors[] = ($n + 1) . '行目: ' . $e->getMessage();
        } catch (PDOException $e) {
            $errors[] = ($n + 1) . '行目: 登録できません(' . $e->getMessage() . ')';
        }
    }
    return [$added, $errors];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        switch ($_POST['do'] ?? '') {
            case 'add':
                Entries::save($_POST);   // 的番号は的番号の画面で設定する
                flash(trim(($_POST['family_name'] ?? '') . ' ' . ($_POST['given_name'] ?? '')) . ' を追加しました');
                break;
            case 'delete':
                $e = Entries::find((int)$_POST['id']);
                if ($e && Entries::hasScore($e) && ($_POST['force'] ?? '') !== '1') {
                    throw new InvalidArgumentException('得点が入っている選手は削除できません(欠場にするか、先に得点を消してください)');
                }
                Entries::delete((int)$_POST['id']);
                flash('削除しました');
                break;
            case 'import':
                $text = $_POST['csv'] ?? '';
                if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
                    $text = file_get_contents($_FILES['file']['tmp_name']);
                }
                list($added, $errors) = import_entries($text);
                flash($added . ' 人を取り込みました', $added ? 'ok' : 'warn');
                foreach (array_slice($errors, 0, 10) as $er) {
                    flash($er, 'err');
                }
                break;
        }
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'err');
    }
    redirect('entries.php');
}

$rows = Entries::all(false);
$cats = Categories::all();
page_head('選手登録', 'entries');
echo '<h1>選手登録 <span class="muted small">' . count($rows) . ' 人</span></h1>';
echo '<p class="muted small">名簿を登録します。的番号と位置は <a href="targets.php">的番号</a> の画面で設定します。</p>';

// ---- 追加(姓・名は横に並べる。過去の大会の選手から選んで入れることもできる)
echo '<form method="post" class="card wideform" autocomplete="off">' . csrf_field() . '<input type="hidden" name="do" value="add">'
    . '<div class="row nowrap wide"><div><label>姓</label><input type="text" name="family_name" id="fam" required placeholder="山田"></div>'
    . '<div><label>名</label><input type="text" name="given_name" id="giv" placeholder="太郎"></div></div>'
    . '<div id="sug" class="chips"></div>'
    . '<label>所属</label><input type="text" name="affiliation" id="aff" list="affs">'
    . '<label>部門</label><select name="category_id" id="cat" required>';
foreach ($cats as $c) {
    echo '<option value="' . $c['id'] . '" data-code="' . h($c['code']) . '">' . h($c['name']) . '</option>';
}
echo '</select><p><button>追加</button></p>'
    . '<p class="muted small">姓を入力すると、開いたことのある過去の大会の選手が候補に出ます。選ぶと姓・名・所属・部門が入ります。</p></form>';
echo '<datalist id="affs">';
foreach (Past::affiliations() as $a) {
    echo '<option value="' . h($a) . '">';
}
echo '</datalist>';

// ---- 登録済みの選手
echo '<div class="actions noprint"><a class="btn sub" href="targets.php">的番号を設定する</a>'
    . '<a class="btn sub" href="pdf.php?type=scorecard_blank">スコアカード(白紙)PDF</a>'
    . '<a class="btn sub" href="export.php?what=entries">名簿CSV</a></div>';
echo '<div class="tablewrap"><table><tr><th>氏名</th><th>所属</th><th>部門</th><th class="c">的</th><th>状態</th><th class="noprint"></th></tr>';
foreach ($rows as $e) {
    $h = Scoring::total(Entries::allArrows($e))[3];
    $state = $e['status'] ? '<span class="muted">欠場</span>' : ($h ? $h . '射' . ($e['locked'] ? ' <span class="lock">確定</span>' : '') : '<span class="muted">未射</span>');
    echo '<tr><td>' . h(Entries::fullName($e)) . '</td><td>' . h($e['affiliation']) . '</td>'
        . '<td>' . h($e['category_name'] ?? '') . '</td>'
        . '<td class="c">' . ($e['target'] ? h($e['target'] . $e['letter']) : '<span class="muted">-</span>') . '</td><td>' . $state . '</td>'
        . '<td class="noprint"><a class="btn sub sm" href="entry_edit.php?id=' . $e['id'] . '">編集</a> '
        . '<form method="post" class="inline" onsubmit="return confirm(\'削除しますか?\')">' . csrf_field()
        . '<input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="' . $e['id'] . '"><button class="sm danger">削除</button></form></td></tr>';
}
if (!$rows) {
    echo '<tr><td colspan="6" class="muted">まだ選手がいません。上のフォームかCSVで登録してください。</td></tr>';
}
echo '</table></div>';

echo '<details class="card"><summary><b>CSVで一括登録</b></summary><form method="post" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="do" value="import">'
    . '<p class="muted small">列: <code>姓,名,所属,部門</code> — カンマ/タブ区切り、UTF-8 または Shift_JIS(Excel保存)。1行目が見出しなら自動で飛ばします。部門は <b>男子 / 女子</b>(リカーブ男子・RM・M、リカーブ女子・RW・W なども可)で書きます。</p>'
    . '<label>ファイルから</label><input type="file" name="file" accept=".csv,.tsv,.txt">'
    . '<label>または貼り付け</label><textarea name="csv" rows="6" placeholder="山田,太郎,京都大学,男子&#10;佐藤,花子,同志社大学,女子"></textarea>'
    . '<p><button>取り込む</button></p></form></details>';

echo '<script>(function(){'
    . 'var fam=document.getElementById("fam"),giv=document.getElementById("giv"),aff=document.getElementById("aff"),cat=document.getElementById("cat"),box=document.getElementById("sug"),t;'
    . 'function pick(a){fam.value=a.family;giv.value=a.given;aff.value=a.affiliation;for(var i=0;i<cat.options.length;i++){if(cat.options[i].dataset.code===a.category_code){cat.selectedIndex=i}}box.innerHTML="";giv.focus()}'
    . 'fam.addEventListener("input",function(){clearTimeout(t);var v=fam.value.trim();if(!v){box.innerHTML="";return}'
    . 't=setTimeout(function(){fetch("past.php?q="+encodeURIComponent(v),{credentials:"same-origin"}).then(function(r){return r.json()}).then(function(list){'
    . 'box.innerHTML="";list.forEach(function(a){var b=document.createElement("button");b.type="button";b.className="chip";'
    . 'b.textContent=a.family+" "+a.given+(a.affiliation?" / "+a.affiliation:"");b.onclick=function(){pick(a)};box.appendChild(b)})}).catch(function(){})},200)});'
    . '})()</script>';
page_foot();
