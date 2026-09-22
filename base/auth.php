<?php

function admin_logged_in(): bool
{
    return !empty($_SESSION['admin'])
        && is_array($_SESSION['admin'])
        && !empty($_SESSION['admin']['id']);
}

function admin_login(PDO $db, string $login, string $password): array
{
    $login = trim($login);

    if ($login === '' || $password === '') {
        return ['success' => false, 'message' => 'Wpisz login i hasło.'];
    }

    $stmt = $db->prepare(
        'SELECT id, login, password_hash, active
         FROM users
         WHERE login = :login
         LIMIT 1'
    );
    $stmt->execute(['login' => $login]);
    $user = $stmt->fetch();

    if (!$user || (int) $user['active'] !== 1 || !password_verify($password, $user['password_hash'])) {
        return ['success' => false, 'message' => 'Nieprawidłowy login lub hasło.'];
    }

    session_regenerate_id(true);
    $_SESSION['admin'] = [
        'id' => (int) $user['id'],
        'login' => (string) $user['login'],
    ];

    return ['success' => true, 'message' => ''];
}

function require_admin(): void
{
    if (!admin_logged_in()) {
        redirect('./?page=admin');
    }
}
