<?php
declare(strict_types=1);
$admin = require_admin();
$errors = [];

$q = trim((string)($_GET['q'] ?? ''));
$q = function_exists('mb_substr') ? mb_substr($q, 0, 120) : substr($q, 0, 120);
$roleFilter = (string)($_GET['role'] ?? 'all');
if (!in_array($roleFilter, ['all', 'user', 'admin'], true)) $roleFilter = 'all';
$activeFilter = (string)($_GET['active'] ?? 'all');
if (!in_array($activeFilter, ['all', '1', '0'], true)) $activeFilter = 'all';
$sort = (string)($_GET['sort'] ?? 'newest');
$sortOptions = [
    'newest' => 'Сначала новые', 'oldest' => 'Сначала старые',
    'name_asc' => 'Имя: А–Я', 'name_desc' => 'Имя: Я–А',
    'login_asc' => 'Логин: А–Я', 'role' => 'По роли', 'status' => 'По состоянию',
];
if (!array_key_exists($sort, $sortOptions)) $sort = 'newest';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$filterParams = ['q' => $q, 'role' => $roleFilter, 'active' => $activeFilter, 'sort' => $sort];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Недействительный CSRF-токен. Обновите страницу и повторите действие.';
    } else {
        $targetId = (int)($_POST['user_id'] ?? 0);
        $displayName = trim((string)($_POST['display_name'] ?? ''));
        $newRole = (string)($_POST['role'] ?? '');
        $newActive = (string)($_POST['is_active'] ?? '');
        $displayNameLength = function_exists('mb_strlen') ? mb_strlen($displayName) : strlen($displayName);
        if ($targetId <= 0 || $displayNameLength < 2 || $displayNameLength > 100 || !in_array($newRole, ['user', 'admin'], true) || !in_array($newActive, ['0', '1'], true)) {
            $errors[] = 'Проверьте имя, роль и состояние учётной записи.';
        } else {
            $pdo = db();
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('SELECT user_id, display_name, role, is_active FROM users WHERE user_id = :id FOR UPDATE');
                $stmt->execute(['id' => $targetId]);
                $target = $stmt->fetch();
                if (!$target) throw new RuntimeException('Пользователь не найден.');

                $isSelf = (int)$target['user_id'] === (int)$admin['user_id'];
                if ($isSelf && ($newRole !== $target['role'] || (int)$newActive !== (int)$target['is_active'])) {
                    throw new RuntimeException('Нельзя менять собственную роль или отключать свою учётную запись.');
                }

                $removingActiveAdmin = $target['role'] === 'admin' && (int)$target['is_active'] === 1 && ($newRole !== 'admin' || (int)$newActive !== 1);
                if ($removingActiveAdmin) {
                    $countAdmins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1")->fetchColumn();
                    if ($countAdmins <= 1) throw new RuntimeException('Нельзя отключить или понизить последнего активного администратора.');
                }

                $update = $pdo->prepare('UPDATE users SET display_name = :display_name, role = :role, is_active = :is_active WHERE user_id = :id');
                $update->execute(['display_name' => $displayName, 'role' => $newRole, 'is_active' => (int)$newActive, 'id' => $targetId]);
                $pdo->commit();
                flash('success', 'Данные пользователя обновлены.');
                $redirectParams = $filterParams;
                $redirectParams['page'] = $page;
                header('Location: ' . route_url('admin/users') . '?' . http_build_query($redirectParams));
                exit;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $known = [
                    'Пользователь не найден.',
                    'Нельзя менять собственную роль или отключать свою учётную запись.',
                    'Нельзя отключить или понизить последнего активного администратора.',
                ];
                if ($exception instanceof RuntimeException && in_array($exception->getMessage(), $known, true)) {
                    $errors[] = $exception->getMessage();
                } else {
                    error_log('Admin user update failed: ' . $exception->getMessage());
                    $errors[] = 'Не удалось обновить пользователя. Проверьте данные и подключение к базе данных.';
                }
            }
        }
    }
}

