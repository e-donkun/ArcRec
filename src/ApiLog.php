<?php
namespace Lite;

/**
 * 端末(ScorekeeperNG)とのやりとりの記録。端末がどこで止まるかを調べるためのもので、
 * 管理画面「通信ログ」でオンにしたときだけ data/api-YYYY-MM-DD.jsonl に書く。
 */
class ApiLog
{
    public static function enabled()
    {
        return Settings::get('api_log') === '1';
    }

    /** 大会ごとのログファイル */
    public static function file($code, $day = null)
    {
        return Db::dataDir() . '/api-' . preg_replace('/[^A-Za-z0-9_-]/', '', $code) . '-' . ($day ?: date('Y-m-d')) . '.jsonl';
    }

    /** 画像など大きい値は短くして記録する */
    private static function shrink($v)
    {
        if (is_string($v)) {
            return strlen($v) > 400 ? substr($v, 0, 60) . '…(' . strlen($v) . ' bytes)' : $v;
        }
        if (is_array($v) || is_object($v)) {
            $o = [];
            foreach ((array)$v as $k => $x) {
                $o[$k] = self::shrink($x);
            }
            return is_object($v) ? (object)$o : $o;
        }
        return $v;
    }

    /** @param string[] $codes このやりとりで使った大会のコード。ログをオンにしている大会のファイルにだけ書く */
    public static function write($requests, array $response, array $codes = [])
    {
        try {
            if (getenv('LITE_DB')) {
                $codes = [Settings::code()];
            }
            foreach ($codes as $code) {
                if (!getenv('LITE_DB') && Competitions::open($code) === false) {
                    continue;
                }
                if (!self::enabled()) {
                    continue;
                }
                $line = json_encode([
                    'time' => date('Y-m-d H:i:s'),
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                    'request' => self::shrink($requests),
                    'response' => self::shrink($response),
                ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
                $f = self::file($code);
                @file_put_contents($f, $line . "\n", FILE_APPEND | LOCK_EX);
                @chmod($f, 0660);
            }
        } catch (\Throwable $e) {
            // ログの失敗で端末の通信を止めない
        }
    }

    /** いま使っている大会の、新しい順に最大 $limit 件(今日のファイルから) */
    public static function recent($limit = 40)
    {
        $f = self::file(Settings::code());
        if (!is_file($f)) {
            return [];
        }
        $lines = array_slice(file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -$limit);
        $out = [];
        foreach (array_reverse($lines) as $l) {
            if (is_array($j = json_decode($l, true))) {
                $out[] = $j;
            }
        }
        return $out;
    }

    /** いま使っている大会のログを消す */
    public static function clear()
    {
        $code = preg_replace('/[^A-Za-z0-9_-]/', '', Settings::code());
        foreach (glob(Db::dataDir() . '/api-' . $code . '-*.jsonl') ?: [] as $f) {
            @unlink($f);
        }
    }
}
