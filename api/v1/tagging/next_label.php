<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../../../lib/event_identity.php';
require __DIR__ . '/../../../lib/tagging_helpers.php';
api_bootstrap();
api_require_method('GET');

api_run(function (): void {
    $category = api_query_string('category', 20);
    gg_validate_category($category, GG_LABEL_CATEGORIES);

    $identity = gg_current_identity();
    $tagDb = api_tagging_db();
    $siteDb = api_db();

    $card = gg_pick_label_candidate($siteDb, $tagDb, $identity, $category);
    $stats = gg_identity_stats($tagDb, $identity);

    api_success([
        'card' => $card,
        'category' => $category,
        'identity' => ['kind' => $identity['kind']],
        'stats' => gg_identity_stats_public($stats),
    ], [], 0);
});
