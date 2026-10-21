<?php
declare(strict_types=1);
$user = current_user();

$q = trim((string)($_GET['q'] ?? ''));
if (function_exists('mb_substr')) {
    $q = mb_substr($q, 0, 120);
} else {
    $q = substr($q, 0, 120);
}
$categoryId = max(0, (int)($_GET['category_id'] ?? 0));
$cityId = max(0, (int)($_GET['city_id'] ?? 0));
$minRaw = trim((string)($_GET['min_price'] ?? ''));
$maxRaw = trim((string)($_GET['max_price'] ?? ''));
$sort = (string)($_GET['sort'] ?? 'newest');
$latitudeRaw = trim((string)($_GET['latitude'] ?? ''));
$longitudeRaw = trim((string)($_GET['longitude'] ?? ''));
$radiusRaw = trim((string)($_GET['radius'] ?? ''));
$errors = [];
$pageError = null;

$sortOptions = [
    'newest' => 'Сначала новые',
    'price_asc' => 'Сначала дешевле',
    'price_desc' => 'Сначала дороже',
];
if (!isset($sortOptions[$sort])) {
    $sort = 'newest';
}

$where = ["a.status = 'active'"];
$params = [];
if ($q !== '') {
    $where[] = '(a.title LIKE :q_title OR a.description LIKE :q_description)';
    $params['q_title'] = '%' . $q . '%';
    $params['q_description'] = '%' . $q . '%';
}
if ($categoryId > 0) {
    $where[] = 'a.category_id = :category_id';
    $params['category_id'] = $categoryId;
}
if ($cityId > 0) {
    $where[] = 'a.city_id = :city_id';
    $params['city_id'] = $cityId;
}

$minPrice = null;
$maxPrice = null;
if ($minRaw !== '') {
    if (!is_numeric($minRaw) || (float)$minRaw < 0) {
        $errors[] = 'Минимальная цена должна быть неотрицательным числом.';
    } else {
        $minPrice = (float)$minRaw;
        $where[] = 'a.price >= :min_price';
        $params['min_price'] = $minPrice;
    }
}
if ($maxRaw !== '') {
    if (!is_numeric($maxRaw) || (float)$maxRaw < 0) {
        $errors[] = 'Максимальная цена должна быть неотрицательным числом.';
    } else {
        $maxPrice = (float)$maxRaw;
        $where[] = 'a.price <= :max_price';
        $params['max_price'] = $maxPrice;
    }
}
if ($minPrice !== null && $maxPrice !== null && $minPrice > $maxPrice) {
    $errors[] = 'Минимальная цена не может превышать максимальную.';
    // Не применяем конфликтующие ограничения цены.
    $where = array_values(array_filter($where, static fn(string $condition): bool => $condition !== 'a.price >= :min_price' && $condition !== 'a.price <= :max_price'));
    unset($params['min_price'], $params['max_price']);
}

$geoProvided = $latitudeRaw !== '' || $longitudeRaw !== '' || $radiusRaw !== '';
if ($geoProvided) {
    if ($latitudeRaw === '' || $longitudeRaw === '' || $radiusRaw === '') {
        $errors[] = 'Для поиска по радиусу заполните широту, долготу и радиус.';
    } elseif (!is_numeric($latitudeRaw) || (float)$latitudeRaw < -90 || (float)$latitudeRaw > 90) {
        $errors[] = 'Широта должна находиться в диапазоне от −90 до 90.';
    } elseif (!is_numeric($longitudeRaw) || (float)$longitudeRaw < -180 || (float)$longitudeRaw > 180) {
        $errors[] = 'Долгота должна находиться в диапазоне от −180 до 180.';
    } elseif (!is_numeric($radiusRaw) || (float)$radiusRaw < 1 || (float)$radiusRaw > 500) {
        $errors[] = 'Радиус поиска должен быть от 1 до 500 км.';
    } else {
        $where[] = 'ST_Distance_Sphere(a.location, POINT(:center_lng, :center_lat)) <= :radius_meters';
        $params['center_lng'] = (float)$longitudeRaw;
        $params['center_lat'] = (float)$latitudeRaw;
        $params['radius_meters'] = (float)$radiusRaw * 1000;
    }
}

