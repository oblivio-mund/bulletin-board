<?php
declare(strict_types=1);
$user = require_auth();

$stmt = db()->prepare('SELECT COUNT(*) FROM ads WHERE author_id = :id AND status <> \'deleted\'');
$stmt->execute(['id' => $user['user_id']]);
$myAdsCount = (int)$stmt->fetchColumn();

$stmt = db()->prepare('SELECT COUNT(*) FROM favorites WHERE user_id = :id');
$stmt->execute(['id' => $user['user_id']]);
$favoritesCount = (int)$stmt->fetchColumn();

$stmt = db()->prepare('SELECT a.ad_id, a.title, a.price, a.status, a.published_at, c.name AS category_name, ci.name AS city_name FROM ads a JOIN categories c ON c.category_id=a.category_id LEFT JOIN cities ci ON ci.city_id=a.city_id WHERE a.author_id=:id AND a.status <> \'deleted\' ORDER BY a.published_at DESC');
$stmt->execute(['id' => $user['user_id']]);
$ads = $stmt->fetchAll();
?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Личный кабинет</title><link rel="stylesheet" href="../assets/css/auth.css"></head>
<body><main class="site-shell"><header class="topbar"><a class="brand" href="<?= e(route_url()) ?>">Доска объявлений</a><nav><a href="<?= e(route_url('ads')) ?>">Объявления</a><a href="<?= e(route_url('categories')) ?>">Категории</a><a href="<?= e(route_url('cities')) ?>">Города</a><?php if($user['role']==='admin'): ?><a href="<?= e(route_url('admin')) ?>">Администрирование</a><?php endif; ?></nav></header>
<section class="content-card"><div class="page-head"><div><span class="eyebrow">Личный кабинет</span><h1><?= e($user['display_name']) ?></h1></div><span class="role-badge"><?= e($user['role']) ?></span></div>
<div class="stats"><div><strong><?= $myAdsCount ?></strong><span>Моих объявлений</span></div><div><strong><?= $favoritesCount ?></strong><span>Избранных</span></div></div>
<div class="profile-grid"><p><b>Логин:</b> <?= e($user['login']) ?></p><p><b>Роль:</b> <?= e($user['role']) ?></p><p><b>Дата регистрации:</b> <?= e((string)$user['created_at']) ?></p></div>
<h2>Мои объявления</h2><?php if(!$ads): ?><p>У вас пока нет объявлений.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>ID</th><th>Название</th><th>Категория</th><th>Город</th><th>Цена</th><th>Статус</th></tr></thead><tbody><?php foreach($ads as $ad): ?><tr><td><?= (int)$ad['ad_id'] ?></td><td><?= e($ad['title']) ?></td><td><?= e($ad['category_name']) ?></td><td><?= e($ad['city_name'] ?? 'Не указан') ?></td><td><?= e((string)$ad['price']) ?></td><td><span class="status status-<?= e($ad['status']) ?>"><?= e($ad['status']) ?></span></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section></main></body></html>
