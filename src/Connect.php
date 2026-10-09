<?php
namespace Lite;

/**
 * ScorekeeperNG が読み取る QR コードの中身(本家 ianseo の DrawQRCode_ISK_NG と同じ形式)。
 *   {"u":"http://サーバー/ianseo-lite/","c":"大会コード"}                       ... 接続設定
 *   {"u":..,"c":..,"s":"1","t":"0012","st":"Q"}                                    ... 的を指定
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

    public static function targetPayload($baseUrl, $target)
    {
        return json_encode([
            'u' => $baseUrl, 'c' => self::code(), 's' => '1',
            't' => str_pad((string)(int)$target, 4, '0', STR_PAD_LEFT), 'st' => 'Q',
        ], JSON_UNESCAPED_SLASHES);
    }

    /** ブラウザ表示用の SVG */
    public static function svg($text, $size = 220)
    {
        require_once LITE_ROOT . '/lib/tcpdf/tcpdf_barcodes_2d.php';
        $bc = new \TCPDF2DBarcode($text, 'QRCODE,M');
        return $bc->getBarcodeSVGcode(4, 4, 'black');
    }
}
