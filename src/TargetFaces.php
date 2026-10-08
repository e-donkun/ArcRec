<?php
namespace Lite;

/**
 * 使える的面。定義は ianseo の Targets 表から生成(tools/gen_targets.php)。
 */
class TargetFaces
{
    /** 的面ID => 画面表示名 */
    const LABELS = [
        5 => '屋外 122cm/80cm (1-X)',
        10 => '屋外 80cm 6リング (6-X)',
        9 => '屋外 80cm (5-X)',
        1 => '屋内 40cm (1-10)',
        2 => '屋内 40cm (6-10)',
        3 => '屋内 コンパウンド (1-10)',
        4 => '屋内 コンパウンド (6-10)',
    ];

    private static $data;

    private static function data()
    {
        if (!self::$data) {
            self::$data = require __DIR__ . '/TargetFaces.data.php';
        }
        return self::$data;
    }

    public static function options()
    {
        return self::LABELS;
    }

    public static function exists($id)
    {
        return isset(self::data()[(int)$id]);
    }

    public static function label($id)
    {
        return self::LABELS[(int)$id] ?? ('的面' . (int)$id);
    }

    /** そのまま端末(ScorekeeperNG)へ送る的面定義 */
    public static function iskFace($id, $withImage = false)
    {
        $id = (int)$id;
        if (!self::exists($id)) {
            $id = 5;
        }
        $d = self::data()[$id];
        $n = count($d['letters']);
        $face = [
            'code' => $id,
            'id' => $d['descr'],
            'name' => self::label($id),
            'imgName' => $id . '.svg',
            'cols' => min(3, $n),
            'rows' => (int)ceil($n / 3),
            'gold' => Scoring::GOLD_CHARS,
            'xnine' => Scoring::X_CHARS,
            'goldlbl' => Settings::get('gold_label'),
            'xninelbl' => Settings::get('x_label'),
            'letterPoint' => $d['letters'],
        ];
        if ($withImage) {
            $face['imgGzip'] = self::imageBase64($id);
            $face['imgBase64Prefix'] = 'data:image/svg+xml;base64,';
        }
        return $face;
    }

    public static function imageBase64($id)
    {
        $file = LITE_ROOT . '/assets/targets/' . (int)$id . '.svgz';
        if (!is_file($file)) {
            $file = LITE_ROOT . '/assets/targets/5.svgz';
        }
        return base64_encode(file_get_contents($file));
    }
}
