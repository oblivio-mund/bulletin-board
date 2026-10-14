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
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Модерация объявлений</title>
    <link rel="stylesheet" href="<?= e(route_url()) ?>../assets/css/auth.css">
</head>
<body class="admin-page">
<main class="site-shell">
    <header class="topbar admin-topbar">
        <a class="brand admin-brand" href="<?= e(route_url('admin')) ?>"><span class="brand-mark">Д</span><span>Доска объявлений <small>Панель управления</small></span></a>
        <nav class="admin-nav">
            <a href="<?= e(route_url('admin')) ?>">Обзор</a>
            <a href="<?= e(route_url('admin/users')) ?>">Пользователи</a>
            <a class="is-active" href="<?= e(route_url('admin/ads')) ?>">Модерация</a>
            <a href="<?= e(route_url('admin/categories')) ?>">Категории</a>
            <a href="<?= e(route_url('cabinet')) ?>">Кабинет</a>
            <form method="post" action="<?= e(route_url('logout')) ?>" class="logout-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button type="submit" class="logout-button">Выйти</button></form>
        </nav>
    </header>
    <section class="content-card admin-content">
        <div class="admin-page-heading">
            <div><span class="eyebrow">Работа с публикациями</span><h1>Модерация объявлений</h1><p class="section-lead">Проверяйте объявления и принимайте решение о публикации.</p></div>
            <span class="page-count"><?= count($items) ?> записей</span>
        </div>
        <?php foreach ($errors as $error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endforeach; ?>
        <div class="moderation-note"><span class="moderation-note-mark">i</span><p>При одобрении объявление становится активным. Отклонение и архивирование сохраняются в истории модерации.</p></div>
        <?php if (!$items): ?>
            <div class="empty-state"><span class="empty-state-icon">✓</span><h2>Объявлений пока нет</h2><p>Когда пользователи добавят публикации, они появятся в этом списке.</p></div>
        <?php else: ?>
            <div class="table-wrap admin-table-wrap">
                <table class="admin-table"><thead><tr><th>ID</th><th>Объявление</th><th>Автор и категория</th><th>Город</th><th>Статус</th><th>Решение модератора</th></tr></thead><tbody>
                <?php foreach ($items as $item): ?>
                    <?php $statusLabels = ['pending'=>'На проверке','active'=>'Активно','rejected'=>'Отклонено','archived'=>'В архиве','deleted'=>'Удалено','draft'=>'Черновик']; ?>
                    <tr>
                        <td><span class="id-chip">#<?= (int)$item['ad_id'] ?></span></td>
                        <td><div class="table-primary-text"><?= e($item['title']) ?></div><div class="table-secondary-text"><?= $item['price'] !== null ? e(number_format((float)$item['price'], 2, ',', ' ')) . ' ₽' : 'Цена не указана' ?></div></td>
                        <td><div class="table-primary-text"><?= e($item['author']) ?></div><div class="table-secondary-text"><?= e($item['category_name']) ?></div></td>
                        <td><?= e($item['city_name'] ?? '—') ?></td>
                        <td><span class="status status-<?= e($item['status']) ?>"><?= e($statusLabels[$item['status']] ?? $item['status']) ?></span></td>
                        <td>
                            <form class="moderation-form" method="post" action="<?= e(route_url('admin/ads')) ?>">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="ad_id" value="<?= (int)$item['ad_id'] ?>">
                                <input class="moderation-comment" name="comment" maxlength="1000" placeholder="Комментарий (необязательно)" aria-label="Комментарий модератора">
                                <div class="moderation-controls"><select name="status" aria-label="Решение"><option value="active">Одобрить</option><option value="rejected">Отклонить</option><option value="archived">В архив</option></select><button type="submit" class="button button-small">Применить</button></div>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table>
            </div>
        <?php endif; ?>
    </section>
    <footer class="site-footer">Доска объявлений <span>·</span> Модерация</footer>
</main>
</body>
</html>
