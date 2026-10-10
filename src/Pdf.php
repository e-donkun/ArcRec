<?php
namespace Lite;

if (!defined('K_TCPDF_EXTERNAL_CONFIG')) {
    define('K_TCPDF_EXTERNAL_CONFIG', true);
    define('K_PATH_CACHE', rtrim(sys_get_temp_dir(), '/') . '/');
}
require_once LITE_ROOT . '/lib/tcpdf/tcpdf.php';

/**
 * スコアカードと結果の PDF。日本語は小塚ゴシック(kozgopromedium。ianseo 本体と同じ CID フォントで、PDF には埋め込まず表示側のフォントを使う)。
 */
class Pdf extends \TCPDF
{
    const FONT = 'kozgopromedium';

    private $footerText = '';

    public function __construct($title)
    {
        parent::__construct('P', 'mm', 'A4', true, 'UTF-8', false);
        $this->setPrintHeader(false);
        $this->setPrintFooter(true);
        $this->SetMargins(15, 12, 15);
        $this->SetAutoPageBreak(false);
        $this->SetCreator('ianseo-lite');
        $this->SetTitle($title);
        $this->footerText = Settings::title() . '  /  出力 ' . date('Y-m-d H:i');
        $this->setFontSubsetting(true);
        $this->SetFont(self::FONT, '', 10);
    }

    public function Footer()
    {
        $this->SetY(-9);
        $this->SetFont(self::FONT, '', 7);
        $this->SetTextColor(120);
        $this->Cell(0, 4, $this->footerText, 0, 0, 'L');
        $this->Cell(0, 4, $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages(), 0, 0, 'R');
        $this->SetTextColor(0);
    }

    private function f($size)
    {
        $this->SetFont(self::FONT, '', $size);
    }

    /** 数字・英字だけの欄(得点・的番号など)は Helvetica(本体と同じ字形) */
    private function n($size, $style = '')
    {
        $this->SetFont('helvetica', $style, $size);
    }

    // ================================================================ スコアカード(ianseo 本体の予選スコアカードと同じ形)

    /**
     * 白紙: 1 距離 1 枚。的ごとにページを分け、立ち数で 1 ページの枚数を決める(本体と同じ)
     *   1〜2 人 → A4 横に 2 枚、3・5・6 人 → A4 横に 3 枚、4 人 → A4 縦に 2×2。ページ下(縦は中央)にその的の QR。
     * 素点入力済: 1 人 1 ページ(A4 横)に、距離ごとのカードを並べる。署名欄は出さない(本体と同じ)。
     * @param array $entries Entries::all() 形式の配列
     */
    public static function scorecards(array $entries, $filled, $baseUrl = null)
    {
        $pdf = new self('スコアカード');
        $pdf->setPrintFooter(false);
        $pdf->SetLineWidth(0.1);
        $pdf->SetDrawColor(51);
        $nd = Settings::distances();
        if ($filled) {
            $lay = self::cardLayout(2, false);
            foreach ($entries as $e) {
                $pdf->AddPage($lay['o']);
                $pdf->pageHeader();
                $prev = 0;   // 前の距離までの合計(2 距離のときは「総計」の列に通しの合計を出す)
                for ($d = 1; $d <= $nd; $d++) {
                    list($x, $y, $w, $h) = $lay['slots'][$d - 1];
                    $pdf->card($x, $y, $w, $h, $e, $d, true, $nd > 1 ? $prev : null);
                    $prev += Scoring::total(Entries::arrows($e, $d))[0];
                }
            }
        } else {
            $lay = self::cardLayout(Settings::perTarget(), (bool)$baseUrl);
            $n = count($lay['slots']);
            // ページ: [的番号, [スロット => 選手]]。的のない選手は最後に詰めて並べる
            $pages = [];
            $loose = [];
            $letters = array_flip(Settings::letters());
            foreach ($entries as $e) {
                $li = $letters[$e['letter']] ?? null;
                if (!$e['target'] || $li === null) {
                    $loose[] = $e;
                    continue;
                }
                $pages[$e['target'] . '-' . intdiv($li, $n)]['t'] = (int)$e['target'];
                $pages[$e['target'] . '-' . intdiv($li, $n)]['cards'][$li % $n] = $e;
            }
            ksort($pages, SORT_NATURAL);
            foreach (array_chunk($loose, $n) as $chunk) {
                $pages[] = ['t' => 0, 'cards' => $chunk];
            }
            for ($d = 1; $d <= $nd; $d++) {
                foreach ($pages as $p) {
                    $pdf->AddPage($lay['o']);
                    $pdf->pageHeader();
                    foreach ($p['cards'] as $k => $e) {
                        list($x, $y, $w, $h) = $lay['slots'][$k];
                        $pdf->card($x, $y, $w, $h, $e, $d, false);
                    }
                    if ($baseUrl && $p['t']) {
                        // この的を端末に読ませる QR(2 距離のときは、その距離を指定した QR)
                        list($qx, $qy, $qs) = $lay['qr'];
                        $mode = $nd > 1 ? $d : 0;
                        $pdf->Rect($qx - 1.5, $qy - 1.5, $qs + 3, $qs + 3);
                        $pdf->write2DBarcode(Connect::targetPayload($baseUrl, $p['t'], $mode), 'QRCODE,M', $qx, $qy, $qs, $qs,
                            ['border' => false, 'padding' => 0, 'fgcolor' => [0, 0, 0], 'bgcolor' => false], 'N');
                        $pdf->f(7);
                        $pdf->SetXY($qx - 10, $qy + $qs + 1.6);
                        $pdf->Cell($qs + 20, 3, Connect::targetLabel($p['t'], $mode), 0, 0, 'C');
                    }
                }
            }
        }
        if (!$pdf->getNumPages()) {
            $pdf->AddPage();
            $pdf->f(12);
            $pdf->Cell(0, 10, '出力する参加者がいません', 0, 1);
        }
        return $pdf->Output('scorecards.pdf', 'S');
    }

