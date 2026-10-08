<?php
namespace Lite;

/**
 * ScorekeeperNG(ISK-NG "Lite" モード)互換のサーバー側 API。
 *
 * 本家 ianseo の Api/ISK-NG/ のうち、Lite モードの予選(Q)に必要な部分だけを SQLite 上に再実装したもの。
 * 端末は 1 リクエストに複数のコマンド(requests)を詰めて POST してくる。
 *
 *   handshake -> comp -> manual(的番号を選ぶ/QR を読む) -> reconfigure(選手一覧)
 *   update / sendall(得点の送信) / confirmhash / info / chargestatus / gps / lang / ping ...
 */
class IskApi
{
    const MAX_API_VERSION = 1;
    const ISK_NG_LITE_CODE = 11;
    const SESSION = 1;

    /** 受け付けるアクション */
    const ACTIONS = ['handshake', 'comp', 'manual', 'update', 'sendall', 'confirmhash', 'getconfig',
        'info', 'chargestatus', 'gps', 'lang', 'ping', 'gettargetimages', 'qrpayload', 'event', 'match'];

    private $json;

    /** HTTP エントリポイント */
    public static function serve()
    {
        // PHP の警告などが JSON の前後に混ざると端末が読めなくなるので、出力は溜めて捨てる
        ob_start();
        @ini_set('display_errors', '0');

        $raw = file_get_contents('php://input');
        $data = json_decode($raw);
        $json = ['error' => true];
        if (is_object($data) && isset($data->requests)) {
            $data = $data->requests;
        } elseif ($data === null) {
            if (!(isset($_REQUEST['requests']) && ($data = json_decode($_REQUEST['requests'])) !== null)) {
                $json['errorMsg'] = 'Missing REQUESTS payload';
                self::out($json);
            }
        } elseif (is_array($data)) {
            // 配列そのままの場合は許容
        } elseif (!is_object($data)) {
            $json['errorMsg'] = 'Missing PAYLOAD and REQUESTS';
            self::out($json);
        }
        $res = (new self())->process($data);
        ApiLog::write($data, $res);
        self::out($res);
    }

    public static function out(array $json)
    {
        $body = json_encode($json, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, AccountKey, x-requested-with, origin, authorization, accept');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Length: ' . strlen($body));
        echo $body;
        exit;
    }

