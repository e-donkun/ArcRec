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

    /** 記録会ごとのログファイル */
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

    /** @param string[] $codes このやりとりで使った記録会のコード。ログをオンにしている記録会のファイルにだけ書く */
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
                    'http' => trim(($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? '') . ' / ' . ($_SERVER['HTTP_USER_AGENT'] ?? '')
                        . (isset($_SERVER['HTTP_ORIGIN']) ? ' / origin ' . $_SERVER['HTTP_ORIGIN'] : '')),
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

    /**
     * 端末とのやりとり 1 回分を記録する。
     * 記録会が決まらなかった返事(未登録の端末・存在しない記録会コード・対応していないアクション・項目の不足など)も残す:
     * 端末が登録されている記録会があればそこ(最後に通信した記録会)へ、無ければログがオンの記録会すべてへ書く
     *
     * @param string[] $codes このやりとりで使った記録会のコード
     */
    public static function writeRequest($requests, array $response, array $codes)
    {
        if (!$codes) {
            $codes = self::deviceCodes($requests);
        }
        if ($codes) {
            self::write($requests, $response, $codes);
        } else {
            self::writeUnbound($requests, $response);
        }
    }

    /** リクエストに含まれる端末が登録されている記録会のコード */
    private static function deviceCodes($requests)
    {
        $codes = [];
        try {
            foreach (is_array($requests) ? $requests : [$requests] as $req) {
                $req = (array)$req;
                $dev = (string)($req['device'] ?? $req['uuid'] ?? '');
                if ($dev !== '' && ($c = Competitions::byDevice($dev)) !== null) {
                    $codes[$c] = true;
                }
            }
        } catch (\Throwable $e) {
            // ログの失敗で端末の通信を止めない
        }
        return array_map('strval', array_keys($codes));
    }

    /** どの記録会への通信か分からないもの(OPTIONS・読めないリクエスト・記録会が決まらなかった返事)を、ログがオンの記録会すべてに残す */
    public static function writeUnbound($request, array $response)
    {
        try {
            self::write($request, $response, array_map('strval', array_keys(Competitions::files())));
        } catch (\Throwable $e) {
            // ログの失敗で端末の通信を止めない
        }
    }

    /** いま使っている記録会の、新しい順に最大 $limit 件(今日のファイルから) */
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

    /** いま使っている記録会のログを消す */
    public static function clear()
    {
        $code = preg_replace('/[^A-Za-z0-9_-]/', '', Settings::code());
        foreach (glob(Db::dataDir() . '/api-' . $code . '-*.jsonl') ?: [] as $f) {
            @unlink($f);
        }
    }
}
