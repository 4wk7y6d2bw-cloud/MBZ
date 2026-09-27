<?php
require_login();
header('Content-Type: application/json; charset=utf-8');
$db->exec('CREATE TABLE IF NOT EXISTS city_raids (city VARCHAR(64) PRIMARY KEY, active TINYINT NOT NULL DEFAULT 0)');
$db->exec('CREATE TABLE IF NOT EXISTS game_migrations (name VARCHAR(100) PRIMARY KEY)');
$db->beginTransaction();
try {
    $migration = 'disable_wroclaw_initial_raid_20260927';
    $stmt = $db->prepare('INSERT IGNORE INTO game_migrations (name) VALUES (?)');
    $stmt->execute([$migration]);
    if ($stmt->rowCount() === 1) {
        $db->exec("UPDATE city_raids SET active=0 WHERE city='Wrocław'");
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}
$db->beginTransaction();
try {
    $migration = 'disable_warsaw_kielce_raid_20260927';
    $stmt = $db->prepare('INSERT IGNORE INTO game_migrations (name) VALUES (?)');
    $stmt->execute([$migration]);
    if ($stmt->rowCount() === 1) {
        $db->exec("UPDATE city_raids SET active=0 WHERE city IN ('Warszawa','Kielce')");
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}
$db->beginTransaction();
try {
    $migration = 'raid_krakow_szczecin_off_warsaw_on_20260927';
    $stmt = $db->prepare('INSERT IGNORE INTO game_migrations (name) VALUES (?)');
    $stmt->execute([$migration]);
    if ($stmt->rowCount() === 1) {
        $db->exec("UPDATE city_raids SET active=0 WHERE city IN ('Kraków','Szczecin')");
        $db->exec("INSERT INTO city_raids (city,active) VALUES ('Warszawa',1) ON DUPLICATE KEY UPDATE active=1");
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}
$db->beginTransaction();
try {
    $migration = 'disable_warsaw_raid_followup_20260927';
    $stmt = $db->prepare('INSERT IGNORE INTO game_migrations (name) VALUES (?)');
    $stmt->execute([$migration]);
    if ($stmt->rowCount() === 1) {
        $db->exec("UPDATE city_raids SET active=0 WHERE city='Warszawa'");
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}
$db->beginTransaction();
try {
    $migration = 'enable_lodz_raid_20260927';
    $stmt = $db->prepare('INSERT IGNORE INTO game_migrations (name) VALUES (?)');
    $stmt->execute([$migration]);
    if ($stmt->rowCount() === 1) {
        $db->exec("INSERT INTO city_raids (city,active) VALUES ('Łódź',1) ON DUPLICATE KEY UPDATE active=1");
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}
$db->beginTransaction();
try {
    $migration = 'raid_lodz_off_warsaw_lublin_szczecin_on_20260927';
    $stmt = $db->prepare('INSERT IGNORE INTO game_migrations (name) VALUES (?)');
    $stmt->execute([$migration]);
    if ($stmt->rowCount() === 1) {
        $db->exec("UPDATE city_raids SET active=0 WHERE city='Łódź'");
        $db->exec("INSERT INTO city_raids (city,active) VALUES ('Warszawa',1),('Lublin',1),('Szczecin',1) ON DUPLICATE KEY UPDATE active=1");
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}
$stmt = $db->prepare("INSERT IGNORE INTO game_migrations (name) VALUES (?)");
$stmt->execute(['all_raids_off_v1']);
if ($stmt->rowCount()) $db->exec("UPDATE city_raids SET active=0");
$stmt = $db->prepare("INSERT IGNORE INTO game_migrations (name) VALUES (?)");
$stmt->execute(['enable_rzeszow_raid_20260927']);
if ($stmt->rowCount()) {
    $db->exec("INSERT INTO city_raids (city,active) VALUES ('Rzeszów',1) ON DUPLICATE KEY UPDATE active=1");
}
echo json_encode(['cities' => $db->query('SELECT city FROM city_raids WHERE active=1')->fetchAll(PDO::FETCH_COLUMN)]);
