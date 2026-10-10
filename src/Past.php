<?php
namespace Lite;

use PDO;

/**
 * 過去の記録会のデータ(選手・所属)から、選手登録の候補を出す。
 * このサーバーにある、いま開いている記録会以外のすべての記録会の名簿を読む(読むのは姓名・所属・部門だけ)。
 * ianseo 本体のスマホモードの「参加者を追加(過去の参加者から: チーム → 選手)」と同じ流れで使う。
 */
class Past
{
    /** @return array[] [['code' => 記録会コード, 'name' => 記録会名, 'pdo' => PDO], ...] いま開いている記録会以外。新しい記録会から */
    private static function others()
    {
        $cur = Db::hasSelection() ? realpath(Db::path()) : null;
        $out = [];
        foreach (Competitions::files() as $code => $path) {
            $code = (string)$code;
            if ($cur && realpath($path) === $cur) {
                continue;
            }
            try {
                $pdo = Db::connect($path);
                $st = $pdo->query("SELECT v FROM settings WHERE k = 'name'");
                $name = (string)$st->fetchColumn();
                $st->closeCursor();
                $out[] = ['code' => $code, 'name' => $name ?: $code, 'pdo' => $pdo, 'mtime' => (int)@filemtime($path)];
            } catch (\Throwable $e) {
                // 読めない記録会は飛ばす
            }
        }
        usort($out, function ($a, $b) {
            return $b['mtime'] <=> $a['mtime'];
        });
        return $out;
    }

    /** いまの記録会にいる選手(姓|名)の一覧 */
    private static function registered()
    {
        $have = [];
        foreach (Db::pdo()->query('SELECT family_name, given_name FROM entries') as $r) {
            $have[$r['family_name'] . '|' . $r['given_name']] = true;
        }
        return $have;
    }

    /** 所属の入力候補(すべての記録会。よく使われる順) */
    public static function affiliations($limit = 300)
    {
        $count = [];
        $dbs = array_merge([['pdo' => Db::pdo()]], self::others());
        foreach ($dbs as $d) {
            foreach ($d['pdo']->query("SELECT affiliation, COUNT(*) n FROM entries WHERE affiliation != '' GROUP BY affiliation") as $r) {
                $count[$r['affiliation']] = ($count[$r['affiliation']] ?? 0) + (int)$r['n'];
            }
        }
        arsort($count);
        return array_slice(array_keys($count), 0, $limit);
    }

    /**
     * 過去の記録会の所属(チーム)と人数。
     * @return array[] [['name' => 所属, 'count' => 人数], ...] 所属名の順
     */
    public static function teams()
    {
        $people = [];
        foreach (self::others() as $d) {
            foreach ($d['pdo']->query("SELECT affiliation, family_name, given_name FROM entries WHERE affiliation != ''") as $r) {
                $people[$r['affiliation']][$r['family_name'] . '|' . $r['given_name']] = true;
            }
        }
        ksort($people);
        $out = [];
        foreach ($people as $aff => $set) {
            $out[] = ['name' => $aff, 'count' => count($set)];
        }
        return $out;
    }

    /**
     * 過去の記録会で、その所属だった選手(同じ姓名は新しい記録会の1人だけ)。
     * @return array[] ['src' => '記録会コード:選手ID', 'family','given','affiliation','category_code','category_name','last' => 記録会名,'registered' => いまの記録会にいるか]
     */
    public static function archers($team)
    {
        $have = self::registered();
        $out = [];
        foreach (self::others() as $d) {
            $st = $d['pdo']->prepare('SELECT e.id, e.family_name, e.given_name, e.affiliation, c.code AS category_code, c.name AS category_name
                FROM entries e LEFT JOIN categories c ON c.id = e.category_id
                WHERE e.affiliation = ? ORDER BY e.family_name, e.given_name');
            $st->execute([(string)$team]);
            foreach ($st as $r) {
                $key = $r['family_name'] . '|' . $r['given_name'];
                if (isset($out[$key])) {
                    continue;   // 新しい記録会のほうを残す
                }
                $out[$key] = [
                    'src' => $d['code'] . ':' . $r['id'],
                    'family' => $r['family_name'], 'given' => $r['given_name'], 'affiliation' => $r['affiliation'],
                    'category_code' => (string)$r['category_code'], 'category_name' => (string)$r['category_name'],
                    'last' => $d['name'], 'registered' => isset($have[$key]),
                ];
            }
        }
        $list = array_values($out);
        usort($list, function ($a, $b) {
            return [$a['family'], $a['given']] <=> [$b['family'], $b['given']];
        });
        return $list;
    }

    /** '記録会コード:選手ID' から、過去の記録会の選手を読む。無ければ null */
    public static function find($src)
    {
        if (!preg_match('/^([A-Za-z0-9_-]{1,32}):(\d+)$/', (string)$src, $m)) {
            return null;
        }
        $f = Competitions::find($m[1]);
        if (!$f || (Db::hasSelection() && realpath($f['path']) === realpath(Db::path()))) {
            return null;
        }
        $st = Db::connect($f['path'])->prepare('SELECT e.family_name, e.given_name, e.affiliation, c.code AS category_code
            FROM entries e LEFT JOIN categories c ON c.id = e.category_id WHERE e.id = ?');
        $st->execute([(int)$m[2]]);
        $r = $st->fetch();
        return $r ?: null;
    }

    /**
     * 過去の記録会の選手を、いまの記録会に登録する(同じ姓名の選手がいれば飛ばす)。
     * @return array ['added' => 人数, 'skipped' => 人数]
     */
    public static function add(array $srcs)
    {
        $have = self::registered();
        $added = $skipped = 0;
        foreach ($srcs as $src) {
            $r = self::find($src);
            $cat = $r ? Categories::byCode($r['category_code']) : null;
            if (!$r || !$cat || isset($have[$r['family_name'] . '|' . $r['given_name']])) {
                $skipped++;
                continue;
            }
            Entries::save(['family_name' => $r['family_name'], 'given_name' => $r['given_name'],
                'affiliation' => $r['affiliation'], 'category_id' => $cat['id']]);
            $have[$r['family_name'] . '|' . $r['given_name']] = true;
            $added++;
        }
        return ['added' => $added, 'skipped' => $skipped];
    }
}