$sortSql = [
    'newest' => 'a.published_at DESC',
    'price_asc' => 'a.price IS NULL ASC, a.price ASC, a.published_at DESC',
    'price_desc' => 'a.price IS NULL ASC, a.price DESC, a.published_at DESC',
][$sort];
$whereSql = implode(' AND ', $where);
$perPage = 12;
$page = max(1, (int)($_GET['page'] ?? 1));
$total = 0;
$totalPages = 1;
$ads = [];
$categories = [];
$cities = [];

try {
    $pdo = db();
    $categories = $pdo->query('SELECT category_id, name FROM categories WHERE is_active = 1 ORDER BY name')->fetchAll();
    $cities = $pdo->query('SELECT city_id, name, region FROM cities ORDER BY name, region')->fetchAll();

    $baseFrom = ' FROM ads a JOIN users u ON u.user_id=a.author_id JOIN categories c ON c.category_id=a.category_id LEFT JOIN cities ci ON ci.city_id=a.city_id ';
    $countStmt = $pdo->prepare('SELECT COUNT(*)' . $baseFrom . ' WHERE ' . $whereSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $sql = 'SELECT a.ad_id, a.title, a.description, a.price, a.status, a.published_at, '
        . 'u.display_name AS author_name, c.name AS category_name, ci.name AS city_name '
        . $baseFrom . ' WHERE ' . $whereSql . ' ORDER BY ' . $sortSql
        . ' LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $ads = $stmt->fetchAll();
} catch (PDOException $exception) {
    error_log('Public ads listing failed: ' . $exception->getMessage());
    $pageError = 'Не удалось загрузить объявления. Проверьте подключение к базе данных и повторите попытку.';
    $ads = [];
}

$filterParams = [
    'q' => $q,
    'category_id' => $categoryId,
    'city_id' => $cityId,
    'min_price' => $minRaw,
    'max_price' => $maxRaw,
    'sort' => $sort,
    'latitude' => $latitudeRaw,
    'longitude' => $longitudeRaw,
    'radius' => $radiusRaw,
];
$paginationParams = $filterParams;
$paginationParams['page'] = $page;
$filterQuery = http_build_query($filterParams);
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Объявления — <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="../assets/css/auth.css">
</head>
<body>
<main class="site-shell">
    <header class="topbar">
        <a class="brand" href="<?= e(route_url()) ?>">Доска объявлений</a>
        <nav>
            <a href="<?= e(route_url('categories')) ?>">Категории</a>
            <a href="<?= e(route_url('cities')) ?>">Города</a>
            <?php if ($user): ?>
                <a href="<?= e(route_url('cabinet')) ?>">Кабинет</a>
                <a href="<?= e(route_url('ads/create')) ?>">+ Подать объявление</a>
                <?php if ($user['role'] === 'admin'): ?><a href="<?= e(route_url('admin')) ?>">Администрирование</a><?php endif; ?>
                <form method="post" action="<?= e(route_url('logout')) ?>" class="logout-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button type="submit" class="logout-button">Выйти</button></form>
            <?php else: ?>
                <a href="<?= e(route_url('login')) ?>">Войти</a>
            <?php endif; ?>
        </nav>
    </header>

    <section class="content-card">
        <div class="page-head">
            <div><span class="eyebrow">Каталог</span><h1>Объявления</h1><p class="subtitle">Поиск по названию и описанию, фильтры по городу, категории, цене и расстоянию.</p></div>
            <span class="page-count"><?= $total ?> найдено</span>
        </div>

        <?php if ($pageError): ?><div class="alert alert-error" role="alert"><?= e($pageError) ?></div><?php endif; ?>
        <?php foreach ($errors as $error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endforeach; ?>

        <form method="get" action="<?= e(route_url('ads')) ?>" class="filters-panel">
            <div class="filters-grid">
                <label class="filter-field filter-search"><span>Поиск</span><input type="search" name="q" maxlength="120" value="<?= e($q) ?>" placeholder="Название или описание"></label>
                <label class="filter-field"><span>Категория</span><select name="category_id"><option value="0">Все категории</option><?php foreach ($categories as $category): ?><option value="<?= (int)$category['category_id'] ?>" <?= $categoryId === (int)$category['category_id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option><?php endforeach; ?></select></label>
                <label class="filter-field"><span>Город</span><select name="city_id"><option value="0">Все города</option><?php foreach ($cities as $city): ?><option value="<?= (int)$city['city_id'] ?>" <?= $cityId === (int)$city['city_id'] ? 'selected' : '' ?>><?= e($city['name']) ?><?= !empty($city['region']) ? ' — ' . e($city['region']) : '' ?></option><?php endforeach; ?></select></label>
                <label class="filter-field"><span>Цена от, ₽</span><input type="number" name="min_price" min="0" step="0.01" value="<?= e($minRaw) ?>" placeholder="Без минимума"></label>
                <label class="filter-field"><span>Цена до, ₽</span><input type="number" name="max_price" min="0" step="0.01" value="<?= e($maxRaw) ?>" placeholder="Без максимума"></label>
                <label class="filter-field"><span>Сортировка</span><select name="sort"><?php foreach ($sortOptions as $key => $label): ?><option value="<?= e($key) ?>" <?= $sort === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
            </div>
            <details class="geo-filter" <?= $geoProvided ? 'open' : '' ?>><summary>Поиск рядом с координатами</summary><p>Укажите точку и радиус поиска. Координаты вводятся в десятичных градусах.</p><div class="filters-grid geo-grid">
                <label class="filter-field"><span>Широта</span><input type="number" name="latitude" min="-90" max="90" step="0.000001" value="<?= e($latitudeRaw) ?>" placeholder="55.7558"></label>
                <label class="filter-field"><span>Долгота</span><input type="number" name="longitude" min="-180" max="180" step="0.000001" value="<?= e($longitudeRaw) ?>" placeholder="37.6173"></label>
                <label class="filter-field"><span>Радиус, км</span><input type="number" name="radius" min="1" max="500" step="1" value="<?= e($radiusRaw) ?>" placeholder="Например, 25"></label>
            </div></details>
            <div class="filter-actions"><button type="submit" class="button">Найти объявления</button><a class="button secondary" href="<?= e(route_url('ads')) ?>">Сбросить фильтры</a></div>
        </form>

        <p class="results-caption">Показано <?= count($ads) ?> из <?= $total ?> объявлений<?php if ($totalPages > 1): ?> · страница <?= $page ?> из <?= $totalPages ?><?php endif; ?></p>
        <?php if (!$pageError && !$ads): ?>
            <div class="empty-state"><span class="empty-state-icon">⌕</span><h2>Объявления не найдены</h2><p>Попробуйте изменить поисковый запрос или сбросить фильтры.</p><a class="button secondary" href="<?= e(route_url('ads')) ?>">Показать все объявления</a></div>
        <?php elseif ($ads): ?>
            <div class="entity-grid">
                <?php foreach ($ads as $ad): ?>
                    <article class="entity-card">
                        <div class="entity-top"><span class="tag"><?= e($ad['category_name']) ?></span><span><?= e($ad['city_name'] ?? 'Город не указан') ?></span></div>
                        <h2><?= e($ad['title']) ?></h2>
                        <p><?= e(mb_strlen((string)$ad['description']) > 145 ? mb_substr((string)$ad['description'], 0, 145) . '…' : (string)$ad['description']) ?></p>
                        <strong class="price"><?= $ad['price'] !== null ? e(number_format((float)$ad['price'], 2, ',', ' ')) . ' ₽' : 'Цена договорная' ?></strong>
                        <small>Автор: <?= e($ad['author_name']) ?> · <?= e(date('d.m.Y', strtotime((string)$ad['published_at']))) ?></small>
                        <a class="button secondary" href="<?= e(route_url('ads/view')) ?>?id=<?= (int)$ad['ad_id'] ?>">Подробнее</a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="Страницы объявлений">
                <?php if ($page > 1): $paginationParams['page'] = $page - 1; ?><a href="<?= e(route_url('ads')) ?>?<?= e(http_build_query($paginationParams)) ?>">← Назад</a><?php endif; ?>
                <span>Страница <?= $page ?> из <?= $totalPages ?></span>
                <?php if ($page < $totalPages): $paginationParams['page'] = $page + 1; ?><a href="<?= e(route_url('ads')) ?>?<?= e(http_build_query($paginationParams)) ?>">Далее →</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
    <footer class="site-footer">Доска объявлений <span>·</span> Каталог публикаций</footer>
</main>
</body>
</html>
