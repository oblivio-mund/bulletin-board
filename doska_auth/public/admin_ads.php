<?php
declare(strict_types=1);
$user = require_admin();
$errors = [];

$q = trim((string)($_GET['q'] ?? ''));
$q = function_exists('mb_substr') ? mb_substr($q, 0, 120) : substr($q, 0, 120);
$statusFilter = (string)($_GET['status'] ?? 'all');
$allowedFilters = ['all', 'draft', 'pending', 'active', 'rejected', 'archived', 'deleted'];
if (!in_array($statusFilter, $allowedFilters, true)) {
    $statusFilter = 'all';
}
$categoryId = max(0, (int)($_GET['category_id'] ?? 0));
$cityId = max(0, (int)($_GET['city_id'] ?? 0));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$statusLabels = [
    'draft' => 'Черновик',
    'pending' => 'На проверке',
    'active' => 'Активно',
    'rejected' => 'Отклонено',
    'archived' => 'В архиве',
    'deleted' => 'Удалено',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Недействительный CSRF-токен. Обновите страницу и повторите действие.';
    } else {
        $adId = (int)($_POST['ad_id'] ?? 0);
        $newStatus = (string)($_POST['status'] ?? '');
        $comment = trim((string)($_POST['comment'] ?? ''));
        $allowedChanges = ['active', 'rejected', 'archived'];
        if ($adId <= 0 || !in_array($newStatus, $allowedChanges, true) || mb_strlen($comment) > 1000) {
            $errors[] = 'Некорректные параметры модерации.';
        } else {
            $pdo = db();
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('SELECT status FROM ads WHERE ad_id=:id FOR UPDATE');
                $stmt->execute(['id' => $adId]);
                $oldStatus = $stmt->fetchColumn();
                if ($oldStatus === false) {
                    throw new RuntimeException('Объявление не найдено.');
                }
                if ($oldStatus === 'deleted') {
                    throw new RuntimeException('Удалённое объявление нельзя изменить через модерацию.');
                }
                $stmt = $pdo->prepare('UPDATE ads SET status=:status, moderated_by=:admin, moderated_at=CURRENT_TIMESTAMP, moderation_comment=:comment WHERE ad_id=:id');
                $stmt->execute([
                    'status' => $newStatus,
                    'admin' => $user['user_id'],
                    'comment' => $comment !== '' ? $comment : null,
                    'id' => $adId,
                ]);
                $stmt = $pdo->prepare('INSERT INTO ad_moderation_history (ad_id,admin_id,old_status,new_status,comment) VALUES (:ad,:admin,:old,:new,:comment)');
                $stmt->execute([
                    'ad' => $adId,
                    'admin' => $user['user_id'],
                    'old' => $oldStatus,
                    'new' => $newStatus,
                    'comment' => $comment !== '' ? $comment : null,
                ]);
                $pdo->commit();
                flash('success', 'Статус объявления обновлён.');
                $redirectParams = ['q' => $q, 'status' => $statusFilter, 'category_id' => $categoryId, 'city_id' => $cityId, 'page' => $page];
                header('Location: ' . route_url('admin/ads') . '?' . http_build_query($redirectParams));
                exit;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($exception instanceof RuntimeException && in_array($exception->getMessage(), ['Объявление не найдено.', 'Удалённое объявление нельзя изменить через модерацию.'], true)) {
                    $errors[] = $exception->getMessage();
                } else {
                    error_log('Admin moderation failed: ' . $exception->getMessage());
                    $errors[] = 'Не удалось изменить статус объявления. Проверьте данные и подключение к базе данных.';
                }
            }
        }
    }
}

$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = '(a.title LIKE :q_title OR a.description LIKE :q_description OR u.display_name LIKE :q_name OR u.login LIKE :q_login)';
    $like = '%' . $q . '%';
    $params['q_title'] = $like;
    $params['q_description'] = $like;
    $params['q_name'] = $like;
    $params['q_login'] = $like;
}
if ($statusFilter !== 'all') {
    $where[] = 'a.status = :status_filter';
    $params['status_filter'] = $statusFilter;
}
if ($categoryId > 0) {
    $where[] = 'a.category_id = :category_id';
    $params['category_id'] = $categoryId;
}
if ($cityId > 0) {
    $where[] = 'a.city_id = :city_id';
    $params['city_id'] = $cityId;
}
$whereSql = implode(' AND ', $where);
$filterParams = ['q' => $q, 'status' => $statusFilter, 'category_id' => $categoryId, 'city_id' => $cityId];
$filterQuery = http_build_query($filterParams);

