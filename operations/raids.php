<?php
require_login();
header('Content-Type: application/json');
echo json_encode(['cities' => ['Warszawa', 'Kraków']]);
