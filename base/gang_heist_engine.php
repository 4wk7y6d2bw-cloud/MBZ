<?php
declare(strict_types=1);

/**
 * MBZ gang heists: two accepted players trigger the convoy automatically.
 * The creator is accepted on creation. All gang members share the penalties
 * on failure, including members who did not accept. Rewards go only to
 * the accepted participants.
 */
const GANG_CONVOY_MIN_MEMBERS = 2;
const GANG_CONVOY_STAT_POOL = 40;
const GANG_CONVOY_ENERGY_COST = 10;

function gang_heist_install(PDO $db): void
{
    // Run ONLY before transactions: MySQL CREATE TABLE implicitly commits.
    $db->exec("CREATE TABLE IF NOT EXISTS gang_heist_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        heist_id INT NOT NULL,
        gang_id INT UNSIGNED NOT NULL,
        user_id INT NOT NULL,
        result VARCHAR(16) NOT NULL,
        message TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY gang_heist_events_once (heist_id,user_id),
        KEY gang_heist_events_user (user_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function gang_heist_player_gang(PDO $db, int $userId): ?array
{
    $stmt = $db->prepare("SELECT g.id,g.name,g.owner_id,gm.role,
        (SELECT COUNT(*) FROM gang_members gm2 WHERE gm2.gang_id=g.id) AS member_count
        FROM gangs g JOIN gang_members gm ON gm.gang_id=g.id
        WHERE gm.user_id=? LIMIT 1");
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function gang_heist_snapshot(PDO $db, int $userId): array
{
    $gang = gang_heist_player_gang($db,$userId);
    if (!$gang) {
        return ['gang'=>null,'active'=>null,'latest_event'=>null,'jailed'=>false];
    }
    $gangId = (int)$gang['id'];
    $stmt = $db->prepare("SELECT h.id,h.created_by,u.login AS creator
        FROM gang_heists h JOIN users u ON u.id=h.created_by
        WHERE h.gang_id=? AND h.status='recruiting' ORDER BY h.id DESC LIMIT 1");
    $stmt->execute([$gangId]);
    $heist = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $active = null;
    if ($heist) {
        $list = $db->prepare("SELECT u.id,u.login
            FROM gang_heist_participants hp
            JOIN gang_members gm ON gm.user_id=hp.user_id AND gm.gang_id=?
            JOIN users u ON u.id=hp.user_id
            WHERE hp.heist_id=? AND hp.status='accepted'
            ORDER BY hp.joined_at,u.id");
        $list->execute([$gangId,(int)$heist['id']]);
        $accepted = array_map(static function (array $p): array {
            return ['id'=>(int)$p['id'],'login'=>(string)$p['login']];
        }, $list->fetchAll(PDO::FETCH_ASSOC));
        $joined = false;
        foreach ($accepted as $participant) {
            if ($participant['id']===$userId) $joined=true;
        }
        $active = [
            'id'=>(int)$heist['id'],
            'creator'=>(string)$heist['creator'],
            'created_by'=>(int)$heist['created_by'],
            'accepted'=>$accepted,
            'accepted_count'=>count($accepted),
            'required'=>GANG_CONVOY_MIN_MEMBERS,
            'joined'=>$joined,
        ];
    }

    $eventStmt = $db->prepare("SELECT id,heist_id,result,message,created_at
        FROM gang_heist_events
        WHERE user_id=? AND gang_id=? AND created_at>=DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ORDER BY id DESC LIMIT 1");
    $eventStmt->execute([$userId,$gangId]);
    $event = $eventStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($event) {
        $event['id']=(int)$event['id'];
        $event['heist_id']=(int)$event['heist_id'];
    }
    $jailStmt=$db->prepare("SELECT 1 FROM player_jail WHERE user_id=? AND jailed_until>NOW() LIMIT 1");
    $jailStmt->execute([$userId]);

    return [
        'gang'=>[
            'id'=>$gangId,
            'name'=>(string)$gang['name'],
            'member_count'=>(int)$gang['member_count'],
            'required'=>GANG_CONVOY_MIN_MEMBERS
        ],
        'active'=>$active,
        'latest_event'=>$event,
        'jailed'=>(bool)$jailStmt->fetchColumn(),
    ];
}

/** Serialize all starts/acceptances through the gang row lock. */
function gang_heist_action(PDO $db, int $userId, string $action, int $season, int $gameDay): string
{
    if (!in_array($action,['create','accept'],true)) {
        throw new InvalidArgumentException('Nieznana akcja skoku.');
    }
    $db->beginTransaction();
    try {
        $gangStmt=$db->prepare("SELECT g.id,g.name
            FROM gangs g JOIN gang_members gm ON gm.gang_id=g.id
            WHERE gm.user_id=? LIMIT 1 FOR UPDATE");
        $gangStmt->execute([$userId]);
        $gang=$gangStmt->fetch(PDO::FETCH_ASSOC);
        if (!$gang) throw new RuntimeException('Nie należysz do gangu.');
        $gangId=(int)$gang['id'];

        $countStmt=$db->prepare('SELECT COUNT(*) FROM gang_members WHERE gang_id=?');
        $countStmt->execute([$gangId]);
        $memberCount=(int)$countStmt->fetchColumn();

        $activeStmt=$db->prepare("SELECT id,created_by FROM gang_heists
            WHERE gang_id=? AND status='recruiting' ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $activeStmt->execute([$gangId]);
        $heist=$activeStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($action==='create') {
            if ($heist) throw new RuntimeException('Skok już jest przygotowywany. Poczekaj na akceptację.');
            if ($memberCount<GANG_CONVOY_MIN_MEMBERS) {
                throw new RuntimeException('Za mało członków gangu. Wymaganych: '.GANG_CONVOY_MIN_MEMBERS.'. Aktualnie: '.$memberCount.'.');
            }
            $insert=$db->prepare("INSERT INTO gang_heists(gang_id,created_by,heist_type,status)
                VALUES (?,?,'convoy','recruiting')");
            $insert->execute([$gangId,$userId]);
            $heistId=(int)$db->lastInsertId();
            $db->prepare("INSERT INTO gang_heist_participants(heist_id,user_id,status,robbery_power)
                VALUES (?,?,'accepted',0)")->execute([$heistId,$userId]);
            $db->commit();
            return 'Skok przygotowany. Automatycznie zaakceptowałeś udział; pozostali członkowie mogą dołączyć.';
        }

        if (!$heist) throw new RuntimeException('Brak skoku oczekującego na akceptację.');
        if ($memberCount<GANG_CONVOY_MIN_MEMBERS) {
            throw new RuntimeException('Gang nie ma wymaganej liczby członków.');
        }
        $heistId=(int)$heist['id'];
        $acceptedStmt=$db->prepare("SELECT 1 FROM gang_heist_participants
            WHERE heist_id=? AND user_id=? AND status='accepted' LIMIT 1");
        $acceptedStmt->execute([$heistId,$userId]);
        if ($acceptedStmt->fetchColumn()) {
            $db->commit();
            return 'Już zaakceptowałeś udział w tym skoku.';
        }
        $db->prepare("INSERT INTO gang_heist_participants(heist_id,user_id,status,robbery_power)
            VALUES (?,?,'accepted',0)
            ON DUPLICATE KEY UPDATE status='accepted',robbery_power=0")
            ->execute([$heistId,$userId]);

        $acceptedList=$db->prepare("SELECT hp.user_id FROM gang_heist_participants hp
            JOIN gang_members gm ON gm.user_id=hp.user_id AND gm.gang_id=?
            WHERE hp.heist_id=? AND hp.status='accepted' ORDER BY hp.user_id");
        $acceptedList->execute([$gangId,$heistId]);
        $participantIds=array_map('intval',$acceptedList->fetchAll(PDO::FETCH_COLUMN));
        if (count($participantIds)<GANG_CONVOY_MIN_MEMBERS) {
            $db->commit();
            return 'Udział zaakceptowany. Skok czeka na pozostałych uczestników.';
        }

        // Check current energy, not an old snapshot, for all approved players.
        foreach ($participantIds as $participantId) {
            regenerate_player_energy($db,$participantId);
        }
        $membersStmt=$db->prepare("SELECT gm.user_id,ps.energy,ps.cash,ps.profession,
            ps.strength,ps.endurance,ps.intelligence,ps.charisma,ps.cunning
            FROM gang_members gm JOIN player_stats ps ON ps.user_id=gm.user_id
            WHERE gm.gang_id=? ORDER BY gm.user_id FOR UPDATE");
        $membersStmt->execute([$gangId]);
        $members=$membersStmt->fetchAll(PDO::FETCH_ASSOC);
        $participants=array_fill_keys($participantIds,true);
        $failed=false;
        foreach ($members as $member) {
            $memberId=(int)$member['user_id'];
            if (!isset($participants[$memberId])) continue;
            $power=(int)floor((int)$member['strength']*.35
                +(int)$member['endurance']*.20
                +(int)$member['intelligence']*.15
                +(int)$member['charisma']*.10
                +(int)$member['cunning']*.20);
            $threshold=random_int(50,60);
            if ($power<$threshold || (int)$member['energy']<GANG_CONVOY_ENERGY_COST) $failed=true;
            $db->prepare('UPDATE gang_heist_participants SET robbery_power=?
                WHERE heist_id=? AND user_id=?')->execute([$power,$heistId,$memberId]);
            $db->prepare('UPDATE player_stats SET energy=GREATEST(0,energy-?)
                WHERE user_id=?')->execute([GANG_CONVOY_ENERGY_COST,$memberId]);
        }

        if ($failed) {
            // Deliberate gameplay rule: EVERY current gang member is punished.
            foreach ($members as $member) {
                $memberId=(int)$member['user_id'];
                $loss=apply_robbery_stat_loss($db,$memberId,$season,GANG_CONVOY_STAT_POOL);
                $respectStmt=$db->prepare("SELECT FLOOR(GREATEST(0,cash)/10)+
                    FLOOR((GREATEST(0,strength)+GREATEST(0,endurance)+GREATEST(0,intelligence)+
                    GREATEST(0,charisma)+GREATEST(0,cunning))/20)
                    FROM player_stats WHERE user_id=?");
                $respectStmt->execute([$memberId]);
                $respect=max(1,(int)$respectStmt->fetchColumn());
                $base=max(100,5*$respect);
                $bribe=random_int(max(1,(int)floor($base*.70)),max(2,(int)ceil($base*1.40)));
                $db->prepare("INSERT INTO player_jail(user_id,jailed_until,bribe_target)
                    VALUES (?,DATE_ADD(NOW(),INTERVAL 60 MINUTE),?)
                    ON DUPLICATE KEY UPDATE jailed_until=VALUES(jailed_until),
                    bribe_target=VALUES(bribe_target),created_at=NOW()")
                    ->execute([$memberId,$bribe]);
                $msg='Skok gangu nieudany. Jeden z zaakceptowanych uczestników nie spełnił wymagań mocy (50–60) lub energii (10%). '
                    .'Wszyscy członkowie gangu trafili do więzienia na 60 minut. '.robbery_stat_loss_notice($loss);
                gang_heist_store_event($db,$heistId,$gangId,$memberId,'failed',$msg);
            }
            $db->prepare("UPDATE gang_heists SET status='failed',resolved_at=NOW() WHERE id=?")
               ->execute([$heistId]);
            $db->commit();
            return 'Skok wykonał się automatycznie, ale nie powiódł się. Cały gang trafił do więzienia.';
        }

        foreach ($members as $member) {
            $memberId=(int)$member['user_id'];
            if (!isset($participants[$memberId])) {
                gang_heist_store_event($db,$heistId,$gangId,$memberId,'completed',
                    'Skok na konwój zakończony powodzeniem. Nie brałeś udziału, więc nie otrzymałeś nagrody.');
                continue;
            }
            $profession=(string)$member['profession'];
            $cash=profession_robbery_cash($profession,random_int(500,700));
            $rewards=profession_robbery_stat_rewards($db,$memberId,$season,$profession,8);
            $points=$rewards['points'];
            $db->prepare('UPDATE player_stats SET cash=cash+?,
                strength=strength+?,endurance=endurance+?,intelligence=intelligence+?,
                charisma=charisma+?,cunning=cunning+? WHERE user_id=?')
               ->execute([$cash,$points['strength'],$points['endurance'],
                   $points['intelligence'],$points['charisma'],$points['cunning'],$memberId]);
            $db->prepare('UPDATE gang_heist_participants SET reward_cash=?,reward_stats=?
                WHERE heist_id=? AND user_id=?')->execute([$cash,8,$heistId,$memberId]);
            $credit=award_first_daily_robbery_credit($db,$memberId,$season,$gameDay);
            $msg='Skok na konwój udany! +'.$cash.' $, +'.$points['strength'].' siły, +'.
                $points['endurance'].' wytrzymałości, +'.$points['intelligence'].' inteligencji, +'.
                $points['charisma'].' charyzmy, +'.$points['cunning'].' sprytu. Łącznie +40 pkt.'.
                ($credit?' Pierwszy udany rabunek dnia: +1 sztabka złota.':'');
            gang_heist_store_event($db,$heistId,$gangId,$memberId,'completed',$msg);
        }
        $db->prepare("UPDATE gang_heists SET status='completed',resolved_at=NOW() WHERE id=?")
           ->execute([$heistId]);
        $db->commit();
        return 'Zaakceptowałeś udział. Skok został automatycznie wykonany!';
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function gang_heist_store_event(PDO $db,int $heistId,int $gangId,int $memberId,string $result,string $message): void
{
    $stmt=$db->prepare('INSERT INTO gang_heist_events(heist_id,gang_id,user_id,result,message)
        VALUES (?,?,?,?,?)');
    $stmt->execute([$heistId,$gangId,$memberId,$result,$message]);
}
