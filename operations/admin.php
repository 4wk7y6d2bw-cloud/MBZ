<?php

require_admin();
$user = current_user();
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MBZ — Panel admina</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; font-family: Arial, sans-serif; background: #111; color: #fff; }
        .wrap { width: min(980px, calc(100% - 32px)); margin: 0 auto; padding: 48px 0; }
        .card { padding: 28px; border: 1px solid #333; border-radius: 16px; background: #181818; }
        .button { display: inline-block; margin-top: 18px; padding: 12px 16px; border-radius: 8px; background: #fff; color: #111; text-decoration: none; font-weight: 700; }
        .button.secondary { margin-left: 8px; background: #2a2a2a; color: #fff; }
        .muted { color: #aaa; }
    </style>
</head>
<body>
<div class="wrap">
    <section class="card">
        <h1>Panel admina</h1>
        <p>Zalogowano jako <strong><?= e($user['login'] ?? '') ?></strong>.</p>
        <p class="muted">Ten ekran jest dostępny wyłącznie dla kont z <code>is_admin = 1</code>.</p>

        <a class="button" href="./">Wróć do gry</a>
        <a class="button secondary" href="./?page=logout">Wyloguj</a>
    </section>
</div>
</body>
</html>
