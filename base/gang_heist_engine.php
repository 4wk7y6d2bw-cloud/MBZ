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

/**
 * Required ACCEPTED participants for each heist type, not the number of
 * members in the gang. Extend this map when another heist is introduced:
 * for instance, a future heist may require 10 confirmations in a 16-member gang.
 */
function gang_heist_required_acceptances(string $heistType): int
{
    $requirements = ['convoy' => GANG_CONVOY_MIN_MEMBERS];
    return max(2, (int)($requirements[$heistType] ?? GANG_CONVOY_MIN_MEMBERS));
}

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

/**
 * Remove leftovers from gang records deleted outside the game (for instance
 * manually in phpMyAdmin). The caller must be outside a DB transaction.
 *
 * Important: only children that reference non-existent parents are removed.
 * Existing gangs, their participants, heists, and histories are untouched.
 */
function gang_heist_cleanup_orphans(PDO $db): void
{
    if ($db->inTransaction()) {
        throw new LogicException('Czyszczenie osieroconych skoków wymaga zakończonej transakcji.');
    }

    // Avoid expensive DELETEs while most gangs and raids are healthy.
    $orphanChecks = [
        "SELECT 1 FROM gang_heists h LEFT JOIN gangs g ON g.id=h.gang_id WHERE g.id IS NULL LIMIT 1",
        "SELECT 1 FROM gang_heist_participants p LEFT JOIN gang_heists h ON h.id=p.heist_id WHERE h.id IS NULL LIMIT 1",
        "SELECT 1 FROM gang_heist_events e LEFT JOIN gang_heists h ON h.id=e.heist_id WHERE h.id IS NULL LIMIT 1",
        "SELECT 1 FROM gang_invites i LEFT JOIN gangs g ON g.id=i.gang_id WHERE g.id IS NULL LIMIT 1",
        "SELECT 1 FROM gang_members m LEFT JOIN gangs g ON g.id=m.gang_id WHERE g.id IS NULL LIMIT 1",
    ];
    $needsCleanup = false;
    foreach ($orphanChecks as $sql) {
        if ($db->query($sql)->fetchColumn()) {
            $needsCleanup = true;
            break;
        }
    }
    if (!$needsCleanup) return;

    $db->beginTransaction();
    try {
        // Delete children first, including events and participants of heists
        // whose parent gang was removed even though the heist row still exists.
        $db->exec("DELETE e FROM gang_heist_events e
            LEFT JOIN gang_heists h ON h.id=e.heist_id
            LEFT JOIN gangs g ON g.id=h.gang_id
            WHERE h.id IS NULL OR g.id IS NULL");
        $db->exec("DELETE p FROM gang_heist_participants p
            LEFT JOIN gang_heists h ON h.id=p.heist_id
            LEFT JOIN gangs g ON g.id=h.gang_id
            WHERE h.id IS NULL OR g.id IS NULL");
        $db->exec("DELETE h FROM gang_heists h
            LEFT JOIN gangs g ON g.id=h.gang_id WHERE g.id IS NULL");
        $db->exec("DELETE i FROM gang_invites i
            LEFT JOIN gangs g ON g.id=i.gang_id WHERE g.id IS NULL");
        $db->exec("DELETE m FROM gang_members m
            LEFT JOIN gangs g ON g.id=m.gang_id WHERE g.id IS NULL");
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/**
 * Future gang-removal actions must use this transaction-safe helper so the
 * gang and ALL associated heists, acceptances, results and invitations are
 * deleted immediately, not left for periodic orphan cleanup. No UI for
 * deleting gangs is exposed by this helper.
 */
/**
 * A member may leave the gang. Only the owner/leader may kick another member.
 * Active heist acceptances are removed atomically with membership. If the
 * pending heist creator departs, the pending heist is cancelled and cleaned up.
 * Past successful heist history is not erased when someone merely leaves.
 */
function gang_heist_remove_member(PDO $db, int $actorId, int $targetId, bool $kick): string
{
    if ($actorId<=0 || $targetId<=0) throw new InvalidArgumentException('Nieprawidłowy gracz.');
    if ($kick && $actorId===$targetId) {
        throw new RuntimeException('Lider nie może wyrzucić samego siebie. Aby odejść, rozwiąż gang.');
    }
    if (!$kick && $actorId!==$targetId) {
        throw new RuntimeException('Możesz opuścić tylko własny gang.');
    }

    // Locate the gang before taking the lock, then revalidate under the lock.
    $find=$db->prepare('SELECT gang_id FROM gang_members WHERE user_id=? LIMIT 1');
    $find->execute([$actorId]);
    $gangId=(int)($find->fetchColumn() ?: 0);
    if ($gangId<=0) throw new RuntimeException('Nie należysz już do żadnego gangu.');

    $db->beginTransaction();
    try {
        // Same root gang lock as automatic heist start/accept and gang deletion.
        $gangLock=$db->prepare('SELECT id,owner_id,name FROM gangs WHERE id=? FOR UPDATE');
        $gangLock->execute([$gangId]);
        $gang=$gangLock->fetch(PDO::FETCH_ASSOC);
        if (!$gang) throw new RuntimeException('Gang nie istnieje.');

        $membership=$db->prepare('SELECT user_id,role FROM gang_members WHERE gang_id=? AND user_id=? FOR UPDATE');
        $membership->execute([$gangId,$actorId]);
        $actor=$membership->fetch(PDO::FETCH_ASSOC);
        if (!$actor) throw new RuntimeException('Nie należysz już do tego gangu.');

        if ($kick) {
            if ((int)$gang['owner_id']!==$actorId || $actor['role']!=='boss') {
                throw new RuntimeException('Tylko lider może wyrzucać członków.');
            }
        } elseif ((int)$gang['owner_id']===$actorId || $actor['role']==='boss') {
            throw new RuntimeException('Lider nie może opuścić gangu. Może go rozwiązać.');
        }

        $member=$db->prepare('SELECT gm.user_id,gm.role,u.login FROM gang_members gm
            JOIN users u ON u.id=gm.user_id
            WHERE gm.gang_id=? AND gm.user_id=? FOR UPDATE');
        $member->execute([$gangId,$targetId]);
        $target=$member->fetch(PDO::FETCH_ASSOC);
        if (!$target) throw new RuntimeException('Ten gracz nie należy już do twojego gangu.');
        if ((int)$gang['owner_id']===$targetId || $target['role']==='boss') {
            throw new RuntimeException('Nie można wyrzucić lidera gangu.');
        }

        // If the creator leaves, the pending raid must not remain under the
        // control of a person who is no longer in this gang.
        $db->prepare('DELETE e FROM gang_heist_events e JOIN gang_heists h ON h.id=e.heist_id
            WHERE h.gang_id=? AND h.status=\'recruiting\' AND h.created_by=?')
           ->execute([$gangId,$targetId]);
        $db->prepare('DELETE p FROM gang_heist_participants p JOIN gang_heists h ON h.id=p.heist_id
            WHERE h.gang_id=? AND h.status=\'recruiting\' AND h.created_by=?')
           ->execute([$gangId,$targetId]);
        $db->prepare("DELETE FROM gang_heists
            WHERE gang_id=? AND status='recruiting' AND created_by=?")
           ->execute([$gangId,$targetId]);

        // Any other awaiting raid loses this player's acceptance.
        $db->prepare('DELETE p FROM gang_heist_participants p JOIN gang_heists h ON h.id=p.heist_id
            WHERE h.gang_id=? AND h.status=\'recruiting\' AND p.user_id=?')
           ->execute([$gangId,$targetId]);
        $del=$db->prepare('DELETE FROM gang_members WHERE gang_id=? AND user_id=? AND role=\'member\'');
        $del->execute([$gangId,$targetId]);
        if ($del->rowCount()!==1) throw new RuntimeException('Nie można usunąć członka gangu.');

        $db->commit();
        return $kick
            ? 'Gracz '.$target['login'].' został wyrzucony z gangu.'
            : 'Opuściłeś gang '.$gang['name'].'.';
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/** Delete the gang and all subordinate records as one transaction. */
function gang_heist_delete_gang(PDO $db, int $gangId, int $leaderId, string $confirmation): void
{
    if ($gangId<=0 || $leaderId<=0) {
        throw new InvalidArgumentException('Nieprawidłowy gang lub lider.');
    }
    $db->beginTransaction();
    try {
        $gang=$db->prepare("SELECT id,name FROM gangs WHERE id=? AND owner_id=? FOR UPDATE");
        $gang->execute([$gangId,$leaderId]);
        $row=$gang->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Tylko lider może usunąć swój istniejący gang.');
        }
        if (!hash_equals((string)$row['name'],$confirmation)) {
            throw new RuntimeException('Wpisz dokładną nazwę gangu, aby potwierdzić usunięcie.');
        }
        $owner=$db->prepare("SELECT 1 FROM gang_members
            WHERE gang_id=? AND user_id=? AND role='boss' FOR UPDATE");
        $owner->execute([$gangId,$leaderId]);
        if (!$owner->fetchColumn()) throw new RuntimeException('Nie masz uprawnień lidera.');

        $db->prepare('DELETE FROM gang_heist_events WHERE gang_id=?')->execute([$gangId]);
        $db->prepare('DELETE p FROM gang_heist_participants p
            JOIN gang_heists h ON h.id=p.heist_id WHERE h.gang_id=?')->execute([$gangId]);
        $db->prepare('DELETE FROM gang_heists WHERE gang_id=?')->execute([$gangId]);
        $db->prepare('DELETE FROM gang_invites WHERE gang_id=?')->execute([$gangId]);
        $db->prepare('DELETE FROM gang_members WHERE gang_id=?')->execute([$gangId]);
        $delete=$db->prepare('DELETE FROM gangs WHERE id=? AND owner_id=?');
        $delete->execute([$gangId,$leaderId]);
        if ($delete->rowCount()!==1) throw new RuntimeException('Nie można usunąć gangu.');
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
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
    $stmt = $db->prepare("SELECT h.id,h.heist_type,h.created_by,u.login AS creator
        FROM gang_heists h JOIN users u ON u.id=h.created_by
        WHERE h.gang_id=? AND h.status='recruiting' ORDER BY h.id DESC LIMIT 1");
    $stmt->execute([$gangId]);
    $heist = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $active = null;
    if ($heist) {
        // Show EVERY current member of this gang. A participant is accepted
        // only if they have an accepted row for this specific heist.
        // Others appear as waiting; the full gang need not accept.
        $list = $db->prepare("SELECT u.id,u.login,
            CASE WHEN hp.user_id IS NOT NULL THEN 1 ELSE 0 END AS accepted
            FROM gang_members gm
            JOIN users u ON u.id=gm.user_id
            LEFT JOIN gang_heist_participants hp
              ON hp.user_id=gm.user_id AND hp.heist_id=? AND hp.status='accepted'
            WHERE gm.gang_id=?
            ORDER BY accepted DESC,gm.joined_at ASC,u.id ASC");
        $list->execute([(int)$heist['id'],$gangId]);
        $members=[];
        $accepted=[];
        $joined=false;
        foreach ($list->fetchAll(PDO::FETCH_ASSOC) as $person) {
            $isAccepted=((int)$person['accepted']===1);
            $member=[
                'id'=>(int)$person['id'],
                'login'=>(string)$person['login'],
                'status'=>$isAccepted ? 'accepted' : 'waiting',
            ];
            $members[]=$member;
            if ($isAccepted) {
                $accepted[]=$member;
                if ($member['id']===$userId) $joined=true;
            }
        }
        $required=gang_heist_required_acceptances((string)$heist['heist_type']);
        $active=[
            'id'=>(int)$heist['id'],
            'type'=>(string)$heist['heist_type'],
            'creator'=>(string)$heist['creator'],
            'created_by'=>(int)$heist['created_by'],
            'members'=>$members,
            'accepted'=>$accepted,
            'accepted_count'=>count($accepted),
            'waiting_count'=>count($members)-count($accepted),
            'member_count'=>count($members),
            'required'=>$required,
            'remaining'=>max(0,$required-count($accepted)),
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
            'required'=>gang_heist_required_acceptances('convoy')
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

        $activeStmt=$db->prepare("SELECT id,heist_type,created_by FROM gang_heists
            WHERE gang_id=? AND status='recruiting' ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $activeStmt->execute([$gangId]);
        $heist=$activeStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($action==='create') {
            if ($heist) throw new RuntimeException('Skok już jest przygotowywany. Poczekaj na akceptację.');
            $createRequired=gang_heist_required_acceptances('convoy');
            if ($memberCount<$createRequired) {
                throw new RuntimeException('Za mało członków gangu. Wymaganych: '.$createRequired.'. Aktualnie: '.$memberCount.'.');
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
        $required=gang_heist_required_acceptances((string)$heist['heist_type']);
        if ($memberCount<$required) {
            throw new RuntimeException('Gang nie ma wymaganej liczby członków: '.$memberCount.' / '.$required.'.');
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
        if (count($participantIds)<$required) {
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
