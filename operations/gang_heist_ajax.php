<?php
declare(strict_types=1);

// Dedicated JSON-only endpoint. GET polls the gang state without refreshing the
// page; POST accepts/creates a raid. The second acceptance resolves it at once.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function gang_ajax_reply(array $data, int $code=200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

if (!user_logged_in()) {
    gang_ajax_reply(['ok'=>false,'error'=>'Sesja wygasła. Zaloguj się ponownie.'],401);
    return;
}
if (!$db instanceof PDO) {
    gang_ajax_reply(['ok'=>false,'error'=>'Baza danych jest niedostępna.'],503);
    return;
}

$userId=(int)current_user()['id'];
$method=$_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method,['GET','POST'],true)) {
    header('Allow: GET, POST');
    gang_ajax_reply(['ok'=>false,'error'=>'Niedozwolona metoda.'],405);
    return;
}

require_once __DIR__.'/../base/robbery_rewards.php';
require_once __DIR__.'/../base/gang_heist_engine.php';

try {
    if ($method==='POST' &&
        !csrf_valid(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        gang_ajax_reply(['ok'=>false,'error'=>'Sesja wygasła. Odśwież stronę.'],403);
        return;
    }

    gang_heist_install($db);
    // Repair old SQL records after a gang was deleted outside MBZ.
    // The maintenance queries are safe for existing gangs and active heists.
    gang_heist_cleanup_orphans($db);
    if ($method==='POST') {
        // Perform schema checks BEFORE beginning heist transaction.
        ensure_profession_stat_distribution_table($db);
        ensure_robbery_stat_loss_table($db);
        ensure_daily_robbery_credit_table($db);
        // Ensures the energy_updated_at migration is checked before any locks
        // are taken by the gang transaction.
        $stats=get_player_stats($db,$userId);
        if (!$stats) throw new RuntimeException('Nie znaleziono statystyk gracza.');
        $jailStmt=$db->prepare('SELECT 1 FROM player_jail WHERE user_id=? AND jailed_until>NOW()');
        $jailStmt->execute([$userId]);
        if ($jailStmt->fetchColumn()) {
            gang_ajax_reply(['ok'=>false,'error'=>'Nie możesz przygotować ani zaakceptować skoku podczas pobytu w więzieniu.'],409);
            return;
        }
        $travelStmt=$db->prepare('SELECT 1 FROM player_travel WHERE user_id=? AND arrives_at>NOW()');
        $travelStmt->execute([$userId]);
        if ($travelStmt->fetchColumn()) {
            gang_ajax_reply(['ok'=>false,'error'=>'Nie możesz brać udziału w skoku podczas podróży.'],409);
            return;
        }
        $state=$db->query('SELECT season,game_day,is_break FROM game_state WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        if (!$state || (int)$state['is_break']===1) {
            gang_ajax_reply(['ok'=>false,'error'=>'Skoki są niedostępne podczas przerwy między sezonami.'],409);
            return;
        }
        $action=is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        $message=gang_heist_action($db,$userId,$action,(int)$state['season'],(int)$state['game_day']);
        gang_ajax_reply(['ok'=>true,'message'=>$message,'state'=>gang_heist_snapshot($db,$userId)]);
        return;
    }
    gang_ajax_reply(['ok'=>true,'state'=>gang_heist_snapshot($db,$userId)]);
} catch (InvalidArgumentException $e) {
    gang_ajax_reply(['ok'=>false,'error'=>$e->getMessage()],400);
} catch (RuntimeException $e) {
    gang_ajax_reply(['ok'=>false,'error'=>$e->getMessage()],409);
} catch (Throwable $e) {
    error_log('Gang heist ajax: '.$e->getMessage());
    gang_ajax_reply(['ok'=>false,'error'=>'Wystąpił błąd skoku gangu. Spróbuj ponownie.'],503);
}
