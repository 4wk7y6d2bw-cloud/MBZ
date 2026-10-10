<?php
// Central profession rules. Successful robberies distribute a fixed stat pool by profession.
function profession_rules(string $profession): array {
    $rules = [
        'Morderca' => ['stats'=>['strength'=>.02,'endurance'=>.02,'intelligence'=>-.01], 'pvp_power'=>.03, 'kill_energy'=>5],
        'Złodziej' => ['stats'=>['strength'=>.01,'intelligence'=>.01,'cunning'=>.01,'charisma'=>-.02], 'robbery_cash'=>.02, 'robbery_success_points'=>3],
        'Biznesmen' => ['stats'=>['intelligence'=>.04,'strength'=>-.01,'endurance'=>-.01,'charisma'=>-.01,'cunning'=>-.01], 'building_discount'=>.02, 'extra_buildings'=>2],
        'Gangster' => ['stats'=>['strength'=>.01,'endurance'=>.01,'intelligence'=>.01,'charisma'=>.01,'cunning'=>.01], 'prison_time_discount'=>.15, 'prison_release_discount'=>.10, 'failed_robbery_stat_loss_discount'=>.03],
        'Diler' => ['stats'=>['cunning'=>.04,'strength'=>-.01,'endurance'=>-.01,'intelligence'=>-.01,'charisma'=>-.01], 'drug_sale_income'=>.01, 'drug_transport_capacity'=>.05, 'drug_warehouse_capacity'=>.05],
        'Alfons' => ['stats'=>['charisma'=>.03,'cunning'=>.01,'strength'=>-.01,'endurance'=>-.01,'intelligence'=>-.01], 'venue_income'=>.03, 'extra_venue_workers'=>1],
    ];
    return $rules[$profession] ?? ['stats'=>[]];
}
function profession_bonus(string $profession, string $bonus): float {
    return (float)(profession_rules($profession)[$bonus] ?? 0);
}
function profession_effective_stat(string $profession, string $stat, int $base): int {
    $modifier = profession_rules($profession)['stats'][$stat] ?? 0;
    return max(0, (int)floor($base * (1 + $modifier)));
}
function profession_robbery_success(string $profession, float $baseChance): float {
    return min(100, max(0, $baseChance + profession_bonus($profession, 'robbery_success_points')));
}
function profession_robbery_cash(string $profession, int $baseCash): int {
    return max(0, (int)floor($baseCash * (1 + profession_bonus($profession, 'robbery_cash'))));
}
function profession_prison_minutes(string $profession, int $baseMinutes): int {
    return max(0, (int)ceil($baseMinutes * (1 - profession_bonus($profession, 'prison_time_discount'))));
}
function profession_prison_release_cost(string $profession, int $baseCost): int {
    return max(0, (int)ceil($baseCost * (1 - profession_bonus($profession, 'prison_release_discount'))));
}
function profession_drug_capacity(string $profession, int $baseCapacity, bool $warehouse = false): int {
    return max(0, (int)floor($baseCapacity * (1 + profession_bonus($profession, $warehouse ? 'drug_warehouse_capacity' : 'drug_transport_capacity'))));
}

/**
 * Percentage of the total stat-point pool earned from successful robberies.
 * Every profession uses exactly 100% of the pool — no extra stat points are created.
 * Separate non-stat perks (cash, PvP, prison, trade) stay in profession_rules().
 */
function profession_stat_distribution_weights(string $profession): array {
    $weights = [
        'Morderca' => ['strength'=>40,'endurance'=>40,'intelligence'=>5,'charisma'=>5,'cunning'=>10],
        'Złodziej' => ['strength'=>20,'endurance'=>10,'intelligence'=>20,'charisma'=>10,'cunning'=>40],
        'Biznesmen' => ['strength'=>10,'endurance'=>10,'intelligence'=>60,'charisma'=>10,'cunning'=>10],
        'Alfons' => ['strength'=>5,'endurance'=>5,'intelligence'=>10,'charisma'=>50,'cunning'=>30],
        'Diler' => ['strength'=>10,'endurance'=>10,'intelligence'=>10,'charisma'=>10,'cunning'=>60],
        'Gangster' => ['strength'=>20,'endurance'=>20,'intelligence'=>20,'charisma'=>20,'cunning'=>20],
    ];
    // Invalid/unselected professions get a safe, equal allocation.
    return $weights[$profession] ?? $weights['Gangster'];
}

