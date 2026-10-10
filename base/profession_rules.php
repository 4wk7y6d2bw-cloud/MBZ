<?php
// Central profession rules, including percentage-based stat rewards from successful street and gang robberies.
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
 * Persist fractional profession bonuses as hundredths of a stat point.
 * The season is part of the key so a new season starts at zero.
 * Must be called OUTSIDE a transaction (MySQL DDL causes implicit commits).
 */
function ensure_profession_stat_reward_table(PDO $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS profession_stat_reward_progress (
        user_id INT NOT NULL,
        season INT NOT NULL,
        strength TINYINT UNSIGNED NOT NULL DEFAULT 0,
        endurance TINYINT UNSIGNED NOT NULL DEFAULT 0,
        intelligence TINYINT UNSIGNED NOT NULL DEFAULT 0,
        charisma TINYINT UNSIGNED NOT NULL DEFAULT 0,
        cunning TINYINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (user_id, season)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/**
 * Apply profession stat modifiers to earned robbery rewards, not the entire
 * character's existing stats. A +2% bonus on +3 points accrues 0.06 point;
 * once a full point has been accumulated, it is awarded atomically.
 * Negative modifiers likewise withhold a point only after reaching 1.00.
 *
 * Call from inside the successful robbery transaction.
 * @return array{points:array<string,int>, adjustments:array<string,int>, progress:array<string,int>}
 */
function profession_robbery_stat_rewards(PDO $db, int $userId, int $season, string $profession, int $baseGain): array {
    $fields = ['strength','endurance','intelligence','charisma','cunning'];
    $points = array_fill_keys($fields, $baseGain);
    $adjustments = array_fill_keys($fields, 0);
    $progress = array_fill_keys($fields, 0);
    $modifiers = profession_rules($profession)['stats'] ?? [];
    if ($baseGain <= 0 || !$modifiers) {
        return ['points'=>$points, 'adjustments'=>$adjustments, 'progress'=>$progress];
    }

    $db->prepare('INSERT IGNORE INTO profession_stat_reward_progress (user_id, season) VALUES (?, ?)')
       ->execute([$userId, $season]);
    $stmt = $db->prepare('SELECT strength,endurance,intelligence,charisma,cunning
        FROM profession_stat_reward_progress WHERE user_id=? AND season=? FOR UPDATE');
    $stmt->execute([$userId, $season]);
    $stored = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$stored) {
        throw new RuntimeException('Nie można naliczyć premii profesji.');
    }

    foreach ($fields as $field) {
        $rate = (float)($modifiers[$field] ?? 0);
        $progress[$field] = (int)$stored[$field];
        if ($rate == 0.0) continue;
        // All configured modifiers are whole percentages: 0.02 = 2/100.
        $rateHundredths = (int)round(abs($rate) * 100);
        $total = $progress[$field] + $baseGain * $rateHundredths;
        $whole = intdiv($total, 100);
        $progress[$field] = $total % 100;
        $adjustments[$field] = $rate > 0 ? $whole : -min($baseGain, $whole);
        $points[$field] = max(0, $baseGain + $adjustments[$field]);
    }

    $db->prepare('UPDATE profession_stat_reward_progress
        SET strength=?,endurance=?,intelligence=?,charisma=?,cunning=?
        WHERE user_id=? AND season=?')
       ->execute([$progress['strength'],$progress['endurance'],$progress['intelligence'],
                  $progress['charisma'],$progress['cunning'],$userId,$season]);

    return ['points'=>$points, 'adjustments'=>$adjustments, 'progress'=>$progress];
}

/** Describe the fractional bonus, so a player can see it even before +1 is reached. */
function profession_robbery_stat_notice(string $profession, array $reward): string {
    $names = ['strength'=>'siła','endurance'=>'wytrzymałość',
              'intelligence'=>'inteligencja','charisma'=>'charyzma','cunning'=>'spryt'];
    $modifiers = profession_rules($profession)['stats'] ?? [];
    $parts = [];
    foreach ($names as $field=>$name) {
        $rate = (float)($modifiers[$field] ?? 0);
        if ($rate == 0.0) continue;
        $rateLabel = ($rate > 0 ? '+' : '-') . (int)round(abs($rate)*100) . '%';
        $adjustment = (int)($reward['adjustments'][$field] ?? 0);
        $remainder = (int)($reward['progress'][$field] ?? 0);
        $deltaLabel = $adjustment > 0 ? '+'.$adjustment.' pkt teraz, ' :
                      ($adjustment < 0 ? $adjustment.' pkt teraz, ' : '');
        $parts[] = $name.' '.$rateLabel.' ('.$deltaLabel.'postęp '.number_format($remainder/100,2,',','').' / 1 pkt)';
    }
    return $parts ? ' Premia profesji '.$profession.': '.implode('; ',$parts).'.' : '';
}
