<?php
namespace Lite;

/**
 * 管理画面の簡易認証(パスワード 1 つ)とCSRF対策。
 * 端末(ScorekeeperNG)の API とは別物で、API は大会コード + PIN で守る。
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

    /** config.local.php の admin_password か、初回設定したパスワードのハッシュ */
    private static function hash()
    {
        if ($p = lite_config('admin_password')) {
            return password_hash($p, PASSWORD_DEFAULT);
        }
        return (string)Settings::get('admin_hash');
    }

    public static function needsSetup()
    {
        return !lite_config('admin_password') && Settings::get('admin_hash') === '';
    }

    public static function setPassword($password)
    {
        if (mb_strlen($password) < 4) {
            throw new \InvalidArgumentException('パスワードは4文字以上にしてください');
        }
        Settings::set('admin_hash', password_hash($password, PASSWORD_DEFAULT));
    }

    public static function attempt($password)
    {
        self::start();
        if ($p = lite_config('admin_password')) {
            $ok = hash_equals((string)$p, (string)$password);
        } else {
            $h = (string)Settings::get('admin_hash');
            $ok = $h !== '' && password_verify($password, $h);
        }
        if ($ok) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
        }
        return $ok;
    }

    public static function check()
    {
        self::start();
        return !empty($_SESSION['admin']);
    }

    public static function logout()
    {
        self::start();
        $_SESSION = [];
        session_destroy();
    }

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
        $t = $_POST['_csrf'] ?? '';
        if (!$t || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) {
            http_response_code(400);
            exit('不正なリクエストです(画面を開き直してください)');
        }
    }
}
