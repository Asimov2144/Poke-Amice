<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
api_bootstrap();
api_require_method('GET');

api_run(function (): void {
    $pdo = api_db();
    $pdo->query('SELECT 1')->fetchColumn();
    $dataset = api_dataset_snapshot($pdo);
    api_success([
        'status' => 'ok',
        'database' => 'ready',
        'checked_at' => gmdate('c'),
    ], ['dataset_version' => $dataset['version']], 0);
});