    /** 1 ページのカードの位置 [x, y, 幅, 高さ] と QR の位置 [x, y, 大きさ](本体の CreateSessionScorecard と同じ割り付け) */
    private static function cardLayout($perTarget, $qr)
    {
        $top = 26;
        $qs = 25;
        if ($perTarget === 4) {
            // A4 縦に 2×2。QR は上下のカードの間
            $w = 90;
            $gap = $qr ? $qs + 5 : 8;
            $h = (297 - $top - 8 - $gap) / 2;
            $y2 = $top + $h + $gap;
            return ['o' => 'P', 'slots' => [[10, $top, $w, $h], [110, $top, $w, $h], [10, $y2, $w, $h], [110, $y2, $w, $h]],
                'qr' => [(210 - $qs) / 2, $top + $h + ($gap - $qs) / 2, $qs]];
        }
        $h = $qr ? 210 - $top - $qs - 16 : 210 - $top - 8;
        $qrPos = [(297 - $qs) / 2, $top + $h + 5, $qs];
        if ($perTarget <= 2) {
            $w = (297 - 90) / 2;
            return ['o' => 'L', 'slots' => [[30, $top, $w, $h], [60 + $w, $top, $w, $h]], 'qr' => $qrPos];
        }
        $w = (297 - 40) / 3;
        return ['o' => 'L', 'slots' => [[10, $top, $w, $h], [20 + $w, $top, $w, $h], [30 + 2 * $w, $top, $w, $h]], 'qr' => $qrPos];
    }

    /** ページ上の見出し(記録会名と記録会コード・競技形式) */
    private function pageHeader()
    {
        $this->SetTextColor(0);
        $this->f(15);
        $this->SetXY(10, 7);
        $this->Cell(270, 7, Settings::title(), 0, 0, 'L');
        $this->f(9);
        $this->SetXY(10, 15);
        $this->Cell(270, 4.5, '(' . Settings::code() . ')  ' . Settings::roundLabel(), 0, 0, 'L');
    }

