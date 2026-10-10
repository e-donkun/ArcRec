<?php
namespace Lite;

/**
 * 選手と、その得点(距離ごとの arrowstring)。
 */
class Entries
{
    const SELECT = 'SELECT e.*, c.name AS category_name, c.code AS category_code, COALESCE(c.sort, 9999) AS category_sort
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

    // ------------------------------------------------------------ 得点の取り出し

    private static function column($distance)
    {
        return (int)$distance === 2 ? 'arrowstring2' : 'arrowstring';
    }

    /** 指定の距離(1/2)の arrowstring。1距離ぶんの長さに揃えて返す */
    public static function arrows(array $e, $distance)
    {
        return Scoring::pad($e[self::column($distance)] ?? '', Settings::arrowsPerDistance());
    }

    /** 全距離をつなげた arrowstring(合計点・射数の計算用) */
    public static function allArrows(array $e)
    {
        $s = '';
        for ($d = 1; $d <= Settings::distances(); $d++) {
            $s .= self::arrows($e, $d);
        }
        return $s;
    }

    /** どの距離でも1本でも入っているか */
    public static function hasScore(array $e)
    {
        return trim(($e['arrowstring'] ?? '') . ($e['arrowstring2'] ?? '')) !== '';
    }

    /**
     * 追加・更新。選手の登録(姓名・所属・部門・欠場)だけを扱う。
     * 的番号と位置は、$d に 'target' があるときだけ設定する(登録画面では触らない。的番号の設定は place())。
     */
    public static function save(array $d, $id = 0)
    {
        $pdo = Db::pdo();
        $family = trim($d['family_name'] ?? '');
        if ($family === '' && trim($d['given_name'] ?? '') === '') {
            throw new \InvalidArgumentException('氏名を入力してください');
        }
        if (!Categories::find((int)($d['category_id'] ?? 0))) {
            throw new \InvalidArgumentException('部門(リカーブ男子/リカーブ女子)を選んでください');
        }
        $setPos = array_key_exists('target', $d);
        $target = max(0, (int)($d['target'] ?? 0));
        $letter = $target ? strtoupper(trim($d['letter'] ?? 'A')) : '';
        if ($setPos) {
            if ($target && !preg_match('/^[A-F]$/', $letter)) {
                throw new \InvalidArgumentException('的の位置は A〜F で指定してください');
            }
            if ($target > 9999) {
                throw new \InvalidArgumentException('的番号が大きすぎます');
            }
            if ($target) {
                $st = $pdo->prepare('SELECT id FROM entries WHERE target = ? AND letter = ? AND id != ?');
                $st->execute([$target, $letter, (int)$id]);
                if ($st->fetchColumn()) {
                    throw new \InvalidArgumentException("的{$target}{$letter} には既に別の選手がいます");
                }
            }
        }
        $row = [
            $family,
            trim($d['given_name'] ?? ''),
            trim($d['affiliation'] ?? ''),
            (int)$d['category_id'],
            (int)!empty($d['status']),
        ];
        if ($id) {
            $sql = 'UPDATE entries SET family_name=?, given_name=?, affiliation=?, category_id=?, status=?';
            $args = $row;
            if ($setPos) {
                $sql .= ', target=?, letter=?';
                $args = array_merge($args, [$target, $letter]);
            }
            $pdo->prepare($sql . ' WHERE id=?')->execute(array_merge($args, [(int)$id]));
            // 欠場にしたら、点数が無ければ的から外す(点数があるときは動かさない)
            if (!empty($d['status']) && !$setPos && ($cur = self::find($id)) && !self::hasScore($cur) && $cur['target']) {
                $pdo->prepare("UPDATE entries SET target = 0, letter = '' WHERE id = ?")->execute([(int)$id]);
                $pdo->exec("UPDATE devices SET setup = '', setup_confirmed = 0");
            }
            return (int)$id;
        }
        $pdo->prepare('INSERT INTO entries(family_name, given_name, affiliation, category_id, status, target, letter)
            VALUES(?,?,?,?,?,?,?)')->execute(array_merge($row, [$target, $letter]));
        $newId = (int)$pdo->lastInsertId();
        // bib は端末へ渡す選手コード(encode)用で、入力させない。ID を入れる
        $pdo->prepare('UPDATE entries SET bib = ? WHERE id = ?')->execute([(string)$newId, $newId]);
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
     * 選手を的(target)・位置(letter)に置く。target=0 で未割当に戻す。letter が空なら、その的の空いている最初の位置。
     * 置き先に別の選手がいれば入れ替える。
     * 得点が入っている選手(置き先の選手も)は動かせない: 端末は「的+位置」で選手を見分けるので、
     * 得点のあとで入れ替わると、別の人の得点になってしまう。
     *
     * @return array ['ok' => bool, 'msg' => string]
     */
    public static function place($id, $target, $letter = '')
    {
        $target = (int)$target;
        $letter = strtoupper(trim((string)$letter));
        if ($target < 0 || $target > 9999) {
            return ['ok' => false, 'msg' => '的番号は 1〜9999 で指定してください'];
        }
        $letters = Settings::letters();
        if ($letter !== '' && !in_array($letter, $letters, true)) {
            return ['ok' => false, 'msg' => '位置は A〜' . end($letters) . ' で指定してください(立ち数 ' . count($letters) . ')'];
        }
        return Db::immediate(function (\PDO $pdo) use ($id, $target, $letter, $letters) {
            $e = self::find($id);
            if (!$e) {
                return ['ok' => false, 'msg' => '選手が見つかりません'];
            }
            if ($e['status']) {
                return ['ok' => false, 'msg' => '欠場の選手は的に置けません'];
            }
            if ($target === 0) {
                $letter = '';
            } elseif ($letter === '') {
                foreach ($letters as $l) {
                    $o = self::findByPos($target, $l);
                    if (!$o || (int)$o['id'] === (int)$e['id']) {
                        $letter = $l;
                        break;
                    }
                }
                if ($letter === '') {
                    return ['ok' => false, 'msg' => "{$target}的は満員です(A〜" . end($letters) . ')'];
                }
            }
            if ((int)$e['target'] === $target && $e['letter'] === $letter) {
                return ['ok' => true, 'msg' => ''];
            }
            if (self::hasScore($e)) {
                return ['ok' => false, 'msg' => '点数が入力済みの選手は動かせません'];
            }
            $other = $target ? self::findByPos($target, $letter) : null;
            if ($other && (int)$other['id'] === (int)$e['id']) {
                $other = null;
            }
            if ($other && self::hasScore($other)) {
                return ['ok' => false, 'msg' => "的{$target}{$letter} には点数が入力済みの選手がいるため移せません"];
            }
            $up = $pdo->prepare('UPDATE entries SET target = ?, letter = ? WHERE id = ?');
            $oldT = (int)$e['target'];
            $oldL = $e['letter'];
            $up->execute([0, '', $e['id']]);                 // 一意制約(的+位置)に当たらないよう、先にどかす
            if ($other) {
                $up->execute([$oldT, $oldL, $other['id']]);  // 入れ替え: 元の場所へ(元が未割当なら未割当へ)
            }
            $up->execute([$target, $letter, $e['id']]);
            // 端末に保存した選手一覧は古くなるので、次に的を選び直したときに作り直させる
            $pdo->exec("UPDATE devices SET setup = '', setup_confirmed = 0");
            $msg = $other ? Entries::fullName($other) . ' と入れ替えました' : '';
            return ['ok' => true, 'msg' => $msg];
        });
    }

    /** 得点のない選手を、すべて未割当に戻す */
    public static function unassignAll()
    {
        Db::pdo()->exec("UPDATE entries SET target = 0, letter = '' WHERE " . self::NO_SCORE);
        Db::pdo()->exec("UPDATE devices SET setup = '', setup_confirmed = 0");
    }

    /** 得点がまだ1本も無い選手の条件(SQL) */
    const NO_SCORE = "TRIM(arrowstring) = '' AND TRIM(arrowstring2) = ''";

    /**
     * 的番号を割り当て直す。部門順 -> 登録順に、1 的あたり $perTarget 人ずつ A,B,C,D と埋める。
     * 既に得点がある選手は動かさない(得点の取り違え防止)。
     */
    public static function autoAssign($perTarget, $firstTarget = 1)
    {
        $perTarget = max(1, min(Settings::MAX_PER_TARGET, (int)$perTarget));
        Db::immediate(function (\PDO $pdo) use ($perTarget, $firstTarget) {
            $rows = self::all(false);
            $fixed = [];
            $todo = [];
            foreach ($rows as $r) {
                if ($r['status']) {
                    continue;
                }
                if (self::hasScore($r)) {
                    if ($r['target']) {
                        $fixed[$r['target'] . $r['letter']] = true;
                    }
                } else {
                    $todo[] = $r;
                }
            }
            $pdo->exec("UPDATE entries SET target = 0, letter = '' WHERE " . self::NO_SCORE . ' AND status = 0');
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
            $pdo->exec("UPDATE devices SET setup = '', setup_confirmed = 0");
        });
    }

    /**
     * 端末(または管理画面)からの得点を、指定の距離に反映する。
     * 空白でないエンドだけを、そのエンド丸ごと置き換える(端末側の再同期で他のエンドが消えないように)。
     *
     * @param bool|null $signed 署名済みか(null なら変えない)。距離ごとに別々に持つ
     * @return bool 内容が変わったか
     */
    public static function applyArrows($entryId, $incoming, $device = '', $signed = null, $source = 'device', $distance = 1)
    {
        $col = self::column($distance);
        $bit = 1 << (int)$distance;      // ianseo と同じ: 距離n の署名は 2^n
        $per = Settings::arrowsPerEnd();
        $ends = Settings::ends();
        $total = Settings::arrowsPerDistance();
        $incoming = Scoring::pad($incoming, $total);

        return Db::immediate(function (\PDO $pdo) use ($entryId, $incoming, $device, $signed, $source, $distance, $col, $bit, $per, $ends, $total) {
            $st = $pdo->prepare("SELECT $col AS arrows, signed FROM entries WHERE id = ?");
            $st->execute([(int)$entryId]);
            $cur = $st->fetch();
            if (!$cur) {
                throw new \RuntimeException('選手が見つかりません');
            }
            $new = Scoring::pad($cur['arrows'], $total);
            $log = $pdo->prepare('INSERT INTO score_log(entry_id, device, distance, end_no, arrows, source, at)
                VALUES(?,?,?,?,?,?,?)');
            $changed = false;
            $now = date('Y-m-d H:i:s');
            for ($e = 1; $e <= $ends; $e++) {
                $in = substr($incoming, ($e - 1) * $per, $per);
                if (trim($in) === '') {
                    continue;
                }
                if ($in !== substr($new, ($e - 1) * $per, $per)) {
                    $new = substr_replace($new, $in, ($e - 1) * $per, $per);
                    $log->execute([(int)$entryId, $device, (int)$distance, $e, $in, $source, $now]);
                    $changed = true;
                }
            }
            $newSigned = (int)$cur['signed'];
            if ($signed !== null) {
                $newSigned = $signed ? ($newSigned | $bit) : ($newSigned & ~$bit);
            }
            if ($changed || $newSigned !== (int)$cur['signed']) {
                $pdo->prepare("UPDATE entries SET $col = ?, signed = ?, updated_at = ? WHERE id = ?")
                    ->execute([rtrim($new) === '' ? '' : $new, $newSigned, $now, (int)$entryId]);
                $changed = true;
            }
            return $changed;
        });
    }

    /** 管理画面からの訂正: 指定の距離を文字列で完全に置き換える(空白エンドも反映) */
    public static function replaceArrows($entryId, $arrowstring, $by = 'admin', $distance = 1)
    {
        $col = self::column($distance);
        $total = Settings::arrowsPerDistance();
        $per = Settings::arrowsPerEnd();
        $ends = Settings::ends();
        $new = Scoring::pad($arrowstring, $total);
        Db::immediate(function (\PDO $pdo) use ($entryId, $by, $distance, $col, $total, $per, $ends, $new) {
            $cur = self::find($entryId);
            if (!$cur) {
                throw new \RuntimeException('選手が見つかりません');
            }
            $old = Scoring::pad($cur[$col], $total);
            $now = date('Y-m-d H:i:s');
            $log = $pdo->prepare('INSERT INTO score_log(entry_id, device, distance, end_no, arrows, source, at) VALUES(?,?,?,?,?,?,?)');
            $changed = false;
            for ($e = 1; $e <= $ends; $e++) {
                $o = substr($old, ($e - 1) * $per, $per);
                $n = substr($new, ($e - 1) * $per, $per);
                if ($o !== $n) {
                    $log->execute([(int)$entryId, '', (int)$distance, $e, $n, $by, $now]);
                    $changed = true;
                }
            }
            if ($changed) {
                $pdo->prepare("UPDATE entries SET $col = ?, updated_at = ? WHERE id = ?")
                    ->execute([rtrim($new) === '' ? '' : $new, $now, (int)$entryId]);
            }
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
