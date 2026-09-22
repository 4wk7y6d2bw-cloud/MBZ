<?php

function redirect(string $where): never
{
    header('Location: ' . $where);
    exit;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_valid(?string $token): bool
{
    return is_string($token) && hash_equals(csrf_token(), $token);
}

function db_configured(): bool
{
    global $dbData;

    return !empty($dbData['host'])
        && !empty($dbData['database'])
        && !empty($dbData['user']);
}

function db_connect(): ?PDO
{
    global $dbData;

    if (!db_configured()) {
        return null;
    }

    return new PDO(
        'mysql:host=' . $dbData['host'] . ';dbname=' . $dbData['database'] . ';charset=utf8mb4',
        $dbData['user'],
        $dbData['password'],
        [
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}
