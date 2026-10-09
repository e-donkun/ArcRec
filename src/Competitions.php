<?php
namespace Lite;

use PDO;

/**
 * 大会の一覧と切り替え。大会 1 つ = SQLite ファイル 1 つ(data/competitions/<大会コード>.sqlite)。
 * 管理パスワードも、その大会の DB の中(settings.admin_hash)にある。
 */
class Competitions
{
    /** 同梱のサンプル大会(samples/ からコピーして使う) */
    const SAMPLE_CODE = '5A4D0001';
    const SAMPLE_PASSWORD = 'sample';

    private static $booted = false;

    // ------------------------------------------------------------ 一覧・検索

    /** @return string[] 大会コード => DB のパス */
    public static function files()
    {
        $out = [];
        foreach (glob(Db::competitionsDir() . '/*.sqlite') ?: [] as $f) {
            $out[basename($f, '.sqlite')] = $f;
        }
        ksort($out);
        return $out;
    }

    /** 大会コード(大文字小文字は区別しない)から DB のパスと正式なコードを探す。無ければ null */
    public static function find($code)
    {
        $code = trim((string)$code);
        if ($code === '' || !preg_match('/^[A-Za-z0-9_-]{1,32}$/', $code)) {
            return null;
        }
        foreach (self::files() as $c => $path) {
            if (strcasecmp($c, $code) === 0) {
                return ['code' => $c, 'path' => $path];
            }
        }
        return null;
    }

    public static function exists($code)
    {
        return self::find($code) !== null;
    }

    /** 一覧用の概要(他の大会の DB を、いま使っている大会とは別の接続で読む) */
    public static function summary($code, $path)
    {
        $pdo = Db::connect($path);
        $set = [];
        foreach ($pdo->query('SELECT k, v FROM settings') as $r) {
            $set[$r['k']] = $r['v'];
        }
        $preset = Settings::PRESETS[$set['preset'] ?? ''] ?? Settings::PRESETS[Settings::DEFAULTS['preset']];
        $n = (int)$pdo->query('SELECT COUNT(*) FROM entries WHERE status = 0')->fetchColumn();
        $last = $pdo->query("SELECT MAX(updated_at) FROM entries WHERE updated_at != ''")->fetchColumn();
        $mtime = max((int)@filemtime($path), (int)@filemtime($path . '-wal'));
        return [
            'code' => $code,
            'name' => (string)($set['name'] ?? ''),
            'preset_label' => $preset['label'],
            'entries' => $n,
            'last_score' => $last ?: '',
            'created' => (string)($set['created_at'] ?? ''),
            'updated' => $mtime,
            'sample' => strcasecmp($code, self::SAMPLE_CODE) === 0,
        ];
    }

    /** 大会の一覧。新しい順(サンプルは最後) */
    public static function all()
    {
        self::bootstrap();
        $list = [];
        foreach (self::files() as $code => $path) {
            try {
                $list[] = self::summary($code, $path);
            } catch (\Throwable $e) {
                error_log('ianseo-lite: ' . $path . ' を読めません: ' . $e->getMessage());
            }
        }
        usort($list, function ($a, $b) {
            return [$a['sample'], $b['updated']] <=> [$b['sample'], $a['updated']];
        });
        return $list;
    }

    /** 参加者向けページ(結果)で、大会を指定しないときに使う大会: いちばん新しい(サンプル以外) */
    public static function latest()
    {
        $all = self::all();
        foreach ($all as $c) {
            if (!$c['sample']) {
                return $c['code'];
            }
        }
        return $all ? $all[0]['code'] : null;
    }

    // ------------------------------------------------------------ 切り替え

    /**
     * 大会の DB を使うようにする。なければ false。
     * DB に入っている大会コードが、ファイル名のコードと違えば、ファイル名に合わせる(端末はファイル名のコードで探す)。
     */
    public static function open($code)
    {
        self::bootstrap();
        $f = self::find($code);
        if (!$f) {
            return false;
        }
        Db::select($f['path']);
        if ((string)Settings::get('code') !== $f['code']) {
            Settings::set('code', $f['code']);
        }
        return $f['code'];
    }

