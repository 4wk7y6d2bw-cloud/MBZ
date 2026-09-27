<?php

require_admin();
$user = current_user();
$locations = ['ulica'=>'Ulica','napad'=>'Napad','gang'=>'Gang','sabotaz'=>'Sabotaż','nocne-zycie'=>'Nocne życie','kasyno'=>'Kasyno','handel'=>'Handel','skwer'=>'Skwer','czarny-rynek'=>'Czarny rynek','szpital'=>'Szpital','wiezienie'=>'Więzienie','bank'=>'Bank','policja'=>'Policja','detektyw'=>'Detektyw','transport'=>'Transport','silownia'=>'Siłownia'];
$message = '';
$cityNames = ['Warszawa','Kraków','Wrocław','Łódź','Poznań','Gdańsk','Szczecin','Rzeszów','Katowice','Bydgoszcz','Olsztyn','Białystok','Lublin','Kielce'];
$db->exec('CREATE TABLE IF NOT EXISTS city_raids (city VARCHAR(64) PRIMARY KEY, active TINYINT NOT NULL DEFAULT 0)');
$db->exec("INSERT IGNORE INTO city_raids (city,active) VALUES ('Wrocław',1),('Szczecin',1),('Kielce',1),('Warszawa',1),('Kraków',1)");
$db->exec('CREATE TABLE IF NOT EXISTS missions (id VARCHAR(64) PRIMARY KEY, title VARCHAR(120) NOT NULL, description TEXT NOT NULL, target INT NOT NULL, location VARCHAR(64) DEFAULT NULL, active TINYINT DEFAULT 1)');
foreach (['reward_cash'=>'BIGINT NOT NULL DEFAULT 0','reward_strength'=>'INT NOT NULL DEFAULT 0','reward_endurance'=>'INT NOT NULL DEFAULT 0','reward_intelligence'=>'INT NOT NULL DEFAULT 0','reward_charisma'=>'INT NOT NULL DEFAULT 0','reward_cunning'=>'INT NOT NULL DEFAULT 0'] as $column=>$type) {
 if (!$db->query("SHOW COLUMNS FROM missions LIKE " . $db->quote($column))->fetch()) $db->exec("ALTER TABLE missions ADD COLUMN $column $type");
}
$columns = $db->query("SHOW COLUMNS FROM missions LIKE 'cash_target'")->fetchAll();
if (!$columns) $db->exec('ALTER TABLE missions ADD COLUMN cash_target BIGINT NULL');
$db->exec('CREATE TABLE IF NOT EXISTS location_locks (location VARCHAR(64) PRIMARY KEY, mission_id VARCHAR(64) DEFAULT NULL, locked TINYINT DEFAULT 1)');
$db->exec("INSERT IGNORE INTO missions (id,title,description,target,location) VALUES ('skwer_200','Pierwsze wpływy','Zdobądź 200 punktów respektu.',200,'skwer')");
$db->exec("INSERT IGNORE INTO location_locks (location,mission_id) VALUES ('skwer','skwer_200')");
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 if (!csrf_valid($_POST['csrf_token'] ?? null)) {
   $message = 'Nieprawidłowy token formularza.';
 } else {
   $action = $_POST['admin_action'] ?? '';
   $id = trim((string) ($_POST['mission_id'] ?? ''));
   $location = (string) ($_POST['location'] ?? '');
   if ($action === 'toggle_raid' && in_array($location, $cityNames, true)) {
     $q = $db->prepare('INSERT INTO city_raids (city,active) VALUES (?,1) ON DUPLICATE KEY UPDATE active=1-active');
     $q->execute([$location]);
     if (isset($_POST['ajax'])) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'active'=>(bool)$db->query('SELECT active FROM city_raids WHERE city='.$db->quote($location))->fetchColumn()]); exit; }
     $message = 'Zmieniono status obławy.';
   } elseif ($action === 'save_mission') {
     $title = trim((string) ($_POST['title'] ?? ''));
     $description = trim((string) ($_POST['description'] ?? ''));
     $respectInput = trim((string) ($_POST['target'] ?? ''));
     $cashInput = trim((string) ($_POST['cash_target'] ?? ''));
     $rewards = [];
     foreach (['reward_cash'=>1000000000000,'reward_strength'=>100000,'reward_endurance'=>100000,'reward_intelligence'=>100000,'reward_charisma'=>100000,'reward_cunning'=>100000] as $field=>$limit) {
       $value = filter_var($_POST[$field] ?? '0', FILTER_VALIDATE_INT);
       $rewards[$field] = $value !== false && $value >= 0 && $value <= $limit ? $value : null;
     }
     $target = $respectInput === '' ? null : filter_var($respectInput, FILTER_VALIDATE_INT);
     $cashTarget = $cashInput === '' ? null : filter_var($cashInput, FILTER_VALIDATE_INT);
     if (in_array(null, $rewards, true) || $title === '' || mb_strlen($title) > 120 || mb_strlen($description) > 2000 || ($target === false || ($target !== null && ($target < 1 || $target > 1000000000))) || ($cashTarget === false || ($cashTarget !== null && ($cashTarget < 1 || $cashTarget > 1000000000000))) || ($target === null && $cashTarget === null) || ($location !== '' && !isset($locations[$location]))) {
       $message = 'Sprawdź dane misji.';
     } else {
       if ($id === '') $id = bin2hex(random_bytes(12));
       $stmt = $db->prepare('INSERT INTO missions (id,title,description,target,cash_target,location,reward_cash,reward_strength,reward_endurance,reward_intelligence,reward_charisma,reward_cunning,active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE title=VALUES(title),description=VALUES(description),target=VALUES(target),cash_target=VALUES(cash_target),location=VALUES(location),reward_cash=VALUES(reward_cash),reward_strength=VALUES(reward_strength),reward_endurance=VALUES(reward_endurance),reward_intelligence=VALUES(reward_intelligence),reward_charisma=VALUES(reward_charisma),reward_cunning=VALUES(reward_cunning)');
       $stmt->execute(array_merge([$id,$title,$description,$target,$cashTarget,$location ?: null],array_values($rewards)));
       $message = 'Misja zapisana.';
     }
   } elseif ($action === 'toggle_mission' && $id !== '') {
     $stmt = $db->prepare('UPDATE missions SET active = IF(active=1,0,1) WHERE id=?');
     $stmt->execute([$id]);
     $message = 'Zmieniono status misji.';
   } elseif ($action === 'save_lock' && isset($locations[$location])) {
     $lockMission = (string) ($_POST['lock_mission'] ?? '');
     $exists = $lockMission === '' || (function () use ($db,$lockMission) { $q=$db->prepare('SELECT id FROM missions WHERE id=?');$q->execute([$lockMission]);return (bool)$q->fetchColumn(); })();
     if ($exists) {
       $stmt = $db->prepare('INSERT INTO location_locks (location,mission_id,locked) VALUES (?,?,?) ON DUPLICATE KEY UPDATE mission_id=VALUES(mission_id),locked=VALUES(locked)');
       $stmt->execute([$location,$lockMission ?: null,isset($_POST['locked']) ? 1 : 0]);
       $message = 'Blokada lokacji zapisana.';
     } else $message = 'Nie znaleziono misji.';
   }
 }
}
$raids = $db->query('SELECT city,active FROM city_raids')->fetchAll(PDO::FETCH_KEY_PAIR);
$missions = $db->query('SELECT * FROM missions ORDER BY id')->fetchAll();
$locks = $db->query('SELECT * FROM location_locks')->fetchAll(PDO::FETCH_UNIQUE);
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
        input,textarea,select { display:block; width:100%; margin:8px 0 14px; padding:11px; background:#111; color:white; border:1px solid #555; border-radius:8px; }
        label {display:block;margin-top:12px} button {padding:11px 16px;cursor:pointer} .item {border:1px solid #444;padding:16px;margin:12px 0;border-radius:10px} .card {margin-bottom:20px}
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
    <?php if ($message !== ''): ?><section class="card"><?= e($message) ?></section><?php endif; ?>
    <section class="card"><h2>Obławy w miastach</h2><p class="muted">Zmiany pojawią się na mapach graczy automatycznie.</p>
    <p id="raidFeedback" aria-live="polite"></p>
    <?php foreach ($cityNames as $city): ?>
      <form method="post" class="raid-form item">
        <strong><?= e($city) ?></strong>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="admin_action" value="toggle_raid">
        <input type="hidden" name="location" value="<?= e($city) ?>">
        <button type="submit"><?= !empty($raids[$city]) ? 'Wyłącz obławę' : 'Włącz obławę' ?></button>
      </form>
    <?php endforeach; ?>
    </section>
    <script>
    document.querySelectorAll('.raid-form').forEach(form => form.addEventListener('submit', async event => {
      event.preventDefault();
      const button = form.querySelector('button');
      button.disabled = true;
      try {
        const body = new FormData(form);
        body.append('ajax', '1');
        const response = await fetch('./?page=admin', {method:'POST', body, credentials:'same-origin'});
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error('Nie udało się zmienić obławy.');
        button.textContent = result.active ? 'Wyłącz obławę' : 'Włącz obławę';
        document.getElementById('raidFeedback').textContent = 'Zapisano zmianę.';
      } catch (error) {
        document.getElementById('raidFeedback').textContent = 'Błąd zapisu. Spróbuj ponownie.';
      } finally { button.disabled = false; }
    }));
    </script>
    <section class="card"><h2>Tworzenie misji</h2>
      <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="admin_action" value="save_mission">
        <label>Tytuł</label><input name="title" maxlength="120" required>
        <label>Opis</label><textarea name="description" maxlength="2000"></textarea>
        <label>Wymagany respekt (opcjonalnie)</label><input type="number" name="target" min="1" max="1000000000" placeholder="Puste = bez wymogu">
        <label>Wymagana gotówka na koncie (opcjonalnie)</label><input type="number" name="cash_target" min="1" max="1000000000000" placeholder="Puste = bez wymogu">
        <label>Lokacja nagrody (opcjonalnie)</label><select name="location"><option value="">Bez odblokowania</option><?php foreach ($locations as $slug=>$name): ?><option value="<?= e($slug) ?>"><?= e($name) ?></option><?php endforeach; ?></select>
<h3>Nagrody (0 = brak)</h3>
<?php foreach (['reward_cash'=>'Gotówka ($)','reward_strength'=>'Siła','reward_endurance'=>'Wytrzymałość','reward_intelligence'=>'Inteligencja','reward_charisma'=>'Charyzma','reward_cunning'=>'Spryt'] as $field=>$label): ?>
<label><?= e($label) ?></label><input type="number" name="<?= e($field) ?>" min="0" max="<?= $field==='reward_cash' ? '1000000000000' : '100000' ?>" value="<?= isset($mission) ? (int)$mission[$field] : 0 ?>">
<?php endforeach; ?>
        <button>Dodaj misję</button>
      </form>
      <h2>Istniejące misje</h2>
      <?php foreach ($missions as $mission): ?>
      <div class="item">
        <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="admin_action" value="save_mission"><input type="hidden" name="mission_id" value="<?= e($mission['id']) ?>">
          <label>Tytuł</label><input name="title" maxlength="120" value="<?= e($mission['title']) ?>" required>
          <label>Opis</label><textarea name="description" maxlength="2000"><?= e($mission['description']) ?></textarea>
          <label>Wymagany respekt (opcjonalnie)</label><input type="number" name="target" min="1" max="1000000000" value="<?= $mission['target'] === null ? '' : (int)$mission['target'] ?>">
          <label>Wymagana gotówka na koncie (opcjonalnie)</label><input type="number" name="cash_target" min="1" max="1000000000000" value="<?= $mission['cash_target'] === null ? '' : (int)$mission['cash_target'] ?>">
          <label>Odblokowanie</label><select name="location"><option value="">Brak</option><?php foreach ($locations as $slug=>$name): ?><option value="<?= e($slug) ?>" <?= $mission['location']===$slug ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?></select>
<h3>Nagrody (0 = brak)</h3>
<?php foreach (['reward_cash'=>'Gotówka ($)','reward_strength'=>'Siła','reward_endurance'=>'Wytrzymałość','reward_intelligence'=>'Inteligencja','reward_charisma'=>'Charyzma','reward_cunning'=>'Spryt'] as $field=>$label): ?>
<label><?= e($label) ?></label><input type="number" name="<?= e($field) ?>" min="0" max="<?= $field==='reward_cash' ? '1000000000000' : '100000' ?>" value="<?= isset($mission) ? (int)$mission[$field] : 0 ?>">
<?php endforeach; ?>
          <button>Zapisz zmiany</button>
        </form>
        <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="admin_action" value="toggle_mission"><input type="hidden" name="mission_id" value="<?= e($mission['id']) ?>"><button><?= $mission['active'] ? 'Wyłącz misję' : 'Włącz misję' ?></button></form>
      </div>
      <?php endforeach; ?>
    </section>
    <section class="card"><h2>Blokady kafelków</h2><p class="muted">Wybierz lokację, włącz blokadę i wskaż misję odblokowującą. Bez misji blokada jest stała.</p>
      <?php foreach ($locations as $slug=>$name): $lock=$locks[$slug] ?? null; ?>
      <form method="post" class="item"><h3><?= e($name) ?></h3><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="admin_action" value="save_lock"><input type="hidden" name="location" value="<?= e($slug) ?>">
        <label><input style="display:inline;width:auto" type="checkbox" name="locked" <?= $lock && $lock['locked'] ? 'checked' : '' ?>> Zablokowana</label>
        <select name="lock_mission"><option value="">Brak misji — blokada stała</option><?php foreach ($missions as $mission): ?><option value="<?= e($mission['id']) ?>" <?= $lock && $lock['mission_id']===$mission['id'] ? 'selected' : '' ?>><?= e($mission['title']) ?></option><?php endforeach; ?></select>
        <button>Zapisz blokadę</button>
      </form>
      <?php endforeach; ?>
    </section>
</div>
</body>
</html>
