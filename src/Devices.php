<?php
namespace Lite;

/**
 * ScorekeeperNG を入れた端末(タブレット等)の登録情報。
 */
class Devices
{
    public static function find($uuid)
    {
        $st = Db::pdo()->prepare('SELECT * FROM devices WHERE uuid = ?');
        $st->execute([(string)$uuid]);
        return $st->fetch() ?: null;
    }

    public static function all()
    {
        return Db::pdo()->query('SELECT * FROM devices ORDER BY last_seen DESC')->fetchAll();
    }

    /** 端末コード(4桁の36進数)を採番する */
    private static function nextCode()
    {
        $max = Db::pdo()->query('SELECT MAX(code) FROM devices WHERE length(code) = 4')->fetchColumn();
        $n = $max ? (int)base_convert($max, 36, 10) + 1 : 1;
        return str_pad(base_convert((string)$n, 10, 36), 4, '0', STR_PAD_LEFT);
    }

    /** @return array [row, isNew] */
    public static function register($uuid, $version, $appType, $extra)
    {
        $now = date('Y-m-d H:i:s');
        if ($row = self::find($uuid)) {
            Db::pdo()->prepare('UPDATE devices SET version=?, app_type=?, extra=?, last_seen=? WHERE uuid=?')
                ->execute([(string)$version, (int)$appType, (string)$extra, $now, $uuid]);
            return [self::find($uuid), false];
        }
        Db::pdo()->prepare('INSERT INTO devices(uuid, code, version, app_type, extra, created_at, last_seen)
            VALUES(?,?,?,?,?,?,?)')
            ->execute([$uuid, self::nextCode(), (string)$version, (int)$appType, (string)$extra, $now, $now]);
        return [self::find($uuid), true];
    }

    public static function touch($uuid)
    {
        Db::pdo()->prepare('UPDATE devices SET last_seen=? WHERE uuid=?')->execute([date('Y-m-d H:i:s'), $uuid]);
    }

    public static function update($uuid, array $fields)
    {
        $set = [];
        $vals = [];
        foreach (['target', 'setup', 'setup_confirmed', 'battery', 'gps', 'extra'] as $k) {
            if (array_key_exists($k, $fields)) {
                $set[] = "$k = ?";
                $vals[] = $fields[$k];
            }
        }
        if ($set) {
            $vals[] = $uuid;
            Db::pdo()->prepare('UPDATE devices SET ' . implode(', ', $set) . ' WHERE uuid = ?')->execute($vals);
        }
    }

    public static function forget($uuid)
    {
        Db::pdo()->prepare('DELETE FROM devices WHERE uuid = ?')->execute([$uuid]);
    }
}
