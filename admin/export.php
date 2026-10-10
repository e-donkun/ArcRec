<?php
require __DIR__ . '/_layout.php';

use Lite\Entries;
use Lite\Results;
use Lite\Scoring;
use Lite\Settings;

$what = $_GET['what'] ?? '';
require_admin();   // CSV は管理者だけ(一般向けの記録会結果には CSV を出さない)

/** Excel(日本語環境)で文字化けしないよう BOM 付き UTF-8 の CSV で出す */
function csv_out($filename, array $rows)
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    foreach ($rows as $r) {
        fputcsv($out, $r, ',', '"', '');
    }
    fclose($out);
    exit;
}

switch ($what) {
    case 'entries':
        $rows = [['姓', '名', '所属', '部門', '的']];
        foreach (Entries::all() as $e) {
            $rows[] = [$e['family_name'], $e['given_name'], $e['affiliation'], $e['category_name'], $e['target'] ? $e['target'] . $e['letter'] : ''];
        }
        csv_out('entries.csv', $rows);
    case 'results':
        // 順位 | 的番号 | 選手氏名 | 所属 | 総合計 | 第1優先 | 第2優先 | (空) | 距離1のエンド 1〜n | 距離1の計 | (空) | 距離2 …
        $ends = Settings::ends();
        $nd = Settings::distances();
        $head = ['順位', '的番号', '選手氏名', '所属', '総合計', Settings::tieLabel(1), Settings::tieLabel(2)];
        for ($d = 1; $d <= $nd; $d++) {
            $head[] = '';
            for ($i = 1; $i <= $ends; $i++) {
                $head[] = (string)$i;
            }
            $head[] = ($nd > 1 ? Settings::distanceName($d) : '') . '計';
        }
        $rows = [$head];
        foreach (Results::byCategory() as $g) {
            foreach ($g['rows'] as $r) {
                $row = [$r['absent'] ? '欠' : ($r['rank'] ?? ''), $r['target'] ? $r['target'] . $r['letter'] : '', Entries::fullName($r), $r['affiliation'],
                    $r['hits'] ? $r['score'] : '', $r['hits'] ? $r['golds'] : '', $r['hits'] ? $r['xs'] : ''];
                for ($d = 1; $d <= $nd; $d++) {
                    $row[] = '';
                    foreach (array_slice($r['end_scores'], ($d - 1) * $ends, $ends) as $v) {
                        $row[] = $v === null ? '' : $v;
                    }
                    $row[] = $r['dist_scores'][$d] ?? '';
                }
                $rows[] = $row;
            }
        }
        csv_out('results.csv', $rows);
    case 'arrows':
        $per = Settings::arrowsPerEnd();
        $rows = [['的', '氏名', '所属', '部門', '距離', 'エンド', '矢1〜']];
        foreach (Entries::all() as $e) {
            for ($d = 1; $d <= Settings::distances(); $d++) {
                $arrows = Entries::arrows($e, $d);
                for ($n = 1; $n <= Settings::ends(); $n++) {
                    $s = Scoring::endString($arrows, $n, $per);
                    if (trim($s) === '') {
                        continue;
                    }
                    $row = [$e['target'] ? $e['target'] . $e['letter'] : '', Entries::fullName($e), $e['affiliation'], $e['category_name'], $d, $n];
                    foreach (str_split($s) as $c) {
                        $row[] = trim($c) === '' ? '' : Scoring::display($c);
                    }
                    $rows[] = $row;
                }
            }
        }
        csv_out('arrows.csv', $rows);
    default:
        http_response_code(404);
        exit('not found');
}
