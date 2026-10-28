<?php
declare(strict_types=1);
$user = require_admin();
$errors = [];

$q = trim((string)($_GET['q'] ?? ''));
$q = function_exists('mb_substr') ? mb_substr($q, 0, 120) : substr($q, 0, 120);
$statusFilter = (string)($_GET['status'] ?? 'all');
$statusLabels = [
    'draft' => 'Черновик', 'pending' => 'На проверке', 'active' => 'Активно',
    'rejected' => 'Отклонено', 'archived' => 'В архиве', 'deleted' => 'Удалено',
];
if (!in_array($statusFilter, array_merge(['all'], array_keys($statusLabels)), true)) $statusFilter = 'all';
$categoryId = max(0, (int)($_GET['category_id'] ?? 0));
$cityId = max(0, (int)($_GET['city_id'] ?? 0));
$sort = (string)($_GET['sort'] ?? 'newest');
$sortOptions = [
    'newest' => 'Сначала новые', 'oldest' => 'Сначала старые',
    'title_asc' => 'Название: А–Я', 'title_desc' => 'Название: Я–А',
    'price_asc' => 'Цена: по возрастанию', 'price_desc' => 'Цена: по убыванию',
    'status' => 'По статусу',
];
if (!array_key_exists($sort, $sortOptions)) $sort = 'newest';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;

