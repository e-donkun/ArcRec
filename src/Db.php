<?php
namespace Lite;

use PDO;

/**
 * SQLite への接続とスキーマ。1 つの DB ファイル = 1 つの記録会(パスワードも DB の中の settings に持つ)。
 * 記録会の DB は data/competitions/<記録会コード>.sqlite。いま使う記録会は select() で切り替える。
 */
class Db
{
    private static $pdo;
    /** いま使っている記録会 DB のパス */
    private static $selected;

    public static function dataDir()
    {
        $dir = getenv('LITE_DATA_DIR') ?: lite_config('data_dir', LITE_ROOT . '/data');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return rtrim($dir, '/');
    }

    /** 記録会の DB を置くフォルダ */
    public static function competitionsDir()
    {
        $dir = self::dataDir() . '/competitions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /** いま使っている DB のパス(環境変数 LITE_DB があればそれ。テスト用) */
    public static function path()
    {
        if ($p = getenv('LITE_DB')) {
            return $p;
        }
        if (!self::$selected) {
            throw new \RuntimeException('記録会が選ばれていません');
        }
        return self::$selected;
    }

    public static function hasSelection()
    {
        return (bool)(getenv('LITE_DB') ?: self::$selected);
    }

    /** 使う記録会の DB を切り替える */
    public static function select($path)
    {
        if ($path !== self::$selected) {
            self::$pdo = null;
            self::$selected = $path;
            Settings::reset();
        }
    }

    public static function pdo()
    {
        if (!self::$pdo) {
            self::$pdo = self::connect(self::path());
        }
        return self::$pdo;
    }

    /** 指定の DB に接続する(スキーマが無ければ作り、古ければ足す)。いま使っている DB とは別の接続 */
    public static function connect($path)
    {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA busy_timeout=8000');
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA synchronous=NORMAL');
        $pdo->exec('PRAGMA foreign_keys=ON');
        self::migrate($pdo);
        return $pdo;
    }

    /**
     * 読んでから書く処理用のトランザクション。BEGIN IMMEDIATE で先に書き込みロックを取るので、
     * 複数の端末が同時に送ってきても「database is locked」にならず順番待ちになる(busy_timeout 内)。
     */
    public static function immediate(callable $fn)
    {
        $pdo = self::pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $r = $fn($pdo);
            $pdo->exec('COMMIT');
            return $r;
        } catch (\Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    /** 接続を閉じる(テスト用。選択は変えない) */
    public static function reset()
    {
        self::$pdo = null;
    }

    /** 選択も解除する */
    public static function unselect()
    {
        self::$pdo = null;
        self::$selected = null;
        Settings::reset();
    }

    private static function migrate(PDO $pdo)
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS settings (
            k TEXT PRIMARY KEY, v TEXT NOT NULL DEFAULT \'\')');

        $pdo->exec('CREATE TABLE IF NOT EXISTS categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL DEFAULT \'\',
            name TEXT NOT NULL,
            sort INTEGER NOT NULL DEFAULT 0)');

        // 選手。矢は arrowstring(1 文字 = 1 本。未射は空白)で持つ。
        $pdo->exec('CREATE TABLE IF NOT EXISTS entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bib TEXT NOT NULL DEFAULT \'\',
            family_name TEXT NOT NULL DEFAULT \'\',
            given_name TEXT NOT NULL DEFAULT \'\',
            affiliation TEXT NOT NULL DEFAULT \'\',
            category_id INTEGER REFERENCES categories(id) ON DELETE SET NULL,
            target INTEGER NOT NULL DEFAULT 0,
            letter TEXT NOT NULL DEFAULT \'\',
            arrowstring TEXT NOT NULL DEFAULT \'\',
            arrowstring2 TEXT NOT NULL DEFAULT \'\',
            signed INTEGER NOT NULL DEFAULT 0,
            locked INTEGER NOT NULL DEFAULT 0,
            status INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL DEFAULT \'\')');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS entries_pos
            ON entries(target, letter) WHERE target > 0');

        $pdo->exec('CREATE TABLE IF NOT EXISTS devices (
            uuid TEXT PRIMARY KEY,
            code TEXT NOT NULL DEFAULT \'\',
            version TEXT NOT NULL DEFAULT \'\',
            app_type INTEGER NOT NULL DEFAULT 0,
            target INTEGER NOT NULL DEFAULT 0,
            setup TEXT NOT NULL DEFAULT \'\',
            setup_confirmed INTEGER NOT NULL DEFAULT 0,
            battery INTEGER NOT NULL DEFAULT 0,
            extra TEXT NOT NULL DEFAULT \'\',
            gps TEXT NOT NULL DEFAULT \'\',
            created_at TEXT NOT NULL DEFAULT \'\',
            last_seen TEXT NOT NULL DEFAULT \'\')');

        // 端末から受け取ったエンド単位の履歴(誤入力の追跡用)
        $pdo->exec('CREATE TABLE IF NOT EXISTS score_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            entry_id INTEGER NOT NULL,
            device TEXT NOT NULL DEFAULT \'\',
            distance INTEGER NOT NULL DEFAULT 1,
            end_no INTEGER NOT NULL,
            arrows TEXT NOT NULL,
            source TEXT NOT NULL DEFAULT \'device\',
            at TEXT NOT NULL)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS score_log_entry ON score_log(entry_id)');

        // 2 距離対応より前に作った DB に列を足す
        self::addColumn($pdo, 'entries', 'arrowstring2', 'TEXT NOT NULL DEFAULT \'\'');
        self::addColumn($pdo, 'score_log', 'distance', 'INTEGER NOT NULL DEFAULT 1');
        // 使っていなかった部門の的面の列を消す(的面は記録会の設定で持つ)
        self::dropColumn($pdo, 'categories', 'targetface');

        // 部門はリカーブ男子・女子だけ(固定)。何も無ければ作る
        if (!(int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn()) {
            $pdo->exec("INSERT INTO categories(code, name, sort) VALUES
                ('RM', 'リカーブ男子', 10), ('RW', 'リカーブ女子', 20)");
        }
    }

    /** 列があれば消す。古い SQLite(3.35 より前)で消せないときは、そのまま残す(使わないだけ) */
    private static function dropColumn(PDO $pdo, $table, $column)
    {
        foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll() as $c) {
            if ($c['name'] === $column) {
                try {
                    $pdo->exec("ALTER TABLE $table DROP COLUMN $column");
                } catch (\PDOException $e) {
                }
                return;
            }
        }
    }

    private static function addColumn(PDO $pdo, $table, $column, $def)
    {
        foreach ($pdo->query('PRAGMA table_info(' . $table . ')') as $c) {
            if ($c['name'] === $column) {
                return;
            }
        }
        $pdo->exec("ALTER TABLE $table ADD COLUMN $column $def");
    }
}
