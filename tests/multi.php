<?php
// php tests/multi.php -- 記録会ごとの DB・パスワード・切り替え・端末の振り分け・的番号の移動・サンプル記録会
$dataDir = sys_get_temp_dir() . '/lite-multi-' . getmypid();
mkdir($dataDir, 0775, true);
putenv('LITE_DATA_DIR=' . $dataDir);
putenv('LITE_DB');                       // 固定 DB を使わない(記録会ごとの DB を使う)
require dirname(__DIR__) . '/src/bootstrap.php';

use Lite\{ApiLog, Auth, Categories, Competitions, Db, Devices, Entries, IskApi, Past, Settings};

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
    $api = new IskApi();
    $res = $api->process(json_decode(json_encode($reqs)));
    ApiLog::write($reqs, $res, $api->usedCodes());
    return $res;
}
function rrmdir($d)
{
    foreach (glob($d . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (in_array(basename($f), ['.', '..'])) {
            continue;
        }
        is_dir($f) ? rrmdir($f) : @unlink($f);
    }
    @rmdir($d);
}

echo "# サンプル記録会\n";
$list = Competitions::all();
$samples = [];
foreach ($list as $c) {
    if ($c['sample']) {
        $samples[$c['code']] = $c;
    }
}
ok(isset($samples['5A4D0001'], $samples['5A4D0002']) && $samples['5A4D0001']['entries'] === 16 && $samples['5A4D0002']['entries'] === 16,
    'サンプル記録会(屋外・屋内)が記録会フォルダに入っている(選手16人ずつ)');
ok(Auth::verify('5A4D0002', 'sample'), '屋内サンプルのパスワードも sample');
Competitions::open('5A4D0002');
ok(Lite\Settings::preset()['label'] === 'Indoor 30射×2（60射）' && Lite\Results::byCategory()[0]['rows'][0]['hits'] === 60, '屋内サンプル: Indoor 30射×2、1位は60射終了');
// 各エンドは X → 10 → … → M の高い順
$sorted = true;
foreach (['5A4D0001', '5A4D0002'] as $sc) {
    Competitions::open($sc);
    $per = Lite\Settings::arrowsPerEnd();
    foreach (Lite\Entries::all() as $e) {
        foreach (str_split(Lite\Entries::allArrows($e), $per) as $end) {
            $chars = str_split(trim($end));
            $want = $chars;
            usort($want, function ($a, $b) {
                return strpos('KLJIHGFEDCBA', $a) <=> strpos('KLJIHGFEDCBA', $b);
            });
            if ($chars !== $want) {
                $sorted = false;
            }
        }
    }
}
ok($sorted, 'サンプル: 各エンドの矢は X → M の高い順');
ok(Lite\Settings::resultsPublic(), 'サンプルは結果を公開している');
ok(Auth::verify('5A4D0001', 'sample') && !Auth::verify('5A4D0001', 'wrong'), 'サンプルのパスワードは sample');
Competitions::open('5A4D0001');
$g = Lite\Results::byCategory();
ok($g[0]['category']['name'] === '男子' && count($g[0]['rows']) === 8 && $g[0]['rows'][0]['rank'] === 1 && $g[0]['rows'][0]['hits'] === 72, 'サンプルの結果: 男子8人、1位は72射終了');
ok(count(array_filter(array_merge($g[0]['rows'], $g[1]['rows']), function ($r) { return $r['hits'] === 72; })) === 16, 'サンプル: 全員が72射とも入力済み');
$pdf = Lite\Pdf::results($g);
ok(substr($pdf, 0, 4) === '%PDF', 'サンプルの結果PDF');
$st = Db::pdo()->query('SELECT COUNT(*) FROM entries WHERE locked = 1');
ok((int)$st->fetchColumn() === 0, 'サンプル: 確定はしていない');

echo "# 記録会の作成とパスワード\n";
$a = Competitions::create('記録会A', 'outdoor72', 'passA1');
$b = Competitions::create('記録会B', 'indoor30', 'passB1');
ok(preg_match('/^[0-9A-F]{8}$/', $a) && preg_match('/^[0-9A-F]{8}$/', $b) && $a !== $b, '記録会コードは8桁の16進数(自動): ' . $a . ', ' . $b);
ok(is_file("$dataDir/competitions/$a.sqlite") && is_file("$dataDir/competitions/$b.sqlite"), '記録会ごとに SQLite ファイル');
ok(Auth::verify($a, 'passA1') && !Auth::verify($a, 'passB1') && Auth::verify($b, 'passB1') && !Auth::verify($b, 'passA1'), 'パスワードは記録会ごと');
ok(strlen((string)Competitions::readSetting($a, 'admin_hash')) > 20 && Competitions::readSetting($a, 'admin_hash') !== Competitions::readSetting($b, 'admin_hash'), 'パスワードのハッシュは各 DB の中にある');
try {
    Competitions::create('短い', 'outdoor72', 'abc');
    ok(false, 'パスワードが短いと作れない');
} catch (InvalidArgumentException $e) {
    ok(true, 'パスワードが短いと作れない');
}
// 記録会コードが数字だけでも、文字列のコードとして扱う(PHP の配列のキーは整数になってしまうため)
copy("$dataDir/competitions/$a.sqlite", "$dataDir/competitions/12345678.sqlite");
$num = null;
foreach (Competitions::all() as $c) {
    if ($c['code'] === '12345678') {
        $num = $c;
    }
}
ok($num !== null && Competitions::find('12345678')['code'] === '12345678' && Competitions::open('12345678') === '12345678' && Settings::code() === '12345678',
    '数字だけの記録会コード(12345678)も文字列で扱う');
unlink("$dataDir/competitions/12345678.sqlite");
Db::unselect();

// パスワードなしの記録会(管理者パスワードでだけ開ける)
$np = Competitions::create('任せない記録会', 'outdoor36');
ok(!Auth::hasCompetitionPassword($np) && !Auth::verify($np, '') && !Auth::verify($np, 'passA1'), 'パスワードなしで作れる(空のパスワードや他の記録会のパスワードでは開けない)');
Competitions::open($np);
Auth::setPassword('makase1');
ok(Auth::hasCompetitionPassword($np) && Auth::verify($np, 'makase1'), 'あとから記録会ごとのパスワードを付けられる');
Auth::setPassword('');
ok(!Auth::hasCompetitionPassword($np), 'パスワードを空にすると管理者のみに戻る');
Competitions::delete($np);

// 記録会の削除(DB ファイルごと)
$tmpCode = Competitions::create('消す記録会', 'outdoor36', 'keshi1');
Entries::save(['family_name' => '削', 'category_id' => (int)Categories::byCode('RM')['id']]);
Competitions::delete($tmpCode);
ok(!Competitions::exists($tmpCode) && !glob("$dataDir/competitions/$tmpCode.sqlite*") && !Db::hasSelection(), '記録会の削除: DB ファイル(-wal/-shm も)が消え、選択も外れる');

echo "# 管理者パスワード(すべての記録会に共通)\n";
ok(!Auth::adminIsSet() && !Auth::verifyAdmin(''), '最初は管理者パスワードなし');
ok(!Auth::canSetupAdmin() && !Auth::canSetupAdmin('wrong') && !Auth::canSetupAdmin('sample') && Auth::canSetupAdmin('passA1'),
    '最初の設定には、サンプル以外の記録会のパスワードが要る(サンプルの sample では不可)');
Auth::setAdminPassword('kanri1');
ok(Auth::adminIsSet() && Auth::verifyAdmin('kanri1') && !Auth::verifyAdmin('passA1'), '管理者パスワードを設定');
ok(is_file("$dataDir/admin.json") && strpos(file_get_contents("$dataDir/admin.json"), 'kanri1') === false, 'data/admin.json にハッシュで保存(平文は残らない)');
ok(Auth::verify($a, 'kanri1') && Auth::verify($b, 'kanri1') && Auth::verify('5A4D0001', 'kanri1'), '管理者パスワードでどの記録会も開ける');
ok(!Auth::canSetupAdmin('passA1'), '設定済みなら、もう「最初の設定」はできない');
Competitions::open($a);
$rm = (int)Categories::byCode('RM')['id'];
$rw = (int)Categories::byCode('RW')['id'];
$a1 = Entries::save(['family_name' => '甲', 'given_name' => '一', 'affiliation' => 'A大', 'category_id' => $rm]);
$a2 = Entries::save(['family_name' => '乙', 'given_name' => '二', 'affiliation' => 'B大', 'category_id' => $rw]);
$a3 = Entries::save(['family_name' => '丙', 'given_name' => '三', 'affiliation' => 'A大', 'category_id' => $rm]);
Competitions::open($b);
Entries::save(['family_name' => '丁', 'given_name' => '四', 'category_id' => $rm, 'target' => 1, 'letter' => 'A']);
Competitions::open($a);
ok(count(Entries::all()) === 3, '記録会Aの選手は3人(記録会Bの選手は混ざらない)');
Competitions::open($b);
ok(count(Entries::all()) === 1 && Settings::get('name') === '記録会B' && Settings::category() === 2, '記録会Bは1人、屋内30射');
ok(Settings::code() === $b, 'DB の中の記録会コードは記録会のコード');

echo "# 的番号の移動(タップ移動/プルダウン共通の Entries::place)\n";
Competitions::open($a);
$r = Entries::place($a1, 1, 'A');
ok($r['ok'] && Entries::find($a1)['target'] == 1 && Entries::find($a1)['letter'] === 'A', '未割当を 1A に置く');
$r = Entries::place($a2, 1, '');
ok($r['ok'] && Entries::find($a2)['letter'] === 'B', '位置を指定しないと、その的の空いている最初の位置(1B)');
$r = Entries::place($a3, 1, 'A');
ok($r['ok'] && strpos($r['msg'], '入れ替え') !== false && Entries::find($a3)['letter'] === 'A' && Entries::find($a1)['target'] == 0, '選手のいる枠に置くと入れ替え(元が未割当なら未割当へ)');
Entries::place($a1, 2, 'C');
$r = Entries::place($a3, 2, 'C');
ok($r['ok'] && Entries::find($a3)['target'] == 2 && Entries::find($a1)['target'] == 1 && Entries::find($a1)['letter'] === 'A', '配置済み同士は位置を入れ替える');
Entries::applyArrows($a1, 'LLLLLL', 't', null, 'admin', 1);
$r = Entries::place($a1, 3, 'A');
ok(!$r['ok'] && Entries::find($a1)['target'] == 1, '点数のある選手は動かせない');
$r = Entries::place($a2, 1, 'A');
ok(!$r['ok'] && Entries::find($a2)['letter'] === 'B', '点数のある選手のいる枠へは移せない');
$r = Entries::place($a2, 0);
ok($r['ok'] && Entries::find($a2)['target'] == 0, '未割当に戻す');
foreach (['A', 'B', 'C', 'D'] as $l) {
    Entries::save(['family_name' => "満$l", 'category_id' => $rm, 'target' => 9, 'letter' => $l]);
}
$r = Entries::place($a2, 9, '');
ok(!$r['ok'] && strpos($r['msg'], '満員') !== false, '満員の的には置けない');
// 立順の直接入力と立ち数(A〜F)
ok(Settings::parsePosition('001a') === [1, 'A'] && Settings::parsePosition(' 12 b ') === [12, 'B'] && Settings::parsePosition('１Ａ') === [1, 'A']
    && Settings::parsePosition('') === [0, ''] && Settings::parsePosition('3') === [3, ''] && Settings::parsePosition('1G') === null
    && Settings::parsePosition('0A') === null && Settings::parsePosition('A1') === null, '立順の入力: 001a→1A、全角可、頭の0は取る、G以降・0的は不可');
$per0 = Settings::get('archers_per_target');
Settings::set('archers_per_target', 4);
$r = Entries::place($a3, 1, 'E');
ok(!$r['ok'] && strpos($r['msg'], 'A〜D') !== false, '立ち数4なら E には置けない');
Settings::set('archers_per_target', 6);
ok(Settings::letters() === ['A', 'B', 'C', 'D', 'E', 'F'], '立ち数6なら A〜F');
$r = Entries::place($a3, 9, 'F');
ok($r['ok'] && Entries::find($a3)['target'] == 9 && Entries::find($a3)['letter'] === 'F', '立ち数6なら F に置ける');
Entries::place($a3, 0, '');
Settings::set('archers_per_target', $per0);
ok(!Entries::place($a2, 10000, 'A')['ok'] && !Entries::place($a2, 1, 'E')['ok'], '範囲外・不正な位置は拒否');
Entries::save(['family_name' => '乙', 'given_name' => '二', 'category_id' => $rw, 'status' => 1], $a2);
ok(!Entries::place($a2, 5, 'A')['ok'], '欠場の選手は置けない');
$e = Entries::find($a2);
ok($e['target'] == 0, '欠場にしても、登録画面では的番号を変えない(未割当のまま)');
Entries::save(['family_name' => '乙', 'given_name' => '二', 'category_id' => $rw, 'status' => 0], $a2);
Entries::place($a2, 5, 'B');
Entries::save(['family_name' => '乙', 'given_name' => '二', 'category_id' => $rw, 'status' => 1], $a2);
ok(Entries::find($a2)['target'] == 0, '得点のない選手を欠場にすると、的からも外れる');
Entries::save(['family_name' => '甲', 'given_name' => '一', 'affiliation' => 'A大 (変更)', 'category_id' => $rm], $a1);
ok(Entries::find($a1)['target'] == 1 && Entries::find($a1)['letter'] === 'A' && Entries::find($a1)['affiliation'] === 'A大 (変更)', '選手の編集では的番号を変えない');

echo "# 端末(ScorekeeperNG)が指す記録会のDBを読む\n";
Competitions::open($b);
Entries::place(Entries::all()[0]['id'], 1, 'A');
Competitions::open($a);
$r = call([['action' => 'handshake', 'uuid' => 'tab1', 'version' => '1.10.0', 'iskType' => 11, 'tournament' => $a]]);
ok($r['responses'][0]['error'] === 0 && Devices::find('tab1') !== null, '記録会Aに端末を登録');
Competitions::open($b);
ok(Devices::find('tab1') === null, '記録会Bには登録されない(端末は記録会ごとのDBに登録される)');
sleep(1);
$r = call([['action' => 'handshake', 'uuid' => 'tab1', 'version' => '1.10.0', 'iskType' => 11, 'tournament' => strtolower($b)]]);
ok($r['responses'][0]['error'] === 0 && Devices::find('tab1') !== null, '同じ端末が記録会Bにも登録(コードは小文字でもよい)');
ok(Competitions::byDevice('tab1') === $b, '記録会コードが無いリクエストは、最後に通信した記録会(B)へ');
$r = call([['action' => 'comp', 'device' => 'tab1', 'tocode' => $a]]);
ok($r['responses'][0]['toname'] === '記録会A' && $r['responses'][0]['numdist'] === 2, '端末が古い記録会(A)のコードで来たら、Aの内容を返す');
$r = call([['action' => 'comp', 'device' => 'tab1', 'tocode' => $b]]);
ok($r['responses'][0]['toname'] === '記録会B' && $r['responses'][0]['numdist'] === 1, 'Bのコードで来たらBの内容');
sleep(1);   // 最終通信は秒単位なので、次にAと通信した時刻がBより後になるようにする
$r = call([['action' => 'manual', 'device' => 'tab1', 'tocode' => $a, 'type' => 'Q', 'sesstarget' => '1001']]);
$cfg = $r['responses'][0];
ok($cfg['action'] === 'reconfigure' && $cfg['toCode'] === $a && $cfg['archers'][0]['name'] === '甲 一' && count($cfg['archers'][0]['scoring']) === 2, '古い記録会A: 的1の選手と2距離を返す');
$r = call([['action' => 'update', 'device' => 'tab1', 'tocode' => $a, 'type' => 'Q', 'session' => 1, 'distance' => 2, 'refKey' => '1.1B', 'arrowstring' => 'JJJJJJ']]);
$r2 = call([['action' => 'update', 'device' => 'tab1', 'tocode' => $a, 'type' => 'Q', 'session' => 1, 'distance' => 1, 'refKey' => '1.1A', 'arrowstring' => 'KKKKKK']]);
Competitions::open($a);
$e1 = Entries::find($a1);
ok(rtrim($e1['arrowstring']) === 'KKKKKK', '端末の更新は、tocode の記録会(A)のDBに書かれた');
Competitions::open($b);
ok(Entries::all()[0]['arrowstring'] === '', '記録会Bの点数は変わらない');
$r = call([['action' => 'info', 'device' => 'tab1', 'data' => ['battery' => ['charging' => false, 'charge' => 0.42]]]]);
Competitions::open($a);
ok(Devices::find('tab1')['battery'] == 42, 'コード無しの info は、その端末が最後に通信した記録会(いまは A)に記録');
$r = call([['action' => 'handshake', 'uuid' => 'tab2', 'version' => '1.10.0', 'iskType' => 11, 'tournament' => 'FFFFFFFF']]);
ok($r['error'] === true && $r['responses'][0]['error'] === 1, '存在しない記録会コードは登録できない');
$r = call([['action' => 'comp', 'device' => 'tab1', 'tocode' => 'FFFFFFFF']]);
ok($r['responses'][0]['errorcode'] === 1, '存在しない記録会コードの comp は errorcode 1');
$r = call([['action' => 'info', 'device' => 'nobody', 'data' => ['battery' => ['charging' => false, 'charge' => 1]]]]);
ok($r['responses'][0]['action'] === 'handshake' && $r['responses'][0]['error'] === 2, 'どの記録会にも登録されていない端末は handshake error 2');
$r = call([['action' => 'ping', 'device' => 'tab1']]);
ok($r['responses'][0] === 'pong', 'ping は記録会を選ばなくても返る');
$r = call([
    ['action' => 'comp', 'device' => 'tab1', 'tocode' => $a],
    ['action' => 'comp', 'device' => 'tab1', 'tocode' => $b],
]);
ok($r['responses'][0]['toname'] === '記録会A' && $r['responses'][1]['toname'] === '記録会B', '1回の通信で別々の記録会を指しても、それぞれの記録会を返す');

echo "# 通信ログは記録会ごと\n";
Competitions::open($a);
Settings::set('api_log', '1');
call([['action' => 'comp', 'device' => 'tab1', 'tocode' => $a]]);
call([['action' => 'comp', 'device' => 'tab1', 'tocode' => $b]]);
$fa = ApiLog::file($a);
$fb = ApiLog::file($b);
ok(is_file($fa) && !is_file($fb), 'ログをオンにした記録会Aにだけ記録される');
Competitions::open($a);
ok(count(ApiLog::recent()) === 1, '記録会Aのログ画面には記録会Aの分だけ');
ApiLog::clear();
ok(!is_file($fa), '記録会Aのログを消せる');

echo "# 過去の記録会から選手を追加(このサーバーの、ほかの記録会の名簿)\n";
Competitions::open($a);
$affs = Past::affiliations();
ok(in_array('A大 (変更)', $affs, true) && in_array('みずうみ大学', $affs, true), '所属の候補: いまの記録会とほかの記録会(サンプル)から');
$teams = array_column(Past::teams(), 'count', 'name');
ok(($teams['みずうみ大学'] ?? 0) === 2 && !isset($teams['A大 (変更)']), '過去の記録会の所属と人数(いまの記録会は含めない): みずうみ大学 2人');
$list = Past::archers('みずうみ大学');
ok(count($list) === 2 && $list[0]['family'] === '佐藤' && $list[1]['family'] === '加藤' && $list[0]['category_code'] === 'RM' && strpos($list[0]['src'], '5A4D0001:') === 0, 'その所属の選手(姓名順、どの記録会のどの選手か)');
Entries::save(['family_name' => '佐藤', 'given_name' => '健', 'category_id' => $rm]);
$list = Past::archers('みずうみ大学');
ok($list[0]['registered'] === true && $list[1]['registered'] === false, 'いまの記録会にいる選手は「登録済み」');
$n0 = count(Entries::all());
$r = Past::add([$list[0]['src'], $list[1]['src'], 'FFFFFFFF:1', 'bad']);
ok($r['added'] === 1 && $r['skipped'] === 3 && count(Entries::all()) === $n0 + 1, '過去の記録会の選手を追加(登録済み・不正は飛ばす)');
$kob = array_values(array_filter(Entries::all(), function ($e) { return $e['family_name'] === '加藤'; }))[0];
ok($kob['affiliation'] === 'みずうみ大学' && $kob['category_code'] === 'RW' && (int)$kob['target'] === 0, '姓名・所属・部門が入り、的は未割当');
ok(Past::find($a . ':' . $a1) === null, 'いまの記録会の選手は「過去」として読まない');

echo "# サンプル記録会の削除\n";
Competitions::delete('5A4D0002');
Competitions::bootstrap(true);
ok(!Competitions::exists('5A4D0002') && is_file("$dataDir/competitions/.5A4D0002.deleted") && Competitions::exists('5A4D0001'),
    'サンプルを削除すると自動で復活せず、もう一方のサンプルは残る');

foreach ([$dataDir] as $d) {
    rrmdir($d);
}
echo $fail ? "\nFAILED(複数記録会): $fail\n" : "\nAll OK(複数記録会)\n";
exit($fail ? 1 : 0);
