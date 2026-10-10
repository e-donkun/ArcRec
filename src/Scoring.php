<?php
namespace Lite;

/**
 * arrowstring(1 文字 = 1 本)の採点。文字の意味は ianseo と同じ:
 *   A=M(ミス) B..J=1..9点 K=X(10点) L=10点。小文字は同じ点の「疑義」。
 */
class Scoring
{
    const GOLD_CHARS = 'KL';  // 10 と X(スコアカードPDFで黄色にする点。順位の優先順位は Settings::tieChars)

    private static $lp;

    public static function letterPoint()
    {
        if (!self::$lp) {
            self::$lp = require __DIR__ . '/LetterPoint.php';
        }
        return self::$lp;
    }

    /**
     * @param string|null $gold 第1優先順位の文字(省略時は大会設定の Settings::tieChars(1))
     * @param string|null $x    第2優先順位の文字(省略時は Settings::tieChars(2))
     * @return int[] [合計点, 第1優先順位の本数, 第2優先順位の本数, 射数]
     */
    public static function total($arrowstring, $gold = null, $x = null)
    {
        $gold = $gold ?? Settings::tieChars(1);
        $x = $x ?? Settings::tieChars(2);
        $lp = self::letterPoint();
        $score = $golds = $xs = $hits = 0;
        $s = strtoupper((string)$arrowstring);
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $c = $s[$i];
            if (!isset($lp[$c])) {
                continue;
            }
            $score += (int)$lp[$c]['N'];
            $hits++;
            if (strpos($gold, $c) !== false) {
                $golds++;
            }
            if (strpos($x, $c) !== false) {
                $xs++;
            }
        }
        return [$score, $golds, $xs, $hits];
    }

    /** 文字 -> 表示("K"->"X", "L"->"10", "A"->"M") */
    public static function display($char)
    {
        $lp = self::letterPoint();
        $c = strtoupper($char);
        return isset($lp[$c]) ? $lp[$c]['P'] : '';
    }

    /** 表示 -> 文字("X"->"K", "10"->"L", "M"->"A", "9"->"J")。不正なら null */
    public static function fromDisplay($text)
    {
        $t = strtoupper(trim($text));
        foreach (self::letterPoint() as $c => $d) {
            if (strtoupper($d['P']) === $t) {
                return $c;
            }
        }
        return null;
    }

    /** "X 10 9 M" や "X,10,9,M" や "X109M" 風の入力を arrowstring にする。不正なら null */
    public static function parseInput($text)
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $tokens = preg_split('/[\s,]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $out = '';
        foreach ($tokens as $tok) {
            $c = self::fromDisplay($tok);
            if ($c !== null) {
                $out .= $c;
                continue;
            }
            // くっつけて書かれた場合(例 "X109M"): 10 を先に切り出す
            $rest = strtoupper($tok);
            while ($rest !== '') {
                if (substr($rest, 0, 2) === '10') {
                    $out .= 'L';
                    $rest = substr($rest, 2);
                    continue;
                }
                $c = self::fromDisplay($rest[0]);
                if ($c === null) {
                    return null;
                }
                $out .= $c;
                $rest = substr($rest, 1);
            }
        }
        return $out;
    }

    /** 1 エンド分の文字列を取り出す(1 始まり) */
    public static function endString($arrowstring, $end, $arrowsPerEnd)
    {
        return (string)substr(str_pad((string)$arrowstring, $end * $arrowsPerEnd), ($end - 1) * $arrowsPerEnd, $arrowsPerEnd);
    }

    /** 規定の長さに揃える(足りなければ空白、超過は切り捨て) */
    public static function pad($arrowstring, $length)
    {
        return substr(str_pad((string)$arrowstring, $length), 0, $length);
    }

    /** 有効な文字だけか(空白可) */
    public static function isValid($arrowstring)
    {
        return (bool)preg_match('/^[A-La-l ]*$/', (string)$arrowstring);
    }
}