    /** 別の大会の設定値を、いま使っている大会を変えずに読む */
    public static function readSetting($code, $key)
    {
        $f = self::find($code);
        if (!$f) {
            return null;
        }
        $st = Db::connect($f['path'])->prepare('SELECT v FROM settings WHERE k = ?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string)$v;
    }

    /** 端末(uuid)が登録されている大会のうち、最後に通信したもの。無ければ null */
    public static function byDevice($uuid)
    {
        self::bootstrap();
        $best = null;
        $bestSeen = '';
        foreach (self::files() as $code => $path) {
            try {
                $st = Db::connect($path)->prepare('SELECT last_seen FROM devices WHERE uuid = ?');
                $st->execute([(string)$uuid]);
                $seen = $st->fetchColumn();
            } catch (\Throwable $e) {
                continue;
            }
            if ($seen !== false && ($best === null || $seen > $bestSeen)) {
                $best = $code;
                $bestSeen = $seen;
            }
        }
        return $best;
    }

    // ------------------------------------------------------------ 作成

    /** 8桁の16進数(大文字)で、まだ使われていない大会コードを作る */
    public static function newCode()
    {
        do {
            $code = strtoupper(bin2hex(random_bytes(4)));
        } while (self::exists($code));
        return $code;
    }

    /**
     * 大会を作る。大会コードは自動。パスワードは大会の DB の中に持つ。
     * @return string 大会コード
     */
    public static function create($name, $preset, $password)
    {
        if (!isset(Settings::PRESETS[$preset])) {
            throw new \InvalidArgumentException('競技形式を選んでください');
        }
        Auth::checkPasswordRule($password);
        $code = self::newCode();
        $path = Db::competitionsDir() . '/' . $code . '.sqlite';
        Db::select($path);
        Db::pdo();   // スキーマを作る
        Settings::setMany([
            'code' => $code,
            'name' => trim($name),
            'preset' => $preset,
            'admin_hash' => Auth::hashPassword($password),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $code;
    }

    // ------------------------------------------------------------ 起動時の準備(旧データの移行・サンプル)

    /** 1リクエストにつき1回だけ($force はテスト用) */
    public static function bootstrap($force = false)
    {
        if (self::$booted && !$force) {
            return;
        }
        self::$booted = true;
        try {
            self::migrateLegacy();
            self::seedSample();
        } catch (\Throwable $e) {
            error_log('ianseo-lite: 起動時の準備に失敗: ' . $e->getMessage());
        }
    }

    /** 1大会だけだった頃の data/lite.sqlite を、大会のフォルダへ移す(パスワードも DB の中なのでそのまま) */
    private static function migrateLegacy()
    {
        if (getenv('LITE_DB')) {
            return;
        }
        $legacy = Db::dataDir() . '/lite.sqlite';
        if (!is_file($legacy)) {
            return;
        }
        $pdo = Db::connect($legacy);
        $st = $pdo->query("SELECT v FROM settings WHERE k = 'code'");
        $code = (string)($st ? $st->fetchColumn() : '');
        if ($st) {
            $st->closeCursor();   // 読み終えてから書く(開いたままだと「table is locked」になる)
        }
        if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $code) || $code === 'LITE' || self::exists($code)) {
            $code = self::newCode();
        }
        $set = $pdo->prepare('INSERT INTO settings(k, v) VALUES(?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v');
        $set->execute(['code', $code]);
        $has = $pdo->prepare("SELECT 1 FROM settings WHERE k = 'created_at'");
        $has->execute();
        $hasCreated = $has->fetchColumn();
        $has->closeCursor();
        if (!$hasCreated) {
            $set->execute(['created_at', date('Y-m-d H:i:s', (int)@filemtime($legacy))]);
        }
        $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        unset($pdo, $st, $set, $has);
        $dest = Db::competitionsDir() . '/' . $code . '.sqlite';
        if (@rename($legacy, $dest)) {
            @unlink($legacy . '-wal');
            @unlink($legacy . '-shm');
        }
    }

    /** 同梱のサンプル大会を、まだ無ければコピーする */
    private static function seedSample()
    {
        $src = LITE_ROOT . '/samples/' . self::SAMPLE_CODE . '.sqlite';
        $dest = Db::competitionsDir() . '/' . self::SAMPLE_CODE . '.sqlite';
        if (is_file($src) && !is_file($dest)) {
            @copy($src, $dest);
        }
    }
}
