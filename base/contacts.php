<?php
// Kontakty: jedna relacja na parę graczy, niezależnie od kierunku zaproszenia.
$contactsMessage = '';
$contactFriends = $contactIncoming = $contactOutgoing = $contactSearchResults = [];
$privateChatFriend = null;
$privateMessages = [];
$privateMessagePage = 1;
$privateMessagePages = 1;
$blockedPlayers = [];
if ($user && $db instanceof PDO && ($_GET['view'] ?? '') === 'contacts') {
    $db->exec("CREATE TABLE IF NOT EXISTS player_contacts (
        user_low INT NOT NULL, user_high INT NOT NULL, requested_by INT NOT NULL,
        status ENUM('pending','accepted') NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY(user_low,user_high), INDEX contacts_high(user_high,status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $me=(int)$user['id'];
    $db->exec("CREATE TABLE IF NOT EXISTS player_blocks (
        blocker_id INT NOT NULL, blocked_id INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(blocker_id,blocked_id), INDEX blocks_blocked(blocked_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS private_messages (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        sender_id INT NOT NULL, receiver_id INT NOT NULL,
        body VARCHAR(500) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX pm_conversation(sender_id,receiver_id,created_at,id),
        INDEX pm_receiver(receiver_id,created_at,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if ($_SERVER['REQUEST_METHOD']==='POST' && in_array($_POST['action']??'', ['friend_send','friend_accept','friend_reject','friend_remove','friend_cancel','private_message_send','player_block','player_unblock'],true)) {
        if (!csrf_valid(is_string($_POST['csrf_token']??null)?$_POST['csrf_token']:null)) {
            $contactsMessage='Sesja wygasła. Odśwież stronę.';
        } else {
            $action=$_POST['action'];
            $target=filter_var($_POST['target']??null,FILTER_VALIDATE_INT);
            if ($target && $target!==$me) {
                $low=min($me,$target);$high=max($me,$target);
                if ($action==='player_block') {
                    $block=$db->prepare('INSERT IGNORE INTO player_blocks(blocker_id,blocked_id) VALUES (?,?)');
                    $block->execute([$me,$target]);
                    $drop=$db->prepare('DELETE FROM player_contacts WHERE user_low=? AND user_high=?');
                    $drop->execute([$low,$high]);
                    $contactsMessage='Gracz został zablokowany.';
                } elseif ($action==='player_unblock') {
                    $unblock=$db->prepare('DELETE FROM player_blocks WHERE blocker_id=? AND blocked_id=?');
                    $unblock->execute([$me,$target]);
                    $contactsMessage=$unblock->rowCount()?'Gracz został odblokowany.':'Ten gracz nie był zablokowany.';
                } elseif ($action==='private_message_send') {
                    $friendCheck=$db->prepare("SELECT 1 FROM player_contacts WHERE user_low=? AND user_high=? AND status='accepted' LIMIT 1");
                    $friendCheck->execute([$low,$high]);
                    $body=trim(is_string($_POST['body']??null)?$_POST['body']:'');
                    $blocked=$db->prepare('SELECT 1 FROM player_blocks WHERE (blocker_id=? AND blocked_id=?) OR (blocker_id=? AND blocked_id=?) LIMIT 1');
                    $blocked->execute([$me,$target,$target,$me]);
                    if (!$friendCheck->fetchColumn()) $contactsMessage='Wiadomości prywatne możesz wysyłać tylko do znajomych.';
                    elseif ($blocked->fetchColumn()) $contactsMessage='Wiadomość niedostępna z powodu blokady.';
                    elseif ($body==='' || mb_strlen($body)>500) $contactsMessage='Wiadomość musi mieć od 1 do 500 znaków.';
                    else {
                        $pm=$db->prepare('INSERT INTO private_messages(sender_id,receiver_id,body) VALUES (?,?,?)');
                        $pm->execute([$me,$target,$body]);
                        $contactsMessage='Wiadomość wysłana.';
                    }
                } elseif ($action==='friend_send') {
                    $blockCheck=$db->prepare('SELECT 1 FROM player_blocks WHERE (blocker_id=? AND blocked_id=?) OR (blocker_id=? AND blocked_id=?) LIMIT 1');
                    $blockCheck->execute([$me,$target,$target,$me]);
                    if ($blockCheck->fetchColumn()) {
                        $contactsMessage='Nie możesz wysłać zaproszenia do tego gracza.';
                        $target=false;
                    }
                    $check=$db->prepare('SELECT id FROM users WHERE id=? AND active=1');
                    $check->execute([$target]);
                    if ($target && $check->fetchColumn()) {
                        $send=$db->prepare("INSERT IGNORE INTO player_contacts(user_low,user_high,requested_by,status) VALUES (?,?,?,'pending')");
                        $send->execute([$low,$high,$me]);
                        $contactsMessage=$send->rowCount()?'Zaproszenie wysłane.':'Zaproszenie lub znajomość już istnieje.';
                    }
                } else {
                    $conditions=[
                        'friend_accept'=>"status='pending' AND requested_by<>?",
                        'friend_reject'=>"status='pending' AND requested_by<>?",
                        'friend_cancel'=>"status='pending' AND requested_by=?",
                        'friend_remove'=>"status='accepted'"
                    ];
                    $condition=$conditions[$action];
                    $sql=($action==='friend_accept'?'UPDATE player_contacts SET status=\'accepted\'':'DELETE FROM player_contacts')
                        ." WHERE user_low=? AND user_high=? AND ".$condition;
                    $params=[$low,$high];
                    if ($action!=='friend_remove')$params[]=$me;
                    $stmt=$db->prepare($sql);$stmt->execute($params);
                    $contactsMessage=$stmt->rowCount()?'Zapisano zmianę.':'Nie znaleziono pasującego zaproszenia lub znajomości.';
                }
            } else $contactsMessage='Nieprawidłowy gracz.';
        }
    }
    $list=$db->prepare("SELECT c.status,c.requested_by,u.id,u.login FROM player_contacts c
      JOIN users u ON u.id=IF(c.user_low=?,c.user_high,c.user_low) AND u.active=1
      WHERE c.user_low=? OR c.user_high=? ORDER BY u.login");
    $list->execute([$me,$me,$me]);
    foreach($list->fetchAll(PDO::FETCH_ASSOC) as $item){
        if($item['status']==='accepted')$contactFriends[]=$item;
        elseif((int)$item['requested_by']===$me)$contactOutgoing[]=$item;
        else $contactIncoming[]=$item;
    }
    $chatId=filter_var($_GET['chat']??null,FILTER_VALIDATE_INT);
    if ($chatId && $chatId!==$me) {
        $low=min($me,$chatId); $high=max($me,$chatId);
        $friend=$db->prepare("SELECT u.id,u.login FROM player_contacts c JOIN users u ON u.id=? AND u.active=1 WHERE c.user_low=? AND c.user_high=? AND c.status='accepted' LIMIT 1");
        $friend->execute([$chatId,$low,$high]);
        $privateChatFriend=$friend->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($privateChatFriend) {
            $messageCount=$db->prepare("SELECT COUNT(*) FROM private_messages WHERE (sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?)");
            $messageCount->execute([$me,$chatId,$chatId,$me]);
            $privateMessagePages=max(1,(int)ceil((int)$messageCount->fetchColumn()/10));
            $privateMessagePage=max(1,min($privateMessagePages,(int)($_GET['msg_page']??1)));
            $messageOffset=($privateMessagePage-1)*10;
            $messages=$db->prepare("SELECT m.id,m.sender_id,m.receiver_id,m.body,m.created_at,u.login AS sender_login
              FROM private_messages m JOIN users u ON u.id=m.sender_id
              WHERE (m.sender_id=? AND m.receiver_id=?) OR (m.sender_id=? AND m.receiver_id=?)
              ORDER BY m.created_at DESC,m.id DESC LIMIT 10 OFFSET ".$messageOffset);
            $messages->execute([$me,$chatId,$chatId,$me]);
            $privateMessages=array_reverse($messages->fetchAll(PDO::FETCH_ASSOC));
        }
    }
    $blockedList=$db->prepare('SELECT u.id,u.login FROM player_blocks b JOIN users u ON u.id=b.blocked_id WHERE b.blocker_id=? ORDER BY u.login');
    $blockedList->execute([$me]);
    $blockedPlayers=$blockedList->fetchAll(PDO::FETCH_ASSOC);
    $search=trim(is_string($_GET['friend_search']??null)?$_GET['friend_search']:'');
    if($search!==''){
        $search=mb_substr($search,0,60);
        $lookup=$db->prepare("SELECT u.id,u.login FROM users u WHERE u.active=1 AND u.id<>? AND u.login LIKE ? ESCAPE '!' ORDER BY u.login LIMIT 20");
        $lookup->execute([$me,'%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%']);
        $contactSearchResults=$lookup->fetchAll(PDO::FETCH_ASSOC);
    }
}
