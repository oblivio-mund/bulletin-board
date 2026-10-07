<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_guest();

$errors = [];
$displayName = '';
$login = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $displayName = trim((string)($_POST['display_name'] ?? ''));
    $login = trim((string)($_POST['login'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $passwordConfirm = (string)($_POST['password_confirm'] ?? '');

    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Недействительный запрос. Обновите страницу и повторите попытку.';
    } else {
        $errors = validate_registration($displayName, $login, $password, $passwordConfirm);

        if (!$errors) {
            $stmt = db()->prepare('SELECT user_id FROM users WHERE login = :login LIMIT 1');
            $stmt->execute(['login' => $login]);

            if ($stmt->fetch()) {
                $errors[] = 'Пользователь с таким логином уже существует.';
            } else {
                $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
                $hash = password_hash($password, $algorithm);

                $stmt = db()->prepare(
                    'INSERT INTO users (display_name, login, password_hash, role, is_active)
                     VALUES (:display_name, :login, :password_hash, :role, TRUE)'
                );
                $stmt->execute([
                    'display_name' => $displayName,
                    'login' => $login,
                    'password_hash' => $hash,
                    'role' => 'user'
                ]);

                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)db()->lastInsertId();
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                header('Location: ' . route_url('cabinet'));
                exit;
            }
        }
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Регистрация — <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="../assets/css/auth.css">
</head>
<body>
<main class="auth-page">
    <section class="auth-card">
        <a class="brand" href="<?= e(route_url()) ?>">Доска объявлений</a>
        <h1>Создание аккаунта</h1>
        <p class="subtitle">Зарегистрируйтесь, чтобы публиковать объявления, сохранять избранное и общаться с пользователями.</p>

        <?php if ($errors): ?>
            <div class="alert alert-error" role="alert">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= e(route_url('register')) ?>" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

            <label for="display_name">Имя</label>
            <input id="display_name" name="display_name" type="text" maxlength="100" required autocomplete="name" value="<?= e($displayName) ?>">

            <label for="login">Логин</label>
            <input id="login" name="login" type="text" maxlength="150" required autocomplete="username" value="<?= e($login) ?>">

            <label for="password">Пароль</label>
            <input id="password" name="password" type="password" minlength="8" maxlength="72" required autocomplete="new-password">
            <small>Не менее 8 символов, включая букву и цифру.</small>

            <label for="password_confirm">Повторите пароль</label>
            <input id="password_confirm" name="password_confirm" type="password" minlength="8" maxlength="72" required autocomplete="new-password">

            <button type="submit">Зарегистрироваться</button>
        </form>

        <p class="switch">Уже есть аккаунт? <a href="<?= e(route_url('login')) ?>">Войти</a></p>
    </section>
</main>
</body>
</html>