$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = '(display_name LIKE :q_name OR login LIKE :q_login)';
    $like = '%' . $q . '%';
    $params = ['q_name' => $like, 'q_login' => $like];
}
if ($roleFilter !== 'all') { $where[] = 'role = :role_filter'; $params['role_filter'] = $roleFilter; }
if ($activeFilter !== 'all') { $where[] = 'is_active = :active_filter'; $params['active_filter'] = (int)$activeFilter; }
$whereSql = implode(' AND ', $where);
$orderMap = [
    'newest' => 'created_at DESC, user_id DESC', 'oldest' => 'created_at ASC, user_id ASC',
    'name_asc' => 'display_name ASC, user_id DESC', 'name_desc' => 'display_name DESC, user_id DESC',
    'login_asc' => 'login ASC, user_id DESC', 'role' => "role ASC, created_at DESC",
    'status' => 'is_active DESC, created_at DESC',
];
$items = [];
$totalUsers = $filteredCount = 0;
$totalPages = 1;
$pageError = null;
try {
    $pdo = db();
    $totalUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $count = $pdo->prepare('SELECT COUNT(*) FROM users WHERE ' . $whereSql);
    $count->execute($params);
    $filteredCount = (int)$count->fetchColumn();
    $totalPages = max(1, (int)ceil($filteredCount / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;
    $list = $pdo->prepare('SELECT user_id, display_name, login, role, is_active, created_at FROM users WHERE ' . $whereSql . ' ORDER BY ' . $orderMap[$sort] . ' LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset);
    $list->execute($params);
    $items = $list->fetchAll();
} catch (PDOException $exception) {
    error_log('Admin users list failed: ' . $exception->getMessage());
    $pageError = 'Не удалось загрузить список пользователей. Проверьте подключение к базе данных.';
}
$flashMessage = consume_flash();
$paginationParams = $filterParams;
?>
<!doctype html>
<html lang="ru">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Пользователи — панель администратора</title><link rel="stylesheet" href="<?= e(route_url()) ?>../assets/css/auth.css"></head>
<body class="admin-page">
<main class="site-shell">
    <header class="topbar admin-topbar">
        <a class="brand admin-brand" href="<?= e(route_url('admin')) ?>"><span class="brand-mark">Д</span><span>Доска объявлений <small>Панель управления</small></span></a>
        <nav class="admin-nav"><a href="<?= e(route_url('admin')) ?>">Обзор</a><a class="is-active" href="<?= e(route_url('admin/users')) ?>">Пользователи</a><a href="<?= e(route_url('admin/ads')) ?>">Все объявления</a><a href="<?= e(route_url('admin/categories')) ?>">Категории</a><a href="<?= e(route_url('cabinet')) ?>">Кабинет</a><form method="post" action="<?= e(route_url('logout')) ?>" class="logout-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button type="submit" class="logout-button">Выйти</button></form></nav>
    </header>
    <section class="content-card admin-content">
        <div class="admin-page-heading"><div><span class="eyebrow">Управление доступом</span><h1>Пользователи</h1><p class="section-lead">Поиск и сортировка аккаунтов, изменение отображаемого имени, роли и состояния учётной записи.</p></div><span class="page-count"><?= $filteredCount ?> из <?= $totalUsers ?> аккаунтов</span></div>
        <?php if ($flashMessage): ?><div class="alert alert-<?= e($flashMessage['type']) ?>" role="status"><?= e($flashMessage['message']) ?></div><?php endif; ?>
        <?php foreach ($errors as $error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endforeach; ?>
        <?php if ($pageError): ?><div class="alert alert-error" role="alert"><?= e($pageError) ?></div><?php endif; ?>

        <form method="get" action="<?= e(route_url('admin/users')) ?>" class="filters-panel admin-filters">
            <div class="filters-grid admin-filters-grid">
                <label class="filter-field filter-search"><span>Поиск</span><input type="search" name="q" maxlength="120" value="<?= e($q) ?>" placeholder="Имя или логин"></label>
                <label class="filter-field"><span>Роль</span><select name="role"><option value="all">Все роли</option><option value="user" <?= $roleFilter === 'user' ? 'selected' : '' ?>>Пользователь</option><option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Администратор</option></select></label>
                <label class="filter-field"><span>Состояние</span><select name="active"><option value="all">Все</option><option value="1" <?= $activeFilter === '1' ? 'selected' : '' ?>>Активен</option><option value="0" <?= $activeFilter === '0' ? 'selected' : '' ?>>Отключён</option></select></label>
                <label class="filter-field"><span>Сортировка</span><select name="sort"><?php foreach ($sortOptions as $key => $label): ?><option value="<?= e($key) ?>" <?= $sort === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
            </div>
            <div class="filter-actions"><button type="submit" class="button">Применить</button><a class="button secondary" href="<?= e(route_url('admin/users')) ?>">Сбросить</a></div>
        </form>
        <div class="moderation-note"><span class="moderation-note-mark">i</span><p>Отключённый аккаунт не сможет войти в систему. Для сохранения связей с объявлениями и сообщениями учётные записи не удаляются физически. Система не позволяет отключить последнего активного администратора или изменить собственную роль.</p></div>

        <?php if (!$pageError && !$items): ?><div class="empty-state"><span class="empty-state-icon">◎</span><h2>Пользователи не найдены</h2><p>Измените фильтры или поисковый запрос.</p><a class="button secondary" href="<?= e(route_url('admin/users')) ?>">Показать всех</a></div>
        <?php elseif ($items): ?>
            <div class="table-wrap admin-table-wrap"><table class="admin-table"><thead><tr><th>ID</th><th>Пользователь</th><th>Логин</th><th>Дата регистрации</th><th>Настройки аккаунта</th></tr></thead><tbody>
            <?php foreach ($items as $item): $isSelf = (int)$item['user_id'] === (int)$admin['user_id']; $displayName = (string)$item['display_name']; $initial = function_exists('mb_substr') ? mb_substr($displayName, 0, 1) : substr($displayName, 0, 1); $initial = function_exists('mb_strtoupper') ? mb_strtoupper($initial) : strtoupper($initial); ?>
                <tr>
                    <td><span class="id-chip">#<?= (int)$item['user_id'] ?></span></td>
                    <td><div class="user-cell"><span class="avatar-placeholder"><?= e($initial) ?></span><span><span class="table-primary-text"><?= e($item['display_name']) ?></span><?php if ($isSelf): ?><small class="table-secondary-text">Текущая учётная запись</small><?php endif; ?></span></div></td>
                    <td><code class="login-chip"><?= e($item['login']) ?></code><div><a class="admin-user-ads-link" href="<?= e(route_url('admin/ads')) ?>?<?= e(http_build_query(['q' => $item['login']])) ?>">Объявления пользователя</a></div></td>
                    <td><?= e(date('d.m.Y H:i', strtotime((string)$item['created_at']))) ?></td>
                    <td>
                        <form class="admin-user-controls" method="post" action="<?= e(route_url('admin/users')) ?>?<?= e(http_build_query($filterParams + ['page' => $page])) ?>">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="user_id" value="<?= (int)$item['user_id'] ?>">
                            <label><span>Имя</span><input type="text" name="display_name" minlength="2" maxlength="100" required value="<?= e($item['display_name']) ?>"></label>
                            <label><span>Роль</span><select name="role" <?= $isSelf ? 'disabled' : '' ?>><option value="user" <?= $item['role'] === 'user' ? 'selected' : '' ?>>Пользователь</option><option value="admin" <?= $item['role'] === 'admin' ? 'selected' : '' ?>>Администратор</option></select><?php if ($isSelf): ?><input type="hidden" name="role" value="admin"><?php endif; ?></label>
                            <label><span>Состояние</span><select name="is_active" <?= $isSelf ? 'disabled' : '' ?>><option value="1" <?= (int)$item['is_active'] === 1 ? 'selected' : '' ?>>Активен</option><option value="0" <?= (int)$item['is_active'] === 0 ? 'selected' : '' ?>>Отключён</option></select><?php if ($isSelf): ?><input type="hidden" name="is_active" value="1"><?php endif; ?></label>
                            <button class="button button-small" type="submit">Сохранить</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <?php if ($totalPages > 1): ?><nav class="pagination" aria-label="Страницы списка пользователей"><?php if ($page > 1): $paginationParams['page'] = $page - 1; ?><a href="<?= e(route_url('admin/users')) ?>?<?= e(http_build_query($paginationParams)) ?>">← Назад</a><?php endif; ?><span>Страница <?= $page ?> из <?= $totalPages ?></span><?php if ($page < $totalPages): $paginationParams['page'] = $page + 1; ?><a href="<?= e(route_url('admin/users')) ?>?<?= e(http_build_query($paginationParams)) ?>">Далее →</a><?php endif; ?></nav><?php endif; ?>
        <?php endif; ?>
    </section>
    <footer class="site-footer">Доска объявлений <span>·</span> Управление пользователями</footer>
</main>
</body>
</html>
