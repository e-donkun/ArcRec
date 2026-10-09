<?php
namespace Lite;

if (!defined('K_TCPDF_EXTERNAL_CONFIG')) {
    define('K_TCPDF_EXTERNAL_CONFIG', true);
    define('K_PATH_CACHE', rtrim(sys_get_temp_dir(), '/') . '/');
}
require_once LITE_ROOT . '/lib/tcpdf/tcpdf.php';

/**
 * スコアカードと結果の PDF。日本語は TCPDF 同梱の Komorebi Gothic を埋め込む。
 */
class Pdf extends \TCPDF
{
    const FONT = 'komorebigothic';

    private $footerText = '';
    private $qrBase = null;

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

    // ================================================================ スコアカード

    /**
     * @param array $entries Entries::all() 形式の配列
     * @param bool $filled true: 入力済みの得点を印字 / false: 記入用の白紙
     */
    public static function scorecards(array $entries, $filled, $baseUrl = null)
    {
        $pdf = new self('スコアカード');
        $pdf->qrBase = $baseUrl;
        $rows = Settings::ends() * Settings::distances();
        $perPage = $rows <= 13 ? 2 : 1;
        $cardH = $perPage === 2 ? 136 : 273;
        $i = 0;
        foreach ($entries as $e) {
            if ($i % $perPage === 0) {
                $pdf->AddPage();
            }
            $y = 12 + ($i % $perPage) * 137;
            $pdf->card($e, $y, $cardH, $filled);
            if ($perPage === 2 && $i % 2 === 0) {
                $pdf->SetLineStyle(['width' => 0.2, 'dash' => '2,2', 'color' => [150, 150, 150]]);
                $pdf->Line(10, 12 + 136.5, 200, 12 + 136.5);
                $pdf->SetLineStyle(['width' => 0.2, 'dash' => 0, 'color' => [0, 0, 0]]);
            }
            $i++;
        }
        if ($i === 0) {
            $pdf->AddPage();
            $pdf->f(12);
            $pdf->Cell(0, 10, '出力する選手がいません', 0, 1);
        }
        return $pdf->Output('scorecards.pdf', 'S');
    }

