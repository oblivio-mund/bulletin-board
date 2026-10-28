<?php
declare(strict_types=1);
$user = current_user();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

try {
    $stmt = db()->prepare(
        'SELECT a.*, u.display_name AS author_name, u.login AS author_login, '
        . 'c.name AS category_name, ci.name AS city_name, ci.region AS city_region, '
        . 'ST_Y(a.location) AS latitude, ST_X(a.location) AS longitude '
        . 'FROM ads a JOIN users u ON u.user_id=a.author_id '
        . 'JOIN categories c ON c.category_id=a.category_id '
        . 'LEFT JOIN cities ci ON ci.city_id=a.city_id WHERE a.ad_id=:id LIMIT 1'
    );
    $stmt->execute(['id' => $id]);
    $ad = $stmt->fetch();
} catch (PDOException $exception) {
    error_log('Ad detail query failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Не удалось загрузить объявление. Проверьте подключение к базе данных.');
}

$isAdmin = $user !== null && $user['role'] === 'admin';
$isOwner = $user !== null && (int)$user['user_id'] === (int)($ad['author_id'] ?? 0);
if (!$ad || ($ad['status'] !== 'active' && !$isOwner && !$isAdmin) || ($ad['status'] === 'deleted' && !$isAdmin)) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

$images = [];
try {
    $imageStmt = db()->prepare('SELECT image_url FROM ad_images WHERE ad_id=:id ORDER BY sort_order, image_id');
    $imageStmt->execute(['id' => $id]);
    $images = $imageStmt->fetchAll();
} catch (PDOException $exception) {
    error_log('Ad images query failed: ' . $exception->getMessage());
}
$statusLabels = [
    'draft' => 'Черновик',
    'pending' => 'На проверке',
    'active' => 'Активно',
    'rejected' => 'Отклонено',
    'archived' => 'В архиве',
    'deleted' => 'Удалено',
];
$safeImages = [];
foreach ($images as $image) {
    $url = trim((string)$image['image_url']);
    if (preg_match('#^https?://#i', $url) && filter_var($url, FILTER_VALIDATE_URL)) {
        $safeImages[] = $url;
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($ad['title']) ?> — <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(route_url()) ?>../assets/css/auth.css?v=20261028"><link rel="stylesheet" href="<?= e(route_url()) ?>../assets/css/detail.css?v=20261028">
</head>
<body>
<main class="site-shell">
    <header class="topbar">
        <a class="brand" href="<?= e(route_url()) ?>">Доска объявлений</a>
        <nav>
            <a href="<?= e(route_url('ads')) ?>">Объявления</a>
            <?php if ($user): ?>
                <a href="<?= e(route_url('cabinet')) ?>">Кабинет</a>
                <?php if ($isAdmin): ?><a href="<?= e(route_url('admin/ads')) ?>">Все записи</a><?php endif; ?>
                <form method="post" action="<?= e(route_url('logout')) ?>" class="logout-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button type="submit" class="logout-button">Выйти</button></form>
            <?php else: ?>
                <a href="<?= e(route_url('login')) ?>">Войти</a>
            <?php endif; ?>
        </nav>
    </header>

    <section class="content-card ad-detail-card">
        <a class="back-link" href="<?= e(route_url('ads')) ?>">← Назад к объявлениям</a>
        <div class="ad-detail-heading">
            <div><span class="tag"><?= e($ad['category_name']) ?></span><h1><?= e($ad['title']) ?></h1><p class="detail-meta">Опубликовано <?= e(date('d.m.Y H:i', strtotime((string)$ad['published_at']))) ?> · Объявление №<?= (int)$ad['ad_id'] ?></p></div>
            <?php if ($isOwner || $isAdmin): ?><span class="status status-<?= e($ad['status']) ?>"><?= e($statusLabels[$ad['status']] ?? $ad['status']) ?></span><?php endif; ?>
        </div>
        <div class="ad-detail-main">
            <div class="ad-description-block"><h2>Описание</h2><div class="ad-full-description"><?= nl2br(e($ad['description'])) ?></div></div>
            <aside class="ad-price-panel"><span>Стоимость</span><strong><?= $ad['price'] !== null ? e(number_format((float)$ad['price'], 2, ',', ' ')) . ' ₽' : 'Договорная' ?></strong><div class="price-panel-divider"></div><p><b>Автор</b><span><?= e($ad['author_name']) ?></span></p><p><b>Город</b><span><?= e($ad['city_name'] ?? 'Не указан') ?><?= !empty($ad['city_region']) ? ', ' . e($ad['city_region']) : '' ?></span></p></aside>
        </div>

        <?php if ($safeImages): ?>
            <section class="detail-section"><h2>Фотографии</h2><div class="detail-images"><?php foreach ($safeImages as $imageUrl): ?><a href="<?= e($imageUrl) ?>" target="_blank" rel="noopener noreferrer"><img src="<?= e($imageUrl) ?>" alt="Фото объявления <?= e($ad['title']) ?>" loading="lazy"></a><?php endforeach; ?></div></section>
        <?php endif; ?>

        <section class="detail-section"><h2>Местоположение</h2><div class="profile-grid detail-info-grid">
            <p><b>Город:</b><span><?= e($ad['city_name'] ?? 'Не указан') ?></span></p>
            <p><b>Широта:</b><span><?= e((string)$ad['latitude']) ?></span></p>
            <p><b>Долгота:</b><span><?= e((string)$ad['longitude']) ?></span></p>
            <p><b>Дата обновления:</b><span><?= e(date('d.m.Y H:i', strtotime((string)$ad['updated_at']))) ?></span></p>
        </div>
        <?php if (is_numeric($ad['latitude']) && is_numeric($ad['longitude'])): ?>
            <a class="button secondary" target="_blank" rel="noopener noreferrer" href="https://www.openstreetmap.org/?mlat=<?= rawurlencode((string)$ad['latitude']) ?>&mlon=<?= rawurlencode((string)$ad['longitude']) ?>#map=16/<?= rawurlencode((string)$ad['latitude']) ?>/<?= rawurlencode((string)$ad['longitude']) ?>">Открыть на карте</a>
        <?php endif; ?>
        </section>

        <?php if (($isOwner || $isAdmin) && !empty($ad['moderation_comment'])): ?><div class="moderation-note detail-moderation-note"><span class="moderation-note-mark">i</span><p><b>Комментарий модератора:</b> <?= e((string)$ad['moderation_comment']) ?></p></div><?php endif; ?>
        <div class="actions detail-actions">
            <?php if ($isOwner && $ad['status'] !== 'deleted'): ?><a class="button" href="<?= e(route_url('ads/edit')) ?>?id=<?= (int)$ad['ad_id'] ?>">Редактировать объявление</a><?php endif; ?>
            <?php if ($isAdmin): ?><a class="button" href="<?= e(route_url('admin/ads')) ?>">Вернуться к списку в админке</a><?php endif; ?>
            <a class="button secondary" href="<?= e(route_url('ads')) ?>">К списку объявлений</a>
        </div>
    </section>
    <footer class="site-footer">Доска объявлений <span>·</span> Подробная информация</footer>
</main>
</body>
</html>
