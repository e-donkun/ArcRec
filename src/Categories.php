<?php
namespace Lite;

/**
 * 部門(例: リカーブ男子、コンパウンド女子)。順位は部門ごとに出す。
 */
class Categories
{
    public static function all()
    {
        return Db::pdo()->query('SELECT * FROM categories ORDER BY sort, id')->fetchAll();
    }

    public static function find($id)
    {
        $st = Db::pdo()->prepare('SELECT * FROM categories WHERE id = ?');
        $st->execute([(int)$id]);
        return $st->fetch() ?: null;
    }

    public static function save($id, $code, $name, $targetface, $sort = 0)
    {
        $pdo = Db::pdo();
        $code = mb_substr(trim($code), 0, 10);
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('部門名を入力してください');
        }
        if (!TargetFaces::exists($targetface)) {
            throw new \InvalidArgumentException('的面が不正です');
        }
        if ($id) {
            $st = $pdo->prepare('UPDATE categories SET code=?, name=?, targetface=?, sort=? WHERE id=?');
            $st->execute([$code, $name, (int)$targetface, (int)$sort, (int)$id]);
            return (int)$id;
        }
        if (!$sort) {
            $sort = (int)$pdo->query('SELECT COALESCE(MAX(sort),0)+10 FROM categories')->fetchColumn();
        }
        $st = $pdo->prepare('INSERT INTO categories(code, name, targetface, sort) VALUES(?,?,?,?)');
        $st->execute([$code, $name, (int)$targetface, (int)$sort]);
        return (int)$pdo->lastInsertId();
    }

    public static function delete($id)
    {
        $st = Db::pdo()->prepare('DELETE FROM categories WHERE id=?');
        $st->execute([(int)$id]);
    }

    /** 名前で探し、無ければ作る(CSV取込用) */
    public static function findOrCreate($name)
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        $st = Db::pdo()->prepare('SELECT id FROM categories WHERE name = ? OR (code != \'\' AND code = ?)');
        $st->execute([$name, $name]);
        if ($id = $st->fetchColumn()) {
            return (int)$id;
        }
        $face = (int)(Settings::PRESETS[Settings::get('preset')]['targetface'] ?? 5);
        return self::save(0, '', $name, $face);
    }
}
