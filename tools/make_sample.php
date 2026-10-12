<?php
// 同梱のサンプル記録会(data/samples/5A4D0001.sqlite 屋外、data/samples/5A4D0002.sqlite 屋内)を作る開発用スクリプト。結果は毎回同じ内容になる。
//   php tools/make_sample.php            … 2つとも作る
//   php tools/make_sample.php 5A4D0002   … 1つだけ作る
// アプリは、記録会のフォルダにサンプルが無いときだけ、data/samples/ からコピーして使う。パスワードは sample。
// 1つの PHP プロセスでは1つの DB しか扱わないので、2つ作るときは自分を記録会ごとに呼び直す。
$samples = [
    '5A4D0001' => ['name' => 'サンプル記録会（Outdoor 72射）', 'preset' => 'outdoor72', 'faces' => ['5', '5'], 'seed' => 20261010,
        // 目標の合計点(全国高校選手権 2025 の予選 70m 72射の分布を参考に): 男子 / 女子
        'men' => [658, 641, 627, 612, 596, 579, 560, 533], 'women' => [642, 620, 603, 586, 568, 549, 527, 498]],
    '5A4D0002' => ['name' => 'サンプル記録会（Indoor 60射）', 'preset' => 'indoor60', 'faces' => ['2', '2'], 'seed' => 20261011,
        // 18m 40cm 三つ目 60射
        'men' => [579, 571, 563, 555, 546, 535, 521, 503], 'women' => [573, 563, 553, 543, 532, 519, 505, 486]],
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
$out = dirname(__DIR__) . '/data/samples/' . $only . '.sqlite';
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
    'sample_version' => Competitions::SAMPLE_VERSION,   // これが変わると、サーバーのサンプルも作り直される
]);
$rm = (int)Categories::byCode('RM')['id'];
$rw = (int)Categories::byCode('RW')['id'];

// 参加者(名前・所属はすべて架空)
$men = [['山田', '太郎', 'さくらの森大学'], ['佐藤', '健', 'みずうみ大学'], ['鈴木', '一郎', 'ほしかげ大学'], ['高橋', '誠', 'そよかぜ工科大学'],
    ['田中', '直樹', 'あさひがおか高等学校'], ['伊藤', '翔', 'つきみの高等学校'], ['渡辺', '大輔', 'こがねの高等学校'], ['中村', '悠斗', 'さくらの森大学']];
$women = [['小林', '美咲', 'はなみずき女子大学'], ['加藤', '花子', 'みずうみ大学'], ['吉田', '結衣', 'ほしかげ大学'], ['山本', '彩', 'あさひがおか高等学校'],
    ['松本', '愛', 'つきみの高等学校'], ['井上', '菜々', 'こがねの高等学校'], ['木村', '優花', 'はなみずき女子大学'], ['林', 'さくら', 'さくらの森大学']];
// 目標点の高い人が先になるよう、並べ替えずにそのまま(的番号は登録順に割り当てる)
foreach ($men as $n) {
    Entries::save(['family_name' => $n[0], 'given_name' => $n[1], 'affiliation' => $n[2], 'category_id' => $rm]);
}
foreach ($women as $n) {
    Entries::save(['family_name' => $n[0], 'given_name' => $n[1], 'affiliation' => $n[2], 'category_id' => $rw]);
}
Entries::autoAssign(4, 1);   // 男子 的1・2、女子 的3・4

$indoor = Settings::category() === 2;
$perEnd = Settings::arrowsPerEnd();
$perDist = Settings::arrowsPerDistance();   // 屋外 36 / 屋内 30

/*
 * 矢の出方: 的の中心からのずれを2次元の正規分布(半径はレイリー分布)とし、ずれの大きさ σ(リング幅を 1 とする)を
 * 目標の平均点になるように選ぶ。点は 10 - floor(r)。X は r < 0.5。
 * 屋内(40cm 三つ目)は 6 点まで(r >= 5 は M)で、X の印は付けない(10 として数える。同点の優先順位は 10・9)。
 */
function expected($sigma, $indoor)
{
    $p = function ($k) use ($sigma) {
        return 1 - exp(-$k * $k / (2 * $sigma * $sigma));
    };
    $e = 0;
    for ($k = 1; $k <= ($indoor ? 5 : 10); $k++) {
        $e += $p($k);
    }
    return $indoor ? $e + 5 * $p(5) : $e;
}
function sigmaFor($avg, $indoor)
{
    $lo = 0.05;
    $hi = 10;
    for ($i = 0; $i < 60; $i++) {
        $mid = ($lo + $hi) / 2;
        if (expected($mid, $indoor) > $avg) {
            $lo = $mid;
        } else {
            $hi = $mid;
        }
    }
    return ($lo + $hi) / 2;
}
function rnd()
{
    return (mt_rand() + 1) / (mt_getrandmax() + 2);
}
function arrow($sigma, $indoor)
{
    $r = $sigma * sqrt(-2 * log(rnd()));   // レイリー分布
    if ($indoor) {
        return $r >= 5 ? 'A' : 'LJIHG'[(int)floor($r)];
    }
    if ($r < 0.5) {
        return 'K';
    }
    return $r >= 10 ? 'A' : 'LJIHGFEDCB'[(int)floor($r)];
}
/** 1距離ぶんの矢。各エンドの中は、スコアカードの書き方と同じく高い順に並べる */
function shoot($n, $sigma, $perEnd, $indoor)
{
    $order = 'KLJIHGFEDCBA';
    $s = '';
    for ($i = 0; $i < $n; $i += $perEnd) {
        $end = [];
        for ($j = 0; $j < $perEnd; $j++) {
            $end[] = arrow($sigma, $indoor);
        }
        usort($end, function ($a, $b) use ($order) {
            return strpos($order, $a) <=> strpos($order, $b);
        });
        $s .= implode('', $end);
    }
    return $s;
}

// 全員が 2 距離とも射ち終えた状態にする。合計が目標点の ±3 点に入るまで引き直す
$goals = array_merge($cfg['men'], $cfg['women']);
foreach (Entries::all() as $i => $e) {
    $goal = $goals[$i];
    $sigma = sigmaFor($goal / ($perDist * 2), $indoor);
    do {
        $d1 = shoot($perDist, $sigma * (0.97 + 0.06 * rnd()), $perEnd, $indoor);
        $d2 = shoot($perDist, $sigma * (0.97 + 0.06 * rnd()), $perEnd, $indoor);
        $total = Lite\Scoring::total($d1 . $d2)[0];
    } while (abs($total - $goal) > 3);
    Entries::applyArrows($e['id'], $d1, 'sample', true, 'sample', 1);
    Entries::applyArrows($e['id'], $d2, 'sample', true, 'sample', 2);
}
$pdo = Db::pdo();
$pdo->exec("UPDATE entries SET updated_at = '2026-10-10 15:00:00' WHERE updated_at != ''");
$pdo->exec("UPDATE score_log SET at = '2026-10-10 15:00:00'");
$pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
$pdo->exec('PRAGMA journal_mode=DELETE');   // 1ファイルだけにして、リポジトリに入れやすくする
echo "作成しました: $out (" . filesize($out) . " bytes)\n";
