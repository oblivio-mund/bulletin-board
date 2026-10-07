<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf($_POST['csrf_token'] ?? null)) {
    logout_user();
}

header('Location: ' . route_url('login'));
exit;
