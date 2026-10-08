<?php
// 入口。端末(ScorekeeperNG)からの POST は API として処理し、ブラウザなら管理画面へ。
require_once __DIR__ . '/src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_REQUEST['requests'])) {
    Lite\IskApi::serve();
}
header('Location: admin/');
