<?php
namespace Lite;

/**
 * 部門別の順位。合計点 -> ゴールド(10+X)数 -> X数 の順。全部同じなら同順位(SO待ち)。
 */
class Results
{
    /**
     * @return array [ ['category'=>row, 'rows'=>[...]] , ... ]
     */
    public static function byCategory()
    {
        $per = Settings::arrowsPerEnd();
        $ends = Settings::ends();
        $groups = [];
        foreach (Categories::all() as $c) {
            $groups[$c['id']] = ['category' => $c, 'rows' => []];
        }
        $groups[0] = ['category' => ['id' => 0, 'name' => '部門未設定', 'code' => '', 'targetface' => 5], 'rows' => []];

        foreach (Entries::all(false) as $e) {
            if ($e['status']) {
                continue; // 欠場
            }
            [$score, $golds, $xs, $hits] = Scoring::total($e['arrowstring']);
            $endScores = [];
            for ($i = 1; $i <= $ends; $i++) {
                $s = Scoring::endString($e['arrowstring'], $i, $per);
                $endScores[] = trim($s) === '' ? null : Scoring::total($s)[0];
            }
            $e['score'] = $score;
            $e['golds'] = $golds;
            $e['xs'] = $xs;
            $e['hits'] = $hits;
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
            foreach ($g['rows'] as $i => &$r) {
                $sig = $r['score'] . '/' . $r['golds'] . '/' . $r['xs'];
                if ($sig !== $prev) {
                    $rank = $i + 1;
                    $prev = $sig;
                }
                $r['rank'] = $r['hits'] ? $rank : null;
                // 同点・同ゴールド・同Xの選手がいるか(シュートオフ判断用)
                $r['tie'] = false;
            }
            unset($r);
            // 同順位の印(1位同士のタイなど)
            $counts = [];
            foreach ($g['rows'] as $r) {
                if ($r['rank'] !== null) {
                    $counts[$r['rank']] = ($counts[$r['rank']] ?? 0) + 1;
                }
            }
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
            $h = Scoring::total($e['arrowstring'])[3];
            $hits += $h;
            if ($h >= $total) {
                $done++;
            }
        }
        return ['archers' => $n, 'finished' => $done, 'hits' => $hits, 'all_arrows' => $n * $total];
    }
}
