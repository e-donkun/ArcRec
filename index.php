<?php
// 入口。端末(ScorekeeperNG)からの POST は API、ブラウザには公開ページ(記録会一覧・記録会結果。admin/_public.php)
require_once __DIR__ . '/src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_REQUEST['requests'])) {
    Lite\IskApi::serve();
}
require __DIR__ . '/admin/_public.php';
public_page();
