<?php
// ScorekeeperNG(Lite モード)の接続先。サーバーURLは「このフォルダの 2 つ上」(…/ianseo-lite/)を指定する。
require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
Lite\IskApi::serve();
