<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
api_bootstrap();
api_require_method('GET');

api_run(function (): void {
    $pdo = api_db();
    $rows = $pdo->query(<<<'SQL'
        SELECT s.game,
               COUNT(*) AS screenshots,
               SUM(CASE WHEN dg.screenshot_id IS NULL OR dg.screenshot_id=dg.group_id THEN 1 ELSE 0 END) AS visible_screenshots,
               SUM(s.text <> '') AS recognized
        FROM screenshots s
        LEFT JOIN screenshot_duplicate_groups dg ON dg.screenshot_id=s.id
        GROUP BY s.game
    SQL)->fetchAll();

    $gamesByName = [];
    foreach ($rows as $row) {
        $name = api_canonical_game((string)$row['game']);
        $gamesByName[$name] ??= ['name' => $name, 'screenshots' => 0, 'visible_screenshots' => 0, 'recognized' => 0];
        $gamesByName[$name]['screenshots'] += (int)$row['screenshots'];
        $gamesByName[$name]['visible_screenshots'] += (int)$row['visible_screenshots'];
        $gamesByName[$name]['recognized'] += (int)$row['recognized'];
    }
    usort($gamesByName, fn(array $a, array $b): int => $b['screenshots'] <=> $a['screenshots']);

    $dataset = api_dataset_snapshot($pdo);
    api_success([
        'stats' => [
            'screenshots' => $dataset['screenshots'],
            'visible_screenshots' => array_sum(array_column($gamesByName, 'visible_screenshots')),
            'recognized' => $dataset['recognized'],
            'games' => count($gamesByName),
            'glossary_matches' => $dataset['glossary_matches'],
            'corpus_matches' => $dataset['corpus_matches'],
        ],
        'games' => array_values($gamesByName),
        'correction_categories' => ['OCR文字错误', '剧情匹配错误', '截图信息错误', '无效截图', '其他问题'],
    ], ['dataset_version' => $dataset['version']], 300);
});