$filterParams = ['q' => $q, 'status' => $statusFilter, 'category_id' => $categoryId, 'city_id' => $cityId, 'sort' => $sort];
$filterQuery = http_build_query($filterParams + ['page' => $page]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirectParams = $filterParams;
    $redirectParams['page'] = $page;
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Недействительный CSRF-токен. Обновите страницу и повторите действие.';
    } else {
        $adId = (int)($_POST['ad_id'] ?? 0);
        $operation = (string)($_POST['operation'] ?? 'status');
        $newStatus = (string)($_POST['status'] ?? '');
        $comment = trim((string)($_POST['comment'] ?? ''));
        if ($adId <= 0 || !in_array($operation, ['status', 'delete', 'restore'], true) || (function_exists('mb_strlen') ? mb_strlen($comment) : strlen($comment)) > 1000) {
            $errors[] = 'Некорректные параметры действия.';
        } else {
            $pdo = db();
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('SELECT status FROM ads WHERE ad_id = :id FOR UPDATE');
                $stmt->execute(['id' => $adId]);
                $oldStatus = $stmt->fetchColumn();
                if ($oldStatus === false) throw new RuntimeException('Объявление не найдено.');

                if ($operation === 'delete') {
                    if ($oldStatus === 'deleted') throw new RuntimeException('Объявление уже удалено.');
                    $targetStatus = 'deleted';
                    $targetComment = $comment !== '' ? $comment : 'Удалено администратором';
                } elseif ($operation === 'restore') {
                    if ($oldStatus !== 'deleted') throw new RuntimeException('Восстановить можно только удалённое объявление.');
                    $targetStatus = 'pending';
                    $targetComment = null;
                } else {
                    $allowedStatuses = ['draft', 'pending', 'active', 'rejected', 'archived'];
                    if (!in_array($newStatus, $allowedStatuses, true)) throw new RuntimeException('Выбран недопустимый статус.');
                    if ($oldStatus === 'deleted') throw new RuntimeException('Сначала восстановите объявление.');
                    $targetStatus = $newStatus;
                    $targetComment = $comment !== '' ? $comment : null;
                }

                if (in_array($targetStatus, ['draft', 'pending'], true)) {
                    $stmt = $pdo->prepare('UPDATE ads SET status = :status, moderated_by = NULL, moderated_at = NULL, moderation_comment = NULL WHERE ad_id = :id');
                    $stmt->execute(['status' => $targetStatus, 'id' => $adId]);
                } else {
                    $stmt = $pdo->prepare('UPDATE ads SET status = :status, moderated_by = :admin_id, moderated_at = CURRENT_TIMESTAMP, moderation_comment = :comment WHERE ad_id = :id');
                    $stmt->execute(['status' => $targetStatus, 'admin_id' => $user['user_id'], 'comment' => $targetComment, 'id' => $adId]);
                }
                $stmt = $pdo->prepare('INSERT INTO ad_moderation_history (ad_id, admin_id, old_status, new_status, comment) VALUES (:ad_id, :admin_id, :old_status, :new_status, :comment)');
                $stmt->execute([
                    'ad_id' => $adId, 'admin_id' => $user['user_id'], 'old_status' => $oldStatus,
                    'new_status' => $targetStatus,
                    'comment' => $targetComment,
                ]);
                $pdo->commit();
                $messages = ['delete' => 'Объявление удалено (перемещено в статус «Удалено»).', 'restore' => 'Объявление восстановлено и отправлено на проверку.', 'status' => 'Статус объявления обновлён.'];
                flash('success', $messages[$operation]);
                header('Location: ' . route_url('admin/ads') . '?' . http_build_query($redirectParams));
                exit;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $knownMessages = [
                    'Объявление не найдено.', 'Объявление уже удалено.',
                    'Восстановить можно только удалённое объявление.', 'Выбран недопустимый статус.',
                    'Сначала восстановите объявление.',
                ];
                if ($exception instanceof RuntimeException && in_array($exception->getMessage(), $knownMessages, true)) {
                    $errors[] = $exception->getMessage();
                } else {
                    error_log('Admin ad action failed: ' . $exception->getMessage());
                    $errors[] = 'Не удалось выполнить действие с объявлением. Проверьте подключение к базе данных.';
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
    $params += ['q_title' => $like, 'q_description' => $like, 'q_name' => $like, 'q_login' => $like];
}
if ($statusFilter !== 'all') { $where[] = 'a.status = :status_filter'; $params['status_filter'] = $statusFilter; }
if ($categoryId > 0) { $where[] = 'a.category_id = :category_id'; $params['category_id'] = $categoryId; }
if ($cityId > 0) { $where[] = 'a.city_id = :city_id'; $params['city_id'] = $cityId; }
$whereSql = implode(' AND ', $where);
$orderByMap = [
    'newest' => 'a.published_at DESC, a.ad_id DESC',
    'oldest' => 'a.published_at ASC, a.ad_id ASC',
    'title_asc' => 'a.title ASC, a.ad_id DESC',
    'title_desc' => 'a.title DESC, a.ad_id DESC',
    'price_asc' => '(a.price IS NULL) ASC, a.price ASC, a.ad_id DESC',
    'price_desc' => '(a.price IS NULL) ASC, a.price DESC, a.ad_id DESC',
    'status' => "CASE a.status WHEN 'pending' THEN 1 WHEN 'draft' THEN 2 WHEN 'active' THEN 3 WHEN 'rejected' THEN 4 WHEN 'archived' THEN 5 WHEN 'deleted' THEN 6 ELSE 7 END, a.published_at DESC",
];

$items = $categories = $cities = [];
$filteredCount = $allCount = 0;
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
    $sql = 'SELECT a.ad_id, a.title, a.description, a.price, a.status, a.published_at, a.updated_at, a.moderation_comment, '
        . 'u.display_name AS author, u.login AS author_login, c.name AS category_name, ci.name AS city_name '
        . $baseFrom . ' WHERE ' . $whereSql . ' ORDER BY ' . $orderByMap[$sort]
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
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Все объявления — панель администратора</title>
    <link rel="stylesheet" href="<?= e(route_url()) ?>../assets/css/auth.css">
</head>
<body class="admin-page">
<main class="site-shell">
    <header class="topbar admin-topbar">
        <a class="brand admin-brand" href="<?= e(route_url('admin')) ?>"><span class="brand-mark">Д</span><span>Доска объявлений <small>Панель управления</small></span></a>
        <nav class="admin-nav">
            <a href="<?= e(route_url('admin')) ?>">Обзор</a><a href="<?= e(route_url('admin/users')) ?>">Пользователи</a>
            <a class="is-active" href="<?= e(route_url('admin/ads')) ?>">Все объявления</a><a href="<?= e(route_url('admin/categories')) ?>">Категории</a>
            <a href="<?= e(route_url('cabinet')) ?>">Кабинет</a>
            <form method="post" action="<?= e(route_url('logout')) ?>" class="logout-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button type="submit" class="logout-button">Выйти</button></form>
        </nav>
    </header>
    <section class="content-card admin-content">
        <div class="admin-page-heading"><div><span class="eyebrow">Администрирование · записи</span><h1>Все объявления</h1><p class="section-lead">Редактирование, смена статусов, поиск, сортировка и удаление всех записей.</p></div><span class="page-count"><?= $filteredCount ?> из <?= $allCount ?> записей</span></div>
        <?php if ($flashMessage): ?><div class="alert alert-<?= e($flashMessage['type']) ?>" role="status"><?= e($flashMessage['message']) ?></div><?php endif; ?>
        <?php foreach ($errors as $error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endforeach; ?>
        <?php if ($pageError): ?><div class="alert alert-error" role="alert"><?= e($pageError) ?></div><?php endif; ?>

        <form method="get" action="<?= e(route_url('admin/ads')) ?>" class="filters-panel admin-filters">
            <div class="filters-grid admin-filters-grid">
                <label class="filter-field filter-search"><span>Поиск</span><input type="search" name="q" maxlength="120" value="<?= e($q) ?>" placeholder="Название, описание, автор или логин"></label>
                <label class="filter-field"><span>Статус</span><select name="status"><option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Все статусы</option><?php foreach ($statusLabels as $key => $label): ?><option value="<?= e($key) ?>" <?= $statusFilter === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                <label class="filter-field"><span>Категория</span><select name="category_id"><option value="0">Все категории</option><?php foreach ($categories as $category): ?><option value="<?= (int)$category['category_id'] ?>" <?= $categoryId === (int)$category['category_id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option><?php endforeach; ?></select></label>
                <label class="filter-field"><span>Город</span><select name="city_id"><option value="0">Все города</option><?php foreach ($cities as $city): ?><option value="<?= (int)$city['city_id'] ?>" <?= $cityId === (int)$city['city_id'] ? 'selected' : '' ?>><?= e($city['name']) ?><?= !empty($city['region']) ? ' — ' . e($city['region']) : '' ?></option><?php endforeach; ?></select></label>
                <label class="filter-field"><span>Сортировка</span><select name="sort"><?php foreach ($sortOptions as $key => $label): ?><option value="<?= e($key) ?>" <?= $sort === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
            </div>
            <div class="filter-actions"><button type="submit" class="button">Применить</button><a class="button secondary" href="<?= e(route_url('admin/ads')) ?>">Сбросить</a></div>
        </form>
        <div class="moderation-note"><span class="moderation-note-mark">i</span><p>Редактирование отправляет объявление на повторную проверку. Удаление является логическим: запись сохраняется в базе, а её история не теряется. Удалённые записи можно восстановить на модерацию.</p></div>

        <?php if (!$pageError && !$items): ?>
            <div class="empty-state"><span class="empty-state-icon">⌕</span><h2>Записи не найдены</h2><p>Измените поисковый запрос или сбросьте фильтры.</p><a class="button secondary" href="<?= e(route_url('admin/ads')) ?>">Показать все записи</a></div>
        <?php elseif ($items): ?>
            <div class="table-wrap admin-table-wrap"><table class="admin-table"><thead><tr><th>ID</th><th>Объявление</th><th>Автор и категория</th><th>Город</th><th>Статус</th><th>Действия</th></tr></thead><tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><span class="id-chip">#<?= (int)$item['ad_id'] ?></span></td>
                    <td><div class="table-primary-text"><?= e($item['title']) ?></div><div class="table-secondary-text"><?= $item['price'] !== null ? e(number_format((float)$item['price'], 2, ',', ' ')) . ' ₽' : 'Цена не указана' ?> · <?= e(date('d.m.Y', strtotime((string)$item['published_at']))) ?></div></td>
                    <td><div class="table-primary-text"><?= e($item['author']) ?></div><div class="table-secondary-text"><?= e($item['author_login']) ?> · <?= e($item['category_name']) ?></div></td>
                    <td><?= e((string)($item['city_name'] ?? '—')) ?></td>
                    <td><span class="status status-<?= e($item['status']) ?>"><?= e($statusLabels[$item['status']] ?? $item['status']) ?></span><?php if (!empty($item['moderation_comment'])): ?><small class="admin-comment-preview">Комментарий: <?= e((string)$item['moderation_comment']) ?></small><?php endif; ?></td>
                    <td class="admin-ad-actions">
                        <div class="admin-action-links"><a class="button button-small secondary" href="<?= e(route_url('ads/view')) ?>?id=<?= (int)$item['ad_id'] ?>">Подробнее</a>
                        <?php if ($item['status'] !== 'deleted'): ?><a class="button button-small secondary" href="<?= e(route_url('admin/ads/edit')) ?>?id=<?= (int)$item['ad_id'] ?>">Редактировать</a><?php endif; ?></div>
                        <?php if ($item['status'] !== 'deleted'): ?>
                            <form class="moderation-form" method="post" action="<?= e(route_url('admin/ads')) ?>?<?= e($filterQuery) ?>">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="ad_id" value="<?= (int)$item['ad_id'] ?>"><input type="hidden" name="operation" value="status">
                                <label class="admin-small-label">Изменить статус<select name="status" aria-label="Новый статус объявления"><?php foreach (['pending'=>'На проверку','draft'=>'Черновик','active'=>'Активно','rejected'=>'Отклонено','archived'=>'В архив'] as $statusKey => $statusText): ?><option value="<?= e($statusKey) ?>" <?= $item['status'] === $statusKey ? 'selected' : '' ?>><?= e($statusText) ?></option><?php endforeach; ?></select></label>
                                <input class="moderation-comment" name="comment" maxlength="1000" placeholder="Комментарий (необязательно)" aria-label="Комментарий администратора">
                                <button type="submit" class="button button-small">Сохранить статус</button>
                            </form>
                            <form method="post" action="<?= e(route_url('admin/ads')) ?>?<?= e($filterQuery) ?>" class="admin-delete-form" onsubmit="return confirm('Переместить объявление в статус «Удалено»?');">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="ad_id" value="<?= (int)$item['ad_id'] ?>"><input type="hidden" name="operation" value="delete"><button type="submit" class="button button-small danger-button">Удалить</button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="<?= e(route_url('admin/ads')) ?>?<?= e($filterQuery) ?>" class="admin-restore-form">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="ad_id" value="<?= (int)$item['ad_id'] ?>"><input type="hidden" name="operation" value="restore"><button type="submit" class="button button-small">Восстановить на проверку</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
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
