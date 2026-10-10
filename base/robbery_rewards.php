<?php
/**
 * Grants exactly one credit for the first successful robbery of a game day.
 * Shared by solo and gang robberies. Call inside the robbery's DB transaction,
 * after success is confirmed, before commit.
 */
function ensure_daily_robbery_credit_table(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS daily_robbery_credits (
        user_id INT NOT NULL,
        season INT NOT NULL,
        game_day INT NOT NULL,
        awarded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, season, game_day)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}
function award_first_daily_robbery_credit(PDO $db, int $userId, int $season, int $gameDay): bool
{
    $insert = $db->prepare('INSERT IGNORE INTO daily_robbery_credits (user_id,season,game_day) VALUES (?,?,?)');
    $insert->execute([$userId, $season, $gameDay]);
    if ($insert->rowCount() !== 1) {
        return false;
    }
    $update = $db->prepare('UPDATE player_stats SET credits=credits+1 WHERE user_id=?');
    $update->execute([$userId]);
    return true;
}

/**
 * Initialize the failed-robbery ledger before entering a transaction.
 * Fractional losses use tenths of a stat point, and next_stat rotates uniformly
 * over all five attributes, regardless of profession.
 */
function ensure_robbery_stat_loss_table(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS robbery_stat_loss_progress (
        user_id INT NOT NULL,
        season INT NOT NULL,
        tenths TINYINT UNSIGNED NOT NULL DEFAULT 0,
        next_stat TINYINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (user_id, season)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/**
 * Pure calculation: the loss is 10% of the potential reward pool.
 * Whole points are applied immediately, remainder is saved for subsequent failures.
 * Rotate every lost point among all stats (not weighted by profession).
 * Never reduce any stat below 1.
 */
function calculate_robbery_stat_loss(int $rewardPool, int $savedTenths, int $nextStat, array $current): array
{
    if ($rewardPool < 0 || $rewardPool > 10000 || $savedTenths < 0 || $savedTenths > 9) {
        throw new InvalidArgumentException('Nieprawidłowa pula straty.');
    }
    $fields = ['strength', 'endurance', 'intelligence', 'charisma', 'cunning'];
    $losses = array_fill_keys($fields, 0);
    $available = [];
    foreach ($fields as $field) {
        $available[$field] = max(1, (int)($current[$field] ?? 1));
    }
    $tenths = $savedTenths + $rewardPool;
    $pointsToLose = intdiv($tenths, 10);
    $remainingTenths = $tenths % 10;
    $next = $nextStat % count($fields);
    for ($i = 0; $i < $pointsToLose; $i++) {
        $chosen = null;
        for ($offset = 0; $offset < count($fields); $offset++) {
            $candidate = ($next + $offset) % count($fields);
            if ($available[$fields[$candidate]] > 1) {
                $chosen = $candidate;
                break;
            }
        }
        if ($chosen === null) {
            break;
        }
        $field = $fields[$chosen];
        $losses[$field]++;
        $available[$field]--;
        $next = ($chosen + 1) % count($fields);
    }
    return [
        'pool' => $rewardPool,
        'expected_loss' => $pointsToLose,
        'losses' => $losses,
        'total_lost' => array_sum($losses),
        'tenths' => $remainingTenths,
        'next_stat' => $next,
    ];
}

/**
 * Apply the loss within the active transaction; all database changes are atomic.
 */
function apply_robbery_stat_loss(PDO $db, int $userId, int $season, int $rewardPool): array
{
    if (!$db->inTransaction()) {
        throw new LogicException('Strata rabunku wymaga transakcji.');
    }
    $db->prepare('INSERT IGNORE INTO robbery_stat_loss_progress (user_id,season) VALUES (?,?)')
       ->execute([$userId, $season]);

    $progressStmt = $db->prepare('SELECT tenths,next_stat FROM robbery_stat_loss_progress
        WHERE user_id=? AND season=? FOR UPDATE');
    $progressStmt->execute([$userId, $season]);
    $previous = $progressStmt->fetch(PDO::FETCH_ASSOC);
    if (!$previous) {
        throw new RuntimeException('Nie można obliczyć straty punktów.');
    }

    $statsStmt = $db->prepare('SELECT strength,endurance,intelligence,charisma,cunning
        FROM player_stats WHERE user_id=? FOR UPDATE');
    $statsStmt->execute([$userId]);
    $currentStats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    if (!$currentStats) {
        throw new RuntimeException('Nie znaleziono statystyk gracza.');
    }

    $result = calculate_robbery_stat_loss($rewardPool, (int)$previous['tenths'], (int)$previous['next_stat'], $currentStats);
    $loss = $result['losses'];
    $db->prepare('UPDATE player_stats SET
        strength=GREATEST(1,strength-?),
        endurance=GREATEST(1,endurance-?),
        intelligence=GREATEST(1,intelligence-?),
        charisma=GREATEST(1,charisma-?),
        cunning=GREATEST(1,cunning-?)
        WHERE user_id=?')
       ->execute([$loss['strength'],$loss['endurance'],$loss['intelligence'],
                  $loss['charisma'],$loss['cunning'],$userId]);
    $db->prepare('UPDATE robbery_stat_loss_progress SET tenths=?,next_stat=?
        WHERE user_id=? AND season=?')
       ->execute([$result['tenths'],$result['next_stat'],$userId,$season]);
    return $result;
}

/** Short, truthful per-attribute failure notice for both solo and gang robberies. */
function robbery_stat_loss_notice(array $result): string
{
    $loss = $result['losses'];
    return 'Strata 10% puli '.$result['pool'].' pkt: -'.$loss['strength'].' siły, -'.$loss['endurance'].
        ' wytrzymałości, -'.$loss['intelligence'].' inteligencji, -'.$loss['charisma'].
        ' charyzmy, -'.$loss['cunning'].' sprytu (łącznie -'.$result['total_lost'].' pkt).';
}
