<?php

require_login();

if (!$db instanceof PDO) {
    http_response_code(500);
    exit('Brak połączenia z bazą danych.');
}

$user = current_user();
$stats = get_player_stats($db, (int) $user['id']);

if ($stats && $stats['profession'] !== null && $stats['current_city'] !== null) {
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

$cities = [
    'Warszawa', 'Kraków', 'Wrocław', 'Łódź', 'Poznań', 'Gdańsk', 'Szczecin',
    'Rzeszów', 'Katowice', 'Bydgoszcz', 'Olsztyn', 'Białystok', 'Lublin', 'Kielce',
];

$error = '';
$step = 1;
$selectedProfession = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Sesja formularza wygasła. Odśwież stronę.';
    } else {
        $profession = isset($_POST['profession']) && is_string($_POST['profession'])
            ? $_POST['profession']
            : '';

        if (!in_array($profession, $professions, true)) {
            $error = 'Wybierz poprawną profesję.';
        } elseif (($_POST['step'] ?? '1') === '1') {
            $selectedProfession = $profession;
            $step = 2;
        } else {
            $city = isset($_POST['city']) && is_string($_POST['city']) ? $_POST['city'] : '';
            $selectedProfession = $profession;
            $step = 2;

            if (!in_array($city, $cities, true)) {
                $error = 'Wybierz poprawne miasto startowe.';
            } else {
                $stmt = $db->prepare(
                    'UPDATE player_stats
                     SET profession = :profession, current_city = :current_city
                     WHERE user_id = :user_id'
                );
                $stmt->execute([
                    'profession' => $profession,
                    'current_city' => $city,
                    'user_id' => (int) $user['id'],
                ]);

                redirect('./');
            }
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
        .cities { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-top: 24px; }
        .profession, .city { display: block; }
        .profession input, .city input { position: absolute; opacity: 0; pointer-events: none; }
        .profession span, .city span { display: block; padding: 18px; border: 1px solid #444; border-radius: 10px; background: #111; cursor: pointer; text-align: center; font-weight: 700; }
        .profession input:checked + span, .city input:checked + span { border-color: #fff; background: #292929; }
        button { width: 100%; margin-top: 24px; padding: 14px 16px; border: 0; border-radius: 8px; background: #fff; color: #111; cursor: pointer; font-weight: 700; }
        .error { padding: 12px 14px; margin-bottom: 18px; border-radius: 8px; background: #3a1717; }
        @media (max-width: 600px) { .professions, .cities { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="wrap">
    <section class="card">
        <?php if ($step === 1): ?>
            <h1>Wybierz profesję</h1>
            <p class="muted">Krok 1 z 2 — wybierz kim chcesz zostać w MBZ.</p>
        <?php else: ?>
            <h1>Wybierz miasto</h1>
            <p class="muted">Krok 2 z 2 — wybierz miasto, w którym rozpoczniesz grę.</p>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="./?page=profession">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="step" value="<?= $step ?>">
            <?php if ($step === 1): ?>
                <div class="professions">
                    <?php foreach ($professions as $profession): ?>
                        <label class="profession">
                            <input type="radio" name="profession" value="<?= e($profession) ?>" required>
                            <span><?= e($profession) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <button type="submit">Dalej</button>
            <?php else: ?>
                <input type="hidden" name="profession" value="<?= e($selectedProfession) ?>">
                <div class="cities">
                    <?php foreach ($cities as $city): ?>
                        <label class="city">
                            <input type="radio" name="city" value="<?= e($city) ?>" required>
                            <span><?= e($city) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <button type="submit">Rozpocznij grę</button>
            <?php endif; ?>
        </form>
    </section>
</div>
</body>
</html>
