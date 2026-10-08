<?php
namespace Lite;

/**
 * 部門別の順位(WA の予選順位の付け方)。
 * 全距離の合計点 -> 10+X の数 -> X の数。すべて同じなら同順位(シュートオフで決める)。
 */
class Results
{
    /**
     * @return array [ ['category'=>row, 'rows'=>[...]] , ... ]
     *   rows の各要素は選手の行に加えて:
     *     dist_scores[d] 距離ごとの合計(未射なら null) / end_scores 全エンドの小計(距離1のエンド…, 距離2のエンド…)
     *     score golds xs hits rank tie
     */
    public static function byCategory()
    {
        $per = Settings::arrowsPerEnd();
        $ends = Settings::ends();
        $nd = Settings::distances();
        $groups = [];
        foreach (Categories::all() as $c) {
            $groups[$c['id']] = ['category' => $c, 'rows' => []];
        }
        $groups[0] = ['category' => ['id' => 0, 'name' => '部門未設定', 'code' => ''], 'rows' => []];

        foreach (Entries::all(false) as $e) {
            if ($e['status']) {
                continue; // 欠場
            }
            $score = $golds = $xs = $hits = 0;
            $distScores = [];
            $endScores = [];
            for ($d = 1; $d <= $nd; $d++) {
                $s = Entries::arrows($e, $d);
                list($ds, $dg, $dx, $dh) = Scoring::total($s);
                $score += $ds;
                $golds += $dg;
                $xs += $dx;
                $hits += $dh;
                $distScores[$d] = $dh ? $ds : null;
                for ($i = 1; $i <= $ends; $i++) {
                    $es = Scoring::endString($s, $i, $per);
                    $endScores[] = trim($es) === '' ? null : Scoring::total($es)[0];
                }
            }
            $e['score'] = $score;
            $e['golds'] = $golds;
            $e['xs'] = $xs;
            $e['hits'] = $hits;
            $e['dist_scores'] = $distScores;
            $e['end_scores'] = $endScores;
            $key = isset($groups[(int)$e['category_id']]) ? (int)$e['category_id'] : 0;
            $groups[$key]['rows'][] = $e;
        }

        foreach ($groups as $k => &$g) {
            usort($g['rows'], function ($a, $b) {
                return [$b['score'], $b['golds'], $b['xs']] <=> [$a['score'], $a['golds'], $a['xs']]
                    ?: $a['target'] <=> $b['target'] ?: $a['id'] <=> $b['id'];
            });
            $rank = 0;
            $prev = null;
            $counts = [];
            foreach ($g['rows'] as $i => &$r) {
                $sig = $r['score'] . '/' . $r['golds'] . '/' . $r['xs'];
                if ($sig !== $prev) {
                    $rank = $i + 1;
                    $prev = $sig;
                }
                $r['rank'] = $r['hits'] ? $rank : null;
                if ($r['rank'] !== null) {
                    $counts[$r['rank']] = ($counts[$r['rank']] ?? 0) + 1;
                }
            }
            unset($r);
            // 同順位(合計・10+X・X が全部同じ)の印。シュートオフ等で決める
            foreach ($g['rows'] as &$r) {
                $r['tie'] = $r['rank'] !== null && ($counts[$r['rank']] ?? 0) > 1;
            }
            unset($r);
            if (!$g['rows'] && $k === 0) {
                unset($groups[$k]);
            }
        }
        unset($g);
        return array_values($groups);
    }

    /** スコアが最後に更新された日時(端末からでも管理画面の訂正でも)。まだ無ければ null */
    public static function lastUpdate()
    {
        $v = Db::pdo()->query("SELECT MAX(updated_at) FROM entries WHERE status = 0 AND updated_at != ''")->fetchColumn();
        return $v ?: null;
    }

    /** 画面に出す文字列。「10/08 14:32:05(3分前)」 */
    public static function lastUpdateText()
    {
        $v = self::lastUpdate();
        if (!$v) {
            return 'まだ得点が届いていません';
        }
        $t = strtotime($v);
        $ago = max(0, time() - $t);
        $rel = $ago < 60 ? $ago . '秒前' : ($ago < 3600 ? intdiv($ago, 60) . '分前' : ($ago < 86400 ? intdiv($ago, 3600) . '時間前' : intdiv($ago, 86400) . '日前'));
        return date('m/d H:i:s', $t) . '(' . $rel . ')';
    }

    /** 進行状況: 全選手の射数 / 全射数 */
    public static function progress()
    {
        $total = Settings::totalArrows();
        $n = 0;
        $done = 0;
        $hits = 0;
        foreach (Entries::all() as $e) {
            if ($e['status']) {
                continue;
            }
            $n++;
            $h = Scoring::total(Entries::allArrows($e))[3];
            $hits += $h;
            if ($h >= $total) {
                $done++;
            }
        }
        return ['archers' => $n, 'finished' => $done, 'hits' => $hits, 'all_arrows' => $n * $total];
    }
}
