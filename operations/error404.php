<?php
http_response_code(404);
$backUrl = user_logged_in() ? './?page=main' : './';
?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>404 — Miasto Bez Zasad</title>
  <style>
    * { box-sizing: border-box; }
    body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; font-family: Arial, Helvetica, sans-serif; color: #f5f5f5; background: radial-gradient(ellipse at 50% 15%, #392020 0%, #191919 42%, #0c0c0e 100%); }
    .panel { width: min(100%, 580px); padding: 52px 30px; text-align: center; border: 1px solid #493030; border-radius: 22px; background: rgba(25, 25, 28, .92); box-shadow: 0 24px 90px #0008; }
    .brand { color: #b7b7b7; letter-spacing: .28em; font-size: 12px; font-weight: 800; }
    .code { margin: 20px 0 4px; color: #ff5454; font-size: clamp(100px, 24vw, 160px); line-height: 1; font-weight: 900; text-shadow: 0 0 55px #b9232340; }
    h1 { margin: 14px 0; font-size: clamp(23px, 5vw, 32px); }
    p { max-width: 410px; margin: 0 auto 28px; line-height: 1.65; color: #b7b7b7; }
    .button { display: inline-block; padding: 14px 26px; border-radius: 10px; background: #df3939; color: #fff; text-decoration: none; font-weight: 800; transition: background .2s, transform .2s; }
    .button:hover, .button:focus-visible { background: #ff5454; transform: translateY(-2px); }
    .foot { margin-top: 32px; color: #777; font-size: 12px; letter-spacing: .12em; }
  </style>
</head>
<body>
  <main class="panel">
    <div class="brand">MBZ / MIASTO BEZ ZASAD</div>
    <div class="code">404</div>
    <h1>Ta ulica nie istnieje.</h1>
    <p>Zgubiłeś drogę w mieście bez zasad. Ta strona nie istnieje albo została przeniesiona. Wróć do gry, zanim zrobi się niebezpiecznie.</p>
    <a class="button" href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>">WRÓĆ DO GRY →</a>
    <div class="foot">NIE KAŻDA DROGA PROWADZI DO CELU.</div>
  </main>
</body>
</html>
