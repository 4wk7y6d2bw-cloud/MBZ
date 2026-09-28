<?php
// Central profession rules. Use these helpers in each action when its mechanic is implemented.
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
