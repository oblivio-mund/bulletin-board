<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="ru">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>404</title>
        <link rel="stylesheet" href="../assets/css/auth.css">
    </head>
    <body>
        <main class="auth-page">
            <section class="auth-card">
                <h1>404</h1>
                <p class="subtitle">Страница не найдена.</p>
                <a class="button" href="<?= e(route_url()) ?>">На главную</a>
            </section>
        </main>
    </body>
</html>
