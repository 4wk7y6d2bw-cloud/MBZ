<?php

declare(strict_types=1);

session_start();
ob_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/base/auth.php';

csrf_token();

$page = isset($_GET['page']) && is_string($_GET['page']) ? $_GET['page'] : 'main';
$page = preg_replace('/[^a-zA-Z0-9_-]/', '', $page) ?: 'main';

$allowedPages = [
    'main',
    'profession',
    'admin',
    'logout',
    'error404',
];

if (!in_array($page, $allowedPages, true)) {
    http_response_code(404);
    $page = 'error404';
}

try {
    $db = db_connect();
} catch (PDOException $e) {
    $db = null;
}

require __DIR__ . '/operations/' . $page . '.php';

ob_end_flush();
