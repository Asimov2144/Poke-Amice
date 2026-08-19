<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
api_bootstrap();
api_require_method('GET');

api_run(function (): void {
    $dataset = api_dataset_snapshot();
    api_success([
        'name' => API_V1_NAME . ' — Tagging',
        'version' => API_V1_VERSION,
        'contract_version' => API_V1_CONTRACT_VERSION,
        'status' => 'ready',
        'endpoints' => [
            'next_case' => 'tagging/next_case.php?game={可选，canonical game 名}',
            'submit_case' => 'tagging/submit_case.php',
            'games_progress' => 'tagging/games_progress.php',
            'next_label' => 'tagging/next_label.php?category={宝可梦|人物|地点}',
            'submit_label' => 'tagging/submit_label.php',
            'next_verify' => 'tagging/next_verify.php?category={宝可梦|人物|地点|剧情}',
            'submit_verify' => 'tagging/submit_verify.php',
            'suggest' => 'tagging/suggest.php?category={宝可梦|人物|地点}&q={text}',
            'leaderboard' => 'tagging/leaderboard.php',
        ],
    ], ['dataset_version' => $dataset['version']], 300);
});
