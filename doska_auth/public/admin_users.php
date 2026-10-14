<?php
declare(strict_types=1);
$user = require_admin();
$items = db()->query('SELECT user_id,display_name,login,role,is_active,created_at FROM users ORDER BY user_id')->fetchAll();
?>
<!doctype html>
<html lang="ru">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Пользователи</title><link rel="stylesheet" href="<?= e(route_url()) ?>../assets/css/auth.css"></head>
<body class="admin-page">
<main class="site-shell">
    <header class="topbar admin-topbar">
        <a class="brand admin-brand" href="<?= e(route_url('admin')) ?>"><span class="brand-mark">Д</span><span>Доска объявлений <small>Панель управления</small></span></a>
        <nav class="admin-nav"><a href="<?= e(route_url('admin')) ?>">Обзор</a><a class="is-active" href="<?= e(route_url('admin/users')) ?>">Пользователи</a><a href="<?= e(route_url('admin/ads')) ?>">Модерация</a><a href="<?= e(route_url('admin/categories')) ?>">Категории</a><a href="<?= e(route_url('cabinet')) ?>">Кабинет</a><form method="post" action="<?= e(route_url('logout')) ?>" class="logout-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button type="submit" class="logout-button">Выйти</button></form></nav>
    </header>
    <section class="content-card admin-content">
        <div class="admin-page-heading"><div><span class="eyebrow">Управление доступом</span><h1>Пользователи</h1><p class="section-lead">Список зарегистрированных пользователей, их ролей и состояния учётных записей.</p></div><span class="page-count"><?= count($items) ?> аккаунтов</span></div>
        <?php if (!$items): ?><div class="empty-state"><span class="empty-state-icon">◎</span><h2>Пользователей пока нет</h2><p>Зарегистрированные аккаунты будут отображаться здесь.</p></div><?php else: ?>
        <div class="table-wrap admin-table-wrap"><table class="admin-table"><thead><tr><th>ID</th><th>Пользователь</th><th>Логин</th><th>Роль</th><th>Состояние</th><th>Дата регистрации</th></tr></thead><tbody>
        <?php foreach ($items as $item): ?><tr><td><span class="id-chip">#<?= (int)$item['user_id'] ?></span></td><td><div class="user-cell"><span class="avatar-placeholder"><?= e(mb_strtoupper(mb_substr((string)$item['display_name'], 0, 1))) ?></span><span class="table-primary-text"><?= e($item['display_name']) ?></span></div></td><td><code class="login-chip"><?= e($item['login']) ?></code></td><td><span class="role-badge <?= $item['role'] === 'admin' ? 'role-admin' : 'role-user' ?>"><?= $item['role'] === 'admin' ? 'Администратор' : 'Пользователь' ?></span></td><td><span class="status <?= (int)$item['is_active'] ? 'status-active' : 'status-disabled' ?>"><span class="status-dot"></span><?= (int)$item['is_active'] ? 'Активен' : 'Отключён' ?></span></td><td><?= e((string)$item['created_at']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </section>
    <footer class="site-footer">Доска объявлений <span>·</span> Пользователи</footer>
</main>
</body>
</html>
