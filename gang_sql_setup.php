<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
header('Content-Type: text/html; charset=utf-8');
try {
    $db = db_connect();
    if (!$db instanceof PDO) throw new RuntimeException('Brak połączenia z bazą danych.');
    $db->exec("CREATE TABLE IF NOT EXISTS gangs (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,name VARCHAR(20) NOT NULL,owner_id INT NOT NULL,respect BIGINT NOT NULL DEFAULT 0,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY gangs_name_unique (name),KEY gangs_owner (owner_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS gang_members (gang_id INT UNSIGNED NOT NULL,user_id INT NOT NULL,role ENUM('boss','member') NOT NULL DEFAULT 'member',joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (user_id),KEY gang_members_gang (gang_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $tagColumn = $db->query("SHOW COLUMNS FROM gangs LIKE 'tag'")->fetch();
    if ($tagColumn) {
        $indexes = $db->query("SHOW INDEX FROM gangs WHERE Column_name='tag'")->fetchAll();
        foreach ($indexes as $index) {
            $keyName = (string)($index['Key_name'] ?? '');
            if ($keyName !== '' && $keyName !== 'PRIMARY') {
                $safeKey = str_replace(chr(96), chr(96).chr(96), $keyName);
                $db->exec("ALTER TABLE gangs DROP INDEX " . chr(96) . $safeKey . chr(96));
            }
        }
        $db->exec("ALTER TABLE gangs DROP COLUMN tag");
    }
    $db->exec("ALTER TABLE gangs MODIFY name VARCHAR(20) NOT NULL");
    echo '<h1>GOTOWE</h1><p>Tabele gangs i gang_members są gotowe.</p><p>Gang: nazwa 3-20 znaków, bez tagu.</p><p><b>Teraz usuń plik gang_sql_setup.php z serwera.</b></p>';
} catch (Throwable $e) {
    http_response_code(500);
    echo '<pre>BŁĄD SQL: '.htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</pre>';
}