    private function card(array $e, $y0, $cardH, $filled)
    {
        $x0 = 15;
        $W = 180;
        $per = Settings::arrowsPerEnd();
        $ends = Settings::ends();

        // ---- 見出し
        $this->f(12);
        $this->SetXY($x0, $y0);
        $this->Cell(120, 6, Settings::title(), 0, 1, 'L');

        $this->f(9);
        $this->SetXY($x0, $y0 + 6);
        $this->Cell(120, 5, trim('スコアカード  ' . $this->categoryLabel($e) . '  ' . Settings::distanceSummary()), 0, 0, 'L');

        // 的番号の大きな枠
        $pos = $e['target'] ? $e['target'] . $e['letter'] : '--';
        $this->SetFillColor(235, 235, 235);
        $this->Rect($x0 + $W - 34, $y0 + 6, 34, 15, 'DF');
        $this->f(8);
        $this->SetXY($x0 + $W - 34, $y0 + 6.3);
        $this->Cell(34, 3.5, '的番号', 0, 0, 'C');
        $this->f(18);
        $this->SetXY($x0 + $W - 34, $y0 + 10);
        $this->Cell(34, 9, $pos, 0, 0, 'C');

        $this->f(15);
        $this->SetXY($x0, $y0 + 12);
        $this->Cell(76, 9, Entries::fullName($e), 'B', 0, 'L');
        $this->f(10);
        $this->Cell(50, 9, mb_strimwidth($e['affiliation'], 0, 24, '…'), 'B', 0, 'L');

        // この的を端末に読ませる QR(的を指定する形式)
        if ($this->qrBase && $e['target']) {
            $this->write2DBarcode(Connect::targetPayload($this->qrBase, $e['target']), 'QRCODE,L',
                $x0 + 128, $y0 + 4.5, 16, 16, ['border' => false, 'padding' => 0, 'fgcolor' => [0, 0, 0], 'bgcolor' => false], 'N');
        }

        // ---- 表(距離ごとにエンドを並べ、2 距離のときは距離ごとの小計の行を入れる)
        $nd = Settings::distances();
        $units = 6 + 1.2 * $per;
        $u = $W / $units;
        $wEnd = $u;
        $wArrow = $u * 1.2;
        $wSub = $u * 1.4;
        $wRun = $u * 1.4;
        $wGold = $u * 1.1;
        $wX = $u * 1.1;

        $yT = $y0 + 24;
        $hHead = 6;
        $hDist = 6;
        $hTotal = 7;
        $reserved = $hTotal + ($nd > 1 ? $nd * $hDist : 0) + 13 + 4; // 合計行・距離小計・署名欄・余白
        $rowH = max(5.5, min(10, ($cardH - 24 - $hHead - $reserved) / ($ends * $nd)));

        $this->SetFillColor(225, 225, 225);
        $this->f(8);
        $this->SetXY($x0, $yT);
        $this->Cell($wEnd, $hHead, 'エンド', 1, 0, 'C', true);
        for ($a = 1; $a <= $per; $a++) {
            $this->Cell($wArrow, $hHead, (string)$a, 1, 0, 'C', true);
        }
        $this->Cell($wSub, $hHead, '小計', 1, 0, 'C', true);
        $this->Cell($wRun, $hHead, '累計', 1, 0, 'C', true);
        $this->Cell($wGold, $hHead, Settings::get('gold_label'), 1, 0, 'C', true);
        $this->Cell($wX, $hHead, Settings::get('x_label'), 1, 1, 'C', true);

        $run = 0;
        $tGold = 0;
        $tX = 0;
        $tScore = 0;
        $y = $yT + $hHead;
        for ($d = 1; $d <= $nd; $d++) {
            $arrows = Entries::arrows($e, $d);
            $dScore = $dGold = $dX = 0;
            for ($n = 1; $n <= $ends; $n++) {
                $s = Scoring::endString($arrows, $n, $per);
                $has = $filled && trim($s) !== '';
                $this->SetXY($x0, $y);
                $this->f(9);
                $this->Cell($wEnd, $rowH, (string)$n, 1, 0, 'C');
                for ($a = 0; $a < $per; $a++) {
                    $ch = $has ? ($s[$a] ?? ' ') : ' ';
                    $txt = trim($ch) === '' ? '' : Scoring::display($ch);
                    $gold = $txt !== '' && strpos(Scoring::GOLD_CHARS, strtoupper($ch)) !== false;
                    if ($gold) {
                        $this->SetFillColor(250, 232, 120);
                    }
                    $this->f(12);
                    $this->Cell($wArrow, $rowH, $txt, 1, 0, 'C', $gold);
                    $this->SetFillColor(225, 225, 225);
                }
                $this->f(11);
                if ($has) {
                    list($sc, $g, $x) = Scoring::total($s);
                    $run += $sc;
                    $dScore += $sc;
                    $dGold += $g;
                    $dX += $x;
                    $this->Cell($wSub, $rowH, (string)$sc, 1, 0, 'C');
                    $this->Cell($wRun, $rowH, (string)$run, 1, 0, 'C');
                    $this->Cell($wGold, $rowH, (string)$g, 1, 0, 'C');
                    $this->Cell($wX, $rowH, (string)$x, 1, 1, 'C');
                } else {
                    $this->Cell($wSub, $rowH, '', 1, 0);
                    $this->Cell($wRun, $rowH, '', 1, 0);
                    $this->Cell($wGold, $rowH, '', 1, 0);
                    $this->Cell($wX, $rowH, '', 1, 1);
                }
                $y += $rowH;
            }
            $tScore += $dScore;
            $tGold += $dGold;
            $tX += $dX;
            if ($nd > 1) {
                // 距離ごとの小計
                $this->SetXY($x0, $y);
                $this->SetFillColor(240, 240, 240);
                $this->f(9);
                $showDist = $filled && trim($arrows) !== '';
                $this->Cell($wEnd + $wArrow * $per, $hDist, (Settings::distanceName($d) ?: '距離' . $d) . ' 小計', 1, 0, 'C', true);
                $this->f(11);
                $this->Cell($wSub + $wRun, $hDist, $showDist ? (string)$dScore : '', 1, 0, 'C', true);
                $this->Cell($wGold, $hDist, $showDist ? (string)$dGold : '', 1, 0, 'C', true);
                $this->Cell($wX, $hDist, $showDist ? (string)$dX : '', 1, 1, 'C', true);
                $this->SetFillColor(225, 225, 225);
                $y += $hDist;
            }
        }

        // 合計行
        $this->SetXY($x0, $y);
        $this->SetFillColor(240, 240, 240);
        $this->f(10);
        $this->Cell($wEnd + $wArrow * $per, $hTotal, '合 計', 1, 0, 'C', true);
        $this->f(13);
        $showTotal = $filled && trim(Entries::allArrows($e)) !== '';
        $this->Cell($wSub + $wRun, $hTotal, $showTotal ? (string)$tScore : '', 1, 0, 'C', true);
        $this->f(11);
        $this->Cell($wGold, $hTotal, $showTotal ? (string)$tGold : '', 1, 0, 'C', true);
        $this->Cell($wX, $hTotal, $showTotal ? (string)$tX : '', 1, 1, 'C', true);

        // 署名欄
        $y += $hTotal + 2;
        $this->f(8);
        $half = $W / 2 - 2;
        $this->SetXY($x0, $y);
        $this->Cell($half, 11, '選手署名', 1, 0, 'L');
        $this->SetXY($x0 + $W / 2 + 2, $y);
        $this->Cell($half, 11, '記録員署名', 1, 0, 'L');
    }

