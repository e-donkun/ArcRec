<?php
// 選手登録の入力候補(過去の大会から)。JSON を返す
require __DIR__ . '/_layout.php';
require_admin();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
echo json_encode(Lite\Past::athletes($_GET['q'] ?? ''), JSON_UNESCAPED_UNICODE);
