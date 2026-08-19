<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
$http = [];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) $http[$k] = $v;
}
echo json_encode(['http_headers' => $http], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
