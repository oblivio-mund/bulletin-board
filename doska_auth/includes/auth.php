<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT user_id, display_name, login, role, is_active FROM users WHERE user_id = :id LIMIT 1'
    );
    $stmt->execute(['id' => (int)$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || !$user['is_active']) {
        logout_user();
        return null;
    }

    return $user;
}

function require_guest(): void
{
    if (current_user() !== null) {
        header('Location: index.php');
        exit;
    }
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'], $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

function validate_registration(string $displayName, string $login, string $password, string $passwordConfirm): array
{
    $errors = [];

    $displayName = trim($displayName);
    $login = trim($login);

    if (mb_strlen($displayName) < 2 || mb_strlen($displayName) > 100) {
        $errors[] = 'Имя должно содержать от 2 до 100 символов.';
    }

    if (!preg_match('/^[\p{L}\p{N}._-]{3,150}$/u', $login)) {
        $errors[] = 'Логин: от 3 до 150 символов; разрешены буквы, цифры, точка, дефис и подчёркивание.';
    }

    if (strlen($password) < 8 || strlen($password) > 72) {
        $errors[] = 'Пароль должен содержать от 8 до 72 символов.';
    }

    if (!preg_match('/[A-Za-zА-Яа-я]/u', $password) || !preg_match('/\d/', $password)) {
        $errors[] = 'Пароль должен содержать хотя бы одну букву и одну цифру.';
    }

    if (!hash_equals($password, $passwordConfirm)) {
        $errors[] = 'Пароли не совпадают.';
    }

    return $errors;
}