    /**
     * スコアカード 1 枚(1 距離)。寸法は本体の DrawScoreNew と同じ考え方:
     * 列の幅 CW = 幅 / (1 行の矢数 + 5)。6 射のエンドは 3 射ずつ 2 行にして、エンド番号を 2 行ぶんにまとめる。
     * 列: エンド 0.8 | 矢 1 × n | 小計 | 累計 | 第1優先 0.7 | 第2優先 0.7
     * $prev(前の距離までの合計)を渡すと、本体の「総計の列つき」と同じ形: 矢 0.9、小計・累計を詰めて、ピンクの「総計」列 1.1
     * (距離をまたいだ通しの合計)を足し、右上に前の距離までの総計の枠を出す。
     */
    private function card($x0, $y0, $W, $H, array $e, $d, $filled, $prev = null)
    {
        $grand = $prev !== null;
        $per = Settings::arrowsPerEnd();
        $ends = Settings::ends();
        $split = $per === 6 && $ends <= 7;
        $cols = $split ? 3 : $per;
        $rowsPerEnd = $split ? 2 : 1;
        $nRows = $ends * $rowsPerEnd;
        $CW = floor(10 * $W / ($cols + 5)) / 10;
        $W = $CW * ($cols + 5);
        $endW = 0.8 * $CW;
        $arW = ($grand ? 0.9 : 1) * $CW;
        $grandW = 1.1 * $CW;
        $totCellW = ($grand ? 1 : 1.4) * $CW;
        $sumW = $totCellW * ($per >= 6 ? 3 / 4 : 1);
        $totW = $totCellW * ($per >= 6 ? 5 / 4 : 1);
        $tieW = 0.7 * $CW;
        $sig = $filled ? 0 : 12;
        $tableTop = $y0 + 14;
        $CH = floor(10 * min(10, ($H - 14 - $sig - 4) / ($nRows + 2.2))) / 10;
        $std = min(10, $CH * 2);
        $fill = function ($light = false) {
            $this->SetFillColor($light ? 248 : 232);
        };

        // ---- 名前・所属と、右の的番号・部門の枠
        $boxW = 1.4 * $CW;
        $fill();
        $this->SetXY($x0 + $W - $boxW, $y0);
        $this->n(17, 'B');
        $this->Cell($boxW, 8.75, $e['target'] ? $e['target'] . $e['letter'] : '', 'B', 0, 'C', true);
        $this->SetXY($x0 + $W - $boxW, $y0 + 9);
        $this->n(7, 'B');
        $this->Cell($boxW, 4, (string)($e['category_code'] ?: $e['category_name']), 0, 0, 'C', true);
        $lineW = $W - $boxW - 4 - ($grand ? $grandW + 1 : 0);
        if ($grand && $d > 1) {
            // 前の距離までの総計
            $gx = $x0 + $W - $boxW - $grandW - 0.5;
            $this->SetFillColor(255, 232, 232);
            $this->SetXY($gx, $y0 + 2);
            $this->f(6.5);
            $this->Cell($grandW, 4.5, '総計', 1, 0, 'C', true);
            $this->SetXY($gx, $y0 + 6.5);
            $this->n(8);
            $this->Cell($grandW, 5, (string)$prev, 1, 0, 'C', true);
        }
        foreach ([[2, '選手名:', Entries::fullName($e), 11], [8.6, '所属先:', (string)$e['affiliation'], 9]] as $r) {
            $this->SetXY($x0 + 2, $y0 + $r[0]);
            $this->f(6.5);
            $this->Cell(10, 5, $r[1], 'B', 0, 'L');
            $this->f($r[3]);
            $this->Cell($lineW - 10, 5, $r[2], 'B', 0, 'L');
        }

        // ---- 表の見出し(1 行目: 距離名 | 矢番号 | 帯、2 行目: 小計・累計・第1/第2優先)
        $y = $tableTop;
        $fill(true);
        $this->SetXY($x0, $y);
        $this->f(min(8, $std * 0.8));
        $this->Cell($endW, $CH, Settings::distances() > 1 ? Settings::distanceName($d) : '', 0, 0, 'C', true);
        $fill();
        $this->n($std, 'B');
        for ($a = 1; $a <= $cols; $a++) {
            $this->Cell($arW, $CH, (string)$a, 1, 0, 'C', true);
        }
        $rx = $this->GetX();
        $restW = $sumW + $totW + ($grand ? $grandW : 0) + 2 * $tieW;
        $this->Cell($restW, $CH / 2, '', 1, 0, 'C', true);
        $this->SetXY($rx, $y + $CH / 2);
        $this->f(max(5, $std * 0.65));
        $this->Cell($sumW, $CH / 2, '小計', 1, 0, 'C', true);
        $this->Cell($totW, $CH / 2, '累計', 1, 0, 'C', true);
        if ($grand) {
            $this->SetFillColor(255, 232, 232);
            $this->Cell($grandW, $CH / 2, '総計', 1, 0, 'C', true);
            $fill();
        }
        $this->n(max(5, $std * 0.65));
        $this->Cell($tieW, $CH / 2, Settings::tieLabel(1), 1, 0, 'C', true);
        $this->Cell($tieW, $CH / 2, Settings::tieLabel(2), 1, 0, 'C', true);
        $y += $CH;

        // ---- エンドの行
        $arrows = Entries::arrows($e, $d);
        $run = 0;
        $show = function ($v) use ($filled) {
            return $filled && $v !== null && $v !== 0 ? (string)$v : '';
        };
        for ($i = 0; $i < $nRows; $i++) {
            $end = intdiv($i, $rowsPerEnd) + 1;
            $half = $i % $rowsPerEnd;
            $s = Scoring::endString($arrows, $end, $per);
            $part = (string)substr(str_pad($s, $per), $half * $cols, $cols);
            $has = $filled && trim($part) !== '';
            $this->SetXY($x0, $y);
            if ($half === 0) {
                $fill();
                $this->n($std, 'B');
                $this->Cell($endW, $CH * $rowsPerEnd, (string)$end, 1, 0, 'C', true);
            } else {
                $this->SetX($x0 + $endW);
            }
            $this->n($std * 0.9);
            for ($a = 0; $a < $cols; $a++) {
                $ch = $part[$a] ?? ' ';
                $this->Cell($arW, $CH, $has && trim($ch) !== '' ? Scoring::display($ch) : '', 1, 0, 'C');
            }
            list($sc, $g, $xx) = $has ? Scoring::total($part) : [0, 0, 0];
            $this->Cell($sumW, $CH, $has ? (string)$sc : '', 1, 0, 'C');
            $endDone = $filled && trim($s) !== '' && $half === $rowsPerEnd - 1;
            if ($endDone) {
                $endTotal = Scoring::total($s)[0];
                $run += $endTotal;
            }
            if ($split && $half === 0) {
                // 6 射のエンドの 1 行目: 累計の欄は斜線
                $cx = $this->GetX();
                $this->Cell($totW, $CH, '', 1, 0, 'C');
                $this->Line($cx, $y, $cx + $totW, $y + $CH);
                $this->Line($cx, $y + $CH, $cx + $totW, $y);
            } elseif ($split) {
                // 2 行目: エンドの合計 | 累計
                $this->Cell($totCellW / 2, $CH, $endDone ? (string)$endTotal : '', 1, 0, 'C');
                $this->Cell($totCellW * 3 / 4, $CH, $endDone ? (string)$run : '', 1, 0, 'C');
            } else {
                $this->Cell($totW, $CH, $endDone ? (string)$run : '', 1, 0, 'C');
            }
            if ($grand) {
                // 総計(前の距離からの通しの合計)。6 射のエンドの 1 行目は斜線
                $this->SetFillColor(255, 232, 232);
                $cx = $this->GetX();
                $this->Cell($grandW, $CH, $endDone ? (string)($prev + $run) : '', 1, 0, 'C', true);
                if ($split && $half === 0) {
                    $this->Line($cx, $y, $cx + $grandW, $y + $CH);
                    $this->Line($cx, $y + $CH, $cx + $grandW, $y);
                }
                $fill();
            }
            $this->n($std * 0.8);
            $this->Cell($tieW, $CH, $show($has ? $g : null), 1, 0, 'C');
            $this->Cell($tieW, $CH, $show($has ? $xx : null), 1, 0, 'C');
            $y += $CH;
        }

        // ---- 総計の行(累計の列の下に合計、第1/第2優先の下にそれぞれの数)
        list($tScore, $tGold, $tX, $hits) = Scoring::total($arrows);
        $boxTotW = $split ? $totCellW * 3 / 4 : $totW;
        $bx = $x0 + $W - 2 * $tieW - ($grand ? $grandW : 0) - $boxTotW;
        $this->SetXY($x0, $y);
        $this->f(11);
        $this->Cell($bx - $x0 - 2, $CH, '総計', 0, 0, 'R');
        $this->SetX($bx);
        $this->n(11, 'B');
        $has = $filled && $hits;
        $this->Cell($boxTotW, $CH, $has ? (string)$tScore : '', 1, 0, 'C');
        if ($grand) {
            $this->SetFillColor(255, 232, 232);
            $this->Cell($grandW, $CH, $has ? (string)($prev + $tScore) : '', 1, 0, 'C', true);
        }
        $this->n(9, 'B');
        $this->Cell($tieW, $CH, $has ? (string)$tGold : '', 1, 0, 'C');
        $this->Cell($tieW, $CH, $has ? (string)$tX : '', 1, 0, 'C');

        // ---- 署名欄(白紙のとき)
        if (!$filled) {
            $sy = $y0 + $H - 4;
            $this->SetDrawColor(120);
            $this->f(6);
            $this->SetXY($x0 + 4, $sy);
            $this->Cell($W / 2 - 7, 3, '選手名', 'T', 0, 'C');
            $this->SetX($this->GetX() + 6);
            $this->Cell($W / 2 - 7, 3, '記録者', 'T', 0, 'C');
            $this->SetDrawColor(51);
        }
    }

