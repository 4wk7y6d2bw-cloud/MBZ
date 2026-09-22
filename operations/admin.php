<?php

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !admin_logged_in()) {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Sesja formularza wygasła. Odśwież stronę.';
    } elseif (!$db instanceof PDO) {
        $error = 'Baza danych nie jest jeszcze skonfigurowana.';
    } else {
        $result = admin_login(
            $db,
            isset($_POST['login']) && is_string($_POST['login']) ? $_POST['login'] : '',
            isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : ''
        );

        if ($result['success']) {
            redirect('./?page=admin');
        }

        $error = $result['message'];
    }
}
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MBZ — Admin</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: Arial, sans-serif; background: #111; color: #fff; }
        .box { width: min(420px, calc(100% - 32px)); padding: 28px; border: 1px solid #333; border-radius: 16px; background: #181818; }
        h1 { margin-top: 0; }
        label { display: block; margin: 14px 0 6px; }
        input { width: 100%; padding: 12px; border: 1px solid #444; border-radius: 8px; background: #0f0f0f; color: #fff; }
        button, .button { display: inline-block; margin-top: 18px; padding: 12px 16px; border: 0; border-radius: 8px; background: #fff; color: #111; text-decoration: none; cursor: pointer; font-weight: 700; }
        .error { padding: 10px 12px; border-radius: 8px; background: #3a1717; margin-bottom: 14px; }
        .muted { color: #aaa; }
    </style>
</head>
<body>
<div class="box">
<?php if (admin_logged_in()): ?>
    <h1>Panel admina</h1>
    <p>Zalogowano jako <strong><?= e($_SESSION['admin']['login'] ?? '') ?></strong>.</p>
    <p class="muted">Czysta baza panelu MBZ. Tutaj będziemy dodawać kolejne moduły.</p>
    <a class="button" href="./?page=admin_logout">Wyloguj</a>
<?php else: ?>
    <h1>Logowanie</h1>
    <?php if ($error !== ''): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
    <?php if (!$db instanceof PDO): ?><p class="muted">Nowa baza nie jest jeszcze podłączona.</p><?php endif; ?>
    <form method="post" action="./?page=admin" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label for="login">Login</label>
        <input id="login" name="login" type="text" required>
        <label for="password">Hasło</label>
        <input id="password" name="password" type="password" required>
        <button type="submit">Zaloguj</button>
    </form>
<?php endif; ?>
</div>
</body>
</html>
