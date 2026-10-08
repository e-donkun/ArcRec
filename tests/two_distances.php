<?php
// php tests/two_distances.php -- WA 形式の2距離(屋外 36x2 / 屋内 30x2)と屋内1距離の動作確認
putenv('LITE_DB=' . sys_get_temp_dir() . '/lite-test2-' . getmypid() . '.sqlite');
require dirname(__DIR__) . '/src/bootstrap.php';

use Lite\{Categories, Devices, Entries, IskApi, Pdf, Results, Scoring, Settings};

$fail = 0;
function ok($cond, $msg)
{
    global $fail;
    echo ($cond ? "  ok   " : "  FAIL ") . $msg . "\n";
    if (!$cond) {
        $fail++;
    }
}
function call(array $reqs)
{
    foreach ($reqs as &$r) {
        $r['apiVersion'] = 1;
    }
    return (new IskApi())->process(json_decode(json_encode($reqs)));
}

$rm = (int)Categories::byCode('RM')['id'];
$rw = (int)Categories::byCode('RW')['id'];

echo "# 屋外 36射 x 2距離\n";
Settings::setMany(['code' => 'WA2', 'name' => 'WA形式テスト', 'preset' => 'outdoor72', 'distance_name' => '70m', 'distance_name2' => '60m']);
ok(Settings::distances() === 2 && Settings::ends() === 6 && Settings::arrowsPerEnd() === 6 && Settings::totalArrows() === 72, '設定: 2距離 6x6 = 72射');
ok(Settings::distanceSummary() === '70m + 60m' && Settings::targetFace() === 5, '距離の要約と的面(屋外122cm)');
$a = Entries::save(['family_name' => '山田', 'given_name' => '太郎', 'category_id' => $rm, 'target' => 1, 'letter' => 'A']);
$b = Entries::save(['family_name' => '佐藤', 'given_name' => '花子', 'category_id' => $rw, 'target' => 1, 'letter' => 'B']);
$c = Entries::save(['family_name' => '鈴木', 'given_name' => '次郎', 'category_id' => $rm, 'target' => 2, 'letter' => 'A']);
call([['action' => 'handshake', 'uuid' => 'd1', 'version' => '1.10.0', 'iskType' => 11, 'tournament' => 'WA2']]);
$r = call([['action' => 'comp', 'device' => 'd1', 'tocode' => 'WA2']]);
ok($r['responses'][0]['numdist'] === 2 && $r['responses'][0]['tocategory'] === '1', 'comp: numdist=2');
$r = call([['action' => 'manual', 'device' => 'd1', 'tocode' => 'WA2', 'type' => 'Q', 'sesstarget' => '10001']]);
$cfg = $r['responses'][0];
$sc = $cfg['archers'][0]['scoring'];
ok(count($sc) === 2 && $sc[0]['distance'] === '1' && $sc[1]['distance'] === '2', 'manual: 距離ごとの scoring が2つ');
ok($sc[0]['distanceName'] === '70m' && $sc[1]['distanceName'] === '60m' && $sc[0]['ends'] === '6' && $sc[0]['arrows'] === '6', '距離名とエンド/矢数');
ok(strlen($sc[0]['arrowstring']) === 36 && strlen($sc[1]['arrowstring']) === 36, '距離ごとの arrowstring は36文字');
ok($cfg['schedule'] === 'Q21', 'schedule=Q21 (2距離, セッション1)');

$upd = ['action' => 'update', 'device' => 'd1', 'tocode' => 'WA2', 'type' => 'Q', 'session' => 1];
$r = call([$upd + ['distance' => 1, 'refKey' => '1.1A', 'arrowstring' => str_repeat('L', 36)]]);
ok($r['responses'][0]['action'] === 'noop', '距離1を送信');
$r = call([$upd + ['distance' => 2, 'refKey' => '1.1A', 'arrowstring' => str_repeat('J', 12)]]);
$e = Entries::find($a);
ok($e['arrowstring'] === str_repeat('L', 36) && rtrim($e['arrowstring2']) === str_repeat('J', 12), '距離1と距離2は別々に保存');
$r = call([$upd + ['distance' => 3, 'refKey' => '1.1A', 'arrowstring' => 'LLLLLL']]);
ok($r['error'] === true, '距離3は拒否');
$r = call([$upd + ['distance' => 2, 'refKey' => '1.1A', 'arrowstring' => str_repeat(' ', 12) . 'JJJJJJ', 'signed' => '1']]);
ok(strlen(rtrim(Entries::find($a)['arrowstring2'])) === 18 && (Entries::find($a)['signed'] & 4) === 4 && (Entries::find($a)['signed'] & 2) === 0, '距離2の署名は 2^2 のビット(距離1は立たない)');
$cfg = call([['action' => 'getconfig', 'device' => 'd1']])['responses'][0];
$sc = $cfg['archers'][0]['scoring'];
ok($sc[0]['previous'] === '0' && $sc[1]['previous'] === '360' && $sc[1]['previousGold'] === '36' && $sc[1]['previousXnine'] === '0', '距離2の previous = 距離1の合計(360点・10が36本)。本家と同じ文字列');

$r = call([['action' => 'sendall', 'device' => 'd1', 'tocode' => 'WA2', 'type' => 'Q', 'session' => 1, 'archers' => [
    ['refKey' => '1.1B', 'scoring' => [
        ['distance' => 1, 'arrowstring' => str_repeat('K', 36)],
        ['distance' => 2, 'arrowstring' => str_repeat('J', 36)],
    ]],
]]]);
$eb = Entries::find($b);
ok($r['responses'][0]['action'] === 'sendall' && $eb['arrowstring'] === str_repeat('K', 36) && $eb['arrowstring2'] === str_repeat('J', 36), 'sendall で2距離まとめて保存');
ok(Entries::fullName($eb) === '佐藤 花子', '選手名');

