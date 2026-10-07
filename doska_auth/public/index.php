<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

$route = trim((string)($_GET['route'] ?? ''), '/');

$routes = [
    '' => __DIR__ . '/home.php',
    'login' => __DIR__ . '/login.php',
    'register' => __DIR__ . '/register.php',
    'logout' => __DIR__ . '/logout.php',
    'cabinet' => __DIR__ . '/cabinet.php',
    'ads' => __DIR__ . '/ads.php',
    'categories' => __DIR__ . '/categories.php',
    'cities' => __DIR__ . '/cities.php',
    'admin' => __DIR__ . '/admin.php',
    'admin/users' => __DIR__ . '/admin_users.php',
    'admin/ads' => __DIR__ . '/admin_ads.php',
    'admin/categories' => __DIR__ . '/admin_categories.php',
];

if (!isset($routes[$route])) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

require $routes[$route];