    private function categoryLabel(array $e)
    {
        return (string)($e['category_name'] ?? '');
    }

    // ================================================================ 的のQR一覧

    /** 的ごとの接続QR(的に貼って、端末に読ませる) */
    public static function qrSheet($baseUrl)
    {
        $pdf = new self('的QRコード');
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
                $pdf->Cell(180, 5, Settings::title() . '  端末で読み取って的を選びます', 0, 0, 'L');
            }
            $k = $i % ($cols * $rows);
            $x = 15 + ($k % $cols) * $cw;
            $y = 16 + intdiv($k, $cols) * $ch;
            $pdf->SetLineStyle(['width' => 0.2, 'dash' => '1.5,1.5', 'color' => [170, 170, 170]]);
            $pdf->Rect($x, $y, $cw, $ch);
            $pdf->SetLineStyle(['width' => 0.2, 'dash' => 0, 'color' => [0, 0, 0]]);
            $pdf->write2DBarcode(Connect::targetPayload($baseUrl, $t), 'QRCODE,M', $x + 8, $y + 4, 44, 44, ['border' => false, 'padding' => 0], 'N');
            $pdf->f(22);
            $pdf->SetXY($x, $y + 49);
            $pdf->Cell($cw, 12, '的 ' . $t, 0, 0, 'C');
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

    /** @param array $groups Results::byCategory() の戻り値 */
    public static function results(array $groups)
    {
        $pdf = new self('結果');
        $pdf->AddPage();
        $pdf->resultsHeader();
        foreach ($groups as $g) {
            $pdf->resultsCategory($g);
        }
        return $pdf->Output('results.pdf', 'S');
    }

    private function resultsHeader()
    {
        $this->f(16);
        $this->SetXY(15, 12);
        $this->Cell(180, 8, Settings::title(), 0, 1, 'C');
        $this->f(12);
        $this->Cell(180, 8, '予選結果 ' . Settings::roundLabel(), 0, 1, 'C');
        $this->Ln(2);
    }

