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
$gameState = null;
$playerRank = null;
$respectHistory = [];
if ($user && $db instanceof PDO) {
    $gameState = update_game_clock($db);
    $stats = get_player_stats($db, (int) $user['id']);
    $playerRank = get_player_rank($db, (int) $user['id']);
    if (isset($_GET['view']) && $_GET['view'] === 'profile' && $gameState) {
        $respectHistory = get_respect_history($db, (int) $user['id'], (int) $gameState['season']);
    }
    if ($stats && ($stats['profession'] === null || $stats['current_city'] === null)) {
        redirect('./?page=profession');
    }
}
$locationNames = [
    'ulica' => 'Ulica', 'napad' => 'Napad', 'gang' => 'Gang',
    'sabotaz' => 'Sabotaż', 'nocne-zycie' => 'Nocne życie',
    'kasyno' => 'Kasyno', 'handel' => 'Handel', 'skwer' => 'Skwer',
    'czarny-rynek' => 'Czarny rynek', 'szpital' => 'Szpital',
    'wiezienie' => 'Więzienie', 'bank' => 'Bank',
    'policja' => 'Policja', 'detektyw' => 'Detektyw',
    'transport' => 'Transport', 'silownia' => 'Siłownia',
];
$selectedLocation = isset($_GET['location']) && is_string($_GET['location'])
    ? $_GET['location'] : '';
