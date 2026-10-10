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

echo "# 記録会設定と選手\n";
Settings::setMany(['code' => 'KYT26', 'name' => '京都テスト記録会', 'preset' => 'outdoor36']);
$rm = (int)Categories::byCode('RM')['id'];
$rw = (int)Categories::byCode('RW')['id'];
ok(count(Categories::all()) === 2 && Categories::all()[0]['name'] === '男子', '部門の初期値は 男子・女子');
ok(Categories::resolve('男子') === $rm && Categories::resolve('リカーブ女子') === $rw && Categories::resolve('W') === $rw && Categories::resolve('RM') === $rm && Categories::resolve('コンパウンド') === null && Categories::resolve('コンパウンド男子') === null && Categories::resolve('Recurve Men') === $rm && Categories::resolve('女子リカーブ') === $rw && Categories::resolve('男') === $rm && Categories::resolve('') === null, '部門の表記ゆれを判別(コンパウンドは拒否)');
// 部門の追加・変更・削除
$cx = Categories::add('cm', 'コンパウンド男子');
ok(Categories::find($cx)['code'] === 'CM' && Categories::all()[2]['id'] == $cx && Categories::resolve('コンパウンド男子') === $cx && Categories::resolve('cm') === $cx, '部門を追加(コードは大文字、最後に並ぶ、名前・コードで判別)');
$dup = false;
try { Categories::add('RM', '別'); } catch (InvalidArgumentException $e) { $dup = true; }
ok($dup, '部門コードの重複は不可');
Categories::update($cx, 'CM', 'コンパウンド男子', 5);
ok(Categories::firstId() === $cx, '並び順を変えると先頭の部門が変わる');
Categories::delete($cx);
ok(count(Categories::all()) === 2 && Categories::firstId() === $rm, '部門を削除');
$last = false;
$tmp = Lite\Db::pdo();
$tmp->exec('BEGIN');
Categories::delete($rw);
try { Categories::delete($rm); } catch (InvalidArgumentException $e) { $last = true; }
$tmp->exec('ROLLBACK');
ok($last && count(Categories::all()) === 2, '最後の 1 つの部門は削除できない');
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
$r = call([$h + ['tournament' => 'WRONG']]);
ok($r['error'] === true && $r['responses'][0]['error'] === 1, '違う記録会コードの登録は拒否');
$r = call([$h + ['tournament' => 'KYT26']]);
ok($r['error'] === false && $r['responses'][0]['error'] === 0 && $r['responses'][0]['devCode'] === '0001', '記録会コードで登録 devCode=0001');
$r = call([array_merge($h, ['uuid' => 'dev-pin', 'tournament' => 'KYT26|1234'])]);
ok($r['responses'][0]['error'] === 0, '本家の "コード|PIN" の形で来ても、PINは無視して登録できる');
Devices::forget('dev-pin');
$r = call([['action' => 'handshake', 'uuid' => 'dev-1', 'version' => '1.10.0', 'iskType' => 12, 'tournament' => 'KYT26|1234']]);
ok($r['responses'][0]['error'] === 1, 'Pro モードの端末は拒否');
$r = call([array_merge($h, ['tournament' => 'KYT26|1234', 'uuid' => 'dev-2'])]);
ok($r['responses'][0]['devCode'] === '0002', '次の端末は 0002');

echo "# comp / manual\n";
$r = call([['action' => 'comp', 'device' => 'nobody', 'tocode' => 'KYT26']]);
ok($r['responses'][0]['action'] === 'handshake' && $r['responses'][0]['error'] === 2, '未登録端末は handshake error 2');
$r = call([['action' => 'comp', 'device' => 'dev-1', 'tocode' => 'KYT26|1234']]);
$comp = $r['responses'][0];
ok($comp['toname'] === '京都テスト記録会' && $comp['stages'] === ['Q'], 'comp: 記録会名と stages');
ok(array_column($comp['sessions'][0]['sesstargets'], 'id') === ['0001', '0002'], 'comp: 的一覧');
$r = call([['action' => 'manual', 'device' => 'dev-1', 'tocode' => 'KYT26', 'type' => 'Q', 'sesstarget' => '10001']]);
$cfg = $r['responses'][0];
ok($cfg['action'] === 'reconfigure' && count($cfg['archers']) === 2, 'manual: reconfigure に 2 人');
ok($cfg['archers'][0]['refKey'] === '1.1A' && $cfg['archers'][1]['refKey'] === '1.1B', 'refKey = 1.1A, 1.1B');
ok($cfg['archers'][0]['placement'] === '1A' && $cfg['archers'][1]['placement'] === '1B', 'placement = 1A, 1B(本家と同じ。端末の選手一覧に位置が出る)');
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

