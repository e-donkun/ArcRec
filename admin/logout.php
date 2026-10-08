<?php
require __DIR__ . '/_layout.php';
Lite\Auth::logout();
header('Location: ./');
