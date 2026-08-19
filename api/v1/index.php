<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
api_bootstrap();
api_require_method('GET');

api_run(function (): void {
    $dataset = api_dataset_snapshot();
    api_success([
        'name' => API_V1_NAME,
        'version' => API_V1_VERSION,
        'contract_version' => API_V1_CONTRACT_VERSION,
        'status' => 'ready',
        'endpoints' => [
            'version' => 'version.php',
            'health' => 'health.php',
            'meta' => 'meta.php',
            'screenshots' => 'screenshots.php',
            'screenshot' => 'screenshot.php?id={id}',
            'corrections' => 'corrections.php',
            'openapi' => 'openapi.yaml',
        ],
    ], ['dataset_version' => $dataset['version']], 300);
});
