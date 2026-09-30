<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$user = current_user();
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="../assets/css/auth.css">
</head>
<body>
<main class="home">
    <section class="home-card">
        <span class="eyebrow">Учебный проект</span>
        <h1>Доска объявлений с геолокацией</h1>
        <?php if ($user): ?>
            <p>Вы вошли как <strong><?= e($user['display_name']) ?></strong> (<?= e($user['role']) ?>).</p>
            <form method="post" action="logout.php">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <button type="submit">Выйти</button>
            </form>
        <?php else: ?>
            <p>Для работы с объявлениями войдите в аккаунт или зарегистрируйтесь.</p>
            <div class="actions">
                <a class="button" href="login.php">Войти</a>
                <a class="button secondary" href="register.php">Регистрация</a>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
