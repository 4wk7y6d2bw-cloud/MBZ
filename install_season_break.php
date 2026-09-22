<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

try {
    $check = $pdo->query("SHOW COLUMNS FROM game_state LIKE 'is_break'");
    if (!$check->fetch()) {
        $pdo->exec("ALTER TABLE game_state ADD is_break TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER season");
    }
    echo 'MBZ SEASON BREAK SQL OK';
} catch (Throwable $e) {
    http_response_code(500);
    echo 'MBZ SEASON BREAK SQL ERROR';
}
