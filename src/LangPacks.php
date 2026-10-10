<?php
namespace Lite;

/**
 * ScorekeeperNG(端末アプリ)の言語ファイル(ISK-App)。
 *
 * 同梱: assets/lang/<言語>/ISK-App.php(ianseo 2023-04-01 版の英語と、独自の日本語訳。GPL v3)
 * 取得: 全体管理者が「本家の翻訳を取得」したときだけ、本家 ianseo の翻訳サイトから言語パックを取り、
 *       data/lang/<言語>.json に保存する(Git・配布物には入らない)。取得したものがあれば、そちらを優先する。
 *
 * 言語パックの形は本家の Update/UpdateIanseo.php と同じ: gzinflate → unserialize で
 *   ['langs' => [言語 => ['flag-png' => PNG, 'testuale' => 言語名, 'lang' => [モジュール => PHP の本文]]]]
 * 外から来たものなので、PHP として実行はしない。unserialize はオブジェクトを作らせず、
 * ISK-App の本文は「$lang['キー']='文言';」の行を文字として読み取るだけにする。
 */
class LangPacks
{
    const URL = 'https://translations.ianseo.net/getpackages.php';
    const MAX_BYTES = 30000000;   // 受け取る大きさの上限(圧縮後)
    const MAX_FLAG = 200000;      // 国旗の画像の上限

    /** テスト用: URL を受け取って中身を返す関数(設定すると、ネットワークに出ずにこれを使う) */
    public static $downloader = null;

    /** 同梱の言語 [言語 => ['name', 'flag'(PNG のバイト列), 'file']] */
    private static function bundled()
    {
        $out = [];
        foreach (glob(LITE_ROOT . '/assets/lang/*', GLOB_ONLYDIR) as $dir) {
            $id = basename($dir);
            if (!self::validId($id) || !is_file("$dir/ISK-App.php")) {
                continue;
            }
            $out[$id] = [
                'name' => is_file("$dir/$id.txt") ? trim(file_get_contents("$dir/$id.txt")) : strtoupper($id),
                'flag' => is_file("$dir/$id.png") ? file_get_contents("$dir/$id.png") : '',
                'file' => "$dir/ISK-App.php",
            ];
        }
        return $out;
    }

    private static function dir()
    {
        return Db::dataDir() . '/lang';
    }

    private static function validId($id)
    {
        return (bool)preg_match('/^[a-z][a-z0-9_-]{0,15}$/i', (string)$id);
    }

    /** 取得した言語 [言語 => 保存した内容(name, flag(base64), translations, fetched_at)] */
    public static function fetched()
    {
        $out = [];
        foreach (glob(self::dir() . '/*.json') ?: [] as $f) {
            $id = basename($f, '.json');
            $j = json_decode((string)file_get_contents($f), true);
            if (self::validId($id) && is_array($j) && !empty($j['translations']) && is_array($j['translations'])) {
                $out[$id] = $j;
            }
        }
        ksort($out);
        return $out;
    }

    /** 端末に出す言語の一覧 [['id', 'name', 'md5', 'flagBase64'], ...](取得したものを優先) */
    public static function languageList()
    {
        $list = [];
        $fetched = self::fetched();
        foreach (self::bundled() as $id => $b) {
            if (!isset($fetched[$id])) {
                $list[$id] = ['id' => $id, 'name' => $b['name'], 'md5' => md5_file($b['file']),
                    'flagBase64' => $b['flag'] !== '' ? 'data:image/png;base64,' . base64_encode($b['flag']) : ''];
            }
        }
        foreach ($fetched as $id => $j) {
            $list[$id] = ['id' => $id, 'name' => (string)$j['name'], 'md5' => md5(json_encode($j['translations'])),
                'flagBase64' => $j['flag'] !== '' ? 'data:image/png;base64,' . $j['flag'] : ''];
        }
        ksort($list);
        return array_values($list);
    }

    /** その言語の文言。無ければ null。['name', 'md5', 'translations'(LangUpdatedEpoch を含む)] */
    public static function load($id)
    {
        if (!self::validId($id)) {
            return null;
        }
        $fetched = self::fetched();
        if (isset($fetched[$id])) {
            $j = $fetched[$id];
            return ['name' => (string)$j['name'], 'md5' => md5(json_encode($j['translations'])),
                'translations' => ['LangUpdatedEpoch' => (int)($j['fetched_at'] ?? 0)] + $j['translations']];
        }
        $bundled = self::bundled();
        if (!isset($bundled[$id])) {
            return null;
        }
        $b = $bundled[$id];
        $lang = ['LangUpdatedEpoch' => filemtime($b['file'])];
        include $b['file'];   // 同梱のファイル(ianseo-lite に入っているもの)なので読み込んでよい
        return ['name' => $b['name'], 'md5' => md5_file($b['file']), 'translations' => $lang];
    }

