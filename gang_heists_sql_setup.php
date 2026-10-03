<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

header('Content-Type: text/html; charset=utf-8');

try {
    $db = db_connect();
    if (!$db instanceof PDO) throw new RuntimeException('Brak połączenia z bazą danych.');

    $db->exec("CREATE TABLE IF NOT EXISTS player_jail (
        user_id INT NOT NULL PRIMARY KEY,
        jailed_until DATETIME NOT NULL,
        bribe_target BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS gang_heists (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        gang_id INT UNSIGNED NOT NULL,
        created_by INT NOT NULL,
        heist_type VARCHAR(40) NOT NULL DEFAULT 'convoy',
        status ENUM('recruiting','completed','failed','cancelled') NOT NULL DEFAULT 'recruiting',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resolved_at DATETIME NULL DEFAULT NULL,
        INDEX gang_heists_gang_status (gang_id,status),
        INDEX gang_heists_creator (created_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS gang_heist_participants (
        heist_id BIGINT UNSIGNED NOT NULL,
        user_id INT NOT NULL,
        status ENUM('accepted','declined') NOT NULL DEFAULT 'accepted',
        joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        robbery_power INT NOT NULL DEFAULT 0,
        reward_cash INT NOT NULL DEFAULT 0,
        reward_stats INT NOT NULL DEFAULT 0,
        PRIMARY KEY (heist_id,user_id),
        INDEX gang_heist_participant_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    echo '<h2>GOTOWE</h2>';
    echo '<p>Utworzono/sprawdzono tabele:</p>';
    echo '<ul><li>player_jail</li><li>gang_heists</li><li>gang_heist_participants</li></ul>';
    echo '<p>Baza jest przygotowana pod więzienie i Napad na konwój. Możesz teraz usunąć ten plik.</p>';
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h2>BŁĄD</h2><pre>'.htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8').'</pre>';
}
