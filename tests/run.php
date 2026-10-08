<?php
// php tests/run.php  -- 一時DBで ScorekeeperNG との通信を再現して確認する
putenv('LITE_DB=' . sys_get_temp_dir() . '/lite-test-' . getmypid() . '.sqlite');
require dirname(__DIR__) . '/src/bootstrap.php';

use Lite\{Categories, Devices, Entries, IskApi, Results, Scoring, Settings};

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

echo "# 大会設定と選手\n";
Settings::setMany(['code' => 'KYT26', 'name' => '京都テスト大会', 'preset' => 'outdoor36', 'pin' => '1234']);
$rm = (int)Categories::byCode('RM')['id'];
$rw = (int)Categories::byCode('RW')['id'];
ok(count(Categories::all()) === 2, '部門はリカーブ男子・女子の2つだけ');
ok(Categories::resolve('男子') === $rm && Categories::resolve('リカーブ女子') === $rw && Categories::resolve('W') === $rw && Categories::resolve('RM') === $rm && Categories::resolve('コンパウンド') === null && Categories::resolve('コンパウンド男子') === null && Categories::resolve('Recurve Men') === $rm && Categories::resolve('女子リカーブ') === $rw && Categories::resolve('男') === $rm && Categories::resolve('') === null, '部門の表記ゆれを判別(コンパウンドは拒否)');
$a = Entries::save(['family_name' => '山田', 'given_name' => '太郎', 'affiliation' => '京都大', 'category_id' => $rm, 'target' => 1, 'letter' => 'A']);
$b = Entries::save(['family_name' => '佐藤', 'given_name' => '花子', 'affiliation' => '同志社', 'category_id' => $rw, 'target' => 1, 'letter' => 'B']);
$c = Entries::save(['family_name' => '鈴木', 'given_name' => '次郎', 'affiliation' => '立命館', 'category_id' => $rm, 'target' => 2, 'letter' => 'A']);
ok(Entries::find($a)['bib'] === (string)$a, '端末へ渡す選手コード(bib)は ID');
try {
    Entries::save(['family_name' => '重複', 'category_id' => $rm, 'target' => 1, 'letter' => 'A']);
    ok(false, '同じ的位置は拒否');
} catch (InvalidArgumentException $e) {
    ok(true, '同じ的位置は拒否');
}

try {
    Entries::save(['family_name' => '部門なし']);
    ok(false, '部門なしは拒否');
} catch (InvalidArgumentException $e) {
    ok(true, '部門なしは拒否');
}

echo "# handshake\n";
$h = ['action' => 'handshake', 'uuid' => 'dev-1', 'version' => '1.10.0', 'iskType' => 11];
$r = call([$h + ['tournament' => 'KYT26']]);
ok($r['error'] === true && $r['responses'][0]['error'] === 1, 'PIN 無しの登録は拒否');
$r = call([$h + ['tournament' => 'KYT26|9999']]);
ok($r['error'] === true && $r['responses'] === [], 'PIN 違いは Validation failed');
$r = call([$h + ['tournament' => 'KYT26|1234']]);
ok($r['error'] === false && $r['responses'][0]['error'] === 0 && $r['responses'][0]['devCode'] === '0001', '正しい PIN で登録 devCode=0001');
$r = call([['action' => 'handshake', 'uuid' => 'dev-1', 'version' => '1.10.0', 'iskType' => 12, 'tournament' => 'KYT26|1234']]);
ok($r['responses'][0]['error'] === 1, 'Pro モードの端末は拒否');
$r = call([array_merge($h, ['tournament' => 'KYT26|1234', 'uuid' => 'dev-2'])]);
ok($r['responses'][0]['devCode'] === '0002', '2台目は 0002');

