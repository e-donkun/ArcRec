<?php
namespace Lite;

use PDO;

/**
 * SQLite への接続とスキーマ。1 つの DB ファイル = 1 つの大会。
 */
class Db
{
    private static $pdo;

    public static function dataDir()
    {
        $dir = getenv('LITE_DATA_DIR') ?: lite_config('data_dir', LITE_ROOT . '/data');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return rtrim($dir, '/');
    }

    public static function path()
    {
        return getenv('LITE_DB') ?: self::dataDir() . '/lite.sqlite';
    }

    public static function pdo()
    {
        if (!self::$pdo) {
            $pdo = new PDO('sqlite:' . self::path());
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA busy_timeout=8000');
            $pdo->exec('PRAGMA journal_mode=WAL');
            $pdo->exec('PRAGMA synchronous=NORMAL');
            $pdo->exec('PRAGMA foreign_keys=ON');
            self::$pdo = $pdo;
            self::migrate($pdo);
        }
        return self::$pdo;
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

    /** テスト用: 接続を閉じる */
    public static function reset()
    {
        self::$pdo = null;
    }

    private static function migrate(PDO $pdo)
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS settings (
            k TEXT PRIMARY KEY, v TEXT NOT NULL DEFAULT \'\')');

        $pdo->exec('CREATE TABLE IF NOT EXISTS categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL DEFAULT \'\',
            name TEXT NOT NULL,
            targetface INTEGER NOT NULL DEFAULT 5,
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
            end_no INTEGER NOT NULL,
            arrows TEXT NOT NULL,
            source TEXT NOT NULL DEFAULT \'device\',
            at TEXT NOT NULL)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS score_log_entry ON score_log(entry_id)');
    }
}
