<?php
namespace Lite;

/**
 * 管理画面の認証(大会ごとのパスワード)と CSRF 対策。
 * パスワードは大会の DB の中(settings.admin_hash)にあり、大会ごとに別々。
 * セッションには「パスワードで開いた大会」を覚えておき、大会を切り替えるときにまた聞かない。
 * config.local.php の admin_password を設定していれば、それはどの大会も開ける共通のパスワード(マスター)になる。
 * 端末(ScorekeeperNG)の API とは別物で、API は大会コード(ランダムな16進数8桁)で守る。
 */
class Auth
{
    public static function start()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $dir = Db::dataDir() . '/sessions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
        }
        session_name('ianseolite');
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
        session_start();
    }

    // ------------------------------------------------------------ パスワード

    public static function hashPassword($password)
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public static function checkPasswordRule($password)
    {
        if (mb_strlen((string)$password) < 4) {
            throw new \InvalidArgumentException('パスワードは4文字以上にしてください');
        }
    }

    private static function master()
    {
        $p = lite_config('admin_password');
        return $p ? (string)$p : null;
    }

    /** 大会のパスワードが合っているか(共通のマスターパスワードでも可) */
    public static function verify($code, $password)
    {
        if (($m = self::master()) !== null && hash_equals($m, (string)$password)) {
            return true;
        }
        $h = (string)Competitions::readSetting($code, 'admin_hash');
        return $h !== '' && password_verify((string)$password, $h);
    }

    /** いま使っている大会のパスワードを変える */
    public static function setPassword($password)
    {
        self::checkPasswordRule($password);
        Settings::set('admin_hash', self::hashPassword($password));
    }

    // ------------------------------------------------------------ ログイン状態(セッション)

    /** パスワードを確かめて、合っていればその大会を開いた状態にする */
    public static function login($code, $password)
    {
        self::start();
        $f = Competitions::find($code);
        if (!$f || !self::verify($f['code'], $password)) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['auth'][$f['code']] = true;
        if (($m = self::master()) !== null && hash_equals($m, (string)$password)) {
            $_SESSION['master'] = true;
        }
        $_SESSION['comp'] = $f['code'];
        return true;
    }

    public static function authorized($code)
    {
        self::start();
        $f = Competitions::find($code);
        return $f && !empty($_SESSION['auth'][$f['code']]);
    }

    /** 開いたことのある(パスワードを通った)大会のコード一覧 */
    public static function authorizedCodes()
    {
        self::start();
        return array_keys(array_filter($_SESSION['auth'] ?? []));
    }

    public static function anyAuthorized()
    {
        return !empty(self::authorizedCodes());
    }

    /** 大会を切り替える(パスワードを通った大会だけ) */
    public static function switchTo($code)
    {
        if (!self::authorized($code)) {
            return false;
        }
        $_SESSION['comp'] = Competitions::find($code)['code'];
        return true;
    }

    /** いま管理している大会のコード。ログインしていなければ null */
    public static function current()
    {
        self::start();
        $c = $_SESSION['comp'] ?? null;
        return ($c && self::authorized($c)) ? $c : null;
    }

    public static function check()
    {
        return self::current() !== null;
    }

    /** 大会を新しく作ってよいか(まだ大会が無い、パスワードを通った大会がある、またはマスターパスワードで入った) */
    public static function canCreate()
    {
        self::start();
        return !Competitions::files() || self::anyAuthorized() || !empty($_SESSION['master']);
    }

    public static function logout()
    {
        self::start();
        $_SESSION = [];
        session_destroy();
    }

    // ------------------------------------------------------------ CSRF

    public static function csrf()
    {
        self::start();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf'];
    }

    public static function verifyCsrf()
    {
        self::start();
        $t = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!$t || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) {
            http_response_code(400);
            exit('不正なリクエストです(画面を開き直してください)');
        }
    }
}
