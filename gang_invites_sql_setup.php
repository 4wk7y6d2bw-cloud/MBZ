<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

header('Content-Type: text/html; charset=utf-8');

try {
    $db = db_connect();
    if (!$db instanceof PDO) {
        throw new RuntimeException('Brak połączenia z bazą danych.');
    }

    $db->exec("CREATE TABLE IF NOT EXISTS gang_invites (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        gang_id INT UNSIGNED NOT NULL,
        invited_user_id INT NOT NULL,
        invited_by INT NOT NULL,
        status ENUM('pending','accepted','rejected','cancelled') NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        responded_at DATETIME NULL DEFAULT NULL,
        INDEX gang_invites_gang (gang_id),
        INDEX gang_invites_user (invited_user_id,status),
        INDEX gang_invites_sender (invited_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    echo '<h2>GOTOWE</h2>';
    echo '<p>Tabela <strong>gang_invites</strong> została utworzona.</p>';
    echo '<p>Możesz teraz usunąć ten plik.</p>';
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h2>BŁĄD</h2>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
}
