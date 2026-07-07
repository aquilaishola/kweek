<?php
// logout.php
require_once __DIR__ . '/includes/functions.php';
if (isset($_COOKIE['kweek_remember'])) {
    db()->prepare("UPDATE users SET remember_token = NULL WHERE remember_token = ?")->execute([$_COOKIE['kweek_remember']]);
    setcookie('kweek_remember', '', time() - 3600, '/', '', true, true);
}
logout();
