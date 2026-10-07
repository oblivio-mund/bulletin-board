<?php
declare(strict_types=1);
$user = current_user();
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(APP_NAME) ?></title><link rel="stylesheet" href="../assets/css/auth.css">
</head>
<body>
<main class="site-shell">
    <header class="topbar">
        <a class="brand" href="<?= e(route_url()) ?>">Доска объявлений</a>
        <nav>
            <a href="<?= e(route_url('ads')) ?>">Объявления</a>
            <a href="<?= e(route_url('categories')) ?>">Категории</a>
            <a href="<?= e(route_url('cities')) ?>">Города</a>
            <?php if ($user): ?>
                <a href="<?= e(route_url('cabinet')) ?>">Личный кабинет</a>
                <?php if ($user['role'] === 'admin'): ?><a href="<?= e(route_url('admin')) ?>">Администрирование</a><?php endif; ?>
            <?php endif; ?>
        </nav>
    </header>
    <section class="hero-card">
        <span class="eyebrow">Учебный проект</span>
        <h1>Доска объявлений с геолокацией</h1>
        <p>Система публикации, поиска и просмотра объявлений с категориями, городами и географическими координатами.</p>
        <div class="actions">
            <?php if ($user): ?>
                <a class="button" href="<?= e(route_url('cabinet')) ?>">Открыть личный кабинет</a>
                <a class="button secondary" href="<?= e(route_url('ads')) ?>">Смотреть объявления</a>
            <?php else: ?>
                <a class="button" href="<?= e(route_url('login')) ?>">Войти</a>
                <a class="button secondary" href="<?= e(route_url('register')) ?>">Регистрация</a>
            <?php endif; ?>
        </div>
    </section>
</main>
</body>
</html>
