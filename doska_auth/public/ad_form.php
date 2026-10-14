<?php
declare(strict_types=1);
$user = require_auth();
$pdo = db();
$errors = [];
$isEdit = ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['id'])) || ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ad_id']) && (int)$_POST['ad_id'] > 0);
$adId = $isEdit ? (int)($_GET['id'] ?? $_POST['ad_id'] ?? 0) : 0;
$ad = ['title'=>'','description'=>'','price'=>'','category_id'=>'','city_id'=>'','latitude'=>'','longitude'=>''];

if ($isEdit) {
    $stmt = $pdo->prepare('SELECT ad_id,title,description,price,category_id,city_id,ST_Y(location) AS latitude,ST_X(location) AS longitude,status FROM ads WHERE ad_id=:id AND author_id=:author AND status <> \'deleted\' LIMIT 1');
    $stmt->execute(['id'=>$adId,'author'=>$user['user_id']]);
    $found = $stmt->fetch();
    if (!$found) {
        http_response_code(404);
        require __DIR__ . '/404.php';
        exit;
    }
    $ad = $found;
}

$categories = $pdo->query('SELECT category_id,name FROM categories WHERE is_active=1 ORDER BY name')->fetchAll();
$cities = $pdo->query('SELECT city_id,name,region FROM cities ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim((string)($_POST['title'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $priceRaw = trim((string)($_POST['price'] ?? ''));
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $cityId = (int)($_POST['city_id'] ?? 0);
    $latitude = trim((string)($_POST['latitude'] ?? ''));
    $longitude = trim((string)($_POST['longitude'] ?? ''));
    $ad = compact('title','description','priceRaw','categoryId','cityId','latitude','longitude');
    $ad['price'] = $priceRaw;
    $ad['category_id'] = $categoryId;
    $ad['city_id'] = $cityId;

    if (!verify_csrf($_POST['csrf_token'] ?? null)) $errors[] = 'Срок действия формы истёк. Обновите страницу и повторите попытку.';
    if (mb_strlen($title) < 3 || mb_strlen($title) > 200) $errors[] = 'Название должно содержать от 3 до 200 символов.';
    if (mb_strlen($description) < 10) $errors[] = 'Описание должно содержать не менее 10 символов.';
    if ($priceRaw !== '' && (!is_numeric($priceRaw) || (float)$priceRaw < 0)) $errors[] = 'Цена должна быть неотрицательным числом.';
    if ($categoryId <= 0) $errors[] = 'Выберите категорию.';
    if ($cityId <= 0) $errors[] = 'Выберите город.';
    if (!is_numeric($latitude) || (float)$latitude < -90 || (float)$latitude > 90) $errors[] = 'Широта должна быть от -90 до 90.';
    if (!is_numeric($longitude) || (float)$longitude < -180 || (float)$longitude > 180) $errors[] = 'Долгота должна быть от -180 до 180.';

    if (!$errors) {
        try {
            // Проверяем существование связанных сущностей до записи.
            $stmt=$pdo->prepare('SELECT COUNT(*) FROM categories WHERE category_id=:id AND is_active=1'); $stmt->execute(['id'=>$categoryId]);
            if (!(int)$stmt->fetchColumn()) $errors[]='Выбранная категория недоступна.';
            $stmt=$pdo->prepare('SELECT COUNT(*) FROM cities WHERE city_id=:id'); $stmt->execute(['id'=>$cityId]);
            if (!(int)$stmt->fetchColumn()) $errors[]='Выбранный город не найден.';
            if (!$errors) {
                $price = $priceRaw === '' ? null : number_format((float)$priceRaw, 2, '.', '');
                $params=['title'=>$title,'description'=>$description,'price'=>$price,'category'=>$categoryId,'city'=>$cityId,'lon'=>(float)$longitude,'lat'=>(float)$latitude,'id'=>$adId,'author'=>$user['user_id']];
                if ($isEdit) {
                    $stmt=$pdo->prepare("UPDATE ads SET title=:title,description=:description,price=:price,category_id=:category,city_id=:city,location=POINT(:lon,:lat),status='pending',moderated_by=NULL,moderated_at=NULL,moderation_comment=NULL WHERE ad_id=:id AND author_id=:author AND status <> 'deleted'");
                    $stmt->execute($params);
                    flash('success','Объявление обновлено и отправлено на повторную модерацию.');
                } else {
                    $stmt=$pdo->prepare("INSERT INTO ads (author_id,category_id,city_id,title,description,price,status,location) VALUES (:author,:category,:city,:title,:description,:price,'pending',POINT(:lon,:lat))");
                    $stmt->execute(['author'=>$user['user_id'],'category'=>$categoryId,'city'=>$cityId,'title'=>$title,'description'=>$description,'price'=>$price,'lon'=>(float)$longitude,'lat'=>(float)$latitude]);
                    flash('success','Объявление создано и отправлено на модерацию.');
                }
                header('Location: '.route_url('cabinet')); exit;
            }
        } catch (PDOException $e) {
            $errors[] = 'Не удалось сохранить объявление. Проверьте данные и соединение с базой данных.';
        }
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $isEdit ? 'Редактирование объявления' : 'Новое объявление' ?></title>
    <link rel="stylesheet" href="<?= e(route_url()) ?>../assets/css/auth.css">
</head>
<body class="ad-form-page">
<main class="site-shell">
    <header class="topbar form-topbar">
        <a class="brand" href="<?= e(route_url()) ?>"><span class="brand-mark">Д</span><span>Доска объявлений</span></a>
        <nav><a href="<?= e(route_url('ads')) ?>">Объявления</a><a href="<?= e(route_url('cabinet')) ?>">Личный кабинет</a><form method="post" action="<?= e(route_url('logout')) ?>" class="logout-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button type="submit" class="logout-button">Выйти</button></form></nav>
    </header>

    <section class="ad-editor-heading">
        <a class="back-link" href="<?= e(route_url('cabinet')) ?>">← Вернуться в личный кабинет</a>
        <div class="ad-editor-title-row"><div><span class="eyebrow"><?= $isEdit ? 'Редактирование' : 'Публикация' ?> · Объявления</span><h1><?= $isEdit ? 'Редактирование объявления' : 'Создание объявления' ?></h1><p>Заполните основные данные — это поможет другим пользователям быстрее найти ваше предложение.</p></div><span class="form-step-badge"><span>01</span> Данные объявления</span></div>
    </section>

    <div class="ad-form-layout">
        <section class="content-card ad-editor-card">
            <div class="editor-card-heading"><span class="editor-heading-mark">✎</span><div><h2>Информация об объявлении</h2><p>Поля со звёздочкой обязательны для заполнения.</p></div></div>
            <?php foreach ($errors as $error): ?><div class="alert alert-error" role="alert"><span class="alert-symbol">!</span><span><?= e($error) ?></span></div><?php endforeach; ?>
            <form method="post" class="crud-form ad-form" action="<?= e(route_url($isEdit ? 'ads/edit' : 'ads/create')) ?><?= $isEdit ? '?id='.(int)$adId : '' ?>">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <?php if ($isEdit): ?><input type="hidden" name="ad_id" value="<?= (int)$adId ?>"><?php endif; ?>

                <label class="field-full"><span>Название объявления <b class="required-mark">*</b></span><input name="title" maxlength="200" required placeholder="Например, велосипед в хорошем состоянии" value="<?= e((string)($ad['title'] ?? '')) ?>"><small>От 3 до 200 символов. Укажите предмет или услугу конкретно.</small></label>
                <label class="field-full"><span>Описание <b class="required-mark">*</b></span><textarea name="description" rows="6" required placeholder="Расскажите о состоянии, характеристиках и особенностях предложения..."><?= e((string)($ad['description'] ?? '')) ?></textarea><small>Минимум 10 символов. Не указывайте лишние персональные данные.</small></label>
                <label><span>Цена, ₽</span><div class="input-with-prefix"><span>₽</span><input type="number" name="price" min="0" step="0.01" placeholder="Не указана" value="<?= e((string)($ad['price'] ?? '')) ?>"></div><small>Оставьте поле пустым, если цена договорная.</small></label>
                <label><span>Категория <b class="required-mark">*</b></span><select name="category_id" required><option value="">Выберите категорию</option><?php foreach ($categories as $c): ?><option value="<?= (int)$c['category_id'] ?>" <?= (string)($ad['category_id'] ?? '') === (string)$c['category_id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select><small>Выберите наиболее подходящую категорию.</small></label>
                <label class="field-full"><span>Город <b class="required-mark">*</b></span><select name="city_id" required><option value="">Выберите город</option><?php foreach ($cities as $c): ?><option value="<?= (int)$c['city_id'] ?>" <?= (string)($ad['city_id'] ?? '') === (string)$c['city_id'] ? 'selected' : '' ?>><?= e($c['name']) ?><?= $c['region'] ? ' — '.e($c['region']) : '' ?></option><?php endforeach; ?></select><small>Город используется для поиска предложений рядом с пользователем.</small></label>

                <div class="field-full coordinates-section"><div class="coordinates-heading"><div><span class="coordinates-icon">⌖</span><span>Местоположение на карте</span></div><span class="optional-badge">Координаты обязательны</span></div><p>Укажите координаты точки на карте. Широта: от −90 до 90, долгота: от −180 до 180.</p><div class="form-grid coordinates-grid"><label><span>Широта</span><input type="number" name="latitude" min="-90" max="90" step="0.000001" required placeholder="Например, 55.7558" value="<?= e((string)($ad['latitude'] ?? '')) ?>"></label><label><span>Долгота</span><input type="number" name="longitude" min="-180" max="180" step="0.000001" required placeholder="Например, 37.6173" value="<?= e((string)($ad['longitude'] ?? '')) ?>"></label></div></div>

                <div class="form-actions field-full"><button type="submit" class="button button-primary-large"><?= $isEdit ? 'Сохранить изменения' : 'Создать объявление' ?><span>→</span></button><a class="button button-quiet" href="<?= e(route_url('cabinet')) ?>">Отмена</a></div>
            </form>
        </section>

        <aside class="form-sidebar">
            <section class="form-help-card"><span class="help-card-kicker">ПАМЯТКА</span><h2>Хорошее объявление проще найти</h2><p>Заполните информацию подробно и выберите правильное местоположение.</p><ul><li><span class="help-check">✓</span><span><strong>Понятный заголовок</strong><small>Кратко опишите товар или услугу.</small></span></li><li><span class="help-check">✓</span><span><strong>Подробное описание</strong><small>Укажите состояние и важные характеристики.</small></span></li><li><span class="help-check">✓</span><span><strong>Точная геолокация</strong><small>Координаты помогают искать объявления поблизости.</small></span></li></ul></section>
            <section class="moderation-help-card"><span class="moderation-help-icon">i</span><div><h3>Перед публикацией</h3><p><?= $isEdit ? 'После сохранения изменений объявление снова отправится на модерацию.' : 'После создания объявление получит статус «На проверке» и будет отправлено администратору.' ?></p></div></section>
        </aside>
    </div>
    <footer class="site-footer">Доска объявлений <span>·</span> Форма публикации</footer>
</main>
</body>
</html>