    /**
     * @param object|array $data 1 件の request か、その配列
     * @return array レスポンス全体 ['error'=>bool, 'responses'=>[...]]
     */
    public function process($data)
    {
        $this->json = ['error' => false, 'responses' => []];
        try {
            foreach (is_array($data) ? $data : [$data] as $req) {
                $this->one($req);
            }
        } catch (\Throwable $e) {
            error_log('ianseo-lite: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
            $this->json['error'] = true;
            $this->json['errorMsg'] = 'Throwable error!';
        }
        return $this->json;
    }

    private function fail($msg)
    {
        $this->json['error'] = true;
        $this->json['errorMsg'] = $msg;
        return null;
    }

    private function one($req)
    {
        if (!is_object($req)) {
            return;
        }
        if (!empty($req->extra) && is_string($req->extra)) {
            $req->extra = json_decode($req->extra);
        }
        // 大会コードに "|PIN" が付いていれば PIN を検証して取り除く
        foreach (['tournament', 'tocode'] as $f) {
            if (!empty($req->$f) && strpos($req->$f, '|') !== false) {
                list($req->$f, $pin) = explode('|', $req->$f, 2);
                if ((string)Settings::get('pin') === '' || $pin !== Settings::get('pin')) {
                    $this->fail('Validation failed');
                    return;
                }
                $req->_pinChecked = true;
            }
        }

        if (!((isset($req->device) || isset($req->uuid)) && isset($req->action) && isset($req->apiVersion))) {
            $this->fail('Incorrect transaction format: ' . ($req->action ?? ''));
            return;
        }

        $res = null;
        if ($req->apiVersion > self::MAX_API_VERSION) {
            $this->json['errorMsg'] = 'Incorrect API version';
        } elseif (!in_array($req->action, self::ACTIONS, true)) {
            $this->fail('Incorrect action or API version: ' . $req->action . ', ' . $req->apiVersion);
        } else {
            $res = $this->{'do' . ucfirst($req->action)}($req);
            if ($dev = ($req->device ?? $req->uuid ?? '')) {
                Devices::touch($dev);
            }
        }

        $this->json['error'] = $this->json['error'] || (is_array($res) && isset($res['error']) && $res['error'] == 1);
        if ($res !== null) {
            $this->json['responses'][] = $res;
        } elseif ($this->json['error']) {
            $this->json['responses'][] = [
                'action' => 'reset',
                'device' => $req->device ?? $req->uuid ?? '',
                'resetMessage' => $this->json['errorMsg'] ?? '', 'resetSubMsg' => '', 'resetTarget' => '',
            ];
        }
    }

    // ------------------------------------------------------------------ 共通

    private function unknownDevice($req)
    {
        return ['action' => 'handshake', 'error' => 2, 'device' => $req->device ?? ''];
    }

    private function device($req)
    {
        return isset($req->device) ? Devices::find($req->device) : null;
    }

    private function noop($req)
    {
        return ['action' => 'noop', 'device' => $req->device];
    }

    private function missing($req)
    {
        return $this->fail('Missing parameters for ' . $req->action);
    }

    /** 端末に「使われていない」ことを知らせて設定を消させる */
    public static function resetDevice($dev, $target = 0)
    {
        $r = ['action' => 'reset', 'device' => $dev, 'resetMessage' => 'Device not in use',
            'resetSubMsg' => '', 'resetTarget' => ''];
        if (empty($target)) {
            $r['resetSubMsg'] = 'Return to Results Crew';
        } else {
            $r['resetTarget'] = $target;
        }
        return $r;
    }

    // ------------------------------------------------------------------ アクション

    private function doPing($req)
    {
        return 'pong';
    }

    private function doHandshake($req)
    {
        $type = (int)($req->iskType ?? 0);
        if (empty($req->uuid) || empty($req->version) || !$type) {
            return $this->missing($req);
        }
        $res = ['action' => 'handshake', 'device' => $req->uuid, 'devCode' => '', 'error' => 1,
            'socketid' => '', 'key' => '', 'ianseoVersion' => '20260101'];

        // このサーバーは Lite モード(予選のみ)専用
        if ($type !== self::ISK_NG_LITE_CODE) {
            return $res;
        }
        $code = trim($req->tournament ?? '');
        if ($code === '' || strcasecmp($code, Settings::get('code')) !== 0) {
            return $res;
        }
        // PIN を設定している大会は、端末の登録時に PIN を必須にする
        if ((string)Settings::get('pin') !== '' && !$this->pinGiven($req)) {
            return $res;
        }
        $extra = (empty($req->extra)) ? '' : json_encode($req->extra);
        list($dev, $isNew) = Devices::register($req->uuid, $req->version, $type, $extra);
        $res['devCode'] = $dev['code'];
        $res['error'] = 0;
        $res['newdevice'] = $isNew;
        return $res;
    }

    /** one() が PIN を検証済みか */
    private function pinGiven($req)
    {
        return !empty($req->_pinChecked);
    }

    private function doComp($req)
    {
        if (empty($req->tocode)) {
            return $this->missing($req);
        }
        if (!$this->device($req)) {
            return $this->unknownDevice($req);
        }
        if (strcasecmp($req->tocode, Settings::get('code')) !== 0) {
            return ['action' => $req->action, 'device' => $req->device, 'tocode' => $req->tocode, 'errorcode' => 1];
        }
        $targets = [];
        foreach (Entries::targets() as $t) {
            $targets[] = ['id' => str_pad($t, 4, '0', STR_PAD_LEFT), 'name' => (string)$t];
        }
        return [
            'action' => $req->action,
            'device' => $req->device,
            'tocode' => Settings::get('code'),
            'toname' => Settings::get('name') ?: Settings::get('code'),
            'tocategory' => (string)Settings::category(),
            'id' => '',
            'numdist' => Settings::distances(),
            'numsession' => 1,
            'sessions' => [[
                'sessid' => self::SESSION,
                'sessdesc' => Settings::sessionName(),
                'sesstype' => 'Q',
                'sesstargets' => $targets,
            ]],
            'stages' => ['Q'],
        ];
    }

    private function doManual($req)
    {
        if (empty($req->tocode) || (empty($req->sesstarget) && empty($req->matchid))) {
            return $this->missing($req);
        }
        $dev = $this->device($req);
        if (!$dev) {
            return $this->unknownDevice($req);
        }
        if (strcasecmp($req->tocode, Settings::get('code')) !== 0) {
            return $this->fail('Competition missing');
        }
        if (($req->type ?? '') !== 'Q') {
            return $this->unknownDevice($req); // 予選以外は未対応
        }
        $sesTarget = (string)$req->sesstarget;
        $session = (int)substr($sesTarget, 0, -4);
        $target = (int)substr($sesTarget, -4);
        if ($session !== self::SESSION) {
            return self::resetDevice($req->device, $target);
        }
        Devices::update($req->device, ['target' => $target, 'setup' => '', 'setup_confirmed' => 0]);
        return $this->buildSetup($req->device, $target);
    }

    private function doGetconfig($req)
    {
        $dev = $this->device($req);
        if (!$dev) {
            return $this->unknownDevice($req);
        }
        if ($dev['setup'] !== '' && $dev['target']) {
            return $this->cachedSetup($dev);
        }
        return $this->noop($req);
    }

    private function doConfirmhash($req)
    {
        if (empty($req->device) || empty($req->configHash)) {
            return $this->missing($req);
        }
        $dev = $this->device($req);
        if (!$dev) {
            return $this->unknownDevice($req);
        }
        if ($dev['setup'] !== '' && md5($dev['setup']) === $req->configHash) {
            Devices::update($req->device, ['setup_confirmed' => 1]);
            return $this->noop($req);
        }
        if ($dev['target']) {
            return $this->buildSetup($req->device, (int)$dev['target']);
        }
        return self::resetDevice($req->device, 0);
    }

    private function doInfo($req)
    {
        if (!isset($req->data)) {
            return $this->missing($req);
        }
        if (!$this->device($req)) {
            return $this->unknownDevice($req);
        }
        if (!empty($req->data->battery)) {
            $b = $req->data->battery;
            Devices::update($req->device, ['battery' => (($b->charging ?? false) ? -1 : 1) * (int)round(($b->charge ?? 0) * 100)]);
        }
        return $this->noop($req);
    }

    private function doChargestatus($req)
    {
        if (empty($req->device)) {
            return $this->missing($req);
        }
        if (!$this->device($req)) {
            return $this->unknownDevice($req);
        }
        Devices::update($req->device, ['battery' => (($req->charging ?? false) ? -1 : 1) * (int)($req->charge ?? 0)]);
        return $this->noop($req);
    }

    private function doGps($req)
    {
        if (empty($req->device) || empty($req->latitude) || empty($req->longitude)) {
            return $this->missing($req);
        }
        if (!$this->device($req)) {
            return $this->unknownDevice($req);
        }
        Devices::update($req->device, ['gps' => json_encode([
            'lat' => $req->latitude, 'lon' => $req->longitude, 'alt' => $req->altitude ?? null,
            'spd' => $req->speed ?? null, 'hdg' => $req->heading ?? null, 'acc' => $req->accuracy ?? null,
            'ts' => $req->timestamp ?? null])]);
        return $this->noop($req);
    }

    private function doQrpayload($req)
    {
        // Pro/Live の「的の切り替え QR」用。Lite では使わない
        return $this->noop($req);
    }

    private function doGettargetimages($req)
    {
        $dev = $this->device($req);
        if (!$dev) {
            return $this->unknownDevice($req);
        }
        $images = [];
        foreach (($req->imgCodes ?? []) as $code) {
            $code = (int)$code;
            if (!TargetFaces::exists($code)) {
                continue;
            }
            $images[$code] = [
                'imgName' => $code . '.svg',
                'imgGzip' => TargetFaces::imageBase64($code),
                'imgBase64Prefix' => 'data:image/svg+xml;base64,',
            ];
        }
        return ['action' => 'targetimages', 'device' => $req->device, 'images' => array_values($images)];
    }

    private function doEvent($req)
    {
        if (!$this->device($req)) {
            return $this->unknownDevice($req);
        }
        return ['action' => $req->action, 'device' => $req->device, 'tocode' => $req->tocode ?? '', 'events' => []];
    }

    private function doMatch($req)
    {
        if (!$this->device($req)) {
            return $this->unknownDevice($req);
        }
        return ['action' => $req->action, 'device' => $req->device, 'tocode' => $req->tocode ?? '', 'matches' => []];
    }

    private function doLang($req)
    {
        if (!$this->device($req)) {
            return $this->unknownDevice($req);
        }
        $base = LITE_ROOT . '/assets/lang/';
        $names = [];
        foreach (glob($base . '*', GLOB_ONLYDIR) as $dir) {
            $id = basename($dir);
            if (!is_file($dir . '/ISK-App.php')) {
                continue;
            }
            $names[$id] = is_file("$dir/$id.txt") ? trim(file_get_contents("$dir/$id.txt")) : strtoupper($id);
        }
        if (empty($req->lang)) {
            $list = [];
            foreach ($names as $id => $name) {
                $flag = is_file($f = "$base$id/$id.png") ? 'data:image/png;base64,' . base64_encode(file_get_contents($f)) : '';
                $list[] = ['id' => $id, 'name' => $name, 'md5' => md5_file("$base$id/ISK-App.php"), 'flagBase64' => $flag];
            }
            return ['action' => $req->action, 'device' => $req->device, 'languageList' => $list];
        }
        if (!preg_match('/^[a-z_0-9-]+$/i', $req->lang) || !isset($names[$req->lang])) {
            return $this->fail('Unknown language');
        }
        $file = $base . $req->lang . '/ISK-App.php';
        $lang = ['LangUpdatedEpoch' => filemtime($file)];
        include $file;  // $lang[] を埋める
        return [
            'action' => $req->action, 'device' => $req->device, 'language' => $req->lang,
            'languageName' => $names[$req->lang], 'md5' => md5_file($file), 'translations' => $lang,
        ];
    }

    // ------------------------------------------------------------------ 得点の受信

    private function doUpdate($req)
    {
        list($dev, $early) = $this->scoringDevice($req);
        if ($early) {
            return $early;
        }
        $msg = $this->readData($req, $dev);
        switch ($msg) {
            case 'OK':
                return $this->noop($req);
            case 'RECONFIGURE':
            case 'RESET':
                return self::resetDevice($req->device, $dev['target']);
            default:
                return $this->fail($msg);
        }
    }

    private function doSendall($req)
    {
        list($dev, $early) = $this->scoringDevice($req);
        if ($early) {
            return $early;
        }
        // 全件送信は常に sendall で返す(個別の失敗は無視)
        $this->readData($req, $dev);
        return ['action' => 'sendall', 'device' => $req->device];
    }

    /** @return array [device row, 早期レスポンス|null] */
    private function scoringDevice($req)
    {
        if (empty($req->tocode)) {
            return [null, $this->missing($req)];
        }
        $dev = $this->device($req);
        if (!$dev) {
            return [null, $this->unknownDevice($req)];
        }
        if (!$dev['target'] || strcasecmp($req->tocode, Settings::get('code')) !== 0) {
            return [null, self::resetDevice($dev['uuid'], $dev['target'])];
        }
        return [$dev, null];
    }

    /** @return string 'OK' / 'RECONFIGURE' / エラーメッセージ */
    private function readData($req, array $dev)
    {
        if (($req->type ?? '') !== 'Q') {
            return 'NOT IMPLEMENTED YET';
        }
        // まとめ送信(sendall)
        if (!empty($req->archers)) {
            if ((int)($req->session ?? self::SESSION) !== self::SESSION) {
                return 'RECONFIGURE';
            }
            $allFailed = true;
            $last = '';
            foreach ($req->archers as $archer) {
                foreach (($archer->scoring ?? []) as $d) {
                    $r = $this->applyOne($dev, $archer->refKey ?? '', (int)($d->distance ?? 0),
                        (string)($d->arrowstring ?? ''), $d->signed ?? null);
                    if ($r === 'OK') {
                        $allFailed = false;
                    } else {
                        $last = $r;
                    }
                }
            }
            return $allFailed ? ($last ?: 'OK') : 'OK';
        }
        if ((int)($req->session ?? self::SESSION) !== self::SESSION) {
            return 'RECONFIGURE';
        }
        return $this->applyOne($dev, $req->refKey ?? '', (int)($req->distance ?? 0),
            (string)($req->arrowstring ?? ''), $req->signed ?? null);
    }

    private function applyOne(array $dev, $refKey, $distance, $arrowstring, $signed)
    {
        if ($distance < 1 || $distance > Settings::distances()) {
            return 'Incorrect Distance Setting';
        }
        if (!preg_match('/^(\d+)\.(\d+)([A-Da-d])$/', (string)$refKey, $m) || (int)$m[1] !== self::SESSION) {
            return 'RECONFIGURE';
        }
        $entry = Entries::findByPos((int)$m[2], $m[3]);
        // 端末は自分が割り当てられた的の選手にしか書けない
        if (!$entry || $entry['status'] || (int)$entry['target'] !== (int)$dev['target']) {
            return 'RECONFIGURE';
        }
        if ($entry['locked']) {
            return 'This scorecard has already been validated';
        }
        if (!Scoring::isValid($arrowstring)) {
            return 'Invalid arrow data';
        }
        Entries::applyArrows($entry['id'], $arrowstring, $dev['uuid'],
            $signed === null ? null : (bool)(int)$signed, 'device', $distance);
        return 'OK';
    }

    // ------------------------------------------------------------------ 端末への設定(reconfigure)

    /** 端末ごとの設定を作って保存し、端末に返す形で返す */
    public function buildSetup($uuid, $target)
    {
        $dev = Devices::find($uuid);
        $archers = [];
        $faces = [];
        $per = Settings::arrowsPerEnd();
        $ends = Settings::ends();
        $face = (string)Settings::targetFace();
        $totalTargets = max(1, count(Entries::targets()));
        $golds = Settings::get('gold_label');
        $xl = Settings::get('x_label');

        foreach (Entries::onTarget($target) as $e) {
            $key = self::SESSION . '.' . $e['target'] . $e['letter'];
            $faces[$face] = true;
            $scoring = [];
            for ($d = 1; $d <= Settings::distances(); $d++) {
                $scoring[] = [
                    'distance' => (string)$d,
                    'distanceName' => Settings::distanceName($d),
                    'ends' => (string)$ends,
                    'arrows' => (string)$per,
                    'shootOff' => 0,
                    'soEnds' => 1,
                    'soClosest' => '0',
                    'gold' => Scoring::GOLD_CHARS,
                    'xnine' => Scoring::X_CHARS,
                    'targetface' => $face,
                    'arrowstring' => '',
                    'previous' => '0',
                    'previousGold' => '0',
                    'previousXnine' => '0',
                    'endStartNumber' => 1,
                    'scoringEnds' => $ends,
                    'totalTargets' => $totalTargets,
                ];
            }
            $archers[$key] = [
                'refKey' => $key,
                'encode' => $e['bib'],
                'name' => Entries::fullName($e),
                'placement' => (string)$e['target'],
                'noc' => mb_substr($e['affiliation'], 0, 10),
                'nocname' => $e['affiliation'],
                'event' => $e['category_code'] ?: (string)$e['category_name'],
                'eventname' => (string)$e['category_name'],
                'matchMode' => 0,
                'goldLbl' => $golds,
                'xnineLbl' => $xl,
                'qrCode' => $e['bib'],
                'scoring' => $scoring,
            ];
        }
        if (!$archers) {
            return self::resetDevice($uuid, $target);
        }

        $withImages = self::needsImages($dev['version']);
        $targetFaces = [];
        foreach (array_keys($faces) as $f) {
            $targetFaces[] = TargetFaces::iskFace((int)$f, $withImages);
        }
        $setup = [
            'action' => 'reconfigure',
            'msgIdentifier' => time(),
            'device' => $uuid,
            'toCode' => Settings::get('code'),
            'toName' => Settings::get('name') ?: Settings::get('code'),
            'devCode' => $dev['code'],
            'targetAssigned' => (string)$target,
            'type' => 'Q',
            'ctype' => Settings::isIndoor() ? 'I' : 'O',
            'schedule' => 'Q' . Settings::distances() . self::SESSION,
            'session' => (string)self::SESSION,
            'sessionName' => Settings::sessionName(),
            'archers' => $archers,
            'targetFaces' => $targetFaces,
        ];
        Devices::update($uuid, ['setup' => json_encode($setup), 'setup_confirmed' => 0, 'target' => $target]);
        return $this->cachedSetup(Devices::find($uuid));
    }

    /** 保存済みの設定に、最新の arrowstring を差し込んで返す */
    private function cachedSetup(array $dev)
    {
        $setup = json_decode($dev['setup'], true);
        if (!$setup) {
            return self::resetDevice($dev['uuid'], $dev['target']);
        }
        $setup['configHash'] = md5($dev['setup']);
        foreach ($setup['archers'] as $key => &$a) {
            if (!preg_match('/^\d+\.(\d+)([A-D])$/', $key, $m)) {
                continue;
            }
            $e = Entries::findByPos((int)$m[1], $m[2]);
            // 距離ごとの arrowstring と、それより前の距離の合計(previous)を最新にする
            $prev = [0, 0, 0];
            foreach ($a['scoring'] as &$s) {
                $d = (int)$s['distance'];
                $arrows = $e ? Entries::arrows($e, $d) : Scoring::pad('', Settings::arrowsPerDistance());
                $s['arrowstring'] = $arrows;
                // 本家は DB の値をそのまま出すので文字列
                $s['previous'] = (string)$prev[0];
                $s['previousGold'] = (string)$prev[1];
                $s['previousXnine'] = (string)$prev[2];
                $t = Scoring::total($arrows);
                $prev = [$prev[0] + $t[0], $prev[1] + $t[1], $prev[2] + $t[2]];
            }
            unset($s);
        }
        unset($a);
        $setup['archers'] = array_values($setup['archers']);
        return $setup;
    }

    /** バージョン 1.3 未満のアプリは的面画像を設定に含めて送る必要がある */
    private static function needsImages($version)
    {
        $p = explode('.', (string)$version, 3);
        return !((int)($p[0] ?? 0) > 1 || (int)($p[1] ?? 0) >= 3);
    }
}
