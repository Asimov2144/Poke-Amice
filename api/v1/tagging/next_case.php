<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../../../lib/event_identity.php';
require __DIR__ . '/../../../lib/tagging_helpers.php';
api_bootstrap();
api_require_method('GET');

api_run(function (): void {
    $game = api_query_string('game', 60);
    $gameVariants = $game !== '' ? api_game_variants($game) : null;

    $identity = gg_current_identity();
    $tagDb = api_tagging_db();
    $siteDb = api_db();

    $result = gg_pick_case_candidate($siteDb, $tagDb, $identity, $gameVariants);
    $stats = gg_identity_stats($tagDb, $identity);

    api_success([
        'card' => $result['card'] ?? null,
        'missing_fields' => $result['missing_fields'] ?? [],
        'game' => $game !== '' ? $game : null,
        'identity' => ['kind' => $identity['kind']],
        'stats' => gg_identity_stats_public($stats),
    ], [], 0);
});
