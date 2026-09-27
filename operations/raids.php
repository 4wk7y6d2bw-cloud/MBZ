<?php
require_login();
header('Content-Type: application/json; charset=utf-8');
$db->exec('CREATE TABLE IF NOT EXISTS city_raids (city VARCHAR(64) PRIMARY KEY, active TINYINT NOT NULL DEFAULT 0)');
echo json_encode(['cities' => $db->query('SELECT city FROM city_raids WHERE active=1')->fetchAll(PDO::FETCH_COLUMN)]);
