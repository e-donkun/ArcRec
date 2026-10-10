<?php
// 開いている記録会を閉じて、記録会選択に戻る(管理者のログインはそのまま)
require __DIR__ . '/_layout.php';
Lite\Auth::closeCompetition();
header('Location: index.php');