echo "# 順位(合計 → 10+X → X)\n";
call([array_merge($upd, ['distance' => 1, 'refKey' => '1.1A', 'arrowstring' => str_repeat('L', 36)])]);
Entries::applyArrows($a, str_repeat('J', 36), 't', null, 'admin', 2);   // 山田: 360 + 324 = 684
Entries::applyArrows($c, str_repeat('K', 36), 't', null, 'admin', 1);   // 鈴木: 360(X36) + 324 = 684 → X が多い
Entries::applyArrows($c, str_repeat('J', 36), 't', null, 'admin', 2);
$g = Results::byCategory();
$men = $g[0]['rows'];
ok($g[0]['category']['name'] === 'リカーブ男子' && count($men) === 2, '男子の部');
ok($men[0]['id'] == $c && $men[0]['score'] === 684 && $men[0]['xs'] === 36 && $men[0]['rank'] === 1, '同点684 → X が多い鈴木が1位');
ok($men[1]['id'] == $a && $men[1]['rank'] === 2 && !$men[1]['tie'], '山田は2位(同順位ではない)');
ok($men[0]['dist_scores'] === [1 => 360, 2 => 324], '距離ごとの合計');
ok($g[1]['rows'][0]['score'] === 360 + 324 && $g[1]['rows'][0]['golds'] === 36, '女子: 佐藤 684 / 10+X 36');
Entries::applyArrows($a, str_repeat('K', 36), 't', null, 'admin', 1);   // 山田も X36 → 鈴木と全部同じ
$men = Results::byCategory()[0]['rows'];
ok($men[0]['rank'] === 1 && $men[1]['rank'] === 1 && $men[0]['tie'] && $men[1]['tie'], '合計・10+X・Xが全部同じなら同順位(*)');
$p = Results::progress();
ok($p['all_arrows'] === 3 * 72, '進行の分母は 人数 x 72射');

echo "# 管理画面からの訂正\n";
Entries::replaceArrows($c, str_repeat('L', 36), 'admin', 2);
ok(Entries::find($c)['arrowstring2'] === str_repeat('L', 36) && Entries::find($c)['arrowstring'] === str_repeat('K', 36), '距離2だけ訂正しても距離1は変わらない');
$log = Entries::log($c);
ok($log[0]['distance'] == 2 && $log[0]['source'] === 'admin', '履歴に距離が残る');

echo "# 設定変更の保護と PDF\n";
ok(Entries::hasScore(Entries::find($c)), 'hasScore');
$pdf = Pdf::scorecards(Entries::all(), true, 'http://192.168.0.10/ianseo-lite/');
ok(substr($pdf, 0, 4) === '%PDF', 'スコアカードPDF 2距離(得点入り)');
ok(substr(Pdf::scorecards(Entries::all(), false), 0, 4) === '%PDF', 'スコアカードPDF 2距離(白紙)');
ok(substr(Pdf::results(Results::byCategory()), 0, 4) === '%PDF', '結果PDF 2距離');

echo "# 屋内 30射 x 2距離 / 1距離\n";
Settings::setMany(['preset' => 'indoor60', 'distance_name' => '', 'distance_name2' => '', 'indoor_face' => '2']);
ok(Settings::distances() === 2 && Settings::ends() === 10 && Settings::arrowsPerEnd() === 3 && Settings::totalArrows() === 60 && Settings::isIndoor(), '屋内60: 3射 x 10エンド x 2距離');
ok(Settings::distanceSummary() === '18m x 2' && Settings::targetFace() === 2, '屋内の既定は18m、的面は設定(三つ目)');
$cfg = call([['action' => 'manual', 'device' => 'd1', 'tocode' => 'WA2', 'type' => 'Q', 'sesstarget' => '10001']])['responses'][0];
ok($cfg['ctype'] === 'I' && count($cfg['archers'][0]['scoring']) === 2 && $cfg['archers'][0]['scoring'][0]['arrows'] === '3' && $cfg['targetFaces'][0]['code'] === '2', '屋内: ctype=I, 3射, 的面2');
ok(strlen($cfg['archers'][0]['scoring'][0]['arrowstring']) === 30, '屋内: 距離ごと30文字');
ok(substr(Pdf::scorecards(Entries::all(), true), 0, 4) === '%PDF', 'スコアカードPDF 屋内60(1人1ページ)');
ok(substr(Pdf::results(Results::byCategory()), 0, 4) === '%PDF', '結果PDF 屋内60');
Settings::setMany(['preset' => 'indoor30']);
ok(Settings::distances() === 1 && Settings::totalArrows() === 30, '屋内30: 1距離');
$cfg = call([['action' => 'manual', 'device' => 'd1', 'tocode' => 'WA2', 'type' => 'Q', 'sesstarget' => '10001']])['responses'][0];
ok(count($cfg['archers'][0]['scoring']) === 1 && $cfg['schedule'] === 'Q11', '屋内30: scoring は1つ、schedule=Q11');
Settings::setMany(['preset' => 'bogus']);
ok(Settings::distances() === 2 && Settings::totalArrows() === 72, '不明な形式は既定(屋外 36x2)に戻る');

echo $fail ? "\nFAILED(2距離): $fail\n" : "\nAll OK(2距離)\n";
@unlink(getenv('LITE_DB'));
@unlink(getenv('LITE_DB') . '-wal');
@unlink(getenv('LITE_DB') . '-shm');
exit($fail ? 1 : 0);
