<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../../../lib/event_identity.php';
require __DIR__ . '/../../../lib/tagging_helpers.php';
api_bootstrap();
api_require_method('GET');

api_run(function (): void {
    $category = api_query_string('category', 20);
    gg_validate_category($category, GG_VERIFY_CATEGORIES);

    $identity = gg_current_identity();
    $tagDb = api_tagging_db();
    $siteDb = api_db();

    $candidate = gg_pick_verify_candidate($siteDb, $tagDb, $identity, $category);
    $stats = gg_identity_stats($tagDb, $identity);

    api_success([
        'card' => $candidate['card'] ?? null,
        'target_type' => $candidate['target_type'] ?? null,
        'target_key' => $candidate['target_key'] ?? null,
        'claim_text' => $candidate['claim_text'] ?? null,
        'category' => $category,
        'identity' => ['kind' => $identity['kind']],
        'stats' => gg_identity_stats_public($stats),
    ], [], 0);
});
