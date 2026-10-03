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
$profileTab = in_array($_GET['tab'] ?? '', ['guestbook','respect'], true) ? $_GET['tab'] : 'overview';
if ($user && $db instanceof PDO) {
    $gameState = update_game_clock($db);
    $stats = get_player_stats($db, (int) $user['id']);
    $playerRank = get_player_rank($db, (int) $user['id']);
    if (isset($_GET['view']) && $_GET['view'] === 'profile' && $gameState && $profileTab === 'respect') {
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
$showRanking = ($_GET['view'] ?? '') === 'ranking';
$showNotifications = ($_GET['view'] ?? '') === 'notifications';
$rankingType = ($_GET['ranking'] ?? '') === 'gangs' ? 'gangs' : 'players';
$rankingSearch = trim(is_string($_GET['nick'] ?? null) ? $_GET['nick'] : '');
$rankingSearch = mb_substr($rankingSearch, 0, 60);
$rankingPage = max(1, min(100000, (int)($_GET['p'] ?? 1)));
$rankingPlayers = [];
$rankingTotal = 0;
$rankingPages = 1;
$rankingGangs = [];
$viewGang = null;
$viewGangIsMember = false;
$viewGangId = max(0, (int)($_GET['gang'] ?? 0));
if ($showRanking && $rankingType === 'gangs' && $user && $db instanceof PDO) {
    $rankingGangs = $db->query("SELECT g.id,g.name,COALESCE(SUM(FLOOR(GREATEST(0,ps.cash)/10)+FLOOR((GREATEST(0,ps.strength)+GREATEST(0,ps.endurance)+GREATEST(0,ps.intelligence)+GREATEST(0,ps.charisma)+GREATEST(0,ps.cunning))/20)),0) AS respect,COUNT(gm.user_id) AS member_count
        FROM gangs g
        LEFT JOIN gang_members gm ON gm.gang_id=g.id
        LEFT JOIN player_stats ps ON ps.user_id=gm.user_id
        GROUP BY g.id,g.name
        ORDER BY respect DESC,g.id ASC")->fetchAll();
}
if ($showRanking && $rankingType === 'gangs' && $viewGangId > 0 && $user && $db instanceof PDO) {
    $viewGangStmt = $db->prepare("SELECT g.id,g.name,g.owner_id,u.login AS leader,ps.respect AS leader_respect,ps.profession AS leader_profession
        FROM gangs g
        JOIN users u ON u.id=g.owner_id
        LEFT JOIN player_stats ps ON ps.user_id=g.owner_id
        WHERE g.id=? LIMIT 1");
    $viewGangStmt->execute([$viewGangId]);
    $viewGang = $viewGangStmt->fetch() ?: null;
    if ($viewGang) {
        $viewMemberStmt = $db->prepare('SELECT 1 FROM gang_members WHERE gang_id=? AND user_id=? LIMIT 1');
        $viewMemberStmt->execute([$viewGangId,(int)$user['id']]);
        $viewGangIsMember = (bool)$viewMemberStmt->fetchColumn();
    }
}
if ($showRanking && $rankingType === 'players' && $user && $db instanceof PDO) {
    $rankingWhere = 'FROM player_stats p JOIN users u ON u.id=p.user_id WHERE u.active=1';
    $rankingParams = [];
    if ($rankingSearch !== '') {
        $rankingWhere .= " AND u.login LIKE :nick ESCAPE '!'";
        $rankingParams[':nick'] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $rankingSearch) . '%';
    }
    $rankingCountQuery = $db->prepare('SELECT COUNT(*) ' . $rankingWhere);
    $rankingCountQuery->execute($rankingParams);
    $rankingTotal = (int)$rankingCountQuery->fetchColumn();
    $rankingPages = max(1,(int)ceil($rankingTotal/20));
    $rankingPage = min($rankingPage,$rankingPages);
    $rankingOffset = ($rankingPage-1)*20;
    $rankingQuery = $db->prepare('SELECT u.id,u.login,p.public_respect,p.profession,
      (SELECT COUNT(*)+1 FROM player_stats other
       WHERE other.public_respect > p.public_respect
          OR (other.public_respect=p.public_respect AND other.user_id<p.user_id)) AS global_rank '
      . $rankingWhere . ' ORDER BY p.public_respect DESC,p.user_id ASC LIMIT 20 OFFSET :offset');
    foreach ($rankingParams as $key=>$value) $rankingQuery->bindValue($key,$value,PDO::PARAM_STR);
    $rankingQuery->bindValue(':offset',$rankingOffset,PDO::PARAM_INT);
    $rankingQuery->execute();
    $rankingPlayers = $rankingQuery->fetchAll();
}
$showTravel = ($_GET['view'] ?? '') === 'travel';
$activeTravel = null;
$travelMessage = '';
if ($user && $db instanceof PDO) require __DIR__ . '/../base/travel.php';
if ($activeTravel) {
    // Cała gra pozostaje widoczna. Akcje i lokacje są zablokowane po stronie serwera.
    if ($selectedLocation !== '') {
        redirect('./?page=main');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        redirect('./?page=main');
    }
}

require __DIR__ . '/../base/contacts.php';
$pendingContactCount = 0;
if ($user && $db instanceof PDO) {
    $db->exec("CREATE TABLE IF NOT EXISTS player_contacts (
        user_low INT NOT NULL, user_high INT NOT NULL, requested_by INT NOT NULL,
        status ENUM('pending','accepted') NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY(user_low,user_high), INDEX contacts_high(user_high,status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pendingContactsStmt=$db->prepare("SELECT COUNT(*) FROM player_contacts WHERE (user_low=? OR user_high=?) AND status='pending' AND requested_by<>?");
    $pendingContactsStmt->execute([(int)$user['id'],(int)$user['id'],(int)$user['id']]);
    $pendingContactCount=(int)$pendingContactsStmt->fetchColumn();
}

// Zaproszenia do gangu z listy znajomych — tylko lider własnego gangu.
$contactGangLeaderId = 0;
$gangInviteMessage = '';
if ($user && $db instanceof PDO) {
    $leaderStmt = $db->prepare("SELECT g.id FROM gangs g JOIN gang_members gm ON gm.gang_id=g.id WHERE g.owner_id=? AND gm.user_id=? AND gm.role='boss' LIMIT 1");
    $leaderStmt->execute([(int)$user['id'],(int)$user['id']]);
    $contactGangLeaderId = (int)($leaderStmt->fetchColumn() ?: 0);

    if ($_SERVER['REQUEST_METHOD']==='POST' && in_array($_POST['action'] ?? '', ['gang_invite','gang_invite_cancel'], true)) {
        $gangInviteAction = $_POST['action'];
        $target = filter_var($_POST['target'] ?? null, FILTER_VALIDATE_INT);
        if (!csrf_valid(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
            $contactsMessage = 'Sesja wygasła. Odśwież stronę.';
        } elseif (!$contactGangLeaderId || !$target || $target===(int)$user['id']) {
            $contactsMessage = 'Nie możesz wysłać tego zaproszenia.';
        } else {
            $low=min((int)$user['id'],(int)$target); $high=max((int)$user['id'],(int)$target);
            if ($gangInviteAction==='gang_invite_cancel') {
                $cancel=$db->prepare("UPDATE gang_invites SET status='cancelled',responded_at=NOW() WHERE gang_id=? AND invited_user_id=? AND invited_by=? AND status='pending'");
                $cancel->execute([$contactGangLeaderId,(int)$target,(int)$user['id']]);
                $contactsMessage=$cancel->rowCount()?'Zaproszenie do gangu zostało anulowane.':'Nie znaleziono oczekującego zaproszenia.';
            } else {
                $friend=$db->prepare("SELECT 1 FROM player_contacts WHERE user_low=? AND user_high=? AND status='accepted' LIMIT 1");
                $friend->execute([$low,$high]);
                $pending=$db->prepare("SELECT 1 FROM gang_invites WHERE gang_id=? AND invited_user_id=? AND status='pending' LIMIT 1");
                $pending->execute([$contactGangLeaderId,(int)$target]);
                if (!$friend->fetchColumn()) $contactsMessage='Do gangu możesz zapraszać tylko znajomych.';
                elseif ($pending->fetchColumn()) $contactsMessage='Ten gracz jest już zaproszony do gangu.';
                else {
                    $invite=$db->prepare("INSERT INTO gang_invites(gang_id,invited_user_id,invited_by,status) VALUES (?,?,?,'pending')");
                    $invite->execute([$contactGangLeaderId,(int)$target,(int)$user['id']]);
                    $contactsMessage='Zaproszenie do gangu zostało wysłane.';
                }
            }
        }
    }
}

$contactGangPendingInvites = [];
if ($contactGangLeaderId && $db instanceof PDO) {
    $pendingGangInvitesStmt=$db->prepare("SELECT invited_user_id FROM gang_invites WHERE gang_id=? AND invited_by=? AND status='pending'");
    $pendingGangInvitesStmt->execute([$contactGangLeaderId,(int)$user['id']]);
    $contactGangPendingInvites=array_map('intval',$pendingGangInvitesStmt->fetchAll(PDO::FETCH_COLUMN));
}

// Gang działa jako zwykła lokacja w głównym layoucie gry.
$gangMessage = '';
$gangMessageType = 'ok';
$gang = null;
$gangMembers = [];
$gangInvitedPlayers = [];
if ($user && $db instanceof PDO && $selectedLocation === 'gang') {
    $membershipStmt = $db->prepare("SELECT g.id,g.name,g.owner_id,gm.role,
        COALESCE((SELECT SUM(FLOOR(GREATEST(0,ps.cash)/10)+FLOOR((GREATEST(0,ps.strength)+GREATEST(0,ps.endurance)+GREATEST(0,ps.intelligence)+GREATEST(0,ps.charisma)+GREATEST(0,ps.cunning))/20)) FROM gang_members gm2 JOIN player_stats ps ON ps.user_id=gm2.user_id WHERE gm2.gang_id=g.id),0) AS respect
        FROM gang_members gm JOIN gangs g ON g.id=gm.gang_id WHERE gm.user_id=? LIMIT 1");
    $membershipStmt->execute([(int)$user['id']]);
    $gang = $membershipStmt->fetch() ?: null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_gang') {
        if ($activeTravel) {
            $gangMessage = 'Nie możesz utworzyć gangu podczas podróży.';
            $gangMessageType = 'error';
        } elseif (!csrf_valid(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
            $gangMessage = 'Sesja formularza wygasła. Odśwież stronę.';
            $gangMessageType = 'error';
        } elseif ($gang) {
            $gangMessage = 'Należysz już do gangu.';
            $gangMessageType = 'error';
        } else {
            $name = trim(is_string($_POST['name'] ?? null) ? $_POST['name'] : '');
            if (mb_strlen($name) < 3 || mb_strlen($name) > 20) {
                $gangMessage = 'Nazwa gangu musi mieć od 3 do 20 znaków.';
                $gangMessageType = 'error';
            } elseif (!preg_match('/^[\\p{L}\\p{N} ._-]+$/u', $name)) {
                $gangMessage = 'Nazwa zawiera niedozwolone znaki.';
                $gangMessageType = 'error';
            } else {
                try {
                    $db->beginTransaction();
                    $checkMember = $db->prepare('SELECT gang_id FROM gang_members WHERE user_id=? FOR UPDATE');
                    $checkMember->execute([(int)$user['id']]);
                    if ($checkMember->fetchColumn()) throw new RuntimeException('Należysz już do gangu.');
                    $duplicate = $db->prepare('SELECT id FROM gangs WHERE name=? LIMIT 1');
                    $duplicate->execute([$name]);
                    if ($duplicate->fetchColumn()) throw new RuntimeException('Gang o takiej nazwie już istnieje.');
                    $create = $db->prepare('INSERT INTO gangs(name,owner_id) VALUES(?,?)');
                    $create->execute([$name,(int)$user['id']]);
                    $gangId = (int)$db->lastInsertId();
                    $join = $db->prepare("INSERT INTO gang_members(gang_id,user_id,role) VALUES(?,?,'boss')");
                    $join->execute([$gangId,(int)$user['id']]);
                    $db->commit();
                    redirect('./?page=main&location=gang&created=1');
                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    $gangMessage = $e instanceof RuntimeException ? $e->getMessage() : 'Nie udało się utworzyć gangu.';
                    $gangMessageType = 'error';
                }
            }
        }
    }

    $membershipStmt->execute([(int)$user['id']]);
    $gang = $membershipStmt->fetch() ?: null;
    if ($gang) {
        $membersStmt = $db->prepare("SELECT u.id,u.login,gm.role,gm.joined_at,
            FLOOR(GREATEST(0,ps.cash)/10)+FLOOR((GREATEST(0,ps.strength)+GREATEST(0,ps.endurance)+GREATEST(0,ps.intelligence)+GREATEST(0,ps.charisma)+GREATEST(0,ps.cunning))/20) AS player_respect
            FROM gang_members gm JOIN users u ON u.id=gm.user_id
            LEFT JOIN player_stats ps ON ps.user_id=gm.user_id
            WHERE gm.gang_id=? ORDER BY (gm.role='boss') DESC,gm.joined_at ASC,u.id ASC");
        $membersStmt->execute([(int)$gang['id']]);
        $gangMembers = $membersStmt->fetchAll();

        $invitedStmt = $db->prepare("SELECT u.id,u.login,gi.created_at,
            FLOOR(GREATEST(0,ps.cash)/10)+FLOOR((GREATEST(0,ps.strength)+GREATEST(0,ps.endurance)+GREATEST(0,ps.intelligence)+GREATEST(0,ps.charisma)+GREATEST(0,ps.cunning))/20) AS player_respect
            FROM gang_invites gi JOIN users u ON u.id=gi.invited_user_id
            LEFT JOIN player_stats ps ON ps.user_id=gi.invited_user_id
            WHERE gi.gang_id=? AND gi.status='pending'
            ORDER BY gi.created_at ASC,gi.id ASC");
        $invitedStmt->execute([(int)$gang['id']]);
        $gangInvitedPlayers = $invitedStmt->fetchAll();
    }
    if (isset($_GET['created']) && $gang) $gangMessage = 'Gang został utworzony.';
}

require_once __DIR__ . '/../base/robbery_rewards.php';
$streetMessage = '';
$streetReward = null;
$robberyPower = $stats ? (int) floor(
    (int)$stats['strength'] * 0.35 +
    (int)$stats['endurance'] * 0.20 +
    (int)$stats['intelligence'] * 0.15 +
    (int)$stats['charisma'] * 0.10 +
    (int)$stats['cunning'] * 0.20
) : 0;
$streetAction = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
if ($user && $db instanceof PDO && $selectedLocation === 'ulica'
    && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($streetAction, ['street_grocery_robbery','street_taxi_robbery'], true)) {
    if ($activeTravel) {
        $streetMessage = 'Nie możesz rabować podczas podróży.';
    } elseif (!csrf_valid(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $streetMessage = 'Sesja wygasła. Odśwież stronę.';
    } else {
        $db->beginTransaction();
        try {
            regenerate_player_energy($db, (int)$user['id']);
            $lock = $db->prepare('SELECT energy FROM player_stats WHERE user_id=? FOR UPDATE');
            $lock->execute([(int)$user['id']]);
            $currentEnergy = $lock->fetchColumn();
            $energyCost = $streetAction === 'street_taxi_robbery' ? 10 : 5;
            if ($currentEnergy === false || (int)$currentEnergy < $energyCost) {
                $streetMessage = 'Potrzebujesz co najmniej '.$energyCost.'% energii.';
            } else {
                $taxi = $streetAction === 'street_taxi_robbery';
                // Przedział 25–30 oznacza poziom trudności, a nie blokadę akcji.
                // Im bliżej/głębiej ponad przedział, tym większa szansa powodzenia; poniżej nadal można próbować.
                $successChance = $taxi ? max(10, min(95, 50 + ($robberyPower - 25) * 8)) : 100;
                $success = random_int(1,100) <= $successChance;
                if ($success) {
                    $streetReward = $taxi ? random_int(50,100) : random_int(10,20);
                    $statGain = $taxi ? random_int(2,4) : 1;
                    $rob = $db->prepare('UPDATE player_stats SET energy=energy-?,cash=cash+?,
                        strength=strength+?,endurance=endurance+?,intelligence=intelligence+?,
                        charisma=charisma+?,cunning=cunning+? WHERE user_id=? AND energy>=?');
                    $rob->execute([$energyCost,$streetReward,$statGain,$statGain,$statGain,$statGain,$statGain,(int)$user['id'],$energyCost]);
                    $firstDailyRobbery = award_first_daily_robbery_credit($db, (int)$user['id'], (int)$gameState['season'], (int)$gameState['game_day']);
                    $streetMessage = 'Rabunek udany! +'.$streetReward.' $, +'.$statGain.' do każdej statystyki. -'.$energyCost.'% energii.'
                        . ($firstDailyRobbery ? ' Pierwszy udany rabunek dnia: +1 sztabka złota!' : '');
                } else {
                    $statLoss = $taxi ? random_int(1,2) : 0;
                    $fail = $db->prepare('UPDATE player_stats SET energy=energy-?,
                        strength=GREATEST(1,strength-?),endurance=GREATEST(1,endurance-?),
                        intelligence=GREATEST(1,intelligence-?),charisma=GREATEST(1,charisma-?),
                        cunning=GREATEST(1,cunning-?) WHERE user_id=? AND energy>=?');
                    $fail->execute([$energyCost,$statLoss,$statLoss,$statLoss,$statLoss,$statLoss,(int)$user['id'],$energyCost]);
                    $streetMessage = 'Rabunek nieudany! -'.$energyCost.'% energii i -'.$statLoss.' do każdej statystyki.';
                }
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
$killerLeader = null;
$killerReward = 0;
$killerKilled = false;
$killerMessage = '';
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
    $db->exec('CREATE TABLE IF NOT EXISTS wanted_killer_bounties (
        season INT NOT NULL, game_day INT NOT NULL, target_id INT NOT NULL,
        kill_count INT NOT NULL DEFAULT 0, reward BIGINT NOT NULL DEFAULT 0,
        killed_by INT DEFAULT NULL, killed_at DATETIME DEFAULT NULL,
        PRIMARY KEY(season,game_day)
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
            // WANTED uses the last CLOSED day snapshot, never live respect.
            $targetDay = $break ? $day : $day - 1;
            $targetSeason = $season;
            if ($targetDay < 1) {
                $targetSeason = $season - 1;
                $targetDay = 60;
            }
            $leaderSql='SELECT u.id,u.login,(h.respect-COALESCE(prev.respect,100)) AS gained FROM respect_history h
                JOIN users u ON u.id=h.user_id AND u.active=1
                LEFT JOIN respect_history prev ON prev.user_id=h.user_id AND prev.season=? AND prev.game_day=?
                WHERE h.season=? AND h.game_day=?
                ORDER BY gained DESC,u.id ASC LIMIT 1';
            $params=[$targetSeason,$targetDay-1,$targetSeason,$targetDay];
            $leaderQuery=$db->prepare($leaderSql);
            $leaderQuery->execute($params);
            $candidate=$leaderQuery->fetch();
            if ($candidate && (int)$candidate['gained']>0) {
                $wantedReward = (int) min(1000000000000, floor((int)$candidate['gained'] * $wantedRewardPerRespect));
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
            if ($activeTravel) {
                $wantedMessage='Nie możesz atakować podczas podróży.';
            } elseif (!csrf_valid(is_string($_POST['csrf_token']??null)?$_POST['csrf_token']:null)) {
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
        // Drugi cel WANTED: gracz z największą liczbą zabójstw w ostatnim zakończonym dniu.
        $killerBountyStmt=$db->prepare('SELECT * FROM wanted_killer_bounties WHERE season=? AND game_day=? FOR UPDATE');
        $killerBountyStmt->execute([$season,$day]);
        $killerBounty=$killerBountyStmt->fetch();
        if (!$killerBounty) {
            $killTargetDay=$break ? $day : $day-1;
            $killTargetSeason=$season;
            if ($killTargetDay<1) { $killTargetSeason=$season-1; $killTargetDay=60; }
            $killerQuery=$db->prepare('SELECT u.id,u.login,COUNT(*) AS kill_count FROM player_kills k JOIN users u ON u.id=k.killer_id AND u.active=1 WHERE k.season=? AND k.game_day=? GROUP BY k.killer_id,u.id,u.login ORDER BY kill_count DESC,u.id ASC LIMIT 1');
            $killerQuery->execute([$killTargetSeason,$killTargetDay]);
            $killerCandidate=$killerQuery->fetch();
            if ($killerCandidate && (int)$killerCandidate['kill_count']>0) {
                // Każde zabójstwo zwiększa nagrodę o 1 000 $.
                $killerReward=(int)$killerCandidate['kill_count']*1000;
                $makeKiller=$db->prepare('INSERT INTO wanted_killer_bounties(season,game_day,target_id,kill_count,reward) VALUES (?,?,?,?,?)');
                $makeKiller->execute([$season,$day,(int)$killerCandidate['id'],(int)$killerCandidate['kill_count'],$killerReward]);
                $killerBounty=['target_id'=>(int)$killerCandidate['id'],'kill_count'=>(int)$killerCandidate['kill_count'],'reward'=>$killerReward,'killed_by'=>null];
                $killerLeader=$killerCandidate;
            }
        }
        if ($killerBounty) {
            if (!$killerLeader) {
                $killerUser=$db->prepare('SELECT id,login FROM users WHERE id=?');
                $killerUser->execute([(int)$killerBounty['target_id']]);
                $killerLeader=$killerUser->fetch();
            }
            $killerReward=(int)$killerBounty['reward'];
            $killerKilled=$killerBounty['killed_by']!==null;
        }
        if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='wanted_killer_kill') {
            if ($activeTravel) $killerMessage='Nie możesz atakować podczas podróży.';
            elseif (!csrf_valid(is_string($_POST['csrf_token']??null)?$_POST['csrf_token']:null)) $killerMessage='Sesja wygasła. Odśwież stronę.';
            elseif ($break || !$killerLeader || $killerKilled || (int)$killerLeader['id']===(int)$user['id']) $killerMessage='Tego celu nie można teraz zaatakować.';
            elseif ((int)($_POST['target_id']??0)!==(int)$killerLeader['id']) $killerMessage='Cel WANTED zmienił się. Odśwież stronę.';
            else {
                $claimKiller=$db->prepare('UPDATE wanted_killer_bounties SET killed_by=?,killed_at=NOW() WHERE season=? AND game_day=? AND target_id=? AND killed_by IS NULL');
                $claimKiller->execute([(int)$user['id'],$season,$day,(int)$killerLeader['id']]);
                if ($claimKiller->rowCount()===1) {
                    $payKiller=$db->prepare('UPDATE player_stats SET cash=cash+? WHERE user_id=?');
                    $payKiller->execute([$killerReward,(int)$user['id']]);
                    $killerKilled=true;
                    $killerMessage='Najgroźniejszy morderca zabity! Otrzymujesz '.number_format($killerReward,0,'.',' ').' $.';
                    $stats=get_player_stats($db,(int)$user['id']);
                } else $killerMessage='Nagroda została już odebrana.';
            }
        }
        $db->commit();
    } catch (Throwable $ex) {
        if ($db->inTransaction()) $db->rollBack();
        throw $ex;
    }
}
$guestbookOwner = null;
$publicProfile = null;
$guestbookEntries = [];
$guestbookPage = 1;
$guestbookPages = 1;
$guestbookMessage = '';
if ($user && $db instanceof PDO && $showProfile) {
    $db->exec('CREATE TABLE IF NOT EXISTS player_blocks (
        blocker_id INT NOT NULL, blocked_id INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(blocker_id,blocked_id), INDEX blocks_blocked(blocked_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
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
    if ($guestbookOwner && $profileTab === 'respect' && $gameState) {
        $respectHistory = get_respect_history($db, (int)$guestbookOwner['id'], (int)$gameState['season']);
    }
    if ($guestbookOwner && (int)$guestbookOwner['id'] !== (int)$user['id']) {
        $publicStmt=$db->prepare('SELECT profession,current_city,public_respect FROM player_stats WHERE user_id=?');
        $publicStmt->execute([(int)$guestbookOwner['id']]);
        $publicProfile=$publicStmt->fetch() ?: null;
        $publicRank=get_player_rank($db,(int)$guestbookOwner['id']);
    }
    if ($guestbookOwner) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['guestbook_add','guestbook_delete'], true)) {
            if (!csrf_valid(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
                $guestbookMessage = 'Sesja wygasła. Odśwież stronę.';
            } elseif ($_POST['action'] === 'guestbook_add' && (int)$guestbookOwner['id'] === (int)$user['id']) {
                $guestbookMessage = 'Nie możesz wpisywać się do własnej księgi gości.';
            } elseif ($_POST['action'] === 'guestbook_add') {
                $blockCheck=$db->prepare('SELECT 1 FROM player_blocks WHERE blocker_id=? AND blocked_id=? LIMIT 1');
                $blockCheck->execute([(int)$guestbookOwner['id'],(int)$user['id']]);
                if ($blockCheck->fetchColumn()) {
                    $guestbookMessage='Nie możesz wpisać się do księgi gości tego gracza.';
                } else {
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
                        redirect('./?page=main&view=profile&player='.(int)$guestbookOwner['id'].'&tab=guestbook#guestbook');
                    }
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
// Misje podróżnicze: jedno odblokowanie dla każdego miasta poza startowym.
$travelMissionMessage='';
if ($user && $db instanceof PDO && ($_POST['action'] ?? '') === 'unlock_city') {
    if ($activeTravel) $travelMissionMessage='Odblokowywanie miast jest niedostępne podczas podróży.';
    elseif (!csrf_valid($_POST['csrf_token'] ?? null)) $travelMissionMessage='Sesja wygasła. Odśwież stronę.';
    else {
        $city=is_string($_POST['city'] ?? null)?$_POST['city']:'';
        $cityIndex=array_search($city,array_keys($travelCities),true);
        $required=$cityIndex===false?PHP_INT_MAX:200+100*$cityIndex;
        if (!isset($travelCities[$city])) $travelMissionMessage='Nieprawidłowe miasto.';
        elseif (isset($unlockedCities[$city])) $travelMissionMessage='To miasto jest już odblokowane.';
        elseif ((int)$stats['respect']<$required) $travelMissionMessage='Nie masz jeszcze wymaganego respektu.';
        else {
            $unlock=$db->prepare('INSERT IGNORE INTO player_unlocked_cities(user_id,city) VALUES(?,?)');
            $unlock->execute([(int)$user['id'],$city]);
            $unlockedCities[$city]=true;
            $travelMissionMessage='Odblokowano podróż do: '.$city.'.';
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
        .city-map { margin-bottom: 24px; padding: 22px; overflow: hidden; }
        .city-map h2 { margin: 0 0 8px; }
        .city-map .map-legend { display: flex; flex-wrap: wrap; gap: 15px; color: #bbb; font-size: 13px; margin: 12px 0; }
        .city-map .map-dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 5px; background: #51a8ff; }
        .city-map .map-dot.current { background: #f45454; box-shadow: 0 0 12px #f45454; }
        .city-map .raid-ring { animation: raid-pulse 1.2s ease-out infinite; transform-box: fill-box; transform-origin: center; }
        @keyframes raid-pulse { from { opacity: .95; transform: scale(.6); } to { opacity: 0; transform: scale(2.2); } }
        @media (prefers-reduced-motion: reduce) { .city-map .raid-ring { animation: none; } }
        .city-map svg { width: 100%; max-height: 470px; display: block; margin: auto; }
        .city-map .map-label { fill: #e3e7ed; font-size: 14px; font-weight: 700; paint-order: stroke; stroke: #171a21; stroke-width: 3px; stroke-linejoin: round; }
        .city-map .map-label.current { fill: #ffd400; }
        @media (max-width: 600px) { .city-map { padding: 12px; } .city-map .map-label { font-size: 14px; } }
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
        .location-grid.traveling {opacity:.45;pointer-events:none;filter:grayscale(1);}
        .travel-status {margin-bottom:16px;border-color:#b28c37;background:#292316;}
        #travelDestination {width:100%;padding:13px;border:1px solid #555;border-radius:9px;background:#111;color:#fff;font-size:16px;}
        .travel-grid {display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
        @media(max-width:600px){.travel-grid{grid-template-columns:1fr}}
        .profile-tabs { display:flex; flex-wrap:wrap; gap:10px; margin:22px 0; }
        .profile-tabs a { padding:12px 16px; border:1px solid #444; border-radius:9px; color:#ddd; text-decoration:none; background:#111; font-weight:700; }
        .profile-tabs a.active { color:#111; background:#52dc8b; border-color:#52dc8b; }
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
        <a href="./?page=main&amp;view=contacts">Kontakty<?= $pendingContactCount > 0 ? ' ('.$pendingContactCount.')' : '' ?></a>
        <a href="./?page=main&amp;view=wanted">Wanted</a>
        <a href="./?page=main&amp;view=travel">Podróż</a>
        <a href="./?page=main&amp;view=market">Rynek</a>
        <a href="./?page=main&amp;view=ranking">Ranking</a>
        <a href="./?page=main&amp;view=notifications">Powiadomienia</a>
    </nav>

    <?php if ($activeTravel): ?>
    <section class="card travel-status" aria-live="polite">
      <strong>🚗 Podróż do <?= e($activeTravel['destination']) ?></strong>
      <span> · Pozostało: <strong class="travel-live-countdown" data-seconds="<?= max(0,strtotime($activeTravel['arrives_at'])-time()) ?>">--:--</strong></span>
      <span class="muted"> · Lokacje i akcje niedostępne do przyjazdu.</span>
    </section>
    <script>
    (()=>{const el=document.querySelector('.travel-live-countdown');if(!el)return;let s=Number(el.dataset.seconds);const tick=()=>{if(s<=0){el.textContent='00:00';location.reload();return;}el.textContent=Math.floor(s/60)+':'+String(s%60).padStart(2,'0');s--;};tick();setInterval(tick,1000);})();
    </script>
    <?php endif; ?>

    <?php if ($gameState): ?>
    <section class="game-state" aria-label="Stan gry">
        <div class="stat"><span>Dzień gry</span><strong><?= (int) $gameState['game_day'] ?>/60</strong></div>
        <div class="stat"><span>Sezon gry</span><strong><?= (int) $gameState['season'] ?></strong></div>
        <div class="stat"><span><?= (int) ($gameState['is_break'] ?? 0) === 1 ? 'Start następnego sezonu' : 'Następna aktualizacja rankingu' ?></span><strong id="rankingCountdown" data-next="<?= e($gameState['next_ranking_update']) ?>">--:--:--</strong></div>
    </section>
    <?php endif; ?>

    <?php if ($showProfile): ?>
    <section class="card location-panel">
        <a class="button secondary back-button" href="<?= $activeTravel ? './?page=main&view=travel' : './?page=main' ?>">← Powrót do menu</a>
        <h2><?= $guestbookOwner ? 'Profil: '.e($guestbookOwner['login']) : 'Profil gracza' ?></h2>
        <?php $profileId = (int)($guestbookOwner['id'] ?? $user['id']); ?>
        <nav class="profile-tabs" aria-label="Zakładki profilu">
          <a class="<?= $profileTab === 'overview' ? 'active' : '' ?>" href="./?page=main&amp;view=profile&amp;player=<?= $profileId ?>">Profil</a>
          <a class="<?= $profileTab === 'guestbook' ? 'active' : '' ?>" href="./?page=main&amp;view=profile&amp;player=<?= $profileId ?>&amp;tab=guestbook#guestbook">Księga gości</a>
          <?php if ($guestbookOwner): ?>
          <a class="<?= $profileTab === 'respect' ? 'active' : '' ?>" href="./?page=main&amp;view=profile&amp;player=<?= $profileId ?>&amp;tab=respect">Respekt dni</a>
          <?php endif; ?>
        </nav>
        <?php if ($profileTab === 'overview' && $guestbookOwner && (int)$guestbookOwner['id'] === (int)$user['id'] && $stats): ?>
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
            <div class="stat"><span>Sztabki złota</span><strong><?= number_format((int) ($stats['credits'] ?? 0), 0, '.', ',') ?></strong></div>
        </div>
        <?php endif; ?>
        <?php if ($profileTab === 'overview' && $guestbookOwner && (int)$guestbookOwner['id'] !== (int)$user['id'] && $publicProfile): ?>
        <div class="stats">
          <div class="stat"><span>Login</span><strong><?= e($guestbookOwner['login']) ?></strong></div>
          <div class="stat"><span>Miejsce w rankingu</span><strong>#<?= (int)$publicRank ?></strong></div>
          <div class="stat"><span>Respekt publiczny</span><strong><?= number_format((int)$publicProfile['public_respect'],0,'.',' ') ?> pkt</strong></div>
          <div class="stat"><span>Profesja</span><strong><?= e($publicProfile['profession'] ?? 'Nie wybrano') ?></strong></div>
          <div class="stat"><span>Miasto</span><strong><?= e($publicProfile['current_city'] ?? 'Nie wybrano') ?></strong></div>
        </div>
        <?php endif; ?>
        <?php if ($profileTab === 'respect' && $guestbookOwner): ?>
        <h2 style="margin-top:28px">Historia respektu</h2>
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
        <?php if ($profileTab === 'guestbook' && $guestbookOwner): ?>
        <div id="guestbook" style="margin-top:32px">
          <h2>Księga gości</h2>
          <?php if ($guestbookMessage !== ''): ?><p><?= e($guestbookMessage) ?></p><?php endif; ?>
          <?php if ((int)$guestbookOwner['id'] !== (int)$user['id']): ?>
          <form method="post" action="./?page=main&amp;view=profile&amp;player=<?= (int)$guestbookOwner['id'] ?>&amp;tab=guestbook#guestbook">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="guestbook_add">
            <label for="guestbook-body">Dodaj wpis (maksymalnie 300 znaków)</label>
            <textarea id="guestbook-body" name="body" maxlength="300" required rows="3" style="width:100%;background:#111;color:white;padding:12px;border:1px solid #555;border-radius:8px"></textarea>
            <button type="submit">Dodaj komentarz</button>
          </form>
          <?php endif; ?>
          <?php foreach ($guestbookEntries as $entry): ?>
            <div class="mission">
              <strong><a style="color:inherit" href="./?page=main&amp;view=profile&amp;player=<?= (int)$entry['author_id'] ?>&amp;tab=guestbook#guestbook"><?= e($entry['login']) ?></a></strong>
              <span class="muted"><?= e($entry['created_at']) ?></span>
              <p style="white-space:pre-wrap;overflow-wrap:anywhere"><?= e($entry['body']) ?></p>
              <?php if ((int)$guestbookOwner['id'] === (int)$user['id']): ?>
              <form method="post" action="./?page=main&amp;view=profile&amp;tab=guestbook#guestbook">
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
                <a class="button <?= $pageNo===$guestbookPage ? '' : 'secondary' ?>" href="./?page=main&amp;view=profile&amp;player=<?= (int)$guestbookOwner['id'] ?>&amp;gb_page=<?= $pageNo ?>&amp;tab=guestbook#guestbook"><?= $pageNo ?></a>
              <?php endfor; ?>
            </nav>
          <?php endif; ?>
        </div>
        <?php endif; ?>
    </section>
    <?php elseif ($showTravel): ?>
    <section class="card location-panel">
      <a class="button secondary back-button" href="<?= $activeTravel ? './?page=main&view=profile' : './?page=main' ?>">← Powrót do menu</a>
      <h2>Podróże</h2>
      <p class="muted">Cena i czas podróży zależą od odległości oraz Twojego respektu.</p>
      <?php if ($travelMessage): ?><p><?= e($travelMessage) ?></p><?php endif; ?>
      <?php if ($activeTravel): ?>
        <h3>Podróż do <?= e($activeTravel['destination']) ?></h3>
        <p>Przyjazd: <?= e($activeTravel['arrives_at']) ?></p>
        <p id="travelCountdown" data-seconds="<?= max(0,strtotime($activeTravel['arrives_at'])-time()) ?>"></p>
        <script>
        (()=>{const el=document.getElementById('travelCountdown');let left=Number(el.dataset.seconds);const tick=()=>{el.textContent=left>0?'Pozostało: '+Math.floor(left/60)+' min '+String(left%60).padStart(2,'0')+' s':'Podróż zakończona — odśwież stronę';left=Math.max(0,left-1);};tick();setInterval(tick,1000);})();
        </script>
      <?php else: ?>
        <p>Jesteś w: <strong><?= e($stats['current_city']) ?></strong></p>
        <form class="mission" method="post" action="./?page=main&amp;view=travel">
          <label for="travelDestination">Wybierz miasto docelowe</label>
          <select id="travelDestination" name="destination" required>
            <option value="">— Wybierz miasto —</option>
            <?php foreach ($travelCities as $destination=>$coords):
              $quote=travel_quote($travelCities,(string)$stats['current_city'],$destination,(int)$stats['respect']);
              if (!$quote) continue;
              $cityUnlocked=isset($unlockedCities[$destination]);
            ?>
              <?php if ($cityUnlocked): ?>
              <option value="<?= e($destination) ?>"><?= e($destination) ?></option>
              <?php endif; ?>
            <?php endforeach; ?>
          </select>
          <div id="travelDetails" class="stat" style="margin-top:16px" hidden>
            <span>Odległość i czas</span>
            <strong id="travelRoute"></strong>
            <span style="margin-top:10px">Cena podróży</span>
            <strong id="travelPrice"></strong>
          </div>
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="start_travel">
          <button type="submit" id="travelSubmit" disabled>Podróżuj</button>
        </form>
        <script>
        (() => {
          const select=document.getElementById('travelDestination');
          const details=document.getElementById('travelDetails');
          const route=document.getElementById('travelRoute');
          const price=document.getElementById('travelPrice');
          const submit=document.getElementById('travelSubmit');
          const routes=<?= json_encode(array_reduce(array_keys($travelCities),function($all,$destination) use ($travelCities,$stats,$unlockedCities) {
            $quote=travel_quote($travelCities,(string)$stats['current_city'],$destination,(int)$stats['respect']);
            if($quote) $all[$destination]=['km'=>$quote['km'],'seconds'=>$quote['seconds'],'price'=>$quote['price'],'unlocked'=>isset($unlockedCities[$destination])];
            return $all;
          },[]),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
          const cash=<?= (int)$stats['cash'] ?>;
          const seasonBreak=<?= (int)($gameState['is_break']??0)===1 ? 'true' : 'false' ?>;
          select.addEventListener('change',()=>{
            const q=routes[select.value];
            details.hidden=!q;
            if(!q){submit.disabled=true;return;}
            route.textContent=q.km+' km · '+Math.floor(q.seconds/60)+' min '+String(q.seconds%60).padStart(2,'0')+' s';
            price.textContent=q.price.toLocaleString('pl-PL')+' $';
            submit.disabled=!q.unlocked||cash<q.price||seasonBreak;
            submit.textContent=seasonBreak?'Przerwa między sezonami':cash<q.price?'Za mało pieniędzy':'Podróżuj';
          });
        })();
        </script>

      <?php endif; ?>
    </section>
    <?php elseif ($showNotifications): ?>
    <section class="card">
        <h2>Powiadomienia</h2>
        <p class="muted">Brak powiadomień.</p>
    </section>
    <?php elseif ($showRanking): ?>
    <section class="card location-panel">
      <a class="button secondary back-button" href="<?= $activeTravel ? './?page=main&view=travel' : './?page=main' ?>">← Powrót do menu</a>
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin:14px 0 22px">
        <a class="button <?= $rankingType==='players'?'':'secondary' ?>" href="./?page=main&amp;view=ranking">Ranking graczy</a>
        <a class="button <?= $rankingType==='gangs'?'':'secondary' ?>" href="./?page=main&amp;view=ranking&amp;ranking=gangs">Ranking gangów</a>
      </div>
      <?php if ($rankingType === 'gangs'): ?>
      <?php if ($viewGang): ?>
      <h2><?= e($viewGang['name']) ?></h2>
      <div class="stat"><span>Lider</span><strong><a style="color:inherit" href="./?page=main&amp;view=profile&amp;player=<?= (int)$viewGang['owner_id'] ?>"><?= e($viewGang['leader']) ?></a></strong></div>
      <div class="stat"><span>Respekt</span><strong><?= number_format((int)($viewGang['leader_respect'] ?? 0),0,'.',' ') ?> pkt</strong></div>
      <div class="stat"><span>Profesja</span><strong><?= e((string)($viewGang['leader_profession'] ?? '—')) ?></strong></div>
      <?php if ($viewGangIsMember): ?>
        <p class="muted">Należysz do tego gangu.</p>
        <a class="button" href="./?page=main&amp;location=gang">Przejdź do swojego gangu</a>
      <?php endif; ?>
      <a class="button secondary" href="./?page=main&amp;view=ranking&amp;ranking=gangs">← Wróć do rankingu gangów</a>
      <?php else: ?>
      <h2>🏆 Ranking gangów</h2>
      <div style="overflow-x:auto">
      <table style="width:100%;border-collapse:collapse;text-align:left">
        <thead><tr><th style="padding:12px">Miejsce</th><th style="padding:12px">Nazwa</th><th style="padding:12px">Respekt</th><th style="padding:12px">Członkowie</th></tr></thead>
        <tbody>
        <?php foreach ($rankingGangs as $index=>$rankedGang): ?>
          <tr style="border-top:1px solid #363636">
            <td style="padding:12px">#<?= $index+1 ?></td>
            <td style="padding:12px;font-weight:700"><a style="color:inherit" href="./?page=main&amp;view=ranking&amp;ranking=gangs&amp;gang=<?= (int)$rankedGang['id'] ?>"><?= e($rankedGang['name']) ?></a></td>
            <td style="padding:12px"><?= number_format((int)$rankedGang['respect'],0,'.',' ') ?></td>
            <td style="padding:12px"><?= (int)$rankedGang['member_count'] ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rankingGangs): ?><tr><td colspan="4" style="padding:15px">Brak gangów.</td></tr><?php endif; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
      <?php else: ?>
      <h2>🏆 Ranking graczy</h2>
      <form method="get" action="./" style="display:flex;gap:10px;flex-wrap:wrap;margin:14px 0 20px">
        <input type="hidden" name="page" value="main">
        <input type="hidden" name="view" value="ranking">
        <input type="search" name="nick" aria-label="Wyszukaj gracza po nicku" placeholder="Wpisz nick gracza..." value="<?= e($rankingSearch) ?>" maxlength="60" style="flex:1;min-width:170px;padding:12px;border:1px solid #555;border-radius:8px;background:#111;color:#fff">
        <button type="submit">Szukaj</button>
        <?php if ($rankingSearch !== ''): ?><a class="button secondary" href="./?page=main&amp;view=ranking">Wyczyść</a><?php endif; ?>
      </form>
      <div style="overflow-x:auto">
      <table style="width:100%;border-collapse:collapse;text-align:left">
        <thead><tr><th style="padding:12px">Miejsce</th><th style="padding:12px">Gracz</th><th style="padding:12px">Respekt</th><th style="padding:12px">Profesja</th></tr></thead>
        <tbody>
        <?php foreach ($rankingPlayers as $index=>$ranked): ?>
          <tr style="border-top:1px solid #363636;<?= (int)$ranked['id']===(int)$user['id']?'background:#263b2b;':'' ?>">
            <td style="padding:12px">#<?= (int)$ranked['global_rank'] ?></td>
            <td style="padding:12px;font-weight:700"><a style="color:inherit" href="./?page=main&amp;view=profile&amp;player=<?= (int)$ranked['id'] ?>"><?= e($ranked['login']) ?></a></td>
            <td style="padding:12px"><?= number_format((int)$ranked['public_respect'],0,'.',' ') ?></td>
            <td style="padding:12px"><?= e((string)($ranked['profession']??'—')) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rankingPlayers): ?><tr><td colspan="4" style="padding:15px"><?= $rankingSearch !== '' ? 'Nie znaleziono gracza o podanym nicku.' : 'Brak graczy.' ?></td></tr><?php endif; ?>
        </tbody>
      </table>
      </div>
      <?php if ($rankingPages>1): ?>
      <nav aria-label="Strony rankingu" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:22px">
        <?php if ($rankingPage>1): ?><a class="button secondary" href="./?page=main&amp;view=ranking&amp;nick=<?= urlencode($rankingSearch) ?>&amp;p=<?= $rankingPage-1 ?>">← Poprzednia</a><?php endif; ?>
        <span>Strona <?= $rankingPage ?> z <?= $rankingPages ?></span>
        <?php if ($rankingPage<$rankingPages): ?><a class="button secondary" href="./?page=main&amp;view=ranking&amp;nick=<?= urlencode($rankingSearch) ?>&amp;p=<?= $rankingPage+1 ?>">Następna →</a><?php endif; ?>
      </nav>
      <?php endif; ?>
      <?php endif; ?>
    </section>
    <?php elseif (($_GET['view'] ?? '') === 'contacts'): ?>
    <section class="card location-panel">
      <a class="button secondary back-button" href="<?= $activeTravel ? './?page=main&view=travel' : './?page=main' ?>">← Powrót do menu</a>
      <h2>Kontakty</h2>
      <?php $contactsTab=in_array($_GET['contacts_tab']??'friends',['friends','blocked','messages'],true)?($_GET['contacts_tab']??'friends'):'friends'; if ($privateChatFriend) $contactsTab='messages'; ?>
      <nav class="profile-tabs" aria-label="Zakładki kontaktów">
        <a class="<?= $contactsTab==='friends'?'active':'' ?>" href="./?page=main&amp;view=contacts&amp;contacts_tab=friends">Znajomi<?= $pendingContactCount>0?' ('.$pendingContactCount.')':'' ?></a>
        <a class="<?= $contactsTab==='blocked'?'active':'' ?>" href="./?page=main&amp;view=contacts&amp;contacts_tab=blocked">Zablokowani</a>
        <a class="<?= $contactsTab==='messages'?'active':'' ?>" href="./?page=main&amp;view=contacts&amp;contacts_tab=messages">Wiadomości</a>
      </nav>
      <?php if ($contactsMessage): ?><p><?= e($contactsMessage) ?></p><?php endif; ?>

      <?php if ($contactsTab==='messages'): ?>
        <?php if ($privateChatFriend): ?>
        <div class="card" style="margin:18px 0">
          <h3>PW — <?= e($privateChatFriend['login']) ?></h3>
          <div style="max-height:360px;overflow:auto;margin:12px 0">
            <?php if (!$privateMessages): ?><p class="muted">Brak wiadomości. Napisz pierwszą.</p><?php endif; ?>
            <?php foreach($privateMessages as $pm): ?>
            <div style="margin:8px 0;padding:10px;border:1px solid #333;border-radius:8px">
              <strong><?= (int)$pm['sender_id']===(int)$user['id']?'Ty':e($pm['sender_login']) ?></strong>
              <span class="muted"> · <?= e($pm['created_at']) ?></span>
              <div style="margin-top:5px;white-space:pre-wrap"><?= e($pm['body']) ?></div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php if ($privateMessagePages>1): ?><nav style="margin:10px 0"><?php for($mp=1;$mp<=$privateMessagePages;$mp++): ?><a class="button <?= $mp===$privateMessagePage?'':'secondary' ?>" href="./?page=main&amp;view=contacts&amp;contacts_tab=messages&amp;chat=<?= (int)$privateChatFriend['id'] ?>&amp;msg_page=<?= $mp ?>"><?= $mp ?></a><?php endfor; ?></nav><?php endif; ?>
          <?php if (!$activeTravel): ?><form method="post" action="./?page=main&amp;view=contacts&amp;contacts_tab=messages&amp;chat=<?= (int)$privateChatFriend['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="private_message_send"><input type="hidden" name="target" value="<?= (int)$privateChatFriend['id'] ?>">
            <textarea name="body" maxlength="500" required rows="3" placeholder="Napisz wiadomość..." style="width:100%;background:#111;color:white;padding:12px;border:1px solid #555;border-radius:8px"></textarea>
            <button class="button" type="submit" style="margin-top:8px">Wyślij PW</button>
          </form><?php endif; ?>
        </div>
        <?php else: ?>
          <p class="muted">Wybierz znajomego, aby otworzyć rozmowę.</p>
          <?php foreach($contactFriends as $person): ?>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #333">
            <strong><?= e($person['login']) ?></strong><a class="button" href="./?page=main&amp;view=contacts&amp;contacts_tab=messages&amp;chat=<?= (int)$person['id'] ?>">Otwórz PW</a>
          </div><?php endforeach; ?>
          <?php if(!$contactFriends): ?><p class="muted">Nie masz jeszcze znajomych.</p><?php endif; ?>
        <?php endif; ?>

      <?php elseif ($contactsTab==='blocked'): ?>
        <h3>Zablokowani (<?= count($blockedPlayers) ?>)</h3>
        <?php if(!$blockedPlayers): ?><p class="muted">Brak zablokowanych graczy.</p><?php endif; ?>
        <?php foreach($blockedPlayers as $person): ?><div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid #333">
          <a href="./?page=main&amp;view=profile&amp;player=<?= (int)$person['id'] ?>"><?= e($person['login']) ?></a>
          <form method="post" action="./?page=main&amp;view=contacts&amp;contacts_tab=blocked"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="player_unblock"><input type="hidden" name="target" value="<?= (int)$person['id'] ?>"><button class="button secondary" type="submit">Odblokuj</button></form>
        </div><?php endforeach; ?>

      <?php else: ?>
        <form method="get" action="./" style="display:flex;gap:10px;flex-wrap:wrap;margin:18px 0"><input type="hidden" name="page" value="main"><input type="hidden" name="view" value="contacts"><input type="hidden" name="contacts_tab" value="friends"><input name="friend_search" maxlength="60" placeholder="Wyszukaj gracza po nicku" value="<?= e($_GET['friend_search']??'') ?>" style="padding:10px;background:#111;color:white;border:1px solid #555;border-radius:8px;flex:1"><button class="button" type="submit">Szukaj</button></form>
        <?php if(trim((string)($_GET['friend_search']??''))!==''): ?><h3>Wyniki wyszukiwania</h3>
        <?php if(!$contactSearchResults): ?><p class="muted">Nie znaleziono graczy.</p><?php endif; ?>
        <?php foreach($contactSearchResults as $person): $already=false; foreach(array_merge($contactFriends,$contactIncoming,$contactOutgoing) as $existing)if((int)$existing['id']===(int)$person['id'])$already=true; ?>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px 0"><a href="./?page=main&amp;view=profile&amp;player=<?= (int)$person['id'] ?>"><?= e($person['login']) ?></a>
        <?php if($already): ?><span class="muted">Znajomy lub zaproszenie oczekujące</span><?php elseif(!$activeTravel): ?><form method="post" action="./?page=main&amp;view=contacts&amp;contacts_tab=friends"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="friend_send"><input type="hidden" name="target" value="<?= (int)$person['id'] ?>"><button class="button" type="submit">Zaproś</button></form><?php endif; ?></div><?php endforeach; endif; ?>
        <?php foreach([['Znajomi',$contactFriends,'player_block'=>'Zablokuj','friend_remove'=>'Usuń znajomego'],['Otrzymane zaproszenia',$contactIncoming,'friend_accept'=>'Akceptuj','friend_reject'=>'Odrzuć'],['Wysłane zaproszenia',$contactOutgoing,'friend_cancel'=>'Anuluj']] as $group): $heading=$group[0];$people=$group[1];unset($group[0],$group[1]); ?>
        <h3 style="margin-top:25px"><?= e($heading) ?> (<?= count($people) ?>)</h3><?php if(!$people): ?><p class="muted">Brak.</p><?php endif; ?>
        <?php foreach($people as $person): ?><div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding:10px 0;border-bottom:1px solid #333"><a href="./?page=main&amp;view=profile&amp;player=<?= (int)$person['id'] ?>"><?= e($person['login']) ?></a><?php if(!$activeTravel): ?><div style="display:flex;gap:8px;flex-wrap:wrap"><?php if($heading==='Znajomi' && $contactGangLeaderId): $gangInvitePending=in_array((int)$person['id'],$contactGangPendingInvites,true); ?><form method="post" action="./?page=main&amp;view=contacts&amp;contacts_tab=friends"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="<?= $gangInvitePending?'gang_invite_cancel':'gang_invite' ?>"><input type="hidden" name="target" value="<?= (int)$person['id'] ?>"><button class="button<?= $gangInvitePending?' secondary':'' ?>" type="submit"><?= $gangInvitePending?'Anuluj zaproszenie':'Zaproś do gangu' ?></button></form><?php endif; ?><?php foreach($group as $action=>$label): ?><form method="post" action="./?page=main&amp;view=contacts&amp;contacts_tab=friends"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="<?= e($action) ?>"><input type="hidden" name="target" value="<?= (int)$person['id'] ?>"><button class="button secondary" type="submit"><?= e($label) ?></button></form><?php endforeach; ?><a class="button" href="./?page=main&amp;view=contacts&amp;contacts_tab=messages&amp;chat=<?= (int)$person['id'] ?>">Napisz PW</a></div><?php endif; ?></div><?php endforeach; endforeach; ?>
      <?php endif; ?>
    </section>
    <?php elseif (($_GET['view'] ?? '') === 'market'): ?>
    <section class="card location-panel">
      <a class="button secondary back-button" href="<?= $activeTravel ? './?page=main&view=travel' : './?page=main' ?>">← Powrót do menu</a>
      <h2>Rynek</h2><p class="muted">Ta zakładka jest w przygotowaniu.</p>
    </section>
    <?php elseif ($showWanted): ?>
    <section class="card location-panel">
      <a class="button secondary back-button" href="<?= $activeTravel ? './?page=main&view=travel' : './?page=main' ?>">← Powrót do menu</a>
      <h2>WANTED — dzień <?= (int)$gameState['game_day'] ?></h2>
      <?php if ($wantedMessage !== ''): ?><p class="stat"><?= e($wantedMessage) ?></p><?php endif; ?>
      <?php if ($wantedKilled && $wantedLeader): ?>
        <div class="stat"><strong>POSZUKIWANY ZABITY</strong><span><?= e($wantedLeader['login']) ?></span><span>Nagroda odebrana — dzisiaj nie ma kolejnego celu.</span></div>
      <?php elseif ($wantedLeader): ?>
        <div class="stat">
          <strong><a href="./?page=main&amp;view=profile&amp;player=<?= (int)$wantedLeader['id'] ?>"><?= e($wantedLeader['login']) ?></a></strong>
          <span>Respekt zdobyty w zakończonym dniu: +<?= number_format((int)$wantedLeader['gained'],0,'.',' ') ?></span>
          <strong>Nagroda: <?= number_format($wantedReward,0,'.',' ') ?> $</strong>
        </div>
        <?php if ((int)$wantedLeader['id']===(int)$user['id']): ?>
          <p class="muted">Nie możesz zaatakować samego siebie.</p>
        <?php elseif ((int)$gameState['is_break']===1): ?>
          <p class="muted">Podczas przerwy między sezonami ataki są wyłączone.</p>
        <?php elseif ($activeTravel): ?>
          <p class="muted">Atak niedostępny podczas podróży.</p>
        <?php else: ?>
          <form method="post" action="./?page=main&amp;view=wanted">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="wanted_kill">
            <input type="hidden" name="target_id" value="<?= (int)$wantedLeader['id'] ?>">
            <button type="submit">Zabij poszukiwanego i odbierz nagrodę</button>
          </form>
        <?php endif; ?>
      <?php else: ?>
        <p class="muted">Brak gracza.</p>
      <?php endif; ?>

      <h3 style="margin-top:28px">Najgroźniejszy morderca dnia</h3>
      <?php if ($killerMessage!==''): ?><p class="stat"><?= e($killerMessage) ?></p><?php endif; ?>
      <?php if ($killerKilled && $killerLeader): ?>
        <div class="stat"><strong>CEL ZABITY</strong><span><?= e($killerLeader['login']) ?></span><span>Nagroda została odebrana.</span></div>
      <?php elseif ($killerLeader): ?>
        <div class="stat">
          <strong><a href="./?page=main&amp;view=profile&amp;player=<?= (int)$killerLeader['id'] ?>"><?= e($killerLeader['login']) ?></a></strong>
          <span>Zabójstwa w zakończonym dniu: <?= (int)($killerBounty['kill_count']??$killerLeader['kill_count']??0) ?></span>
          <strong>Nagroda: <?= number_format($killerReward,0,'.',' ') ?> $</strong>
        </div>
        <?php if ((int)$killerLeader['id']===(int)$user['id']): ?><p class="muted">Nie możesz zaatakować samego siebie.</p>
        <?php elseif ($break): ?><p class="muted">Podczas przerwy między sezonami ataki są wyłączone.</p>
        <?php elseif ($activeTravel): ?><p class="muted">Atak niedostępny podczas podróży.</p>
        <?php else: ?><form method="post" action="./?page=main&amp;view=wanted"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="wanted_killer_kill"><input type="hidden" name="target_id" value="<?= (int)$killerLeader['id'] ?>"><button type="submit">Zabij mordercę i odbierz nagrodę</button></form><?php endif; ?>
      <?php else: ?>
        <p class="muted">Brak gracza.</p>
      <?php endif; ?>
    </section>
    <?php elseif ($showMissions): ?>
    <section class="card location-panel">
        <a class="button secondary back-button" href="<?= $activeTravel ? './?page=main&view=travel' : './?page=main' ?>">← Powrót do menu</a>
        <h2>Misje</h2>
        <p class="muted">Wykonuj zadania, odbieraj nagrody i odblokowuj nowe lokacje.</p>
        <?php if ($missionMessage !== ''): ?><p class="stat"><?= e($missionMessage) ?></p><?php endif; ?>
        <?php if ($travelMissionMessage !== ''): ?><p class="stat"><?= e($travelMissionMessage) ?></p><?php endif; ?>
        <h3>Misje podróżnicze</h3>
        <p class="muted">Miasto startowe jest odblokowane. Zdobywaj respekt i odblokowuj pozostałe miasta.</p>
        <div class="travel-grid">
        <?php foreach (array_keys($travelCities) as $cityIndex=>$city):
            $required=200+100*$cityIndex;
            $cityUnlocked=isset($unlockedCities[$city]);
        ?>
          <article class="mission">
            <h3><?= e($city) ?> <?= $cityUnlocked ? '✓' : '🔒' ?></h3>
            <?php if ($cityUnlocked): ?>
              <strong>Odblokowane</strong>
            <?php else: ?>
              <p>Wymagany respekt: <?= number_format($required,0,'.',' ') ?></p>
              <progress value="<?= min((int)$stats['respect'],$required) ?>" max="<?= $required ?>"></progress>
              <?php if ((int)$stats['respect'] >= $required && !$activeTravel): ?>
              <form method="post" action="./?page=main&amp;view=missions">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="unlock_city">
                <input type="hidden" name="city" value="<?= e($city) ?>">
                <button type="submit">Odblokuj miasto</button>
              </form>
              <?php else: ?><p class="muted"><?= $activeTravel ? 'Dostępne po podróży' : 'Zdobądź wymagany respekt' ?></p><?php endif; ?>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
        </div>
        <h3>Pozostałe misje</h3>
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
            <?php elseif ($respectOk && $cashOk && $activeTravel): ?>
                <p class="muted">Nagrodę odbierzesz po zakończeniu podróży.</p>
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
    <?php
    // Approximate positions on a schematic outline of Poland; display only (travel comes later).
    $db->exec('CREATE TABLE IF NOT EXISTS city_raids (city VARCHAR(64) PRIMARY KEY, active TINYINT NOT NULL DEFAULT 0)');
    $raidCities = $db->query('SELECT city FROM city_raids WHERE active=1')->fetchAll(PDO::FETCH_COLUMN);
    $unavailableCities = array_values(array_diff(array_keys($travelCities), array_keys($unlockedCities))); // Te same blokady co w podróżach i misjach.
    $mapCities = [
      ['Szczecin', 105, 163], ['Gdańsk', 260, 65], ['Olsztyn', 348, 126],
      ['Białystok', 445, 184], ['Bydgoszcz', 236, 184], ['Poznań', 168, 244],
      ['Warszawa', 350, 254], ['Łódź', 280, 298], ['Wrocław', 178, 352],
      ['Lublin', 428, 330], ['Kielce', 349, 373], ['Katowice', 274, 417],
      ['Kraków', 330, 446], ['Rzeszów', 419, 435],
    ];
    ?>
    <section class="card city-map" aria-label="Mapa miast Polski">
      <h2>Mapa Polski</h2>
      <p class="muted">Jesteś w: <strong><?= e((string)($stats['current_city'] ?? 'Nie wybrano')) ?></strong></p>

      <svg viewBox="0 0 550 510" role="img" aria-label="Schematyczna mapa Polski z zaznaczonym aktualnym miastem i pozostałymi miastami gry">
        <path d="M79 139 L117 113 153 120 194 91 236 96 258 42 306 55 339 91 384 102 418 130 467 145 480 203 464 256 484 311 453 356 467 400 438 451 405 460 377 440 345 472 308 457 274 469 242 441 203 447 168 420 139 390 109 366 91 328 62 303 78 259 59 219 77 184 Z" fill="#252e38" stroke="#637589" stroke-width="3" stroke-linejoin="round"/>
        <?php foreach ($mapCities as [$mapCity, $mx, $my]): $isHere = mb_strtolower((string)($stats['current_city'] ?? '')) === mb_strtolower($mapCity); $isRaid = in_array($mapCity, $raidCities, true); $isUnavailable = in_array($mapCity, $unavailableCities, true); $cityColor = $isHere ? '#ffd400' : ($isUnavailable ? '#80858e' : '#51a8ff'); ?>
          <g data-raid-city="<?= e($mapCity) ?>">
            <?php if ($isHere): ?><circle cx="<?= $mx ?>" cy="<?= $my ?>" r="14" fill="#ffd400" opacity=".24"/><?php endif; ?>
            <circle class="raid-ring" cx="<?= $mx ?>" cy="<?= $my ?>" r="11" fill="none" stroke="#ff4545" stroke-width="3" style="<?= $isRaid ? "" : "display:none" ?>"/>
            <circle cx="<?= $mx ?>" cy="<?= $my ?>" r="8" fill="<?= $cityColor ?>" stroke="#121820" stroke-width="2"/>
            <?php if ($isRaid): ?><title>Obława: <?= e($mapCity) ?></title><?php elseif ($isUnavailable): ?><title>Miasto niedostępne: <?= e($mapCity) ?></title><?php endif; ?>
            <text class="map-label<?= $isHere ? ' current' : '' ?>" x="<?= $mx ?>" y="<?= $my - 11 ?>" text-anchor="middle"><?= e($mapCity) ?></text>
          </g>
        <?php endforeach; ?>
      </svg>
    </section>
    <script>
    (() => {
      const map = document.querySelector('.city-map');
      if (!map) return;
      const refresh = async () => {
        if (document.hidden) return;
        try {
          const response = await fetch('./?page=raids', {cache:'no-store',credentials:'same-origin'});
          if (!response.ok) return;
          const data = await response.json();
          const active = new Set(data.cities || []);
          map.querySelectorAll('[data-raid-city]').forEach(group => {
            const on = active.has(group.dataset.raidCity);
            const ring = group.querySelector('.raid-ring');
            if (ring) ring.style.display = on ? '' : 'none';
          });
        } catch (_) {}
      };
      refresh();
      setInterval(refresh, 3000);
      document.addEventListener('visibilitychange', refresh);
    })();
    </script>
    <section class="location-grid<?= $activeTravel ? ' traveling' : '' ?>" aria-label="Lokacje gry">
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
        <a class="button secondary back-button" href="<?= $activeTravel ? './?page=main&view=travel' : './?page=main' ?>">← Powrót do menu</a>
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
          <h3>Napad na taksówkę</h3>
          <p class="muted">Koszt: 10% energii · Moc rabunku: 25–30 · Nagroda: 50–100 $ i +2–4 do każdej statystyki. Możesz próbować z dowolną mocą — zbyt niska moc zwiększa ryzyko niepowodzenia i utraty statystyk.</p>
          <p class="muted">Twoja moc rabunku: <strong><?= $robberyPower ?></strong></p>
          <form method="post" action="./?page=main&amp;location=ulica">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="street_taxi_robbery">
            <button type="submit" <?= !$stats || (int)$stats['energy'] < 10 ? 'disabled' : '' ?>>Napadnij na taksówkę</button>
          </form>
        <?php elseif ($selectedLocation === 'gang'): ?>
          <?php if ($gangMessage !== ''): ?><p class="stat"><?= e($gangMessage) ?></p><?php endif; ?>
          <?php if (!$gang): ?>
            <p class="muted">Nie należysz jeszcze do żadnego gangu. Założenie własnego gangu jest darmowe.</p>
            <form method="post" action="./?page=main&amp;location=gang">
              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="create_gang">
              <label for="gang-name">Nazwa gangu</label>
              <input id="gang-name" name="name" type="text" minlength="3" maxlength="20" required autocomplete="off" placeholder="np. Warszawska Mafia">
              <p class="muted">Nazwa musi mieć od 3 do 20 znaków i być unikalna.</p>
              <button type="submit">Utwórz gang za darmo</button>
            </form>
          <?php else: ?>
            <h3><?= e($gang['name']) ?></h3>
            <div class="stats">
              <div class="stat"><span>Twoja ranga</span><strong><?= $gang['role']==='boss'?'Lider':'Członek' ?></strong></div>
              <div class="stat"><span>Respekt gangu</span><strong><?= number_format((int)$gang['respect'],0,'.',' ') ?></strong></div>
              <div class="stat"><span>Liczba członków</span><strong><?= count($gangMembers) ?></strong></div>
            </div>
            <h3>Członkowie</h3>
            <?php foreach ($gangMembers as $member): ?>
              <div class="stat"><strong><?= e($member['login']) ?></strong> <span><?= number_format((int)($member['player_respect']??0),0,'.',' ') ?> respektu</span> <span><?= $member['role']==='boss'?'Lider':'Członek' ?></span></div>
            <?php endforeach; ?>
            <?php foreach ($gangInvitedPlayers as $invited): ?>
              <div class="stat"><strong><?= e($invited['login']) ?></strong> <span><?= number_format((int)($invited['player_respect']??0),0,'.',' ') ?> respektu</span> <span>Zaproszony</span></div>
            <?php endforeach; ?>
          <?php endif; ?>
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
        <div class="stat"><span>Sztabki złota</span><strong><?= number_format((int) ($stats['credits'] ?? 0), 0, '.', ',') ?></strong></div>
                <div class="stat"><span>Respekt</span><strong><?= (int) $stats['respect'] ?> pkt</strong></div>
                <div class="stat"><span>Miejsce</span><strong>#<?= (int) ($playerRank ?? 0) ?></strong></div>
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

/* Lightweight HUD: only change DOM when server values differ. */
(() => {
    const labels = {
        'Kasa': ['cash', v => Number(v).toLocaleString('en-US') + ' $'],
        'Energia': ['energy', v => v + '%'],
        'Bilety': ['tickets', v => v + '/25'],
        'Respekt': ['respect', v => v + ' pkt'],
        'Sztabki złota': ['credits', v => Number(v).toLocaleString('en-US')],
        'Siła': ['strength', String],
        'Wytrzymałość': ['endurance', String],
        'Inteligencja': ['intelligence', String],
        'Charyzma': ['charisma', String],
        'Spryt': ['cunning', String],
        'Miasto': ['current_city', v => v || 'Nie wybrano'],
        'Profesja': ['profession', v => v || 'Nie wybrano']
    };
    let busy = false;
    let lastVersion = '';
    const refreshStats = async () => {
        if (busy || document.hidden) return;
        busy = true;
        try {
            const response = await fetch('./?page=live_stats' + (lastVersion ? '&version=' + encodeURIComponent(lastVersion) : ''), {credentials: 'same-origin', cache: 'no-store'});
            if (!response.ok) return;
            const data = await response.json();
            if (!data.player || data.version === lastVersion) return;
            lastVersion = data.version;
            document.querySelectorAll('.stats .stat, .game-state .stat').forEach(tile => {
                const label = tile.querySelector('span')?.textContent.trim();
                const target = tile.querySelector('strong');
                if (!label || !target) return;
                let next;
                if (labels[label]) {
                    const [key, format] = labels[label];
                    next = format(data.player[key]);
                } else if (label === 'Miejsce w rankingu' || label === 'Miejsce') next = '#' + (data.rank ?? 0);
                else if (label === 'Dzień gry') next = data.game.game_day + '/60';
                else if (label === 'Sezon gry') next = String(data.game.season);
                if (next !== undefined && target.textContent !== next) target.textContent = next;
            });
            const button = document.querySelector('form input[name="action"][value="street_grocery_robbery"]')?.form?.querySelector('button[type="submit"]');
            if (button) button.disabled = data.player.energy < 5;
            const countdown = document.getElementById('rankingCountdown');
            if (countdown && data.game.next_ranking_update) countdown.dataset.next = data.game.next_ranking_update;
        } catch (_) { /* Keep existing values during a network error. */ }
        finally { busy = false; }
    };
    // Lightweight AJAX version check; unchanged data returns HTTP 204.
    // Paused when the tab is hidden.
    setInterval(refreshStats, 5000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshStats(); });
    window.addEventListener('focus', refreshStats);
    refreshStats();
})();
const menuToggle = document.getElementById('menuToggle');
const gameNav = document.getElementById('gameNav');
const rankingCountdown = document.getElementById('rankingCountdown');
if (rankingCountdown) {
    const tick = () => {
        const next = new Date(rankingCountdown.dataset.next.replace(' ', 'T')).getTime();
        const diff = Math.max(0, next - Date.now());
        const hours = Math.floor(diff / 3600000);
        const minutes = Math.floor((diff % 3600000) / 60000);
        const seconds = Math.floor((diff % 60000) / 1000);
        rankingCountdown.textContent = String(hours).padStart(2, '0') + ':' + String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
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
