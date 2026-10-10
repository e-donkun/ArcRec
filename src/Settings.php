<?php
namespace Lite;

/**
 * 記録会設定。WA(World Archery)の予選形式に絞る: 1 または 2 距離、各距離 屋外 36射 / 屋内 30射。
 * 射数・エンド数は競技形式(preset)から決まる。部門はリカーブ男子・女子だけ。
 */
class Settings
{
    /**
     * 競技形式。ends/arrows は 1 距離ぶん。屋外は 6 射 x 6 エンド、屋内は 3 射 x 10 エンド。
     */
    const PRESETS = [
        'outdoor36' => ['label' => 'Outdoor 36射', 'category' => 1, 'distances' => 1, 'ends' => 6, 'arrows' => 6],
        'outdoor72' => ['label' => 'Outdoor 36射×2（72射）', 'category' => 1, 'distances' => 2, 'ends' => 6, 'arrows' => 6],
        'indoor30' => ['label' => 'Indoor 30射', 'category' => 2, 'distances' => 1, 'ends' => 10, 'arrows' => 3],
        'indoor60' => ['label' => 'Indoor 30射×2（60射）', 'category' => 2, 'distances' => 2, 'ends' => 10, 'arrows' => 3],
    ];

    /** 的面の選択肢(リカーブ): 屋外 / 屋内。値は的面ID(TargetFaces) */
    const FACES = [
        1 => [5 => '10リング (1-X)', 10 => '6リング (6-X)'],
        2 => [2 => '40cm 三つ目 (6-10)', 1 => '40cm 10リング (1-10)'],   // 屋内は三つ目が既定(先頭が既定)
    ];

    /**
     * 同点のときの優先順位(ianseo の Points as 1st / 2nd Tie-Breaker)の初期値。屋外(1) / 屋内(2)。
     * 合計点が同じなら、第1優先順位の点の本数 → 第2優先順位の点の本数 の多い順。
     */
    const TIE_DEFAULTS = [
        1 => [1 => ['X'], 2 => ['10']],
        2 => [1 => ['10'], 2 => ['9']],
    ];

    /** 1的あたりの立ち数(位置 A〜F)の上限 */
    const MAX_PER_TARGET = 6;

    /** 2距離のときの距離名の初期値(1距離のときは空欄) */
    const DEFAULT_DISTANCE_NAMES = [1 => '前半', 2 => '後半'];

    /** 端末に見せるセッション名(セッションは1つだけ) */
    const SESSION_NAME = 'Session 1';

    const DEFAULTS = [
        'code' => '',
        'name' => '',
        'preset' => 'outdoor72',
        'distance_name' => '',
        'distance_name2' => '',
        'target_face1' => '',
        'target_face2' => '',
        'tie1' => '',   // 第1優先順位(カンマ区切り。例 "10,X")。空なら競技形式(屋外/屋内)の初期値
        'tie2' => '',   // 第2優先順位
        'archers_per_target' => '4',   // 立ち数(1的あたりの人数。1〜6 = A〜F)
        'target_count' => '0',         // 立順の割り当て表に出す的の数(0 = 選手の人数から決める)
        'admin_hash' => '',
        'api_log' => '0',
        'results_public' => '0',   // 1 = 結果をトップの results.php で誰でも見られる(管理者は非公開でも見られる)
    ];

    private static $cache;

    public static function all()
    {
        if (self::$cache === null) {
            self::$cache = self::DEFAULTS;
            foreach (Db::pdo()->query('SELECT k, v FROM settings') as $r) {
                self::$cache[$r['k']] = $r['v'];
            }
        }
        return self::$cache;
    }

    /** 結果を一般に公開しているか */
    public static function resultsPublic()
    {
        return (string)self::get('results_public') === '1';
    }

    public static function get($key)
    {
        $a = self::all();
        return $a[$key] ?? null;
    }

