<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
api_bootstrap();
api_require_method('GET');

api_run(function (): void {
    $dataset = api_dataset_snapshot();
    api_success([
        'api_version' => API_V1_VERSION,
        'contract_version' => API_V1_CONTRACT_VERSION,
        'dataset' => $dataset,
    ], ['dataset_version' => $dataset['version']], 300);
});
