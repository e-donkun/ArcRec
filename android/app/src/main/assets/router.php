<?php
// Android アプリ用: PHP 組み込みサーバーのルーター。
// Apache の .htaccess の代わりに、ブラウザから直接開かせたくない場所を 404 にする。
// それ以外は false を返し、組み込みサーバーにそのまま処理させる(PHP の実行・静的ファイル・index.php)。
$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$path = '/' . ltrim(str_replace('\\', '/', $path), '/');

$deny = '#^/(data|src|lib|tests|tools|android)(/|$)'
    . '|^/(config(\.sample)?\.php|AGENTS\.md|CHANGELOG\.md)$'
    . '|/\.#i';
if (preg_match($deny, $path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Not Found\n";
    return true;
}
return false;