/**
 * Automatically create the new signed rounding ledger (one per season/profession).
 * The previous profession_stat_reward_progress table is not reused because its
 * unsigned values belonged to the replaced percentage-bonus system.
 * CALL OUTSIDE TRANSACTIONS: MySQL CREATE TABLE implicitly commits transactions.
 */
function ensure_profession_stat_distribution_table(PDO $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS profession_stat_distribution_progress (
        user_id INT NOT NULL,
        season INT NOT NULL,
        profession VARCHAR(32) NOT NULL,
        strength SMALLINT NOT NULL DEFAULT 0,
        endurance SMALLINT NOT NULL DEFAULT 0,
        intelligence SMALLINT NOT NULL DEFAULT 0,
        charisma SMALLINT NOT NULL DEFAULT 0,
        cunning SMALLINT NOT NULL DEFAULT 0,
        PRIMARY KEY (user_id, season, profession)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/**
 * Distribute one complete pool without losing any points. Scores are stored in
 * hundredths of a point; after each assigned point, subtract 100 from the
 * relevant score. Residual scores are carried into the next successful robbery
 * so even smaller allocations get their fair share across repeated attempts.
 *
 * Pure function for tests. $carry comes from the ledger, not player_stats.
 * @return array{points:array<string,int>,pool:int,progress:array<string,int>}
 */
function profession_allocate_stat_pool(string $profession, int $pool, array $carry = []): array {
    $weights = profession_stat_distribution_weights($profession);
    if (array_sum($weights) !== 100) {
        throw new LogicException('Podział statystyk musi wynosić 100%.');
    }
    if ($pool < 0 || $pool > 10000) {
        throw new InvalidArgumentException('Nieprawidłowa pula punktów.');
    }
    $points = array_fill_keys(array_keys($weights), 0);
    $scores = [];
    foreach ($weights as $field => $percent) {
        $scores[$field] = $pool * $percent + (int)($carry[$field] ?? 0);
    }

    for ($i = 0; $i < $pool; $i++) {
        $winner = null;
        $best = PHP_INT_MIN;
        foreach ($weights as $field => $percent) {
            // A higher professional specialization wins an exact tie.
            if ($winner === null || $scores[$field] > $best ||
                ($scores[$field] === $best && $percent > $weights[$winner])) {
                $winner = $field;
                $best = $scores[$field];
            }
        }
        $points[$winner]++;
        $scores[$winner] -= 100;
    }

    return ['points'=>$points, 'pool'=>$pool, 'progress'=>$scores];
}

/**
 * Allocate the five-stat total that used to give $baseGain to each statistic.
 * Run INSIDE the already-open successful robbery transaction. On rollback the
 * allocation ledger and the actual character-stat UPDATE are both reverted.
 */
function profession_robbery_stat_rewards(PDO $db, int $userId, int $season, string $profession, int $baseGain): array {
    if ($baseGain < 0 || $baseGain > 2000) {
        throw new InvalidArgumentException('Nieprawidłowa nagroda rabunku.');
    }

    $db->prepare('INSERT IGNORE INTO profession_stat_distribution_progress (user_id,season,profession) VALUES (?,?,?)')
       ->execute([$userId,$season,$profession]);
    $read = $db->prepare('SELECT strength,endurance,intelligence,charisma,cunning
        FROM profession_stat_distribution_progress
        WHERE user_id=? AND season=? AND profession=? FOR UPDATE');
    $read->execute([$userId,$season,$profession]);
    $carry = $read->fetch(PDO::FETCH_ASSOC);
    if (!$carry) {
        throw new RuntimeException('Nie można naliczyć punktów statystyk.');
    }

    $reward = profession_allocate_stat_pool($profession, $baseGain * 5, $carry);
    $rem = $reward['progress'];
    $db->prepare('UPDATE profession_stat_distribution_progress
        SET strength=?,endurance=?,intelligence=?,charisma=?,cunning=?
        WHERE user_id=? AND season=? AND profession=?')
       ->execute([$rem['strength'],$rem['endurance'],$rem['intelligence'],
                  $rem['charisma'],$rem['cunning'],$userId,$season,$profession]);
    return $reward;
}

function profession_stat_distribution_description(string $profession): string {
    $weights = profession_stat_distribution_weights($profession);
    return 'Siła '.$weights['strength'].'% · Wytrzymałość '.$weights['endurance'].'% · Inteligencja '.$weights['intelligence'].'% · Charyzma '.$weights['charisma'].'% · Spryt '.$weights['cunning'].'%';
}

function profession_robbery_stat_notice(string $profession, array $reward): string {
    return ' Pula: '.(int)($reward['pool'] ?? 0).' pkt, podzielona według profesji '.$profession.'.';
}
