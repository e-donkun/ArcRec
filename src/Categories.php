<?php
namespace Lite;

/**
 * 部門は「リカーブ男子」「リカーブ女子」の2つだけ(DB 作成時に自動で入る)。
 * 順位は部門ごとに出す。
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

    public static function byCode($code)
    {
        $st = Db::pdo()->prepare('SELECT * FROM categories WHERE code = ?');
        $st->execute([(string)$code]);
        return $st->fetch() ?: null;
    }

    /**
     * 名簿に書かれた部門の表記から部門IDを決める。
     * 「リカーブ男子」「男子」「男」「M」「RM」→ 男子、「リカーブ女子」「女子」「女」「W」「F」「RW」→ 女子。
     * リカーブ以外(コンパウンドなど)や判別できない表記は null(男の字を含むだけでは男子にしない)。
     */
    public static function resolve($text)
    {
        $t = mb_strtoupper(trim((string)$text));
        // 前後の「リカーブ」「の部」は付いていてもよい
        $t = preg_replace('/^(リカーブ|RECURVE)[\s・]*/u', '', $t);
        $t = preg_replace('/[\s・]*(リカーブ|RECURVE|の部|部)$/u', '', $t);
        $code = null;
        if (preg_match('/^(女子?|女性|W|F|WOMEN|FEMALE|LADIES|GIRL|RW)$/u', $t)) {
            $code = 'RW';
        } elseif (preg_match('/^(男子?|男性|M|MEN|MALE|BOY|RM)$/u', $t)) {
            $code = 'RM';
        }
        $c = $code ? self::byCode($code) : null;
        return $c ? (int)$c['id'] : null;
    }
}
