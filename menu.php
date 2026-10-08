<?php
/*
ianseo-lite（簡易記録会システム）の管理画面へのリンクを、メニューの「モジュール」に追加する。
ianseo 本体の大会データとは無関係に動くので、大会を開いていなくても出す。
*/
$ret['MODS'][] = 'ianseo-lite（簡易記録会）' . '|' . $CFG->ROOT_DIR . 'Modules/Custom/ianseo-lite/admin/';
