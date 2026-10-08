<?php
namespace Lite;

/**
 * 選手と、その得点(arrowstring)。
 */
class Entries
{
    const SELECT = 'SELECT e.*, c.name AS category_name, c.code AS category_code,
            COALESCE(c.targetface, 5) AS targetface, COALESCE(c.sort, 9999) AS category_sort
        FROM entries e LEFT JOIN categories c ON c.id = e.category_id';

    public static function all($orderByTarget = true)
    {
        $order = $orderByTarget
            ? ' ORDER BY (e.target = 0), e.target, e.letter, e.id'
            : ' ORDER BY category_sort, e.id';
        return Db::pdo()->query(self::SELECT . $order)->fetchAll();
    }

    public static function find($id)
    {
        $st = Db::pdo()->prepare(self::SELECT . ' WHERE e.id = ?');
        $st->execute([(int)$id]);
        return $st->fetch() ?: null;
    }

    public static function findByPos($target, $letter)
    {
        $st = Db::pdo()->prepare(self::SELECT . ' WHERE e.target = ? AND e.letter = ?');
        $st->execute([(int)$target, strtoupper($letter)]);
        return $st->fetch() ?: null;
    }

    /** 的番号の一覧(昇順) */
    public static function targets()
    {
        return array_map('intval', Db::pdo()->query(
            'SELECT DISTINCT target FROM entries WHERE target > 0 ORDER BY target')->fetchAll(\PDO::FETCH_COLUMN));
    }

    public static function onTarget($target)
    {
        $st = Db::pdo()->prepare(self::SELECT . ' WHERE e.target = ? AND e.status = 0 ORDER BY e.letter');
        $st->execute([(int)$target]);
        return $st->fetchAll();
    }

