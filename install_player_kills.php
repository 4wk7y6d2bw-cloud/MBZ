<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $db = db_connect();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->exec("CREATE TABLE IF NOT EXISTS player_kills (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        killer_id INT NOT NULL,
        victim_id INT NOT NULL,
        season INT NOT NULL,
        game_day INT NOT NULL,
        killed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX kills_day (season, game_day, killer_id),
        INDEX kills_victim (victim_id, killed_at),
        INDEX kills_killer (killer_id, killed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    echo "OK - tabela player_kills zostala utworzona lub juz istniala.\n";
    echo "Mozesz teraz usunac ten plik z serwera.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "BLAD SQL: " . $e->getMessage() . "\n";
}
