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
        'outdoor36' => ['label' => '屋外 36射 (1距離)', 'category' => 1, 'distances' => 1, 'ends' => 6, 'arrows' => 6],
        'outdoor72' => ['label' => '屋外 36射 x 2距離 (72射)', 'category' => 1, 'distances' => 2, 'ends' => 6, 'arrows' => 6],
        'indoor30' => ['label' => '屋内 30射 (1距離)', 'category' => 2, 'distances' => 1, 'ends' => 10, 'arrows' => 3],
        'indoor60' => ['label' => '屋内 30射 x 2距離 (60射)', 'category' => 2, 'distances' => 2, 'ends' => 10, 'arrows' => 3],
    ];

    /** 屋外リカーブの的面(122cm 1-X)。屋内は indoor_face で選ぶ */
    const OUTDOOR_FACE = 5;
    /** 屋内の的面の選択肢: 40cm 単独(1-10) / 三つ目(6-10) */
    const INDOOR_FACES = [1 => '40cm 単独 (1-10)', 2 => '三つ目 (6-10)'];

    const DEFAULTS = [
        'code' => 'LITE',
        'name' => '',
        'preset' => 'outdoor72',
        'distance_name' => '',
        'distance_name2' => '',
        'indoor_face' => '1',
        'pin' => '',
        'gold_label' => '10+X',
        'x_label' => 'X',
        'session_name' => '',
        'archers_per_target' => '4',
        'admin_hash' => '',
        'server_url' => '',
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

    /** 距離の表記(例 "70m")。未入力なら屋外 70m / 屋内 18m */
    public static function distanceName($d = 1)
    {
        $v = trim((string)self::get($d == 2 ? 'distance_name2' : 'distance_name'));
        return $v !== '' ? $v : (self::isIndoor() ? '18m' : '70m');
    }

    /** 距離の要約(例 "70m x 2" / "70m + 60m" / "70m") */
    public static function distanceSummary()
    {
        if (self::distances() === 1) {
            return self::distanceName(1);
        }
        $a = self::distanceName(1);
        $b = self::distanceName(2);
        return $a === $b ? $a . ' x 2' : $a . ' + ' . $b;
    }

    /** 全選手が使う的面ID */
    public static function targetFace()
    {
        if (!self::isIndoor()) {
            return self::OUTDOOR_FACE;
        }
        $f = (int)self::get('indoor_face');
        return isset(self::INDOOR_FACES[$f]) ? $f : 1;
    }

    public static function sessionName()
    {
        return self::get('session_name') ?: 'Session 1';
    }
}
