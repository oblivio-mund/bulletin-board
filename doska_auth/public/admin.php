<?php
declare(strict_types=1);
$user = require_admin();
$usersCount = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
$adsCount = (int)db()->query('SELECT COUNT(*) FROM ads')->fetchColumn();
$pendingCount = (int)db()->query("SELECT COUNT(*) FROM ads WHERE status='pending'")->fetchColumn();
$categoriesCount = (int)db()->query('SELECT COUNT(*) FROM categories')->fetchColumn();
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Панель администратора</title>
    <link rel="stylesheet" href="<?= e(route_url()) ?>../assets/css/auth.css">
</head>
<body class="admin-page">
<main class="site-shell">
    <header class="topbar admin-topbar">
        <a class="brand admin-brand" href="<?= e(route_url('admin')) ?>"><span class="brand-mark">Д</span><span>Доска объявлений <small>Панель управления</small></span></a>
        <nav class="admin-nav">
            <a class="is-active" href="<?= e(route_url('admin')) ?>">Обзор</a>
            <a href="<?= e(route_url('admin/users')) ?>">Пользователи</a>
            <a href="<?= e(route_url('admin/ads')) ?>">Модерация</a>
            <a href="<?= e(route_url('admin/categories')) ?>">Категории</a>
            <a href="<?= e(route_url('cabinet')) ?>">Кабинет</a>
            <form method="post" action="<?= e(route_url('logout')) ?>" class="logout-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button type="submit" class="logout-button">Выйти</button></form>
        </nav>
    </header>

    <section class="admin-welcome">
        <div>
            <span class="eyebrow">Администрирование</span>
            <h1>Панель управления</h1>
            <p>Контролируйте объявления, пользователей и справочники проекта из одного места.</p>
        </div>
        <div class="admin-welcome-badge"><span class="online-dot"></span> Режим администратора</div>
    </section>

    <section class="stats admin-stats" aria-label="Краткая статистика">
        <div class="stat-card stat-users"><span class="stat-icon">П</span><span class="stat-label">Пользователей</span><strong><?= $usersCount ?></strong><small>Учётные записи</small></div>
        <div class="stat-card stat-ads"><span class="stat-icon">О</span><span class="stat-label">Всего объявлений</span><strong><?= $adsCount ?></strong><small>Все статусы</small></div>
        <div class="stat-card stat-pending"><span class="stat-icon">М</span><span class="stat-label">Ожидают проверки</span><strong><?= $pendingCount ?></strong><small>Требуют модерации</small></div>
        <div class="stat-card stat-categories"><span class="stat-icon">К</span><span class="stat-label">Категорий</span><strong><?= $categoriesCount ?></strong><small>Справочник объявлений</small></div>
    </section>

    <section class="admin-section-heading">
        <div><h2>Быстрые действия</h2><p>Перейдите к нужному разделу управления.</p></div>
    </section>
    <section class="admin-module-grid">
        <a class="admin-module" href="<?= e(route_url('admin/ads')) ?>"><span class="module-mark module-mark-orange">01</span><span><strong>Все объявления</strong><small>Поиск, фильтрация и модерация публикаций</small></span><span class="module-arrow">→</span></a>
        <a class="admin-module" href="<?= e(route_url('admin/users')) ?>"><span class="module-mark module-mark-blue">02</span><span><strong>Пользователи</strong><small>Просмотр учётных записей и ролей</small></span><span class="module-arrow">→</span></a>
        <a class="admin-module" href="<?= e(route_url('admin/categories')) ?>"><span class="module-mark module-mark-green">03</span><span><strong>Категории</strong><small>Просмотр категорий и их состояния</small></span><span class="module-arrow">→</span></a>
    </section>
    <footer class="site-footer">Доска объявлений <span>·</span> Административная панель</footer>
</main>
</body>
</html>
