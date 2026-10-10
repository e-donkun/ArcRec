<?php
namespace Lite;

/**
 * 管理画面の認証(記録会ごとのパスワード)と CSRF 対策。
 * パスワードは記録会の DB の中(settings.admin_hash)にあり、記録会ごとに別々。
 * セッションには「いまパスワードで開いている記録会」1つだけを覚える。別の記録会に切り替えるときは、その都度パスワードを入れる
 * (前に開いた記録会のパスワードは覚えない)。
 * これとは別に「管理者パスワード」が1つあり(data/admin.json にハッシュで保存。config.local.php の admin_password でも可)、
 * 管理者としてログインすると、どの記録会もパスワードなしで開け、新しい記録会を作れる。
 * 端末(ScorekeeperNG)の API とは別物で、API は記録会コード(ランダムな16進数8桁)で守る。
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

    // ------------------------------------------------------------ 管理者パスワード(すべての記録会に共通)

    private static function adminFile()
    {
        return Db::dataDir() . '/admin.json';
    }

    private static function adminHash()
    {
        $j = is_file(self::adminFile()) ? json_decode((string)file_get_contents(self::adminFile()), true) : null;
        return is_array($j) ? (string)($j['hash'] ?? '') : '';
    }

    /** 管理者パスワードが決まっているか */
    public static function adminIsSet()
    {
        return self::adminHash() !== '' || self::master() !== null;
    }

    public static function verifyAdmin($password)
    {
        $password = (string)$password;
        if ($password === '') {
            return false;
        }
        if (($m = self::master()) !== null && hash_equals($m, $password)) {
            return true;
        }
        $h = self::adminHash();
        return $h !== '' && password_verify($password, $h);
    }

    public static function setAdminPassword($password)
    {
        self::checkPasswordRule($password);
        $f = self::adminFile();
        if (@file_put_contents($f, json_encode(['hash' => self::hashPassword($password), 'updated' => date('Y-m-d H:i:s')]), LOCK_EX) === false) {
            throw new \RuntimeException('管理者パスワードを保存できません(data フォルダに書き込めるか確認してください)');
        }
        @chmod($f, 0600);
    }

    /**
     * 管理者パスワードを初めて決めてよいか。だれでも先に決められてしまわないよう、
     * サンプル以外の記録会があるときは、その記録会を開いているか、その記録会のパスワード($proof)を知っていること。
     */
    public static function canSetupAdmin($proof = null)
    {
        if (self::adminIsSet()) {
            return false;
        }
        $real = array_filter(array_map('strval', array_keys(Competitions::files())), function ($c) {
            return !Competitions::isSample($c);
        });
        if (!$real) {
            return true;
        }
        $cur = session_status() === PHP_SESSION_ACTIVE ? self::current() : null;   // 画面からはセッションが始まっている
        if ($cur !== null && !Competitions::isSample($cur)) {
            return true;
        }
        foreach ($real as $c) {
            $h = (string)Competitions::readSetting($c, 'admin_hash');
            if ($proof !== null && $proof !== '' && $h !== '' && password_verify((string)$proof, $h)) {
                return true;
            }
        }
        return false;
    }

    /** 管理者としてログイン */
    public static function adminLogin($password)
    {
        self::start();
        if (!self::verifyAdmin($password)) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        return true;
    }

    public static function isAdmin()
    {
        self::start();
        return !empty($_SESSION['admin']) && self::adminIsSet();
    }

    /** 記録会のパスワードが合っているか(管理者パスワードでも可) */
    public static function verify($code, $password)
    {
        if (self::verifyAdmin($password)) {
            return true;
        }
        $h = (string)Competitions::readSetting($code, 'admin_hash');
        return $h !== '' && password_verify((string)$password, $h);
    }

    /** いま使っている記録会のパスワードを変える */
    /** いま使っている記録会のパスワードを変える。空にすると、管理者パスワードでだけ開ける記録会になる */
    public static function setPassword($password)
    {
        $password = (string)$password;
        if ($password === '') {
            Settings::set('admin_hash', '');
            return;
        }
        self::checkPasswordRule($password);
        Settings::set('admin_hash', self::hashPassword($password));
    }

    /** 記録会ごとのパスワードがあるか(なければ管理者だけが開ける) */
    public static function hasCompetitionPassword($code)
    {
        return (string)Competitions::readSetting($code, 'admin_hash') !== '';
    }

    // ------------------------------------------------------------ ログイン状態(セッション)

    /** パスワードを確かめて、合っていればその記録会を開く(それまで開いていた記録会は閉じる)。管理者はパスワードなしで開ける */
    public static function login($code, $password)
    {
        self::start();
        $f = Competitions::find($code);
        if (!$f || (!self::isAdmin() && !self::verify($f['code'], $password))) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['comp'] = $f['code'];
        unset($_SESSION['auth']);   // 以前の版で覚えていた「開いた記録会の一覧」は使わない
        return true;
    }

    /** いま管理している記録会のコード。ログインしていなければ null */
    public static function current()
    {
        self::start();
        $c = $_SESSION['comp'] ?? null;
        if (!$c) {
            return null;
        }
        $f = Competitions::find($c);
        return $f ? $f['code'] : null;
    }

    public static function check()
    {
        return self::current() !== null;
    }

    /** いま開いている記録会か */
    public static function isCurrent($code)
    {
        $cur = self::current();
        return $cur !== null && strcasecmp($cur, (string)$code) === 0;
    }

    /** 記録会を新しく作ってよいか(管理者だけ) */
    public static function canCreate()
    {
        return self::isAdmin();
    }

    /** 開いている記録会を閉じる(管理者のログインはそのまま) */
    public static function closeCompetition()
    {
        self::start();
        unset($_SESSION['comp']);
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