    public static function set($key, $value)
    {
        $st = Db::pdo()->prepare('INSERT INTO settings(k, v) VALUES(?, ?)
            ON CONFLICT(k) DO UPDATE SET v = excluded.v');
        $st->execute([$key, (string)$value]);
        self::$cache = null;
    }

    public static function setMany(array $values)
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        foreach ($values as $k => $v) {
            self::set($k, $v);
        }
        $pdo->commit();
    }

    public static function reset()
    {
        self::$cache = null;
    }

    /** 今の競技形式の定義(不明な値のときは既定の形式) */
    public static function preset()
    {
        $k = (string)self::get('preset');
        return self::PRESETS[$k] ?? self::PRESETS[self::DEFAULTS['preset']];
    }

    /** 距離の数(1 か 2) */
    public static function distances()
    {
        return self::preset()['distances'];
    }

    /** 1エンドの矢数 */
    public static function arrowsPerEnd()
    {
        return self::preset()['arrows'];
    }

    /** 1距離のエンド数 */
    public static function ends()
    {
        return self::preset()['ends'];
    }

    /** 1距離の矢数(屋外 36 / 屋内 30) */
    public static function arrowsPerDistance()
    {
        return self::arrowsPerEnd() * self::ends();
    }

    /** 全距離の矢数 */
    public static function totalArrows()
    {
        return self::arrowsPerDistance() * self::distances();
    }

    public static function isIndoor()
    {
        return self::preset()['category'] === 2;
    }

    /** ISK の tocategory(1 屋外 / 2 屋内) */
    public static function category()
    {
        return self::preset()['category'];
    }

    /** 記録会の表示名。未入力のときは記録会コード */
    public static function title()
    {
        $n = trim((string)self::get('name'));
        return $n !== '' ? $n : self::code();
    }

    /** 距離の名前。入力が無ければ、2距離は 前半 / 後半、1距離は空欄 */
    public static function distanceName($d = 1)
    {
        $d = $d == 2 ? 2 : 1;
        $v = trim((string)self::get($d === 2 ? 'distance_name2' : 'distance_name'));
        if ($v !== '') {
            return $v;
        }
        return self::distances() === 2 ? self::DEFAULT_DISTANCE_NAMES[$d] : '';
    }

    /**
     * 見出しに足す距離名(例 "70m + 60m")。初期値のまま(前半/後半)や空欄のときは空文字にして、
     * 見出しには射数だけを出す。
     */
    public static function distanceSummary()
    {
        if (self::distances() === 1) {
            return self::distanceName(1);
        }
        $a = self::distanceName(1);
        $b = self::distanceName(2);
        if ($a === self::DEFAULT_DISTANCE_NAMES[1] && $b === self::DEFAULT_DISTANCE_NAMES[2]) {
            return '';
        }
        return $a . ' + ' . $b;
    }

    /** 見出し用: 「72射」「70m 36射」「70m + 60m 72射」 */
    public static function roundLabel()
    {
        return trim(self::distanceSummary() . ' ' . self::totalArrows() . '射');
    }

    /** 今の競技形式(屋外/屋内)で選べる的面 [的面ID => 表示名] */
    public static function faceOptions()
    {
        return self::FACES[self::category()];
    }

    /** 的面ID(前半・後半とも同じ的)。未設定や、競技形式に合わない値のときは選択肢の先頭 */
    public static function targetFace($d = 1)
    {
        $opts = self::faceOptions();
        $v = (int)self::get('target_face1');
        return isset($opts[$v]) ? $v : (int)array_key_first($opts);
    }

    // ------------------------------------------------------------ 立順(的の数・立ち数)

    /** 1的あたりの立ち数(1〜6) */
    public static function perTarget()
    {
        return max(1, min(self::MAX_PER_TARGET, (int)self::get('archers_per_target')));
    }

    /** 使える位置(立ち数ぶん。A, B, C, D …) */
    public static function letters()
    {
        return array_slice(['A', 'B', 'C', 'D', 'E', 'F'], 0, self::perTarget());
    }