    // ================================================================ 的のQR一覧

    /** 的ごとの接続QR(的に貼って、端末に読ませる) */
    /** @param int $distance 0 = 指定無し、1/2 = その距離のQR(Connect::distanceModes) */
    public static function qrSheet($baseUrl, $distance = 0)
    {
        $pdf = new self('的QRコード');
        $modeName = $distance > 0 ? (Connect::distanceModes()[$distance] ?? '') : (Settings::distances() > 1 ? '指定無し' : '');
        $targets = Entries::targets();
        $cols = 3;
        $rows = 4;
        $cw = 60;
        $ch = 66;
        $i = 0;
        foreach ($targets as $t) {
            if ($i % ($cols * $rows) === 0) {
                $pdf->AddPage();
                $pdf->f(9);
                $pdf->SetXY(15, 8);
                $pdf->Cell(180, 5, Settings::title() . ($modeName !== '' ? '  [' . $modeName . ']' : '') . '  端末で読み取って的を選びます', 0, 0, 'L');
            }
            $k = $i % ($cols * $rows);
            $x = 15 + ($k % $cols) * $cw;
            $y = 16 + intdiv($k, $cols) * $ch;
            $pdf->SetLineStyle(['width' => 0.2, 'dash' => '1.5,1.5', 'color' => [170, 170, 170]]);
            $pdf->Rect($x, $y, $cw, $ch);
            $pdf->SetLineStyle(['width' => 0.2, 'dash' => 0, 'color' => [0, 0, 0]]);
            $pdf->write2DBarcode(Connect::targetPayload($baseUrl, $t, $distance), 'QRCODE,M', $x + 8, $y + 4, 44, 44, ['border' => false, 'padding' => 0], 'N');
            $pdf->f(22);
            $pdf->SetXY($x, $y + 48);
            $pdf->Cell($cw, 10, Connect::targetLabel($t, $distance), 0, 0, 'C');
            // 切り離して的に貼っても、どの記録会のQRかわかるように
            $pdf->f(8);
            $pdf->SetXY($x + 2, $y + 58.5);
            $pdf->Cell($cw - 4, 5, mb_strimwidth(Settings::title(), 0, 44, '…'), 0, 0, 'C');
            $i++;
        }
        if (!$i) {
            $pdf->AddPage();
            $pdf->f(12);
            $pdf->Cell(0, 10, '的番号が割り当てられた選手がいません', 0, 1);
        }
        return $pdf->Output('target-qr.pdf', 'S');
    }

