<?php
namespace Lite;

use PDO;

/**
 * 過去の大会のデータ(選手・所属)から、入力の候補を出す。
 * 読むのは、このセッションでパスワードを通した大会(と、いま開いている大会)だけ。
 * パスワードを知らない大会の名簿は読まない。
 */
class Past
{
    /** @return PDO[] 候補を探す DB(いま開いている大会を先頭に) */
    private static function dbs(array $codes = null)
    {
        $out = [Db::pdo()];
        $codes = $codes ?? (session_status() === PHP_SESSION_ACTIVE ? Auth::authorizedCodes() : []);
        $cur = Db::path();
        foreach ($codes as $code) {
            $f = Competitions::find($code);
            if ($f && realpath($f['path']) !== realpath($cur)) {
                try {
                    $out[] = Db::connect($f['path']);
                } catch (\Throwable $e) {
                    // 読めない大会は飛ばす
                }
            }
        }
        return $out;
    }

    /** 所属の候補(重複なし、よく使われる順) */
    public static function affiliations(array $codes = null, $limit = 300)
    {
        $count = [];
        foreach (self::dbs($codes) as $pdo) {
            foreach ($pdo->query("SELECT affiliation, COUNT(*) n FROM entries WHERE affiliation != '' GROUP BY affiliation") as $r) {
                $count[$r['affiliation']] = ($count[$r['affiliation']] ?? 0) + (int)$r['n'];
            }
        }
        arsort($count);
        return array_slice(array_keys($count), 0, $limit);
    }

    /**
     * 過去の選手の候補。姓・名・所属のどれかに $q が含まれるもの。いまの大会に同じ姓名の選手がいれば除く。
     * @return array[] ['family','given','affiliation','category_code']
     */
    public static function athletes($q, array $codes = null, $limit = 8)
    {
        $q = trim((string)$q);
        if ($q === '') {
            return [];
        }
        $have = [];
        foreach (Db::pdo()->query('SELECT family_name, given_name FROM entries') as $r) {
            $have[$r['family_name'] . '|' . $r['given_name']] = true;
        }
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        $out = [];
        foreach (self::dbs($codes) as $i => $pdo) {
            $st = $pdo->prepare("SELECT e.family_name, e.given_name, e.affiliation, c.code AS category_code
                FROM entries e LEFT JOIN categories c ON c.id = e.category_id
                WHERE e.family_name LIKE :q ESCAPE '\\' OR e.given_name LIKE :q ESCAPE '\\' OR e.affiliation LIKE :q ESCAPE '\\'
                    OR (e.family_name || e.given_name) LIKE :q ESCAPE '\\'
                ORDER BY e.id DESC LIMIT 50");
            $st->execute([':q' => $like]);
            foreach ($st as $r) {
                $key = $r['family_name'] . '|' . $r['given_name'];
                if (isset($have[$key]) || isset($out[$key])) {
                    continue;
                }
                $out[$key] = [
                    'family' => $r['family_name'], 'given' => $r['given_name'],
                    'affiliation' => $r['affiliation'], 'category_code' => (string)$r['category_code'],
                ];
            }
        }
        return array_slice(array_values($out), 0, $limit);
    }
}
