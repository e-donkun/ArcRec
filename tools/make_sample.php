<?php
// 同梱のサンプル記録会(samples/5A4D0001.sqlite 屋外、samples/5A4D0002.sqlite 屋内)を作る開発用スクリプト。結果は毎回同じ内容になる。
//   php tools/make_sample.php            … 2つとも作る
//   php tools/make_sample.php 5A4D0002   … 1つだけ作る
// アプリは、記録会のフォルダにサンプルが無いときだけ、samples/ からコピーして使う。パスワードは sample。
// 1つの PHP プロセスでは1つの DB しか扱わないので、2つ作るときは自分を記録会ごとに呼び直す。
$samples = [
    '5A4D0001' => ['name' => 'サンプル記録会（Outdoor 36射×2）', 'preset' => 'outdoor72', 'faces' => ['5', '5'], 'seed' => 20261010],
    '5A4D0002' => ['name' => 'サンプル記録会（Indoor 30射×2）', 'preset' => 'indoor60', 'faces' => ['2', '2'], 'seed' => 20261011],
];
$only = $argv[1] ?? null;
if ($only === null) {
    foreach (array_keys($samples) as $code) {
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . $code, $rc);
        if ($rc) {
            exit($rc);
        }
    }
    exit;
}
if (!isset($samples[$only])) {
    fwrite(STDERR, "不明なサンプル: $only\n");
    exit(1);
}
$cfg = $samples[$only];
$out = dirname(__DIR__) . '/samples/' . $only . '.sqlite';
@unlink($out);
@unlink($out . '-wal');
@unlink($out . '-shm');
putenv('LITE_DB=' . $out);
require dirname(__DIR__) . '/src/bootstrap.php';

use Lite\{Categories, Entries, Settings, Competitions, Auth, Db};

mt_srand($cfg['seed']);
Settings::setMany([
    'code' => $only,
    'name' => $cfg['name'],
    'preset' => $cfg['preset'],
    'target_face1' => $cfg['faces'][0],
    'target_face2' => $cfg['faces'][1],
    'admin_hash' => Auth::hashPassword(Competitions::SAMPLE_PASSWORD),
    'results_public' => '1',
    'created_at' => '2026-10-10 09:00:00',
]);
$rm = (int)Categories::byCode('RM')['id'];
$rw = (int)Categories::byCode('RW')['id'];

$men = [['山田', '太郎', '京都大学'], ['佐藤', '健', '同志社大学'], ['鈴木', '一郎', '立命館大学'], ['高橋', '誠', '京都産業大学'],
    ['田中', '直樹', '龍谷大学'], ['伊藤', '翔', '関西大学'], ['渡辺', '大輔', '近畿大学'], ['中村', '悠斗', '京都工芸繊維大学']];
$women = [['小林', '美咲', '京都大学'], ['加藤', '花子', '同志社大学'], ['吉田', '結衣', '立命館大学'], ['山本', '彩', '京都産業大学'],
    ['松本', '愛', '龍谷大学'], ['井上', '菜々', '関西大学'], ['木村', '優花', '近畿大学'], ['林', 'さくら', '京都女子大学']];
foreach ($men as $n) {
    Entries::save(['family_name' => $n[0], 'given_name' => $n[1], 'affiliation' => $n[2], 'category_id' => $rm]);
}
foreach ($women as $n) {
    Entries::save(['family_name' => $n[0], 'given_name' => $n[1], 'affiliation' => $n[2], 'category_id' => $rw]);
}
Entries::autoAssign(4, 1);   // 男子 的1・2、女子 的3・4

$indoor = Settings::category() === 2;
$perEnd = Settings::arrowsPerEnd();

/**
 * 矢の出方(強さの違いをつける)。文字は ianseo と同じ(K=X, L=10, J=9 … B=1, A=M)。
 * 屋内(40cm 三つ目)は X が無く 6 点より下は M なので、X の分は 10 に、5 点以下は M にする。
 * 各エンドの中は、スコアカードの書き方と同じく X → 10 → 9 … → M の順(高い順)に並べる。
 */
function shoot($n, $skill, $perEnd, $indoor)
{
    $pool = ['K' => 4 * $skill, 'L' => 14 * $skill, 'J' => 26, 'I' => 22, 'H' => 14, 'G' => 8, 'F' => 4, 'E' => 2, 'A' => 1];
    if ($indoor) {
        $pool['L'] += $pool['K'];
        $pool['A'] += $pool['F'] + $pool['E'];
        unset($pool['K'], $pool['F'], $pool['E']);
    }
    $bag = '';
    foreach ($pool as $c => $w) {
        $bag .= str_repeat($c, (int)round($w));
    }
    $order = 'KLJIHGFEDCBA';   // 高い順
    $s = '';
    for ($i = 0; $i < $n; $i += $perEnd) {
        $end = [];
        for ($j = 0; $j < $perEnd && $i + $j < $n; $j++) {
            $end[] = $bag[mt_rand(0, strlen($bag) - 1)];
        }
        usort($end, function ($a, $b) use ($order) {
            return strpos($order, $a) <=> strpos($order, $b);
        });
        $s .= implode('', $end);
    }
    return $s;
}
$per = Settings::arrowsPerDistance();   // 屋外 36 / 屋内 30
$half = intdiv(Settings::ends(), 2) * $perEnd;   // 後半の途中(半分のエンド)
foreach (Entries::all() as $i => $e) {
    $skill = 1 + (($i * 7) % 5) * 0.35;
    $t = (int)$e['target'];
    if ($t <= 2) {                 // 的1・2: 2距離とも終了
        Entries::applyArrows($e['id'], shoot($per, $skill, $perEnd, $indoor), 'sample', true, 'sample', 1);
        Entries::applyArrows($e['id'], shoot($per, $skill, $perEnd, $indoor), 'sample', true, 'sample', 2);
    } elseif ($t === 3) {          // 的3: 前半が終わって、後半の途中
        Entries::applyArrows($e['id'], shoot($per, $skill, $perEnd, $indoor), 'sample', null, 'sample', 1);
        Entries::applyArrows($e['id'], shoot($half, $skill, $perEnd, $indoor), 'sample', null, 'sample', 2);
    }                              // 的4: まだ射っていない
}
Entries::setLocked(Entries::all()[0]['id'], true);   // 1人は確定済み
$pdo = Db::pdo();
$pdo->exec("UPDATE entries SET updated_at = '2026-10-10 12:00:00' WHERE updated_at != ''");
$pdo->exec("UPDATE score_log SET at = '2026-10-10 12:00:00'");
$pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
$pdo->exec('PRAGMA journal_mode=DELETE');   // 1ファイルだけにして、リポジトリに入れやすくする
echo "作成しました: $out (" . filesize($out) . " bytes)\n";