// 実機は的番号を3桁で付けた "1001"(セッション1+的001)で送ってきた。どちらの形でも同じ的として扱う
foreach (['10001', '1001', '11', 1001, '0001'] as $st) {
    $r = call([['action' => 'manual', 'device' => 'dev-1', 'tocode' => 'KYT26', 'type' => 'Q', 'sesstarget' => $st]]);
    ok($r['responses'][0]['action'] === 'reconfigure' && $r['responses'][0]['targetAssigned'] === '1', 'manual sesstarget=' . var_export($st, true) . ' -> 的1の reconfigure');
}
$r = call([['action' => 'manual', 'device' => 'dev-1', 'tocode' => 'KYT26', 'type' => 'Q', 'sesstarget' => '1002']]);
ok($r['responses'][0]['action'] === 'reconfigure' && $r['responses'][0]['targetAssigned'] === '2', 'sesstarget=1002 -> 的2');
ok(IskApi::parseSessionTarget('2001') === [2, 1] && IskApi::parseSessionTarget('abc') === [0, 0] && IskApi::parseSessionTarget('10012') === [1, 12] && IskApi::parseSessionTarget('0012') === [1, 12], 'sesstarget の解釈(別セッション・不正な値)');
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
ok($r['responses'][0]['translations']['AppTitle'] === 'Scorekeeper NG' && $r['responses'][0]['translations']['SettingsConnectionCompCode'] === '記録会コード', 'lang ja');
$r = call([['action' => 'gettargetimages', 'device' => 'dev-1', 'imgCodes' => [5, 1]]]);
ok(count($r['responses'][0]['images']) === 2, 'gettargetimages');
$r = call([['action' => 'ping', 'device' => 'dev-1']]);
ok($r['responses'][0] === 'pong', 'ping');
$r = call([['action' => 'gateverify', 'device' => 'dev-1']]);
ok($r['error'] === true, '未対応アクションはエラー');
$r = call([['action' => 'handshake', 'uuid' => 'old', 'version' => '1.2.0', 'iskType' => 11, 'tournament' => 'KYT26|1234'],
    ['action' => 'manual', 'device' => 'old', 'tocode' => 'KYT26', 'type' => 'Q', 'sesstarget' => '10002']]);
ok(isset($r['responses'][1]['targetFaces'][0]['imgGzip']), 'v1.2 の端末には的面画像を含める');

echo "# 言語パック(本家の翻訳サイトから取得。ここではネットワークに出ない見本で確かめる)\n";
$evil = 'O:8:"stdClass":0:{}';
Lite\LangPacks::$downloader = function ($url) use (&$lastUrl) {
    $lastUrl = $url;
    $body = "\$lang['AppTitle']='Scorekeeper NG';\n\$lang['GenericOk']='OK';\n\$lang['Quote']='It\\'s';\nsystem('id');\n";
    for ($i = 0; $i < 12; $i++) {
        $body .= "\$lang['K$i']='値$i';\n";
    }
    return gzdeflate(serialize(['langs' => [
        'ja' => ['flag-png' => "\x89PNG\r\n\x1a\nxx", 'testuale' => '日本語 - Japanese', 'lang' => ['ISK-App' => $body, 'Common' => 'x']],
        '../x' => ['lang' => ['ISK-App' => $body]],
        'fr' => ['lang' => ['Common' => 'x']],
    ], 'credits' => '']));
};
$got = Lite\LangPacks::fetch(['ja', 'fr']);
ok($got === ['ja'] && strpos($lastUrl, 'l[]=ja&l[]=fr') !== false, '言語パックを取得(ISK-App があるものだけ保存、不正な言語名は無視)');
$r = call([['action' => 'lang', 'device' => 'dev-1', 'lang' => 'ja']]);
$t = $r['responses'][0]['translations'];
ok($t['AppTitle'] === 'Scorekeeper NG' && $t['Quote'] === "It's" && $t['K3'] === '値3' && !isset($t['SettingsConnectionCompCode']), '取得した訳を優先して端末に返す(文字として読むだけで実行しない)');
$list = array_column(call([['action' => 'lang', 'device' => 'dev-1']])['responses'][0]['languageList'], null, 'id');
ok(isset($list['en'], $list['ja']) && strpos($list['ja']['flagBase64'], 'data:image/png;base64,') === 0, '言語の一覧は同梱+取得');
Lite\LangPacks::clear();
$r = call([['action' => 'lang', 'device' => 'dev-1', 'lang' => 'ja']]);
ok($r['responses'][0]['translations']['SettingsConnectionCompCode'] === '記録会コード', '取得したものを消すと同梱の訳に戻る');
Lite\LangPacks::$downloader = function () { return gzdeflate('O:8:"stdClass":0:{}'); };
$bad = false;
try { Lite\LangPacks::fetch(['ja']); } catch (RuntimeException $e) { $bad = true; }
ok($bad, '想定と違う形(オブジェクトなど)は受け付けない');
Lite\LangPacks::$downloader = null;

