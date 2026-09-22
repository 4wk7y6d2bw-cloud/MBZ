<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

try {
    $check = $pdo->query("SHOW COLUMNS FROM player_stats LIKE 'current_city'");
    if (!$check->fetch()) {
        $pdo->exec("ALTER TABLE player_stats ADD current_city VARCHAR(50) NULL DEFAULT NULL AFTER profession");
    }

    echo 'MBZ CITY SQL OK';
} catch (Throwable $e) {
    http_response_code(500);
    echo 'MBZ CITY SQL ERROR';
}
