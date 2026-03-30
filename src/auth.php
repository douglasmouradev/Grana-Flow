<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function startSessionIfNeeded(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function csrfToken(): string
{
    startSessionIfNeeded();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

function verifyCsrfToken(?string $token): bool
{
    startSessionIfNeeded();
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (!is_string($sessionToken) || $sessionToken === '') {
        return false;
    }
    return is_string($token) && hash_equals($sessionToken, $token);
}

function currentUserId(): ?int
{
    startSessionIfNeeded();
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

function isLoggedIn(): bool
{
    return currentUserId() !== null;
}

function requireAuth(): void
{
    if (!isLoggedIn()) {
        header('Location: /?page=login');
        exit;
    }
}

function registerUser(string $name, string $email, string $password): array
{
    $name = trim($name);
    $email = trim(mb_strtolower($email));

    if ($name === '' || $email === '' || $password === '') {
        return ['ok' => false, 'message' => 'Preencha todos os campos.'];
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Informe um e-mail valido.'];
    }

    if (mb_strlen($password) < 6) {
        return ['ok' => false, 'message' => 'A senha deve ter pelo menos 6 caracteres.'];
    }

    $stmt = db()->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    if ($stmt->fetch()) {
        return ['ok' => false, 'message' => 'Este e-mail ja esta cadastrado.'];
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $insert = db()->prepare('INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :password_hash)');
    $insert->execute([
        'name' => $name,
        'email' => $email,
        'password_hash' => $hash,
    ]);

    $userId = (int) db()->lastInsertId();
    db()->prepare('INSERT INTO finance_settings (user_id, monthly_salary) VALUES (:user_id, 0)')
        ->execute(['user_id' => $userId]);

    startSessionIfNeeded();
    $_SESSION['user_id'] = $userId;

    return ['ok' => true, 'message' => 'Conta criada com sucesso.'];
}

function loginUser(string $email, string $password): array
{
    startSessionIfNeeded();
    $attempts = (int) ($_SESSION['login_attempts'] ?? 0);
    $lockedUntil = (int) ($_SESSION['login_locked_until'] ?? 0);
    if ($lockedUntil > time()) {
        return ['ok' => false, 'message' => 'Muitas tentativas. Aguarde 5 minutos.'];
    }

    $email = trim(mb_strtolower($email));

    if ($email === '' || $password === '') {
        return ['ok' => false, 'message' => 'Informe e-mail e senha.'];
    }

    $stmt = db()->prepare('SELECT id, password_hash FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, (string) $user['password_hash'])) {
        $attempts++;
        $_SESSION['login_attempts'] = $attempts;
        if ($attempts >= 5) {
            $_SESSION['login_locked_until'] = time() + 300;
            $_SESSION['login_attempts'] = 0;
        }
        return ['ok' => false, 'message' => 'Credenciais invalidas.'];
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['login_attempts'] = 0;
    $_SESSION['login_locked_until'] = 0;

    return ['ok' => true, 'message' => 'Login realizado com sucesso.'];
}

function logoutUser(): void
{
    startSessionIfNeeded();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
}
