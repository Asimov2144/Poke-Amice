<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../../../lib/event_identity.php';
require __DIR__ . '/../../../lib/tagging_helpers.php';
api_bootstrap();
api_require_method('GET');

const GG_LEADERBOARD_LIMIT = 20;

api_run(function (): void {
    $tagDb = api_tagging_db();
    // 排行榜本身不需要身份，但如果调用方带了身份（登录用户/游客）就顺带算一下"我的排名"。
    $identity = gg_current_identity(false);

    $rows = $tagDb->query(
        'SELECT identity_kind, identity_id, xp, level, best_streak, labels_submitted, verifies_submitted
         FROM identity_stats
         WHERE xp > 0
         ORDER BY xp DESC, (labels_submitted + verifies_submitted) DESC
         LIMIT ' . GG_LEADERBOARD_LIMIT
    )->fetchAll();

    $entries = [];
    $rank = 0;
    foreach ($rows as $row) {
        $rank++;
        $entries[] = [
            'rank' => $rank,
            'display_name' => gg_leaderboard_display_name((string)$row['identity_kind'], (string)$row['identity_id']),
            'is_guest' => $row['identity_kind'] === 'guest',
            'xp' => (int)$row['xp'],
            'level' => (int)$row['level'],
            'best_streak' => (int)$row['best_streak'],
            'labels_submitted' => (int)$row['labels_submitted'],
            'verifies_submitted' => (int)$row['verifies_submitted'],
        ];
    }

    $me = null;
    if ($identity['id'] !== '') {
        $selfStmt = $tagDb->prepare('SELECT xp, level, best_streak, labels_submitted, verifies_submitted FROM identity_stats WHERE identity_kind=? AND identity_id=?');
        $selfStmt->execute([$identity['kind'], $identity['id']]);
        $selfRow = $selfStmt->fetch();
        if ($selfRow && (int)$selfRow['xp'] > 0) {
            $rankStmt = $tagDb->prepare(
                'SELECT COUNT(*) + 1 FROM identity_stats
                 WHERE xp > ? OR (xp = ? AND (labels_submitted + verifies_submitted) > ?)'
            );
            $rankStmt->execute([(int)$selfRow['xp'], (int)$selfRow['xp'], (int)$selfRow['labels_submitted'] + (int)$selfRow['verifies_submitted']]);
            $me = [
                'rank' => (int)$rankStmt->fetchColumn(),
                'display_name' => gg_leaderboard_display_name($identity['kind'], $identity['id']),
                'is_guest' => $identity['kind'] === 'guest',
                'xp' => (int)$selfRow['xp'],
                'level' => (int)$selfRow['level'],
                'best_streak' => (int)$selfRow['best_streak'],
                'labels_submitted' => (int)$selfRow['labels_submitted'],
                'verifies_submitted' => (int)$selfRow['verifies_submitted'],
            ];
        }
    }

    api_success(['entries' => $entries, 'me' => $me], [], 30);
});