$items = [];
$categories = [];
$cities = [];
$filteredCount = 0;
$allCount = 0;
$totalPages = 1;
$pageError = null;
try {
    $pdo = db();
    $categories = $pdo->query('SELECT category_id, name FROM categories ORDER BY name')->fetchAll();
    $cities = $pdo->query('SELECT city_id, name, region FROM cities ORDER BY name, region')->fetchAll();
    $allCount = (int)$pdo->query('SELECT COUNT(*) FROM ads')->fetchColumn();
    $baseFrom = ' FROM ads a JOIN users u ON u.user_id=a.author_id JOIN categories c ON c.category_id=a.category_id LEFT JOIN cities ci ON ci.city_id=a.city_id ';
    $countStmt = $pdo->prepare('SELECT COUNT(*)' . $baseFrom . ' WHERE ' . $whereSql);
    $countStmt->execute($params);
    $filteredCount = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($filteredCount / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;
    $sql = 'SELECT a.ad_id, a.title, a.description, a.price, a.status, a.published_at, a.moderation_comment, '
        . 'u.display_name AS author, u.login AS author_login, c.name AS category_name, ci.name AS city_name '
        . $baseFrom . ' WHERE ' . $whereSql
        . " ORDER BY CASE a.status WHEN 'pending' THEN 1 WHEN 'draft' THEN 2 WHEN 'active' THEN 3 WHEN 'rejected' THEN 4 WHEN 'archived' THEN 5 WHEN 'deleted' THEN 6 ELSE 7 END, a.published_at DESC"
        . ' LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();
} catch (PDOException $exception) {
    error_log('Admin ads listing failed: ' . $exception->getMessage());
    $pageError = 'Не удалось загрузить список объявлений. Проверьте подключение к базе данных.';
}
$flashMessage = consume_flash();
$paginationParams = $filterParams;
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Все объявления — панель администратора</title>
    <link rel="stylesheet" href="<?= e(route_url()) ?>../assets/css/auth.css">
</head>
<body class="admin-page">
<main class="site-shell">
    <header class="topbar admin-topbar">
        <a class="brand admin-brand" href="<?= e(route_url('admin')) ?>"><span class="brand-mark">Д</span><span>Доска объявлений <small>Панель управления</small></span></a>
        <nav class="admin-nav">
            <a href="<?= e(route_url('admin')) ?>">Обзор</a>
            <a href="<?= e(route_url('admin/users')) ?>">Пользователи</a>
            <a class="is-active" href="<?= e(route_url('admin/ads')) ?>">Все объявления</a>
            <a href="<?= e(route_url('admin/categories')) ?>">Категории</a>
            <a href="<?= e(route_url('cabinet')) ?>">Кабинет</a>
            <form method="post" action="<?= e(route_url('logout')) ?>" class="logout-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button type="submit" class="logout-button">Выйти</button></form>
        </nav>
    </header>

    <section class="content-card admin-content">
        <div class="admin-page-heading">
            <div><span class="eyebrow">Администрирование · записи</span><h1>Все объявления</h1><p class="section-lead">Поиск, фильтрация, детальный просмотр и модерация всех записей, включая архивные и удалённые.</p></div>
            <span class="page-count"><?= $filteredCount ?> из <?= $allCount ?> записей</span>
        </div>

        <?php if ($flashMessage): ?><div class="alert alert-<?= e($flashMessage['type']) ?>" role="status"><?= e($flashMessage['message']) ?></div><?php endif; ?>
        <?php foreach ($errors as $error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endforeach; ?>
        <?php if ($pageError): ?><div class="alert alert-error" role="alert"><?= e($pageError) ?></div><?php endif; ?>

        <form method="get" action="<?= e(route_url('admin/ads')) ?>" class="filters-panel admin-filters">
            <div class="filters-grid admin-filters-grid">
                <label class="filter-field filter-search"><span>Поиск</span><input type="search" name="q" maxlength="120" value="<?= e($q) ?>" placeholder="Название, описание, автор или логин"></label>
                <label class="filter-field"><span>Статус</span><select name="status"><option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Все статусы</option><?php foreach ($statusLabels as $key => $label): ?><option value="<?= e($key) ?>" <?= $statusFilter === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                <label class="filter-field"><span>Категория</span><select name="category_id"><option value="0">Все категории</option><?php foreach ($categories as $category): ?><option value="<?= (int)$category['category_id'] ?>" <?= $categoryId === (int)$category['category_id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option><?php endforeach; ?></select></label>
                <label class="filter-field"><span>Город</span><select name="city_id"><option value="0">Все города</option><?php foreach ($cities as $city): ?><option value="<?= (int)$city['city_id'] ?>" <?= $cityId === (int)$city['city_id'] ? 'selected' : '' ?>><?= e($city['name']) ?><?= !empty($city['region']) ? ' — ' . e($city['region']) : '' ?></option><?php endforeach; ?></select></label>
            </div>
            <div class="filter-actions"><button type="submit" class="button">Применить фильтры</button><a class="button secondary" href="<?= e(route_url('admin/ads')) ?>">Сбросить</a></div>
        </form>

        <div class="moderation-note"><span class="moderation-note-mark">i</span><p>Выберите «Подробнее», чтобы открыть карточку объявления. Решения модерации сохраняются в истории. Записи со статусом «Удалено» доступны для просмотра, но не могут быть повторно опубликованы из этого списка.</p></div>

        <?php if (!$pageError && !$items): ?>
            <div class="empty-state"><span class="empty-state-icon">⌕</span><h2>Записи не найдены</h2><p>Измените поисковый запрос или сбросьте фильтры.</p><a class="button secondary" href="<?= e(route_url('admin/ads')) ?>">Показать все записи</a></div>
        <?php elseif ($items): ?>
            <div class="table-wrap admin-table-wrap">
                <table class="admin-table"><thead><tr><th>ID</th><th>Объявление</th><th>Автор и категория</th><th>Город</th><th>Статус</th><th>Действия и модерация</th></tr></thead><tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><span class="id-chip">#<?= (int)$item['ad_id'] ?></span></td>
                        <td><div class="table-primary-text"><?= e($item['title']) ?></div><div class="table-secondary-text"><?= $item['price'] !== null ? e(number_format((float)$item['price'], 2, ',', ' ')) . ' ₽' : 'Цена не указана' ?> · <?= e(date('d.m.Y', strtotime((string)$item['published_at']))) ?></div></td>
                        <td><div class="table-primary-text"><?= e($item['author']) ?></div><div class="table-secondary-text"><?= e($item['author_login']) ?> · <?= e($item['category_name']) ?></div></td>
                        <td><?= e($item['city_name'] ?? '—') ?></td>
                        <td><span class="status status-<?= e($item['status']) ?>"><?= e($statusLabels[$item['status']] ?? $item['status']) ?></span></td>
                        <td class="admin-ad-actions">
                            <a class="button button-small secondary" href="<?= e(route_url('ads/view')) ?>?id=<?= (int)$item['ad_id'] ?>">Подробнее</a>
                            <?php if ($item['status'] !== 'deleted'): ?>
                                <form class="moderation-form" method="post" action="<?= e(route_url('admin/ads')) ?>?<?= e($filterQuery) ?>">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="ad_id" value="<?= (int)$item['ad_id'] ?>">
                                    <input class="moderation-comment" name="comment" maxlength="1000" placeholder="Комментарий (необязательно)" aria-label="Комментарий модератора">
                                    <div class="moderation-controls"><select name="status" aria-label="Решение модератора"><option value="active">Одобрить</option><option value="rejected">Отклонить</option><option value="archived">В архив</option></select><button type="submit" class="button button-small">Применить</button></div>
                                </form>
                            <?php else: ?><span class="table-secondary-text">Удалено автором</span><?php endif; ?>
                            <?php if (!empty($item['moderation_comment'])): ?><small class="admin-comment-preview">Комментарий: <?= e((string)$item['moderation_comment']) ?></small><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table>
            </div>
            <?php if ($totalPages > 1): ?>
                <nav class="pagination" aria-label="Страницы административного списка">
                    <?php if ($page > 1): $paginationParams['page'] = $page - 1; ?><a href="<?= e(route_url('admin/ads')) ?>?<?= e(http_build_query($paginationParams)) ?>">← Назад</a><?php endif; ?>
                    <span>Страница <?= $page ?> из <?= $totalPages ?></span>
                    <?php if ($page < $totalPages): $paginationParams['page'] = $page + 1; ?><a href="<?= e(route_url('admin/ads')) ?>?<?= e(http_build_query($paginationParams)) ?>">Далее →</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>
    <footer class="site-footer">Доска объявлений <span>·</span> Управление объявлениями</footer>
</main>
</body>
</html>
