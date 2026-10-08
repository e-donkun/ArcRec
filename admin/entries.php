<?php
require __DIR__ . '/_layout.php';
require_admin();

use Lite\Categories;
use Lite\Entries;
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
                Entries::save($_POST);
                flash('選手を追加しました');
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
            case 'assign':
                Entries::autoAssign((int)$_POST['per_target'], (int)($_POST['first'] ?? 1));
                flash('的番号を割り当てました(得点のある選手の位置は変えていません)');
                break;
            case 'unassign':
                Lite\Db::pdo()->exec("UPDATE entries SET target = 0, letter = '' WHERE " . Entries::NO_SCORE);
                flash('得点のない選手の的番号をクリアしました');
                break;
        }
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'err');
    }
    redirect('entries.php');
}

$rows = Entries::all();
$cats = Categories::all();
page_head('選手', 'entries');
echo '<h1>選手 <span class="muted small">' . count($rows) . ' 人</span></h1>';

echo '<div class="actions noprint"><a class="btn sub" href="pdf.php?type=scorecard_blank">スコアカード(白紙)PDF</a>'
    . '<a class="btn sub" href="export.php?what=entries">名簿CSV</a></div>';

echo '<div class="tablewrap"><table><tr><th class="c">的</th><th>氏名</th><th>所属</th><th>部門</th><th>状態</th><th class="noprint"></th></tr>';
foreach ($rows as $e) {
    $h = Scoring::total(Entries::allArrows($e))[3];
    $state = $e['status'] ? '<span class="muted">欠場</span>' : ($h ? $h . '射' . ($e['locked'] ? ' <span class="lock">確定</span>' : '') : '<span class="muted">未射</span>');
    echo '<tr><td class="c">' . ($e['target'] ? h($e['target'] . $e['letter']) : '<span class="muted">-</span>') . '</td>'
        . '<td>' . h(Entries::fullName($e)) . '</td><td>' . h($e['affiliation']) . '</td>'
        . '<td>' . h($e['category_name'] ?? '') . '</td><td>' . $state . '</td>'
        . '<td class="noprint"><a class="btn sub sm" href="entry_edit.php?id=' . $e['id'] . '">編集</a> '
        . '<form method="post" class="inline" onsubmit="return confirm(\'削除しますか?\')">' . csrf_field()
        . '<input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="' . $e['id'] . '"><button class="sm danger">削除</button></form></td></tr>';
}
if (!$rows) {
    echo '<tr><td colspan="6" class="muted">まだ選手がいません。下のフォームかCSVで登録してください。</td></tr>';
}
echo '</table></div>';

echo '<h2>選手を追加</h2><form method="post" class="card">' . csrf_field() . '<input type="hidden" name="do" value="add">';
echo entryFields([], $cats);
echo '<p><button>追加</button></p></form>';

echo '<h2>CSVで一括登録</h2><form method="post" enctype="multipart/form-data" class="card">' . csrf_field() . '<input type="hidden" name="do" value="import">'
    . '<p class="muted small">列: <code>姓,名,所属,部門,的(3A など・省略可)</code> — カンマ/タブ区切り、UTF-8 または Shift_JIS(Excel保存)。1行目が見出しなら自動で飛ばします。部門は <b>男子 / 女子</b>(リカーブ男子・RM・M、リカーブ女子・RW・W なども可)で書きます。</p>'
    . '<label>ファイルから</label><input type="file" name="file" accept=".csv,.tsv,.txt">'
    . '<label>または貼り付け</label><textarea name="csv" rows="6" placeholder="山田,太郎,京都大学,男子&#10;佐藤,花子,同志社大学,女子"></textarea>'
    . '<p><button>取り込む</button></p></form>';

echo '<h2>的番号の割り当て</h2><form method="post" class="card">' . csrf_field() . '<input type="hidden" name="do" value="assign">'
    . '<p class="muted small">部門順・登録順に A,B,C,D と詰めて割り当てます。<b>得点が入っている選手は動かしません。</b>個別に決めたい場合は各選手の編集で指定してください。</p>'
    . '<div class="row"><div><label>1的あたりの人数</label><input type="number" name="per_target" min="1" max="4" value="' . h(Settings::get('archers_per_target')) . '"></div>'
    . '<div><label>開始の的番号</label><input type="number" name="first" min="1" value="1"></div></div>'
    . '<p><button>割り当てる</button></p></form>';
echo '<form method="post" class="inline" onsubmit="return confirm(\'得点のない選手の的番号をすべて外します。よろしいですか?\')">' . csrf_field() . '<input type="hidden" name="do" value="unassign"><button class="sub sm">的番号をクリア</button></form>';
page_foot();

function entryFields(array $e, array $cats)
{
    $v = function ($k) use ($e) {
        return h($e[$k] ?? '');
    };
    $o = '<div class="row"><div><label>姓</label><input type="text" name="family_name" value="' . $v('family_name') . '" required></div>'
        . '<div><label>名</label><input type="text" name="given_name" value="' . $v('given_name') . '"></div>'
        . '<div><label>所属</label><input type="text" name="affiliation" value="' . $v('affiliation') . '"></div></div>'
        . '<div class="row"><div><label>部門</label><select name="category_id" required><option value="">選択してください</option>';
    foreach ($cats as $c) {
        $o .= '<option value="' . $c['id'] . '"' . ((int)($e['category_id'] ?? 0) === (int)$c['id'] ? ' selected' : '') . '>' . h($c['name']) . '</option>';
    }
    $o .= '</select></div><div><label>的番号</label><input type="number" name="target" min="0" value="' . h($e['target'] ?? '') . '"></div>'
        . '<div><label>的の位置</label><select name="letter">';
    foreach (['A', 'B', 'C', 'D'] as $l) {
        $o .= '<option' . (($e['letter'] ?? 'A') === $l ? ' selected' : '') . '>' . $l . '</option>';
    }
    $o .= '</select></div></div>';
    return $o;
}
