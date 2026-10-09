<?php
// 同梱のサンプル大会(samples/5A4D0001.sqlite)を作る開発用スクリプト。結果は毎回同じ内容になる。
//   php tools/make_sample.php
// アプリは、大会のフォルダにこのサンプルが無いときだけ、samples/ からコピーして使う。パスワードは sample。
$out = dirname(__DIR__) . '/samples/5A4D0001.sqlite';
@unlink($out);
@unlink($out . '-wal');
@unlink($out . '-shm');
putenv('LITE_DB=' . $out);
require dirname(__DIR__) . '/src/bootstrap.php';

use Lite\{Categories, Entries, Settings, Competitions, Auth, Db};

mt_srand(20261010);
Settings::setMany([
    'code' => Competitions::SAMPLE_CODE,
    'name' => 'サンプル大会（Outdoor 36射×2）',
    'preset' => 'outdoor72',
    'target_face1' => '5',
    'target_face2' => '5',
    'admin_hash' => Auth::hashPassword(Competitions::SAMPLE_PASSWORD),
    'created_at' => '2026-10-10 09:00:00',
]);
$rm = (int)Categories::byCode('RM')['id'];
$rw = (int)Categories::byCode('RW')['id'];

$men = [['山田', '太郎', '京都大学'], ['佐藤', '健', '同志社大学'], ['鈴木', '一郎', '立命館大学'], ['高橋', '誠', '京都産業大学'],
    ['田中', '直樹', '龍谷大学'], ['伊藤', '翔', '関西大学'], ['渡辺', '大輔', '近畿大学'], ['中村', '悠斗', '京都工芸繊維大学']];
$women = [['小林', '美咲', '京都大学'], ['加藤', '花子', '同志社大学'], ['吉田', '結衣', '立命館大学'], ['山本', '彩', '京都産業大学'],
    ['松本', '愛', '龍谷大学'], ['井上', '菜々', '関西大学'], ['木村', '優花', '近畿大学'], ['林', 'さくら', '京都女子大学']];
$ids = [];
foreach ($men as $n) {
    $ids[] = Entries::save(['family_name' => $n[0], 'given_name' => $n[1], 'affiliation' => $n[2], 'category_id' => $rm]);
}
foreach ($women as $n) {
    $ids[] = Entries::save(['family_name' => $n[0], 'given_name' => $n[1], 'affiliation' => $n[2], 'category_id' => $rw]);
}
Entries::autoAssign(4, 1);   // 男子 的1・2、女子 的3・4

// 矢の出方(強さの違いをつける)。文字は ianseo と同じ(K=X, L=10, J=9 …)
function shoot($n, $skill)
{
    $pool = ['K' => 4 * $skill, 'L' => 14 * $skill, 'J' => 26, 'I' => 22, 'H' => 14, 'G' => 8, 'F' => 4, 'E' => 2, 'A' => 1];
    $bag = '';
    foreach ($pool as $c => $w) {
        $bag .= str_repeat($c, (int)round($w));
    }
    $s = '';
    for ($i = 0; $i < $n; $i++) {
        $s .= $bag[mt_rand(0, strlen($bag) - 1)];
    }
    return $s;
}
$per = Settings::arrowsPerDistance();   // 36
foreach (Entries::all() as $i => $e) {
    $skill = 1 + (($i * 7) % 5) * 0.35;
    $t = (int)$e['target'];
    if ($t <= 2) {                 // 的1・2: 2距離とも終了
        Entries::applyArrows($e['id'], shoot($per, $skill), 'sample', true, 'sample', 1);
        Entries::applyArrows($e['id'], shoot($per, $skill), 'sample', true, 'sample', 2);
    } elseif ($t === 3) {          // 的3: 前半が終わって、後半の途中(3エンド)
        Entries::applyArrows($e['id'], shoot($per, $skill), 'sample', null, 'sample', 1);
        Entries::applyArrows($e['id'], shoot(18, $skill), 'sample', null, 'sample', 2);
    }                              // 的4: まだ射っていない
}
Entries::setLocked(Entries::all()[0]['id'], true);   // 1人は確定済み
$pdo = Db::pdo();
$pdo->exec("UPDATE entries SET updated_at = '2026-10-10 12:00:00' WHERE updated_at != ''");
$pdo->exec("UPDATE score_log SET at = '2026-10-10 12:00:00'");
$pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
$pdo->exec('PRAGMA journal_mode=DELETE');   // 1ファイルだけにして、リポジトリに入れやすくする
echo "作成しました: $out (" . filesize($out) . " bytes)\n";
