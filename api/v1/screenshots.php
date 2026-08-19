<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../lib/pokemon_search.php';
api_bootstrap();
api_require_method('GET');

/**
 * 多个检索词之间取"任一命中"（OR），每个词内部仍是 text/game/filename/tags 四列 OR。
 */
function gg_terms_or_clause(PDO $pdo, array $terms, array &$params): ?string
{
    $parts = [];
    foreach ($terms as $term) {
        if ($term === '') continue;
        $parts[] = api_screenshot_text_clause($pdo, $term, $params);
    }
    return $parts ? '(' . implode(' OR ', $parts) . ')' : null;
}

function gg_fetch_screenshots(PDO $pdo, array $whereParts, array $params, string $orderSql, int $limit): array
{
    $whereSql = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';
    $sql = <<<SQL
        SELECT s.id, s.game, s.filename, s.width, s.height, s.text, s.tags_json,
               s.thumb_url, s.preview_url, s.contributor, s.created_at, s.updated_at,
               COALESCE(dg.group_size, 1) AS variant_count,
               (SELECT COUNT(DISTINCT gm.canonical) FROM glossary_matches gm WHERE gm.screenshot_id=s.id) AS term_count,
               COALESCE((SELECT MAX(cm.score) FROM screenshot_corpus_matches cm WHERE cm.screenshot_id=s.id), 0) AS story_score
        FROM screenshots s
        LEFT JOIN screenshot_duplicate_groups dg ON dg.screenshot_id=s.id
        {$whereSql}
        ORDER BY {$orderSql}
        LIMIT ?
    SQL;
    $params[] = $limit + 1;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $hasMore = count($rows) > $limit;
    if ($hasMore) array_pop($rows);
    return ['items' => $rows, 'has_more' => $hasMore];
}

function gg_decorate_screenshot_row(array $item, ?string $matchTier): array
{
    $item['id'] = (int)$item['id'];
    $item['width'] = $item['width'] === null ? null : (int)$item['width'];
    $item['height'] = $item['height'] === null ? null : (int)$item['height'];
    $item['variant_count'] = (int)$item['variant_count'];
    $item['term_count'] = (int)$item['term_count'];
    $item['story_score'] = (float)$item['story_score'];
    $item['created_at'] = (float)$item['created_at'];
    $item['updated_at'] = (float)$item['updated_at'];
    $item['tags'] = json_decode((string)$item['tags_json'], true) ?: [];
    unset($item['tags_json']);
    api_decorate_game($item);
    $item['match_tier'] = $matchTier;
    return $item;
}

