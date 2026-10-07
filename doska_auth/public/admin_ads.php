<?php
declare(strict_types=1);
$user = require_admin();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Недействительный CSRF-токен.';
    } else {
        $adId = (int)($_POST['ad_id'] ?? 0);
        $newStatus = (string)($_POST['status'] ?? '');
        $comment = trim((string)($_POST['comment'] ?? ''));
        $allowed = ['active','rejected','archived'];
        if ($adId <= 0 || !in_array($newStatus, $allowed, true)) {
            $errors[] = 'Некорректные параметры модерации.';
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('SELECT status FROM ads WHERE ad_id=:id FOR UPDATE');
                $stmt->execute(['id' => $adId]);
                $oldStatus = $stmt->fetchColumn();
                if ($oldStatus === false) {
                    throw new RuntimeException('Объявление не найдено.');
                }
                $stmt = $pdo->prepare('UPDATE ads SET status=:status, moderated_by=:admin, moderated_at=CURRENT_TIMESTAMP, moderation_comment=:comment WHERE ad_id=:id');
                $stmt->execute(['status'=>$newStatus,'admin'=>$user['user_id'],'comment'=>$comment !== '' ? $comment : null,'id'=>$adId]);
                $stmt = $pdo->prepare('INSERT INTO ad_moderation_history (ad_id,admin_id,old_status,new_status,comment) VALUES (:ad,:admin,:old,:new,:comment)');
                $stmt->execute(['ad'=>$adId,'admin'=>$user['user_id'],'old'=>$oldStatus,'new'=>$newStatus,'comment'=>$comment !== '' ? $comment : null]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors[] = 'Не удалось изменить статус объявления.';
            }
        }
    }
}
$items = db()->query('SELECT a.ad_id,a.title,a.price,a.status,a.published_at,u.display_name AS author,c.name AS category_name,ci.name AS city_name FROM ads a JOIN users u ON u.user_id=a.author_id JOIN categories c ON c.category_id=a.category_id LEFT JOIN cities ci ON ci.city_id=a.city_id ORDER BY FIELD(a.status,\'pending\',\'active\',\'rejected\',\'archived\',\'deleted\',\'draft\'),a.published_at DESC')->fetchAll();
?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Модерация объявлений</title><link rel="stylesheet" href="../assets/css/auth.css"></head><body><main class="site-shell"><header class="topbar"><a class="brand" href="<?= e(route_url('admin')) ?>">Администрирование</a><nav><a href="<?= e(route_url('admin/users')) ?>">Пользователи</a><a href="<?= e(route_url('admin/categories')) ?>">Категории</a><a href="<?= e(route_url('cabinet')) ?>">Кабинет</a></nav></header><section class="content-card"><h1>Модерация объявлений</h1><?php foreach($errors as $error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endforeach; ?><div class="table-wrap"><table><thead><tr><th>ID</th><th>Объявление</th><th>Автор</th><th>Категория</th><th>Город</th><th>Статус</th><th>Действие</th></tr></thead><tbody><?php foreach($items as $item): ?><tr><td><?= (int)$item['ad_id'] ?></td><td><?= e($item['title']) ?></td><td><?= e($item['author']) ?></td><td><?= e($item['category_name']) ?></td><td><?= e($item['city_name'] ?? '—') ?></td><td><span class="status status-<?= e($item['status']) ?>"><?= e($item['status']) ?></span></td><td><form class="inline-form" method="post" action="<?= e(route_url('admin/ads')) ?>"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="ad_id" value="<?= (int)$item['ad_id'] ?>"><input name="comment" placeholder="Комментарий"><select name="status"><option value="active">Одобрить</option><option value="rejected">Отклонить</option><option value="archived">Архивировать</option></select><button type="submit">Сохранить</button></form></td></tr><?php endforeach; ?></tbody></table></div></section></main></body></html>