    // ================================================================ 結果

    /**
     * 結果(A4 縦の成績表)。部門ごとに、順位 | 選手氏名 | 所属 | 距離ごと(前半・後半)と合計の 得点・第1優先・第2優先。
     * @param array $groups Results::byCategory() の戻り値
     */
    public static function results(array $groups)
    {
        $pdf = new self('結果');
        $pdf->SetLineWidth(0.2);
        $pdf->SetDrawColor(0);
        $nd = Settings::distances();
        // 列の幅(合わせて 190mm。選手氏名と所属は同じ幅)。1 距離のときは合計だけ
        $pdf->cols = $nd > 1
            ? ['rank' => 10, 'name' => 39, 'aff' => 39, 'grp' => [15, 9.5, 9.5]]
            : ['rank' => 12, 'name' => 62.5, 'aff' => 62.5, 'grp' => [23, 15, 15]];
        $pdf->groupNames = $nd > 1 ? [Settings::distanceName(1), Settings::distanceName(2), '合計'] : ['合計'];
        $pdf->AddPage();
        $pdf->f(16);
        $pdf->SetXY(10, 10);
        $pdf->Cell(190, 8, Settings::title(), 0, 1, 'C');
        $pdf->f(9);
        // 点数の最終更新(まだ無ければ出さない)。欠場以外の全員が確定していたら「確定記録」
        $upd = Results::lastUpdate();
        $active = array_filter(Entries::all(), function ($e) {
            return !$e['status'];
        });
        $fixed = $active && !array_filter($active, function ($e) {
            return !$e['locked'];
        });
        $pdf->Cell(190, 5, Settings::roundLabel() . ($upd ? '（' . ($fixed ? '確定記録' : '最終更新') . '：' . substr($upd, 0, 16) . '）' : ''), 0, 1, 'C');
        $pdf->SetY($pdf->GetY() + 3);
        foreach ($groups as $g) {
            $pdf->resultsCategory($g);
        }
        return $pdf->Output('results.pdf', 'S');
    }

