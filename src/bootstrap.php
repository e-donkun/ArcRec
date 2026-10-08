<?php
// ianseo-lite 共通の初期化。親ディレクトリ(本家 ianseo)には依存しない。
if (defined('LITE_ROOT')) {
    return;
}
define('LITE_ROOT', dirname(__DIR__));
define('LITE_VERSION', '0.1.0');

date_default_timezone_set('Asia/Tokyo');
mb_internal_encoding('UTF-8');

// 任意の設定ファイル(config.local.php)で上書きできる。
//   <?php return ['data_dir' => '/var/lib/ianseo-lite', 'timezone' => 'Asia/Tokyo'];
$GLOBALS['LITE_CONFIG'] = [];
if (is_file(LITE_ROOT . '/config.local.php')) {
    $GLOBALS['LITE_CONFIG'] = (array)include LITE_ROOT . '/config.local.php';
}
if (!empty($GLOBALS['LITE_CONFIG']['timezone'])) {
    date_default_timezone_set($GLOBALS['LITE_CONFIG']['timezone']);
}

// ianseo の Modules/Custom に置いたときだけ、カスタムモジュール管理の「無効」を尊重する(単体で動かすときは何もしない)。
// State.php は ianseo 本体の関数を使わない軽い作りなので、ここから直接読める。
$cmState = dirname(LITE_ROOT) . '/ModuleManager/State.php';
if (is_file($cmState)) {
    require_once $cmState;
    cmBlockDisabledModule();
}
unset($cmState);

spl_autoload_register(function ($class) {
    if (strpos($class, 'Lite\\') === 0) {
        $file = LITE_ROOT . '/src/' . str_replace('\\', '/', substr($class, 5)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

function lite_config($key, $default = null)
{
    return $GLOBALS['LITE_CONFIG'][$key] ?? $default;
}

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
