<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

require_allowed_ip();
session_boot();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $u = $_SESSION['user'] ?? '';
    logout();
    auth_log('logout', (string)$u);
}
redirect('index.php');
