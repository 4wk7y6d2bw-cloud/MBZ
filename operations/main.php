<?php

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !user_logged_in()) {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Sesja formularza wygasła. Odśwież stronę.';
    } elseif (!$db instanceof PDO) {
        $error = 'Baza danych nie jest jeszcze skonfigurowana.';
    } else {
        $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';

        if ($action === 'login') {
            $result = login_user(
                $db,
                isset($_POST['login']) && is_string($_POST['login']) ? $_POST['login'] : '',
                isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : ''
            );
        } elseif ($action === 'register') {
            $result = register_user(
                $db,
                isset($_POST['login']) && is_string($_POST['login']) ? $_POST['login'] : '',
                isset($_POST['email']) && is_string($_POST['email']) ? $_POST['email'] : '',
                isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '',
                isset($_POST['password_repeat']) && is_string($_POST['password_repeat']) ? $_POST['password_repeat'] : ''
            );
        } else {
            $result = ['success' => false, 'message' => 'Nieprawidłowa akcja.'];
        }

        if ($result['success']) {
            redirect('./');
        }

        $error = $result['message'];
    }
}

$user = current_user();
$stats = null;
if ($user && $db instanceof PDO) {
    $stats = get_player_stats($db, (int) $user['id']);
}
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MBZ</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; font-family: Arial, sans-serif; background: #111; color: #fff; }
        .wrap { width: min(980px, calc(100% - 32px)); margin: 0 auto; padding: 48px 0; }
        .auth-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 24px; }
        .card { padding: 28px; border: 1px solid #333; border-radius: 16px; background: #181818; }
        h1, h2 { margin-top: 0; }
        label { display: block; margin: 14px 0 6px; }
        input { width: 100%; padding: 12px; border: 1px solid #444; border-radius: 8px; background: #0f0f0f; color: #fff; }
        button, .button { display: inline-block; margin-top: 18px; padding: 12px 16px; border: 0; border-radius: 8px; background: #fff; color: #111; text-decoration: none; cursor: pointer; font-weight: 700; }
        .button.secondary { margin-left: 8px; background: #2a2a2a; color: #fff; }
        .error { padding: 12px 14px; border-radius: 8px; background: #3a1717; margin-bottom: 20px; }
        .muted { color: #aaa; }
        .topbar { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 24px; }
        .stats { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .stat { padding: 14px; background: #111; border: 1px solid #333; border-radius: 10px; }
        .stat span { display: block; color: #aaa; font-size: 13px; margin-bottom: 5px; }
        .stat strong { font-size: 18px; }
        @media (max-width: 720px) {
            .auth-grid, .stats { grid-template-columns: 1fr; }
            .topbar { align-items: flex-start; flex-direction: column; }
            .button.secondary { margin-left: 0; }
        }
    </style>
</head>
<body>
<div class="wrap">
<?php if ($user): ?>
    <div class="topbar">
        <div>
            <h1>MBZ</h1>
            <p class="muted">Zalogowano jako <strong><?= e($user['login'] ?? '') ?></strong>.</p>
        </div>
        <div>
            <?php if (current_user_is_admin()): ?>
                <a class="button" href="./?page=admin">Panel admina</a>
            <?php endif; ?>
            <a class="button secondary" href="./?page=logout">Wyloguj</a>
        </div>
    </div>

    <section class="card">
        <h2>Statystyki postaci</h2>
        <?php if ($stats): ?>
            <div class="stats">
                <div class="stat"><span>Kasa</span><strong><?= number_format((int) $stats['cash'], 0, '.', ',') ?> $</strong></div>
                <div class="stat"><span>Respekt</span><strong><?= (int) $stats['respect'] ?> pkt</strong></div>
                <div class="stat"><span>Profesja</span><strong><?= $stats['profession'] === null ? 'Nie wybrano' : e($stats['profession']) ?></strong></div>
                <div class="stat"><span>Energia</span><strong><?= (int) $stats['energy'] ?>%</strong></div>
                <div class="stat"><span>Bilety</span><strong><?= (int) $stats['tickets'] ?>/25</strong></div>
                <div class="stat"><span>Siła</span><strong><?= (int) $stats['strength'] ?></strong></div>
                <div class="stat"><span>Wytrzymałość</span><strong><?= (int) $stats['endurance'] ?></strong></div>
                <div class="stat"><span>Inteligencja</span><strong><?= (int) $stats['intelligence'] ?></strong></div>
                <div class="stat"><span>Charyzma</span><strong><?= (int) $stats['charisma'] ?></strong></div>
                <div class="stat"><span>Spryt</span><strong><?= (int) $stats['cunning'] ?></strong></div>
            </div>
        <?php endif; ?>
    </section>
<?php else: ?>
    <h1>MBZ</h1>

    <?php if ($error !== ''): ?>
        <div class="error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (!$db instanceof PDO): ?>
        <p class="muted">Baza danych nie jest jeszcze podłączona, więc logowanie i rejestracja są chwilowo nieaktywne.</p>
    <?php endif; ?>

    <div class="auth-grid">
        <section class="card">
            <h2>Logowanie</h2>
            <form method="post" action="./" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="login">

                <label for="login-login">Login</label>
                <input id="login-login" name="login" type="text" required>

                <label for="login-password">Hasło</label>
                <input id="login-password" name="password" type="password" required>

                <button type="submit">Zaloguj</button>
            </form>
        </section>

        <section class="card">
            <h2>Rejestracja</h2>
            <form method="post" action="./" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="register">

                <label for="register-login">Login</label>
                <input id="register-login" name="login" type="text" minlength="3" maxlength="24" required>

                <label for="register-email">E-mail</label>
                <input id="register-email" name="email" type="email" required>

                <label for="register-password">Hasło</label>
                <input id="register-password" name="password" type="password" minlength="8" required>

                <label for="register-password-repeat">Powtórz hasło</label>
                <input id="register-password-repeat" name="password_repeat" type="password" minlength="8" required>

                <button type="submit">Załóż konto</button>
            </form>
            <p class="muted">Nowe konto zawsze otrzymuje zwykłe uprawnienia użytkownika.</p>
        </section>
    </div>
<?php endif; ?>
</div>
</body>
</html>
