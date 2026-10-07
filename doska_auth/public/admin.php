<?php
declare(strict_types=1);
$user = require_admin();
$usersCount = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
$adsCount = (int)db()->query('SELECT COUNT(*) FROM ads')->fetchColumn();
$pendingCount = (int)db()->query("SELECT COUNT(*) FROM ads WHERE status='pending'")->fetchColumn();
$categoriesCount = (int)db()->query('SELECT COUNT(*) FROM categories')->fetchColumn();
?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Администрирование</title><link rel="stylesheet" href="../assets/css/auth.css"></head><body><main class="site-shell"><header class="topbar"><a class="brand" href="<?= e(route_url()) ?>">Доска объявлений</a><nav><a href="<?= e(route_url('cabinet')) ?>">Кабинет</a><a href="<?= e(route_url('admin/users')) ?>">Пользователи</a><a href="<?= e(route_url('admin/ads')) ?>">Модерация</a><a href="<?= e(route_url('admin/categories')) ?>">Категории</a></nav></header><section class="content-card"><span class="eyebrow">Только admin</span><h1>Панель администратора</h1><div class="stats"><div><strong><?= $usersCount ?></strong><span>Пользователей</span></div><div><strong><?= $adsCount ?></strong><span>Объявлений</span></div><div><strong><?= $pendingCount ?></strong><span>На модерации</span></div><div><strong><?= $categoriesCount ?></strong><span>Категорий</span></div></div><div class="admin-links"><a class="button" href="<?= e(route_url('admin/users')) ?>">Пользователи</a><a class="button" href="<?= e(route_url('admin/ads')) ?>">Объявления</a><a class="button" href="<?= e(route_url('admin/categories')) ?>">Категории</a></div></section></main></body></html>
