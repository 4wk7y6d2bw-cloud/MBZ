<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS player_stats (
            user_id INT UNSIGNED NOT NULL PRIMARY KEY,
            cash BIGINT UNSIGNED NOT NULL DEFAULT 500,
            respect INT UNSIGNED NOT NULL DEFAULT 100,
            profession VARCHAR(100) NULL DEFAULT NULL,
            energy TINYINT UNSIGNED NOT NULL DEFAULT 100,
            tickets TINYINT UNSIGNED NOT NULL DEFAULT 25,
            strength INT UNSIGNED NOT NULL DEFAULT 10,
            endurance INT UNSIGNED NOT NULL DEFAULT 10,
            intelligence INT UNSIGNED NOT NULL DEFAULT 10,
            charisma INT UNSIGNED NOT NULL DEFAULT 10,
            cunning INT UNSIGNED NOT NULL DEFAULT 10,
            tickets_last_grant DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_player_stats_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        INSERT IGNORE INTO player_stats
        (user_id, cash, respect, profession, energy, tickets, strength, endurance, intelligence, charisma, cunning, tickets_last_grant)
        SELECT id, 500, 100, NULL, 100, 25, 10, 10, 10, 10, 10, NOW()
        FROM users
    ");

    echo 'MBZ STATS SQL OK';
} catch (Throwable $e) {
    http_response_code(500);
    echo 'MBZ STATS SQL ERROR';
}
