<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

try {
    $pdo->beginTransaction();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS game_state (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            game_day TINYINT UNSIGNED NOT NULL DEFAULT 1,
            season INT UNSIGNED NOT NULL DEFAULT 1,
            next_ranking_update DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $stmt = $pdo->query("SHOW COLUMNS FROM player_stats LIKE 'public_respect'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE player_stats ADD public_respect INT UNSIGNED NOT NULL DEFAULT 100 AFTER respect");
        $pdo->exec("UPDATE player_stats SET public_respect = respect");
    }

    $pdo->exec("
        INSERT IGNORE INTO game_state (id, game_day, season, next_ranking_update)
        VALUES (1, 1, 1, DATE_ADD(NOW(), INTERVAL 4 HOUR))
    ");

    $pdo->commit();
    echo 'MBZ GAME CLOCK SQL OK';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo 'MBZ GAME CLOCK SQL ERROR';
}
