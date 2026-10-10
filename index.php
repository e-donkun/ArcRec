<?php
// 入口。端末(ScorekeeperNG)からの POST は API として処理し、ブラウザには公開の記録会結果(results.php)を出す。管理画面は admin/
require_once __DIR__ . '/src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_REQUEST['requests'])) {
    Lite\IskApi::serve();
}
require __DIR__ . '/results.php';
