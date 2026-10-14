<?php
declare(strict_types=1);
$user = require_admin();
$items = db()->query('SELECT c.category_id,c.name,c.description,c.is_active,u.display_name AS creator FROM categories c LEFT JOIN users u ON u.user_id=c.created_by ORDER BY c.category_id')->fetchAll();
?>
<!doctype html>
<html lang="ru">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Управление категориями</title><link rel="stylesheet" href="<?= e(route_url()) ?>../assets/css/auth.css"></head>
<body class="admin-page">
<main class="site-shell">
    <header class="topbar admin-topbar">
        <a class="brand admin-brand" href="<?= e(route_url('admin')) ?>"><span class="brand-mark">Д</span><span>Доска объявлений <small>Панель управления</small></span></a>
        <nav class="admin-nav"><a href="<?= e(route_url('admin')) ?>">Обзор</a><a href="<?= e(route_url('admin/users')) ?>">Пользователи</a><a href="<?= e(route_url('admin/ads')) ?>">Модерация</a><a class="is-active" href="<?= e(route_url('admin/categories')) ?>">Категории</a><a href="<?= e(route_url('cabinet')) ?>">Кабинет</a><form method="post" action="<?= e(route_url('logout')) ?>" class="logout-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button type="submit" class="logout-button">Выйти</button></form></nav>
    </header>
    <section class="content-card admin-content">
        <div class="admin-page-heading"><div><span class="eyebrow">Справочник</span><h1>Категории</h1><p class="section-lead">Категории используются для классификации пользовательских объявлений.</p></div><span class="page-count"><?= count($items) ?> записей</span></div>
        <?php if (!$items): ?><div class="empty-state"><span class="empty-state-icon">＋</span><h2>Категорий пока нет</h2><p>После добавления категорий они отобразятся в этом разделе.</p></div><?php else: ?>
        <div class="table-wrap admin-table-wrap"><table class="admin-table"><thead><tr><th>ID</th><th>Название</th><th>Описание</th><th>Создал</th><th>Состояние</th></tr></thead><tbody>
        <?php foreach ($items as $item): ?><tr><td><span class="id-chip">#<?= (int)$item['category_id'] ?></span></td><td><div class="table-primary-text"><?= e($item['name']) ?></div></td><td class="description-cell"><?= e($item['description'] ?? 'Описание не добавлено') ?></td><td><?= e($item['creator'] ?? 'Система') ?></td><td><span class="status <?= (int)$item['is_active'] ? 'status-active' : 'status-disabled' ?>"><?= (int)$item['is_active'] ? 'Активна' : 'Отключена' ?></span></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </section>
    <footer class="site-footer">Доска объявлений <span>·</span> Категории</footer>
</main>
</body>
</html>
