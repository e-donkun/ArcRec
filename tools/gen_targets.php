<?php
// install.sql の Targets 表から、Lite で使う的面の定義(src/TargetFaces.data.php)を生成する開発用スクリプト。
// 使い方: php tools/gen_targets.php /path/to/ianseo/Install/install.sql > src/TargetFaces.data.php
$sql = file_get_contents($argv[1] ?? __DIR__ . '/../../../../Install/install.sql');
$ids = [5, 10, 9, 1, 2, 3, 4];
$letterPoint = require __DIR__ . '/../src/LetterPoint.php';

if (!preg_match('/INSERT INTO `Targets` \(([^)]*)\) VALUES(.*?);\s*$/ms', $sql, $m)) {
    fwrite(STDERR, "Targets INSERT not found\n");
    exit(1);
}
$cols = array_map(function ($c) { return trim($c, " `\n"); }, explode(',', $m[1]));
preg_match_all('/\((\d+), (.*?)\)(?:,|$)/m', $m[2], $rows, PREG_SET_ORDER);

function dark($rgb) {
    $l = 0.2126 * pow($rgb[0] / 255, 2.2) + 0.7152 * pow($rgb[1] / 255, 2.2) + 0.0722 * pow($rgb[2] / 255, 2.2);
    return (int)(($l + 0.05) / 0.05) <= 5; // IsDarkBackground と同じ
}

$out = [];
foreach ($rows as $row) {
    $id = (int)$row[1];
    if (!in_array($id, $ids)) continue;
    $vals = str_getcsv('(' . $row[1] . ', ' . $row[2] . ')', ',', "'");
    $vals[0] = ltrim($vals[0], '(');
    $vals = array_map('trim', $vals);
    $rec = array_combine(array_slice($cols, 0, count($vals)), array_slice($vals, 0, count($cols)));
    $lp = [];
    foreach (array_merge(range('9', '1'), range('Z', 'A')) as $k) {
        if (!empty($rec[$k . '_size'])) {
            $c = $rec[$k . '_color'];
            $rgb = [min(255, round(hexdec(substr($c, 0, 2)) * 1.5)), min(255, round(hexdec(substr($c, 2, 2)) * 1.5)), min(255, round(hexdec(substr($c, -2)) * 1.5))];
            $lp[$k] = ['letter' => (string)$k, 'point' => $letterPoint[$k]['P'], 'num' => (int)$letterPoint[$k]['N'], 'bg' => '#' . $c, 'fg' => dark($rgb) ? '#FFFFFF' : '#000000'];
        }
    }
    if (empty($lp['A'])) {
        $lp['A'] = ['letter' => 'A', 'point' => 'M', 'num' => 0, 'bg' => '#999999', 'fg' => '#000000'];
    }
    uksort($lp, function ($a, $b) use ($letterPoint) {
        return $letterPoint[strtoupper($b)]['W'] <=> $letterPoint[strtoupper($a)]['W'];
    });
    $out[$id] = ['descr' => $rec['TarDescr'], 'letters' => array_values($lp)];
}
echo "<?php\n// tools/gen_targets.php で生成 (Install/install.sql の Targets 表より)\nreturn ", var_export($out, true), ";\n";
