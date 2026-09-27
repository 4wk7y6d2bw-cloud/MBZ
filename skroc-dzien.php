<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/base/auth.php';
require_admin();
header('Content-Type: text/html; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Nieprawidłowy token.');
    }
    $stmt = $pdo->prepare('UPDATE game_state SET next_ranking_update = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE id = 1 AND is_break = 0');
    $stmt->execute();
    echo '<p>'.($stmt->rowCount() ? 'Gotowe: dzień zakończy się za 5 minut.' : 'Nie zmieniono czasu (możliwa przerwa sezonowa).').'</p>';
    echo '<a href="./?page=main">Wróć do gry</a>';
    exit;
}
?>
<!doctype html><html lang="pl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<body style="background:#111;color:white;font:18px Arial;padding:30px">
<h1>MBZ — skróć bieżący dzień</h1><p>Jednorazowo ustaw koniec aktualnego dnia za 5 minut. Następne dni pozostaną 4-godzinne.</p>
<form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><button style="padding:15px;background:#d33;color:white;border:0;border-radius:8px">Ustaw 5 minut</button></form></body></html>