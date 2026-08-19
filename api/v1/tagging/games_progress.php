<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../../../lib/tagging_helpers.php';
api_bootstrap();
api_require_method('GET');

/**
 * 每个作品的标注覆盖率，供 mark.php 的"选择作品"页用。口径见设计文档 §6.3：
 * MVP 阶段还没有 consensus job，"已标注"用"有 glossary_matches 证据 或 有人提交过
 * label_submissions（present/none_of_above）"这个偏乐观的口径；等 confirmed_annotations
 * 积累起来之后，这里可以直接把 confirmed 状态也并进覆盖判断，不需要动前端。
 */
api_run(function (): void {
    $siteDb = api_db();
    $tagDb = api_tagging_db();

    // 先从 tagging.sqlite3 拿到"至少被回答过一次"的 (screenshot_id) 集合，按分类分开，
    // 复用 tagging_helpers.php 文件头说明过的两库不能 JOIN、PHP 侧两步拼接的写法。
    $attempted = ['宝可梦' => [], '人物' => [], '地点' => []];
    $stmt = $tagDb->query(
        "SELECT DISTINCT screenshot_id, category FROM label_submissions
         WHERE category IN ('宝可梦','人物','地点') AND answer_kind IN ('present','none_of_above')"
    );
    foreach ($stmt->fetchAll() as $row) {
        $category = (string)$row['category'];
        if (isset($attempted[$category])) $attempted[$category][] = (int)$row['screenshot_id'];
    }

    $gamesByName = [];
    foreach (GG_LABEL_CATEGORIES as $category) {
        $attemptedIds = $attempted[$category];
        $attemptedClause = '';
        $attemptedParams = [];
        if ($attemptedIds !== []) {
            $attemptedClause = ' OR s.id IN (' . implode(',', array_fill(0, count($attemptedIds), '?')) . ')';
            $attemptedParams = $attemptedIds;
        }

        $sql = "SELECT s.game,
                       COUNT(*) AS screenshots,
                       SUM(CASE WHEN EXISTS(
                               SELECT 1 FROM glossary_matches gm WHERE gm.screenshot_id = s.id AND gm.category = ?
                           ){$attemptedClause} THEN 1 ELSE 0 END) AS covered
                FROM screenshots s
                LEFT JOIN screenshot_duplicate_groups dg ON dg.screenshot_id = s.id
                WHERE (dg.screenshot_id IS NULL OR dg.screenshot_id = dg.group_id)
                GROUP BY s.game";
        $stmt = $siteDb->prepare($sql);
        $stmt->execute([$category, ...$attemptedParams]);

        foreach ($stmt->fetchAll() as $row) {
            $name = api_canonical_game((string)$row['game']);
            $gamesByName[$name] ??= ['game' => $name, 'screenshots' => 0, 'fields_covered' => 0];
            // screenshots 按分类重复统计会翻三倍，只在第一个分类时累加一次。
            if ($category === '宝可梦') $gamesByName[$name]['screenshots'] += (int)$row['screenshots'];
            $gamesByName[$name]['fields_covered'] += (int)$row['covered'];
        }
    }

    $games = array_values($gamesByName);
    foreach ($games as &$game) {
        $fieldsTotal = $game['screenshots'] * count(GG_LABEL_CATEGORIES);
        $game['fields_total'] = $fieldsTotal;
        $game['progress'] = $fieldsTotal > 0 ? round($game['fields_covered'] / $fieldsTotal, 4) : 0.0;
    }
    unset($game);
    usort($games, fn(array $a, array $b): int => $b['screenshots'] <=> $a['screenshots']);

    $dataset = api_dataset_snapshot($siteDb);
    api_success(['games' => $games], ['dataset_version' => $dataset['version']], 120);
});
