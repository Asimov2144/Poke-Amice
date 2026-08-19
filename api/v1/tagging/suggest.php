<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../../../lib/tagging_helpers.php';
require __DIR__ . '/../../../lib/pokemon_search.php';
api_bootstrap();
api_require_method('GET');

api_run(function (): void {
    $tagDb = api_tagging_db();
    api_rate_limit($tagDb, 'tagging_suggest_v1', 120, 60);

    $category = api_query_string('category', 20);
    gg_validate_category($category, GG_LABEL_CATEGORIES);
    $q = api_query_string('q', 40);
    if ($q === '') api_success(['suggestions' => []], [], 0);

    if ($category === '宝可梦') {
        $suggestions = array_map(static fn(array $row): array => [
            'canonical' => (string)($row['name']['zh'] ?? ''),
            'matched_via' => $row['matched_via'],
        ], gg_pokemon_suggest($q, 8));
    } else {
        $suggestions = gg_glossary_suggest(api_db(), $category, $q, 8);
    }

    api_success(['suggestions' => $suggestions], [], 0);
});
