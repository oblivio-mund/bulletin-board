<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="ru">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>403 — Доступ запрещён</title>
        <link rel="stylesheet" href="../assets/css/auth.css">
    </head>
    <body>
        <main class="auth-page">
            <section class="auth-card">
                <h1>403</h1>
                <p class="subtitle">Доступ запрещён. Эта страница доступна только администратору.</p>
                <a class="button" href="<?= e(route_url('cabinet')) ?>">Вернуться в кабинет</a>
            </section>
        </main>
    </body>
</html>
