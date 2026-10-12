<?php
// ArcRec 共通の初期化。ianseo 本体には依存しない(単体で動く)。
if (defined('LITE_ROOT')) {
    return;
}
define('LITE_ROOT', dirname(__DIR__));
define('LITE_VERSION', '0.17.0');   // 版。変更の履歴は CHANGELOG.md

date_default_timezone_set('Asia/Tokyo');
mb_internal_encoding('UTF-8');

// 任意の設定ファイル(config.php)で上書きできる。
//   <?php return ['data_dir' => '/var/lib/arcrec', 'admin_password' => 'change-this'];
$GLOBALS['LITE_CONFIG'] = [];
$configFile = LITE_ROOT . '/config.php';
if (is_file($configFile)) {
    $GLOBALS['LITE_CONFIG'] = (array)include $configFile;
}
if (!empty($GLOBALS['LITE_CONFIG']['timezone'])) {
    date_default_timezone_set($GLOBALS['LITE_CONFIG']['timezone']);
}

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
