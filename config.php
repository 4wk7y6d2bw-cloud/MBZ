<?php

$dbData = [
    'host' => 'localhost',
    'database' => 'p594392_mbzDB',
    'user' => 'p594392_mbzUSER',
    'password' => '',
];

try {
    $pdo = new PDO(
        "mysql:host={$dbData['host']};dbname={$dbData['database']};charset=utf8mb4",
        $dbData['user'],
        $dbData['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    exit('Błąd połączenia z bazą danych.');
}
