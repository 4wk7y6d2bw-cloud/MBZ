<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('Content-Type: text/html; charset=utf-8');

try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            login VARCHAR(24) NOT NULL,
            email VARCHAR(255) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            is_admin TINYINT(1) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_users_login (login),
            UNIQUE KEY uq_users_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $login = 'admin';
    $email = 'admin@mbz.local';
    $password = 'damian123';

    $stmt = $pdo->prepare('SELECT id FROM users WHERE login = :login LIMIT 1');
    $stmt->execute(['login' => $login]);
    $existingId = $stmt->fetchColumn();

    $hash = password_hash($password, PASSWORD_DEFAULT);

    if ($existingId) {
        $stmt = $pdo->prepare(
            'UPDATE users
             SET email = :email, password_hash = :password_hash, is_admin = 1, active = 1
             WHERE id = :id'
        );
        $stmt->execute([
            'email' => $email,
            'password_hash' => $hash,
            'id' => $existingId,
        ]);
        $message = 'Tabela działa. Konto admin zostało zaktualizowane.';
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO users (login, email, password_hash, is_admin, active)
             VALUES (:login, :email, :password_hash, 1, 1)'
        );
        $stmt->execute([
            'login' => $login,
            'email' => $email,
            'password_hash' => $hash,
        ]);
        $message = 'Tabela działa. Konto admin zostało utworzone.';
    }

    echo '<h1>MBZ SQL OK</h1>';
    echo '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p><strong>Usuń teraz plik install.php.</strong></p>';
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>MBZ SQL ERROR</h1>';
    echo '<p>Instalacja nie powiodła się. Sprawdź dane połączenia w config.php.</p>';
}
