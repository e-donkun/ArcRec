<?php
require __DIR__ . '/_layout.php';

use Lite\Entries;
use Lite\Results;
use Lite\Scoring;
use Lite\Settings;

$what = $_GET['what'] ?? '';
if ($what !== 'results') {
    require_admin();
}

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
        $rows = [['姓', '名', '所属', '部門', '的', 'ゼッケン']];
        foreach (Entries::all() as $e) {
            $rows[] = [$e['family_name'], $e['given_name'], $e['affiliation'], $e['category_name'], $e['target'] ? $e['target'] . $e['letter'] : '', $e['bib']];
        }
        csv_out('entries.csv', $rows);
    case 'results':
        $ends = Settings::ends();
        $head = ['部門', '順位', '的', '氏名', '所属'];
        for ($i = 1; $i <= $ends; $i++) {
            $head[] = $i . 'E';
        }
        $rows = [array_merge($head, ['合計', Settings::get('gold_label'), Settings::get('x_label')])];
        foreach (Results::byCategory() as $g) {
            foreach ($g['rows'] as $r) {
                $rows[] = array_merge(
                    [$g['category']['name'], $r['rank'], $r['target'] ? $r['target'] . $r['letter'] : '', Entries::fullName($r), $r['affiliation']],
                    $r['end_scores'],
                    [$r['hits'] ? $r['score'] : '', $r['hits'] ? $r['golds'] : '', $r['hits'] ? $r['xs'] : '']
                );
            }
        }
        csv_out('results.csv', $rows);
    case 'arrows':
        $per = Settings::arrowsPerEnd();
        $rows = [['的', '氏名', '所属', '部門', 'エンド', '矢1〜']];
        foreach (Entries::all() as $e) {
            for ($n = 1; $n <= Settings::ends(); $n++) {
                $s = Scoring::endString($e['arrowstring'], $n, $per);
                if (trim($s) === '') {
                    continue;
                }
                $row = [$e['target'] ? $e['target'] . $e['letter'] : '', Entries::fullName($e), $e['affiliation'], $e['category_name'], $n];
                foreach (str_split($s) as $c) {
                    $row[] = trim($c) === '' ? '' : Scoring::display($c);
                }
                $rows[] = $row;
            }
        }
        csv_out('arrows.csv', $rows);
    default:
        http_response_code(404);
        exit('not found');
}
