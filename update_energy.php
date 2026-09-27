<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/base/auth.php';
if (!current_user_is_admin()) {
    http_response_code(403);
    exit('Zaloguj sie na konto administratora w grze.');
}
header('Content-Type: text/html; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        http_response_code(403);
        exit('Nieprawidlowy token CSRF.');
    }
    try {
        $db = db_connect();
        if (!$db instanceof PDO) throw new RuntimeException('Brak polaczenia z baza.');
        $exists = $db->query("SHOW COLUMNS FROM player_stats LIKE 'energy_updated_at'")->fetch();
        if (!$exists) {
            $db->exec("ALTER TABLE player_stats ADD COLUMN energy_updated_at DATETIME NULL DEFAULT NULL");
        }
        // Begin timing from migration for existing players; no invented offline elapsed time.
        $db->exec("UPDATE player_stats SET energy_updated_at = NOW() WHERE energy_updated_at IS NULL");
        echo '<h2>Gotowe! Regeneracja energii: +2% co minute, maks. 100%.</h2><p>Usun teraz plik update_energy.php z hostingu.</p>';
    } catch (Throwable $e) {
        http_response_code(500);
        echo '<h2>Aktualizacja nie powiodla sie. Sprawdz logi serwera.</h2>';
    }
    exit;
}
echo '<h2>Aktualizacja SQL: regeneracja energii</h2><p>Doda kolumne energy_updated_at i uruchomi licznik od teraz dla istniejacych graczy.</p><form method="post"><input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '"><button type="submit">Aktualizuj SQL</button></form>';
