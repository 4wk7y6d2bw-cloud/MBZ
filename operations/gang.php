<?php
declare(strict_types=1);

if (!user_logged_in()) {
    redirect('./');
}
$user = current_user();
if (!$db instanceof PDO) {
    http_response_code(503);
    exit('Baza danych jest niedostępna.');
}

$db->exec("CREATE TABLE IF NOT EXISTS gangs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(40) NOT NULL,
    tag VARCHAR(8) NOT NULL,
    owner_id INT NOT NULL,
    respect BIGINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY gangs_name_unique (name),
    UNIQUE KEY gangs_tag_unique (tag),
    KEY gangs_owner (owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$db->exec("CREATE TABLE IF NOT EXISTS gang_members (
    gang_id INT UNSIGNED NOT NULL,
    user_id INT NOT NULL,
    role ENUM('boss','member') NOT NULL DEFAULT 'member',
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    KEY gang_members_gang (gang_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$message = '';
$messageType = 'ok';

$membershipStmt = $db->prepare("SELECT g.id,g.name,g.tag,g.owner_id,g.respect,gm.role
    FROM gang_members gm JOIN gangs g ON g.id=gm.gang_id WHERE gm.user_id=? LIMIT 1");
$membershipStmt->execute([(int)$user['id']]);
$gang = $membershipStmt->fetch() ?: null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_gang') {
    if (!csrf_valid(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        $message = 'Sesja formularza wygasła. Odśwież stronę.';
        $messageType = 'error';
    } elseif ($gang) {
        $message = 'Należysz już do gangu.';
        $messageType = 'error';
    } else {
        $name = trim(is_string($_POST['name'] ?? null) ? $_POST['name'] : '');
        $tag = mb_strtoupper(trim(is_string($_POST['tag'] ?? null) ? $_POST['tag'] : ''));

        if (mb_strlen($name) < 3 || mb_strlen($name) > 40) {
            $message = 'Nazwa gangu musi mieć od 3 do 40 znaków.';
            $messageType = 'error';
        } elseif (!preg_match('/^[\p{L}\p{N} ._-]+$/u', $name)) {
            $message = 'Nazwa zawiera niedozwolone znaki.';
            $messageType = 'error';
        } elseif (!preg_match('/^[A-Z0-9]{2,8}$/', $tag)) {
            $message = 'Tag musi mieć 2–8 znaków: A–Z lub 0–9.';
            $messageType = 'error';
        } else {
            try {
                $db->beginTransaction();

                $checkMember = $db->prepare('SELECT gang_id FROM gang_members WHERE user_id=? FOR UPDATE');
                $checkMember->execute([(int)$user['id']]);
                if ($checkMember->fetchColumn()) {
                    throw new RuntimeException('Należysz już do gangu.');
                }

                $duplicate = $db->prepare('SELECT id FROM gangs WHERE name=? OR tag=? LIMIT 1');
                $duplicate->execute([$name,$tag]);
                if ($duplicate->fetchColumn()) {
                    throw new RuntimeException('Gang o takiej nazwie lub tagu już istnieje.');
                }

                // Utworzenie gangu jest darmowe — brak operacji na gotówce gracza.
                $create = $db->prepare('INSERT INTO gangs(name,tag,owner_id) VALUES(?,?,?)');
                $create->execute([$name,$tag,(int)$user['id']]);
                $gangId = (int)$db->lastInsertId();

                $join = $db->prepare("INSERT INTO gang_members(gang_id,user_id,role) VALUES(?,?,'boss')");
                $join->execute([$gangId,(int)$user['id']]);

                $db->commit();
                redirect('./?page=main&location=gang&created=1');
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                $message = $e instanceof RuntimeException ? $e->getMessage() : 'Nie udało się utworzyć gangu.';
                $messageType = 'error';
            }
        }
    }
}

$membershipStmt->execute([(int)$user['id']]);
$gang = $membershipStmt->fetch() ?: null;
$members = [];
if ($gang) {
    $membersStmt = $db->prepare("SELECT u.id,u.login,gm.role,gm.joined_at
        FROM gang_members gm JOIN users u ON u.id=gm.user_id
        WHERE gm.gang_id=? ORDER BY (gm.role='boss') DESC,gm.joined_at ASC,u.id ASC");
    $membersStmt->execute([(int)$gang['id']]);
    $members = $membersStmt->fetchAll();
}
if (isset($_GET['created']) && $gang) {
    $message = 'Gang został utworzony.';
}
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Gang — MBZ</title>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;font-family:Arial,sans-serif;background:#111;color:#fff}.wrap{width:min(980px,calc(100% - 32px));margin:0 auto;padding:48px 0}.card{padding:28px;border:1px solid #333;border-radius:16px;background:#181818}.button,button{display:inline-block;margin-top:18px;padding:12px 16px;border:0;border-radius:8px;background:#fff;color:#111;text-decoration:none;cursor:pointer;font-weight:700}.secondary{background:#2a2a2a;color:#fff}.muted{color:#aaa}label{display:block;margin:14px 0 6px}input{width:100%;padding:12px;border:1px solid #444;border-radius:8px;background:#0f0f0f;color:#fff}.notice{padding:12px 14px;border-radius:8px;background:#183a24;margin:18px 0}.notice.error{background:#3a1717}.stats{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:18px}.stat{padding:14px;background:#111;border:1px solid #333;border-radius:10px}.stat span{display:block;color:#aaa;font-size:13px;margin-bottom:5px}.member{display:flex;justify-content:space-between;gap:12px;padding:12px 0;border-bottom:1px solid #333}.tag{display:inline-block;padding:4px 8px;border:1px solid #555;border-radius:6px;margin-left:8px;color:#ddd}@media(max-width:650px){.stats{grid-template-columns:1fr}.wrap{padding:24px 0}.card{padding:20px}}
</style>
</head>
<body><div class="wrap">
<section class="card">
<a class="button secondary" href="./?page=main">← Powrót do menu</a>
<h1 style="margin-top:24px">Gang</h1>
<?php if ($message !== ''): ?><div class="notice <?= $messageType==='error'?'error':'' ?>"><?= e($message) ?></div><?php endif; ?>

<?php if (!$gang): ?>
<p class="muted">Nie należysz jeszcze do żadnego gangu. Założenie własnego gangu jest darmowe.</p>
<form method="post" action="./?page=main&amp;location=gang">
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
<input type="hidden" name="action" value="create_gang">
<label for="gang-name">Nazwa gangu</label>
<input id="gang-name" name="name" type="text" minlength="3" maxlength="40" required autocomplete="off" placeholder="np. Warszawska Mafia">
<label for="gang-tag">Tag gangu</label>
<input id="gang-tag" name="tag" type="text" minlength="2" maxlength="8" required autocomplete="off" placeholder="np. WM" style="text-transform:uppercase">
<p class="muted">Tag: 2–8 znaków, litery A–Z lub cyfry. Nazwa i tag muszą być unikalne.</p>
<button type="submit">Utwórz gang za darmo</button>
</form>
<?php else: ?>
<h2><?= e($gang['name']) ?> <span class="tag">[<?= e($gang['tag']) ?>]</span></h2>
<div class="stats">
<div class="stat"><span>Twoja ranga</span><strong><?= $gang['role']==='boss'?'Szef':'Członek' ?></strong></div>
<div class="stat"><span>Respekt gangu</span><strong><?= number_format((int)$gang['respect'],0,'.',' ') ?></strong></div>
<div class="stat"><span>Liczba członków</span><strong><?= count($members) ?></strong></div>
<div class="stat"><span>Koszt utworzenia</span><strong>0 $</strong></div>
</div>
<h2 style="margin-top:28px">Członkowie</h2>
<?php foreach($members as $member): ?>
<div class="member"><strong><?= e($member['login']) ?></strong><span class="muted"><?= $member['role']==='boss'?'Szef':'Członek' ?></span></div>
<?php endforeach; ?>
<?php endif; ?>
</section>
</div></body></html>
