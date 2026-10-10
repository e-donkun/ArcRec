<?php
namespace Lite;

/**
 * 部門。記録会ごとに自由に設定できる(記録会設定の「部門」)。新しい記録会には 男子(RM)・女子(RW) が入る。
 * 順位は部門ごとに出す。並び順(sort)の小さい順に並べ、CSV で部門が空のときは先頭の部門にする。
 * コードは端末(ScorekeeperNG)に渡す種目コードにもなる。
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
        $st = Db::pdo()->prepare('SELECT * FROM categories WHERE code = ? COLLATE NOCASE');
        $st->execute([(string)$code]);
        return $st->fetch() ?: null;
    }

    /** 並び順が一番若い部門の ID(CSV で部門が空のときの既定) */
    public static function firstId()
    {
        $all = self::all();
        return $all ? (int)$all[0]['id'] : null;
    }

    /** 部門を追加する。並び順を省くと最後に付ける。@return int 新しい ID */
    public static function add($code, $name, $sort = null)
    {
        list($code, $name) = self::check($code, $name);
        if ($sort === null || $sort === '') {
            $sort = (int)Db::pdo()->query('SELECT COALESCE(MAX(sort), 0) FROM categories')->fetchColumn() + 10;
        }
        Db::pdo()->prepare('INSERT INTO categories(code, name, sort) VALUES(?, ?, ?)')->execute([$code, $name, (int)$sort]);
        return (int)Db::pdo()->lastInsertId();
    }

    public static function update($id, $code, $name, $sort)
    {
        list($code, $name) = self::check($code, $name, (int)$id);
        Db::pdo()->prepare('UPDATE categories SET code = ?, name = ?, sort = ? WHERE id = ?')->execute([$code, $name, (int)$sort, (int)$id]);
    }

    /** 削除。最後の 1 つと、参加者のいる部門は消せない */
    public static function delete($id)
    {
        $c = self::find($id);
        if (!$c) {
            throw new \InvalidArgumentException('部門が見つかりません');
        }
        if (count(self::all()) <= 1) {
            throw new \InvalidArgumentException('部門は 1 つ以上必要です');
        }
        $st = Db::pdo()->prepare('SELECT COUNT(*) FROM entries WHERE category_id = ?');
        $st->execute([(int)$id]);
        if ((int)$st->fetchColumn()) {
            throw new \InvalidArgumentException('部門「' . $c['name'] . '」には参加者がいるため削除できません(先に参加者の部門を変えてください)');
        }
        Db::pdo()->prepare('DELETE FROM categories WHERE id = ?')->execute([(int)$id]);
    }

    /** コード(英数字 1〜8 文字、大文字にする)と部門名の確認。重複は不可 */
    private static function check($code, $name, $id = 0)
    {
        $code = strtoupper(trim(mb_convert_kana((string)$code, 'a')));
        $name = trim((string)$name);
        if (!preg_match('/^[A-Z0-9]{1,8}$/', $code)) {
            throw new \InvalidArgumentException('部門コードは英数字 8 文字までで入力してください(例 RM)');
        }
        if ($name === '') {
            throw new \InvalidArgumentException('部門名を入力してください');
        }
        foreach (self::all() as $c) {
            if ((int)$c['id'] === (int)$id) {
                continue;
            }
            if (strcasecmp($c['code'], $code) === 0) {
                throw new \InvalidArgumentException('部門コード「' . $code . '」はすでにあります');
            }
            if ($c['name'] === $name) {
                throw new \InvalidArgumentException('部門名「' . $name . '」はすでにあります');
            }
        }
        return [$code, $name];
    }

    /**
     * 名簿に書かれた部門の表記から部門 ID を決める。見つからなければ null。
     *   1. 登録してある部門のコード・部門名と一致(大文字小文字・全角半角は区別しない)
     *   2. 男女の表記ゆれ: 「リカーブ男子」「男」「M」「Men」→ コード RM か部門名「男子」の部門、
     *      「リカーブ女子」「女」「W」「F」「Women」→ RW か「女子」の部門(コンパウンドなどは対象外)
     */
    public static function resolve($text)
    {
        $raw = trim(mb_convert_kana((string)$text, 'as'));
        if ($raw === '') {
            return null;
        }
        $all = self::all();
        foreach ($all as $c) {
            if (strcasecmp($c['code'], $raw) === 0 || mb_strtoupper($c['name']) === mb_strtoupper($raw)) {
                return (int)$c['id'];
            }
        }
        $t = mb_strtoupper($raw);
        // 前後の「リカーブ」「の部」は付いていてもよい
        $t = preg_replace('/^(リカーブ|RECURVE)[\s・]*/u', '', $t);
        $t = preg_replace('/[\s・]*(リカーブ|RECURVE|の部|部)$/u', '', $t);
        $want = null;
        if (preg_match('/^(女子?|女性|W|F|WOMEN|FEMALE|LADIES|GIRL|RW)$/u', $t)) {
            $want = ['RW', '女子'];
        } elseif (preg_match('/^(男子?|男性|M|MEN|MALE|BOY|RM)$/u', $t)) {
            $want = ['RM', '男子'];
        }
        if ($want) {
            foreach ($all as $c) {
                if (strcasecmp($c['code'], $want[0]) === 0 || $c['name'] === $want[1]) {
                    return (int)$c['id'];
                }
            }
        }
        return null;
    }
}
