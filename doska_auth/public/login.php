<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_guest();
$error = '';
$login = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim((string)($_POST['login'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if (!verify_csrf($_POST['csrf_token'] ?? null)) $error = 'Недействительный запрос. Обновите страницу и повторите попытку.';
    elseif ($login === '' || $password === '') $error = 'Заполните логин и пароль.';
    elseif (mb_strlen($login) > 150 || strlen($password) > 72) $error = 'Некорректные данные для входа.';
    else {
        $stmt = db()->prepare('SELECT user_id,display_name,password_hash,is_active FROM users WHERE login=:login LIMIT 1');
        $stmt->execute(['login'=>$login]); $u=$stmt->fetch();
        if (!$u || !(int)$u['is_active'] || !password_verify($password,$u['password_hash'])) $error='Неверный логин или пароль.';
        else { if (password_needs_rehash($u['password_hash'],PASSWORD_DEFAULT)) { $newHash=password_hash($password,PASSWORD_DEFAULT); $up=db()->prepare('UPDATE users SET password_hash=:hash WHERE user_id=:id'); $up->execute(['hash'=>$newHash,'id'=>$u['user_id']]); } session_regenerate_id(true); $_SESSION['user_id']=(int)$u['user_id']; $_SESSION['csrf_token']=bin2hex(random_bytes(32)); header('Location: '.route_url('cabinet')); exit; }
    }
}
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Авторизация — <?= e(APP_NAME) ?></title><link rel="stylesheet" href="../assets/css/auth.css"></head><body><main class="auth-page"><section class="auth-card"><a class="brand" href="<?= e(route_url()) ?>">Доска объявлений</a><h1>Вход в аккаунт</h1><p class="subtitle">Введите данные учётной записи.</p><?php if($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?><form method="post" action="<?= e(route_url('login')) ?>"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label for="login">Логин</label><input id="login" name="login" type="text" maxlength="150" required autocomplete="username" value="<?= e($login) ?>"><label for="password">Пароль</label><input id="password" name="password" type="password" maxlength="72" required autocomplete="current-password"><button type="submit">Войти</button></form><p class="switch">Нет аккаунта? <a href="<?= e(route_url('register')) ?>">Зарегистрироваться</a></p></section></main></body></html>