$selectedLocation = array_key_exists($selectedLocation, $locationNames) ? $selectedLocation : '';
$streetMessage = '';
$streetReward = null;
if ($user && $db instanceof PDO && $selectedLocation === 'ulica'
    && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'street_grocery_robbery') {
    if (!csrf_valid(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $streetMessage = 'Sesja wygasła. Odśwież stronę.';
    } else {
        $db->beginTransaction();
        try {
            $lock = $db->prepare('SELECT energy FROM player_stats WHERE user_id=? FOR UPDATE');
            $lock->execute([(int)$user['id']]);
            $currentEnergy = $lock->fetchColumn();
            if ($currentEnergy === false || (int)$currentEnergy < 5) {
                $streetMessage = 'Potrzebujesz co najmniej 5% energii.';
            } else {
                $streetReward = random_int(10,20);
                $rob = $db->prepare('UPDATE player_stats SET energy=energy-5,cash=cash+?,
                    strength=strength+1,endurance=endurance+1,intelligence=intelligence+1,
                    charisma=charisma+1,cunning=cunning+1 WHERE user_id=? AND energy>=5');
                $rob->execute([$streetReward,(int)$user['id']]);
                $streetMessage = 'Rabunek udany! +'.$streetReward.' $ i +1 do każdej statystyki. -5% energii.';
            }
            $db->commit();
            $stats = get_player_stats($db,(int)$user['id']);
        } catch (Throwable $ex) {
            if ($db->inTransaction()) $db->rollBack();
            throw $ex;
        }
    }
}
$showProfile = isset($_GET['view']) && $_GET['view'] === 'profile';
$showMissions = isset($_GET['view']) && $_GET['view'] === 'missions';
$showWanted = isset($_GET['view']) && $_GET['view'] === 'wanted';
$wantedLeader = null;
$wantedKilled = false;
$wantedMessage = '';
$wantedReward = 0;
$wantedRewardPerRespect = 0.5;
if ($showWanted && $user && $db instanceof PDO && $gameState) {
    $db->exec('CREATE TABLE IF NOT EXISTS wanted_bounties (
        season INT NOT NULL,
        game_day INT NOT NULL,
        target_id INT NOT NULL,
        reward BIGINT NOT NULL DEFAULT 10000,
        killed_by INT DEFAULT NULL,
        killed_at DATETIME DEFAULT NULL,
        PRIMARY KEY (season,game_day)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $day=(int)$gameState['game_day'];
    $season=(int)$gameState['season'];
    $break=(int)$gameState['is_break']===1;
    $db->beginTransaction();
    try {
        $existing=$db->prepare('SELECT * FROM wanted_bounties WHERE season=? AND game_day=? FOR UPDATE');
        $existing->execute([$season,$day]);
        $bounty=$existing->fetch();
        if (!$bounty || $bounty['killed_by']===null) {
            if ($break) {
                $leaderSql='SELECT u.id,u.login,(h.respect-COALESCE(prev.respect,100)) AS gained FROM respect_history h
                    JOIN users u ON u.id=h.user_id AND u.active=1
                    LEFT JOIN respect_history prev ON prev.user_id=h.user_id AND prev.season=h.season AND prev.game_day=?
                    WHERE h.season=? AND h.game_day=? ORDER BY gained DESC,u.id ASC LIMIT 1';
                $params=[$day-1,$season,$day];
            } else {
                $leaderSql='SELECT u.id,u.login,(p.respect-COALESCE(prev.respect,100)) AS gained FROM player_stats p
                    JOIN users u ON u.id=p.user_id AND u.active=1
                    LEFT JOIN respect_history prev ON prev.user_id=p.user_id AND prev.season=? AND prev.game_day=?
                    ORDER BY gained DESC,u.id ASC LIMIT 1';
                $params=[$season,$day-1];
            }
            $leaderQuery=$db->prepare($leaderSql);
            $leaderQuery->execute($params);
            $candidate=$leaderQuery->fetch();
            if ($candidate && (int)$candidate['gained']>0) {
                $wantedReward = min(1000000000000, (int)$candidate['gained'] * $wantedRewardPerRespect);
                if (!$bounty) {
                    $create=$db->prepare('INSERT INTO wanted_bounties (season,game_day,target_id,reward) VALUES (?,?,?,?)');
                    $create->execute([$season,$day,(int)$candidate['id'],$wantedReward]);
                } else {
                    $change=$db->prepare('UPDATE wanted_bounties SET target_id=?,reward=? WHERE season=? AND game_day=? AND killed_by IS NULL');
                    $change->execute([(int)$candidate['id'],$wantedReward,$season,$day]);
                }
                $wantedLeader=$candidate;
            }
        } else {
            $dead=$db->prepare('SELECT id,login FROM users WHERE id=?');
            $dead->execute([(int)$bounty['target_id']]);
            $wantedLeader=$dead->fetch();
            $wantedReward=(int)$bounty['reward'];
            $wantedKilled=true;
        }
        if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='wanted_kill') {
            if (!csrf_valid(is_string($_POST['csrf_token']??null)?$_POST['csrf_token']:null)) {
                $wantedMessage='Sesja wygasła. Odśwież stronę.';
            } elseif ($break || !$wantedLeader || $wantedKilled || (int)$wantedLeader['id']===(int)$user['id']) {
                $wantedMessage='Tego celu nie można teraz zaatakować.';
            } elseif ((int)($_POST['target_id']??0)!==(int)$wantedLeader['id']) {
                $wantedMessage='Cel WANTED zmienił się. Odśwież stronę.';
            } else {
                $claim=$db->prepare('UPDATE wanted_bounties SET killed_by=?,killed_at=NOW() WHERE season=? AND game_day=? AND target_id=? AND killed_by IS NULL');
                $claim->execute([(int)$user['id'],$season,$day,(int)$wantedLeader['id']]);
                if ($claim->rowCount()===1) {
                    $rewardStmt=$db->prepare('SELECT reward FROM wanted_bounties WHERE season=? AND game_day=?');
                    $rewardStmt->execute([$season,$day]);
                    $wantedReward=(int)$rewardStmt->fetchColumn();
                    $pay=$db->prepare('UPDATE player_stats SET cash=cash+? WHERE user_id=?');
                    $pay->execute([$wantedReward,(int)$user['id']]);
                    $wantedKilled=true;
                    $wantedMessage='Poszukiwany zabity! Otrzymujesz '.number_format($wantedReward,0,'.',' ').' $.';
                    $stats=get_player_stats($db,(int)$user['id']);
                } else $wantedMessage='Nagroda została już odebrana.';
            }
        }
        $db->commit();
    } catch (Throwable $ex) {
        if ($db->inTransaction()) $db->rollBack();
        throw $ex;
    }
}
$guestbookOwner = null;
$guestbookEntries = [];
$guestbookPage = 1;
$guestbookPages = 1;
$guestbookMessage = '';
if ($user && $db instanceof PDO && $showProfile) {
    $db->exec('CREATE TABLE IF NOT EXISTS guestbook_entries (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        owner_id INT NOT NULL,
        author_id INT NOT NULL,
        body VARCHAR(300) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX guestbook_owner_date (owner_id, created_at, id),
        INDEX guestbook_author_date (author_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $requestedId = filter_var($_GET['player'] ?? null, FILTER_VALIDATE_INT);
    if (isset($_GET['login']) && is_string($_GET['login']) && $_GET['login'] !== '') {
        $lookup = $db->prepare('SELECT id FROM users WHERE login=? AND active=1 LIMIT 1');
        $lookup->execute([trim($_GET['login'])]);
        $requestedId = $lookup->fetchColumn() ?: -1;
    }
    $ownerId = $requestedId && $requestedId !== -1 ? $requestedId : ($requestedId === -1 ? -1 : (int)$user['id']);
    $ownerStmt = $db->prepare('SELECT id, login FROM users WHERE id=? AND active=1');
    $ownerStmt->execute([$ownerId]);
    $guestbookOwner = $ownerStmt->fetch();
    if ($guestbookOwner) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['guestbook_add','guestbook_delete'], true)) {
            if (!csrf_valid(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
                $guestbookMessage = 'Sesja wygasła. Odśwież stronę.';
            } elseif ($_POST['action'] === 'guestbook_add' && (int)$guestbookOwner['id'] === (int)$user['id']) {
                $guestbookMessage = 'Nie możesz wpisywać się do własnej księgi gości.';
            } elseif ($_POST['action'] === 'guestbook_add') {
                $body = trim((string)($_POST['body'] ?? ''));
                if ($body === '' || mb_strlen($body) > 300) {
                    $guestbookMessage = 'Komentarz musi mieć od 1 do 300 znaków.';
                } else {
                    $recent = $db->prepare('SELECT created_at FROM guestbook_entries WHERE author_id=? ORDER BY created_at DESC,id DESC LIMIT 1');
                    $recent->execute([(int)$user['id']]);
                    $last = $recent->fetchColumn();
                    if ($last && strtotime($last) > time()-30) {
                        $guestbookMessage = 'Odczekaj 30 sekund przed kolejnym komentarzem.';
                    } else {
                        $insert = $db->prepare('INSERT INTO guestbook_entries (owner_id,author_id,body) VALUES (?,?,?)');
                        $insert->execute([(int)$guestbookOwner['id'],(int)$user['id'],$body]);
                        redirect('./?page=main&view=profile&player='.(int)$guestbookOwner['id'].'#guestbook');
                    }
                }
            } elseif ((int)$guestbookOwner['id'] === (int)$user['id']) {
                $entryId = filter_var($_POST['entry_id'] ?? null,FILTER_VALIDATE_INT);
                if ($entryId && $entryId > 0) {
                    $delete = $db->prepare('DELETE FROM guestbook_entries WHERE id=? AND owner_id=?');
                    $delete->execute([$entryId,(int)$user['id']]);
                    redirect('./?page=main&view=profile&player='.(int)$user['id'].'#guestbook');
                }
            }
        }
        $countStmt = $db->prepare('SELECT COUNT(*) FROM guestbook_entries WHERE owner_id=?');
        $countStmt->execute([(int)$guestbookOwner['id']]);
        $total = (int)$countStmt->fetchColumn();
        $guestbookPages = max(1,(int)ceil($total/10));
        $requestedPage = filter_var($_GET['gb_page'] ?? 1,FILTER_VALIDATE_INT);
        $guestbookPage = min($guestbookPages,max(1,$requestedPage ?: 1));
        $offset = ($guestbookPage-1)*10;
        $entriesStmt = $db->prepare('SELECT g.id,g.author_id,g.body,g.created_at,u.login FROM guestbook_entries g JOIN users u ON u.id=g.author_id WHERE g.owner_id=? ORDER BY g.created_at DESC,g.id DESC LIMIT 10 OFFSET '.$offset);
        $entriesStmt->execute([(int)$guestbookOwner['id']]);
        $guestbookEntries = $entriesStmt->fetchAll();
    }
}
$missions = [];
if ($user && $db instanceof PDO) {
 $db->exec('CREATE TABLE IF NOT EXISTS missions (id VARCHAR(64) PRIMARY KEY,title VARCHAR(120) NOT NULL,description TEXT NOT NULL,target INT NULL,location VARCHAR(64) DEFAULT NULL,active TINYINT DEFAULT 1)');
 foreach (['reward_cash'=>'BIGINT NOT NULL DEFAULT 0','reward_strength'=>'INT NOT NULL DEFAULT 0','reward_endurance'=>'INT NOT NULL DEFAULT 0','reward_intelligence'=>'INT NOT NULL DEFAULT 0','reward_charisma'=>'INT NOT NULL DEFAULT 0','reward_cunning'=>'INT NOT NULL DEFAULT 0'] as $column=>$type) {
  if (!$db->query("SHOW COLUMNS FROM missions LIKE " . $db->quote($column))->fetch()) $db->exec("ALTER TABLE missions ADD COLUMN $column $type");
 }
 $cashColumn = $db->query("SHOW COLUMNS FROM missions LIKE 'cash_target'")->fetch();
 if (!$cashColumn) $db->exec('ALTER TABLE missions ADD COLUMN cash_target BIGINT NULL');
 $db->exec('CREATE TABLE IF NOT EXISTS location_locks (location VARCHAR(64) PRIMARY KEY,mission_id VARCHAR(64) DEFAULT NULL,locked TINYINT DEFAULT 1)');
 $db->exec("INSERT IGNORE INTO missions (id,title,description,target,location) VALUES ('skwer_200','Pierwsze wpływy','Zdobądź 200 punktów respektu.',200,'skwer')");
 $db->exec("INSERT IGNORE INTO location_locks (location,mission_id) VALUES ('skwer','skwer_200')");
 $missions = $db->query('SELECT * FROM missions WHERE active=1 ORDER BY id')->fetchAll();
}
$completedMissions = [];
$missionMessage = '';
if ($user && $db instanceof PDO) {
    $db->exec('CREATE TABLE IF NOT EXISTS player_missions (
        user_id INT NOT NULL, mission_id VARCHAR(64) NOT NULL,
        completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, mission_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $missionQuery = $db->prepare('SELECT mission_id FROM player_missions WHERE user_id = ?');
    $missionQuery->execute([(int) $user['id']]);
    $completedMissions = array_fill_keys($missionQuery->fetchAll(PDO::FETCH_COLUMN), true);
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'claim_mission') {
        if (!csrf_valid(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
            $missionMessage = 'Sesja wygasła. Odśwież stronę.';
        } else {
            $missionId = is_string($_POST['mission_id'] ?? null) ? $_POST['mission_id'] : '';
            foreach ($missions as $mission) {
                if ($mission['id'] === $missionId && !isset($completedMissions[$missionId])) {
                    // Claim and award in one transaction, locked per player to prevent duplicate rewards.
                    $db->beginTransaction();
                    try {
                        $lock = $db->prepare('SELECT * FROM player_stats WHERE user_id=? FOR UPDATE');
                        $lock->execute([(int)$user['id']]);
                        $freshStats = $lock->fetch();
                        $already = $db->prepare('SELECT 1 FROM player_missions WHERE user_id=? AND mission_id=?');
                        $already->execute([(int)$user['id'],$missionId]);
                        if ($freshStats && !$already->fetchColumn()
                            && ($mission['target'] === null || (int)$freshStats['respect'] >= (int)$mission['target'])
                            && ($mission['cash_target'] === null || (int)$freshStats['cash'] >= (int)$mission['cash_target'])) {
                            $claim = $db->prepare('INSERT INTO player_missions (user_id,mission_id) VALUES (?,?)');
                            $claim->execute([(int)$user['id'],$missionId]);
                            $award = $db->prepare('UPDATE player_stats SET cash=cash+?,strength=strength+?,endurance=endurance+?,intelligence=intelligence+?,charisma=charisma+?,cunning=cunning+? WHERE user_id=?');
                            $award->execute([(int)$mission['reward_cash'],(int)$mission['reward_strength'],(int)$mission['reward_endurance'],(int)$mission['reward_intelligence'],(int)$mission['reward_charisma'],(int)$mission['reward_cunning'],(int)$user['id']]);
                            $completedMissions[$missionId] = true;
                            $stats = get_player_stats($db,(int)$user['id']);
                            $missionMessage = 'Misja ukończona! Nagrody odebrane.';
                        } else {
                            $missionMessage = 'Misja została już odebrana lub nie spełniasz wymagań.';
                        }
                        $db->commit();
                    } catch (Throwable $ex) {
                        if ($db->inTransaction()) $db->rollBack();
                        throw $ex;
                    }
                    break;
                }
            }
        }
    }
}
$lockedLocations = [];
if ($user && $db instanceof PDO) {
 $locks = $db->query('SELECT l.location,l.mission_id,m.title FROM location_locks l LEFT JOIN missions m ON m.id=l.mission_id AND m.active=1 WHERE l.locked=1')->fetchAll();
 foreach ($locks as $lock) {
   if ($lock['mission_id'] === null || $lock['title'] === null || !isset($completedMissions[$lock['mission_id']])) {
     $lockedLocations[$lock['location']] = $lock['title'] ?? 'Blokada administratora';
   }
 }
}
if ($selectedLocation !== '' && isset($lockedLocations[$selectedLocation])) {
    $selectedLocation = '';
    $showMissions = true;
    $missionMessage = 'Najpierw ukończ misję, aby odblokować tę lokację.';
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
        .topbar { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 18px; }
        .game-nav { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 24px; padding: 12px; border: 1px solid #333; border-radius: 12px; background: #181818; }
        .game-nav a { padding: 10px 13px; border-radius: 8px; background: #242424; color: #fff; text-decoration: none; font-weight: 700; }
        .game-nav a:hover { background: #333; }
        .location-button.locked { pointer-events: none; cursor: not-allowed; opacity: .5; }
        .menu-toggle { display: none; width: 46px; height: 42px; padding: 8px; margin: 0; background: #242424; color: #fff; }
        .menu-toggle span { display: block; height: 3px; margin: 4px 0; background: currentColor; border-radius: 2px; }
        .game-state { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-bottom: 24px; }
        .game-state .stat { text-align: center; }
        .location-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 24px; }
        .location-button { min-height: 92px; display: flex; align-items: center; justify-content: center; padding: 14px; border: 1px solid #3b3b3b; border-radius: 12px; background: #181818; color: #fff; text-decoration: none; text-align: center; font-weight: 700; }
        .location-button:hover { background: #242424; border-color: #555; }
        .location-panel { min-height: 240px; margin-bottom: 24px; }
        .location-panel .back-button { margin: 0 0 22px; }
        .respect-chart { width: 100%; overflow-x: auto; padding: 12px 0; }
        .respect-chart svg { display: block; min-width: 340px; width: 100%; height: auto; }
        .history-table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        .history-table th, .history-table td { text-align: left; padding: 10px; border-bottom: 1px solid #333; }
        .mission { padding: 18px; border: 1px solid #383838; border-radius: 12px; background: #111; margin-top: 14px; }
        .mission progress { width: 100%; height: 16px; accent-color: #48d597; }
        .location-button.locked { opacity: .5; border-style: dashed; }
        .stats { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .stat { padding: 14px; background: #111; border: 1px solid #333; border-radius: 10px; }
        .stat span { display: block; color: #aaa; font-size: 13px; margin-bottom: 5px; }
        .stat strong { font-size: 18px; }
        @media (max-width: 720px) {
            .auth-grid, .stats { grid-template-columns: 1fr; }
            .game-state { grid-template-columns: 1fr; }
            .location-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .topbar { align-items: flex-start; flex-direction: column; }
            .button.secondary { margin-left: 0; }
            .menu-toggle { display: block; }
            .game-nav { display: none; flex-direction: column; width: 100%; }
            .game-nav.open { display: flex; }
            .game-nav a { width: 100%; }
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

    <button class="menu-toggle" id="menuToggle" type="button" aria-label="Otwórz menu" aria-expanded="false">
        <span></span><span></span><span></span>
    </button>
    <nav class="game-nav" id="gameNav">
        <a href="./?page=main&view=profile">Twój profil</a>
        <a href="./?page=main&view=missions">Misje</a>
        <a href="#">Kontakty</a>
        <a href="./?page=main&amp;view=wanted">Wanted</a>
        <a href="#">Podróż</a>
        <a href="#">Rynek</a>
    </nav>

    <?php if ($gameState): ?>
    <section class="game-state" aria-label="Stan gry">
        <div class="stat"><span>Dzień gry</span><strong><?= (int) $gameState['game_day'] ?>/60</strong></div>
        <div class="stat"><span>Sezon gry</span><strong><?= (int) $gameState['season'] ?></strong></div>
        <div class="stat"><span><?= (int) ($gameState['is_break'] ?? 0) === 1 ? 'Start następnego sezonu' : 'Następna aktualizacja rankingu' ?></span><strong id="rankingCountdown" data-next="<?= e($gameState['next_ranking_update']) ?>">--:--:--</strong></div>
    </section>
    <?php endif; ?>

    <?php if ($showProfile): ?>
    <section class="card location-panel">
        <a class="button secondary back-button" href="./?page=main">← Powrót do menu</a>
        <h2><?= $guestbookOwner ? 'Profil: '.e($guestbookOwner['login']) : 'Profil gracza' ?></h2>
        <?php if ($guestbookOwner && (int)$guestbookOwner['id'] === (int)$user['id'] && $stats): ?>
        <div class="stats">
            <div class="stat"><span>Login</span><strong><?= e($user['login'] ?? '') ?></strong></div>
            <div class="stat"><span>Miejsce w rankingu</span><strong>#<?= (int) ($playerRank ?? 0) ?></strong></div>
            <div class="stat"><span>Respekt</span><strong><?= (int) $stats['respect'] ?> pkt</strong></div>
            <div class="stat"><span>Profesja</span><strong><?= e($stats['profession'] ?? 'Nie wybrano') ?></strong></div>
            <div class="stat"><span>Miasto</span><strong><?= e($stats['current_city'] ?? 'Nie wybrano') ?></strong></div>
            <div class="stat"><span>Kasa</span><strong><?= number_format((int) $stats['cash'], 0, '.', ',') ?> $</strong></div>
            <div class="stat"><span>Energia</span><strong><?= (int) $stats['energy'] ?>%</strong></div>
            <div class="stat"><span>Bilety</span><strong><?= (int) $stats['tickets'] ?>/25</strong></div>
            <div class="stat"><span>Siła</span><strong><?= (int) $stats['strength'] ?></strong></div>
            <div class="stat"><span>Wytrzymałość</span><strong><?= (int) $stats['endurance'] ?></strong></div>
            <div class="stat"><span>Inteligencja</span><strong><?= (int) $stats['intelligence'] ?></strong></div>
            <div class="stat"><span>Charyzma</span><strong><?= (int) $stats['charisma'] ?></strong></div>
            <div class="stat"><span>Spryt</span><strong><?= (int) $stats['cunning'] ?></strong></div>
            <div class="stat"><span>Kredyty</span><strong><?= number_format((int) ($stats['credits'] ?? 0), 0, '.', ',') ?></strong></div>
        </div>
        <?php endif; ?>
        <?php if ($guestbookOwner && (int)$guestbookOwner['id'] === (int)$user['id']): ?>
        <h2 style="margin-top:28px">Historia respektu — sezon <?= (int) ($gameState['season'] ?? 1) ?></h2>
        <p class="muted">Wynik zapisywany na koniec każdego dnia gry (co 4 godziny).</p>
        <?php if ($respectHistory): ?>
        <?php
            $values = array_map(static fn($item) => (int) $item['respect'], $respectHistory);
            $low = min($values); $high = max($values);
            $range = max(1, $high - $low);
            $count = count($respectHistory);
            $chartWidth = max(340, $count * 36);
            $points = [];
            foreach ($respectHistory as $i => $item) {
                $x = 28 + ($count === 1 ? 0 : $i * ($chartWidth - 56) / ($count - 1));
                $y = 160 - ((int) $item['respect'] - $low) / $range * 120;
                $points[] = round($x, 2) . ',' . round($y, 2);
            }
        ?>
        <div class="respect-chart">
            <svg viewBox="0 0 <?= $chartWidth ?> 200" style="width:<?= $chartWidth ?>px" role="img" aria-label="Wykres historii respektu">
                <line x1="28" y1="160" x2="<?= $chartWidth - 20 ?>" y2="160" stroke="#555"/>
                <polyline points="<?= e(implode(' ', $points)) ?>" fill="none" stroke="#48d597" stroke-width="3" stroke-linejoin="round"/>
                <?php foreach ($respectHistory as $i => $item):
                    $x = 28 + ($count === 1 ? 0 : $i * ($chartWidth - 56) / ($count - 1));
                    $y = 160 - ((int) $item['respect'] - $low) / $range * 120;
                ?>
                <circle cx="<?= round($x, 2) ?>" cy="<?= round($y, 2) ?>" r="4" fill="#48d597"><title>Dzień <?= (int) $item['game_day'] ?>: <?= (int) $item['respect'] ?> pkt</title></circle>
                <text x="<?= round($x, 2) ?>" y="184" font-size="11" fill="#aaa" text-anchor="middle"><?= (int) $item['game_day'] ?></text>
                <?php endforeach; ?>
            </svg>
        </div>
        <table class="history-table"><thead><tr><th>Dzień gry</th><th>Respekt</th><th>Zmiana</th></tr></thead><tbody>
        <?php $previous = null; foreach ($respectHistory as $entry): ?>
            <tr><td><?= (int) $entry['game_day'] ?></td><td><?= number_format((int) $entry['respect'], 0, '.', ' ') ?></td>
            <td><?= $previous === null ? '—' : sprintf('%+d', (int) $entry['respect'] - $previous) ?></td></tr>
        <?php $previous = (int) $entry['respect']; endforeach; ?>
        </tbody></table>
        <?php else: ?>
            <p class="muted">Pierwszy zapis pojawi się po zakończeniu bieżącego dnia gry.</p>
        <?php endif; ?>
        <?php endif; ?>
        <?php if ($guestbookOwner): ?>
        <div id="guestbook" style="margin-top:32px">
          <h2>Księga gości</h2>
          <?php if ($guestbookMessage !== ''): ?><p><?= e($guestbookMessage) ?></p><?php endif; ?>
          <?php if ((int)$guestbookOwner['id'] !== (int)$user['id']): ?>
          <form method="post" action="./?page=main&amp;view=profile&amp;player=<?= (int)$guestbookOwner['id'] ?>#guestbook">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="guestbook_add">
            <label for="guestbook-body">Dodaj wpis (maksymalnie 300 znaków)</label>
            <textarea id="guestbook-body" name="body" maxlength="300" required rows="3" style="width:100%;background:#111;color:white;padding:12px;border:1px solid #555;border-radius:8px"></textarea>
            <button type="submit">Dodaj komentarz</button>
          </form>
          <?php endif; ?>
          <?php foreach ($guestbookEntries as $entry): ?>
            <div class="mission">
              <strong><a style="color:inherit" href="./?page=main&amp;view=profile&amp;player=<?= (int)$entry['author_id'] ?>#guestbook"><?= e($entry['login']) ?></a></strong>
              <span class="muted"><?= e($entry['created_at']) ?></span>
              <p style="white-space:pre-wrap;overflow-wrap:anywhere"><?= e($entry['body']) ?></p>
              <?php if ((int)$guestbookOwner['id'] === (int)$user['id']): ?>
              <form method="post" action="./?page=main&amp;view=profile#guestbook">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="guestbook_delete">
                <input type="hidden" name="entry_id" value="<?= (int)$entry['id'] ?>">
                <button type="submit">Usuń wpis</button>
              </form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          <?php if (!$guestbookEntries): ?><p class="muted">Brak wpisów. Dodaj pierwszy komentarz.</p><?php endif; ?>
          <?php if ($guestbookPages > 1): ?>
            <nav aria-label="Strony księgi gości" style="margin-top:16px">
              <?php for ($pageNo=1;$pageNo<=$guestbookPages;$pageNo++): ?>
                <a class="button <?= $pageNo===$guestbookPage ? '' : 'secondary' ?>" href="./?page=main&amp;view=profile&amp;player=<?= (int)$guestbookOwner['id'] ?>&amp;gb_page=<?= $pageNo ?>#guestbook"><?= $pageNo ?></a>
              <?php endfor; ?>
            </nav>
          <?php endif; ?>
        </div>
        <?php endif; ?>
    </section>
    <?php elseif ($showWanted): ?>
    <section class="card location-panel">
      <a class="button secondary back-button" href="./?page=main">← Powrót do menu</a>
      <h2>WANTED — dzień <?= (int)$gameState['game_day'] ?></h2>
      <p class="muted">Cel: gracz z największym przyrostem respektu w bieżącym dniu gry.</p>
      <?php if ($wantedMessage !== ''): ?><p class="stat"><?= e($wantedMessage) ?></p><?php endif; ?>
      <?php if ($wantedKilled && $wantedLeader): ?>
        <div class="stat"><strong>POSZUKIWANY ZABITY</strong><span><?= e($wantedLeader['login']) ?></span><span>Nagroda odebrana — dzisiaj nie ma kolejnego celu.</span></div>
      <?php elseif ($wantedLeader): ?>
        <div class="stat">
          <strong><a href="./?page=main&amp;view=profile&amp;player=<?= (int)$wantedLeader['id'] ?>"><?= e($wantedLeader['login']) ?></a></strong>
          <span>Respekt zdobyty dzisiaj: +<?= number_format((int)$wantedLeader['gained'],0,'.',' ') ?></span>
          <strong>Nagroda: <?= number_format($wantedReward,0,'.',' ') ?> $</strong>
        </div>
        <?php if ((int)$wantedLeader['id']===(int)$user['id']): ?>
          <p class="muted">Nie możesz zaatakować samego siebie.</p>
        <?php elseif ((int)$gameState['is_break']===1): ?>
          <p class="muted">Podczas przerwy między sezonami ataki są wyłączone.</p>
        <?php else: ?>
          <form method="post" action="./?page=main&amp;view=wanted">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="wanted_kill">
            <input type="hidden" name="target_id" value="<?= (int)$wantedLeader['id'] ?>">
            <button type="submit">Zabij poszukiwanego i odbierz nagrodę</button>
          </form>
        <?php endif; ?>
      <?php else: ?>
        <p class="muted">Nikt jeszcze nie zdobył respektu w tym dniu.</p>
      <?php endif; ?>
    </section>
    <?php elseif ($showMissions): ?>
    <section class="card location-panel">
        <a class="button secondary back-button" href="./?page=main">← Powrót do menu</a>
        <h2>Misje</h2>
        <p class="muted">Wykonuj zadania, odbieraj nagrody i odblokowuj nowe lokacje.</p>
        <?php if ($missionMessage !== ''): ?><p class="stat"><?= e($missionMessage) ?></p><?php endif; ?>
        <?php foreach ($missions as $mission):
            $done = isset($completedMissions[$mission['id']]);
            $respectOk = $mission['target'] === null || (int) ($stats['respect'] ?? 0) >= (int) $mission['target'];
            $cashOk = $mission['cash_target'] === null || (int) ($stats['cash'] ?? 0) >= (int) $mission['cash_target'];
        ?>
        <article class="mission">
            <h3><?= e($mission['title']) ?> <?= $done ? '✓' : '' ?></h3>
            <p><?= e($mission['description']) ?></p>
            <p class="muted">Nagrody:
            <?php
            $rewards = [];
            if ($mission['location']) $rewards[] = 'Odblokowanie: ' . ($locationNames[$mission['location']] ?? $mission['location']);
            if ((int)$mission['reward_cash'] > 0) $rewards[] = '+' . (int)$mission['reward_cash'] . ' USD';
            if ((int)$mission['reward_strength'] > 0) $rewards[] = '+' . (int)$mission['reward_strength'] . ' siły';
            if ((int)$mission['reward_endurance'] > 0) $rewards[] = '+' . (int)$mission['reward_endurance'] . ' wytrzymałości';
            if ((int)$mission['reward_intelligence'] > 0) $rewards[] = '+' . (int)$mission['reward_intelligence'] . ' inteligencji';
            if ((int)$mission['reward_charisma'] > 0) $rewards[] = '+' . (int)$mission['reward_charisma'] . ' charyzmy';
            if ((int)$mission['reward_cunning'] > 0) $rewards[] = '+' . (int)$mission['reward_cunning'] . ' sprytu';
            echo e($rewards ? implode(', ', $rewards) : 'Ukończenie misji');
            ?>
            </p>
            <?php if ($mission['target'] !== null): ?>
            <p>Respekt: <?= min((int)($stats['respect'] ?? 0), (int)$mission['target']) ?> / <?= (int)$mission['target'] ?></p>
            <progress value="<?= min((int)($stats['respect'] ?? 0), (int)$mission['target']) ?>" max="<?= (int)$mission['target'] ?>"></progress>
            <?php endif; ?>
            <?php if ($mission['cash_target'] !== null): ?>
            <p>Gotówka na koncie: <?= number_format(min((int)($stats['cash'] ?? 0), (int)$mission['cash_target']), 0, '.', ' ') ?> / <?= number_format((int)$mission['cash_target'], 0, '.', ' ') ?> $</p>
            <progress value="<?= min((int)($stats['cash'] ?? 0), (int)$mission['cash_target']) ?>" max="<?= (int)$mission['cash_target'] ?>"></progress>
            <?php endif; ?>
            <?php if ($done): ?>
                <strong>Ukończona — nagroda odebrana</strong>
            <?php elseif ($respectOk && $cashOk): ?>
                <form method="post" action="./?page=main&amp;view=missions">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="claim_mission">
                    <input type="hidden" name="mission_id" value="<?= e($mission['id']) ?>">
                    <button type="submit">Odbierz nagrodę</button>
                </form>
            <?php else: ?>
                <p class="muted">W trakcie</p>
            <?php endif; ?>
        </article>
        <?php endforeach; ?>
    </section>
    <?php elseif ($selectedLocation === ''): ?>
    <section class="location-grid" aria-label="Lokacje gry">
        <a class="location-button <?= isset($lockedLocations['ulica']) ? 'locked' : '' ?>" href="<?= isset($lockedLocations['ulica']) ? '#' : './?page=main&amp;location=ulica' ?>"><?= isset($lockedLocations['ulica']) ? '🔒 ' : '' ?>Ulica</a>
        <a class="location-button <?= isset($lockedLocations['napad']) ? 'locked' : '' ?>" href="<?= isset($lockedLocations['napad']) ? '#' : './?page=main&amp;location=napad' ?>"><?= isset($lockedLocations['napad']) ? '🔒 ' : '' ?>Napad</a>
        <a class="location-button <?= isset($lockedLocations['gang']) ? 'locked' : '' ?>" href="<?= isset($lockedLocations['gang']) ? '#' : './?page=main&amp;location=gang' ?>"><?= isset($lockedLocations['gang']) ? '🔒 ' : '' ?>Gang</a>
        <a class="location-button <?= isset($lockedLocations['sabotaz']) ? 'locked' : '' ?>" href="<?= isset($lockedLocations['sabotaz']) ? '#' : './?page=main&amp;location=sabotaz' ?>"><?= isset($lockedLocations['sabotaz']) ? '🔒 ' : '' ?>Sabotaż</a>

        <a class="location-button <?= isset($lockedLocations['nocne-zycie']) ? 'locked' : '' ?>" href="<?= isset($lockedLocations['nocne-zycie']) ? '#' : './?page=main&amp;location=nocne-zycie' ?>"><?= isset($lockedLocations['nocne-zycie']) ? '🔒 ' : '' ?>Nocne życie</a>
        <a class="location-button <?= isset($lockedLocations['kasyno']) ? 'locked' : '' ?>" href="<?= isset($lockedLocations['kasyno']) ? '#' : './?page=main&amp;location=kasyno' ?>"><?= isset($lockedLocations['kasyno']) ? '🔒 ' : '' ?>Kasyno</a>
        <a class="location-button <?= isset($lockedLocations['handel']) ? 'locked' : '' ?>" href="<?= isset($lockedLocations['handel']) ? '#' : './?page=main&amp;location=handel' ?>"><?= isset($lockedLocations['handel']) ? '🔒 ' : '' ?>Handel</a>
        <a class="location-button <?= isset($lockedLocations['skwer']) ? 'locked' : '' ?>" href="<?= isset($lockedLocations['skwer']) ? '#' : './?page=main&amp;location=skwer' ?>"><?= isset($lockedLocations['skwer']) ? '🔒 ' : '' ?>Skwer</a>

        <a class="location-button <?= isset($lockedLocations['czarny-rynek']) ? 'locked' : '' ?>" href="<?= isset($lockedLocations['czarny-rynek']) ? '#' : './?page=main&amp;location=czarny-rynek' ?>"><?= isset($lockedLocations['czarny-rynek']) ? '🔒 ' : '' ?>Czarny rynek</a>
        <a class="location-button <?= isset($lockedLocations['szpital']) ? 'locked' : '' ?>" href="<?= isset($lockedLocations['szpital']) ? '#' : './?page=main&amp;location=szpital' ?>"><?= isset($lockedLocations['szpital']) ? '🔒 ' : '' ?>Szpital</a>
        <a class="location-button <?= isset($lockedLocations['wiezienie']) ? 'locked' : '' ?>" href="<?= isset($lockedLocations['wiezienie']) ? '#' : './?page=main&amp;location=wiezienie' ?>"><?= isset($lockedLocations['wiezienie']) ? '🔒 ' : '' ?>Więzienie</a>
        <a class="location-button <?= isset($lockedLocations['bank']) ? 'locked' : '' ?>" href="<?= isset($lockedLocations['bank']) ? '#' : './?page=main&amp;location=bank' ?>"><?= isset($lockedLocations['bank']) ? '🔒 ' : '' ?>Bank</a>

        <a class="location-button <?= isset($lockedLocations['policja']) ? 'locked' : '' ?>" href="<?= isset($lockedLocations['policja']) ? '#' : './?page=main&amp;location=policja' ?>"><?= isset($lockedLocations['policja']) ? '🔒 ' : '' ?>Policja</a>
        <a class="location-button <?= isset($lockedLocations['detektyw']) ? 'locked' : '' ?>" href="<?= isset($lockedLocations['detektyw']) ? '#' : './?page=main&amp;location=detektyw' ?>"><?= isset($lockedLocations['detektyw']) ? '🔒 ' : '' ?>Detektyw</a>
        <a class="location-button <?= isset($lockedLocations['transport']) ? 'locked' : '' ?>" href="<?= isset($lockedLocations['transport']) ? '#' : './?page=main&amp;location=transport' ?>"><?= isset($lockedLocations['transport']) ? '🔒 ' : '' ?>Transport</a>
        <a class="location-button <?= isset($lockedLocations['silownia']) ? 'locked' : '' ?>" href="<?= isset($lockedLocations['silownia']) ? '#' : './?page=main&amp;location=silownia' ?>"><?= isset($lockedLocations['silownia']) ? '🔒 ' : '' ?>Siłownia</a>
    </section>
    <?php else: ?>
    <section class="card location-panel" aria-label="Wybrana lokacja">
        <a class="button secondary back-button" href="./?page=main">← Powrót do menu</a>
        <h2><?= e($locationNames[$selectedLocation]) ?></h2>
        <?php if ($selectedLocation === 'ulica'): ?>
          <h3>Rabunek na spożywczak</h3>
          <p class="muted">Koszt: 5% energii · Szansa wpadki: 0% · Nagroda: 10–20 $ i +1 do każdej statystyki. Bez limitu prób, dopóki masz energię.</p>
          <?php if ($streetMessage !== ''): ?><p class="stat"><?= e($streetMessage) ?></p><?php endif; ?>
          <form method="post" action="./?page=main&amp;location=ulica">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="street_grocery_robbery">
            <button type="submit" <?= !$stats || (int)$stats['energy'] < 5 ? 'disabled' : '' ?>>Napadnij na spożywczak</button>
          </form>
        <?php else: ?>
          <p class="muted">Tutaj pojawią się informacje i dostępne akcje tej lokacji.</p>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if (!$showProfile && !$showMissions): ?>
    <section class="card">
        <h2>Statystyki postaci</h2>
        <?php if ($stats): ?>
            <div class="stats">
                <div class="stat"><span>Kasa</span><strong><?= number_format((int) $stats['cash'], 0, '.', ',') ?> $</strong></div>
        <div class="stat"><span>Kredyty</span><strong><?= number_format((int) ($stats['credits'] ?? 0), 0, '.', ',') ?></strong></div>
                <div class="stat"><span>Respekt</span><strong><?= (int) $stats['respect'] ?> pkt</strong></div>
                <div class="stat"><span>Miejsce</span><strong>#<?= (int) ($playerRank ?? 0) ?></strong></div>
                <div class="stat"><span>Profesja</span><strong><?= $stats['profession'] === null ? 'Nie wybrano' : e($stats['profession']) ?></strong></div>
                <div class="stat"><span>Obecne miasto</span><strong><?= $stats['current_city'] === null ? 'Nie wybrano' : e($stats['current_city']) ?></strong></div>
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
    <?php endif; ?>
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
<?php if ($user): ?>
<script>
const menuToggle = document.getElementById('menuToggle');
const gameNav = document.getElementById('gameNav');
const rankingCountdown = document.getElementById('rankingCountdown');
if (rankingCountdown) {
    const next = new Date(rankingCountdown.dataset.next.replace(' ', 'T')).getTime();
    const tick = () => {
        const diff = Math.max(0, next - Date.now());
        const hours = Math.floor(diff / 3600000);
        const minutes = Math.floor((diff % 3600000) / 60000);
        const seconds = Math.floor((diff % 60000) / 1000);
        rankingCountdown.textContent = String(hours).padStart(2, '0') + ':' + String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
        if (diff <= 0) location.reload();
    };
    tick();
    setInterval(tick, 1000);
}
if (menuToggle && gameNav) {
    menuToggle.addEventListener('click', () => {
        const open = gameNav.classList.toggle('open');
        menuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
}
</script>
<?php endif; ?>
</body>
</html>
