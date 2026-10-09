<?php
// 結果一覧(管理画面)。参加者向けの公開ページはトップの results.php
require __DIR__ . '/_layout.php';
require __DIR__ . '/_results.php';
require_admin();

results_page(Lite\Settings::code(), false);