api_run(function (): void {
    $pdo = api_db();
    $query = api_query_string('q', 80);
    $game = api_query_string('game', 80);
    $limit = api_query_int('limit', API_V1_DEFAULT_LIMIT, 1, API_V1_MAX_LIMIT);
    $includeVariants = api_query_bool('include_variants', false);
    $expandEvolution = api_query_bool('expand_evolution', false);
    $sort = api_query_string('sort', 16, 'id_desc');
    $sort = match ($sort) {
        'id_desc', 'newest' => 'id_desc',
        'id_asc', 'oldest' => 'id_asc',
        default => api_error('INVALID_PARAMETER', '参数 sort 无效', 400, [
            'parameter' => 'sort',
            'allowed' => ['id_desc', 'id_asc'],
        ]),
    };

    // 宝可梦名称解析：仅对单个词的 q 尝试（别名归一 → 拼音/错别字容错）。
    // 命中后：primaryTerms = 该宝可梦自己的 zh/en/jp 名称；expand_evolution=1 时
    // familyOnlyTerms = 整条进化/退化链其余成员的名称（已排除 primaryTerms 重复项）。
    // 两组分别检索，保证结果里本体永远排在进化/退化家族前面（见下方 twoPhase 分支）。
    $primaryTerms = [$query];
    $familyOnlyTerms = [];
    $resolvedPokemon = null;
    if ($query !== '' && !preg_match('/\s/u', $query)) {
        $resolution = gg_pokemon_resolve($query);
        if ($resolution !== null) {
            $primaryTerms = array_values(array_unique(array_merge(
                [$query],
                gg_pokemon_name_variants($resolution['entry'])
            )));
            if ($expandEvolution) {
                $familyNames = [];
                foreach (gg_pokemon_evolution_family((string)$resolution['entry']['id']) as $familyId) {
                    $familyEntry = gg_pokemon_by_id($familyId);
                    if ($familyEntry !== null) {
                        $familyNames = array_merge($familyNames, gg_pokemon_name_variants($familyEntry));
                    }
                }
                $familyOnlyTerms = array_values(array_diff(array_unique($familyNames), $primaryTerms));
            }
            $resolvedPokemon = [
                'pokemon_id' => (string)$resolution['entry']['id'],
                'pokemon_name' => (string)($resolution['entry']['name']['zh'] ?? ''),
                'matched_via' => $resolution['matched_via'],
                'expanded_terms' => array_values(array_unique(array_merge($primaryTerms, $familyOnlyTerms))),
            ];
        }
    }
    // twoPhase：结果按 tier 优先级返回——先出完本体（primary），再出进化/退化家族（family）。
    $twoPhase = $resolvedPokemon !== null && $familyOnlyTerms !== [];

    $fingerprint = hash('sha256', json_encode([
        'q' => $query,
        'game' => $game,
        'include_variants' => $includeVariants,
        'sort' => $sort,
        'expand_evolution' => $expandEvolution,
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

    // cursor.id 允许为 null：twoPhase 从 primary 跨入 family 阶段时，family 阶段从头开始，
    // 不受 primary 阶段最后一个 id 的约束（两个 tier 的 id 大小没有必然顺序关系）。
    $cursor = api_query_string('cursor', 512);
    $phase = 1;
    $cursorId = null;
    if ($cursor !== '') {
        $decoded = api_decode_cursor($cursor);
        $decodedId = $decoded['id'] ?? null;
        if (($decoded['v'] ?? null) !== 1
            || ($decoded['sort'] ?? null) !== $sort
            || ($decodedId !== null && !is_int($decodedId))
            || !isset($decoded['fingerprint'])
            || !is_string($decoded['fingerprint'])
            || !hash_equals($fingerprint, $decoded['fingerprint'])) {
            api_error('INVALID_CURSOR', '分页游标与当前查询不匹配', 400);
        }
        $cursorId = $decodedId;
        $phase = (($decoded['phase'] ?? 1) === 2) ? 2 : 1;
    }

    $baseWhere = [];
    if (!$includeVariants) $baseWhere[] = '(dg.screenshot_id IS NULL OR dg.screenshot_id=dg.group_id)';
    $baseParams = [];
    if ($game !== '') {
        $variants = api_game_variants($game);
        $baseWhere[] = 's.game IN (' . implode(',', array_fill(0, count($variants), '?')) . ')';
        array_push($baseParams, ...$variants);
    }

    $orderSql = $sort === 'id_desc' ? 's.id DESC' : 's.id ASC';
    $cursorCompareSql = $sort === 'id_desc' ? 's.id < ?' : 's.id > ?';

    // primary 阶段的 WHERE：本体关键词（OR）+（若无 resolvedPokemon）原始多词 AND + 语料匹配。
    $buildPrimaryWhere = function () use ($pdo, $resolvedPokemon, $primaryTerms, $query, $baseWhere): array {
        $where = $baseWhere;
        $params = [];
        $keywordClauses = [];
        if ($resolvedPokemon !== null) {
            $clause = gg_terms_or_clause($pdo, $primaryTerms, $params);
            if ($clause !== null) $keywordClauses[] = $clause;
        } else {
            foreach (api_search_terms($query) as $term) {
                $keywordClauses[] = api_screenshot_text_clause($pdo, $term, $params);
            }
        }
        $queryClauses = $keywordClauses ? ['(' . implode(' AND ', $keywordClauses) . ')'] : [];
        $locations = api_multilingual_locations($pdo, $query);
        if ($locations) {
            $locationRows = [];
            foreach ($locations as $location) {
                $locationRows[] = '(?,?,?,?)';
                array_push($params, $location['collection'], $location['file_key'], $location['text_file'], (int)$location['line_no']);
            }
            $queryClauses[] = 'EXISTS (SELECT 1 FROM screenshot_corpus_matches cm WHERE cm.screenshot_id=s.id AND cm.score>=0.72 AND (cm.collection,cm.file_key,cm.text_file,cm.line_no) IN (VALUES ' . implode(',', $locationRows) . '))';
        }
        if ($queryClauses) $where[] = '(' . implode(' OR ', $queryClauses) . ')';
        return [$where, $params];
    };

    // family 阶段的 WHERE：家族关键词（OR）且排除本体已命中的行（NOT primaryClause），避免和 primary 阶段重复。
    $buildFamilyWhere = function () use ($pdo, $familyOnlyTerms, $primaryTerms, $baseWhere): array {
        $where = $baseWhere;
        $params = [];
        $familyClause = gg_terms_or_clause($pdo, $familyOnlyTerms, $params);
        if ($familyClause !== null) $where[] = $familyClause;
        $excludeParams = [];
        $primaryClause = gg_terms_or_clause($pdo, $primaryTerms, $excludeParams);
        if ($primaryClause !== null) {
            $where[] = 'NOT ' . $primaryClause;
            array_push($params, ...$excludeParams);
        }
        return [$where, $params];
    };

    $items = [];
    $hasMore = false;
    $nextPhase = 1;
    $nextId = null;

    if (!$twoPhase) {
        [$where, $params] = $buildPrimaryWhere();
        if ($cursorId !== null) { $where[] = $cursorCompareSql; $params[] = $cursorId; }
        $page = gg_fetch_screenshots($pdo, $where, $params, $orderSql, $limit);
        $tier = $resolvedPokemon !== null ? 'primary' : null;
        foreach ($page['items'] as $row) $items[] = gg_decorate_screenshot_row($row, $tier);
        $hasMore = $page['has_more'];
        $nextPhase = 1;
        $nextId = $items ? (int)end($items)['id'] : null;
    } elseif ($phase === 1) {
        [$where, $params] = $buildPrimaryWhere();
        if ($cursorId !== null) { $where[] = $cursorCompareSql; $params[] = $cursorId; }
        $page1 = gg_fetch_screenshots($pdo, $where, $params, $orderSql, $limit);
        foreach ($page1['items'] as $row) $items[] = gg_decorate_screenshot_row($row, 'primary');

        if ($page1['has_more']) {
            $hasMore = true;
            $nextPhase = 1;
            $nextId = (int)end($items)['id'];
        } else {
            // primary 阶段在这页内取尽：用剩余名额续上 family 阶段，让本体和家族尽量拼成一整页。
            $remaining = $limit - count($items);
            [$whereFamily, $paramsFamily] = $buildFamilyWhere();
            $probeLimit = $remaining > 0 ? $remaining : 1;
            $page2 = gg_fetch_screenshots($pdo, $whereFamily, $paramsFamily, $orderSql, $probeLimit);
            if ($remaining > 0) {
                foreach ($page2['items'] as $row) $items[] = gg_decorate_screenshot_row($row, 'family');
                $hasMore = $page2['has_more'];
                $nextPhase = 2;
                $nextId = $page2['items'] ? (int)end($page2['items'])['id'] : null;
            } else {
                // 本页已被 primary 精确占满：不并入 family 结果，只探测 family 是否还有数据。
                $hasMore = count($page2['items']) > 0;
                $nextPhase = 2;
                $nextId = null;
            }
        }
    } else {
        [$where, $params] = $buildFamilyWhere();
        if ($cursorId !== null) { $where[] = $cursorCompareSql; $params[] = $cursorId; }
        $page = gg_fetch_screenshots($pdo, $where, $params, $orderSql, $limit);
        foreach ($page['items'] as $row) $items[] = gg_decorate_screenshot_row($row, 'family');
        $hasMore = $page['has_more'];
        $nextPhase = 2;
        $nextId = $items ? (int)end($items)['id'] : null;
    }

    $nextCursor = null;
    if ($hasMore) {
        $nextCursor = api_encode_cursor([
            'v' => 1,
            'sort' => $sort,
            'phase' => $nextPhase,
            'id' => $nextId,
            'fingerprint' => $fingerprint,
        ]);
    }

    $dataset = api_dataset_snapshot($pdo);
    api_success([
        'items' => $items,
    ], [
        'dataset_version' => $dataset['version'],
        'pagination' => [
            'limit' => $limit,
            'sort' => $sort,
            'has_more' => $hasMore,
            'next_cursor' => $nextCursor,
        ],
        'filters' => [
            'q' => $query,
            'game' => $game,
            'include_variants' => $includeVariants,
            'expand_evolution' => $expandEvolution,
        ],
        'resolved_pokemon' => $resolvedPokemon,
    ], 60);
});
