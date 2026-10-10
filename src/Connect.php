<?php
namespace Lite;

/**
 * ScorekeeperNG が読み取る QR コードの中身(本家 ianseo の DrawQRCode_ISK_NG と同じ形式)。
 *   {"u":"http://サーバー/ianseo-lite/","c":"記録会コード"}                       ... 接続設定
 *   {"u":..,"c":..,"s":"1","t":"0012","st":"Q"}                                    ... 的を指定(距離は端末で選ぶ)
 *   {"u":..,"c":..,"s":"1","d":"1","t":"0012","st":"Q"}                            ... 的と距離(前半/後半)を指定
 */
class Connect
{
    public static function code()
    {
        return Settings::code();
    }

    public static function setupPayload($baseUrl)
    {
        return json_encode(['u' => $baseUrl, 'c' => self::code()], JSON_UNESCAPED_SLASHES);
    }

    /** @param int $distance 0 = 距離は端末で選ぶ(手動)、1/2 = その距離 */
    public static function targetPayload($baseUrl, $target, $distance = 0)
    {
        $o = ['u' => $baseUrl, 'c' => self::code(), 's' => '1'];
        if ($distance > 0) {
            $o['d'] = (string)(int)$distance;
        }
        $o['t'] = str_pad((string)(int)$target, 4, '0', STR_PAD_LEFT);
        $o['st'] = 'Q';
        return json_encode($o, JSON_UNESCAPED_SLASHES);
    }

    /**
     * 的のQRの距離の選び方。2距離なら [0 => 距離手動, 1 => 前半, 2 => 後半]、1距離なら [1 => ''](距離名は出さない)
     */
    public static function distanceModes()
    {
        if (Settings::distances() < 2) {
            return [1 => ''];
        }
        return [0 => '距離手動', 1 => Settings::distanceName(1) ?: '距離1', 2 => Settings::distanceName(2) ?: '距離2'];
    }

    /** 既定の距離の選び方(2距離なら前半、1距離ならその距離) */
    public static function defaultMode()
    {
        return 1;
    }

    /** 正しい距離の選び方に直す */
    public static function mode($d)
    {
        $d = (int)$d;
        return array_key_exists($d, self::distanceModes()) ? $d : self::defaultMode();
    }

    /** 的のQRの見出し: 「1的 前半」。1距離や距離手動のときは「1的」だけ */
    public static function targetLabel($target, $distance = 0)
    {
        $name = $distance > 0 ? (self::distanceModes()[$distance] ?? '') : '';
        return (int)$target . '的' . ($name !== '' ? ' ' . $name : '');
    }

    /**
     * ブラウザ表示用の SVG。枠の大きさに合わせて拡大縮小できるよう viewBox を付ける。
     * $inline = true なら HTML に埋め込む形(XML 宣言などを除く)
     */
    public static function svg($text, $inline = true)
    {
        require_once LITE_ROOT . '/lib/tcpdf/tcpdf_barcodes_2d.php';
        $bc = new \TCPDF2DBarcode($text, 'QRCODE,M');
        $svg = preg_replace('/<svg width="(\d+(?:\.\d+)?)" height="(\d+(?:\.\d+)?)"/', '<svg viewBox="0 0 $1 $2" width="$1" height="$2"', $bc->getBarcodeSVGcode(4, 4, 'black'), 1);
        if ($inline) {
            $svg = substr($svg, strpos($svg, '<svg'));
        }
        return $svg;
    }
}