echo "# comp / manual\n";
$r = call([['action' => 'comp', 'device' => 'nobody', 'tocode' => 'KYT26']]);
ok($r['responses'][0]['action'] === 'handshake' && $r['responses'][0]['error'] === 2, '未登録端末は handshake error 2');
$r = call([['action' => 'comp', 'device' => 'dev-1', 'tocode' => 'KYT26|1234']]);
$comp = $r['responses'][0];
ok($comp['toname'] === '京都テスト大会' && $comp['stages'] === ['Q'], 'comp: 大会名と stages');
ok(array_column($comp['sessions'][0]['sesstargets'], 'id') === ['0001', '0002'], 'comp: 的一覧');
$r = call([['action' => 'manual', 'device' => 'dev-1', 'tocode' => 'KYT26', 'type' => 'Q', 'sesstarget' => '10001']]);
$cfg = $r['responses'][0];
ok($cfg['action'] === 'reconfigure' && count($cfg['archers']) === 2, 'manual: reconfigure に 2 人');
ok($cfg['archers'][0]['refKey'] === '1.1A' && $cfg['archers'][1]['refKey'] === '1.1B', 'refKey = 1.1A, 1.1B');
ok($cfg['archers'][0]['scoring'][0]['ends'] === '6' && strlen($cfg['archers'][0]['scoring'][0]['arrowstring']) === 36, 'ends=6, arrowstring 36文字');
ok(isset($cfg['configHash']) && $cfg['targetFaces'][0]['code'] === '5' && $cfg['targetFaces'][0]['letterPoint'][0]['letter'] === 'K', 'targetFaces と configHash');
// 端末は scoring.targetface と targetFaces[].code を突き合わせて入力画面の的を決める。型(文字列)まで一致している必要がある
$codes = array_column($cfg['targetFaces'], 'code');
ok(in_array($cfg['archers'][0]['scoring'][0]['targetface'], $codes, true), 'scoring.targetface が targetFaces[].code と(型まで)一致');
foreach ($cfg['archers'][0]['scoring'][0] as $k => $v) {
    $types[$k] = gettype($v);
}
ok($types['previous'] === 'string' && $types['ends'] === 'string' && $types['scoringEnds'] === 'integer' && $types['shootOff'] === 'integer', '数値項目の型が本家と同じ');
ok(!isset($cfg['targetFaces'][0]['imgGzip']), 'v1.10 は画像を含めない');
$r = call([['action' => 'manual', 'device' => 'dev-1', 'tocode' => 'KYT26', 'type' => 'Q', 'sesstarget' => '10099']]);
ok($r['responses'][0]['action'] === 'reset', '選手のいない的は reset');
call([['action' => 'manual', 'device' => 'dev-1', 'tocode' => 'KYT26', 'type' => 'Q', 'sesstarget' => '10001']]);

echo "# confirmhash\n";
$r = call([['action' => 'confirmhash', 'device' => 'dev-1', 'configHash' => $cfg['configHash']]]);
ok($r['responses'][0]['action'] === 'noop' && Devices::find('dev-1')['setup_confirmed'] == 1, 'confirmhash 一致 -> noop');
$r = call([['action' => 'confirmhash', 'device' => 'dev-1', 'configHash' => 'xxxx']]);
ok($r['responses'][0]['action'] === 'reconfigure', 'confirmhash 不一致 -> reconfigure');

echo "# update\n";
$upd = ['action' => 'update', 'device' => 'dev-1', 'tocode' => 'KYT26', 'type' => 'Q', 'session' => 1, 'distance' => 1];
$r = call([$upd + ['refKey' => '1.1A', 'arrowstring' => 'KLJIHG']]);
ok($r['responses'][0]['action'] === 'noop', 'update 1エンド');
ok(Entries::find($a)['arrowstring'] === 'KLJIHG' . str_repeat(' ', 30), 'DB に保存 (全長36文字に空白詰め)');
$r = call([$upd + ['refKey' => '1.1A', 'arrowstring' => str_repeat(' ', 6) . 'LLLLLL']]);
ok(rtrim(Entries::find($a)['arrowstring']) === 'KLJIHGLLLLLL', '2エンド目を追加しても 1エンド目は残る');
$r = call([$upd + ['refKey' => '1.1A', 'arrowstring' => 'KLJIHG' . 'LLLLLL', 'signed' => '1']]);
ok((Entries::find($a)['signed'] & 2) === 2, 'signed を保存(距離1は 2^1 のビット)');
$r = call([$upd + ['refKey' => '1.2A', 'arrowstring' => 'LLLLLL']]);
ok($r['responses'][0]['action'] === 'reset', '他の的の選手には書けない -> reset');
ok(Entries::find($c)['arrowstring'] === '', '他の的は変更されない');
$r = call([$upd + ['refKey' => '1.1A', 'arrowstring' => 'ZZZZZZ']]);
ok($r['error'] === true, '不正な文字はエラー');
Entries::setLocked($b, true);
$r = call([$upd + ['refKey' => '1.1B', 'arrowstring' => 'LLLLLL']]);
ok($r['error'] === true && strpos($r['responses'][0]['resetMessage'], 'validated') !== false, '確定済みスコアカードは拒否');
Entries::setLocked($b, false);

echo "# sendall\n";
$r = call([['action' => 'sendall', 'device' => 'dev-1', 'tocode' => 'KYT26', 'type' => 'Q', 'session' => 1, 'archers' => [
    ['refKey' => '1.1B', 'scoring' => [['distance' => 1, 'arrowstring' => 'JJJJJJ' . 'IIIIII' . str_repeat(' ', 24)]]],
]]]);
ok($r['responses'][0]['action'] === 'sendall' && rtrim(Entries::find($b)['arrowstring']) === 'JJJJJJIIIIII', 'sendall で保存');
$r = call([['action' => 'getconfig', 'device' => 'dev-1']]);
ok(substr($r['responses'][0]['archers'][1]['scoring'][0]['arrowstring'], 0, 12) === 'JJJJJJIIIIII', 'getconfig に最新の得点が入る');