echo "# 採点と順位\n";
ok(Scoring::total('KLJIHG', 'KL', 'K') === [10 + 10 + 9 + 8 + 7 + 6, 2, 1, 6], '採点: 合計/第1優先(10+X)/第2優先(X)/射数');
ok(Scoring::total('KLJIHG') === [50, 1, 1, 6], '採点: 優先順位の初期値は 屋外 X → 10');
ok(Scoring::parseInput('X 10 9 8 7 M') === 'KLJIHA', '入力 "X 10 9 8 7 M"');
ok(Scoring::parseInput('X1098') === 'KLIHJ' || Scoring::parseInput('X1098') === 'KLJI', '連続入力 X1098 -> KLJI');
ok(Scoring::parseInput('X Q') === null, '不正入力は null');
$g = Results::byCategory();
ok($g[0]['category']['name'] === '男子' && $g[0]['rows'][0]['rank'] === 1, '順位: 部門ごと');
ok($g[0]['rows'][0]['score'] > $g[0]['rows'][1]['score'] - 1 && $g[0]['rows'][0]['id'] == $a, '山田が 1 位');
$d = Entries::save(['family_name' => '田中', 'category_id' => $rm]);
$e = Entries::save(['family_name' => '高橋', 'category_id' => $rm]);
Entries::autoAssign(2);
ok(Entries::find($a)['target'] == 1 && Entries::find($a)['letter'] === 'A', '得点のある選手の的は動かさない');
ok(Entries::find($b)['target'] == 1 && Entries::find($b)['letter'] === 'B', '得点のある選手(B)も動かさない');
ok(Entries::find($c)['target'] == 2 && Entries::find($c)['letter'] === 'A', '未得点の選手は先頭から詰め直す (鈴木=2A)');
ok(Entries::find($d)['target'] == 2 && Entries::find($d)['letter'] === 'B' && Entries::find($e)['target'] == 3, '田中=2B, 高橋=3A');

// 欠場: 結果には部門の最後に「欠」(順位なし)、端末の的の一覧には出さない
$tgtE = (int)Entries::find($e)['target'];
Entries::save(['family_name' => '高橋', 'category_id' => $rm, 'status' => 1], $e);
$men = Results::byCategory()[0]['rows'];
$last = end($men);
ok($last['id'] == $e && $last['absent'] && $last['rank'] === null, '欠場は結果の部門の最後に、順位なしで出る');
ok(!array_filter(Entries::onTarget($tgtE), function ($r) use ($e) { return $r['id'] == $e; }), '欠場は端末の的の一覧に出ない');
ok(strpos(Lite\Pdf::results(Results::byCategory()), '%PDF') === 0, '欠場がいても結果PDF');
Entries::save(['family_name' => '高橋', 'category_id' => $rm, 'status' => 0], $e);

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
ok(json_decode(Lite\Connect::targetPayload('http://x/', 12), true) === ['u' => 'http://x/', 'c' => 'KYT26', 's' => '1', 't' => '0012', 'st' => 'Q'], '的QRの中身');

echo $fail ? "\nFAILED: $fail\n" : "\nAll OK\n";
@unlink(getenv('LITE_DB'));
@unlink(getenv('LITE_DB') . '-wal');
@unlink(getenv('LITE_DB') . '-shm');

// 2距離・屋内の組み合わせは別プロセスで確認する
$code = 0;
foreach (['two_distances.php', 'multi.php'] as $f) {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $f), $c);
    $code |= $c;
}
exit($fail || $code ? 1 : 0);
