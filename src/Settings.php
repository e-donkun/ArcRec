<?php
namespace Lite;

/**
 * 大会設定(1 セッション・1 距離の予選のみ)。
 */
class Settings
{
    /** 競技形式のプリセット: ends x arrows が総射数 */
    const PRESETS = [
        'outdoor36' => ['label' => '屋外 36射 (6射 x 6エンド)', 'category' => 1, 'ends' => 6, 'arrows' => 6, 'targetface' => 5],
        'outdoor72' => ['label' => '屋外 72射 (6射 x 12エンド)', 'category' => 1, 'ends' => 12, 'arrows' => 6, 'targetface' => 5],
        'indoor30' => ['label' => '屋内 30射 (3射 x 10エンド)', 'category' => 2, 'ends' => 10, 'arrows' => 3, 'targetface' => 1],
        'indoor60' => ['label' => '屋内 60射 (3射 x 20エンド)', 'category' => 2, 'ends' => 20, 'arrows' => 3, 'targetface' => 1],
    ];

    const DEFAULTS = [
        'code' => 'LITE',
        'name' => '',
        'place' => '',
        'date' => '',
        'organizer' => '',
        'preset' => 'outdoor72',
        'ends' => '12',
        'arrows' => '6',
        'category' => '1',        // 1:屋外 2:屋内
        'distance_name' => '70m',
        'pin' => '',
        'gold_label' => '10+X',
        'x_label' => 'X',
        'session_name' => '',
        'archers_per_target' => '4',
        'admin_hash' => '',
        'server_url' => '',
        'configured' => '0',
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

    /** 1エンドの矢数 */
    public static function arrowsPerEnd()
    {
        return max(1, (int)self::get('arrows'));
    }

    public static function ends()
    {
        return max(1, (int)self::get('ends'));
    }

    public static function totalArrows()
    {
        return self::arrowsPerEnd() * self::ends();
    }

    public static function isIndoor()
    {
        return (int)self::get('category') === 2;
    }

    public static function sessionName()
    {
        return self::get('session_name') ?: 'Session 1';
    }
}