    /**
     * 「1A」「001a」「１Ａ」のような入力を [的番号, 位置] にする。位置は大文字、頭の 0 は取る。
     * 空なら [0, '']、位置を省いた「3」は [3, '']。読めなければ null
     */
    public static function parsePosition($text)
    {
        $t = strtoupper(trim(mb_convert_kana((string)$text, 'as')));
        if ($t === '') {
            return [0, ''];
        }
        if (!preg_match('/^0*(\d{1,4})\s*([A-F]?)$/', $t, $m) || (int)$m[1] === 0) {
            return null;
        }
        return [(int)$m[1], $m[2]];
    }

    // ------------------------------------------------------------ 同点のときの優先順位

    /**
     * 「10,X」のような入力を点の並び(['10', 'X'])にする。使える点は X・10〜1・M。
     * 区切りはカンマ(全角・読点も可)か空白。重複は1つにまとめる。不正なら InvalidArgumentException
     */
    public static function parseTie($text)
    {
        $out = [];
        foreach (preg_split('/[\s,，、+＋]+/u', mb_convert_kana(trim((string)$text), 'as'), -1, PREG_SPLIT_NO_EMPTY) as $t) {
            $t = strtoupper($t);
            if (Scoring::fromDisplay($t) === null) {
                throw new \InvalidArgumentException('優先順位に使えない点です: ' . $t . '(X・10〜1・M をカンマ区切りで)');
            }
            if (!in_array($t, $out, true)) {
                $out[] = $t;
            }
        }
        if (!$out) {
            throw new \InvalidArgumentException('優先順位を入力してください(例 X や 10,X)');
        }
        return $out;
    }

    /** 競技形式(屋外/屋内)ごとの初期値 */
    public static function tieDefault($n, $category = null)
    {
        return self::TIE_DEFAULTS[$category ?: self::category()][$n == 2 ? 2 : 1];
    }

    /** 第1($n=1)・第2($n=2)優先順位の点の並び */
    public static function tiePoints($n)
    {
        $v = trim((string)self::get($n == 2 ? 'tie2' : 'tie1'));
        if ($v !== '') {
            try {
                return self::parseTie($v);
            } catch (\InvalidArgumentException $e) {
                // 壊れた値なら初期値
            }
        }
        return self::tieDefault($n);
    }

    /** 優先順位の矢の文字(arrowstring の文字。X→K, 10→L …) */
    public static function tieChars($n)
    {
        static $memo = [];   // 採点のたびに呼ばれるので、設定値ごとに覚えておく
        $key = $n . '|' . self::category() . '|' . self::get($n == 2 ? 'tie2' : 'tie1');
        if (!isset($memo[$key])) {
            $memo[$key] = implode('', array_map([Scoring::class, 'fromDisplay'], self::tiePoints($n)));
        }
        return $memo[$key];
    }

    /** 見出し(点を + でつなぐ。例 10+X) */
    public static function tieLabel($n)
    {
        return implode('+', self::tiePoints($n));
    }

    /**
     * 記録会コード(端末が使う識別子)。ユーザーは入力しない。
     * 記録会の作成時(最初に必要になったとき)に、8桁の16進数をランダムに作って保存する。
     * 空のときと、以前の既定値 "LITE" のときだけ作り直す(自分で入れた値は、そのまま使い続ける)。
     */
    public static function code()
    {
        $v = (string)self::get('code');
        if ($v !== '' && $v !== 'LITE') {
            return $v;
        }
        return Db::immediate(function () {
            self::reset();
            $v = (string)self::get('code');
            if ($v === '' || $v === 'LITE') {
                $v = strtoupper(bin2hex(random_bytes(4)));
                self::set('code', $v);
            }
            return $v;
        });
    }

    public static function sessionName()
    {
        return self::SESSION_NAME;
    }
}
