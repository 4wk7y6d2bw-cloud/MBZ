<?php
// Authenticated, read-only JSON snapshot for live HUD updates.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
if (!user_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'session_expired']);
    return;
}
if (!$db instanceof PDO) {
    http_response_code(503);
    echo json_encode(['error' => 'database_unavailable']);
    return;
}
try {
    $userId = (int) current_user()['id'];
    $game = $db->query('SELECT game_day, season, is_break, next_ranking_update FROM game_state WHERE id=1')->fetch();
    if (!$game) throw new RuntimeException('Game state missing');
    // Only regenerate the logged-in player's energy; avoid global ranking writes.
    regenerate_player_energy($db, $userId);
    $query = $db->prepare('SELECT * FROM player_stats WHERE user_id=? LIMIT 1');
    $query->execute([$userId]);
    $stats = $query->fetch();
    if (!$stats) throw new RuntimeException('Player stats missing');
    $rank = get_player_rank($db, $userId);
    $fields = ['cash','credits','respect','public_respect','energy','tickets','strength','endurance','intelligence','charisma','cunning','profession','current_city'];
    $player = [];
    foreach ($fields as $field) {
        $player[$field] = in_array($field, ['profession','current_city'], true)
            ? ($stats[$field] ?? null) : (int) ($stats[$field] ?? 0);
    }
    echo json_encode(['player' => $player, 'rank' => $rank, 'game' => [
        'game_day' => (int) $game['game_day'],
        'season' => (int) $game['season'],
        'is_break' => (int) $game['is_break'],
        'next_ranking_update' => $game['next_ranking_update']
    ]], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['error' => 'temporarily_unavailable']);
}