    private $cols = [];
    private $groupNames = [];
    const RES_ROW = 5.6;
    const RES_BOTTOM = 284;

    /** 見出し(2 行): 順位・選手氏名・所属は 2 行ぶん、距離と合計は 得点・第1優先・第2優先 に分ける */
    private function resultsHead()
    {
        $h = 5;
        $c = $this->cols;
        $y = $this->GetY();
        $this->f(10);
        $this->SetXY(10, $y);
        $this->Cell($c['rank'], 2 * $h, '', 1, 0, 'C');
        $this->Cell($c['name'], 2 * $h, '選手氏名', 1, 0, 'C');
        $this->Cell($c['aff'], 2 * $h, '所　属', 1, 0, 'C');
        $gw = array_sum($c['grp']);
        foreach ($this->groupNames as $i => $name) {
            $x = 10 + $c['rank'] + $c['name'] + $c['aff'] + $i * $gw;
            $this->f(10);
            $this->SetXY($x, $y);
            $this->Cell($gw, $h, $name, 1, 0, 'C');
            $this->SetXY($x, $y + $h);
            $this->Cell($c['grp'][0], $h, '得点', 1, 0, 'C');
            $this->n(9);
            $this->Cell($c['grp'][1], $h, Settings::tieLabel(1), 1, 0, 'C');
            $this->Cell($c['grp'][2], $h, Settings::tieLabel(2), 1, 0, 'C');
        }
        $this->SetXY(10, $y + 2 * $h);
    }

    private function resultsCategory(array $g)
    {
        $rows = $g['rows'];
        if (!$rows) {
            return;
        }
        $nd = Settings::distances();
        $c = $this->cols;
        $h = self::RES_ROW;
        // 部門名と表の見出し(部門名・見出しだけページの下に残らないよう、2 行入らなければ次のページへ)
        $title = function ($cont) use ($g, $h) {
            $this->f(11);
            $this->SetX(10);
            $this->Cell(190, $h + 1, $g['category']['name'] . ($cont ? '(続き)' : ''), 0, 1, 'L');
            $this->resultsHead();
        };
        if ($this->GetY() + 10 + 3 * $h > self::RES_BOTTOM) {
            $this->AddPage();
            $this->SetY(10);
        }
        $title(false);
        foreach ($rows as $r) {
            if ($this->GetY() + $h > self::RES_BOTTOM) {
                $this->AddPage();
                $this->SetY(10);
                $title(true);
            }
            $this->SetX(10);
            // 順位。欠場は「欠」(日本語の字体で)、まだ点数が無い人は的番号(1A など)
            $r['absent'] ? $this->f(10) : $this->n(10);
            $rank = $r['absent'] ? '欠' : ($r['rank'] !== null ? (string)$r['rank'] : ($r['target'] ? $r['target'] . $r['letter'] : ''));
            $this->Cell($c['rank'], $h, $rank, 1, 0, 'C');
            $this->f(10.5);
            $this->Cell($c['name'], $h, Entries::fullName($r), 1, 0, 'C', false, '', 1);
            $this->Cell($c['aff'], $h, (string)$r['affiliation'], 1, 0, 'C', false, '', 1);
            $vals = [];
            if ($nd > 1) {
                for ($d = 1; $d <= $nd; $d++) {
                    $vals[] = Scoring::total(Entries::arrows($r, $d));
                }
            }
            $vals[] = [$r['score'], $r['golds'], $r['xs'], $r['hits']];
            $this->n(10);
            foreach ($vals as $v) {
                foreach ([0, 1, 2] as $k) {
                    $this->Cell($c['grp'][$k], $h, $v[3] ? (string)$v[$k] : '', 1, 0, 'C');
                }
            }
            $this->Ln($h);
        }
        $this->Ln(4);
    }
}
