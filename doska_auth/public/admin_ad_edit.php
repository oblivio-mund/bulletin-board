<?php
declare(strict_types=1);
$admin = require_admin();
$pdo = db();
$errors = [];
$adId = max(0, (int)($_GET['id'] ?? $_POST['ad_id'] ?? 0));
if ($adId <= 0) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT a.ad_id, a.title, a.description, a.price, a.category_id, a.city_id, a.status, ST_Y(a.location) AS latitude, ST_X(a.location) AS longitude, u.display_name AS author_name, u.login AS author_login FROM ads a JOIN users u ON u.user_id = a.author_id WHERE a.ad_id = :id LIMIT 1');
    $stmt->execute(['id' => $adId]);
    $ad = $stmt->fetch();
} catch (PDOException $exception) {
    error_log('Admin ad edit load failed: ' . $exception->getMessage());
    http_response_code(500);
    $ad = false;
    $errors[] = 'Не удалось загрузить объявление из базы данных.';
}
if (!$ad) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}
if ($ad['status'] === 'deleted') {
    flash('error', 'Сначала восстановите объявление в административном списке.');
    header('Location: ' . route_url('admin/ads'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim((string)($_POST['title'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $priceRaw = trim((string)($_POST['price'] ?? ''));
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $cityId = (int)($_POST['city_id'] ?? 0);
    $latitudeRaw = trim((string)($_POST['latitude'] ?? ''));
    $longitudeRaw = trim((string)($_POST['longitude'] ?? ''));

    $ad['title'] = $title;
    $ad['description'] = $description;
    $ad['price'] = $priceRaw;
    $ad['category_id'] = $categoryId;
    $ad['city_id'] = $cityId;
    $ad['latitude'] = $latitudeRaw;
    $ad['longitude'] = $longitudeRaw;

    if (!verify_csrf($_POST['csrf_token'] ?? null)) $errors[] = 'Срок действия формы истёк. Обновите страницу и повторите попытку.';
    $titleLength = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);
    $descriptionLength = function_exists('mb_strlen') ? mb_strlen($description) : strlen($description);
    if ($titleLength < 3 || $titleLength > 200) $errors[] = 'Название должно содержать от 3 до 200 символов.';
    if ($descriptionLength < 10) $errors[] = 'Описание должно содержать не менее 10 символов.';
    if ($priceRaw !== '' && (!is_numeric($priceRaw) || (float)$priceRaw < 0 || (float)$priceRaw > 9999999999.99)) $errors[] = 'Цена должна быть неотрицательным числом в допустимом диапазоне.';
    if ($categoryId <= 0) $errors[] = 'Выберите категорию.';
    if ($cityId <= 0) $errors[] = 'Выберите город.';
    if (!is_numeric($latitudeRaw) || (float)$latitudeRaw < -90 || (float)$latitudeRaw > 90) $errors[] = 'Широта должна быть от -90 до 90.';
    if (!is_numeric($longitudeRaw) || (float)$longitudeRaw < -180 || (float)$longitudeRaw > 180) $errors[] = 'Долгота должна быть от -180 до 180.';

    if (!$errors) {
        try {
            $check = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE category_id = :id');
            $check->execute(['id' => $categoryId]);
            if (!(int)$check->fetchColumn()) $errors[] = 'Выбранная категория не найдена.';
            $check = $pdo->prepare('SELECT COUNT(*) FROM cities WHERE city_id = :id');
            $check->execute(['id' => $cityId]);
            if (!(int)$check->fetchColumn()) $errors[] = 'Выбранный город не найден.';

            if (!$errors) {
                $pdo->beginTransaction();
                $lock = $pdo->prepare('SELECT status FROM ads WHERE ad_id = :id FOR UPDATE');
                $lock->execute(['id' => $adId]);
                $oldStatus = $lock->fetchColumn();
                if ($oldStatus === false) throw new RuntimeException('Объявление не найдено.');
                if ($oldStatus === 'deleted') throw new RuntimeException('Удалённое объявление нельзя редактировать. Сначала восстановите его.');

                $price = $priceRaw === '' ? null : number_format((float)$priceRaw, 2, '.', '');
                $update = $pdo->prepare("UPDATE ads SET title=:title, description=:description, price=:price, category_id=:category, city_id=:city, location=POINT(:longitude, :latitude), status='pending', moderated_by=NULL, moderated_at=NULL, moderation_comment=NULL WHERE ad_id=:id");
                $update->execute([
                    'title' => $title, 'description' => $description, 'price' => $price,
                    'category' => $categoryId, 'city' => $cityId, 'longitude' => (float)$longitudeRaw,
                    'latitude' => (float)$latitudeRaw, 'id' => $adId,
                ]);
                $history = $pdo->prepare('INSERT INTO ad_moderation_history (ad_id, admin_id, old_status, new_status, comment) VALUES (:ad_id, :admin_id, :old_status, :new_status, :comment)');
                $history->execute([
                    'ad_id' => $adId, 'admin_id' => $admin['user_id'],
                    'old_status' => $oldStatus, 'new_status' => 'pending',
                    'comment' => 'Содержимое объявления изменено администратором; отправлено на повторную проверку.',
                ]);
                $pdo->commit();
                flash('success', 'Объявление сохранено и отправлено на повторную проверку.');
                header('Location: ' . route_url('admin/ads'));
                exit;
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($exception instanceof RuntimeException && in_array($exception->getMessage(), ['Объявление не найдено.', 'Удалённое объявление нельзя редактировать. Сначала восстановите его.'], true)) {
                $errors[] = $exception->getMessage();
            } else {
                error_log('Admin ad edit save failed: ' . $exception->getMessage());
                $errors[] = 'Не удалось сохранить изменения. Проверьте данные и подключение к базе данных.';
            }
        }
    }
}

try {
    $categories = $pdo->query('SELECT category_id, name, is_active FROM categories ORDER BY name')->fetchAll();
    $cities = $pdo->query('SELECT city_id, name, region FROM cities ORDER BY name, region')->fetchAll();
} catch (PDOException $exception) {
    error_log('Admin ad edit dictionaries failed: ' . $exception->getMessage());
    $categories = $cities = [];
    $errors[] = 'Не удалось загрузить список категорий или городов.';
}
$statusLabels = ['draft'=>'Черновик','pending'=>'На проверке','active'=>'Активно','rejected'=>'Отклонено','archived'=>'В архиве','deleted'=>'Удалено'];
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Редактирование объявления #<?= (int)$adId ?> — администрирование</title>
    <link rel="stylesheet" href="<?= e(route_url()) ?>../assets/css/auth.css">
</head>
<body class="admin-page ad-form-page">
<main class="site-shell">
    <header class="topbar admin-topbar">
        <a class="brand admin-brand" href="<?= e(route_url('admin')) ?>"><span class="brand-mark">Д</span><span>Доска объявлений <small>Панель управления</small></span></a>
        <nav class="admin-nav"><a href="<?= e(route_url('admin')) ?>">Обзор</a><a href="<?= e(route_url('admin/users')) ?>">Пользователи</a><a class="is-active" href="<?= e(route_url('admin/ads')) ?>">Все объявления</a><a href="<?= e(route_url('admin/categories')) ?>">Категории</a><a href="<?= e(route_url('cabinet')) ?>">Кабинет</a><form method="post" action="<?= e(route_url('logout')) ?>" class="logout-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button type="submit" class="logout-button">Выйти</button></form></nav>
    </header>
    <section class="ad-editor-heading">
        <a class="back-link" href="<?= e(route_url('admin/ads')) ?>">← Вернуться ко всем объявлениям</a>
        <div class="ad-editor-title-row"><div><span class="eyebrow">Администрирование · запись #<?= (int)$adId ?></span><h1>Редактирование объявления</h1><p>Изменения будут сохранены, а объявление получит статус «На проверке».</p></div><span class="status status-<?= e($ad['status']) ?>"><?= e($statusLabels[$ad['status']] ?? $ad['status']) ?></span></div>
    </section>
    <div class="ad-form-layout">
        <section class="content-card ad-editor-card">
            <div class="editor-card-heading"><span class="editor-heading-mark">✎</span><div><h2>Данные объявления</h2><p>Автор: <?= e((string)$ad['author_name']) ?> (<?= e((string)$ad['author_login']) ?>)</p></div></div>
            <?php foreach ($errors as $error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endforeach; ?>
            <form method="post" class="crud-form ad-form" action="<?= e(route_url('admin/ads/edit')) ?>?id=<?= (int)$adId ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="ad_id" value="<?= (int)$adId ?>">
                <label class="field-full"><span>Название объявления *</span><input name="title" maxlength="200" required value="<?= e((string)$ad['title']) ?>"><small>От 3 до 200 символов.</small></label>
                <label class="field-full"><span>Описание *</span><textarea name="description" rows="7" required><?= e((string)$ad['description']) ?></textarea><small>Не менее 10 символов.</small></label>
                <label><span>Цена, ₽</span><div class="input-with-prefix"><span>₽</span><input type="number" name="price" min="0" step="0.01" value="<?= e((string)($ad['price'] ?? '')) ?>" placeholder="Не указана"></div></label>
                <label><span>Категория *</span><select name="category_id" required><option value="">Выберите категорию</option><?php foreach ($categories as $category): ?><option value="<?= (int)$category['category_id'] ?>" <?= (int)$ad['category_id'] === (int)$category['category_id'] ? 'selected' : '' ?>><?= e($category['name']) ?><?= !(int)$category['is_active'] ? ' (неактивна)' : '' ?></option><?php endforeach; ?></select></label>
                <label class="field-full"><span>Город *</span><select name="city_id" required><option value="">Выберите город</option><?php foreach ($cities as $city): ?><option value="<?= (int)$city['city_id'] ?>" <?= (int)$ad['city_id'] === (int)$city['city_id'] ? 'selected' : '' ?>><?= e($city['name']) ?><?= !empty($city['region']) ? ' — '.e($city['region']) : '' ?></option><?php endforeach; ?></select></label>
                <div class="field-full coordinates-section"><div class="coordinates-heading"><div><span class="coordinates-icon">⌖</span><span>Координаты местоположения</span></div></div><div class="form-grid coordinates-grid"><label><span>Широта</span><input type="number" name="latitude" min="-90" max="90" step="0.000001" required value="<?= e((string)$ad['latitude']) ?>"></label><label><span>Долгота</span><input type="number" name="longitude" min="-180" max="180" step="0.000001" required value="<?= e((string)$ad['longitude']) ?>"></label></div></div>
                <div class="form-actions field-full"><button type="submit" class="button button-primary-large">Сохранить изменения <span>→</span></button><a class="button button-quiet" href="<?= e(route_url('admin/ads')) ?>">Отмена</a></div>
            </form>
        </section>
        <aside class="form-sidebar"><section class="form-help-card"><span class="help-card-kicker">ВАЖНО</span><h2>Повторная модерация</h2><p>Чтобы изменения не публиковались без проверки, после сохранения объявление автоматически перейдёт в статус «На проверке».</p></section><section class="moderation-help-card"><span class="moderation-help-icon">i</span><div><h3>История статусов</h3><p>Смена статуса при редактировании будет записана в историю модерации.</p></div></section></aside>
    </div>
    <footer class="site-footer">Доска объявлений <span>·</span> Редактирование записи</footer>
</main>
</body>
</html>
