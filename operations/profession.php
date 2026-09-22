<?php

require_login();

if (!$db instanceof PDO) {
    http_response_code(500);
    exit('Brak połączenia z bazą danych.');
}

$user = current_user();
$stats = get_player_stats($db, (int) $user['id']);

if ($stats && $stats['profession'] !== null) {
    redirect('./');
}

$professions = [
    'Morderca',
    'Złodziej',
    'Biznesmen',
    'Gangster',
    'Diler',
    'Alfons',
];

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Sesja formularza wygasła. Odśwież stronę.';
    } else {
        $profession = isset($_POST['profession']) && is_string($_POST['profession'])
            ? $_POST['profession']
            : '';

        if (!in_array($profession, $professions, true)) {
            $error = 'Wybierz poprawną profesję.';
        } else {
            $stmt = $db->prepare(
                'UPDATE player_stats
                 SET profession = :profession
                 WHERE user_id = :user_id AND profession IS NULL'
            );
            $stmt->execute([
                'profession' => $profession,
                'user_id' => (int) $user['id'],
            ]);

            redirect('./');
        }
    }
}
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Wybierz profesję - MBZ</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; font-family: Arial, sans-serif; background: #111; color: #fff; }
        .wrap { width: min(760px, calc(100% - 32px)); margin: 0 auto; padding: 48px 0; }
        .card { padding: 28px; border: 1px solid #333; border-radius: 16px; background: #181818; }
        h1 { margin-top: 0; }
        .muted { color: #aaa; }
        .professions { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-top: 24px; }
        .profession { display: block; }
        .profession input { position: absolute; opacity: 0; pointer-events: none; }
        .profession span { display: block; padding: 18px; border: 1px solid #444; border-radius: 10px; background: #111; cursor: pointer; text-align: center; font-weight: 700; }
        .profession input:checked + span { border-color: #fff; background: #292929; }
        button { width: 100%; margin-top: 24px; padding: 14px 16px; border: 0; border-radius: 8px; background: #fff; color: #111; cursor: pointer; font-weight: 700; }
        .error { padding: 12px 14px; margin-bottom: 18px; border-radius: 8px; background: #3a1717; }
        @media (max-width: 600px) { .professions { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="wrap">
    <section class="card">
        <h1>Wybierz profesję</h1>
        <p class="muted">Zanim rozpoczniesz grę, wybierz kim chcesz zostać w MBZ.</p>

        <?php if ($error !== ''): ?>
            <div class="error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="./?page=profession">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

            <div class="professions">
                <?php foreach ($professions as $profession): ?>
                    <label class="profession">
                        <input type="radio" name="profession" value="<?= e($profession) ?>" required>
                        <span><?= e($profession) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <button type="submit">Wybieram profesję</button>
        </form>
    </section>
</div>
</body>
</html>
