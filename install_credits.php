<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

try {
    $check = $pdo->query("SHOW COLUMNS FROM player_stats LIKE 'credits'");
    if (!$check->fetch()) {
        $pdo->exec("ALTER TABLE player_stats ADD credits BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER cash");
    }

    echo 'MBZ CREDITS SQL OK';
} catch (Throwable $e) {
    http_response_code(500);
    echo 'MBZ CREDITS SQL ERROR';
}