echo "# その他\n";
$r = call([['action' => 'info', 'device' => 'dev-1', 'data' => ['battery' => ['charging' => true, 'charge' => 0.5]]]]);
ok(Devices::find('dev-1')['battery'] == -50, 'info: 電池');
$r = call([['action' => 'chargestatus', 'device' => 'dev-1', 'charge' => 80, 'charging' => false]]);
ok(Devices::find('dev-1')['battery'] == 80, 'chargestatus');
$r = call([['action' => 'lang', 'device' => 'dev-1']]);
ok(count($r['responses'][0]['languageList']) >= 2, 'lang 一覧');
$r = call([['action' => 'lang', 'device' => 'dev-1', 'lang' => 'ja']]);
ok($r['responses'][0]['translations']['AppTitle'] === 'スコアキーパーNG', 'lang ja');
$r = call([['action' => 'gettargetimages', 'device' => 'dev-1', 'imgCodes' => [5, 1]]]);
ok(count($r['responses'][0]['images']) === 2, 'gettargetimages');
$r = call([['action' => 'ping', 'device' => 'dev-1']]);
ok($r['responses'][0] === 'pong', 'ping');
$r = call([['action' => 'gateverify', 'device' => 'dev-1']]);
ok($r['error'] === true, '未対応アクションはエラー');
$r = call([['action' => 'handshake', 'uuid' => 'old', 'version' => '1.2.0', 'iskType' => 11, 'tournament' => 'KYT26|1234'],
    ['action' => 'manual', 'device' => 'old', 'tocode' => 'KYT26', 'type' => 'Q', 'sesstarget' => '10002']]);
ok(isset($r['responses'][1]['targetFaces'][0]['imgGzip']), 'v1.2 の端末には的面画像を含める');

echo "# 採点と順位\n";
ok(Scoring::total('KLJIHG') === [10 + 10 + 9 + 8 + 7 + 6, 2, 1, 6], '採点: 合計/ゴールド/X/射数');
ok(Scoring::parseInput('X 10 9 8 7 M') === 'KLJIHA', '入力 "X 10 9 8 7 M"');
ok(Scoring::parseInput('X1098') === 'KLIHJ' || Scoring::parseInput('X1098') === 'KLJI', '連続入力 X1098 -> KLJI');
ok(Scoring::parseInput('X Q') === null, '不正入力は null');
$g = Results::byCategory();
ok($g[0]['category']['name'] === 'リカーブ男子' && $g[0]['rows'][0]['rank'] === 1, '順位: 部門ごと');
ok($g[0]['rows'][0]['score'] > $g[0]['rows'][1]['score'] - 1 && $g[0]['rows'][0]['id'] == $a, '山田が 1 位');
$d = Entries::save(['family_name' => '田中', 'category_id' => $rm]);
$e = Entries::save(['family_name' => '高橋', 'category_id' => $rm]);
Entries::autoAssign(2);
ok(Entries::find($a)['target'] == 1 && Entries::find($a)['letter'] === 'A', '得点のある選手の的は動かさない');
ok(Entries::find($b)['target'] == 1 && Entries::find($b)['letter'] === 'B', '得点のある選手(B)も動かさない');
ok(Entries::find($c)['target'] == 2 && Entries::find($c)['letter'] === 'A', '未得点の選手は先頭から詰め直す (鈴木=2A)');
ok(Entries::find($d)['target'] == 2 && Entries::find($d)['letter'] === 'B' && Entries::find($e)['target'] == 3, '田中=2B, 高橋=3A');

echo "# 最終更新\n";
ok(preg_match('/^\d\d\/\d\d \d\d:\d\d:\d\d\(\d+秒前\)$/', Results::lastUpdateText()) === 1, '最終更新の表示: ' . Results::lastUpdateText());
ok(strtotime(Results::lastUpdate()) >= time() - 5, '最終更新は直近の更新時刻');

echo "# PDF\n";
$pdf = Lite\Pdf::scorecards(Entries::all(), true, 'http://192.168.0.10/ianseo-lite/');
ok(substr($pdf, 0, 4) === '%PDF' && strlen($pdf) > 20000, 'スコアカードPDF(QR付き)');
$pdf = Lite\Pdf::scorecards(Entries::all(), false);
ok(substr($pdf, 0, 4) === '%PDF', 'スコアカードPDF(白紙)');
$pdf = Lite\Pdf::results(Results::byCategory());
ok(substr($pdf, 0, 4) === '%PDF', '結果PDF');
$pdf = Lite\Pdf::qrSheet('http://192.168.0.10/ianseo-lite/');
ok(substr($pdf, 0, 4) === '%PDF', '的QR PDF');
ok(json_decode(Lite\Connect::targetPayload('http://x/', 12), true) === ['u' => 'http://x/', 'c' => 'KYT26|1234', 's' => '1', 't' => '0012', 'st' => 'Q'], '的QRの中身');

echo $fail ? "\nFAILED: $fail\n" : "\nAll OK\n";
@unlink(getenv('LITE_DB'));
@unlink(getenv('LITE_DB') . '-wal');
@unlink(getenv('LITE_DB') . '-shm');

// 2距離・屋内の組み合わせは別プロセスで確認する
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/two_distances.php'), $code);
exit($fail || $code ? 1 : 0);
