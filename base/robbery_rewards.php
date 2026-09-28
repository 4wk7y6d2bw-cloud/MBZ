<?php
/**
 * Grants exactly one credit for the first successful robbery of a game day.
 * Shared by solo and gang robberies. Call inside the robbery's DB transaction,
 * after success is confirmed, before commit.
 */
function award_first_daily_robbery_credit(PDO $db, int $userId, int $season, int $gameDay): bool
{
    $db->exec('CREATE TABLE IF NOT EXISTS daily_robbery_credits (
        user_id INT NOT NULL,
        season INT NOT NULL,
        game_day INT NOT NULL,
        awarded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, season, game_day)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $insert = $db->prepare('INSERT IGNORE INTO daily_robbery_credits (user_id,season,game_day) VALUES (?,?,?)');
    $insert->execute([$userId, $season, $gameDay]);
    if ($insert->rowCount() !== 1) {
        return false;
    }
    $update = $db->prepare('UPDATE player_stats SET credits=credits+1 WHERE user_id=?');
    $update->execute([$userId]);
    return true;
}
