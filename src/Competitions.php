<?php
namespace Lite;

use PDO;

/**
 * 記録会の一覧と切り替え。記録会 1 つ = SQLite ファイル 1 つ(data/competitions/<記録会コード>.sqlite)。
 * 管理パスワードも、その記録会の DB の中(settings.admin_hash)にある。
 */
class Competitions
{
    /** 同梱のサンプル記録会(samples/ からコピーして使う): 屋外(Outdoor 36射×2)と屋内(Indoor 30射×2)。パスワードはどちらも sample */
    const SAMPLE_CODE = '5A4D0001';
    const SAMPLE_CODES = ['5A4D0001', '5A4D0002'];
    const SAMPLE_PASSWORD = 'sample';

    public static function isSample($code)
    {
        foreach (self::SAMPLE_CODES as $c) {
            if (strcasecmp($c, (string)$code) === 0) {
                return true;
            }
        }
        return false;
    }

    private static $booted = false;

    // ------------------------------------------------------------ 一覧・検索

    /**
     * @return string[] 記録会コード => DB のパス
     * 注意: コードが数字だけ(例 84247516)だと、PHP の配列のキーは整数になる。使うときは (string) にすること
     */
    public static function files()
    {
        $out = [];
        foreach (glob(Db::competitionsDir() . '/*.sqlite') ?: [] as $f) {
            $out[basename($f, '.sqlite')] = $f;
        }
        ksort($out);
        return $out;
    }

    /** 記録会コード(大文字小文字は区別しない)から DB のパスと正式なコードを探す。無ければ null */
    public static function find($code)
    {
        $code = trim((string)$code);
        if ($code === '' || !preg_match('/^[A-Za-z0-9_-]{1,32}$/', $code)) {
            return null;
        }
        foreach (self::files() as $c => $path) {
            $c = (string)$c;
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

    /** 一覧用の概要(他の記録会の DB を、いま使っている記録会とは別の接続で読む) */
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
            'sample' => self::isSample($code),
        ];
    }

    /** 記録会の一覧。新しい順(サンプルは最後) */
    public static function all()
    {
        self::bootstrap();
        $list = [];
        foreach (self::files() as $code => $path) {
            try {
                $list[] = self::summary((string)$code, $path);
            } catch (\Throwable $e) {
                error_log('ianseo-lite: ' . $path . ' を読めません: ' . $e->getMessage());
            }
        }
        usort($list, function ($a, $b) {
            return [$a['sample'], $b['updated']] <=> [$b['sample'], $a['updated']];
        });
        return $list;
    }

    // ------------------------------------------------------------ 切り替え

    /**
     * 記録会の DB を使うようにする。なければ false。
     * DB に入っている記録会コードが、ファイル名のコードと違えば、ファイル名に合わせる(端末はファイル名のコードで探す)。
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

    /** 別の記録会の設定値を、いま使っている記録会を変えずに読む */
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

    /** 端末(uuid)が登録されている記録会のうち、最後に通信したもの。無ければ null */
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
                $best = (string)$code;
                $bestSeen = $seen;
            }
        }
        return $best;
    }

    // ------------------------------------------------------------ 作成

    /** 8桁の16進数(大文字)で、まだ使われていない記録会コードを作る */
    public static function newCode()
    {
        do {
            $code = strtoupper(bin2hex(random_bytes(4)));
        } while (self::exists($code));
        return $code;
    }

    /**
     * 記録会を作る。記録会コードは自動。パスワードは記録会の DB の中に持つ。
     * @return string 記録会コード
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

    /**
     * 記録会の DB ファイルを削除する(-wal / -shm も)。元に戻せない。
     * 同梱のサンプルを消した場合は、次のアクセスで samples/ から作り直される。
     */
    public static function delete($code)
    {
        $f = self::find($code);
        if (!$f) {
            throw new \InvalidArgumentException('記録会が見つかりません');
        }
        Db::unselect();   // 開いている接続を閉じてから消す
        foreach (['', '-wal', '-shm'] as $ext) {
            if (is_file($f['path'] . $ext) && !@unlink($f['path'] . $ext)) {
                throw new \RuntimeException('記録会のファイルを削除できません: ' . basename($f['path'] . $ext));
            }
        }
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

    /** 1記録会だけだった頃の data/lite.sqlite を、記録会のフォルダへ移す(パスワードも DB の中なのでそのまま) */
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

    /** 同梱のサンプル記録会を、まだ無ければコピーする */
    private static function seedSample()
    {
        foreach (self::SAMPLE_CODES as $code) {
            $src = LITE_ROOT . '/samples/' . $code . '.sqlite';
            $dest = Db::competitionsDir() . '/' . $code . '.sqlite';
            if (is_file($src) && !is_file($dest)) {
                @copy($src, $dest);
            } elseif (strpos((string)self::readSetting($code, 'name'), 'サンプル大会') === 0) {
                // 以前の版の「サンプル大会」という名前を「サンプル記録会」に直す
                $pdo = Db::connect($dest);
                $pdo->exec("UPDATE settings SET v = REPLACE(v, 'サンプル大会', 'サンプル記録会') WHERE k = 'name' AND v LIKE 'サンプル大会%'");
            }
        }
    }
}