    /**
     * 追加・更新。target/letter が既に別選手で使われている場合は例外。
     */
    public static function save(array $d, $id = 0)
    {
        $pdo = Db::pdo();
        $family = trim($d['family_name'] ?? '');
        if ($family === '' && trim($d['given_name'] ?? '') === '') {
            throw new \InvalidArgumentException('氏名を入力してください');
        }
        $target = max(0, (int)($d['target'] ?? 0));
        $letter = $target ? strtoupper(trim($d['letter'] ?? 'A')) : '';
        if ($target && !preg_match('/^[A-D]$/', $letter)) {
            throw new \InvalidArgumentException('的の位置は A〜D で指定してください');
        }
        if ($target > 9999) {
            throw new \InvalidArgumentException('的番号が大きすぎます');
        }
        if ($target) {
            $st = $pdo->prepare('SELECT id FROM entries WHERE target = ? AND letter = ? AND id != ?');
            $st->execute([$target, $letter, (int)$id]);
            if ($st->fetchColumn()) {
                throw new \InvalidArgumentException("的 {$target}{$letter} には既に別の選手がいます");
            }
        }
        $row = [
            trim($d['bib'] ?? ''),
            $family,
            trim($d['given_name'] ?? ''),
            trim($d['affiliation'] ?? ''),
            empty($d['category_id']) ? null : (int)$d['category_id'],
            $target,
            $letter,
            (int)!empty($d['status']),
        ];
        if ($id) {
            $st = $pdo->prepare('UPDATE entries SET bib=?, family_name=?, given_name=?, affiliation=?,
                category_id=?, target=?, letter=?, status=? WHERE id=?');
            $st->execute(array_merge($row, [(int)$id]));
            return (int)$id;
        }
        $st = $pdo->prepare('INSERT INTO entries(bib, family_name, given_name, affiliation, category_id,
            target, letter, status) VALUES(?,?,?,?,?,?,?,?)');
        $st->execute($row);
        $newId = (int)$pdo->lastInsertId();
        if ($row[0] === '') {
            $pdo->prepare('UPDATE entries SET bib = ? WHERE id = ?')->execute([(string)$newId, $newId]);
        }
        return $newId;
    }

    public static function delete($id)
    {
        Db::pdo()->prepare('DELETE FROM entries WHERE id=?')->execute([(int)$id]);
        Db::pdo()->prepare('DELETE FROM score_log WHERE entry_id=?')->execute([(int)$id]);
    }

    public static function setLocked($id, $locked)
    {
        Db::pdo()->prepare('UPDATE entries SET locked=? WHERE id=?')->execute([$locked ? 1 : 0, (int)$id]);
    }

    public static function setAllLocked($locked)
    {
        Db::pdo()->prepare('UPDATE entries SET locked=?')->execute([$locked ? 1 : 0]);
    }

    /**
     * 的番号を割り当て直す。部門順 -> 登録順に、1 的あたり $perTarget 人ずつ A,B,C,D と埋める。
     * 既に得点がある選手は動かさない(得点の取り違え防止)。
     */
    public static function autoAssign($perTarget, $firstTarget = 1)
    {
        $perTarget = max(1, min(4, (int)$perTarget));
        Db::immediate(function (\PDO $pdo) use ($perTarget, $firstTarget) {
            $rows = self::all(false);
            $fixed = [];
            $todo = [];
            foreach ($rows as $r) {
                if ($r['status']) {
                    continue;
                }
                if (trim($r['arrowstring']) !== '') {
                    if ($r['target']) {
                        $fixed[$r['target'] . $r['letter']] = true;
                    }
                } else {
                    $todo[] = $r;
                }
            }
            $pdo->exec('UPDATE entries SET target = 0, letter = \'\' WHERE TRIM(arrowstring) = \'\' AND status = 0');
            $t = max(1, (int)$firstTarget);
            $slot = 0;
            $up = $pdo->prepare('UPDATE entries SET target = ?, letter = ? WHERE id = ?');
            foreach ($todo as $r) {
                while (true) {
                    $letter = chr(65 + $slot);
                    if (!isset($fixed[$t . $letter])) {
                        break;
                    }
                    if (++$slot >= $perTarget) {
                        $slot = 0;
                        $t++;
                    }
                }
                $up->execute([$t, $letter, $r['id']]);
                if (++$slot >= $perTarget) {
                    $slot = 0;
                    $t++;
                }
            }
        });
    }

    /**
     * 端末(または管理画面)からの得点を反映する。
     * 空白でないエンドだけを、そのエンド丸ごと置き換える(端末側の再同期で他のエンドが消えないように)。
     *
     * @return bool 内容が変わったか
     */
    public static function applyArrows($entryId, $incoming, $device = '', $signed = null, $source = 'device')
    {
        $per = Settings::arrowsPerEnd();
        $ends = Settings::ends();
        $total = $per * $ends;
        $incoming = Scoring::pad($incoming, $total);

        return Db::immediate(function (\PDO $pdo) use ($entryId, $incoming, $device, $signed, $source, $per, $ends, $total) {
            $st = $pdo->prepare('SELECT arrowstring, signed FROM entries WHERE id = ?');
            $st->execute([(int)$entryId]);
            $cur = $st->fetch();
            if (!$cur) {
                throw new \RuntimeException('選手が見つかりません');
            }
            $new = Scoring::pad($cur['arrowstring'], $total);
            $log = $pdo->prepare('INSERT INTO score_log(entry_id, device, end_no, arrows, source, at)
                VALUES(?,?,?,?,?,?)');
            $changed = false;
            $now = date('Y-m-d H:i:s');
            for ($e = 1; $e <= $ends; $e++) {
                $in = substr($incoming, ($e - 1) * $per, $per);
                if (trim($in) === '') {
                    continue;
                }
                if ($in !== substr($new, ($e - 1) * $per, $per)) {
                    $new = substr_replace($new, $in, ($e - 1) * $per, $per);
                    $log->execute([(int)$entryId, $device, $e, $in, $source, $now]);
                    $changed = true;
                }
            }
            $newSigned = $signed === null ? (int)$cur['signed'] : (int)(bool)$signed;
            if ($changed || $newSigned !== (int)$cur['signed']) {
                $pdo->prepare('UPDATE entries SET arrowstring = ?, signed = ?, updated_at = ? WHERE id = ?')
                    ->execute([rtrim($new) === '' ? '' : $new, $newSigned, $now, (int)$entryId]);
                $changed = true;
            }
            return $changed;
        });
    }

    /** 管理画面からの訂正: 文字列で完全に置き換える(空白エンドも反映) */
    public static function replaceArrows($entryId, $arrowstring, $by = 'admin')
    {
        $total = Settings::totalArrows();
        $per = Settings::arrowsPerEnd();
        $ends = Settings::ends();
        $new = Scoring::pad($arrowstring, $total);
        Db::immediate(function (\PDO $pdo) use ($entryId, $by, $total, $per, $ends, $new) {
            $cur = self::find($entryId);
            if (!$cur) {
                throw new \RuntimeException('選手が見つかりません');
            }
            $old = Scoring::pad($cur['arrowstring'], $total);
            $now = date('Y-m-d H:i:s');
            $log = $pdo->prepare('INSERT INTO score_log(entry_id, device, end_no, arrows, source, at) VALUES(?,?,?,?,?,?)');
            for ($e = 1; $e <= $ends; $e++) {
                $o = substr($old, ($e - 1) * $per, $per);
                $n = substr($new, ($e - 1) * $per, $per);
                if ($o !== $n) {
                    $log->execute([(int)$entryId, '', $e, $n, $by, $now]);
                }
            }
            $pdo->prepare('UPDATE entries SET arrowstring = ?, updated_at = ? WHERE id = ?')
                ->execute([rtrim($new) === '' ? '' : $new, $now, (int)$entryId]);
        });
    }

    public static function log($entryId, $limit = 50)
    {
        $st = Db::pdo()->prepare('SELECT * FROM score_log WHERE entry_id = ? ORDER BY id DESC LIMIT ' . (int)$limit);
        $st->execute([(int)$entryId]);
        return $st->fetchAll();
    }

    /** 氏名の表示(姓 名) */
    public static function fullName(array $e)
    {
        return trim($e['family_name'] . ' ' . $e['given_name']);
    }
}
