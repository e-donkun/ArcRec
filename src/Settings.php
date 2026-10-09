<?php
namespace Lite;

/**
 * 大会設定。WA(World Archery)の予選形式に絞る: 1 または 2 距離、各距離 屋外 36射 / 屋内 30射。
 * 射数・エンド数は競技形式(preset)から決まる。部門はリカーブ男子・女子だけ。
 */
class Settings
{
    /**
     * 競技形式。ends/arrows は 1 距離ぶん。屋外は 6 射 x 6 エンド、屋内は 3 射 x 10 エンド。
     */
    const PRESETS = [
        'outdoor36' => ['label' => 'Outdoor 36射', 'category' => 1, 'distances' => 1, 'ends' => 6, 'arrows' => 6],
        'outdoor72' => ['label' => 'Outdoor 36射×2（72射）', 'category' => 1, 'distances' => 2, 'ends' => 6, 'arrows' => 6],
        'indoor30' => ['label' => 'Indoor 30射', 'category' => 2, 'distances' => 1, 'ends' => 10, 'arrows' => 3],
        'indoor60' => ['label' => 'Indoor 30射×2（60射）', 'category' => 2, 'distances' => 2, 'ends' => 10, 'arrows' => 3],
    ];

    /** 的面の選択肢(リカーブ): 屋外 / 屋内。値は的面ID(TargetFaces) */
    const FACES = [
        1 => [5 => '10リング (1-X)', 10 => '6リング (6-X)'],
        2 => [1 => '40cm 10リング (1-10)', 2 => '40cm 三つ目 (6-10)'],
    ];

    /** 2距離のときの距離名の初期値(1距離のときは空欄) */
    const DEFAULT_DISTANCE_NAMES = [1 => '前半', 2 => '後半'];

    /** 端末に見せるセッション名(セッションは1つだけ) */
    const SESSION_NAME = 'Session 1';

    const DEFAULTS = [
        'code' => '',
        'name' => '',
        'preset' => 'outdoor72',
        'distance_name' => '',
        'distance_name2' => '',
        'target_face1' => '',
        'target_face2' => '',
        'gold_label' => '10+X',
        'x_label' => 'X',
        'archers_per_target' => '4',
        'admin_hash' => '',
        'api_log' => '0',
    ];

    private static $cache;

    public static function all()
    {
        if (self::$cache === null) {
            self::$cache = self::DEFAULTS;
            foreach (Db::pdo()->query('SELECT k, v FROM settings') as $r) {
                self::$cache[$r['k']] = $r['v'];
            }
        }
        return self::$cache;
    }

    public static function get($key)
    {
        $a = self::all();
        return $a[$key] ?? null;
    }

    public static function set($key, $value)
    {
        $st = Db::pdo()->prepare('INSERT INTO settings(k, v) VALUES(?, ?)
            ON CONFLICT(k) DO UPDATE SET v = excluded.v');
        $st->execute([$key, (string)$value]);
        self::$cache = null;
    }

    public static function setMany(array $values)
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        foreach ($values as $k => $v) {
            self::set($k, $v);
        }
        $pdo->commit();
    }

    public static function reset()
    {
        self::$cache = null;
    }

    /** 今の競技形式の定義(不明な値のときは既定の形式) */
    public static function preset()
    {
        $k = (string)self::get('preset');
        return self::PRESETS[$k] ?? self::PRESETS[self::DEFAULTS['preset']];
    }

    /** 距離の数(1 か 2) */
    public static function distances()
    {
        return self::preset()['distances'];
    }

    /** 1エンドの矢数 */
    public static function arrowsPerEnd()
    {
        return self::preset()['arrows'];
    }

    /** 1距離のエンド数 */
    public static function ends()
    {
        return self::preset()['ends'];
    }

    /** 1距離の矢数(屋外 36 / 屋内 30) */
    public static function arrowsPerDistance()
    {
        return self::arrowsPerEnd() * self::ends();
    }

    /** 全距離の矢数 */
    public static function totalArrows()
    {
        return self::arrowsPerDistance() * self::distances();
    }

    public static function isIndoor()
    {
        return self::preset()['category'] === 2;
    }

    /** ISK の tocategory(1 屋外 / 2 屋内) */
    public static function category()
    {
        return self::preset()['category'];
    }

    /** 大会の表示名。未入力のときは大会コード */
    public static function title()
    {
        $n = trim((string)self::get('name'));
        return $n !== '' ? $n : self::code();
    }

    /** 距離の名前。入力が無ければ、2距離は 前半 / 後半、1距離は空欄 */
    public static function distanceName($d = 1)
    {
        $d = $d == 2 ? 2 : 1;
        $v = trim((string)self::get($d === 2 ? 'distance_name2' : 'distance_name'));
        if ($v !== '') {
            return $v;
        }
        return self::distances() === 2 ? self::DEFAULT_DISTANCE_NAMES[$d] : '';
    }

    /**
     * 見出しに足す距離名(例 "70m + 60m")。初期値のまま(前半/後半)や空欄のときは空文字にして、
     * 見出しには射数だけを出す。
     */
    public static function distanceSummary()
    {
        if (self::distances() === 1) {
            return self::distanceName(1);
        }
        $a = self::distanceName(1);
        $b = self::distanceName(2);
        if ($a === self::DEFAULT_DISTANCE_NAMES[1] && $b === self::DEFAULT_DISTANCE_NAMES[2]) {
            return '';
        }
        return $a . ' + ' . $b;
    }

    /** 見出し用: 「72射」「70m 36射」「70m + 60m 72射」 */
    public static function roundLabel()
    {
        return trim(self::distanceSummary() . ' ' . self::totalArrows() . '射');
    }

    /** 今の競技形式(屋外/屋内)で選べる的面 [的面ID => 表示名] */
    public static function faceOptions()
    {
        return self::FACES[self::category()];
    }

    /** 距離ごとの的面ID。未設定や、競技形式に合わない値のときは選択肢の先頭 */
    public static function targetFace($d = 1)
    {
        $opts = self::faceOptions();
        $v = (int)self::get($d == 2 ? 'target_face2' : 'target_face1');
        if (!isset($opts[$v]) && $d == 2) {
            $v = (int)self::get('target_face1'); // 距離2が未設定なら距離1と同じ
        }
        return isset($opts[$v]) ? $v : (int)array_key_first($opts);
    }

    /**
     * 大会コード(端末が使う識別子)。ユーザーは入力しない。
     * 大会の作成時(最初に必要になったとき)に、8桁の16進数をランダムに作って保存する。
     * 空のときと、以前の既定値 "LITE" のときだけ作り直す(自分で入れた値は、そのまま使い続ける)。
     */
    public static function code()
    {
        $v = (string)self::get('code');
        if ($v !== '' && $v !== 'LITE') {
            return $v;
        }
        return Db::immediate(function () {
            self::reset();
            $v = (string)self::get('code');
            if ($v === '' || $v === 'LITE') {
                $v = strtoupper(bin2hex(random_bytes(4)));
                self::set('code', $v);
            }
            return $v;
        });
    }

    public static function sessionName()
    {
        return self::SESSION_NAME;
    }
}