    /**
     * 本家の翻訳サイトから言語パックを取得して保存する。
     * @param string[] $ids 取得する言語(空なら同梱の言語)
     * @return string[] 保存した言語
     */
    public static function fetch(array $ids = [])
    {
        $ids = array_values(array_filter($ids ?: array_keys(self::bundled()), [self::class, 'validId']));
        if (!$ids) {
            throw new \InvalidArgumentException('取得する言語がありません');
        }
        $query = implode('&', array_map(function ($id) {
            return 'l[]=' . rawurlencode($id);
        }, $ids));
        $raw = self::download(self::URL . '?' . $query);
        $data = @gzinflate($raw);
        if ($data === false) {
            throw new \RuntimeException('言語パックを展開できませんでした');
        }
        $all = @unserialize($data, ['allowed_classes' => false]);
        if (!is_array($all) || !isset($all['langs']) || !is_array($all['langs'])) {
            throw new \RuntimeException('言語パックの形式が想定と違います');
        }
        $saved = [];
        foreach ($all['langs'] as $id => $files) {
            if (!self::validId($id) || !in_array($id, $ids, true) || !is_array($files)) {
                continue;
            }
            $body = $files['lang']['ISK-App'] ?? null;
            if (!is_string($body)) {
                continue;   // その言語に端末アプリ用の訳が無い
            }
            $translations = self::parse($body);
            if (count($translations) < 10) {
                continue;
            }
            $flag = (string)($files['flag-png'] ?? '');
            if (strlen($flag) > self::MAX_FLAG || strncmp($flag, "\x89PNG\r\n\x1a\n", 8) !== 0) {
                $flag = '';
            }
            $name = trim(preg_replace('/[\x00-\x1f]/', '', (string)($files['testuale'] ?? '')));
            self::save($id, [
                'name' => mb_substr($name !== '' ? $name : strtoupper($id), 0, 60),
                'flag' => $flag !== '' ? base64_encode($flag) : '',
                'translations' => $translations,
                'fetched_at' => time(),
                'source' => self::URL,
            ]);
            $saved[] = $id;
        }
        if (!$saved) {
            throw new \RuntimeException('端末アプリ用の訳(ISK-App)が見つかりませんでした');
        }
        return $saved;
    }

    /** 取得したものを消して、同梱の言語ファイルに戻す */
    public static function clear()
    {
        foreach (glob(self::dir() . '/*.json') ?: [] as $f) {
            @unlink($f);
        }
    }

    /**
     * ISK-App の PHP の本文から「$lang['キー']='文言';」だけを読み取る(実行はしない)。
     * 文言の中の \' と \\ は元に戻す。キーは英数字と _ - だけ。
     */
    public static function parse($body)
    {
        $out = [];
        if (preg_match_all('/\$lang\[\'([A-Za-z0-9_-]{1,80})\'\]\s*=\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*;/s', (string)$body, $m, PREG_SET_ORDER)) {
            foreach ($m as $r) {
                if (strlen($r[2]) <= 5000) {
                    $out[$r[1]] = strtr($r[2], ["\\'" => "'", '\\\\' => '\\']);
                }
            }
        }
        return $out;
    }

    private static function save($id, array $j)
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $f = "$dir/$id.json";
        if (@file_put_contents("$f.tmp", json_encode($j, JSON_UNESCAPED_UNICODE)) === false || !@rename("$f.tmp", $f)) {
            throw new \RuntimeException('言語ファイルを保存できませんでした(data/ の書き込み権限を確認してください)');
        }
    }

    private static function download($url)
    {
        if (self::$downloader) {
            return (string)call_user_func(self::$downloader, $url);
        }
        $ctx = stream_context_create([
            'http' => ['timeout' => 30, 'ignore_errors' => false, 'header' => "User-Agent: ianseo-lite/" . LITE_VERSION . "\r\n"],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $raw = @file_get_contents($url, false, $ctx, 0, self::MAX_BYTES + 1);
        if ($raw === false || $raw === '') {
            throw new \RuntimeException('本家の翻訳サイトにつながりませんでした');
        }
        if (strlen($raw) > self::MAX_BYTES) {
            throw new \RuntimeException('言語パックが大きすぎます');
        }
        return $raw;
    }
}