    private function resultsCategory(array $g)
    {
        $rows = $g['rows'];
        if (!$rows) {
            return;
        }
        $nd = Settings::distances();

        // 2 距離のときは距離ごとの合計の列を足す。余りは所属欄に回して 180mm に揃える
        $w = ['rank' => 11, 'tgt' => 14, 'name' => 38, 'aff' => 0, 'total' => 17, 'gold' => 16, 'x' => 12];
        if ($nd > 1) {
            $w = array_merge(array_slice($w, 0, 4, true), ['d1' => 19, 'd2' => 19], array_slice($w, 4, null, true));
        }
        $w['aff'] = 180 - array_sum($w);
        $rowH = 6.5;

        $needed = 10 + 6 + $rowH * min(count($rows), 4);
        if ($this->GetY() + $needed > 285) {
            $this->AddPage();
        }

        $this->f(11);
        $this->SetFillColor(70, 90, 120);
        $this->SetTextColor(255);
        $this->SetX(15);
        $this->Cell(180, 7, $g['category']['name'], 0, 1, 'L', true);
        $this->SetTextColor(0);
        $this->headRow($w, $nd);

        foreach ($rows as $r) {
            if ($this->GetY() + $rowH > 285) {
                $this->AddPage();
                $this->f(10);
                $this->SetX(15);
                $this->Cell(180, 6, $g['category']['name'] . '(続き)', 0, 1, 'L');
                $this->headRow($w, $nd);
            }
            $this->SetX(15);
            $this->f(9);
            $rank = $r['rank'] === null ? '' : $r['rank'] . ($r['tie'] ? '*' : '');
            $this->Cell($w['rank'], $rowH, $rank, 1, 0, 'C');
            $this->Cell($w['tgt'], $rowH, $r['target'] ? $r['target'] . $r['letter'] : '', 1, 0, 'C');
            $this->f(10);
            $this->Cell($w['name'], $rowH, Entries::fullName($r), 1, 0, 'L');
            $this->f(8);
            $this->Cell($w['aff'], $rowH, mb_strimwidth($r['affiliation'], 0, 36, '…'), 1, 0, 'L');
            $this->f(10);
            if ($nd > 1) {
                foreach ([1, 2] as $d) {
                    $v = $r['dist_scores'][$d] ?? null;
                    $this->Cell($w['d' . $d], $rowH, $v === null ? '' : (string)$v, 1, 0, 'C');
                }
            }
            $this->f(11);
            $this->Cell($w['total'], $rowH, $r['hits'] ? (string)$r['score'] : '', 1, 0, 'C');
            $this->f(10);
            $this->Cell($w['gold'], $rowH, $r['hits'] ? (string)$r['golds'] : '', 1, 0, 'C');
            $this->Cell($w['x'], $rowH, $r['hits'] ? (string)$r['xs'] : '', 1, 1, 'C');
        }
        $this->Ln(5);
    }

    private function headRow(array $w, $nd)
    {
        $this->SetFillColor(225, 225, 225);
        $this->f(8);
        $this->SetX(15);
        $this->Cell($w['rank'], 6, '順位', 1, 0, 'C', true);
        $this->Cell($w['tgt'], 6, '的', 1, 0, 'C', true);
        $this->Cell($w['name'], 6, '氏名', 1, 0, 'C', true);
        $this->Cell($w['aff'], 6, '所属', 1, 0, 'C', true);
        if ($nd > 1) {
            $this->f(7);
            $this->Cell($w['d1'], 6, Settings::distanceName(1) ?: '距離1', 1, 0, 'C', true);
            $this->Cell($w['d2'], 6, Settings::distanceName(2) ?: '距離2', 1, 0, 'C', true);
            $this->f(8);
        }
        $this->Cell($w['total'], 6, '合計', 1, 0, 'C', true);
        $this->Cell($w['gold'], 6, Settings::get('gold_label'), 1, 0, 'C', true);
        $this->Cell($w['x'], 6, Settings::get('x_label'), 1, 1, 'C', true);
    }
}
