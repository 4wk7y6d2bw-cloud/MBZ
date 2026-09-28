<?php
require_admin();
header('Cache-Control: no-store, private');
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $message = 'Nieprawidłowy token. Odśwież stronę i spróbuj ponownie.';
    } elseif (!$db instanceof PDO) {
        $message = 'Brak połączenia z bazą.';
    } else {
        try {
            $stmt = $db->prepare('UPDATE player_stats SET cash = 1000, respect = GREATEST(100, 150 + FLOOR((GREATEST(0,strength-10) + GREATEST(0,endurance-10) + GREATEST(0,intelligence-10) + GREATEST(0,charisma-10) + GREATEST(0,cunning-10))/20)) WHERE user_id = :id');
            $stmt->execute(['id' => (int) current_user()['id']]);
            $message = 'Gotowe. Twoje konto administratora ma teraz 1000 $.';
        } catch (Throwable $e) {
            $message = 'Nie udało się wykonać aktualizacji.';
        }
    }
}
?>
<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>MBZ — test 1000 $</title></head>
<body style="background:#121721;color:#fff;font:18px Arial,sans-serif;max-width:500px;margin:12vh auto;padding:20px">
<h1>MBZ — aktualizacja SQL</h1>
<p>Ustaw gotówkę zalogowanego administratora na <strong>1000 $</strong>.</p>
<?php if ($message): ?><p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><button style="padding:14px 22px;cursor:pointer">Ustaw 1000 $</button></form>
<p><a style="color:#87bfff" href="./?page=main">Powrót do gry</a></p>
</body></html>